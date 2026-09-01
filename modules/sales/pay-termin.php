<?php
/**
 * CQC Pay Termin Invoice
 * Process payment for termin invoice
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index-cqc.php');
    exit;
}

try {
    $pdo = getCQCDatabaseConnection(); // CQC database (adf_cqc)
    
    $invoice_id = (int)$_POST['invoice_id'];
    $payment_method = $_POST['payment_method'] ?? 'transfer';
    $notes = trim($_POST['notes'] ?? '');
    $currentUser = $auth->getCurrentUser();
    
    // Get invoice info
    $stmt = $pdo->prepare("
        SELECT ti.*, p.project_code, p.project_name, p.client_name 
        FROM cqc_termin_invoices ti
        LEFT JOIN cqc_projects p ON ti.project_id = p.id
        WHERE ti.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception("Faktur tidak ditemukan.");
    }
    
    if ($invoice['payment_status'] === 'paid') {
        throw new Exception("Faktur sudah lunas.");
    }
    
    // Update invoice status
    $stmt = $pdo->prepare("
        UPDATE cqc_termin_invoices 
        SET payment_status = 'paid',
            paid_amount = total_amount,
            payment_date = CURDATE(),
            payment_method = :payment_method,
            payment_notes = :notes,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        'payment_method' => $payment_method,
        'notes' => $notes,
        'id' => $invoice_id
    ]);
    
    // Record in CQC cashbook (same database as CQC projects)
    $description = "[CQC_PROJECT:{$invoice['project_id']}] [{$invoice['project_code']}] Pembayaran {$invoice['invoice_number']} - Termin {$invoice['termin_number']} ({$invoice['percentage']}%) - {$invoice['client_name']}";
    
    // Get or create CQC division and category in CQC database
    $stmtDiv = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%cqc%' OR LOWER(division_code) = 'cqc' LIMIT 1");
    $cqcDivision = $stmtDiv->fetch(PDO::FETCH_ASSOC);
    if (!$cqcDivision) {
        $pdo->exec("INSERT INTO divisions (division_name, division_code, is_active) VALUES ('CQC Projects', 'CQC', 1)");
        $divisionId = $pdo->lastInsertId();
    } else {
        $divisionId = $cqcDivision['id'];
    }
    
    // Get or create income category
    $stmtCat = $pdo->query("SELECT id FROM categories WHERE category_type = 'income' LIMIT 1");
    $incomeCategory = $stmtCat->fetch(PDO::FETCH_ASSOC);
    if (!$incomeCategory) {
        $pdo->exec("INSERT INTO categories (category_name, category_type, division_id, is_active) VALUES ('Pembayaran Proyek', 'income', {$divisionId}, 1)");
        $categoryId = $pdo->lastInsertId();
    } else {
        $categoryId = $incomeCategory['id'];
    }
    
    // Determine cash_account_id from payment method
    $cashAccountId = null;
    try {
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bizId = getMasterBusinessId();
        // Cash payment → Petty Cash, Transfer/other → Bank
        $accType = ($payment_method === 'cash') ? 'cash' : 'bank';
        $stmtAcc = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = ? ORDER BY id LIMIT 1");
        $stmtAcc->execute([$bizId, $accType]);
        $accRow = $stmtAcc->fetch(PDO::FETCH_ASSOC);
        if ($accRow) $cashAccountId = $accRow['id'];
    } catch (Exception $e) {
        error_log("pay-termin: Error getting cash_account_id: " . $e->getMessage());
    }
    
    // Insert to CQC cash_book (adf_cqc database)
    $stmtCashbook = $pdo->prepare("
        INSERT INTO cash_book 
        (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, cash_account_id, source_type, is_editable, created_by)
        VALUES (CURDATE(), CURTIME(), ?, ?, 'income', ?, ?, ?, ?, 'invoice_payment', 0, ?)
    ");
    $stmtCashbook->execute([
        $divisionId,
        $categoryId,
        $invoice['total_amount'],
        $description,
        $payment_method,
        $cashAccountId,
        $currentUser['id']
    ]);
    
    // Update cash_accounts balance
    if ($cashAccountId) {
        try {
            // Add income to cash_account_transactions
            $stmtTrans = $masterDb->prepare("
                INSERT INTO cash_account_transactions 
                (cash_account_id, transaction_date, description, amount, transaction_type, created_by) 
                VALUES (?, CURDATE(), ?, ?, 'income', ?)
            ");
            $stmtTrans->execute([$cashAccountId, $description, $invoice['total_amount'], $currentUser['id']]);
            
            // Update balance
            $stmtBal = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
            $stmtBal->execute([$invoice['total_amount'], $cashAccountId]);
            error_log("pay-termin: Updated cash_account {$cashAccountId} +{$invoice['total_amount']}");
        } catch (Exception $e) {
            error_log("pay-termin: Error updating balance: " . $e->getMessage());
        }
    }
    
    $_SESSION['success'] = "Pembayaran {$invoice['invoice_number']} berhasil dicatat. Total: Rp " . number_format($invoice['total_amount'], 0, ',', '.');
    header('Location: index-cqc.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: index-cqc.php');
    exit;
}
