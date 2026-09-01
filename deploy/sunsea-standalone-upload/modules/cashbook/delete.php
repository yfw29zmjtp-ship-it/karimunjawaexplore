<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Delete Cash Book Transaction with Audit Log
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// ── Permission: only users with can_delete on cashbook may proceed ───────────
if (!$auth->canDelete('cashbook')) {
    $_SESSION['error'] = '⛔ Anda tidak memiliki izin untuk menghapus transaksi.';
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    $_SESSION['error'] = 'ID transaksi tidak valid';
    header('Location: index.php');
    exit;
}

// Get transaction details before deleting (for audit log)
$transaction = $db->fetchOne(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, '-') as division_name,
        COALESCE(c.category_name, '-') as category_name,
        COALESCE(u.full_name, '-') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    WHERE cb.id = :id",
    ['id' => $id]
);

if (!$transaction) {
    $_SESSION['error'] = 'Transaksi tidak ditemukan';
    header('Location: index.php');
    exit;
}

// Setor Tunai rows are tracked separately in cash_transfers (master DB) and already
// moved real money between cash_accounts. Deleting the cash_book row here would NOT
// reverse the bank-side credit, causing a balance mismatch. Use the archive feature
// on the "Ringkasan Setor Tunai" page instead.
if (isset($transaction['source_type']) && $transaction['source_type'] === 'cash_transfer') {
    $_SESSION['error'] = '⛔ Transaksi Setor Tunai tidak bisa dihapus dari sini. Gunakan fitur arsip di halaman Ringkasan Setor Tunai.';
    header('Location: index.php');
    exit;
}

try {
    $db->beginTransaction();
    
    // Check if transaction is from Purchase Order
    $isPurchaseOrder = false;
    $poNumber = '';
    $poId = null;
    
    if (isset($transaction['source_type']) && $transaction['source_type'] === 'purchase_order' && !empty($transaction['source_id'])) {
        $isPurchaseOrder = true;
        $poId = $transaction['source_id'];
        
        // Get PO number
        $po = $db->fetchOne("SELECT po_number FROM purchase_orders_header WHERE id = ?", [$poId]);
        if ($po) {
            $poNumber = $po['po_number'];
        }
    }
    
    // Create audit log
    $oldData = json_encode([
        'id' => $transaction['id'],
        'transaction_date' => $transaction['transaction_date'] ?? '',
        'transaction_time' => $transaction['transaction_time'] ?? '',
        'division' => $transaction['division_name'] ?? '-',
        'category' => $transaction['category_name'] ?? '-',
        'transaction_type' => $transaction['transaction_type'] ?? '',
        'amount' => $transaction['amount'] ?? 0,
        'payment_method' => $transaction['payment_method'] ?? '',
        'description' => $transaction['description'] ?? '',
        'created_by' => $transaction['created_by_name'] ?? '-',
        'source_type' => $transaction['source_type'] ?? 'manual',
        'source_id' => $transaction['source_id'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    
    // Get user IP and browser info
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Insert audit log (wrapped in try-catch so it doesn't block delete)
    try {
        $db->insert('audit_logs', [
            'table_name' => 'cash_book',
            'record_id' => $id,
            'action' => 'DELETE',
            'old_data' => $oldData,
            'user_id' => $currentUser['id'],
            'user_name' => $currentUser['full_name'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    } catch (Exception $auditEx) {
        // Audit log failed, continue with delete anyway
    }
    
    // If from PO, update PO status back to submitted and remove attachment
    if ($isPurchaseOrder && $poId) {
        $db->update('purchase_orders_header', [
            'status' => 'submitted',
            'approved_by' => null,
            'approved_at' => null,
            'attachment_path' => null
        ], 'id = :id', ['id' => $poId]);
    }
    
    // ============================================
    // FIX: Reverse balance in cash_accounts when deleting
    // ============================================
    $cashAccountId = $transaction['cash_account_id'] ?? null;
    $amount = floatval($transaction['amount'] ?? 0);
    $transactionType = $transaction['transaction_type'] ?? '';
    
    if (!empty($cashAccountId) && $amount > 0) {
        try {
            $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            if ($transactionType === 'income') {
                // Was income: subtract from balance (reverse the add)
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $stmt->execute([$amount, $cashAccountId]);
            } else {
                // Was expense: add back to balance (reverse the subtract)
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?");
                $stmt->execute([$amount, $cashAccountId]);
            }
            
            // Delete related cash_account_transactions record
            $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id = ? AND ABS(amount - ?) < 1 AND transaction_type = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cashAccountId, $amount, $transactionType]);
            
            error_log("DELETE REVERSE: Account #{$cashAccountId}, Type: {$transactionType}, Amount: {$amount} - Balance restored");
        } catch (Exception $balanceErr) {
            error_log("Delete balance reverse error: " . $balanceErr->getMessage());
            // Don't fail the delete, just log the error
        }
    }
    
    // Delete the transaction
    $db->delete('cash_book', 'id = :id', ['id' => $id]);
    
    $db->commit();
    
    if ($isPurchaseOrder) {
        $_SESSION['success'] = '✅ Transaksi pembayaran <strong>PO ' . $poNumber . '</strong> berhasil dihapus dari Buku Kas Besar.<br>⚠️ Status PO dikembalikan ke <strong>"Menunggu Approve"</strong>. Silakan approve ulang jika diperlukan.';
    } else {
        $_SESSION['success'] = '✅ Transaksi berhasil dihapus. Log penghapusan telah dicatat.';
    }
    
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['error'] = '❌ Gagal menghapus transaksi: ' . $e->getMessage();
}

header('Location: index.php');
exit;
