<?php
/**
 * KASBOOK DAILY SIMPLE - Owner Cash Management
 * Clean & Simple Interface for Daily Cash Tracking
 * 
 * Shows:
 * - Kas masuk hari ini (dari owner + revenue cash)
 * - Kas keluar hari ini (operasional expenses)
 * - Saldo akhir hari (cash di tangan)
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Check authorization
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

try {
    // Get MASTER DB instance for cash accounts
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    // Get business DB instance
    $businessDb = Database::getInstance();
    
    $businessId = getMasterBusinessId();
    
    // Get selected date or default to today
    $selectedDate = $_GET['date'] ?? date('Y-m-d');
    $displayDate = new DateTime($selectedDate);
    
    // ==========================================
    // FETCH KAS DATA FOR SELECTED DATE
    // ==========================================
    
    // 1. Get Kas Modal Owner (owner capital account)
    $stmt = $masterDb->prepare(
        "SELECT id, account_name, business_id, opening_balance 
         FROM cash_accounts 
         WHERE business_id = ? AND account_type = 'owner_capital'
         LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $ownerCapitalAccount = $stmt->fetch();
    
    // 2. Get Petty Cash account
    $stmt = $masterDb->prepare(
        "SELECT id, account_name, business_id 
         FROM cash_accounts 
         WHERE business_id = ? AND account_type = 'petty_cash'
         LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $pettyCashAccount = $stmt->fetch();
    
    // Determine business DB name
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                     strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    
    if ($isProduction) {
        $businessDbName = ($businessId == 1) ? 'adfb2574_narayana' : 'adfb2574_benscafe';
    } else {
        $businessDbName = ($businessId == 1) ? 'adf_narayana_hotel' : 'adf_benscafe';
    }
    
    // 3. Get KAS MASUK (Owner Capital deposits + Revenue cash for this date)
    $kasMasuk = ['owner' => 0, 'revenue' => 0, 'total' => 0];
    
    // Owner capital transactions for selected date
    if ($ownerCapitalAccount) {
        $stmt = $masterDb->prepare(
            "SELECT SUM(amount) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? AND transaction_type = 'debit'
             GROUP BY cash_account_id"
        );
        $stmt->execute([$ownerCapitalAccount['id'], $selectedDate]);
        $result = $stmt->fetch();
        $kasMasuk['owner'] = $result['total'] ?? 0;
    }
    
    // Revenue cash transactions for selected date (from petty cash account)
    if ($pettyCashAccount) {
        $stmt = $masterDb->prepare(
            "SELECT SUM(amount) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? 
             AND description LIKE '%revenue%' AND transaction_type = 'debit'
             GROUP BY cash_account_id"
        );
        $stmt->execute([$pettyCashAccount['id'], $selectedDate]);
        $result = $stmt->fetch();
        $kasMasuk['revenue'] = $result['total'] ?? 0;
    }
    $kasMasuk['total'] = $kasMasuk['owner'] + $kasMasuk['revenue'];
    
    // 4. Get KAS KELUAR (expenses for this date)
    $kasKeluar = 0;
    if ($pettyCashAccount) {
        $stmt = $masterDb->prepare(
            "SELECT SUM(amount) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? AND transaction_type = 'credit'
             GROUP BY cash_account_id"
        );
        $stmt->execute([$pettyCashAccount['id'], $selectedDate]);
        $result = $stmt->fetch();
        $kasKeluar = $result['total'] ?? 0;
    }
    
    // 5. Get SALDO AKHIR (balance as of selected date)
    // Get all transactions up to and including selected date
    $saldoAkhir = 0;
    if ($pettyCashAccount) {
        // Calculate opening balance for selected date
        $stmt = $masterDb->prepare(
            "SELECT 
                SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE -amount END) as net_amount
             FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date <= ?
             GROUP BY cash_account_id"
        );
        $stmt->execute([$pettyCashAccount['id'], $selectedDate]);
        $result = $stmt->fetch();
        $saldoAkhir = ($pettyCashAccount['opening_balance'] ?? 0) + ($result['net_amount'] ?? 0);
    }
    
    // 6. Get transaction detail for the day (breakdown)
    $transaksiDetail = [];
    if ($pettyCashAccount) {
        $stmt = $masterDb->prepare(
            "SELECT 
                id, transaction_type, amount, description, reference_number, created_at
             FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ?
             ORDER BY created_at ASC"
        );
        $stmt->execute([$pettyCashAccount['id'], $selectedDate]);
        $transaksiDetail = $stmt->fetchAll();
    }
    
    // Helper function to format currency
    function formatCurrency($amount) {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
    
} catch (Exception $e) {
    die('❌ Error: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasbook Daily - Simple Tracking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">
    <style>
        :root {
            --primary: #0071e3;
            --success: #34c759;
            --danger: #ff3b30;
            --warning: #ff9500;
            --dark: #1a1a1a;
            --light: #f5f5f5;
            --gray: #6c757d;
            --border: #e5e7eb;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--light);
            color: var(--dark);
        }

        .navbar-custom {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 16px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .container-main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-subtitle {
            font-size: 14px;
            color: var(--gray);
        }

        .date-controls {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .date-controls input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .date-controls button {
            padding: 8px 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        /* Main Cards */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .card-simple {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }

        .card-simple:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .card-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 24px;
        }

        .card-icon.incoming {
            background: rgba(52, 199, 89, 0.1);
            color: var(--success);
        }

        .card-icon.outgoing {
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger);
        }

        .card-icon.balance {
            background: rgba(0, 113, 227, 0.1);
            color: var(--primary);
        }

        .card-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .card-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 12px;
            font-family: 'SF Mono', monospace;
        }

        .card-description {
            font-size: 13px;
            color: var(--gray);
            line-height: 1.5;
        }

        .breakdown {
            font-size: 12px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            margin-top: 12px;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }

        /* Table Section */
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--dark);
        }

        .table-wrapper {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .table-wrapper table {
            width: 100%;
            border-collapse: collapse;
        }

        .table-wrapper th {
            background: var(--light);
            padding: 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
        }

        .table-wrapper td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .table-wrapper tr:last-child td {
            border-bottom: none;
        }

        .table-wrapper tr:hover {
            background: var(--light);
        }

        .badge-debit {
            display: inline-block;
            background: rgba(52, 199, 89, 0.1);
            color: var(--success);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-credit {
            display: inline-block;
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }

        .amount-value {
            font-family: 'SF Mono', monospace;
            font-weight: 600;
        }

        .amount-positive {
            color: var(--success);
        }

        .amount-negative {
            color: var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--gray);
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .cards-grid {
                grid-template-columns: 1fr;
            }

            .header-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .date-controls {
                width: 100%;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <div class="navbar-custom">
        <div class="container-main" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h5 style="font-size: 16px; font-weight: 700; margin: 0;">📊 Kasbook Daily</h5>
            </div>
            <a href="dashboard.php" style="text-decoration: none; color: var(--gray); font-size: 14px;">← Back to Dashboard</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container-main">
        <!-- Header -->
        <div class="header-section">
            <div>
                <div class="header-title">Kasbook Daily</div>
                <div class="header-subtitle">Simple Cash Management - <?= $displayDate->format('l, d F Y') ?></div>
            </div>
            <div class="date-controls">
                <input type="date" id="dateInput" value="<?= $selectedDate ?>" class="form-control" style="width: auto;">
                <button onclick="updateDate()">
                    <i data-feather="calendar"></i> Load
                </button>
                <button onclick="printReport()" style="background: var(--gray);">
                    <i data-feather="printer"></i> Print
                </button>
            </div>
        </div>

        <!-- Main Cards -->
        <div class="cards-grid">
            <!-- Kas Masuk -->
            <div class="card-simple">
                <div class="card-icon incoming">
                    <i data-feather="arrow-down-circle"></i>
                </div>
                <div class="card-label">💵 Kas Masuk Hari Ini</div>
                <div class="card-value"><?= formatCurrency($kasMasuk['total']) ?></div>
                <div class="card-description">Total uang masuk ke kasir dari semua sumber</div>
                
                <div class="breakdown">
                    <div class="breakdown-item">
                        <span>📦 Dari Owner:</span>
                        <strong><?= formatCurrency($kasMasuk['owner']) ?></strong>
                    </div>
                    <div class="breakdown-item">
                        <span>🏨 Dari Revenue:</span>
                        <strong><?= formatCurrency($kasMasuk['revenue']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Kas Keluar -->
            <div class="card-simple">
                <div class="card-icon outgoing">
                    <i data-feather="arrow-up-circle"></i>
                </div>
                <div class="card-label">💸 Kas Keluar Hari Ini</div>
                <div class="card-value"><?= formatCurrency($kasKeluar) ?></div>
                <div class="card-description">Total pengeluaran operasional hari ini</div>
                
                <div class="breakdown">
                    <div class="breakdown-item">
                        <span>Operasional:</span>
                        <strong><?= formatCurrency($kasKeluar) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Saldo Akhir -->
            <div class="card-simple">
                <div class="card-icon balance">
                    <i data-feather="wallet"></i>
                </div>
                <div class="card-label">💰 Saldo Akhir Hari</div>
                <div class="card-value"><?= formatCurrency($saldoAkhir) ?></div>
                <div class="card-description">Kas yang ada di tangan sekarang (tutup harian)</div>
                
                <div class="breakdown">
                    <div class="breakdown-item">
                        <span>Status Kas:</span>
                        <strong style="color: <?= $saldoAkhir >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= $saldoAkhir >= 0 ? '✅ Sesuai' : '❌ Minus' ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Detail Table -->
        <div style="margin-bottom: 32px;">
            <h5 class="section-title">📋 Detail Transaksi Hari Ini</h5>
            
            <?php if (empty($transaksiDetail)): ?>
                <div class="empty-state" style="background: white; border: 1px solid var(--border); border-radius: 12px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p>Belum ada transaksi untuk hari ini</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Waktu</th>
                                <th>Jenis</th>
                                <th>Keterangan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksiDetail as $txn): ?>
                            <tr>
                                <td>
                                    <?= isset($txn['created_at']) ? (new DateTime($txn['created_at']))->format('H:i:s') : '-' ?>
                                </td>
                                <td>
                                    <?php if ($txn['transaction_type'] == 'debit'): ?>
                                        <span class="badge-debit">MASUK</span>
                                    <?php else: ?>
                                        <span class="badge-credit">KELUAR</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($txn['description'] ?? $txn['reference_number'] ?? '-') ?>
                                </td>
                                <td>
                                    <span class="amount-value <?= $txn['transaction_type'] == 'debit' ? 'amount-positive' : 'amount-negative' ?>">
                                        <?= formatCurrency($txn['amount']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div style="text-align: center; padding: 24px; color: var(--gray); font-size: 12px;">
            <p>Kasbook Daily Simple - Updated at <?= date('H:i') ?></p>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        feather.replace();

        function updateDate() {
            const dateInput = document.getElementById('dateInput').value;
            if (dateInput) {
                window.location.href = '?date=' + dateInput;
            }
        }

        function printReport() {
            window.print();
        }

        // Keyboard shortcut: Enter to update
        document.getElementById('dateInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                updateDate();
            }
        });
    </script>
</body>
</html>
