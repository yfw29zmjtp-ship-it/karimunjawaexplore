<?php
/**
 * End Shift Report - Print PDF
 * Laporan Akhir Shift dengan Detail Transaksi Harian
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/business_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit();
}

// Resolve business info (prefer selected_business_id, fallback to active business config)
$selectedBusinessId = $_SESSION['selected_business_id'] ?? null;
$business = null;
$operatorName = $_SESSION['username'] ?? 'Unknown';

// Get Master DB for user fetch
$masterDb = Database::getInstance();

if (isset($_SESSION['user_id'])) {
    $user = $masterDb->fetchOne("SELECT full_name FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($user && !empty($user['full_name'])) {
        $operatorName = $user['full_name'];
    }
}

if ($selectedBusinessId) {
    $businessQuery = "SELECT * FROM businesses WHERE id = ?";
    $business = $masterDb->fetchOne($businessQuery, [$selectedBusinessId]);
}

if (!$business) {
    $activeConfig = getActiveBusinessConfig();
    if (!empty($activeConfig['database'])) {
        $business = [
            'business_name' => $activeConfig['name'] ?? 'Business',
            'database_name' => $activeConfig['database']
        ];
    }
}

if (!$business) {
    header('Location: ' . BASE_URL . '/select-business.php');
    exit();
}

// Switch to business database
$businessDb = Database::switchDatabase($business['database_name']);

// Get today's date
$today = date('Y-m-d');
$todayDisplay = date('d F Y');
$thisMonth = date('Y-m');
$firstDayOfMonth = date('Y-m-01');

// ============================================
// Daily Cash Calculation (Same as Dashboard)
// ============================================

// Get cash account IDs (grouped by type) from MASTER database
$capitalAccounts = [];
$pettyCashAccounts = [];

try {
    // Get business ID using proper function (same as dashboard)
    $businessId = getMasterBusinessId();
    
    // Create direct PDO connection to master database
    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get ALL owner_capital account IDs from MASTER database
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get ALL cash (Petty Cash) account IDs from MASTER database
    $stmt = $masterPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    // Table might not exist
    error_log("End Shift Report - Cash Accounts Error: " . $e->getMessage());
}

$hasCashAccountIdCol = true;
try {
    $businessDb->getConnection()->query("SELECT cash_account_id FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasCashAccountIdCol = false;
}

// Initialize values
$startKasHariIni = 0;
$ownerTransferThisMonth = 0;
$totalOperationalIncome = 0;
$totalOperationalExpense = 0;
$totalOperationalCash = 0;
$guestCashIncome = 0;

// Calculate Start Cash (balance at end of LAST month)
if ($hasCashAccountIdCol && (!empty($capitalAccounts) || !empty($pettyCashAccounts))) {
    $allAccIds = array_merge($capitalAccounts, $pettyCashAccounts);
    $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
    
    // Start Cash = balance before this month
    $qStart = "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
        FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
    $pStart = array_merge($allAccIds, [$firstDayOfMonth]);
    $rStart = $businessDb->fetchOne($qStart, $pStart);
    $startKasHariIni = $rStart['bal'] ?? 0;
    
    // Owner Transfer THIS MONTH (income to operational accounts)
    $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book WHERE cash_account_id IN ($placeholders) 
        AND transaction_type = 'income'
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pOwner = array_merge($allAccIds, [$thisMonth]);
    $rOwner = $businessDb->fetchOne($qOwner, $pOwner);
    $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    
    // Total Expense THIS MONTH
    $qExp = "SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book WHERE cash_account_id IN ($placeholders) 
        AND transaction_type = 'expense'
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pExp = array_merge($allAccIds, [$thisMonth]);
    $rExp = $businessDb->fetchOne($qExp, $pExp);
    $totalOperationalExpense = $rExp['total'] ?? 0;
    
    // Total Income THIS MONTH from operational accounts
    $totalOperationalIncome = $ownerTransferThisMonth;
    
    // Current Balance (operational accounts)
    $qBal = "SELECT 
        COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
        FROM cash_book WHERE cash_account_id IN ($placeholders) AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
    $pBal = array_merge($allAccIds, [$thisMonth]);
    $rBal = $businessDb->fetchOne($qBal, $pBal);
    $totalOperationalCash = $startKasHariIni + ($rBal['bal'] ?? 0);
}

// Guest Cash Income (cash payments NOT from owner accounts)
$excludeAccountIds = array_merge($capitalAccounts ?? [], $pettyCashAccounts ?? []);
if (!empty($excludeAccountIds)) {
    $excludePlaceholders = implode(',', array_fill(0, count($excludeAccountIds), '?'));
    $cashIncomeResult = $businessDb->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total 
         FROM cash_book 
         WHERE transaction_type = 'income' 
         AND payment_method = 'cash'
         AND (cash_account_id IS NULL OR cash_account_id NOT IN ($excludePlaceholders))
         AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        array_merge($excludeAccountIds, [$thisMonth])
    );
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
} else {
    $cashIncomeResult = $businessDb->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total 
         FROM cash_book 
         WHERE transaction_type = 'income' 
         AND payment_method = 'cash'
         AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
        [$thisMonth]
    );
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
}

// Cash Available = Operational Cash + Guest Cash
$cashAvailable = $totalOperationalCash + $guestCashIncome;

// ============================================
// Today's Transactions (for detail table)
// ============================================
$transactionsQuery = "
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.transaction_time,
        cb.transaction_type,
        cb.description,
        cb.amount,
        cb.payment_method,
        cb.reference_no,
        cb.created_at,
        c.category_name AS category
    FROM cash_book cb
    LEFT JOIN categories c ON cb.category_id = c.id
    WHERE cb.transaction_date = ?
    ORDER BY cb.transaction_date ASC, cb.transaction_time ASC, cb.id ASC
";

$transactions = $businessDb->fetchAll($transactionsQuery, [$today]);

// Calculate today's totals
$totalIncome = 0;
$totalExpense = 0;
$incomeTransactions = [];
$expenseTransactions = [];

foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
        $incomeTransactions[] = $trans;
    } else {
        $totalExpense += $trans['amount'];
        $expenseTransactions[] = $trans;
    }
}

// Currency format function
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan End Shift - <?php echo htmlspecialchars($business['business_name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 15px;
            background: white;
            font-size: 12px;
        }
        
        .report-container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 20px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .header h2 {
            font-size: 16px;
            color: #666;
            font-weight: normal;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 12px;
            color: #888;
        }
        
        .section {
            margin-bottom: 20px;
        }
        
        .section h2 {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        table thead {
            background: #333;
            color: white;
        }
        
        table th {
            padding: 8px 8px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
        }
        
        table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        table td {
            padding: 6px 8px;
            font-size: 11px;
            border-bottom: 1px solid #eee;
        }
        
        table td.amount {
            text-align: right;
            font-weight: 600;
        }
        
        table td.income {
            color: #4CAF50;
        }
        
        table td.expense {
            color: #f44336;
        }
        
        .summary-table {
            width: 100%;
            margin-top: 30px;
            border: 2px solid #333;
        }
        
        .summary-table tr {
            background: white;
        }
        
        .summary-table td {
            padding: 12px 15px;
            font-size: 15px;
            border-bottom: 1px solid #ddd;
        }
        
        .summary-table td:first-child {
            font-weight: 600;
            width: 60%;
        }
        
        .summary-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        
        .summary-table tr.total {
            background: #333;
            color: white;
        }
        
        .summary-table tr.total td {
            border-bottom: none;
            font-size: 18px;
        }
        
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 2px solid #eee;
            text-align: center;
            font-size: 12px;
            color: #888;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        @media print {
            body {
                padding: 0;
            }
            
            .no-print {
                display: none;
            }
            
            .report-container {
                max-width: 100%;
            }
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Cetak PDF</button>
    
    <div class="report-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo htmlspecialchars($business['business_name']); ?></h1>
            <h2>💰 DAILY CASH - <?php echo strtoupper(date('M Y')); ?></h2>
            <p><strong>Tanggal:</strong> <?php echo $todayDisplay; ?> | <strong>Waktu Cetak:</strong> <?php echo date('H:i:s'); ?></p>
            <p><strong>Operator:</strong> <?php echo htmlspecialchars($operatorName); ?></p>
        </div>
        
        <!-- Daily Cash Summary (Same as Dashboard) -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px;">
            <!-- Start Cash -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 7px 10px; border-radius: 7px; border: 1px solid #e2e8f0;">
                <div style="font-size: 8.5px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px;">Start Cash (<?php echo date('M'); ?>)</div>
                <div style="font-size: 14px; font-weight: 600; color: #334155; font-family: 'Segoe UI', Arial, sans-serif;"><?php echo formatRupiah($startKasHariIni); ?></div>
            </div>
            <!-- Cash Available -->
            <div style="background: linear-gradient(135deg, <?php echo $cashAvailable >= 0 ? '#ecfdf5' : '#fef2f2'; ?> 0%, <?php echo $cashAvailable >= 0 ? '#d1fae5' : '#fee2e2'; ?> 100%); padding: 7px 10px; border-radius: 7px; border: 1px solid <?php echo $cashAvailable >= 0 ? '#a7f3d0' : '#fecaca'; ?>;">
                <div style="font-size: 8.5px; color: <?php echo $cashAvailable >= 0 ? '#047857' : '#b91c1c'; ?>; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px;">Cash Available</div>
                <div style="font-size: 14px; font-weight: 600; color: <?php echo $cashAvailable >= 0 ? '#059669' : '#dc2626'; ?>; font-family: 'Segoe UI', Arial, sans-serif;"><?php echo formatRupiah($cashAvailable); ?></div>
            </div>
        </div>

        <!-- Detail Cards: Owner Transfer, Owner + Guest, Expense -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; margin-bottom: 15px;">
            <!-- Owner Transfer -->
            <div style="background: #fff; padding: 7px; border-radius: 6px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="width: 22px; height: 22px; border-radius: 6px; background: linear-gradient(135deg, #fbbf24, #f59e0b); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <span style="font-size: 12px;">💵</span>
                </div>
                <div style="font-size: 7px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.2px;">Owner Transfer</div>
                <div style="font-size: 11px; font-weight: 600; color: #1f2937; font-family: 'Segoe UI', Arial, sans-serif;"><?php echo formatRupiah($ownerTransferThisMonth); ?></div>
            </div>
            <!-- Owner + Guest -->
            <div style="background: #fff; padding: 7px; border-radius: 6px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="width: 22px; height: 22px; border-radius: 6px; background: linear-gradient(135deg, #34d399, #10b981); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <span style="font-size: 12px;">⬆️</span>
                </div>
                <div style="font-size: 7px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.2px;">Owner + Guest</div>
                <div style="font-size: 11px; font-weight: 600; color: #059669; font-family: 'Segoe UI', Arial, sans-serif;"><?php echo formatRupiah($totalOperationalIncome + $guestCashIncome); ?></div>
            </div>
            <!-- Expense -->
            <div style="background: #fff; padding: 7px; border-radius: 6px; border: 1px solid #e5e7eb; text-align: center;">
                <div style="width: 22px; height: 22px; border-radius: 6px; background: linear-gradient(135deg, #f87171, #ef4444); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 4px;">
                    <span style="font-size: 12px;">⬇️</span>
                </div>
                <div style="font-size: 7px; color: #9ca3af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.2px;">Expense</div>
                <div style="font-size: 11px; font-weight: 600; color: #dc2626; font-family: 'Segoe UI', Arial, sans-serif;"><?php echo formatRupiah($totalOperationalExpense); ?></div>
            </div>
        </div>
        
        <!-- Rincian Transaksi Hari Ini -->
        <div class="section">
            <h2>📋 Rincian Transaksi Hari Ini (<?php echo count($transactions); ?>)</h2>
            <?php if (count($transactions) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%;">Waktu</th>
                        <th style="width: 10%;">Tipe</th>
                        <th style="width: 35%;">Keterangan</th>
                        <th style="width: 15%;">Kategori</th>
                        <th style="width: 15%;">Metode</th>
                        <th style="width: 15%;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($trans['transaction_time'] ?? ''); ?></td>
                        <td>
                            <?php if ($trans['transaction_type'] === 'income'): ?>
                                <span style="color: #4CAF50; font-weight: 600;">MASUK</span>
                            <?php else: ?>
                                <span style="color: #f44336; font-weight: 600;">KELUAR</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($trans['description']); ?></td>
                        <td><?php echo htmlspecialchars($trans['category']); ?></td>
                        <td><?php echo htmlspecialchars($trans['payment_method']); ?></td>
                        <td class="amount <?php echo $trans['transaction_type'] === 'income' ? 'income' : 'expense'; ?>">
                            <?php echo formatRupiah($trans['amount']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">Tidak ada transaksi hari ini</div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>Laporan ini dicetak secara otomatis dari sistem <?php echo APP_NAME; ?></p>
            <p>Dicetak pada: <?php echo date('d F Y, H:i:s'); ?> oleh <?php echo htmlspecialchars($_SESSION['username']); ?></p>
        </div>
    </div>
    
    <script>
        // Auto print dialog on load
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });

        // After print (including cancel), notify opener and close
        window.addEventListener('afterprint', function() {
            const logoutUrl = '<?php echo BASE_URL; ?>/logout.php';

            try {
                if (window.opener && window.opener !== window) {
                    window.opener.location.href = logoutUrl;
                } else {
                    window.location.href = logoutUrl;
                    return;
                }
            } catch (e) {
                window.location.href = logoutUrl;
                return;
            }

            setTimeout(function() {
                window.close();
            }, 300);
        });
    </script>
</body>
</html>
