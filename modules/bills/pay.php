<?php
/**
 * BILLS MODULE - Pay Bill
 * Process payment and auto-insert into cashbook
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

if (!isPost()) {
    redirect(BASE_URL . '/modules/bills/');
}

$recordId = (int)getPost('record_id');
$templateId = (int)getPost('template_id');
$paidDate = sanitize(getPost('paid_date'));
$paidAmount = (int)str_replace(['.', ',', ' '], '', getPost('paid_amount'));
$paymentMethod = sanitize(getPost('payment_method'));
$notes = sanitize(getPost('notes'));
$autoCashbook = getPost('auto_cashbook') == '1';

if (!$recordId || !$paidAmount || !$paidDate) {
    setFlash('error', 'Data pembayaran tidak lengkap!');
    redirect(BASE_URL . '/modules/bills/');
}

try {
    $db->beginTransaction();
    
    // Get bill record and template
    $record = $db->fetchOne("SELECT br.*, bt.bill_name, bt.bill_category, bt.vendor_name, bt.division_id, bt.category_id, bt.payment_method as default_payment
        FROM bill_records br 
        JOIN bill_templates bt ON br.template_id = bt.id 
        WHERE br.id = ?", [$recordId]);
    
    if (!$record) {
        throw new Exception('Tagihan tidak ditemukan');
    }
    
    if ($record['status'] === 'paid') {
        throw new Exception('Tagihan ini sudah dibayar');
    }
    
    $cashbookId = null;
    
    // Auto-insert into cashbook
    if ($autoCashbook) {
        // Find/validate division_id
        $divisionId = $record['division_id'];
        if (!$divisionId) {
            // Use "Utilities" or first available division
            $utilDiv = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%util%' OR LOWER(division_code) LIKE '%util%' LIMIT 1");
            if ($utilDiv) {
                $divisionId = $utilDiv['id'];
            } else {
                $firstDiv = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 ORDER BY id LIMIT 1");
                $divisionId = $firstDiv ? $firstDiv['id'] : 1;
            }
        }
        
        // Find/validate category_id
        $categoryId = $record['category_id'];
        if (!$categoryId) {
            // Find expense category matching bill type
            $catNameMap = [
                'electricity' => '%listrik%',
                'tax' => '%pajak%',
                'wifi' => '%internet%',
                'vehicle' => '%kendaraan%',
                'po' => '%purchase%',
                'other' => '%lain%'
            ];
            $searchPattern = $catNameMap[$record['bill_category']] ?? '%tagihan%';
            $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'expense' AND LOWER(category_name) LIKE ? LIMIT 1", [$searchPattern]);
            if (!$cat) {
                $cat = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'expense' ORDER BY id LIMIT 1");
            }
            $categoryId = $cat ? $cat['id'] : 1;
        }
        
        // Determine valid payment_method for cashbook
        $pmMethod = $paymentMethod ?: ($record['default_payment'] ?? 'transfer');
        
        // Check if payment_method is ENUM and validate
        try {
            $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(\PDO::FETCH_ASSOC);
            if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                $allowed = $enumMatches[1] ?? ['cash'];
                if (!in_array($pmMethod, $allowed)) {
                    $pmMethod = 'cash'; // fallback
                }
            }
        } catch (Exception $e) { /* ignore */ }
        
        // Validate created_by user exists
        $userId = $currentUser['id'] ?? 1;
        $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$userExists) {
            $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $userId = $firstUser ? $firstUser['id'] : 1;
        }
        
        // Build cashbook insert - start with required columns
        $cbData = [
            'transaction_date' => $paidDate,
            'transaction_time' => date('H:i:s'),
            'division_id' => $divisionId,
            'category_id' => $categoryId,
            'transaction_type' => 'expense',
            'amount' => $paidAmount,
            'description' => 'Pembayaran tagihan: ' . $record['bill_name'] . ($record['vendor_name'] ? ' - ' . $record['vendor_name'] : '') . ($notes ? ' (' . $notes . ')' : ''),
            'payment_method' => $pmMethod,
            'created_by' => $userId
        ];
        
        // Check which optional columns exist and add them
        try {
            $pdo = $db->getConnection();
            $cols = $pdo->query("SHOW COLUMNS FROM cash_book")->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('source_type', $cols)) {
                $cbData['source_type'] = 'bill_payment';
            }
            if (in_array('reference_no', $cols)) {
                $cbData['reference_no'] = 'BILL-' . $recordId;
            }
            if (in_array('is_editable', $cols)) {
                $cbData['is_editable'] = 0;
            }
            
            // Check and set cash_account_id
            if (in_array('cash_account_id', $cols)) {
                try {
                    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
                    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=utf8mb4", DB_USER, DB_PASS);
                    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $businessId = $_SESSION['business_id'] ?? 1;
                    
                    // Map payment method to account type
                    $acctType = ($pmMethod === 'cash') ? 'cash' : 'bank';
                    $cashAcct = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = ? LIMIT 1");
                    $cashAcct->execute([$businessId, $acctType]);
                    $acctRow = $cashAcct->fetch(PDO::FETCH_ASSOC);
                    if ($acctRow) {
                        $cbData['cash_account_id'] = $acctRow['id'];
                    }
                } catch (Exception $e) {
                    error_log("Bills pay.php - cash_account lookup error: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Bills pay.php - column check error: " . $e->getMessage());
        }
        
        $cashbookId = $db->insert('cash_book', $cbData);
        
        if (!$cashbookId) {
            throw new Exception('Gagal menyimpan ke buku kas');
        }
    }
    
    // Update bill record as paid
    $db->update('bill_records', [
        'status' => 'paid',
        'paid_date' => $paidDate,
        'paid_amount' => $paidAmount,
        'payment_method' => $paymentMethod,
        'cashbook_id' => $cashbookId,
        'notes' => $notes,
        'paid_by' => $currentUser['id'] ?? null
    ], 'id = :id', ['id' => $recordId]);
    
    $db->commit();
    
    $msg = 'Tagihan "' . $record['bill_name'] . '" berhasil dibayar ' . formatCurrency($paidAmount);
    if ($cashbookId) {
        $msg .= ' — otomatis tercatat di Buku Kas';
    }
    setFlash('success', $msg);
    
} catch (Exception $e) {
    $db->rollback();
    setFlash('error', 'Gagal memproses pembayaran: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/bills/');
