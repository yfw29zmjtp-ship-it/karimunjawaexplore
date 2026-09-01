<?php

/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Add New Transaction
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Auto-drop FK constraint on cash_book.created_by (references users in master DB, not business DB)
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
} catch (Exception $e) { /* already dropped or doesn't exist */
}

// Auto-fix: allow NULL division_id/category_id - Setor Tunai (cash_transfer) rows
// have no division/category since they're an internal cash<->bank transfer, not a
// real income/expense transaction. Original schema had these NOT NULL.
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
} catch (Exception $e) { /* ignore */
}
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
} catch (Exception $e) { /* ignore */
}

// Auto-fix: Convert payment_method ENUM to VARCHAR (ENUM misses edc, ota, etc.)
try {
    $colInfo = $db->fetchOne("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_book' AND COLUMN_NAME = 'payment_method'");
    if ($colInfo && stripos($colInfo['COLUMN_TYPE'], 'enum') !== false) {
        $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash'");
    }
} catch (Exception $e) { /* ignore */
}

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// ============================================
// BUSINESS FEATURE DETECTION (CONFIG-BASED)
// Uses enabled_modules and business_type from config
// NOT hardcoded business ID - allows proper isolation
// ============================================
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';

// Legacy compatibility - use feature flags for conditional logic
$isCQC = $hasProjectModule; // Only true if business has cqc-projects module enabled

$pageTitle = $hasProjectModule ? '☀️ Input Transaksi Proyek' : 'Tambah Transaksi';
$pageSubtitle = $hasProjectModule ? 'Catat pemasukan & pengeluaran proyek solar panel' : 'Input Transaksi Baru';

// ============================================
// HANDLE AJAX REQUESTS
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// AJAX: Add new bank account
if ($action === 'add_bank_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $accountName = $_POST['account_name'] ?? null;
    $bizId = getMasterBusinessId();

    if (!$accountName || strlen(trim($accountName)) < 3) {
        echo json_encode(['success' => false, 'message' => 'Nama rekening minimal 3 karakter']);
        exit;
    }

    try {
        // Connect to master DB
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Insert new bank account
        $stmt = $masterDb->prepare("
            INSERT INTO cash_accounts (business_id, account_name, account_type, current_balance, is_active)
            VALUES (?, ?, 'bank', 0, 1)
        ");
        $stmt->execute([$bizId, trim($accountName)]);

        echo json_encode(['success' => true, 'message' => 'Rekening berhasil ditambahkan']);
    } catch (Exception $e) {
        error_log("Add Bank Account Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// AJAX: Get bank accounts (for dropdown refresh)
if ($action === 'get_bank_accounts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');

    $bizId = $_GET['biz_id'] ?? getMasterBusinessId();

    try {
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $masterDb->prepare("
            SELECT id, account_name FROM cash_accounts 
            WHERE business_id = ? AND account_type = 'bank' AND is_active = 1 
            ORDER BY account_name
        ");
        $stmt->execute([$bizId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'accounts' => $accounts]);
    } catch (Exception $e) {
        error_log("Get Bank Accounts Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get divisions and categories
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Load investor projects (for non-hotel expense linking)
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

        // Detect actual column names (table may have 'name' instead of 'project_name', etc.)
        $descStmt = $pdo->query("DESCRIBE projects");
        $cols = array_column($descStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        $nameCol = in_array('project_name', $cols) ? 'project_name' : (in_array('name', $cols) ? 'name' : "'Unknown'");
        $codeCol = in_array('project_code', $cols) ? 'project_code' : (in_array('code', $cols) ? 'code' : "NULL");
        $statusCol = in_array('status', $cols) ? 'status' : "NULL";

        $sql = "SELECT id, {$nameCol} as project_name, {$codeCol} as project_code, {$statusCol} as status FROM projects ORDER BY id DESC";
        $investorProjects = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Filter out completed/cancelled in PHP (safer than SQL with unknown ENUM)
        $investorProjects = array_filter($investorProjects, function ($p) {
            $s = strtolower($p['status'] ?? '');
            return !in_array($s, ['completed', 'cancelled']);
        });
        $investorProjects = array_values($investorProjects);
    } catch (Exception $e) {
        error_log("Cashbook project load error: " . $e->getMessage());
        $investorProjects = [];
    }
}

// Project Module: Load projects and expense categories (only if module enabled)
$cqcProjects = [];
$cqcCategories = [];
if ($hasProjectModule) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        $stmt = $cqcPdo->query("SELECT id, project_name, project_code, client_name, status, budget_idr, spent_idr FROM cqc_projects ORDER BY status != 'installation' ASC, project_name ASC");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $cqcPdo->query("SELECT id, category_name, category_icon FROM cqc_expense_categories WHERE is_active = 1 ORDER BY id");
        $cqcCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Project module load error: ' . $e->getMessage());
    }
}

// Get cash accounts from MASTER database (cash_accounts table is in adf_system, not business DB)
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get business ID dynamically
    $businessId = getMasterBusinessId();

    // Load cash accounts if we have a business ID
    if ($businessId) {
        // Show cash and bank accounts only (exclude owner_capital - not for direct selection)
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND is_active = 1 AND account_type IN ('cash', 'bank', 'e-wallet', 'credit_card') ORDER BY account_type = 'cash' DESC, account_type = 'bank' DESC, account_name");
        $stmt->execute([$businessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    $cashAccounts = []; // Empty array if query fails
}

// Handle form submission
if (isPost()) {
    $transactionDate = sanitize(getPost('transaction_date'));
    $transactionTime = sanitize(getPost('transaction_time'));
    $divisionId = sanitize(getPost('division_id'));
    $categoryName = sanitize(getPost('category_name'));
    $transactionType = sanitize(getPost('transaction_type'));
    $amount = str_replace(['.', ','], '', getPost('amount')); // Remove formatting
    $description = sanitize(getPost('description'));
    $paymentMethod = sanitize(getPost('payment_method'));
    $cashAccountId = sanitize(getPost('cash_account_id')) ?: null; // Optional: can be null for now
    $sourceType = sanitize(getPost('source_type'));
    $penyetor = sanitize(getPost('setor_penyetor')); // Nama penyetor for cash_transfer

    // ============================================
    // EARLY RETURN: CASH TRANSFER (SETOR TUNAI)
    // Skip regular validation - only need amount, accounts, date/time
    // ============================================
    if ($sourceType === 'cash_transfer') {
        if (empty($transactionDate) || empty($amount) || empty($cashAccountId)) {
            setFlash('error', 'Data setor tunai tidak lengkap! Diperlukan: tanggal, nominal, dan rekening kas sumber.');
        } else {
            try {
                // Connect to master DB
                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $bizId = getMasterBusinessId();

                // Auto-migrate: link column so this cash_transfers row remembers which
                // cash_book row (business DB) it created - used by cash-transfers.php's
                // delete feature to also remove/reverse the ledger entry.
                try {
                    $masterDb->exec("ALTER TABLE cash_transfers ADD COLUMN cash_book_id INT NULL AFTER archived_by");
                } catch (Exception $e) { /* column already exists */
                }

                // Get destination account (bank) - use explicit bank_account_id if provided
                $bankAccountId = sanitize(getPost('bank_account_id')) ?: null;
                if ($bankAccountId) {
                    $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE id = ? AND business_id = ? AND account_type = 'bank' AND is_active = 1");
                    $stmt->execute([$bankAccountId, $bizId]);
                } else {
                    $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' AND is_active = 1 ORDER BY id LIMIT 1");
                    $stmt->execute([$bizId]);
                }
                $destAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$destAccount) {
                    throw new Exception("Tidak ada rekening bank untuk penerima transfer");
                }

                // Get source account info
                $stmt = $masterDb->prepare("SELECT account_name FROM cash_accounts WHERE id = ?");
                $stmt->execute([$cashAccountId]);
                $sourceAcct = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sourceAcct) {
                    throw new Exception("Akun kas sumber tidak ditemukan");
                }

                // STEP 1: Debit from source cash account
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $cashAccountId]);
                error_log("SETOR TUNAI: Debit from {$sourceAcct['account_name']} (ID: {$cashAccountId}) - Amount: {$amount} - Penyetor: {$penyetor}");

                // STEP 2: Credit to destination bank account
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $destAccount['id']]);
                error_log("SETOR TUNAI: Credit to {$destAccount['account_name']} (ID: {$destAccount['id']}) - Amount: {$amount}");

                // STEP 3: Record in cash_transfers table for tracking & archiving
                $refNum = 'ST-' . date('YmdHis') . '-' . $_SESSION['user_id']; // Reference tracking

                $transferStmt = $masterDb->prepare("
                    INSERT INTO cash_transfers 
                    (business_id, cash_account_id, bank_account_id, amount, transfer_date, transfer_time, reference_number, description, created_by, is_archived)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                ");

                $transferStmt->execute([
                    $bizId,
                    $cashAccountId,
                    $destAccount['id'],
                    $amount,
                    $transactionDate,
                    $transactionTime ?: date('H:i:s'),
                    $refNum,
                    "[{$penyetor}] " . ($description ?: 'Setor tunai dari kas cabang ke rekening operasional'),
                    $_SESSION['user_id']
                ]);

                $transferId = $masterDb->lastInsertId();

                error_log("SETOR TUNAI: Recorded in cash_transfers - From {$sourceAcct['account_name']} to {$destAccount['account_name']} - Amount: {$amount} - Penyetor: {$penyetor}");

                // STEP 4: Insert into cash_book (business DB) so "Cash Available" on the
                // Buku Kas page reduces correctly. transaction_type='expense' makes the
                // cash balance math work, but source_type='cash_transfer' marks it so the
                // UI shows a distinct "Setor Tunai" badge/color and EXCLUDES it from
                // Total Pengeluaran (it's an internal transfer, not a real business expense).
                try {
                    $cashBookData = [
                        'transaction_date' => $transactionDate,
                        'transaction_time' => $transactionTime ?: date('H:i:s'),
                        'division_id' => null,
                        'category_id' => null,
                        'transaction_type' => 'expense',
                        'amount' => $amount,
                        'description' => "Pemindahan Uang / Setoran Harian ke {$destAccount['account_name']} - Penyetor: {$penyetor}" . ($description ? " - {$description}" : ''),
                        'payment_method' => 'cash',
                        'cash_account_id' => $cashAccountId,
                        'created_by' => $_SESSION['user_id'],
                        'source_type' => 'cash_transfer',
                        'is_editable' => 0
                    ];
                    if ($db->insert('cash_book', $cashBookData)) {
                        $newCashBookId = $db->getConnection()->lastInsertId();
                        $masterDb->prepare("UPDATE cash_transfers SET cash_book_id = ? WHERE id = ?")
                            ->execute([$newCashBookId, $transferId]);
                    }
                } catch (Exception $cbEx) {
                    error_log("SETOR TUNAI: Failed to insert cash_book tracking row: " . $cbEx->getMessage());
                    // Non-fatal: the transfer itself (cash_accounts + cash_transfers) already succeeded
                }

                setFlash('success', '✅ Setor tunai berhasil dicatat! Nominal: ' . number_format($amount, 0, ',', '.') . ' - Penyetor: ' . $penyetor);

                // Redirect to ringkasan page
                redirect('cash-transfers.php');
                exit;
            } catch (Exception $e) {
                error_log("SETOR TUNAI ERROR: " . $e->getMessage());
                setFlash('error', 'Error setor tunai: ' . $e->getMessage());
            }
        }

        // Any cash_transfer path that didn't already redirect (validation failure or
        // caught exception) must stop here - otherwise execution falls through to the
        // generic transaction validation below, which overwrites the flash message with
        // "Mohon lengkapi semua field yang wajib diisi!" because division_id/category_name
        // are intentionally empty for Setor Tunai.
        redirect('add.php');
        exit;
    }

    // Validation
    if (empty($transactionDate) || empty($divisionId) || empty($categoryName) || empty($amount)) {
        setFlash('error', 'Mohon lengkapi semua field yang wajib diisi!');
    } else {
        try {
            // Check if category exists, create if not
            $existingCategory = $db->fetchOne(
                "SELECT id FROM categories WHERE LOWER(category_name) = LOWER(:name) AND category_type = :type",
                ['name' => $categoryName, 'type' => $transactionType]
            );

            if ($existingCategory) {
                $categoryId = $existingCategory['id'];
            } else {
                // Create new category
                $db->insert('categories', [
                    'category_name' => $categoryName,
                    'category_type' => $transactionType,
                    'division_id' => $divisionId,
                    'is_active' => 1
                ]);

                // Get the newly inserted ID
                $categoryId = $db->getConnection()->lastInsertId();
            }

            // CQC: Embed project reference in description for list page lookup
            if ($isCQC && !empty(getPost('cqc_project_id'))) {
                $cqcProjVal = getPost('cqc_project_id');
                if ($cqcProjVal === 'operational') {
                    // Operational Office - non-project expense
                    $description = '[OPERATIONAL_OFFICE] ' . $description;
                } else {
                    $cqcProjId = intval($cqcProjVal);
                    $description = '[CQC_PROJECT:' . $cqcProjId . '] ' . $description;
                }
            }

            // ============================================
            // SMART SOURCE_TYPE DETECTION FOR ALL BUSINESSES
            // Owner top-up to Kas Operasional = 'owner_fund' (NOT company income)
            // Regular income (from customers) = 'manual' or 'invoice_payment'
            // ============================================
            $sourceType = 'manual';

            // Check if source_type is explicitly set
            $explicitSourceType = sanitize(getPost('source_type'));
            if ($explicitSourceType === 'owner_fund') {
                $sourceType = 'owner_fund';
            } elseif ($explicitSourceType === 'owner_project') {
                $sourceType = 'owner_project';
            } elseif ($explicitSourceType === 'cash_transfer') {
                $sourceType = 'cash_transfer';
            }
            // For CQC with project module: use cqc_income_type field
            elseif ($hasProjectModule && $transactionType === 'income') {
                $cqcIncomeType = getPost('cqc_income_type') ?? '';
                if ($cqcIncomeType === 'topup_owner') {
                    $sourceType = 'owner_fund';
                } elseif (in_array($cqcIncomeType, ['dp', 'termin', 'pelunasan', 'retensi'])) {
                    $sourceType = 'invoice_payment';
                }
            }

            // NOTE: source_type is determined by:
            // 1. Explicit owner_fund from "Input dari Bu Sita" button
            // 2. Explicit owner_project from project expense toggle
            // 3. CQC topup_owner for contractor businesses
            // 4. Default 'manual' for regular income

            $data = [
                'transaction_date' => $transactionDate,
                'transaction_time' => $transactionTime ?: date('H:i:s'),
                'division_id' => $divisionId,
                'category_id' => $categoryId,
                'transaction_type' => $transactionType,
                'amount' => $amount,
                'description' => $description,
                'payment_method' => $paymentMethod,
                'cash_account_id' => $cashAccountId,
                'created_by' => $_SESSION['user_id'],
                'source_type' => $sourceType,
                'is_editable' => 1
            ];

            // ============================================
            // SMART LOGIC - INCOME BASED ON PAYMENT METHOD
            // Cash payment → goes to Petty Cash (operational)
            // Non-cash payment → goes to Bank (not operational cash)
            // EXCEPTION: owner_fund (Transfer Petty Cash) ALWAYS goes to Petty Cash
            // ============================================
            if ($transactionType === 'income') {
                try {
                    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $businessId = getMasterBusinessId();

                    // OWNER_FUND: Always goes to Petty Cash regardless of payment method
                    if ($sourceType === 'owner_fund') {
                        $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' ORDER BY id LIMIT 1");
                        $stmt->execute([$businessId]);
                        $pettyCashAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($pettyCashAccount) {
                            $cashAccountId = $pettyCashAccount['id'];
                            $data['cash_account_id'] = $cashAccountId;
                            error_log("TRANSFER PETTY CASH: Always goes to Petty Cash ({$pettyCashAccount['account_name']})");
                        }
                    } elseif ($paymentMethod === 'cash') {
                        // CASH PAYMENT: Income goes to Petty Cash (operational cash)
                        $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' ORDER BY id LIMIT 1");
                        $stmt->execute([$businessId]);
                        $pettyCashAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($pettyCashAccount) {
                            $cashAccountId = $pettyCashAccount['id'];
                            $data['cash_account_id'] = $cashAccountId;
                            // Only override source_type if not explicitly set to owner_fund
                            if ($sourceType !== 'owner_fund') {
                                $data['source_type'] = 'manual'; // Real income, not owner fund
                            }
                            error_log("SMART LOGIC - CASH payment: Income goes to Petty Cash ({$pettyCashAccount['account_name']})");
                        }
                    } else {
                        // NON-CASH PAYMENT (Debit, Transfer, QR, EDC): Income goes to Bank account
                        $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' ORDER BY id LIMIT 1");
                        $stmt->execute([$businessId]);
                        $bankAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($bankAccount) {
                            $cashAccountId = $bankAccount['id'];
                            $data['cash_account_id'] = $cashAccountId;
                            // Only override source_type if not explicitly set to owner_fund
                            if ($sourceType !== 'owner_fund') {
                                $data['source_type'] = 'manual'; // Real income
                            }
                            error_log("SMART LOGIC - NON-CASH payment ({$paymentMethod}): Income goes to Bank ({$bankAccount['account_name']})");
                        } else {
                            // No bank account, just record without cash_account_id
                            $data['cash_account_id'] = null;
                            error_log("SMART LOGIC - NON-CASH payment: No bank account, recording without cash_account_id");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Income smart logic error: " . $e->getMessage());
                }
            }

            // ============================================
            // USE USER'S ACCOUNT SELECTION DIRECTLY
            // User explicitly selects Petty Cash or Bank from dropdown
            // DO NOT override based on payment method
            // ============================================
            if ($transactionType === 'expense') {
                // Get the selected account type to determine fund source tag
                $fundTag = '[Petty Cash]'; // Default
                if (!empty($cashAccountId)) {
                    try {
                        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $stmt = $masterDb->prepare("SELECT account_type, account_name FROM cash_accounts WHERE id = ?");
                        $stmt->execute([$cashAccountId]);
                        $selectedAccount = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($selectedAccount) {
                            if ($selectedAccount['account_type'] === 'cash') {
                                $fundTag = '[Petty Cash]';
                                error_log("EXPENSE: User selected Petty Cash ({$selectedAccount['account_name']})");
                            } elseif ($selectedAccount['account_type'] === 'bank') {
                                // Check if this bank account is a Setor Tunai destination (Rekening Operasional)
                                // vs a regular Kas Besar bank account
                                $bizIdForCheck = getMasterBusinessId();
                                $opChk = $masterDb->prepare("
                                    SELECT COUNT(*) FROM cash_transfers
                                    WHERE business_id = ? AND bank_account_id = ?
                                    LIMIT 1
                                ");
                                $opChk->execute([$bizIdForCheck, $cashAccountId]);
                                $isOperational = (int)$opChk->fetchColumn() > 0;

                                if ($isOperational) {
                                    $fundTag = '[Rekening Operasional]';
                                    error_log("EXPENSE: User selected Rekening Operasional ({$selectedAccount['account_name']})");
                                } else {
                                    $fundTag = '[Kas Besar]';
                                    error_log("EXPENSE: User selected Kas Besar ({$selectedAccount['account_name']})");
                                }
                            } else {
                                $fundTag = '[Kas Besar]';
                                error_log("EXPENSE: User selected other account ({$selectedAccount['account_name']})");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error checking account type: " . $e->getMessage());
                    }
                }

                // Add fund source tag to description for tracking
                $existingDesc = $data['description'] ?? '';
                $tagAlreadyPresent = strpos($existingDesc, '[Petty Cash]') !== false
                    || strpos($existingDesc, '[Kas Besar]') !== false
                    || strpos($existingDesc, '[Rekening Operasional]') !== false;
                if (!$tagAlreadyPresent) {
                    $data['description'] = trim($existingDesc . ' ' . $fundTag);
                    $description = $data['description'];
                }
            }
            // ============================================

            // ============================================
            // ============================================

            // Start transaction for atomic operation
            $db->beginTransaction();

            try {
                // Insert to cash_book (business database)
                if ($db->insert('cash_book', $data)) {
                    $transactionId = $db->getConnection()->lastInsertId();

                    // If user selected a cash account, also save to cash_account_transactions (master DB)
                    if (!empty($cashAccountId)) {
                        error_log("PROCESSING cash account transaction - Account ID: {$cashAccountId}, Type: {$transactionType}, Amount: {$amount}");

                        try {
                            $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                            // Get account type to determine transaction type
                            $stmt = $masterDb->prepare("SELECT account_type FROM cash_accounts WHERE id = ?");
                            $stmt->execute([$cashAccountId]);
                            $account = $stmt->fetch(PDO::FETCH_ASSOC);

                            // Determine transaction type for cash_account_transactions
                            $accountTransactionType = $transactionType; // Default: 'income' or 'expense'

                            // ONLY owner_capital account income = capital_injection
                            // Petty Cash income from customers = real income (not capital_injection)
                            if ($account && $account['account_type'] === 'owner_capital' && $transactionType === 'income') {
                                $accountTransactionType = 'capital_injection';
                                error_log("SMART LOGIC - Owner Capital income = capital injection");
                            }
                            // Cash account income from customers via cash payment = real income
                            // This adds to petty cash balance for operational use

                            // Insert to cash_account_transactions
                            $stmt = $masterDb->prepare("
                                INSERT INTO cash_account_transactions 
                                (cash_account_id, transaction_date, description, amount, transaction_type, created_by) 
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");

                            $stmt->execute([
                                $cashAccountId,
                                $transactionDate,
                                $data['description'] ?: $categoryName, // Use updated description from $data
                                $amount,
                                $accountTransactionType,
                                $_SESSION['user_id']
                            ]);

                            error_log("SAVED to cash_account_transactions - Account ID: {$cashAccountId}, Type: {$accountTransactionType}, Amount: {$amount}");

                            // Update current_balance in cash_accounts
                            if ($transactionType === 'income') {
                                // Income: add to balance
                                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                                $stmt->execute([$amount, $cashAccountId]);
                                error_log("BALANCE UPDATED - Account ID: {$cashAccountId} - INCOME +{$amount}");

                                // ============================================
                                // TRANSFER PETTY CASH / MODAL OWNER
                                // CQC: Transfer dari Bank (invoice income)
                                // Narayana: Modal dari Bu Sita (owner_capital)
                                // ============================================
                                if ($sourceType === 'owner_fund') {
                                    $bizId = getMasterBusinessId();

                                    if ($isCQC) {
                                        // CQC: Transfer ke Petty Cash dari BANK (Kas Besar)
                                        // Karena di CQC, uang invoice masuk ke Bank, bukan dari owner
                                        $stmt = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' ORDER BY id LIMIT 1");
                                        $stmt->execute([$bizId]);
                                        $sourceAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                                        $sourceLabel = 'Bank';
                                    } else {
                                        // Narayana: Modal dari Bu Sita dari OWNER_CAPITAL
                                        // Ini uang dari owner yang ditambahkan ke kas operasional
                                        $stmt = $masterDb->prepare("SELECT id, account_name, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital' ORDER BY id LIMIT 1");
                                        $stmt->execute([$bizId]);
                                        $sourceAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                                        $sourceLabel = 'Kas Modal Owner';
                                    }

                                    if ($sourceAccount) {
                                        // Record expense transaction from source account
                                        $stmt = $masterDb->prepare("
                                            INSERT INTO cash_account_transactions 
                                            (cash_account_id, transaction_date, description, amount, transaction_type, created_by) 
                                            VALUES (?, ?, ?, ?, 'expense', ?)
                                        ");
                                        $transferDesc = $isCQC
                                            ? 'Transfer ke Petty Cash' . ($data['description'] ? ': ' . $data['description'] : '')
                                            : 'Modal dari Bu Sita' . ($data['description'] ? ': ' . $data['description'] : '');
                                        $stmt->execute([
                                            $sourceAccount['id'],
                                            $transactionDate,
                                            $transferDesc,
                                            $amount,
                                            $_SESSION['user_id']
                                        ]);

                                        // Deduct from source account
                                        $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                                        $stmt->execute([$amount, $sourceAccount['id']]);

                                        error_log("TRANSFER/MODAL: {$sourceLabel} ({$sourceAccount['account_name']}) -{$amount}, Petty Cash +{$amount}");
                                    } else {
                                        error_log("WARNING: No {$sourceLabel} account found for transfer deduction");
                                    }
                                }
                                // ============================================
                            } else {
                                // Expense: subtract from balance (smart logic already handled account switch)
                                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                                $stmt->execute([$amount, $cashAccountId]);
                                error_log("BALANCE UPDATED - Account ID: {$cashAccountId} - EXPENSE -{$amount}");
                            }
                        } catch (Exception $e) {
                            error_log("ERROR saving to cash_account_transactions: " . $e->getMessage());
                            error_log("ERROR Stack Trace: " . $e->getTraceAsString());
                            // Rethrow to fail the transaction if balance update fails
                            throw new Exception("Gagal update balance akun: " . $e->getMessage());
                        }
                    } else {
                        error_log("WARNING: cash_account_id is empty, skipping cash account transaction");
                    }

                    // INVESTOR PROJECT: Sync expense to project_expenses table
                    $investorProjectId = intval(getPost('project_id'));
                    if ($sourceType === 'owner_project' && $transactionType === 'expense') {
                        try {
                            // Commit current transaction first (DDL causes implicit commit anyway)
                            $db->commit();

                            $pdo = $db->getConnection();

                            // Ensure projects table exists (DDL - outside transaction)
                            $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
                                id INT AUTO_INCREMENT PRIMARY KEY, project_name VARCHAR(150) NOT NULL, project_code VARCHAR(50),
                                description TEXT, budget_idr DECIMAL(15,2) DEFAULT 0,
                                status ENUM('planning','ongoing','on_hold','completed','cancelled') DEFAULT 'planning',
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                created_by INT, INDEX idx_status (status)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                            // Ensure project_expenses table exists (DDL - outside transaction)
                            $pdo->exec("CREATE TABLE IF NOT EXISTS project_expenses (
                                id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL, category_id INT,
                                amount DECIMAL(15,2) NOT NULL, description TEXT, division_name VARCHAR(100),
                                expense_date DATE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_by INT,
                                cash_book_id INT NULL,
                                INDEX idx_project (project_id), INDEX idx_date (expense_date)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                            // Add columns if not exists (DDL - outside transaction)
                            try {
                                $pdo->exec("ALTER TABLE project_expenses ADD COLUMN cash_book_id INT NULL");
                            } catch (Exception $ignore) {
                            }
                            try {
                                $pdo->exec("ALTER TABLE project_expenses ADD COLUMN division_name VARCHAR(100)");
                            } catch (Exception $ignore) {
                            }

                            // If no project selected, auto-create/use "Proyek Umum"
                            if ($investorProjectId <= 0) {
                                $defStmt = $pdo->prepare("SELECT id FROM projects WHERE project_code = 'UMUM' LIMIT 1");
                                $defStmt->execute();
                                $defaultProject = $defStmt->fetch(PDO::FETCH_ASSOC);
                                if ($defaultProject) {
                                    $investorProjectId = (int)$defaultProject['id'];
                                } else {
                                    $createStmt = $pdo->prepare("INSERT INTO projects (project_name, project_code, description, budget_idr, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                                    $createStmt->execute(['Proyek Umum', 'UMUM', 'Proyek default untuk pengeluaran proyek dari kas besar', 0, 'ongoing', $_SESSION['user_id'] ?? null]);
                                    $investorProjectId = (int)$pdo->lastInsertId();
                                    error_log("PROJECT AUTO-CREATE: Created default 'Proyek Umum' with ID #{$investorProjectId}");
                                }
                            }

                            // Get division name for project_expenses
                            $divName = '';
                            if ($divisionId) {
                                $divStmt = $pdo->prepare("SELECT division_name FROM divisions WHERE id = ?");
                                $divStmt->execute([$divisionId]);
                                $divName = $divStmt->fetchColumn() ?: '';
                            }

                            $peStmt = $pdo->prepare("INSERT INTO project_expenses (project_id, amount, description, division_name, expense_date, created_by, cash_book_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $peStmt->execute([$investorProjectId, $amount, ($description ?: $categoryName), $divName, $transactionDate, $_SESSION['user_id'], $transactionId]);
                            error_log("PROJECT SYNC: Cashbook #{$transactionId} -> project_expenses for project #{$investorProjectId}, Amount: {$amount}");

                            // Auto-sync to project_salaries if division is "Gaji"
                            if (stripos($divName, 'gaji') !== false || stripos($divName, 'salary') !== false) {
                                try {
                                    // Ensure tables exist
                                    $pdo->exec("CREATE TABLE IF NOT EXISTS project_workers (
                                        id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL,
                                        name VARCHAR(100) NOT NULL, role VARCHAR(100) DEFAULT 'Tukang',
                                        daily_rate DECIMAL(15,2) DEFAULT 0, phone VARCHAR(20),
                                        status ENUM('active','inactive') DEFAULT 'active',
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                    $pdo->exec("CREATE TABLE IF NOT EXISTS project_salaries (
                                        id INT AUTO_INCREMENT PRIMARY KEY, project_id INT NOT NULL,
                                        worker_id INT NOT NULL, period_type ENUM('weekly','monthly') DEFAULT 'weekly',
                                        period_label VARCHAR(50), daily_rate DECIMAL(15,2) DEFAULT 0,
                                        overtime_per_day DECIMAL(15,2) DEFAULT 0, other_per_day DECIMAL(15,2) DEFAULT 0,
                                        total_days INT DEFAULT 0, total_salary DECIMAL(15,2) DEFAULT 0,
                                        status ENUM('draft','submitted','approved','paid') DEFAULT 'draft',
                                        notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_by INT
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                                    // Find or create default worker for this project
                                    $wStmt = $pdo->prepare("SELECT id FROM project_workers WHERE project_id = ? AND name = 'Gaji dari Kas' LIMIT 1");
                                    $wStmt->execute([$investorProjectId]);
                                    $workerId = $wStmt->fetchColumn();
                                    if (!$workerId) {
                                        $wIns = $pdo->prepare("INSERT INTO project_workers (project_id, name, role, daily_rate) VALUES (?, 'Gaji dari Kas', 'Kas Besar', 0)");
                                        $wIns->execute([$investorProjectId]);
                                        $workerId = (int)$pdo->lastInsertId();
                                    }

                                    $salStmt = $pdo->prepare("INSERT INTO project_salaries (project_id, worker_id, period_type, period_label, daily_rate, total_days, total_salary, status, notes, created_by) VALUES (?, ?, 'monthly', ?, ?, 1, ?, 'submitted', ?, ?)");
                                    $periodLabel = date('F Y', strtotime($transactionDate));
                                    $salStmt->execute([$investorProjectId, $workerId, $periodLabel, $amount, $amount, ($description ?: $categoryName), $_SESSION['user_id'] ?? null]);
                                    error_log("GAJI SYNC: Cashbook #{$transactionId} -> project_salaries for project #{$investorProjectId}, Amount: {$amount}");
                                } catch (Exception $gajiErr) {
                                    error_log("GAJI SYNC ERROR: " . $gajiErr->getMessage());
                                }
                            }

                            // Success - redirect directly (transaction already committed)
                            setFlash('success', 'Transaksi berhasil ditambahkan! 🏗️ Tersinkron ke Proyek.');
                            redirect(BASE_URL . '/modules/cashbook/index.php');
                            exit;
                        } catch (Exception $e) {
                            error_log('Investor project expense sync error: ' . $e->getMessage());
                            // Cash book already committed, just log sync failure
                            setFlash('success', 'Transaksi berhasil, tapi sync ke proyek gagal: ' . $e->getMessage());
                            redirect(BASE_URL . '/modules/cashbook/index.php');
                            exit;
                        }
                    }

                    // CQC: Also save to cqc_project_expenses if project selected (expense only)
                    // Skip if "operational" - that's for office expenses, not project expenses
                    $cqcProjectVal = getPost('cqc_project_id');
                    if ($isCQC && !empty($cqcProjectVal) && $cqcProjectVal !== 'operational') {
                        try {
                            require_once __DIR__ . '/../cqc-projects/db-helper.php';
                            $cqcPdo = getCQCDatabaseConnection();
                            $cqcProjectId = intval($cqcProjectVal);
                            $cqcCategoryId = !empty(getPost('cqc_category_id')) ? intval(getPost('cqc_category_id')) : null;

                            if ($transactionType === 'expense') {
                                // Add source marker to notes to track it came from cashbook
                                $syncNotes = '[SYNCED_FROM_CASHBOOK:' . $transactionId . '] ' . ($description ?: $categoryName);
                                $stmtExp = $cqcPdo->prepare("INSERT INTO cqc_project_expenses (project_id, category_id, description, amount, expense_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $stmtExp->execute([$cqcProjectId, $cqcCategoryId, $categoryName, $amount, $transactionDate, $syncNotes, $_SESSION['user_id']]);
                                error_log("CQC SYNC: Cashbook -> Project expense. Project ID: {$cqcProjectId}, Amount: {$amount}");

                                // Update spent_idr on the project
                                $stmtUpd = $cqcPdo->prepare("UPDATE cqc_projects SET spent_idr = (SELECT COALESCE(SUM(amount),0) FROM cqc_project_expenses WHERE project_id = ?) WHERE id = ?");
                                $stmtUpd->execute([$cqcProjectId, $cqcProjectId]);
                            }
                        } catch (Exception $e) {
                            error_log('CQC expense save error: ' . $e->getMessage());
                        }
                    }

                    $db->commit();

                    // Success message with auto-switch notification
                    if ($autoSwitched) {
                        setFlash('success', 'Transaksi berhasil! ⚡ Petty Cash tidak cukup, otomatis dipotong dari Modal Owner.');
                    } else {
                        setFlash('success', 'Transaksi berhasil ditambahkan!');
                    }

                    redirect(BASE_URL . '/modules/cashbook/index.php');
                } else {
                    $db->rollBack();
                    setFlash('error', 'Gagal menambahkan transaksi!');
                }
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('error', 'Error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    }
}

include '../../includes/header.php';
?>

<?php if ($isCQC): ?>
    <style>
        :root,
        body,
        body[data-theme="light"],
        body[data-theme="dark"] {
            --primary-color: #f0b429 !important;
            --primary-dark: #d4960d !important;
            --secondary-color: #0d1f3c !important;
        }

        .transaction-type-card:has(input[value="income"]:checked),
        .transaction-type-card:has(input[value="expense"]:checked),
        .payment-method-card:has(input[type="radio"]:checked) {
            border-color: #0d1f3c !important;
            box-shadow: 0 0 0 4px rgba(13, 31, 60, 0.15), 0 8px 20px rgba(13, 31, 60, 0.1) !important;
        }

        .transaction-type-card:has(input[value="income"]:checked) {
            border-color: #10b981 !important;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15) !important;
        }

        .transaction-type-card:has(input[value="expense"]:checked) {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15) !important;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0d1f3c, #1a3a5c) !important;
            color: #f0b429 !important;
            border: none !important;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #122a4e, #1f4570) !important;
        }

        .cqc-project-option {
            display: flex;
            justify-content: space-between;
        }

        .cqc-form-header {
            background: #ffffff !important;
            border-bottom: none !important;
            border-left: 4px solid #f0b429 !important;
        }

        .cqc-form-header h3 {
            color: #0d1f3c !important;
        }

        .cqc-form-header i {
            color: #f0b429 !important;
        }

        .cqc-select-project {
            border: 2px solid rgba(13, 31, 60, 0.15) !important;
            font-weight: 600 !important;
        }

        .cqc-select-project:focus {
            border-color: #f0b429 !important;
            box-shadow: 0 0 0 3px rgba(240, 180, 41, 0.2) !important;
        }
    </style>
<?php endif; ?>

<style>
    .payment-method-card {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 0.75rem;
        background: var(--bg-tertiary);
        border: 2px solid transparent;
        border-radius: 0.75rem;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .payment-method-card:hover {
        background: var(--bg-secondary);
        transform: translateY(-2px);
    }

    .payment-method-card input[type="radio"] {
        position: absolute;
        opacity: 0;
    }

    .payment-method-card input[type="radio"]:checked+.payment-content {
        border-color: var(--primary-color);
    }

    .payment-method-card input[type="radio"]:checked~* {
        color: var(--primary-color);
    }

    /* Enhanced checked state for payment method */
    .payment-method-card input[type="radio"]:checked~.payment-content {
        position: relative;
    }

    .payment-method-card input[type="radio"]:checked~.payment-content::after {
        content: '✓';
        position: absolute;
        top: -8px;
        right: -8px;
        width: 20px;
        height: 20px;
        background: var(--success);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .payment-method-card input[type="radio"]:checked {
        &+.payment-content {
            background: rgba(99, 102, 241, 0.1);
            border-radius: 0.5rem;
            padding: 0.25rem;
        }
    }

    .payment-method-card:has(input[type="radio"]:checked) {
        background: rgba(99, 102, 241, 0.05);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .compact-form-group {
        margin-bottom: 0.5rem;
    }

    .compact-form-group:last-child {
        margin-bottom: 0;
    }

    /* Animation for owner fund notification */
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .transaction-type-card {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-tertiary);
        border: 2px solid transparent;
        border-radius: 0.5rem;
        cursor: pointer;
        transition: all 0.3s ease;
        min-height: 85px;
    }

    .transaction-type-card:hover {
        background: var(--bg-secondary);
        transform: translateY(-3px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }

    .transaction-type-card input[type="radio"] {
        position: absolute;
        opacity: 0;
    }

    .transaction-type-card input[type="radio"]:checked~div i {
        transform: scale(1.1);
    }

    .transaction-type-card:has(input[type="radio"]:checked) {
        background: rgba(99, 102, 241, 0.08);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15), 0 8px 20px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }

    /* Green highlight for income */
    .transaction-type-card:has(input[value="income"]:checked) {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 8px 20px rgba(16, 185, 129, 0.2);
    }

    /* Red highlight for expense */
    .transaction-type-card:has(input[value="expense"]:checked) {
        border-color: #ef4444;
        box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15), 0 8px 20px rgba(239, 68, 68, 0.2);
    }
</style>

<form method="POST" id="transactionForm" onsubmit="return handleFormSubmit(event)">
    <!-- Main Form Container -->
    <div class="card" style="max-width: 920px; margin: 0 auto 0.75rem;">
        <div class="<?php echo $isCQC ? 'cqc-form-header' : ''; ?>" style="padding: 0.875rem 1rem; border-bottom: 1px solid var(--bg-tertiary); <?php echo !$isCQC ? 'background: linear-gradient(135deg, var(--primary-color)15, var(--bg-secondary));' : 'border-radius: 12px 12px 0 0;'; ?>">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; margin: 0;">
                    <?php if ($isCQC): ?>
                        ☀️ Input Transaksi Proyek CQC
                    <?php else: ?>
                        <i data-feather="plus-circle" style="width: 16px; height: 16px;"></i> Tambah Transaksi Baru
                    <?php endif; ?>
                </h3>
            </div>
        </div>

        <div style="padding: 0.875rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem 0.875rem;">
            <!-- Transaction Type - FIRST (Full Width) -->
            <div style="grid-column: span 2; margin-bottom: 0.5rem;">
                <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.5rem; display: block;">Tipe Transaksi <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; align-items: stretch; gap: 0.75rem; flex-wrap: wrap;">
                    <label class="transaction-type-card" style="padding: 0.75rem; flex: 1; min-width: 140px; max-width: 180px;">
                        <input type="radio" name="transaction_type" value="income" required>
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.3rem; text-align: center;">
                            <i data-feather="trending-up" style="width: 22px; height: 22px; color: var(--success); stroke-width: 2.5;"></i>
                            <div>
                                <div style="font-weight: 700; font-size: 0.875rem; color: var(--text-primary);">UANG MASUK</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pemasukan</div>
                            </div>
                        </div>
                    </label>

                    <label class="transaction-type-card" style="padding: 0.75rem; flex: 1; min-width: 140px; max-width: 180px;">
                        <input type="radio" name="transaction_type" value="expense" required checked>
                        <div style="display: flex; flex-direction: column; align-items: center; gap: 0.3rem; text-align: center;">
                            <i data-feather="trending-down" style="width: 22px; height: 22px; color: var(--danger); stroke-width: 2.5;"></i>
                            <div>
                                <div style="font-weight: 700; font-size: 0.875rem; color: var(--text-primary);">UANG KELUAR</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pengeluaran</div>
                            </div>
                        </div>
                    </label>

                    <?php if (!$isCQC): ?>
                        <!-- Special Button: Input dari Bu Sita (Owner Fund) -->
                        <button type="button" id="btnOwnerFund" onclick="fillOwnerFund()" style="padding: 0.75rem; flex: 1; min-width: 140px; max-width: 180px; background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; border: 2px solid #f59e0b; border-radius: 12px; font-size: 0.875rem; font-weight: 700; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.3rem; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2); transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(245, 158, 11, 0.35)'; this.style.borderColor='#d97706'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(245, 158, 11, 0.2)'; this.style.borderColor='#f59e0b'">
                            <span style="font-size: 1.25rem;">💰</span>
                            <div style="font-weight: 700; font-size: 0.813rem;">INPUT DARI BU SITA</div>
                            <div style="font-size: 0.7rem; color: #b45309;">Modal Pemilik</div>
                        </button>

                        <!-- Special Button: Setor Tunai ke Rekening Operasional -->
                        <button type="button" id="btnSetorTunai" onclick="showSetorTunaiModal()" style="padding: 0.75rem; flex: 1; min-width: 140px; max-width: 180px; background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #0c4a6e; border: 2px solid #0284c7; border-radius: 12px; font-size: 0.875rem; font-weight: 700; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.3rem; box-shadow: 0 2px 8px rgba(2, 132, 199, 0.2); transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(2, 132, 199, 0.35)'; this.style.borderColor='#0369a1'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(2, 132, 199, 0.2)'; this.style.borderColor='#0284c7'">
                            <span style="font-size: 1.25rem;">🏦</span>
                            <div style="font-weight: 700; font-size: 0.813rem;">SETOR TUNAI</div>
                            <div style="font-size: 0.7rem; color: #0c4a6e;">Ke Rekening Bank</div>
                        </button>
                    <?php else: ?>
                        <!-- CQC: Transfer to Petty Cash Button -->
                        <label class="transaction-type-card" id="btnTransferPettyCash" onclick="fillTransferPettyCash()" style="padding: 0.75rem; flex: 1; min-width: 140px; max-width: 180px; cursor: pointer;">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 0.3rem; text-align: center;">
                                <span style="font-size: 1.25rem;">💸</span>
                                <div>
                                    <div style="font-weight: 700; font-size: 0.813rem; color: var(--text-primary);">TRANSFER PETTY CASH</div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);">Office & Proyek</div>
                                </div>
                            </div>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Column 1 -->
            <div>
                <!-- Date & Time -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Tanggal <span style="color: var(--danger);">*</span></label>
                    <input type="date" name="transaction_date" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Waktu</label>
                    <input type="time" name="transaction_time" class="form-control" style="height: 34px; font-size: 0.813rem;" value="<?php echo date('H:i'); ?>">
                </div>

                <?php if ($isCQC): ?>
                    <!-- CQC: Project Selection -->
                    <div class="compact-form-group">
                        <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">☀️ Proyek <span style="color: var(--danger);">*</span></label>
                        <select name="cqc_project_id" id="cqc_project_id" class="form-control cqc-select-project" style="height: 38px; font-size: 0.813rem;" required onchange="updateCQCProjectInfo(this)">
                            <option value="">-- Pilih Proyek --</option>
                            <option value="operational" data-budget="0" data-spent="0" data-remaining="0" data-client="Office" style="background: #f0f9ff; font-weight: 600;">💼 Operasional Office & Proyek</option>
                            <?php foreach ($cqcProjects as $proj):
                                $statusLabels = ['planning' => '📋 Planning', 'procurement' => '🛒 Procurement', 'installation' => '⚡ Instalasi', 'testing' => '🔧 Testing', 'completed' => '✅ Selesai', 'on_hold' => '⏸️ Ditunda'];
                                $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                                $remaining = floatval($proj['budget_idr']) - floatval($proj['spent_idr'] ?? 0);
                            ?>
                                <option value="<?php echo $proj['id']; ?>"
                                    data-budget="<?php echo $proj['budget_idr']; ?>"
                                    data-spent="<?php echo $proj['spent_idr'] ?? 0; ?>"
                                    data-remaining="<?php echo $remaining; ?>"
                                    data-client="<?php echo htmlspecialchars($proj['client_name'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($proj['project_code'] . ' - ' . $proj['project_name']); ?> [<?php echo $statusLabel; ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="cqcProjectInfo" style="display: none; margin-top: 0.4rem; padding: 0.5rem 0.75rem; background: linear-gradient(135deg, rgba(13,31,60,0.05), rgba(240,180,41,0.08)); border-radius: 8px; border-left: 3px solid #f0b429;">
                            <div style="display: flex; gap: 1rem; font-size: 0.7rem;">
                                <span>💰 Budget: <strong id="cqcBudgetDisplay">-</strong></span>
                                <span>📤 Terpakai: <strong id="cqcSpentDisplay" style="color: #ef4444;">-</strong></span>
                                <span>💵 Sisa: <strong id="cqcRemainingDisplay" style="color: #10b981;">-</strong></span>
                            </div>
                        </div>
                        <!-- Hidden division_id for cashbook compatibility -->
                        <input type="hidden" name="division_id" value="<?php echo !empty($divisions) ? $divisions[0]['id'] : 1; ?>">
                    </div>

                    <!-- CQC: Expense Category -->
                    <div class="compact-form-group" id="cqcExpenseSection">
                        <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">📦 Kategori Biaya <span style="color: var(--danger);">*</span></label>
                        <select name="cqc_category_id" id="cqc_category_id" class="form-control" style="height: 34px; font-size: 0.813rem;" onchange="document.querySelector('[name=category_name]').value = this.options[this.selectedIndex].text">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($cqcCategories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>">
                                    <?php echo $cat['category_icon'] . ' ' . htmlspecialchars($cat['category_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom">✏️ Tulis Manual</option>
                        </select>
                        <input type="text" name="category_name" class="form-control" style="height: 34px; font-size: 0.813rem; margin-top: 0.3rem;" placeholder="Nama item / deskripsi biaya" required>
                    </div>
                    <!-- CQC: Income Type -->
                    <div class="compact-form-group" id="cqcIncomeSection" style="display: none;">
                        <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; color: #0d1f3c;">💰 Jenis Uang Masuk <span style="color: var(--danger);">*</span></label>
                        <select name="cqc_income_type" id="cqc_income_type" class="form-control" style="height: 34px; font-size: 0.813rem;" onchange="updateCQCIncomeCategory(this)">
                            <option value="">-- Pilih Jenis --</option>
                            <option value="topup_owner" style="background: #fef3c7; font-weight: 600;">� Operasional Office & Proyek</option>
                            <option value="dp">💵 DP Masuk (Invoice)</option>
                            <option value="termin">📄 Pembayaran Termin (Invoice)</option>
                            <option value="pelunasan">✅ Pelunasan (Invoice)</option>
                            <option value="retensi">🔒 Retensi / Garansi</option>
                            <option value="manual">✏️ Tulis Manual</option>
                        </select>
                        <input type="text" name="cqc_income_desc" id="cqc_income_desc" class="form-control" style="height: 34px; font-size: 0.813rem; margin-top: 0.3rem;" placeholder="Keterangan" readonly>
                        <div id="cqcIncomeNote" style="display: none; margin-top: 0.3rem; padding: 6px 10px; border-radius: 6px; font-size: 0.7rem;"></div>
                    </div>
                <?php else: ?>
                    <!-- Division -->
                    <div class="compact-form-group">
                        <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Divisi <span style="color: var(--danger);">*</span></label>
                        <select name="division_id" id="division_id" class="form-control" style="height: 34px; font-size: 0.813rem;" required>
                            <option value="">-- Pilih Divisi --</option>
                            <?php foreach ($divisions as $div): ?>
                                <option value="<?php echo $div['id']; ?>"><?php echo $div['division_name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Category -->
                    <div class="compact-form-group">
                        <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Kategori/Nama <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="category_name" class="form-control" style="height: 34px; font-size: 0.813rem;" placeholder="Nama kategori atau nama item" required>
                    </div>

                    <?php if ($isHotel): ?>
                        <!-- Project Expense Toggle -->
                        <div class="compact-form-group" id="projectExpenseGroup">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; padding: 0.5rem 0.75rem; background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 8px; transition: all 0.2s;" id="projectToggleLabel">
                                <input type="checkbox" id="isProjectExpense" name="is_project_expense" value="1" onchange="toggleProjectExpense()" style="width: 16px; height: 16px; accent-color: #f59e0b;">
                                <span style="font-size: 0.813rem; font-weight: 600; color: #92400e;">🏗️ Pengeluaran Proyek (bukan beban hotel)</span>
                            </label>
                            <div id="projectSelectWrapper" style="display: none; margin-top: 0.5rem;">
                                <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; color: #92400e;">Pilih Proyek <span style="color: var(--danger);">*</span></label>
                                <select name="project_id" id="projectSelect" class="form-control" style="height: 36px; font-size: 0.813rem; border-color: #f59e0b;">
                                    <option value="">-- Pilih Proyek --</option>
                                    <?php foreach ($investorProjects as $proj): ?>
                                        <option value="<?php echo $proj['id']; ?>">
                                            <?php echo htmlspecialchars(($proj['project_code'] ? $proj['project_code'] . ' - ' : '') . $proj['project_name']); ?>
                                            <?php echo ' [' . ucfirst($proj['status']) . ']'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($investorProjects)): ?>
                                    <div style="font-size: 0.72rem; color: #dc2626; margin-top: 0.25rem;">⚠️ Belum ada proyek. Buat dulu di menu <a href="<?php echo BASE_URL; ?>/modules/investor/index.php" style="color: #4f46e5; font-weight: 600;">Investor & Proyek</a></div>
                                <?php endif; ?>
                            </div>
                            <div id="projectExpenseNote" style="display: none; font-size: 0.72rem; color: #f59e0b; margin-top: 0.25rem;">⚠️ Transaksi ini tidak masuk laporan P&L hotel, tapi tercatat di menu Investor & Proyek</div>
                            <input type="hidden" name="source_type" id="sourceTypeHidden" value="">
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Amount -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Jumlah <span style="color: var(--danger);">*</span></label>
                    <input type="text" name="amount" class="form-control amount-input" style="height: 36px; font-size: 0.938rem; font-weight: 600;" placeholder="0" required>
                </div>
            </div>

            <!-- Column 2 -->
            <div>
                <!-- Cash Account Selection -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem; display: flex; align-items: center; justify-content: space-between;">
                        <span>Pilih Akun <span style="color: var(--danger);">*</span></span>
                        <a href="accounts.php" style="font-size: 0.7rem; font-weight: 600; color: <?php echo $isCQC ? '#0d1f3c' : 'var(--primary-color)'; ?>; text-decoration: none; display: flex; align-items: center; gap: 0.2rem;">
                            <i data-feather="settings" style="width: 12px; height: 12px;"></i> Setup Rekening
                        </a>
                    </label>
                    <select name="cash_account_id" class="form-control" style="height: 34px; font-size: 0.813rem; font-weight: 600;" required>
                        <option value="">-- Pilih Akun --</option>
                        <?php if (empty($cashAccounts)): ?>
                            <option value="" disabled style="color: #dc2626;">⚠️ Tidak ada akun kas tersedia. Hubungi admin!</option>
                        <?php else: ?>
                            <?php
                            $accTypeIcons = ['cash' => '💵', 'bank' => '🏦', 'e-wallet' => '📱', 'credit_card' => '💳'];
                            foreach ($cashAccounts as $acc):
                                $icon = $accTypeIcons[$acc['account_type']] ?? '💰';
                            ?>
                                <option value="<?php echo htmlspecialchars($acc['id']); ?>">
                                    <?php echo $icon . ' ' . htmlspecialchars($acc['account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($cashAccounts)): ?>
                        <div style="font-size: 0.75rem; color: #dc2626; margin-top: 0.3rem; padding: 0.5rem; background: #fee2e2; border-radius: 4px; border-left: 3px solid #dc2626;">
                            <strong>⚠️ Error:</strong> Akun kas tidak ditemukan di database master. Pastikan cash_accounts sudah di-setup untuk bisnis ini.
                        </div>
                    <?php else: ?>
                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.3rem; line-height: 1.4;">
                            💡 Pilih rekening tujuan. <a href="accounts.php" style="color: <?php echo $isCQC ? '#0d1f3c' : 'var(--primary-color)'; ?>; font-weight: 600; text-decoration: none;">Tambah/edit rekening →</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Method -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Metode Pembayaran <span style="color: var(--danger);">*</span></label>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.4rem;">
                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="cash" required checked>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="dollar-sign" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #10b981;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Cash</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="debit" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="credit-card" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #3b82f6;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Debit</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="transfer" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="send" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #8b5cf6;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Transfer</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="qr" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="smartphone" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #f59e0b;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">QR Code</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="edc" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="cpu" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #ec4899;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">EDC</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="ota" required id="payment_ota">
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="globe" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #06b6d4;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">OTA</div>
                            </div>
                        </label>

                        <label class="payment-method-card" style="padding: 0.5rem;">
                            <input type="radio" name="payment_method" value="other" required>
                            <div class="payment-content" style="text-align: center; position: relative;">
                                <i data-feather="more-horizontal" style="width: 16px; height: 16px; margin-bottom: 0.15rem; color: #6b7280;"></i>
                                <div style="font-weight: 600; font-size: 0.75rem; color: var(--text-primary);">Lainnya</div>
                            </div>
                        </label>
                    </div>

                    <!-- OTA Source Selection -->
                    <div id="ota_source_section" style="display: none; margin-top: 0.5rem;">
                        <label class="form-label" style="font-size: 0.75rem; font-weight: 600; margin-bottom: 0.25rem; color: #06b6d4;">
                            <i data-feather="globe" style="width: 12px; height: 12px;"></i> Pilih Sumber OTA
                        </label>
                        <select name="ota_source" id="ota_source" class="form-control" style="font-size: 0.813rem; padding: 0.4rem 0.6rem;">
                            <option value="">-- Pilih OTA --</option>
                            <option value="OTA tiket.com">tiket.com</option>
                            <option value="OTA Agoda">Agoda</option>
                            <option value="OTA Booking.com">Booking.com</option>
                            <option value="OTA Traveloka">Traveloka</option>
                            <option value="OTA Airbnb">Airbnb</option>
                            <option value="OTA Expedia">Expedia</option>
                            <option value="OTA Pegipegi">Pegipegi</option>
                            <option value="OTA Lainnya">OTA Lainnya</option>
                        </select>
                    </div>
                </div>

                <!-- Description -->
                <div class="compact-form-group">
                    <label class="form-label" style="font-size: 0.813rem; font-weight: 600; margin-bottom: 0.3rem;">Keterangan</label>
                    <textarea name="description" class="form-control" rows="2" style="font-size: 0.813rem; resize: none; line-height: 1.5;" placeholder="Keterangan tambahan (opsional)"></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions Container -->
    <div class="card" style="max-width: 920px; margin: 0 auto; padding: 0.875rem 1.125rem; background: var(--bg-secondary); display: flex; justify-content: flex-end; gap: 0.75rem;">
        <a href="index.php" class="btn btn-secondary" style="padding: 0.625rem 1.125rem; font-size: 0.875rem;">
            <i data-feather="x" style="width: 15px; height: 15px;"></i> Batal
        </a>
        <button type="submit" class="btn btn-primary" style="padding: 0.625rem 1.25rem; font-size: 0.875rem;">
            <i data-feather="save" style="width: 15px; height: 15px;"></i> Simpan Transaksi
        </button>
    </div>

    <!-- Modal CSS (inline before modal to ensure styles load first) -->
    <style>
        #setorTunaiModal {
            display: none !important;
        }

        #setorTunaiModal.show {
            display: flex !important;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #setorTunaiModal.show>div {
            animation: slideUp 0.3s ease;
        }
    </style>

    <!-- Modal: Setor Tunai -->
    <div id="setorTunaiModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 2rem; max-width: 450px; width: 90%; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
            <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: #0c4a6e; display: flex; align-items: center; gap: 0.5rem;">
                <span>🏦</span> Setor Tunai ke Rekening Bank
            </h2>

            <div id="setorTunaiForm" style="display: flex; flex-direction: column; gap: 1.25rem;">
                <!-- Pilih Rekening Kas (Sumber) -->
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155; font-size: 0.875rem;">Kas / Rekening Sumber <span style="color: #dc2626;">*</span></label>
                    <select name="setor_cash_account" id="setorCashAccount" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem;">
                        <option value="">-- Pilih Rekening Kas --</option>
                        <?php
                        try {
                            $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            $bizId = getMasterBusinessId();

                            $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' AND is_active = 1 ORDER BY account_name");
                            $stmt->execute([$bizId]);
                            $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($cashAccounts as $acc) {
                                echo '<option value="' . htmlspecialchars($acc['id']) . '">💵 ' . htmlspecialchars($acc['account_name']) . '</option>';
                            }
                        } catch (Exception $e) {
                            error_log("Setor Tunai Modal: Error loading cash accounts: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>

                <!-- Pilih Rekening Bank (Tujuan) -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                        <label style="display: block; font-weight: 600; color: #334155; font-size: 0.875rem;">Rekening Bank Tujuan <span style="color: #dc2626;">*</span></label>
                        <a href="#" onclick="openAddBankAccountModal(event)" style="font-size: 0.75rem; color: #0284c7; font-weight: 600; text-decoration: none; border-bottom: 1px dashed #0284c7; cursor: pointer;">+ Tambah</a>
                    </div>
                    <select name="setor_bank_account" id="setorBankAccount" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem;">
                        <option value="">-- Pilih Rekening Bank --</option>
                        <?php
                        try {
                            $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                            $bizId = getMasterBusinessId();

                            $stmt = $masterDb->prepare("SELECT id, account_name FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' AND is_active = 1 ORDER BY account_name");
                            $stmt->execute([$bizId]);
                            $bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            foreach ($bankAccounts as $acc) {
                                echo '<option value="' . htmlspecialchars($acc['id']) . '">🏛️ ' . htmlspecialchars($acc['account_name']) . '</option>';
                            }
                        } catch (Exception $e) {
                            error_log("Setor Tunai Modal: Error loading bank accounts: " . $e->getMessage());
                        }
                        ?>
                    </select>
                </div>

                <!-- Quick-Add Bank Account Section (Hidden) -->
                <div id="quickAddAccountSection" style="display: none; border: 1px solid #dbeafe; border-radius: 8px; padding: 1rem; background: #f0f9ff;">
                    <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: #0c4a6e; font-size: 0.875rem;">➕ Tambah Rekening Bank Baru</label>
                    <input type="text" id="quickAccountName" placeholder="Nama rekening: e.g., BNI Operasional" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.875rem; margin-bottom: 0.5rem;">
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" onclick="saveQuickAccount()" class="btn btn-primary" style="flex: 1; padding: 0.5rem; border-radius: 6px; font-weight: 600; font-size: 0.875rem; background: linear-gradient(135deg, #10b981, #059669); border: none; color: white; cursor: pointer;">
                            💾 Simpan
                        </button>
                        <button type="button" onclick="cancelQuickAccount()" style="padding: 0.5rem 1rem; border-radius: 6px; font-weight: 600; font-size: 0.875rem; border: 1px solid #cbd5e1; background: white; color: #334155; cursor: pointer;">
                            Batal
                        </button>
                    </div>
                </div>

                <!-- Input Nominal -->
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155; font-size: 0.875rem;">Nominal Setor <span style="color: #dc2626;">*</span></label>
                    <input type="number" name="setor_amount" id="setorAmount" min="1000" placeholder="Contoh: 500000" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem; font-family: 'Courier New', monospace;">
                </div>

                <!-- Nama Penyetor -->
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155; font-size: 0.875rem;">Nama Penyetor <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="setor_penyetor" id="setorPenyetor" placeholder="Nama orang yang melakukan setor" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem;">
                </div>

                <!-- Keterangan (Optional) -->
                <div>
                    <label style="display: block; font-weight: 600; margin-bottom: 0.4rem; color: #334155; font-size: 0.875rem;">Keterangan <span style="color: #94a3b8;">(Opsional)</span></label>
                    <input type="text" name="setor_notes" id="setorNotes" placeholder="Contoh: Setor harian" style="width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem;">
                </div>

                <!-- Tombol Aksi -->
                <div style="display: flex; gap: 0.75rem; margin-top: 1rem;">
                    <button type="button" onclick="closeSetorTunaiModal()" class="btn btn-secondary" style="flex: 1; padding: 0.65rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
                        Batal
                    </button>
                    <button type="button" onclick="submitSetorTunai(event)" class="btn btn-primary" style="flex: 1; padding: 0.65rem; border-radius: 8px; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #0284c7, #0369a1);">
                        ✓ Setor Tunai
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- ====== SETOR TUNAI: Clean isolated script block ====== -->
<!-- This is separate from the main script to survive any syntax errors elsewhere -->
<script>
    (function() {
        'use strict';

        // Show modal
        window.showSetorTunaiModal = function() {
            var modal = document.getElementById('setorTunaiModal');
            if (modal) {
                modal.classList.add('show');
                setTimeout(function() {
                    var f = document.getElementById('setorAmount');
                    if (f) f.focus();
                }, 100);
            }
        };

        // Close modal
        window.closeSetorTunaiModal = function() {
            var modal = document.getElementById('setorTunaiModal');
            if (modal) {
                modal.classList.remove('show');
                // Reset modal fields manually (it's a div, not a form)
                var fields = ['setorCashAccount', 'setorBankAccount', 'setorAmount', 'setorPenyetor', 'setorNotes'];
                fields.forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) el.value = '';
                });
                var qa = document.getElementById('quickAddAccountSection');
                if (qa) qa.style.display = 'none';
            }
        };

        // Open quick-add account panel
        window.openAddBankAccountModal = function(e) {
            if (e) e.preventDefault();
            var section = document.getElementById('quickAddAccountSection');
            if (section) {
                section.style.display = (section.style.display === 'none' || !section.style.display) ? 'block' : 'none';
                if (section.style.display === 'block') {
                    var inp = document.getElementById('quickAccountName');
                    if (inp) inp.focus();
                }
            }
        };

        // Save quick-add account via AJAX
        window.saveQuickAccount = function() {
            var accountName = document.getElementById('quickAccountName') ? document.getElementById('quickAccountName').value : '';
            if (!accountName || accountName.trim().length < 3) {
                alert('❌ Nama rekening minimal 3 karakter');
                return;
            }
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=add_bank_account&account_name=' + encodeURIComponent(accountName)
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        alert('✅ Rekening berhasil ditambahkan!');
                        window.refreshBankAccountDropdown();
                        document.getElementById('quickAccountName').value = '';
                        var qa = document.getElementById('quickAddAccountSection');
                        if (qa) qa.style.display = 'none';
                    } else {
                        alert('❌ Error: ' + (data.message || 'Gagal menambahkan rekening'));
                    }
                })
                .catch(function(err) {
                    alert('❌ Error: ' + err.message);
                });
        };

        // Cancel quick-add
        window.cancelQuickAccount = function() {
            var qa = document.getElementById('quickAddAccountSection');
            if (qa) qa.style.display = 'none';
            var inp = document.getElementById('quickAccountName');
            if (inp) inp.value = '';
        };

        // Refresh bank account dropdown
        window.refreshBankAccountDropdown = function() {
            var bizId = '<?php echo getMasterBusinessId(); ?>';
            var select = document.getElementById('setorBankAccount');
            if (!select) return;
            fetch(window.location.pathname + '?action=get_bank_accounts&biz_id=' + bizId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    select.innerHTML = '<option value="">-- Pilih Rekening Bank --</option>';
                    if (data.accounts && data.accounts.length > 0) {
                        data.accounts.forEach(function(acc) {
                            var opt = document.createElement('option');
                            opt.value = acc.id;
                            opt.textContent = '🏛️ ' + acc.account_name;
                            select.appendChild(opt);
                        });
                    }
                })
                .catch(function(err) {
                    console.error('Refresh error:', err);
                });
        };

        // Submit Setor Tunai
        window.submitSetorTunai = function(event) {
            if (event) event.preventDefault();
            var container = document.getElementById('setorTunaiForm');
            if (!container) {
                alert('❌ Modal form tidak ditemukan!');
                return;
            }

            // Use getElementById for each field (more reliable than named form access)
            var elCash = document.getElementById('setorCashAccount');
            var elBank = document.getElementById('setorBankAccount');
            var elAmount = document.getElementById('setorAmount');
            var elPenyetor = document.getElementById('setorPenyetor');
            var elNotes = document.getElementById('setorNotes');

            var cashAccountId = elCash ? elCash.value.trim() : '';
            var bankAccountId = elBank ? elBank.value.trim() : '';
            var amount = elAmount ? elAmount.value.trim() : '';
            var penyetor = elPenyetor ? elPenyetor.value.trim() : '';
            var notes = elNotes ? elNotes.value.trim() : '';

            // Debug: show which fields are empty
            var emptyFields = [];
            if (!cashAccountId) emptyFields.push('Kas Sumber' + (elCash ? '' : ' [elemen tidak ada]'));
            if (!bankAccountId) emptyFields.push('Rekening Bank Tujuan' + (elBank ? '' : ' [elemen tidak ada]'));
            if (!amount) emptyFields.push('Nominal' + (elAmount ? '' : ' [elemen tidak ada]'));
            if (!penyetor) emptyFields.push('Nama Penyetor' + (elPenyetor ? '' : ' [elemen tidak ada]'));

            if (emptyFields.length > 0) {
                alert('❌ Harap isi: ' + emptyFields.join(', '));
                return;
            }
            if (parseFloat(amount) < 1000) {
                alert('❌ Nominal minimal Rp 1.000,-');
                return;
            }

            // Find main transaction form by its unique submit button text or by name of a unique field
            var mainForm = document.querySelector('input[name="transaction_date"]');
            mainForm = mainForm ? mainForm.closest('form') : null;
            if (!mainForm) {
                alert('❌ Form transaksi utama tidak ditemukan!');
                return;
            }

            function setHidden(name, value) {
                var f = mainForm.querySelector('input[name="' + name + '"]');
                if (!f) {
                    f = document.createElement('input');
                    f.type = 'hidden';
                    f.name = name;
                    mainForm.appendChild(f);
                }
                f.value = value;
            }

            setHidden('source_type', 'cash_transfer');
            setHidden('cash_account_id', cashAccountId);
            setHidden('bank_account_id', bankAccountId);
            setHidden('amount', amount);
            setHidden('transaction_type', 'income');
            setHidden('setor_penyetor', penyetor);

            var dateField = mainForm.querySelector('input[name="transaction_date"]');
            var timeField = mainForm.querySelector('input[name="transaction_time"]');
            var divField = mainForm.querySelector('select[name="division_id"]');
            var descField = mainForm.querySelector('textarea[name="description"], input[name="description"]');

            if (dateField) dateField.value = '<?php echo date("Y-m-d"); ?>';
            if (timeField) timeField.value = '<?php echo date("H:i"); ?>';
            if (divField) divField.removeAttribute('required');
            if (descField) descField.value = '[' + penyetor + '] ' + (notes || 'Setor tunai dari kas ke rekening bank');

            // Setor Tunai only needs date/amount/cash_account_id/bank_account_id (already
            // validated above with the "Harap isi: ..." alert). The rest of the main
            // transaction form (Kategori/Nama, dropdown "Pilih Akun", etc.) is intentionally
            // left empty since it doesn't apply to a cash transfer, but those fields still
            // carry `required` - the browser's native validation was silently blocking
            // submission and re-highlighting those unrelated empty fields. Skip native
            // validation here; server-side (add.php cash_transfer branch) re-validates anyway.
            mainForm.noValidate = true;

            window.closeSetorTunaiModal();
            mainForm.submit();
        };

        // Close on click outside
        document.addEventListener('click', function(e) {
            var modal = document.getElementById('setorTunaiModal');
            if (modal && e.target === modal) {
                window.closeSetorTunaiModal();
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var qa = document.getElementById('quickAddAccountSection');
                if (qa && qa.style.display === 'block') {
                    window.cancelQuickAccount();
                } else {
                    window.closeSetorTunaiModal();
                }
            }
        });

        // Enter in quick-add name field saves
        document.addEventListener('keypress', function(e) {
            if (e.target && e.target.id === 'quickAccountName' && e.key === 'Enter') {
                e.preventDefault();
                window.saveQuickAccount();
            }
        });

    })();
</script>

<script>
    feather.replace();

    // OTA Payment Method Handler
    (function() {
        const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
        const otaSection = document.getElementById('ota_source_section');
        const otaSelect = document.getElementById('ota_source');
        const paymentOta = document.getElementById('payment_ota');

        // Show/hide OTA dropdown based on selection
        paymentRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'ota') {
                    otaSection.style.display = 'block';
                    otaSelect.required = true;
                    setTimeout(() => feather.replace(), 10);
                } else {
                    otaSection.style.display = 'none';
                    otaSelect.required = false;
                    otaSelect.value = '';
                }
            });
        });

        // Update payment_method value when OTA source changes
        if (otaSelect) {
            otaSelect.addEventListener('change', function() {
                if (this.value && paymentOta) {
                    paymentOta.value = this.value;
                }
            });
        }
    })();

    <?php // Base transaction + Setor Tunai handlers must load for all business modes 
    ?>
    // Show/hide project expense toggle based on transaction type (only visible for expense)
    document.querySelectorAll('input[name="transaction_type"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const projectGroup = document.getElementById('projectExpenseGroup');
            const checkbox = document.getElementById('isProjectExpense');
            if (projectGroup) {
                if (this.value === 'expense') {
                    projectGroup.style.display = 'block';
                } else {
                    projectGroup.style.display = 'none';
                    // Uncheck and reset when switching to income
                    if (checkbox && checkbox.checked) {
                        checkbox.checked = false;
                        toggleProjectExpense();
                    }
                }
            }
        });
    });

    // ========================================
    // SETOR TUNAI MODAL FUNCTIONS
    // ========================================
    window.showSetorTunaiModal = function() {
        console.log('showSetorTunaiModal called');
        const modal = document.getElementById('setorTunaiModal');
        console.log('Modal element:', modal);
        if (modal) {
            modal.classList.add('show');
            // Focus on nominal input
            setTimeout(() => {
                const amountField = document.getElementById('setorAmount');
                if (amountField) amountField.focus();
            }, 100);
        } else {
            console.error('setorTunaiModal element not found!');
        }
    };

    window.closeSetorTunaiModal = function() {
        console.log('closeSetorTunaiModal called');
        const modal = document.getElementById('setorTunaiModal');
        if (modal) {
            modal.classList.remove('show');
            // #setorTunaiForm is a <div>, not a <form>, so it has no .reset() method.
            // Manually clear each input field instead.
            ['setorCashAccount', 'setorBankAccount', 'setorAmount', 'setorPenyetor', 'setorNotes'].forEach(function(id) {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            const qa = document.getElementById('quickAddAccountSection');
            if (qa) qa.style.display = 'none';
        }
    };

    window.openAddBankAccountModal = function(e) {
        e.preventDefault();
        const section = document.getElementById('quickAddAccountSection');
        if (section.style.display === 'none') {
            section.style.display = 'block';
            document.getElementById('quickAccountName').focus();
            console.log('Quick add account form shown');
        }
    };

    window.saveQuickAccount = function() {
        const accountName = document.getElementById('quickAccountName')?.value;
        if (!accountName || accountName.trim().length < 3) {
            alert('❌ Nama rekening minimal 3 karakter');
            return;
        }

        console.log('Saving account:', accountName);

        // AJAX call to save account
        fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=add_bank_account&account_name=' + encodeURIComponent(accountName)
            })
            .then(response => response.json())
            .then(data => {
                console.log('Save response:', data);
                if (data.success) {
                    alert('✅ Rekening berhasil ditambahkan!');
                    // Refresh dropdown
                    window.refreshBankAccountDropdown();
                    // Clear and hide form
                    document.getElementById('quickAccountName').value = '';
                    document.getElementById('quickAddAccountSection').style.display = 'none';
                } else {
                    alert('❌ Error: ' + (data.message || 'Gagal menambahkan rekening'));
                }
            })
            .catch(error => {
                console.error('Save error:', error);
                alert('❌ Error: ' + error.message);
            });
    };

    window.cancelQuickAccount = function() {
        document.getElementById('quickAddAccountSection').style.display = 'none';
        document.getElementById('quickAccountName').value = '';
    };

    window.refreshBankAccountDropdown = function() {
        const bizId = '<?php echo getMasterBusinessId(); ?>';
        const select = document.getElementById('setorBankAccount');

        fetch(window.location.pathname + '?action=get_bank_accounts&biz_id=' + bizId)
            .then(response => response.json())
            .then(data => {
                console.log('Bank accounts refreshed:', data);
                // Keep the placeholder option
                select.innerHTML = '<option value="">-- Pilih Rekening Bank --</option>';
                // Add new options
                if (data.accounts && data.accounts.length > 0) {
                    data.accounts.forEach(acc => {
                        const option = document.createElement('option');
                        option.value = acc.id;
                        option.textContent = '🏛️ ' + acc.account_name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Refresh error:', error));
    };

    // Close modal when clicking outside - ensure DOM is ready
    function initSetorTunaiModal() {
        const modal = document.getElementById('setorTunaiModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    window.closeSetorTunaiModal();
                }
            });
            console.log('Setor Tunai modal initialized successfully');
        } else {
            console.warn('setorTunaiModal element not found during init');
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSetorTunaiModal);
    } else {
        initSetorTunaiModal();
    }

    window.submitSetorTunai = function(event) {
        event.preventDefault();
        console.log('submitSetorTunai called');

        const form = document.getElementById('setorTunaiForm');
        if (!form) {
            console.error('setorTunaiForm not found');
            alert('❌ Form tidak ditemukan!');
            return;
        }

        // NOTE: #setorTunaiForm is a <div>, not a <form>, so named-property
        // access (form.setor_cash_account) never works - HTMLFormElement is
        // the only element type that supports that. Must use getElementById
        // (or querySelector) on the actual input/select IDs instead.
        const cashAccountId = document.getElementById('setorCashAccount')?.value;
        const bankAccountId = document.getElementById('setorBankAccount')?.value;
        const amount = document.getElementById('setorAmount')?.value;
        const penyetor = document.getElementById('setorPenyetor')?.value;
        const notes = document.getElementById('setorNotes')?.value || '';

        console.log('Form values:', {
            cashAccountId,
            bankAccountId,
            amount,
            penyetor
        });

        if (!cashAccountId || !bankAccountId || !amount || !penyetor) {
            alert('❌ Silakan isi semua field yang wajib diisi!');
            return;
        }

        if (parseFloat(amount) < 1000) {
            alert('❌ Nominal minimal Rp 1.000,-');
            return;
        }

        // Get the main form
        const mainForm = document.querySelector('form');
        if (!mainForm) {
            console.error('Main form not found');
            alert('❌ Form utama tidak ditemukan!');
            return;
        }

        // Create/update hidden fields for source_type and accounts
        const ensureHiddenField = (name, value) => {
            let field = mainForm.querySelector(`input[name="${name}"]`);
            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                mainForm.appendChild(field);
            }
            field.value = value;
        };

        ensureHiddenField('source_type', 'cash_transfer');
        ensureHiddenField('cash_account_id', cashAccountId);
        ensureHiddenField('bank_account_id', bankAccountId);
        ensureHiddenField('amount', amount);
        ensureHiddenField('transaction_type', 'income');
        ensureHiddenField('setor_penyetor', penyetor);

        // Set date/time
        mainForm.transaction_date.value = '<?php echo date("Y-m-d"); ?>';
        mainForm.transaction_time.value = '<?php echo date("H:i"); ?>';

        // Clear division - don't set it for cash_transfer
        if (mainForm.division_id) {
            mainForm.division_id.value = '';
        }

        // Set description with penyetor name
        mainForm.description.value = `[${penyetor}] ${notes || 'Setor tunai dari kas cabang ke rekening operasional'}`;

        // Skip native HTML5 validation - other required fields on the main form
        // (Kategori/Nama, dropdown "Pilih Akun", etc.) are intentionally left
        // empty for a cash transfer and would otherwise silently block submit /
        // re-highlight as "still empty" even though the Setor Tunai popup was
        // fully filled. Already validated the popup's own fields above; server
        // (add.php cash_transfer branch) re-validates too.
        mainForm.noValidate = true;

        // Close modal
        window.closeSetorTunaiModal();

        // Submit the main form
        console.log('Submitting main form with hidden fields');
        mainForm.submit();
    };

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Close quick-add first if open
            const quickAdd = document.getElementById('quickAddAccountSection');
            if (quickAdd && quickAdd.style.display !== 'none') {
                window.cancelQuickAccount();
            } else {
                window.closeSetorTunaiModal();
            }
        }
    });

    // Allow Enter key in quick-add name field to save
    document.addEventListener('keypress', function(e) {
        if (e.target.id === 'quickAccountName' && e.key === 'Enter') {
            e.preventDefault();
            window.saveQuickAccount();
        }
    });

    // Owner Fund - Input dari Bu Sita
    function fillOwnerFund() {
        // Set transaction type to income
        const incomeRadio = document.querySelector('input[name="transaction_type"][value="income"]');
        if (incomeRadio) {
            incomeRadio.checked = true;
            incomeRadio.dispatchEvent(new Event('change', {
                bubbles: true
            }));
        }

        // Set date to today
        document.querySelector('input[name="transaction_date"]').value = '<?php echo date("Y-m-d"); ?>';
        document.querySelector('input[name="transaction_time"]').value = '<?php echo date("H:i"); ?>';

        // Set division - try to find "Kas", "Modal", "Owner", "Finance" division
        // If not found, use Hotel or first available (will be excluded from pie chart anyway)
        const divisionSelect = document.querySelector('select[name="division_id"]');
        if (divisionSelect) {
            let foundKasDiv = false;
            const keywords = ['kas', 'modal', 'owner', 'finance', 'keuangan', 'petty'];
            for (let opt of divisionSelect.options) {
                const text = opt.text.toLowerCase();
                if (keywords.some(kw => text.includes(kw))) {
                    divisionSelect.value = opt.value;
                    foundKasDiv = true;
                    break;
                }
            }
            // If no special division found, look for Hotel
            if (!foundKasDiv) {
                for (let opt of divisionSelect.options) {
                    if (opt.text.toLowerCase().includes('hotel')) {
                        divisionSelect.value = opt.value;
                        foundKasDiv = true;
                        break;
                    }
                }
            }
            // Still not found, use first non-placeholder
            if (!foundKasDiv && divisionSelect.options.length > 1) {
                divisionSelect.selectedIndex = 1;
            }
        }

        // Set category to "Modal Operasional"
        document.querySelector('input[name="category_name"]').value = 'Modal Operasional dari Bu Sita';

        // Set cash account to Petty Cash (Kas Operasional)
        const cashAccountSelect = document.querySelector('select[name="cash_account_id"]');
        if (cashAccountSelect) {
            // Find Kas Operasional option
            for (let opt of cashAccountSelect.options) {
                if (opt.text.toLowerCase().includes('kas operasional') || opt.text.toLowerCase().includes('petty cash')) {
                    cashAccountSelect.value = opt.value;
                    break;
                }
            }
        }

        // Set payment method to cash (default)
        const cashPayment = document.querySelector('input[name="payment_method"][value="cash"]');
        if (cashPayment) cashPayment.checked = true;

        // Set source_type hidden field to owner_fund
        let sourceTypeField = document.querySelector('input[name="source_type"]');
        if (!sourceTypeField) {
            sourceTypeField = document.createElement('input');
            sourceTypeField.type = 'hidden';
            sourceTypeField.name = 'source_type';
            document.querySelector('form').appendChild(sourceTypeField);
        }
        sourceTypeField.value = 'owner_fund';

        // Set description
        document.querySelector('textarea[name="description"]').value = 'Transfer dana operasional dari Bu Sita';

        // Focus on amount field
        const amountField = document.querySelector('input[name="amount"]');
        if (amountField) {
            amountField.value = '';
            amountField.focus();
        }

        // Show notification
        showOwnerFundNotice();
    }

    function showOwnerFundNotice() {
        // Remove existing notice
        const existing = document.getElementById('ownerFundNotice');
        if (existing) existing.remove();

        // Create notice
        const notice = document.createElement('div');
        notice.id = 'ownerFundNotice';
        notice.innerHTML = `
        <div style="position: fixed; top: 80px; right: 20px; padding: 1rem 1.25rem; background: linear-gradient(135deg, #fef3c7, #fde68a); border: 1px solid #f59e0b; border-radius: 12px; box-shadow: 0 4px 20px rgba(245, 158, 11, 0.3); z-index: 9999; max-width: 320px; animation: slideIn 0.3s ease;">
            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                <span style="font-size: 1.5rem;">💰</span>
                <div>
                    <div style="font-weight: 700; color: #92400e; font-size: 0.875rem; margin-bottom: 0.25rem;">Input dari Bu Sita</div>
                    <div style="font-size: 0.75rem; color: #b45309; line-height: 1.4;">Form sudah diisi otomatis. Tinggal masukkan jumlah uang yang dikirim Bu Sita untuk operasional harian.</div>
                </div>
                <button onclick="this.parentElement.parentElement.parentElement.remove()" style="background: none; border: none; color: #92400e; cursor: pointer; font-size: 1.25rem; line-height: 1; padding: 0;">&times;</button>
            </div>
        </div>
    `;
        document.body.appendChild(notice);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            const el = document.getElementById('ownerFundNotice');
            if (el) el.style.opacity = '0';
            setTimeout(() => {
                if (el) el.remove();
            }, 300);
        }, 5000);
    }

    // Setor Tunai ke Rekening Operasional
    // Pure internal transfer - NOT entered in cash_book, only balance updates
    function fillSetorTunai() {
        // Set date to today
        document.querySelector('input[name="transaction_date"]').value = '<?php echo date("Y-m-d"); ?>';
        document.querySelector('input[name="transaction_time"]').value = '<?php echo date("H:i"); ?>';

        // Set cash account to Kas Tunai (source account to be debited)
        const cashAccountSelect = document.querySelector('select[name="cash_account_id"]');
        if (cashAccountSelect) {
            // Find cash/tunai account
            for (let opt of cashAccountSelect.options) {
                if (opt.text.toLowerCase().includes('tunai') || opt.text.toLowerCase().includes('cash')) {
                    cashAccountSelect.value = opt.value;
                    break;
                }
            }
        }

        // Set description (optional)
        document.querySelector('textarea[name="description"]').value = '';

        // Create hidden source_type field if not exists
        let sourceTypeField = document.querySelector('input[name="source_type"]');
        if (!sourceTypeField) {
            sourceTypeField = document.createElement('input');
            sourceTypeField.type = 'hidden';
            sourceTypeField.name = 'source_type';
            document.querySelector('form').appendChild(sourceTypeField);
        }
        sourceTypeField.value = 'cash_transfer';

        // Focus on amount field for user to input nominal
        const amountField = document.querySelector('input[name="amount"]');
        if (amountField) {
            amountField.value = '';
            amountField.focus();
        }

        // Show notification
        showSetorTunaiNotice();
    }

    function showSetorTunaiNotice() {
        // Remove existing notice
        const existing = document.getElementById('setorTunaiNotice');
        if (existing) existing.remove();

        // Create notice
        const notice = document.createElement('div');
        notice.id = 'setorTunaiNotice';
        notice.innerHTML = `
        <div style="position: fixed; top: 80px; right: 20px; padding: 1rem 1.25rem; background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 1px solid #0284c7; border-radius: 12px; box-shadow: 0 4px 20px rgba(2, 132, 199, 0.3); z-index: 9999; max-width: 380px; animation: slideIn 0.3s ease;">
            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                <span style="font-size: 1.5rem;">🏦</span>
                <div>
                    <div style="font-weight: 700; color: #0c4a6e; font-size: 0.875rem; margin-bottom: 0.25rem;">TRANSFER INTERNAL - Setor Tunai</div>
                    <div style="font-size: 0.75rem; color: #0c5460; line-height: 1.5;">
                        ✅ Hanya kurangi saldo kas<br/>
                        ✅ Hanya tambah saldo bank<br/>
                        ❌ Bukan income/expense<br/>
                        ❌ Tidak ubah total nominal<br/>
                        📝 Input nominal & submit
                    </div>
                </div>
                <button onclick="this.parentElement.parentElement.parentElement.remove()" style="background: none; border: none; color: #0c4a6e; cursor: pointer; font-size: 1.25rem; line-height: 1; padding: 0;">&times;</button>
            </div>
        </div>
    `;
        document.body.appendChild(notice);

        // Auto-remove after 8 seconds
        setTimeout(() => {
            const el = document.getElementById('setorTunaiNotice');
            if (el) el.style.opacity = '0';
            setTimeout(() => {
                if (el) el.remove();
            }, 300);
        }, 8000);
    }
    <?php if ($isCQC): ?>
        // CQC: Transfer to Petty Cash
        function fillTransferPettyCash() {
            // Set transaction type to income (uang masuk ke Petty Cash)
            const incomeRadio = document.querySelector('input[name="transaction_type"][value="income"]');
            if (incomeRadio) {
                incomeRadio.checked = true;
                incomeRadio.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }

            // Wait for income section to show, then fill
            setTimeout(() => {
                // Set income type to topup_owner
                const incomeTypeSelect = document.getElementById('cqc_income_type');
                if (incomeTypeSelect) {
                    incomeTypeSelect.value = 'topup_owner';
                    incomeTypeSelect.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                // Set project to Operational Office
                const projectSelect = document.getElementById('cqc_project_id');
                if (projectSelect) {
                    projectSelect.value = 'operational';
                    projectSelect.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                // Set cash account to Petty Cash
                const cashAccountSelect = document.querySelector('select[name="cash_account_id"]');
                if (cashAccountSelect) {
                    for (let opt of cashAccountSelect.options) {
                        if (opt.text.toLowerCase().includes('petty') || opt.text.toLowerCase().includes('kas operasional')) {
                            cashAccountSelect.value = opt.value;
                            break;
                        }
                    }
                }

                // Set payment method to cash
                const cashPayment = document.querySelector('input[name="payment_method"][value="cash"]');
                if (cashPayment) cashPayment.checked = true;

                // Set source_type hidden field to owner_fund
                let sourceTypeField = document.querySelector('input[name="source_type"]');
                if (!sourceTypeField) {
                    sourceTypeField = document.createElement('input');
                    sourceTypeField.type = 'hidden';
                    sourceTypeField.name = 'source_type';
                    document.querySelector('form').appendChild(sourceTypeField);
                }
                sourceTypeField.value = 'owner_fund';

                // Set description
                const descField = document.querySelector('textarea[name="description"]');
                if (descField) {
                    descField.value = 'Transfer Petty Cash - Operasional Office & Proyek';
                }

                // Update income desc field
                const incomeDescField = document.getElementById('cqc_income_desc');
                if (incomeDescField) {
                    incomeDescField.value = 'Operasional Office & Proyek';
                }

                // Focus on amount field
                const amountField = document.querySelector('input[name="amount"]');
                if (amountField) {
                    amountField.value = '';
                    amountField.focus();
                }

                // Show notification
                showPettyCashNotice();
            }, 100);
        }

        function showPettyCashNotice() {
            // Remove existing notice
            const existing = document.getElementById('pettyCashNotice');
            if (existing) existing.remove();

            // Create notice
            const notice = document.createElement('div');
            notice.id = 'pettyCashNotice';
            notice.innerHTML = `
        <div style="position: fixed; top: 80px; right: 20px; padding: 1rem 1.25rem; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #22c55e; border-radius: 12px; box-shadow: 0 4px 20px rgba(34, 197, 94, 0.25); z-index: 9999; max-width: 350px; animation: slideIn 0.3s ease;">
            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                <span style="font-size: 1.5rem;">💸</span>
                <div>
                    <div style="font-weight: 700; color: #166534; font-size: 0.875rem; margin-bottom: 0.25rem;">Operasional Office & Proyek</div>
                    <div style="font-size: 0.75rem; color: #15803d; line-height: 1.4;">Form sudah diisi otomatis. Masukkan jumlah untuk <strong>Petty Cash</strong>.</div>
                    <div style="font-size: 0.7rem; color: #64748b; margin-top: 0.3rem;">⚡ Saldo Kas Besar akan otomatis berkurang</div>
                </div>
                <button onclick="this.parentElement.parentElement.parentElement.remove()" style="background: none; border: none; color: #166534; cursor: pointer; font-size: 1.25rem; line-height: 1; padding: 0;">&times;</button>
            </div>
        </div>
    `;
            document.body.appendChild(notice);

            // Auto-remove after 6 seconds
            setTimeout(() => {
                const el = document.getElementById('pettyCashNotice');
                if (el) el.style.opacity = '0';
                setTimeout(() => {
                    if (el) el.remove();
                }, 300);
            }, 6000);
        }

        // CQC Project Info Display
        function updateCQCProjectInfo(select) {
            const opt = select.options[select.selectedIndex];
            const info = document.getElementById('cqcProjectInfo');
            if (!opt.value) {
                info.style.display = 'none';
                return;
            }

            // Handle Operational Office selection
            if (opt.value === 'operational') {
                info.innerHTML = '<div style="display: flex; gap: 1rem; font-size: 0.75rem; color: #0d1f3c;"><span>💼 <strong>Operasional Office & Proyek</strong> - Untuk kebutuhan kantor dan proyek</span></div>';
                info.style.display = 'block';
                info.style.background = 'linear-gradient(135deg, rgba(59,130,246,0.08), rgba(59,130,246,0.03))';
                info.style.borderLeftColor = '#3b82f6';
                return;
            }

            // Reset styling for regular projects
            info.style.background = 'linear-gradient(135deg, rgba(13,31,60,0.05), rgba(240,180,41,0.08))';
            info.style.borderLeftColor = '#f0b429';
            info.innerHTML = '<div style="display: flex; gap: 1rem; font-size: 0.7rem;"><span>💰 Budget: <strong id="cqcBudgetDisplay">-</strong></span><span>📤 Terpakai: <strong id="cqcSpentDisplay" style="color: #ef4444;">-</strong></span><span>💵 Sisa: <strong id="cqcRemainingDisplay" style="color: #10b981;">-</strong></span></div>';

            const budget = parseFloat(opt.dataset.budget || 0);
            const spent = parseFloat(opt.dataset.spent || 0);
            const remaining = parseFloat(opt.dataset.remaining || 0);

            document.getElementById('cqcBudgetDisplay').textContent = 'Rp ' + budget.toLocaleString('id-ID');
            document.getElementById('cqcSpentDisplay').textContent = 'Rp ' + spent.toLocaleString('id-ID');
            document.getElementById('cqcRemainingDisplay').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
            document.getElementById('cqcRemainingDisplay').style.color = remaining >= 0 ? '#10b981' : '#ef4444';
            info.style.display = 'block';

            // Update income description if income is selected
            updateCQCIncomeCategory(document.getElementById('cqc_income_type'));
        }

        // Toggle expense/income sections based on transaction type
        function toggleCQCSections() {
            const type = document.querySelector('input[name="transaction_type"]:checked')?.value;
            const expSection = document.getElementById('cqcExpenseSection');
            const incSection = document.getElementById('cqcIncomeSection');
            const catInput = document.querySelector('[name=category_name]');

            if (type === 'expense') {
                expSection.style.display = 'block';
                incSection.style.display = 'none';
                catInput.setAttribute('required', 'required');
                catInput.value = '';
            } else {
                expSection.style.display = 'none';
                incSection.style.display = 'block';
                catInput.removeAttribute('required');
                // Reset income type
                document.getElementById('cqc_income_type').value = '';
                document.getElementById('cqc_income_desc').value = '';
            }
        }

        // Update category_name based on income type + selected project
        function updateCQCIncomeCategory(select) {
            if (!select) return;
            const type = select.value;
            const projSelect = document.getElementById('cqc_project_id');
            const projOpt = projSelect.options[projSelect.selectedIndex];
            const projName = projOpt && projOpt.value ? projOpt.textContent.trim().split(' [')[0] : '';
            const descInput = document.getElementById('cqc_income_desc');
            const catInput = document.querySelector('[name=category_name]');
            const noteDiv = document.getElementById('cqcIncomeNote');

            const labels = {
                'topup_owner': 'Transfer Petty Cash',
                'dp': 'DP Masuk',
                'termin': 'Pembayaran Termin',
                'pelunasan': 'Pelunasan',
                'retensi': 'Retensi / Garansi'
            };

            // Show/hide note
            if (type === 'topup_owner') {
                noteDiv.style.display = 'block';
                noteDiv.style.background = '#fef3c7';
                noteDiv.style.color = '#92400e';
                noteDiv.innerHTML = '⚠️ <strong>Ini BUKAN pendapatan perusahaan.</strong> Transfer ke Petty Cash untuk operasional proyek. Tidak masuk ke laporan income.';
            } else if (['dp', 'termin', 'pelunasan'].includes(type)) {
                noteDiv.style.display = 'block';
                noteDiv.style.background = '#dcfce7';
                noteDiv.style.color = '#166534';
                noteDiv.innerHTML = '✅ Ini adalah <strong>pendapatan dari invoice</strong>. Masuk ke laporan income.';
            } else {
                noteDiv.style.display = 'none';
            }

            if (type === 'manual') {
                descInput.removeAttribute('readonly');
                descInput.placeholder = 'Tulis keterangan...';
                descInput.value = '';
                descInput.focus();
            } else if (type === 'topup_owner') {
                descInput.setAttribute('readonly', 'readonly');
                descInput.value = 'Transfer Petty Cash dari Owner';
                catInput.value = 'Transfer Petty Cash';
            } else if (type && labels[type]) {
                descInput.setAttribute('readonly', 'readonly');
                const desc = projName ? labels[type] + ' - ' + projName : labels[type];
                descInput.value = desc;
                catInput.value = desc;
            } else {
                descInput.setAttribute('readonly', 'readonly');
                descInput.value = '';
                catInput.value = '';
                noteDiv.style.display = 'none';
            }
        }

        // Listen for transaction type changes
        document.querySelectorAll('input[name="transaction_type"]').forEach(radio => {
            radio.addEventListener('change', toggleCQCSections);
        });

        // Handle form submit - sync income desc to category_name
        document.getElementById('transactionForm').addEventListener('submit', function() {
            const type = document.querySelector('input[name="transaction_type"]:checked')?.value;
            if (type === 'income') {
                const desc = document.getElementById('cqc_income_desc').value;
                if (desc) {
                    document.querySelector('[name=category_name]').value = desc;
                }
            }
        });

        // Initialize on load
        toggleCQCSections();
    <?php endif; ?>

    // ============================================
    // PROJECT EXPENSE TOGGLE (NON-HOTEL EXPENSE)
    // ============================================
    <?php if ($isHotel): ?>
        const projectDivisionKeywords = ['proyek', 'projek', 'project', 'konstruksi', 'renovasi', 'pembangunan', 'bangunan'];

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
                projectSelect.required = checked;
                if (!checked) projectSelect.value = '';
            }
        }

        // Auto-detect project division
        const divisionSelect = document.getElementById('division_id');
        if (divisionSelect) {
            divisionSelect.addEventListener('change', function() {
                const text = this.options[this.selectedIndex]?.text?.toLowerCase() || '';
                const isProjectDiv = projectDivisionKeywords.some(kw => text.includes(kw));
                const checkbox = document.getElementById('isProjectExpense');
                if (checkbox && isProjectDiv && !checkbox.checked) {
                    checkbox.checked = true;
                    toggleProjectExpense();
                }
            });
        }
    <?php endif; ?>

    // ============================================
    // DUPLICATE TRANSACTION CHECK
    // ============================================
    let dupCheckBypassed = false;

    function handleFormSubmit(e) {
        e.preventDefault();

        if (!validateForm('transactionForm')) return false;
        if (dupCheckBypassed) {
            dupCheckBypassed = false;
            document.getElementById('transactionForm').submit();
            return true;
        }

        const form = document.getElementById('transactionForm');
        const date = form.querySelector('[name=transaction_date]').value;
        const rawAmount = form.querySelector('[name=amount]').value;
        const amount = rawAmount.replace(/[.,]/g, '');
        const category = form.querySelector('[name=category_name]').value;
        const description = form.querySelector('[name=description]')?.value || '';
        const type = form.querySelector('input[name=transaction_type]:checked')?.value || '';

        if (!amount || parseFloat(amount) <= 0 || !category) {
            form.submit();
            return true;
        }

        const params = new URLSearchParams({
            date,
            amount,
            category,
            description,
            type
        });

        fetch('../../api/check-duplicate-transaction.php?' + params)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.count > 0) {
                    showDuplicateWarning(data.duplicates, rawAmount, category);
                } else {
                    form.submit();
                }
            })
            .catch(() => {
                // If check fails, allow submit anyway
                form.submit();
            });

        return false;
    }

    function showDuplicateWarning(duplicates, amount, category) {
        // Remove existing modal if any
        const existing = document.getElementById('dupWarningModal');
        if (existing) existing.remove();

        let rows = '';
        duplicates.forEach(d => {
            const time = d.transaction_time ? d.transaction_time.substring(0, 5) : '-';
            const amt = parseInt(d.amount).toLocaleString('id-ID');
            const cat = d.category_name || '-';
            const desc = d.description ? (d.description.length > 40 ? d.description.substring(0, 40) + '...' : d.description) : '-';
            rows += `<tr>
            <td style="padding:8px; border-bottom:1px solid #333;">${time}</td>
            <td style="padding:8px; border-bottom:1px solid #333;">${cat}</td>
            <td style="padding:8px; border-bottom:1px solid #333; text-align:right; font-weight:700;">Rp ${amt}</td>
            <td style="padding:8px; border-bottom:1px solid #333; font-size:0.8rem;">${desc}</td>
        </tr>`;
        });

        const modal = document.createElement('div');
        modal.id = 'dupWarningModal';
        modal.innerHTML = `
    <div style="position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; display:flex; align-items:center; justify-content:center; padding:1rem;">
        <div style="background:#1e1e2e; border:2px solid #f59e0b; border-radius:16px; max-width:560px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.5);">
            <div style="padding:1.25rem; border-bottom:1px solid #333; display:flex; align-items:center; gap:0.75rem;">
                <span style="font-size:2rem;">⚠️</span>
                <div>
                    <h3 style="margin:0; color:#fbbf24; font-size:1.1rem;">Transaksi Serupa Terdeteksi!</h3>
                    <p style="margin:0.25rem 0 0; color:#94a3b8; font-size:0.85rem;">
                        Ada ${duplicates.length} transaksi dengan nominal & kategori sama hari ini
                    </p>
                </div>
            </div>
            <div style="padding:1rem; max-height:300px; overflow-y:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem; color:#e2e8f0;">
                    <thead>
                        <tr style="color:#94a3b8; font-size:0.75rem; text-transform:uppercase;">
                            <th style="padding:6px 8px; text-align:left;">Jam</th>
                            <th style="padding:6px 8px; text-align:left;">Kategori</th>
                            <th style="padding:6px 8px; text-align:right;">Nominal</th>
                            <th style="padding:6px 8px; text-align:left;">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
            <div style="padding:1rem; border-top:1px solid #333; display:flex; gap:0.75rem; justify-content:flex-end;">
                <button onclick="document.getElementById('dupWarningModal').remove()" 
                    style="padding:0.6rem 1.25rem; background:#374151; color:#e2e8f0; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.9rem;">
                    ✏️ Cek Ulang
                </button>
                <button onclick="submitDespiteDuplicate()" 
                    style="padding:0.6rem 1.25rem; background:#dc2626; color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.9rem;">
                    ✓ Tetap Simpan
                </button>
            </div>
        </div>
    </div>`;
        document.body.appendChild(modal);
    }

    function submitDespiteDuplicate() {
        const modal = document.getElementById('dupWarningModal');
        if (modal) modal.remove();
        dupCheckBypassed = true;
        document.getElementById('transactionForm').dispatchEvent(new Event('submit'));
    }
</script>

<?php include '../../includes/footer.php'; ?>