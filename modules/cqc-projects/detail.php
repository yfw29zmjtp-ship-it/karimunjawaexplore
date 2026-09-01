<?php

/**
 * CQC Projects - Detail & Expense Tracking
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Get project
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$_GET['id']]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    header('Location: dashboard.php');
    exit;
}

$projectId = $project['id'];
$isPdfExport = isset($_GET['export']) && $_GET['export'] === 'pdf';

// Get expenses grouped by category
$stmt = $pdo->query("
    SELECT ec.id, ec.category_name, ec.category_icon, 
           COUNT(pe.id) as expense_count,
           SUM(pe.amount) as total_amount
    FROM cqc_expense_categories ec
    LEFT JOIN cqc_project_expenses pe ON ec.id = pe.category_id AND pe.project_id = $projectId
    WHERE ec.is_active = 1
    GROUP BY ec.id
    ORDER BY ec.id
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($isPdfExport) {
    $stmt = $pdo->prepare(" 
        SELECT pe.expense_date, pe.amount, pe.description,
               COALESCE(ec.category_name, 'Lainnya') as category_name,
               COALESCE(ec.category_icon, '📦') as category_icon
        FROM cqc_project_expenses pe
        LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
        WHERE pe.project_id = ?
        ORDER BY pe.expense_date DESC, pe.id DESC
    ");
    $stmt->execute([$projectId]);
    $allProjectExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalProjectExpense = 0;
    foreach ($allProjectExpenses as $expenseRow) {
        $totalProjectExpense += (float)$expenseRow['amount'];
    }

    header('Content-Type: text/html; charset=UTF-8');
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Export Proyek <?php echo htmlspecialchars($project['project_code']); ?></title>
        <style>
            @page {
                size: A4;
                margin: 14mm;
            }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                color: #0f172a;
                font-size: 11px;
                line-height: 1.45;
            }

            .export-header {
                border-bottom: 2px solid #f0b429;
                padding-bottom: 8px;
                margin-bottom: 10px;
            }

            .export-title {
                margin: 0;
                font-size: 18px;
                color: #0d1f3c;
            }

            .export-meta {
                margin-top: 4px;
                color: #475569;
                font-size: 10px;
            }

            .summary-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                margin-bottom: 12px;
            }

            .summary-item {
                border: 1px solid #e2e8f0;
                border-radius: 4px;
                padding: 6px 8px;
                background: #f8fafc;
            }

            .summary-label {
                color: #64748b;
                font-size: 9px;
                text-transform: uppercase;
                margin-bottom: 2px;
            }

            .summary-value {
                color: #0d1f3c;
                font-weight: 700;
                font-size: 12px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            th {
                background: #f8fafc;
                color: #334155;
                text-align: left;
                font-size: 9px;
                text-transform: uppercase;
                letter-spacing: 0.2px;
                border-bottom: 1px solid #cbd5e1;
                padding: 6px;
            }

            td {
                border-bottom: 1px solid #e2e8f0;
                padding: 6px;
                vertical-align: top;
            }

            .amount {
                text-align: right;
                font-weight: 700;
                white-space: nowrap;
            }

            .desc {
                color: #0d1f3c;
                font-weight: 600;
            }

            .cat {
                color: #64748b;
                font-size: 10px;
            }

            .empty {
                text-align: center;
                color: #64748b;
                padding: 16px 0;
            }

            .actions {
                margin-bottom: 10px;
            }

            .actions button {
                background: #0d1f3c;
                color: #fff;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                font-size: 10px;
                cursor: pointer;
            }

            @media print {
                .actions {
                    display: none;
                }
            }
        </style>
    </head>

    <body>
        <div class="actions">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>

        <div class="export-header">
            <h1 class="export-title">Laporan Transaksi Proyek</h1>
            <div class="export-meta">
                <?php echo htmlspecialchars($project['project_name']); ?> (<?php echo htmlspecialchars($project['project_code']); ?>)
                · Dicetak <?php echo date('d M Y H:i'); ?>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-label">Total Transaksi</div>
                <div class="summary-value"><?php echo number_format(count($allProjectExpenses)); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Total Pengeluaran</div>
                <div class="summary-value">Rp <?php echo number_format($totalProjectExpense, 0); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Budget Proyek</div>
                <div class="summary-value">Rp <?php echo number_format((float)$project['budget_idr'], 0); ?></div>
            </div>
        </div>

        <?php if (!empty($allProjectExpenses)): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 18%;">Tanggal</th>
                        <th>Deskripsi</th>
                        <th style="width: 22%;">Kategori</th>
                        <th style="width: 20%; text-align: right;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allProjectExpenses as $exp): ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                            <td>
                                <div class="desc"><?php echo htmlspecialchars($exp['description'] ?: 'Tanpa keterangan'); ?></div>
                            </td>
                            <td class="cat"><?php echo htmlspecialchars(($exp['category_icon'] ?? '📦') . ' ' . $exp['category_name']); ?></td>
                            <td class="amount">Rp <?php echo number_format((float)$exp['amount'], 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty">Belum ada transaksi untuk proyek ini.</div>
        <?php endif; ?>
    </body>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 200);
        });
    </script>

    </html>
<?php
    exit;
}

// Get latest expenses (LEFT JOIN to show expenses even without category)
$stmt = $pdo->prepare("
    SELECT pe.*, 
           COALESCE(ec.category_name, 'Lainnya') as category_name, 
           COALESCE(ec.category_icon, '📦') as category_icon
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    ORDER BY pe.expense_date DESC
    LIMIT 20
");
$stmt->execute([$projectId]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT pe.category_id, pe.expense_date, pe.amount, pe.description,
           COALESCE(ec.category_name, 'Lainnya') as category_name,
           COALESCE(ec.category_icon, '📦') as category_icon
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    ORDER BY pe.expense_date DESC, pe.id DESC
");
$stmt->execute([$projectId]);
$categoryExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$expensesByCategory = [];
foreach ($categoryExpenses as $expenseRow) {
    $expenseCategoryId = $expenseRow['category_id'] !== null ? (int)$expenseRow['category_id'] : 0;
    if (!isset($expensesByCategory[$expenseCategoryId])) {
        $expensesByCategory[$expenseCategoryId] = [];
    }
    $expensesByCategory[$expenseCategoryId][] = $expenseRow;
}

// Handle add expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    try {
        $expenseAmount = str_replace('.', '', $_POST['amount'] ?? 0);
        $expenseDate = $_POST['expense_date'];
        $expenseDesc = $_POST['description'] ?? '';
        $categoryId = $_POST['category_id'];
        $fundSource = $_POST['fund_source'] ?? 'petty_cash';

        // Get category name for cashbook
        $stmtCat = $pdo->prepare("SELECT category_name FROM cqc_expense_categories WHERE id = ?");
        $stmtCat->execute([$categoryId]);
        $catRow = $stmtCat->fetch();
        $categoryName = $catRow['category_name'] ?? 'CQC Expense';

        $stmt = $pdo->prepare("
            INSERT INTO cqc_project_expenses 
            (project_id, category_id, expense_date, amount, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $projectId,
            $categoryId,
            $expenseDate,
            $expenseAmount,
            $expenseDesc,
            $_SESSION['user_id']
        ]);

        // Update project spent amount
        $result = $pdo->query("SELECT SUM(amount) as total FROM cqc_project_expenses WHERE project_id = $projectId");
        $total = $result->fetch()['total'] ?? 0;

        $pdo->prepare("UPDATE cqc_projects SET spent_idr = ? WHERE id = ?")
            ->execute([$total, $projectId]);

        // ============================================
        // SYNC TO CASHBOOK (cash_book table in same CQC database)
        // ============================================
        try {
            // Ensure tables exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS divisions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                division_name VARCHAR(100) NOT NULL,
                division_code VARCHAR(20),
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_name VARCHAR(100) NOT NULL,
                category_type ENUM('income','expense') DEFAULT 'expense',
                division_id INT DEFAULT 1,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS cash_book (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_date DATE NOT NULL,
                transaction_time TIME NOT NULL,
                division_id INT NOT NULL DEFAULT 1,
                category_id INT NOT NULL DEFAULT 1,
                transaction_type ENUM('income', 'expense') NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                description TEXT,
                reference_no VARCHAR(50),
                payment_method ENUM('cash','debit','transfer','qr','other') DEFAULT 'cash',
                source_type VARCHAR(50) DEFAULT 'manual',
                is_editable TINYINT(1) DEFAULT 1,
                created_by INT NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_date (transaction_date),
                INDEX idx_type (transaction_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Get or create CQC division
            $stmtDiv = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%cqc%' OR LOWER(division_name) LIKE '%proyek%' LIMIT 1");
            $cqcDivision = $stmtDiv->fetch(PDO::FETCH_ASSOC);
            if (!$cqcDivision) {
                $pdo->exec("INSERT INTO divisions (division_name, division_code, is_active) VALUES ('CQC Projects', 'CQC', 1)");
                $divisionId = $pdo->lastInsertId();
            } else {
                $divisionId = $cqcDivision['id'];
            }

            // Get or create category
            $stmtCatCheck = $pdo->prepare("SELECT id FROM categories WHERE LOWER(category_name) = LOWER(?) AND category_type = 'expense' LIMIT 1");
            $stmtCatCheck->execute([$categoryName]);
            $expenseCategory = $stmtCatCheck->fetch(PDO::FETCH_ASSOC);
            if (!$expenseCategory) {
                $pdo->exec("INSERT INTO categories (category_name, category_type, division_id, is_active) VALUES ('" . addslashes($categoryName) . "', 'expense', {$divisionId}, 1)");
                $mainCategoryId = $pdo->lastInsertId();
            } else {
                $mainCategoryId = $expenseCategory['id'];
            }

            // Build description with project reference
            $fundSourceLabel = $fundSource === 'petty_cash' ? 'Petty Cash' : 'Kas Besar';
            $fullDescription = '[CQC_PROJECT:' . $projectId . '] [' . $project['project_code'] . '] [' . $fundSourceLabel . '] ' . $expenseDesc;

            // Insert to cash_book
            // Determine cash_account_id for the cash_book record
            $cashAccountIdForCashbook = null;
            $paymentMethodForCashbook = ($fundSource === 'petty_cash') ? 'cash' : 'transfer';
            try {
                $masterDbTemp = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDbTemp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $bizIdTemp = getMasterBusinessId();
                $accType = ($fundSource === 'petty_cash') ? 'cash' : 'bank';
                $stmtAcc = $masterDbTemp->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = ? ORDER BY id LIMIT 1");
                $stmtAcc->execute([$bizIdTemp, $accType]);
                $accRow = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                if ($accRow) $cashAccountIdForCashbook = $accRow['id'];
            } catch (Exception $e) {
                error_log("CQC detail: Error getting cash_account_id: " . $e->getMessage());
            }

            $stmtCashbook = $pdo->prepare("
                INSERT INTO cash_book 
                (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, cash_account_id, source_type, is_editable, created_by)
                VALUES (?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'cqc_project', 1, ?)
            ");
            $stmtCashbook->execute([
                $expenseDate,
                date('H:i:s'),
                $divisionId,
                $mainCategoryId,
                $expenseAmount,
                $fullDescription,
                $paymentMethodForCashbook,
                $cashAccountIdForCashbook,
                $_SESSION['user_id']
            ]);

            // ============================================
            // DEDUCT FROM APPROPRIATE ACCOUNT IN MASTER DB
            // ============================================
            try {
                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Get business ID
                $businessId = getMasterBusinessId();

                // Determine account type based on fund source
                // Petty Cash → cash account, Kas Besar → bank account (where invoice income goes)
                $accountType = ($fundSource === 'petty_cash') ? 'cash' : 'bank';

                // Get the appropriate account
                $stmtAccount = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? ORDER BY id LIMIT 1");
                $stmtAccount->execute([$businessId, $accountType]);
                $account = $stmtAccount->fetch(PDO::FETCH_ASSOC);

                if ($account) {
                    // Record expense transaction
                    $stmtTrans = $masterDb->prepare("
                        INSERT INTO cash_account_transactions 
                        (cash_account_id, transaction_date, description, amount, transaction_type, created_by) 
                        VALUES (?, ?, ?, ?, 'expense', ?)
                    ");
                    $stmtTrans->execute([
                        $account['id'],
                        $expenseDate,
                        'Pengeluaran Proyek: ' . $project['project_code'] . ' - ' . ($expenseDesc ?: $categoryName),
                        $expenseAmount,
                        $_SESSION['user_id']
                    ]);

                    // Deduct from account balance
                    $stmtBalance = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                    $stmtBalance->execute([$expenseAmount, $account['id']]);

                    error_log("CQC expense deducted from {$account['account_name']}: -{$expenseAmount}");
                }
            } catch (Exception $accountError) {
                error_log("CQC account deduction error: " . $accountError->getMessage());
            }

            error_log("CQC expense synced to cash_book: Project {$project['project_code']}, Amount: {$expenseAmount}, Fund: {$fundSource}");
        } catch (Exception $syncError) {
            error_log("CQC cashbook sync error: " . $syncError->getMessage());
            // Don't fail the main operation, just log the sync error
        }

        header('Location: detail.php?id=' . $projectId . '&success=expense_added');
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Refresh project data to get updated spent amount
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($project['project_name']) . " - CQC Projects";
$pageSubtitle = "Detail Proyek Solar Panel";

include '../../includes/header.php';
?>

<style>
    /* CQC Detail Styles - Compact & Clean */
    .cqc-detail-header {
        background: #fff;
        padding: 10px 14px;
        border-radius: 5px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #f0b429;
    }

    .cqc-detail-header h1 {
        font-size: 14px;
        margin: 0 0 2px;
        color: #0d1f3c !important;
        font-weight: 700;
    }

    .cqc-project-meta {
        display: flex;
        gap: 14px;
        font-size: 11px;
        color: #64748b;
    }

    .cqc-detail-actions {
        display: flex;
        gap: 6px;
    }

    .cqc-detail-actions a,
    .cqc-detail-actions button {
        background: var(--cqc-accent, #f0b429);
        color: #0d1f3c;
        border: none;
        padding: 5px 12px;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        font-weight: 700;
        font-size: 11px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        transition: all 0.2s;
    }

    .cqc-detail-actions a:hover,
    .cqc-detail-actions button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }

    .cqc-main-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 10px;
        margin-bottom: 12px;
    }

    .cqc-card {
        background: #fff;
        border-radius: 5px;
        padding: 12px 14px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        border: 1px solid #e2e8f0;
    }

    .cqc-card h3 {
        color: #0d1f3c;
        font-size: 13px;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 2px solid #f0b429;
    }

    .cqc-status-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
    }

    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 600;
        font-size: 11px;
        display: inline-block;
    }

    .status-planning {
        background: #e8f0fe;
        color: #0d1f3c;
    }

    .status-procurement {
        background: #fef3cd;
        color: #8a6d00;
    }

    .status-installation {
        background: #dbeafe;
        color: #0d1f3c;
    }

    .status-testing {
        background: #d1fae5;
        color: #065f46;
    }

    .status-completed {
        background: #a7f3d0;
        color: #064e3b;
    }

    .cqc-progress-section {
        margin-bottom: 12px;
    }

    .cqc-progress-bar {
        width: 100%;
        height: 8px;
        background: #f1f5f9;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 4px;
    }

    .cqc-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #0d1f3c, #f0b429);
        transition: width 0.3s ease;
    }

    .cqc-progress-text {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: #64748b;
    }

    .cqc-budget-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
        margin-bottom: 10px;
    }

    .cqc-budget-item {
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        padding: 8px 10px;
        text-align: center;
    }

    .cqc-budget-label {
        font-size: 10px;
        color: #94a3b8;
        text-transform: uppercase;
        margin-bottom: 4px;
        letter-spacing: 0.3px;
    }

    .cqc-budget-value {
        font-size: 13px;
        font-weight: 700;
        color: #0d1f3c;
    }

    .cqc-budget-item.warn .cqc-budget-value {
        color: #f0b429;
    }

    .cqc-info-block {
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #f1f5f9;
    }

    .cqc-info-label {
        font-size: 10px;
        color: #94a3b8;
        text-transform: uppercase;
        margin-bottom: 3px;
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    .cqc-info-value {
        font-size: 12px;
        color: #334155;
        font-weight: 500;
    }

    .cqc-category-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 4px;
        border-bottom: 1px solid #f1f5f9;
        cursor: pointer;
    }

    .cqc-category-item:hover {
        background: #f8fafc;
    }

    .cqc-category-item.active {
        background: #eef4ff;
    }

    .cqc-category-left {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1;
    }

    .cqc-category-icon {
        font-size: 16px;
    }

    .cqc-category-info h4 {
        color: #334155;
        font-size: 12px;
        margin-bottom: 1px;
    }

    .cqc-category-info p {
        font-size: 10px;
        color: #94a3b8;
    }

    .cqc-category-amount .amount {
        font-size: 12px;
        font-weight: 700;
        color: #0d1f3c;
    }

    .cqc-category-amount .count {
        font-size: 10px;
        color: #94a3b8;
    }

    .cqc-category-detail {
        display: none;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-top: none;
        border-radius: 0 0 5px 5px;
        padding: 8px 10px;
        margin: -1px 0 4px 0;
    }

    .cqc-category-detail.active {
        display: block;
    }

    .cqc-category-detail-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
        font-size: 10px;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .cqc-category-detail-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: 280px;
        overflow-y: auto;
        padding-right: 2px;
    }

    .cqc-category-detail-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 6px 8px;
    }

    .cqc-category-detail-main {
        flex: 1;
        min-width: 0;
    }

    .cqc-category-detail-desc {
        font-size: 11px;
        color: #0d1f3c;
        font-weight: 600;
        margin-bottom: 2px;
        word-break: break-word;
    }

    .cqc-category-detail-meta {
        font-size: 10px;
        color: #94a3b8;
    }

    .cqc-category-detail-amount {
        font-size: 11px;
        font-weight: 700;
        color: #0d1f3c;
        white-space: nowrap;
    }

    .cqc-category-detail-empty {
        font-size: 11px;
        color: #94a3b8;
        text-align: center;
        padding: 6px 0;
    }

    .cqc-export-btn {
        background: #0d1f3c;
        color: #fff;
        border: none;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 10px;
        text-decoration: none;
        font-weight: 600;
    }

    .cqc-export-btn:hover {
        background: #16294d;
        color: #fff;
    }

    .cqc-expenses-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }

    .cqc-expenses-table thead {
        background: #f8fafc;
    }

    .cqc-expenses-table th {
        padding: 6px 8px;
        text-align: left;
        color: #0d1f3c;
        font-weight: 700;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-bottom: 2px solid #f0b429;
    }

    .cqc-expenses-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 11px;
    }

    .cqc-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 9999;
        padding: 20px;
    }

    .cqc-modal.active {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .cqc-modal-content {
        background: #fff;
        border-radius: 6px;
        padding: 16px 20px;
        max-width: 420px;
        width: 100%;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    }

    .cqc-modal-header {
        font-size: 14px;
        font-weight: 700;
        color: #0d1f3c;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #f0b429;
    }

    .cqc-form-group {
        margin-bottom: 10px;
    }

    .cqc-form-group label {
        display: block;
        font-weight: 600;
        color: #334155;
        margin-bottom: 4px;
        font-size: 11px;
    }

    .cqc-form-group input,
    .cqc-form-group select,
    .cqc-form-group textarea {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-family: inherit;
        font-size: 12px;
        background: #fff;
        color: #334155;
    }

    .cqc-form-group input:focus,
    .cqc-form-group select:focus,
    .cqc-form-group textarea:focus {
        outline: none;
        border-color: #f0b429;
        box-shadow: 0 0 0 2px rgba(240, 180, 41, 0.15);
    }

    .cqc-modal-actions {
        display: flex;
        gap: 8px;
        margin-top: 14px;
    }

    .cqc-modal-actions button {
        flex: 1;
        padding: 7px;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        font-size: 11px;
    }

    .cqc-btn-submit {
        background: #f0b429;
        color: #0d1f3c;
        font-weight: 700;
    }

    .cqc-btn-submit:hover {
        transform: translateY(-1px);
    }

    .cqc-btn-cancel {
        background: #f1f5f9;
        color: #334155;
    }

    .cqc-alert {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
        padding: 8px 12px;
        border-radius: 4px;
        margin-bottom: 10px;
        font-size: 11px;
    }

    @media (max-width: 768px) {
        .cqc-main-grid {
            grid-template-columns: 1fr;
        }

        .cqc-budget-grid {
            grid-template-columns: 1fr;
        }

        .cqc-detail-header {
            flex-direction: column;
            gap: 8px;
        }
    }
</style>

<!-- Header -->
<div class="cqc-detail-header">
    <div>
        <h1><?php echo htmlspecialchars($project['project_name']); ?></h1>
        <div class="cqc-project-meta">
            <span>📍 <?php echo htmlspecialchars($project['location']); ?></span>
            <span>📅 <?php echo date('d M Y', strtotime($project['start_date'])); ?> - <?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></span>
        </div>
    </div>
    <div class="cqc-detail-actions">
        <a href="add.php?id=<?php echo $project['id']; ?>">✏️ Edit</a>
        <a href="dashboard.php">← Kembali</a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="cqc-alert">
        ✅ <?php echo $_GET['success'] === 'expense_added' ? 'Pengeluaran berhasil ditambahkan!' : 'Perubahan berhasil disimpan!'; ?>
    </div>
<?php endif; ?>

<!-- Detail Proyek Row (at top) -->
<div class="cqc-card" style="margin-bottom: 10px;">
    <h3>ℹ️ Detail Proyek</h3>
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
        <div class="cqc-info-block" style="border-bottom: none; margin: 0; padding: 0;">
            <div class="cqc-info-label">Kode Proyek</div>
            <div class="cqc-info-value"><?php echo htmlspecialchars($project['project_code']); ?></div>
        </div>
        <div class="cqc-info-block" style="border-bottom: none; margin: 0; padding: 0;">
            <div class="cqc-info-label">Klien</div>
            <div class="cqc-info-value"><?php echo htmlspecialchars($project['client_name'] ?? '-'); ?></div>
        </div>
        <div class="cqc-info-block" style="border-bottom: none; margin: 0; padding: 0;">
            <div class="cqc-info-label">Kapasitas</div>
            <div class="cqc-info-value"><?php echo htmlspecialchars($project['solar_capacity_kwp'] ?? '-'); ?> KWp</div>
        </div>
        <div class="cqc-info-block" style="border-bottom: none; margin: 0; padding: 0;">
            <div class="cqc-info-label">Tanggal Mulai</div>
            <div class="cqc-info-value"><?php echo date('d M Y', strtotime($project['start_date'])); ?></div>
        </div>
        <div class="cqc-info-block" style="border-bottom: none; margin: 0; padding: 0;">
            <div class="cqc-info-label">Estimasi Selesai</div>
            <div class="cqc-info-value"><?php echo date('d M Y', strtotime($project['estimated_completion'] ?? $project['end_date'])); ?></div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="cqc-main-grid" style="grid-template-columns: 1fr 1fr;">
    <!-- Left Column: Status & Progress -->
    <div class="cqc-card">
        <h3>📊 Status & Progress</h3>

        <div class="cqc-status-bar">
            <span class="status-badge status-<?php echo $project['status']; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
            </span>
        </div>

        <div class="cqc-progress-section">
            <strong style="color: #0d1f3c; font-size: 11px;">Progress Proyek</strong>
            <div class="cqc-progress-bar">
                <div class="cqc-progress-fill" style="width: <?php echo $project['progress_percentage']; ?>%"></div>
            </div>
            <div class="cqc-progress-text">
                <span><?php echo $project['progress_percentage']; ?>% Selesai</span>
                <span><?php echo 100 - $project['progress_percentage']; ?>% Tersisa</span>
            </div>
        </div>
    </div>

    <!-- Right Column: Pie Chart Kategori -->
    <div class="cqc-card">
        <h3>📊 Pengeluaran per Kategori</h3>
        <?php
        // Prepare data for pie chart
        $pieData = array_filter($categories, function ($c) {
            return floatval($c['total_amount'] ?? 0) > 0;
        });
        $pieLabels = array_map(function ($c) {
            return $c['category_name'];
        }, $pieData);
        $pieValues = array_map(function ($c) {
            return floatval($c['total_amount']);
        }, $pieData);
        $pieColors = ['#6366f1', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16'];
        ?>
        <?php if (!empty($pieData)): ?>
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="width: 120px; height: 120px; position: relative;">
                    <canvas id="categoryPieChart"></canvas>
                </div>
                <div style="flex: 1;">
                    <?php foreach (array_slice(array_values($pieData), 0, 5) as $idx => $cat): ?>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                            <span style="width: 10px; height: 10px; border-radius: 50%; background: <?php echo $pieColors[$idx % count($pieColors)]; ?>;"></span>
                            <span style="font-size: 10px; color: #374151; flex: 1;"><?php echo htmlspecialchars($cat['category_name']); ?></span>
                            <span style="font-size: 10px; font-weight: 600; color: #0d1f3c;">Rp <?php echo number_format($cat['total_amount'] / 1000, 0); ?>k</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 30px 0; color: #94a3b8;">
                <div style="font-size: 40px; margin-bottom: 8px;">📊</div>
                <p style="font-size: 11px;">Belum ada data pengeluaran</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Budget & Expenses Section -->
<div class="cqc-main-grid" style="grid-template-columns: 2fr 1fr;">
    <!-- Left: Budget & Category List -->
    <div>
        <div class="cqc-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="margin: 0;">💰 Budget & Pengeluaran</h3>
                <div style="display: flex; gap: 6px;">
                    <a href="categories.php" style="background: #f1f5f9; color: #475569; padding: 4px 8px; border-radius: 4px; font-size: 10px; text-decoration: none; font-weight: 600;">⚙️ Kategori</a>
                    <a href="detail.php?id=<?php echo (int)$project['id']; ?>&export=pdf" target="_blank" class="cqc-export-btn">📄 Export PDF</a>
                    <button onclick="openExpenseModal()" style="background: #10b981; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 10px; cursor: pointer; font-weight: 600;">+ Pengeluaran</button>
                </div>
            </div>
            <div class="cqc-budget-grid">
                <div class="cqc-budget-item">
                    <div class="cqc-budget-label">Budget Total</div>
                    <div class="cqc-budget-value">Rp <?php echo number_format($project['budget_idr'], 0); ?></div>
                </div>
                <div class="cqc-budget-item warn">
                    <div class="cqc-budget-label">Terpakai</div>
                    <div class="cqc-budget-value">Rp <?php echo number_format($project['spent_idr'] ?? 0, 0); ?></div>
                </div>
                <div class="cqc-budget-item">
                    <div class="cqc-budget-label">Sisa</div>
                    <div class="cqc-budget-value">Rp <?php echo number_format(($project['budget_idr'] - ($project['spent_idr'] ?? 0)), 0); ?></div>
                </div>
            </div>

            <?php $usage = $project['budget_idr'] > 0 ? (($project['spent_idr'] ?? 0) / $project['budget_idr'] * 100) : 0; ?>
            <div class="cqc-progress-section">
                <strong style="color: #0d1f3c; font-size: 11px;">Penggunaan Budget</strong>
                <div class="cqc-progress-bar">
                    <div class="cqc-progress-fill" style="width: <?php echo min($usage, 100); ?>%; background: <?php echo $usage > 90 ? '#ef4444' : 'linear-gradient(90deg, #0d1f3c, #f0b429)'; ?>;"></div>
                </div>
                <div class="cqc-progress-text">
                    <span><?php echo number_format($usage, 1); ?>% Digunakan</span>
                    <span><?php echo number_format(max(0, 100 - $usage), 1); ?>% Tersedia</span>
                </div>
            </div>
        </div>

        <!-- Category List -->
        <div class="cqc-card" style="margin-top: 8px;">
            <h3>📋 Daftar per Kategori</h3>
            <div>
                <?php foreach ($categories as $cat): ?>
                    <?php $categoryId = (int)$cat['id']; ?>
                    <div class="cqc-category-item" data-category-id="<?php echo $categoryId; ?>" onclick="toggleCategoryDetail(this)" role="button" tabindex="0" onkeydown="handleCategoryKeydown(event, this)">
                        <div class="cqc-category-left">
                            <div class="cqc-category-icon"><?php echo htmlspecialchars($cat['category_icon'] ?? '📦'); ?></div>
                            <div class="cqc-category-info">
                                <h4><?php echo htmlspecialchars($cat['category_name']); ?></h4>
                                <p><?php echo $cat['expense_count']; ?> transaksi</p>
                            </div>
                        </div>
                        <div class="cqc-category-amount">
                            <div class="amount">Rp <?php echo number_format($cat['total_amount'] ?? 0, 0); ?></div>
                        </div>
                    </div>
                    <div class="cqc-category-detail" data-category-detail="<?php echo $categoryId; ?>">
                        <div class="cqc-category-detail-header">
                            <span><?php echo htmlspecialchars($cat['category_name']); ?> detail</span>
                            <span><?php echo $cat['expense_count']; ?> transaksi</span>
                        </div>
                        <?php $categoryRows = $expensesByCategory[$categoryId] ?? []; ?>
                        <?php if (!empty($categoryRows)): ?>
                            <div class="cqc-category-detail-list">
                                <?php foreach ($categoryRows as $row): ?>
                                    <div class="cqc-category-detail-row">
                                        <div class="cqc-category-detail-main">
                                            <div class="cqc-category-detail-desc"><?php echo htmlspecialchars($row['description'] ?: 'Tanpa keterangan'); ?></div>
                                            <div class="cqc-category-detail-meta"><?php echo htmlspecialchars(($row['category_icon'] ?? '📦') . ' ' . $row['category_name']); ?> · <?php echo date('d M Y', strtotime($row['expense_date'])); ?></div>
                                        </div>
                                        <div class="cqc-category-detail-amount">Rp <?php echo number_format($row['amount'], 0); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="cqc-category-detail-empty">Belum ada transaksi pada kategori ini.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right: Recent Expenses -->
    <div>
        <div class="cqc-card">
            <h3 style="margin-bottom: 10px;">📝 Pengeluaran Terbaru</h3>

            <?php if (!empty($expenses)): ?>
                <table class="cqc-expenses-table">
                    <thead>
                        <tr>
                            <th>Detail Transaksi</th>
                            <th>Tanggal</th>
                            <th style="text-align: right;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                            <tr>
                                <td>
                                    <strong style="color: #0d1f3c;"><?php echo htmlspecialchars($exp['description']); ?></strong>
                                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;"><?php echo htmlspecialchars(($exp['category_icon'] ?? '📦') . ' ' . $exp['category_name']); ?></div>
                                </td>
                                <td style="color: #94a3b8; font-size: 10px;"><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                                <td style="text-align: right; font-weight: 600; color: #0d1f3c;">Rp <?php echo number_format($exp['amount'], 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #94a3b8; padding: 12px 0; font-size: 11px;">Belum ada pengeluaran.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Expense Modal -->
<div class="cqc-modal" id="expenseModal">
    <div class="cqc-modal-content">
        <div class="cqc-modal-header">➕ Tambah Pengeluaran</div>
        <form method="POST">
            <input type="hidden" name="action" value="add_expense">

            <div class="cqc-form-group">
                <label>Sumber Dana</label>
                <select name="fund_source" required style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); font-weight: 600;">
                    <option value="petty_cash" selected>💰 Petty Cash (Operasional)</option>
                    <option value="general_invoice">📄 General Invoice (Kas Besar)</option>
                </select>
                <small style="color: #64748b; font-size: 0.75rem;">Pilih dari mana dana untuk pengeluaran ini</small>
            </div>

            <div class="cqc-form-group">
                <label>Kategori Pengeluaran</label>
                <select name="category_id" required>
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars(($cat['category_icon'] ?? '') . ' ' . $cat['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cqc-form-group">
                <label>Tanggal Pengeluaran</label>
                <input type="date" name="expense_date" required value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="cqc-form-group">
                <label>Jumlah (Rp)</label>
                <input type="text" name="amount" placeholder="1000000" required>
            </div>

            <div class="cqc-form-group">
                <label>Deskripsi/Keterangan</label>
                <textarea name="description" placeholder="Detail pengeluaran..."></textarea>
            </div>

            <div class="cqc-modal-actions">
                <button type="submit" class="cqc-btn-submit">✅ Simpan Pengeluaran</button>
                <button type="button" class="cqc-btn-cancel" onclick="closeExpenseModal()">Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openExpenseModal() {
        document.getElementById('expenseModal').classList.add('active');
    }

    function closeExpenseModal() {
        document.getElementById('expenseModal').classList.remove('active');
    }

    function toggleCategoryDetail(element) {
        const detail = element.nextElementSibling;
        const isOpen = detail && detail.classList.contains('active');

        document.querySelectorAll('.cqc-category-item.active').forEach(function(item) {
            item.classList.remove('active');
        });
        document.querySelectorAll('.cqc-category-detail.active').forEach(function(panel) {
            panel.classList.remove('active');
        });

        if (!isOpen && detail) {
            element.classList.add('active');
            detail.classList.add('active');
        }
    }

    function handleCategoryKeydown(event, element) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggleCategoryDetail(element);
        }
    }

    // Format amount input
    document.querySelector('input[name="amount"]').addEventListener('change', function() {
        const value = this.value.replace(/\D/g, '');
        this.value = value ? new Intl.NumberFormat('id-ID').format(value) : '';
    });

    // Close modal when clicking outside
    document.getElementById('expenseModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeExpenseModal();
        }
    });
</script>

<!-- Chart.js for Pie Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    <?php if (!empty($pieData)): ?>
            (function() {
                const ctx = document.getElementById('categoryPieChart');
                if (!ctx) return;

                new Chart(ctx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode(array_values($pieLabels)); ?>,
                        datasets: [{
                            data: <?php echo json_encode(array_values($pieValues)); ?>,
                            backgroundColor: <?php echo json_encode(array_slice($pieColors, 0, count($pieData))); ?>,
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        cutout: '55%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: '#0d1f3c',
                                bodyColor: '#fff',
                                cornerRadius: 6,
                                padding: 10,
                                callbacks: {
                                    label: function(ctx) {
                                        return ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID');
                                    }
                                }
                            }
                        },
                        animation: {
                            animateRotate: true,
                            duration: 800
                        }
                    }
                });
            })();
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>