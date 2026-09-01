<?php
/**
 * MONTHLY CLOSING SYSTEM
 * Reset bulanan dan transfer sisa operasional
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Check authorization - Only Owner/Admin can do monthly closing
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();

// Get MASTER DB instance
$masterDb = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// Get business ID
$businessId = getMasterBusinessId();

// Handle Monthly Closing Process
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'monthly_closing') {
        $closingMonth = $_POST['closing_month'];
        $minimumOperational = (float)($_POST['minimum_operational'] ?? 500000);
        $transferExcess = isset($_POST['transfer_excess']) ? true : false;
        
        try {
            $db->beginTransaction();
            $masterDb->beginTransaction();
            
            // 1. Calculate current balances
            $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')");
            $stmt->execute([$businessId]);
            $accounts = $stmt->fetchAll();
            
            $pettyCashAccount = null;
            $ownerCapitalAccount = null;
            
            foreach ($accounts as $acc) {
                if ($acc['account_type'] === 'cash') {
                    $pettyCashAccount = $acc;
                } elseif ($acc['account_type'] === 'owner_capital') {
                    $ownerCapitalAccount = $acc;
                }
            }
            
            if (!$pettyCashAccount || !$ownerCapitalAccount) {
                throw new Exception('Required cash accounts not found');
            }
            
            $currentPettyCash = $pettyCashAccount['current_balance'];
            $currentOwnerCapital = $ownerCapitalAccount['current_balance'];
            $totalOperational = $currentPettyCash + $currentOwnerCapital;
            
            // 2. Calculate monthly summary
            $firstDay = $closingMonth . '-01';
            $lastDay = date('Y-m-t', strtotime($firstDay));
            
            $stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as total_expense,
                    COUNT(*) as transaction_count
                FROM cash_book 
                WHERE transaction_date >= ? AND transaction_date <= ?
            ");
            $stmt->execute([$firstDay, $lastDay]);
            $monthlySummary = $stmt->fetch();
            
            $monthlyProfit = $monthlySummary['total_income'] - $monthlySummary['total_expense'];
            
            // 3. Archive monthly transactions
            $stmt = $db->prepare("
                INSERT INTO monthly_archives (
                    business_id, archive_month, total_income, total_expense, 
                    monthly_profit, transaction_count, final_balance, 
                    minimum_operational, excess_transferred, closing_date, closed_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            
            $excessTransferred = $transferExcess ? max(0, $totalOperational - $minimumOperational) : 0;
            
            $stmt->execute([
                $businessId, $closingMonth, $monthlySummary['total_income'], 
                $monthlySummary['total_expense'], $monthlyProfit, 
                $monthlySummary['transaction_count'], $totalOperational, 
                $minimumOperational, $excessTransferred, $_SESSION['user_id']
            ]);
            
            // 4. Create closing entries if transfer excess
            if ($transferExcess && $excessTransferred > 0) {
                // Add closing transaction to business cash_book
                $stmt = $db->prepare("
                    INSERT INTO cash_book (
                        transaction_date, transaction_time, division_id, category_id,
                        description, transaction_type, amount, payment_method,
                        cash_account_id, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                // Get default division and expense category
                $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
                $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'expense' ORDER BY id ASC LIMIT 1");
                
                $stmt->execute([
                    $lastDay, '23:59:59', $division['id'] ?? 1, $category['id'] ?? 1,
                    "Monthly Closing - Excess Transfer to Owner ({$closingMonth})",
                    'expense', $excessTransferred, 'transfer',
                    $pettyCashAccount['id'], $_SESSION['user_id']
                ]);
                
                $transactionId = $db->getConnection()->lastInsertId();
                
                // Add to master cash_account_transactions  
                $stmt = $masterDb->prepare("
                    INSERT INTO cash_account_transactions (
                        cash_account_id, transaction_id, transaction_date,
                        description, amount, transaction_type,
                        reference_number, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                // Deduct from Petty Cash
                $stmt->execute([
                    $pettyCashAccount['id'], $transactionId, $lastDay,
                    "Monthly Closing Excess Transfer ({$closingMonth})",
                    $excessTransferred, 'expense',
                    "CLOSE-" . $closingMonth, $_SESSION['user_id']
                ]);
                
                // Add to Owner Capital (as capital return)
                $stmt->execute([
                    $ownerCapitalAccount['id'], $transactionId, $lastDay,
                    "Monthly Operational Return ({$closingMonth})",
                    $excessTransferred, 'capital_return',
                    "RETURN-" . $closingMonth, $_SESSION['user_id']
                ]);
                
                // Update balances
                $newPettyCash = $currentPettyCash - $excessTransferred;
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?");
                $stmt->execute([$newPettyCash, $pettyCashAccount['id']]);
                
                // Owner capital balance can stay same (it's a return, not addition)
            }
            
            // 5. Create monthly summary record for next month reference
            $nextMonth = date('Y-m', strtotime($firstDay . ' +1 month'));
            $carryForwardBalance = $totalOperational - $excessTransferred;
            
            $stmt = $db->prepare("
                INSERT INTO monthly_carry_forward (
                    business_id, month, carry_forward_balance, 
                    petty_cash_balance, owner_capital_balance, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $businessId, $nextMonth, $carryForwardBalance,
                $newPettyCash ?? $currentPettyCash, 
                $currentOwnerCapital
            ]);
            
            $db->commit();
            $masterDb->commit();
            
            $message = "✅ Monthly closing for {$closingMonth} completed successfully!";
            if ($excessTransferred > 0) {
                $message .= "<br>💰 Excess Rp " . number_format($excessTransferred, 0, ',', '.') . " transferred to Owner";
            }
            $message .= "<br>🔄 Carry forward balance: Rp " . number_format($carryForwardBalance, 0, ',', '.');
            
        } catch (Exception $e) {
            $db->rollBack();
            $masterDb->rollBack();
            $error = "❌ Error during monthly closing: " . $e->getMessage();
        }
    }
}

// Get current month and available months
$currentMonth = date('Y-m');
$availableMonths = [];
for ($i = 0; $i <= 6; $i++) {
    $month = date('Y-m', strtotime("-{$i} months"));
    $availableMonths[] = $month;
}

// Get current balances
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')");
$stmt->execute([$businessId]);
$accounts = $stmt->fetchAll();

$pettyCashBalance = 0;
$ownerCapitalBalance = 0;

foreach ($accounts as $acc) {
    if ($acc['account_type'] === 'cash') {
        $pettyCashBalance = $acc['current_balance'];
    } elseif ($acc['account_type'] === 'owner_capital') {
        $ownerCapitalBalance = $acc['current_balance'];
    }
}

$totalOperational = $pettyCashBalance + $ownerCapitalBalance;

// Get recent closings
$stmt = $db->prepare("
    SELECT * FROM monthly_archives 
    WHERE business_id = ? 
    ORDER BY archive_month DESC 
    LIMIT 5
");
$stmt->execute([$businessId]);
$recentClosings = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Closing - ADF System</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .closing-form {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin: 2rem 0;
        }
        .balance-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }
        .balance-card {
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        .balance-card.petty { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .balance-card.owner { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .balance-card.total { background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; }
        .form-group {
            margin: 1rem 0;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        .history-table th,
        .history-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .history-table th {
            background: #f9fafb;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 Monthly Closing System</h1>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    
    <!-- Current Balance Summary -->
    <div class="balance-summary">
        <div class="balance-card petty">
            <h3>💵 Petty Cash</h3>
            <div style="font-size: 1.5rem; font-weight: bold;">
                Rp <?= number_format($pettyCashBalance, 0, ',', '.') ?>
            </div>
        </div>
        
        <div class="balance-card owner">
            <h3>🔥 Modal Owner</h3>
            <div style="font-size: 1.5rem; font-weight: bold;">
                Rp <?= number_format($ownerCapitalBalance, 0, ',', '.') ?>
            </div>
        </div>
        
        <div class="balance-card total">
            <h3>💰 Total Operasional</h3>
            <div style="font-size: 1.5rem; font-weight: bold;">
                Rp <?= number_format($totalOperational, 0, ',', '.') ?>
            </div>
        </div>
    </div>
    
    <!-- Monthly Closing Form -->
    <div class="closing-form">
        <h2>🔄 Process Monthly Closing</h2>
        <p>Tutup buku bulanan dan transfer kelebihan kas ke Owner</p>
        
        <form method="POST" onsubmit="return confirm('Are you sure you want to process monthly closing? This action cannot be undone.')">
            <input type="hidden" name="action" value="monthly_closing">
            
            <div class="form-group">
                <label for="closing_month">Bulan Penutupan:</label>
                <select name="closing_month" id="closing_month" class="form-control" required>
                    <?php foreach ($availableMonths as $month): ?>
                        <option value="<?= $month ?>" <?= $month === date('Y-m', strtotime('-1 month')) ? 'selected' : '' ?>>
                            <?= date('F Y', strtotime($month . '-01')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="minimum_operational">Minimum Kas Operasional (Rp):</label>
                <input type="number" name="minimum_operational" id="minimum_operational" 
                       class="form-control" value="500000" step="50000" min="0">
                <small>Jumlah minimum yang harus tersisa untuk operasional bulan depan</small>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="transfer_excess" value="1" checked>
                    Transfer kelebihan kas ke Owner
                </label>
                <small>Jika dicentang, kelebihan kas akan ditransfer ke Owner Capital</small>
            </div>
            
            <button type="submit" class="btn btn-warning">
                🔄 Process Monthly Closing
            </button>
        </form>
    </div>
    
    <!-- Recent Closings History -->
    <div class="closing-form">
        <h2>📋 Recent Monthly Closings</h2>
        
        <?php if (empty($recentClosings)): ?>
            <p>Belum ada monthly closing yang dilakukan.</p>
        <?php else: ?>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th>Total Income</th>
                        <th>Total Expense</th>
                        <th>Profit</th>
                        <th>Final Balance</th>
                        <th>Transferred</th>
                        <th>Closing Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentClosings as $closing): ?>
                        <tr>
                            <td><?= date('F Y', strtotime($closing['archive_month'] . '-01')) ?></td>
                            <td>Rp <?= number_format($closing['total_income'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($closing['total_expense'], 0, ',', '.') ?></td>
                            <td style="color: <?= $closing['monthly_profit'] >= 0 ? 'green' : 'red' ?>">
                                Rp <?= number_format($closing['monthly_profit'], 0, ',', '.') ?>
                            </td>
                            <td>Rp <?= number_format($closing['final_balance'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($closing['excess_transferred'], 0, ',', '.') ?></td>
                            <td><?= date('d/m/Y', strtotime($closing['closing_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div style="text-align: center; margin: 2rem 0;">
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">
        ← Back to Dashboard
    </a>
</div>

</body>
</html>