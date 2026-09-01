<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Edit Cash Book Transaction
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Auto-fix: Convert payment_method ENUM to VARCHAR (ENUM misses edc, ota, etc.)
try {
    $colInfo = $db->fetchOne("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_book' AND COLUMN_NAME = 'payment_method'");
    if ($colInfo && stripos($colInfo['COLUMN_TYPE'], 'enum') !== false) {
        $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash'");
    }
} catch (Exception $e) { /* ignore */ }

// ── Permission: only users with can_edit on cashbook may proceed ─────────────
if (!$auth->canEdit('cashbook')) {
    $_SESSION['error'] = '⛔ Anda tidak memiliki izin untuk mengedit transaksi.';
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$pageTitle = 'Edit Transaksi Kas';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $_SESSION['error'] = 'ID transaksi tidak valid';
    header('Location: index.php');
    exit;
}

// Get transaction
$transaction = $db->fetchOne(
    "SELECT 
        cb.*,
        d.division_name,
        c.category_name
    FROM cash_book cb
    JOIN divisions d ON cb.division_id = d.id
    JOIN categories c ON cb.category_id = c.id
    WHERE cb.id = :id",
    ['id' => $id]
);

if (!$transaction) {
    $_SESSION['error'] = 'Transaksi tidak ditemukan';
    header('Location: index.php');
    exit;
}

// Check if transaction is editable
if (isset($transaction['is_editable']) && $transaction['is_editable'] == 0) {
    if ($transaction['source_type'] == 'purchase_order') {
        $_SESSION['error'] = '❌ Transaksi ini berasal dari Purchase Order #' . $transaction['source_id'] . '. Silakan edit melalui halaman Purchase Order.';
    } else {
        $_SESSION['error'] = '❌ Transaksi ini tidak dapat diedit. Alasan: Dikunci oleh sistem.';
    }
    header('Location: index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transaction_date = $_POST['transaction_date'] ?? '';
    $transaction_time = $_POST['transaction_time'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? '';
    $division_id = (int)($_POST['division_id'] ?? 0);
    $category_name_input = trim($_POST['category_name'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $cash_account_id = !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;
    $source_type_input = trim($_POST['source_type'] ?? '');
    $project_id_input = (int)($_POST['project_id'] ?? 0);
    
    // Validate
    $errors = [];
    if (empty($transaction_date)) $errors[] = 'Tanggal transaksi wajib diisi';
    if (empty($transaction_time)) $errors[] = 'Waktu transaksi wajib diisi';
    if (empty($transaction_type)) $errors[] = 'Tipe transaksi wajib dipilih';
    if ($division_id <= 0) $errors[] = 'Divisi wajib dipilih';
    if (empty($category_name_input)) $errors[] = 'Kategori/Nama wajib diisi';
    if ($amount <= 0) $errors[] = 'Jumlah harus lebih dari 0';
    
    // Find or create category by name
    $category_id = 0;
    if (!empty($category_name_input)) {
        // Check if category exists
        $existingCategory = $db->fetchOne(
            "SELECT id FROM categories WHERE LOWER(category_name) = LOWER(:name) AND category_type = :type",
            ['name' => $category_name_input, 'type' => $transaction_type]
        );
        
        if ($existingCategory) {
            $category_id = $existingCategory['id'];
        } else {
            // Create new category
            $db->insert('categories', [
                'category_name' => $category_name_input,
                'category_type' => $transaction_type,
                'division_id' => $division_id,
                'is_active' => 1
            ]);
            $category_id = $db->getConnection()->lastInsertId();
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Save old data to audit log
            $oldData = json_encode([
                'id' => $transaction['id'],
                'transaction_date' => $transaction['transaction_date'],
                'transaction_time' => $transaction['transaction_time'],
                'transaction_type' => $transaction['transaction_type'],
                'division' => $transaction['division_name'],
                'category' => $transaction['category_name'],
                'amount' => $transaction['amount'],
                'description' => $transaction['description'],
                'payment_method' => $transaction['payment_method']
            ], JSON_UNESCAPED_UNICODE);
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $db->insert('audit_logs', [
                'table_name' => 'cash_book',
                'record_id' => $id,
                'action' => 'UPDATE',
                'old_data' => $oldData,
                'user_id' => $currentUser['id'],
                'user_name' => $currentUser['full_name'],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent
            ]);
            
            // Determine source_type
            $newSourceType = $transaction['source_type']; // keep original by default
            if ($source_type_input === 'owner_project') {
                $newSourceType = 'owner_project';
            } elseif ($source_type_input === '' && $transaction['source_type'] === 'owner_project') {
                // Unchecked project expense → revert to manual
                $newSourceType = 'manual';
            }
            
            // Update transaction
            $db->update('cash_book', [
                'transaction_date' => $transaction_date,
                'transaction_time' => $transaction_time,
                'transaction_type' => $transaction_type,
                'division_id' => $division_id,
                'category_id' => $category_id,
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $payment_method,
                'cash_account_id' => $cash_account_id,
                'source_type' => $newSourceType
            ], 'id = :id', ['id' => $id]);
            
            // ============================================
            // FIX: Update cash_accounts balance when editing
            // 1. Reverse old transaction from old account
            // 2. Apply new transaction to new account
            // ============================================
            try {
                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $oldAmount = floatval($transaction['amount']);
                $oldType = $transaction['transaction_type'];
                $oldAccountId = $transaction['cash_account_id'] ?? null;
                $newAmount = $amount;
                $newType = $transaction_type;
                $newAccountId = $cash_account_id;
                
                // Step 1: Reverse old transaction from old account
                if (!empty($oldAccountId)) {
                    if ($oldType === 'income') {
                        // Old income: subtract from old account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $stmt->execute([$oldAmount, $oldAccountId]);
                    } else {
                        // Old expense: add back to old account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $stmt->execute([$oldAmount, $oldAccountId]);
                    }
                    
                    // Delete old cash_account_transactions record
                    $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id = ? AND description LIKE ? AND ABS(amount - ?) < 1 ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$oldAccountId, '%' . substr($transaction['description'] ?? '', 0, 50) . '%', $oldAmount]);
                    
                    error_log("EDIT REVERSE: Account #{$oldAccountId}, Type: {$oldType}, Amount: {$oldAmount}");
                }
                
                // Step 2: Apply new transaction to new account
                if (!empty($newAccountId)) {
                    if ($newType === 'income') {
                        // New income: add to new account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                        $stmt->execute([$newAmount, $newAccountId]);
                    } else {
                        // New expense: subtract from new account
                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                        $stmt->execute([$newAmount, $newAccountId]);
                    }
                    
                    // Insert new cash_account_transactions record
                    $stmt = $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_date, description, amount, transaction_type, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$newAccountId, $transaction_date, $description ?: $category_name_input, $newAmount, $newType, $currentUser['id']]);
                    
                    error_log("EDIT APPLY: Account #{$newAccountId}, Type: {$newType}, Amount: {$newAmount}");
                }
            } catch (Exception $balanceErr) {
                error_log("Edit balance update error: " . $balanceErr->getMessage());
                // Don't fail the edit, just log the error
            }
            
            $db->commit();
            
            // ============================================
            // SYNC PROJECT EXPENSES (after commit - DDL causes implicit commit)
            // ============================================
            $projectSyncResult = '';
            try {
                $pdo = $db->getConnection();
                
                if ($newSourceType === 'owner_project' && $transaction_type === 'expense') {
                    // Ensure project_expenses table
                    $pdo->exec("CREATE TABLE IF NOT EXISTS project_expenses (
                        id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, category_id INT,
                        amount DECIMAL(15,2) NOT NULL, description TEXT, division_name VARCHAR(100),
                        expense_date DATE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_by INT,
                        cash_book_id INT NULL,
                        INDEX idx_project (project_id), INDEX idx_date (expense_date)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    try { $pdo->exec("ALTER TABLE project_expenses ADD COLUMN cash_book_id INT NULL"); } catch (Exception $ignore) {}
                    try { $pdo->exec("ALTER TABLE project_expenses ADD COLUMN division_name VARCHAR(100)"); } catch (Exception $ignore) {}
                    
                    // Detect actual column names in project_expenses
                    $peCols = array_column($pdo->query("DESCRIBE project_expenses")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    
                    // Detect actual column names in projects table
                    $projCols = array_column($pdo->query("DESCRIBE projects")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    $pCodeCol = in_array('project_code', $projCols) ? 'project_code' : (in_array('code', $projCols) ? 'code' : null);
                    $pNameCol = in_array('project_name', $projCols) ? 'project_name' : (in_array('name', $projCols) ? 'name' : 'project_name');
                    
                    // Auto-create "Proyek Umum" if no project selected
                    $projId = $project_id_input;
                    if ($projId <= 0) {
                        $defProject = null;
                        if ($pCodeCol) {
                            $defStmt = $pdo->prepare("SELECT id FROM projects WHERE {$pCodeCol} = 'UMUM' LIMIT 1");
                            $defStmt->execute();
                            $defProject = $defStmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if ($defProject) {
                            $projId = (int)$defProject['id'];
                        } else {
                            $ic = [$pNameCol, 'status'];
                            $iv = ['Proyek Umum', 'ongoing'];
                            if ($pCodeCol) { $ic[] = $pCodeCol; $iv[] = 'UMUM'; }
                            if (in_array('description', $projCols)) { $ic[] = 'description'; $iv[] = 'Proyek default'; }
                            if (in_array('budget_idr', $projCols)) { $ic[] = 'budget_idr'; $iv[] = 0; }
                            if (in_array('created_by', $projCols)) { $ic[] = 'created_by'; $iv[] = $_SESSION['user_id'] ?? null; }
                            $ph = implode(',', array_fill(0, count($ic), '?'));
                            $pdo->prepare("INSERT INTO projects (" . implode(',', $ic) . ") VALUES ({$ph})")->execute($iv);
                            $projId = (int)$pdo->lastInsertId();
                        }
                    }
                    
                    // Get division name
                    $divName = '';
                    if ($division_id) {
                        $divStmt = $pdo->prepare("SELECT division_name FROM divisions WHERE id = ?");
                        $divStmt->execute([$division_id]);
                        $divName = $divStmt->fetchColumn() ?: '';
                    }
                    
                    // Build dynamic INSERT/UPDATE using actual column names from DESCRIBE
                    $hasCashBookId = in_array('cash_book_id', $peCols);
                    $hasDesc = in_array('description', $peCols);
                    $hasDivName = in_array('division_name', $peCols);
                    $hasExpDate = in_array('expense_date', $peCols);
                    $hasCreatedBy = in_array('created_by', $peCols);
                    $descVal = $description ?: $category_name_input;
                    
                    // Check existing record
                    $peRow = null;
                    if ($hasCashBookId) {
                        $chk = $pdo->prepare("SELECT id FROM project_expenses WHERE cash_book_id = ? LIMIT 1");
                        $chk->execute([$id]);
                        $peRow = $chk->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if ($peRow) {
                        $sc = ['project_id = ?', 'amount = ?'];
                        $sv = [$projId, $amount];
                        if ($hasDesc) { $sc[] = 'description = ?'; $sv[] = $descVal; }
                        if ($hasDivName) { $sc[] = 'division_name = ?'; $sv[] = $divName; }
                        if ($hasExpDate) { $sc[] = 'expense_date = ?'; $sv[] = $transaction_date; }
                        $sv[] = $peRow['id'];
                        $pdo->prepare("UPDATE project_expenses SET " . implode(', ', $sc) . " WHERE id = ?")->execute($sv);
                        $projectSyncResult = ' | 🏗️ Proyek diupdate';
                    } else {
                        $ic = ['project_id', 'amount'];
                        $iv = [$projId, $amount];
                        if ($hasDesc) { $ic[] = 'description'; $iv[] = $descVal; }
                        if ($hasDivName) { $ic[] = 'division_name'; $iv[] = $divName; }
                        if ($hasExpDate) { $ic[] = 'expense_date'; $iv[] = $transaction_date; }
                        if ($hasCreatedBy) { $ic[] = 'created_by'; $iv[] = $_SESSION['user_id'] ?? null; }
                        if ($hasCashBookId) { $ic[] = 'cash_book_id'; $iv[] = $id; }
                        $ph = implode(',', array_fill(0, count($ic), '?'));
                        $pdo->prepare("INSERT INTO project_expenses (" . implode(',', $ic) . ") VALUES ({$ph})")->execute($iv);
                        $projectSyncResult = ' | 🏗️ Tersinkron ke proyek';
                    }
                } else {
                    // Not a project expense → remove link if exists
                    try {
                        $pdo->prepare("DELETE FROM project_expenses WHERE cash_book_id = ?")->execute([$id]);
                    } catch (Exception $ignore) {}
                }
            } catch (Exception $projErr) {
                $projectSyncResult = ' | ⚠️ Sync proyek gagal: ' . $projErr->getMessage();
                error_log("Edit project sync error: " . $projErr->getMessage() . " | source_type={$newSourceType}, type={$transaction_type}, project_id={$project_id_input}");
            }
            
            $_SESSION['success'] = '✅ Transaksi berhasil diupdate' . $projectSyncResult;
            header('Location: index.php');
            exit;
            
        } catch (Exception $e) {
            try { $db->rollBack(); } catch (Exception $ignore) {}
            $_SESSION['error'] = '❌ Gagal update transaksi: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = implode('<br>', $errors);
    }
}

// Get divisions and categories
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");
$categories = $db->fetchAll("SELECT * FROM categories ORDER BY category_name");

// Get cash accounts from MASTER database (cash_accounts table is in adf_system, not business DB)
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get business ID dynamically
    $businessId = getMasterBusinessId();
    
    // Load cash accounts if we have a business ID
    if ($businessId) {
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? ORDER BY is_default_account DESC, account_name");
        $stmt->execute([$businessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts in edit.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $cashAccounts = []; // Empty array if query fails
}

// Load business configuration for project feature detection
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';

// Load investor projects (for hotel project expense editing)
$investorProjects = [];
if ($isHotel) {
    try {
        $pdo = $db->getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY, project_name VARCHAR(150) NOT NULL, project_code VARCHAR(50),
            description TEXT, budget_idr DECIMAL(15,2) DEFAULT 0,
            status ENUM('planning','ongoing','on_hold','completed','cancelled') DEFAULT 'planning',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        $descStmt = $pdo->query("DESCRIBE projects");
        $cols = array_column($descStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        
        $nameCol = in_array('project_name', $cols) ? 'project_name' : (in_array('name', $cols) ? 'name' : "'Unknown'");
        $codeCol = in_array('project_code', $cols) ? 'project_code' : (in_array('code', $cols) ? 'code' : "NULL");
        $statusCol = in_array('status', $cols) ? 'status' : "NULL";
        
        $sql = "SELECT id, {$nameCol} as project_name, {$codeCol} as project_code, {$statusCol} as status FROM projects ORDER BY id DESC";
        $investorProjects = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter out completed/cancelled in PHP
        $investorProjects = array_filter($investorProjects, function($p) {
            $s = strtolower($p['status'] ?? '');
            return !in_array($s, ['completed', 'cancelled']);
        });
        $investorProjects = array_values($investorProjects);
    } catch (Exception $e) {
        error_log("Edit cashbook project load error: " . $e->getMessage());
        $investorProjects = [];
    }
}

// Check if current transaction is already a project expense
$currentProjectId = 0;
$isCurrentProjectExpense = ($transaction['source_type'] === 'owner_project');
if ($isCurrentProjectExpense) {
    try {
        $pdo = $db->getConnection();
        $peRow = $pdo->prepare("SELECT project_id FROM project_expenses WHERE cash_book_id = ? LIMIT 1");
        $peRow->execute([$id]);
        $row = $peRow->fetch(PDO::FETCH_ASSOC);
        if ($row) $currentProjectId = (int)$row['project_id'];
    } catch (Exception $ignore) {}
}

include '../../includes/header.php';
?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1; color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        </div>
    </div>
<?php endif; ?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                ✏️ Edit Transaksi Kas
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Update data transaksi keuangan</p>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
            Kembali
        </a>
    </div>
</div>

<!-- Source Info -->
<?php if ($transaction['source_type'] !== 'manual'): ?>
<div style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); border-left: 4px solid #3b82f6; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <i data-feather="info" style="width: 20px; height: 20px; color: #1e40af;"></i>
        <div>
            <strong style="color: #1e3a8a;">Source:</strong>
            <span style="color: #1e40af;"><?php echo ucfirst(str_replace('_', ' ', $transaction['source_type'])); ?></span>
            <?php if ($transaction['source_id']): ?>
                <span style="color: #1e40af;"> (#<?php echo $transaction['source_id']; ?>)</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
            <!-- Tanggal -->
            <div class="form-group">
                <label class="form-label">Tanggal Transaksi *</label>
                <input type="date" name="transaction_date" class="form-control" value="<?php echo $transaction['transaction_date']; ?>" required>
            </div>

            <!-- Waktu -->
            <div class="form-group">
                <label class="form-label">Waktu Transaksi *</label>
                <input type="time" name="transaction_time" class="form-control" value="<?php echo $transaction['transaction_time']; ?>" required>
            </div>

            <!-- Tipe -->
            <div class="form-group">
                <label class="form-label">Tipe Transaksi *</label>
                <select name="transaction_type" class="form-control" required id="transaction_type">
                    <option value="">Pilih Tipe</option>
                    <option value="income" <?php echo $transaction['transaction_type'] == 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                    <option value="expense" <?php echo $transaction['transaction_type'] == 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                </select>
            </div>

            <!-- Divisi -->
            <div class="form-group">
                <label class="form-label">Divisi *</label>
                <select name="division_id" class="form-control" required>
                    <option value="">Pilih Divisi</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['id']; ?>" <?php echo $transaction['division_id'] == $div['id'] ? 'selected' : ''; ?>>
                            <?php echo $div['division_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Kategori/Nama -->
            <div class="form-group">
                <label class="form-label">Kategori/Nama *</label>
                <input type="text" name="category_name" id="category_name" class="form-control" 
                       value="<?php echo htmlspecialchars($transaction['category_name']); ?>" 
                       placeholder="Ketik kategori atau nama transaksi" 
                       list="category_suggestions" required autocomplete="off">
                <datalist id="category_suggestions">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category_name']); ?>" data-type="<?php echo $cat['category_type']; ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">💡 Ketik manual atau pilih dari saran yang muncul</small>
            </div>

            <?php if ($isHotel): ?>
            <!-- Project Expense Toggle -->
            <div class="form-group" id="projectExpenseGroup" style="<?php echo $transaction['transaction_type'] !== 'expense' ? 'display:none;' : ''; ?>">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem 0.75rem; background: <?php echo $isCurrentProjectExpense ? 'rgba(245,158,11,0.25)' : 'rgba(245,158,11,0.1)'; ?>; border: 1px solid <?php echo $isCurrentProjectExpense ? '#f59e0b' : 'rgba(245,158,11,0.3)'; ?>; border-radius: 8px; transition: all 0.2s;" id="projectToggleLabel">
                    <input type="checkbox" id="isProjectExpense" name="is_project_expense" value="1" <?php echo $isCurrentProjectExpense ? 'checked' : ''; ?> onchange="toggleProjectExpense()" style="width: 16px; height: 16px; accent-color: #f59e0b;">
                    <span style="font-size: 0.813rem; font-weight: 600; color: #92400e;">🏗️ Pengeluaran Proyek (bukan beban hotel)</span>
                </label>
                <div id="projectSelectWrapper" style="display: <?php echo $isCurrentProjectExpense ? 'block' : 'none'; ?>; margin-top: 0.5rem;">
                    <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; color: #92400e;">Pilih Proyek <span style="color: var(--danger);">*</span></label>
                    <select name="project_id" id="projectSelect" class="form-control" style="height: 36px; font-size: 0.813rem; border-color: #f59e0b;" <?php echo $isCurrentProjectExpense ? 'required' : ''; ?>>
                        <option value="">-- Pilih Proyek --</option>
                        <?php foreach ($investorProjects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>" <?php echo $currentProjectId == $proj['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(($proj['project_code'] ? $proj['project_code'] . ' - ' : '') . $proj['project_name']); ?>
                            <?php echo ' [' . ucfirst($proj['status']) . ']'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($investorProjects)): ?>
                    <div style="font-size: 0.72rem; color: #dc2626; margin-top: 0.25rem;">⚠️ Belum ada proyek. Buat dulu di menu <a href="<?php echo BASE_URL; ?>/modules/investor/index.php" style="color: #4f46e5; font-weight: 600;">Investor & Proyek</a></div>
                    <?php endif; ?>
                </div>
                <div id="projectExpenseNote" style="display: <?php echo $isCurrentProjectExpense ? 'block' : 'none'; ?>; font-size: 0.72rem; color: #f59e0b; margin-top: 0.25rem;">⚠️ Transaksi ini tidak masuk laporan P&L hotel, tapi tercatat di menu Investor & Proyek</div>
                <input type="hidden" name="source_type" id="sourceTypeHidden" value="<?php echo $isCurrentProjectExpense ? 'owner_project' : ''; ?>">
            </div>
            <?php endif; ?>

            <!-- Cash Account -->
            <div class="form-group">
                <label class="form-label">Akun Kas</label>
                <select name="cash_account_id" class="form-control">
                    <option value="">-- Pilih Akun Kas (opsional) --</option>
                    <?php if (empty($cashAccounts)): ?>
                        <option value="" disabled style="color: #dc2626;">⚠️ Tidak ada akun kas tersedia. Hubungi admin!</option>
                    <?php else: ?>
                        <?php foreach ($cashAccounts as $acc): ?>
                            <option value="<?php echo htmlspecialchars($acc['id']); ?>" 
                                    <?php echo (!empty($transaction['cash_account_id']) && $transaction['cash_account_id'] == $acc['id'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($acc['account_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($cashAccounts)): ?>
                    <small style="color: #dc2626; display: block; margin-top: 0.25rem; padding: 0.5rem; background: #fee2e2; border-radius: 4px; border-left: 3px solid #dc2626;">
                        <strong>⚠️ Error:</strong> Akun kas tidak ditemukan di database master. Pastikan cash_accounts sudah di-setup untuk bisnis ini.
                    </small>
                <?php else: ?>
                    <small style="color: var(--text-muted); display: block; margin-top: 0.25rem;">💡 Pilih akun untuk tracking yang lebih detail</small>
                <?php endif; ?>
            </div>

            <!-- Payment Method -->
            <div class="form-group">
                <label class="form-label">Metode Pembayaran *</label>
                <select name="payment_method" class="form-control" required>
                    <optgroup label="Umum">
                        <option value="cash" <?php echo $transaction['payment_method'] == 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="debit" <?php echo $transaction['payment_method'] == 'debit' ? 'selected' : ''; ?>>Debit</option>
                        <option value="transfer" <?php echo $transaction['payment_method'] == 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                        <option value="qr" <?php echo $transaction['payment_method'] == 'qr' ? 'selected' : ''; ?>>QR Code</option>
                        <option value="edc" <?php echo $transaction['payment_method'] == 'edc' ? 'selected' : ''; ?>>EDC</option>
                    </optgroup>
                    <optgroup label="OTA (Online Travel Agent)">
                        <option value="OTA tiket.com" <?php echo $transaction['payment_method'] == 'OTA tiket.com' ? 'selected' : ''; ?>>OTA tiket.com</option>
                        <option value="OTA Agoda" <?php echo $transaction['payment_method'] == 'OTA Agoda' ? 'selected' : ''; ?>>OTA Agoda</option>
                        <option value="OTA Booking.com" <?php echo $transaction['payment_method'] == 'OTA Booking.com' ? 'selected' : ''; ?>>OTA Booking.com</option>
                        <option value="OTA Traveloka" <?php echo $transaction['payment_method'] == 'OTA Traveloka' ? 'selected' : ''; ?>>OTA Traveloka</option>
                        <option value="OTA Airbnb" <?php echo $transaction['payment_method'] == 'OTA Airbnb' ? 'selected' : ''; ?>>OTA Airbnb</option>
                        <option value="OTA Expedia" <?php echo $transaction['payment_method'] == 'OTA Expedia' ? 'selected' : ''; ?>>OTA Expedia</option>
                        <option value="OTA Pegipegi" <?php echo $transaction['payment_method'] == 'OTA Pegipegi' ? 'selected' : ''; ?>>OTA Pegipegi</option>
                    </optgroup>
                    <optgroup label="Lainnya">
                        <option value="other" <?php echo $transaction['payment_method'] == 'other' ? 'selected' : ''; ?>>Lainnya</option>
                    </optgroup>
                </select>
            </div>

            <!-- Amount -->
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Jumlah *</label>
                <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?php echo $transaction['amount']; ?>" required>
            </div>

            <!-- Description -->
            <div class="form-group" style="grid-column: span 2;">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-control" rows="3"><?php echo $transaction['description']; ?></textarea>
            </div>
        </div>

        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
            <a href="index.php" class="btn btn-secondary">
                <i data-feather="x" style="width: 16px; height: 16px;"></i>
                Batal
            </a>
            <button type="submit" class="btn btn-primary">
                <i data-feather="save" style="width: 16px; height: 16px;"></i>
                Update Transaksi
            </button>
        </div>
    </form>
</div>

<script>
    feather.replace();
    
    // Store all categories for filtering
    const allCategories = [
        <?php foreach ($categories as $cat): ?>
        { name: "<?php echo addslashes($cat['category_name']); ?>", type: "<?php echo $cat['category_type']; ?>" },
        <?php endforeach; ?>
    ];
    
    function updateCategorySuggestions() {
        const type = document.getElementById('transaction_type').value;
        const datalist = document.getElementById('category_suggestions');
        
        // Clear existing options
        datalist.innerHTML = '';
        
        // Add filtered options
        allCategories.forEach(cat => {
            if (type === '' || cat.type === type) {
                const option = document.createElement('option');
                option.value = cat.name;
                datalist.appendChild(option);
            }
        });
    }
    
    // Update suggestions when transaction type changes
    document.getElementById('transaction_type').addEventListener('change', updateCategorySuggestions);
    
    // Initialize on page load
    updateCategorySuggestions();
    
    <?php if ($isHotel): ?>
    // Toggle project expense checkbox
    function toggleProjectExpense() {
        const checked = document.getElementById('isProjectExpense').checked;
        const wrapper = document.getElementById('projectSelectWrapper');
        const label = document.getElementById('projectToggleLabel');
        const sourceField = document.getElementById('sourceTypeHidden');
        const note = document.getElementById('projectExpenseNote');
        const projectSelect = document.getElementById('projectSelect');
        
        if (wrapper) wrapper.style.display = checked ? 'block' : 'none';
        if (note) note.style.display = checked ? 'block' : 'none';
        label.style.background = checked ? 'rgba(245,158,11,0.25)' : 'rgba(245,158,11,0.1)';
        label.style.borderColor = checked ? '#f59e0b' : 'rgba(245,158,11,0.3)';
        sourceField.value = checked ? 'owner_project' : '';
        
        if (projectSelect) {
            projectSelect.required = (checked && projectSelect.options.length > 1);
            if (!checked) projectSelect.value = '';
        }
    }
    
    // Show/hide project expense toggle based on transaction type
    document.getElementById('transaction_type').addEventListener('change', function() {
        const projectGroup = document.getElementById('projectExpenseGroup');
        const checkbox = document.getElementById('isProjectExpense');
        if (projectGroup) {
            if (this.value === 'expense') {
                projectGroup.style.display = 'block';
            } else {
                projectGroup.style.display = 'none';
                if (checkbox && checkbox.checked) {
                    checkbox.checked = false;
                    toggleProjectExpense();
                }
            }
        }
    });
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
