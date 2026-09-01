<?php
/**
 * BENS CAFE - Invoice Management
 * Create invoices, mark paid -> auto-post to cash_book
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Auto-fix ENUM on cash_book
try { $pdo->exec("ALTER TABLE `cash_book` MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cash'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`"); } catch (Exception $e) {}

// === AUTO-CREATE TABLE ===
$pdo->exec("CREATE TABLE IF NOT EXISTS `cafe_invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(30) NOT NULL,
    `customer_name` VARCHAR(100) NOT NULL DEFAULT 'Walk-in',
    `customer_phone` VARCHAR(30) DEFAULT NULL,
    `customer_note` TEXT DEFAULT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status` ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `cash_account_id` INT DEFAULT NULL,
    `cash_book_id` INT DEFAULT NULL,
    `paid_at` DATETIME DEFAULT NULL,
    `paid_by` INT DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_inv_num` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `cafe_invoice_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT NOT NULL,
    `item_name` VARCHAR(150) NOT NULL,
    `qty` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
    INDEX `idx_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load company info
$companyName = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_name'")['setting_value'] ?? 'Bens Cafe';
$companyAddress = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_address'")['setting_value'] ?? '';
$companyPhone = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_phone'")['setting_value'] ?? '';
$companyEmail = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_email'")['setting_value'] ?? '';
$companyTagline = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='company_tagline'")['setting_value'] ?? 'Fresh Coffee & Good Vibes';
$logoUrl = getBusinessLogo();

// Load payment/bank account info shown on the invoice (editable via "Rekening" button)
$invBankName = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='invoice_bank_name'")['setting_value'] ?? '';
$invBankAccountNumber = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='invoice_bank_account_number'")['setting_value'] ?? '';
$invBankAccountHolder = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='invoice_bank_account_holder'")['setting_value'] ?? '';
$invQrUrl = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key='invoice_qr_url'")['setting_value'] ?? '';

// Load cash accounts from master DB
$masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$businessId = getMasterBusinessId();
$cashAccounts = $masterDb->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY account_type, account_name");
$cashAccounts->execute([$businessId]);
$cashAccounts = $cashAccounts->fetchAll(PDO::FETCH_ASSOC);

// Load divisions
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");
$defaultDivision = $divisions[0]['id'] ?? 1;

// === AJAX HANDLERS ===
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'get' && isset($_GET['id'])) {
        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ?", [(int)$_GET['id']]);
        if (!$inv) { echo json_encode(['success' => false]); exit; }
        $items = $db->fetchAll("SELECT * FROM cafe_invoice_items WHERE invoice_id = ?", [(int)$_GET['id']]);
        echo json_encode(['success' => true, 'invoice' => $inv, 'items' => $items]);
        exit;
    }

    if ($_GET['ajax'] === 'pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $cashAccountId = !empty($_POST['cash_account_id']) ? (int)$_POST['cash_account_id'] : null;
        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ? AND status = 'unpaid'", [$invId]);
        if (!$inv) { echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan atau sudah dibayar']); exit; }
        if (!$cashAccountId) { echo json_encode(['success' => false, 'message' => 'Rekening tujuan wajib dipilih']); exit; }
        // Enforce that the chosen rekening type matches the payment method (cash -> cash account,
        // edc/qr/transfer/debit/other -> non-cash account) so money always lands in the right bukukas.
        $accRow = $masterDb->prepare("SELECT account_type FROM cash_accounts WHERE id = ? AND business_id = ? AND is_active = 1");
        $accRow->execute([$cashAccountId, $businessId]);
        $accRow = $accRow->fetch(PDO::FETCH_ASSOC);
        if (!$accRow) { echo json_encode(['success' => false, 'message' => 'Rekening tidak valid']); exit; }
        $isCashType = $accRow['account_type'] === 'cash';
        if (($paymentMethod === 'cash' && !$isCashType) || ($paymentMethod !== 'cash' && $isCashType)) {
            echo json_encode(['success' => false, 'message' => 'Rekening tidak sesuai dengan metode pembayaran yang dipilih']); exit;
        }
        try {
            $db->beginTransaction();
            $cat = $db->fetchOne("SELECT id FROM categories WHERE LOWER(category_name) = 'invoice cafe' AND category_type = 'income'");
            if (!$cat) {
                $db->insert('categories', ['category_name' => 'Invoice Cafe', 'category_type' => 'income', 'division_id' => $defaultDivision, 'is_active' => 1]);
                $catId = $pdo->lastInsertId();
            } else { $catId = $cat['id']; }
            $cbId = $db->insert('cash_book', [
                'transaction_date' => date('Y-m-d'), 'transaction_time' => date('H:i:s'),
                'division_id' => $defaultDivision, 'category_id' => $catId,
                'transaction_type' => 'income', 'amount' => $inv['total_amount'],
                'description' => 'Bayar ' . $inv['invoice_number'] . ' - ' . $inv['customer_name'],
                'payment_method' => $paymentMethod, 'cash_account_id' => $cashAccountId,
                'created_by' => $_SESSION['user_id'], 'source_type' => 'invoice_payment', 'is_editable' => 1
            ]);
            $db->update('cafe_invoices', [
                'status' => 'paid', 'payment_method' => $paymentMethod,
                'cash_account_id' => $cashAccountId, 'cash_book_id' => $cbId,
                'paid_at' => date('Y-m-d H:i:s'), 'paid_by' => $_SESSION['user_id']
            ], 'id = :id', ['id' => $invId]);
            if ($cashAccountId) {
                try {
                    $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$inv['total_amount'], $cashAccountId]);
                    $masterDb->prepare("INSERT INTO cash_account_transactions (account_id, business_id, transaction_type, amount, description, reference_type, reference_id, created_at) VALUES (?, ?, 'credit', ?, ?, 'cafe_invoice', ?, NOW())")
                        ->execute([$cashAccountId, $businessId, $inv['total_amount'], 'Bayar ' . $inv['invoice_number'], $invId]);
                } catch (Exception $e) { error_log("Cash account update: " . $e->getMessage()); }
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Invoice ' . $inv['invoice_number'] . ' berhasil dibayar!']);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $inv = $db->fetchOne("SELECT * FROM cafe_invoices WHERE id = ?", [$invId]);
        if (!$inv) { echo json_encode(['success' => false, 'message' => 'Invoice tidak ditemukan']); exit; }
        try {
            $db->beginTransaction();
            // If the invoice was already paid, reverse its cashbook entry & account balance
            // so deleting it never leaves stale/incorrect money in the bukukas.
            if ($inv['status'] === 'paid') {
                if (!empty($inv['cash_book_id'])) {
                    $db->delete('cash_book', 'id = :id', ['id' => $inv['cash_book_id']]);
                }
                if (!empty($inv['cash_account_id'])) {
                    try {
                        $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?")
                            ->execute([$inv['total_amount'], $inv['cash_account_id']]);
                        $masterDb->prepare("DELETE FROM cash_account_transactions WHERE reference_type = 'cafe_invoice' AND reference_id = ?")
                            ->execute([$invId]);
                    } catch (Exception $e) { error_log("Cash account reverse: " . $e->getMessage()); }
                }
            }
            $db->delete('cafe_invoice_items', 'invoice_id = :id', ['id' => $invId]);
            $db->delete('cafe_invoices', 'id = :id', ['id' => $invId]);
            $db->commit();
            $msg = $inv['status'] === 'paid'
                ? 'Invoice ' . $inv['invoice_number'] . ' dan catatan kas terkait berhasil dihapus'
                : 'Invoice dihapus';
            echo json_encode(['success' => true, 'message' => $msg]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'save_bank_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Use trim/strip_tags only (not sanitize()'s htmlspecialchars) so the raw value is stored;
        // it is HTML-escaped once at display time (both in PHP and via escHtml() in JS).
        $bankName = trim(strip_tags($_POST['bank_name'] ?? ''));
        $bankAccountNumber = trim(strip_tags($_POST['bank_account_number'] ?? ''));
        $bankAccountHolder = trim(strip_tags($_POST['bank_account_holder'] ?? ''));
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['invoice_bank_name', $bankName]);
            $stmt->execute(['invoice_bank_account_number', $bankAccountNumber]);
            $stmt->execute(['invoice_bank_account_holder', $bankAccountHolder]);
            echo json_encode(['success' => true, 'message' => 'Info rekening berhasil disimpan']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal simpan: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File logo tidak valid']); exit;
        }
        $fileExt = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            echo json_encode(['success' => false, 'message' => 'Format file tidak didukung (jpg/png/gif/webp)']); exit;
        }
        try {
            $cloudinary = CloudinaryHelper::getInstance();
            $result = $cloudinary->smartUpload(
                $_FILES['logo'], 'uploads/logos', ACTIVE_BUSINESS_ID . '_logo.' . $fileExt,
                'logos', 'company_logo_' . ACTIVE_BUSINESS_ID
            );
            if (!$result['success']) {
                echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload gagal']); exit;
            }
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['company_logo_' . ACTIVE_BUSINESS_ID, $result['path']]);
            $url = $result['is_cloud'] ? $result['url'] : (BASE_URL . '/uploads/logos/' . $result['path'] . '?v=' . time());
            echo json_encode(['success' => true, 'message' => 'Logo berhasil diperbarui', 'url' => $url]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal upload: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'upload_qr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_FILES['qr']) || $_FILES['qr']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'File QR tidak valid']); exit;
        }
        $fileExt = strtolower(pathinfo($_FILES['qr']['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            echo json_encode(['success' => false, 'message' => 'Format file tidak didukung (jpg/png/gif/webp)']); exit;
        }
        try {
            $cloudinary = CloudinaryHelper::getInstance();
            $result = $cloudinary->smartUpload(
                $_FILES['qr'], 'uploads/qr', ACTIVE_BUSINESS_ID . '_invoice_qr.' . $fileExt,
                'invoice-qr', 'invoice_qr_' . ACTIVE_BUSINESS_ID
            );
            if (!$result['success']) {
                echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload gagal']); exit;
            }
            $url = $result['is_cloud'] ? $result['url'] : (BASE_URL . '/uploads/qr/' . $result['path'] . '?v=' . time());
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute(['invoice_qr_url', $url]);
            echo json_encode(['success' => true, 'message' => 'QR pembayaran berhasil disimpan', 'url' => $url]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal upload: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['ajax'] === 'remove_qr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $pdo->prepare("UPDATE settings SET setting_value = '' WHERE setting_key = 'invoice_qr_url'")->execute();
            echo json_encode(['success' => true, 'message' => 'QR pembayaran dihapus']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal hapus: ' . $e->getMessage()]);
        }
        exit;
    }
    exit;
}

// === CREATE INVOICE (POST) ===
if (isPost() && getPost('action') === 'create_invoice') {
    $customerName = sanitize(getPost('customer_name')) ?: 'Walk-in';
    $customerPhone = sanitize(getPost('customer_phone'));
    $customerNote = sanitize(getPost('customer_note'));
    $discount = floatval(str_replace(['.', ','], ['', '.'], getPost('discount_amount', '0')));
    $taxPercent = floatval(getPost('tax_percent', '0'));
    $notes = sanitize(getPost('notes'));
    $itemNames = getPost('item_name', []);
    $itemQtys = getPost('item_qty', []);
    $itemPrices = getPost('item_price', []);
    if (empty($itemNames) || empty($itemNames[0])) {
        setFlash('error', 'Minimal 1 item harus diisi');
    } else {
        try {
            $db->beginTransaction();
            $today = date('Ymd');
            $last = $db->fetchOne("SELECT invoice_number FROM cafe_invoices WHERE invoice_number LIKE ? ORDER BY id DESC LIMIT 1", ["INV-CF-$today-%"]);
            $seq = 1;
            if ($last) { $parts = explode('-', $last['invoice_number']); $seq = (int)end($parts) + 1; }
            $invNumber = "INV-CF-$today-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
            $subtotal = 0;
            $validItems = [];
            for ($i = 0; $i < count($itemNames); $i++) {
                $name = trim($itemNames[$i] ?? '');
                $qty = max(1, (int)($itemQtys[$i] ?? 1));
                $price = floatval(str_replace(['.', ','], ['', '.'], $itemPrices[$i] ?? '0'));
                if (empty($name) || $price <= 0) continue;
                $lineTotal = $qty * $price;
                $subtotal += $lineTotal;
                $validItems[] = ['name' => $name, 'qty' => $qty, 'price' => $price, 'subtotal' => $lineTotal];
            }
            if (empty($validItems)) throw new Exception('Tidak ada item valid');
            $taxAmount = round($subtotal * $taxPercent / 100);
            $totalAmount = $subtotal - $discount + $taxAmount;
            $invId = $db->insert('cafe_invoices', [
                'invoice_number' => $invNumber, 'customer_name' => $customerName,
                'customer_phone' => $customerPhone, 'customer_note' => $customerNote,
                'subtotal' => $subtotal, 'discount_amount' => $discount,
                'tax_amount' => $taxAmount, 'total_amount' => $totalAmount,
                'notes' => $notes, 'created_by' => $_SESSION['user_id']
            ]);
            foreach ($validItems as $item) {
                $db->insert('cafe_invoice_items', [
                    'invoice_id' => $invId, 'item_name' => $item['name'],
                    'qty' => $item['qty'], 'unit_price' => $item['price'], 'subtotal' => $item['subtotal']
                ]);
            }
            $db->commit();
            setFlash('success', "Invoice $invNumber berhasil dibuat!");
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollback();
            setFlash('error', 'Gagal membuat invoice: ' . $e->getMessage());
        }
    }
}

// === LOAD DATA ===
$filter = sanitize(getGet('filter', 'all'));
$search = sanitize(getGet('q', ''));
$where = "1=1";
$params = [];
if ($filter === 'unpaid') $where .= " AND ci.status = 'unpaid'";
elseif ($filter === 'paid') $where .= " AND ci.status = 'paid'";
if ($search) { $where .= " AND (ci.invoice_number LIKE ? OR ci.customer_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$invoices = $db->fetchAll("SELECT ci.*, u.full_name as creator_name FROM cafe_invoices ci LEFT JOIN " . DB_NAME . ".users u ON u.id = ci.created_by WHERE $where ORDER BY ci.id DESC LIMIT 100", $params);
$stats = $db->fetchOne("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='unpaid' THEN 1 ELSE 0 END) as unpaid_count,
    SUM(CASE WHEN status='unpaid' THEN total_amount ELSE 0 END) as unpaid_amount,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
    SUM(CASE WHEN status='paid' AND DATE(paid_at) = CURDATE() THEN total_amount ELSE 0 END) as today_paid
FROM cafe_invoices");

$pageTitle = 'Invoice Bens Cafe';
include '../../includes/header.php';
$successMsg = getFlash('success');
$errorMsg = getFlash('error');
$logoForInvoice = $logoUrl ?: '';
$businessIcon = defined('BUSINESS_ICON') ? BUSINESS_ICON : 'C';
?>

<style>
:root {
    --cafe: #0369a1; --cafe-light: #e0f2fe; --cafe-dark: #075985; --cafe-bg: #f0f9ff;
    --cafe-gold: #38bdf8; --cafe-cream: #f0f9ff; --cafe-espresso: #0c4a6e;
    --shadow-sm: 0 1px 3px rgba(3,105,161,.06);
    --shadow-md: 0 4px 16px rgba(3,105,161,.08);
    --shadow-lg: 0 12px 40px rgba(3,105,161,.12);
    --radius: 14px;
}

/* Stats Cards */
.inv-stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 24px; }
.inv-stat { background: #fff; border-radius: var(--radius); padding: 18px 20px; border: 1px solid rgba(3,105,161,.08); position: relative; overflow: hidden; box-shadow: var(--shadow-sm); transition: all .3s; }
.inv-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.inv-stat::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 3px 3px 0 0; }
.inv-stat h4 { font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .8px; margin: 0 0 8px; }
.inv-stat .val { font-size: 24px; font-weight: 800; margin: 0; line-height: 1.2; }
.inv-stat .sub { font-size: 11px; font-weight: 600; color: #9ca3af; margin-top: 2px; }
.inv-stat.s1::before { background: linear-gradient(90deg, #0284c7, #38bdf8); } .inv-stat.s1 .val { color: var(--cafe); }
.inv-stat.s2::before { background: linear-gradient(90deg, #ef4444, #f97316); } .inv-stat.s2 .val { color: #dc2626; }
.inv-stat.s3::before { background: linear-gradient(90deg, #10b981, #34d399); } .inv-stat.s3 .val { color: #059669; }
.inv-stat.s4::before { background: linear-gradient(90deg, #3b82f6, #8b5cf6); } .inv-stat.s4 .val { color: #3b82f6; }

/* Toolbar */
.inv-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
.inv-filters { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 10px; }
.inv-filters a { padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 700; text-decoration: none; color: #6b7280; transition: all .2s; }
.inv-filters a.active { background: #fff; color: var(--cafe); box-shadow: var(--shadow-sm); }
.inv-filters a:hover:not(.active) { color: var(--cafe-dark); }

/* Table */
.inv-table-wrap { background: #fff; border-radius: var(--radius); border: 1px solid rgba(3,105,161,.06); overflow: hidden; box-shadow: var(--shadow-sm); }
.inv-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.inv-table th { background: linear-gradient(180deg, #e0f2fe, #bae6fd); padding: 12px 14px; text-align: left; font-size: 10px; font-weight: 800; color: var(--cafe); text-transform: uppercase; letter-spacing: .6px; border-bottom: 2px solid #7dd3fc; }
.inv-table td { padding: 12px 14px; border-bottom: 1px solid #e0f2fe; vertical-align: middle; }
.inv-table tr { transition: background .15s; }
.inv-table tr:hover { background: linear-gradient(90deg, #f0f9ff, #fff); }
.inv-table tr:last-child td { border-bottom: none; }
.inv-num { font-weight: 800; color: var(--cafe); font-size: 12px; font-family: 'Courier New', monospace; letter-spacing: .3px; }
.badge { padding: 4px 12px; border-radius: 20px; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; }
.b-unpaid { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #dc2626; border: 1px solid #fca5a5; }
.b-paid { background: linear-gradient(135deg, #f0fdf4, #dcfce7); color: #059669; border: 1px solid #86efac; }
.b-cancelled { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }

/* Buttons - CLEAR AND VISIBLE */
.btn-cafe { background: linear-gradient(135deg, var(--cafe), var(--cafe-dark)); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .25s; box-shadow: 0 2px 8px rgba(3,105,161,.2); letter-spacing: .3px; }
.btn-cafe:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(3,105,161,.3); filter: brightness(1.05); }
.btn-sm { padding: 7px 14px; font-size: 11px; font-weight: 800; border-radius: 8px; }
.btn-pay { background: linear-gradient(135deg, #059669, #047857); box-shadow: 0 2px 8px rgba(5,150,105,.3); color: #fff; }
.btn-pay:hover { box-shadow: 0 6px 20px rgba(5,150,105,.4); }
.btn-view { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 2px 8px rgba(59,130,246,.3); color: #fff; }
.btn-view:hover { box-shadow: 0 6px 20px rgba(59,130,246,.4); }
.btn-del { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 2px 8px rgba(220,38,38,.2); }
.btn-del:hover { box-shadow: 0 6px 20px rgba(220,38,38,.3); }
.btn-ghost { background: #fff; color: #6b7280; border: 1.5px solid #e5e7eb; box-shadow: none; }
.btn-ghost:hover { background: #f9fafb; color: #374151; border-color: #d1d5db; }
.btn-create { background: linear-gradient(135deg, #0284c7, #0369a1); font-size: 13px; padding: 10px 22px; font-weight: 800; box-shadow: 0 3px 12px rgba(3,105,161,.3); }
.btn-create:hover { box-shadow: 0 6px 24px rgba(3,105,161,.4); }
.btn-search { background: linear-gradient(135deg, #6b7280, #4b5563); padding: 8px 14px; }

/* Create Form - compact */
.cf-card { background: #fff; border-radius: 12px; padding: 14px 16px; border: 1px solid rgba(3,105,161,.06); margin-bottom: 10px; box-shadow: var(--shadow-sm); }
.cf-title { font-size: 12px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 10px; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #f3f4f6; }
.cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px; }
.cf-label { font-size: 10.5px; font-weight: 700; color: #4b5563; margin-bottom: 3px; display: block; letter-spacing: .2px; }
.cf-input { width: 100%; padding: 6px 10px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: 12px; background: #fafafa; transition: all .2s; box-sizing: border-box; }
.cf-input:focus { outline: none; border-color: var(--cafe); background: #fff; box-shadow: 0 0 0 3px rgba(3,105,161,.08); }

/* Item rows */
.item-row { display: grid; grid-template-columns: 1fr 60px 100px 90px 30px; gap: 6px; align-items: end; margin-bottom: 5px; padding: 5px 7px; border-radius: 7px; background: #fafafa; transition: background .15s; }
.item-row:hover { background: var(--cafe-cream); }
.item-header { display: grid; grid-template-columns: 1fr 60px 100px 90px 30px; gap: 6px; font-size: 9.5px; font-weight: 800; color: var(--cafe); text-transform: uppercase; letter-spacing: .5px; padding: 0 7px 6px; border-bottom: 2px solid #7dd3fc; margin-bottom: 6px; }
.remove-item { width: 26px; height: 26px; border-radius: 7px; border: 1px solid #fca5a5; background: #fff; color: #dc2626; cursor: pointer; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; transition: all .2s; }
.remove-item:hover { background: #dc2626; color: #fff; }
.totals-box { background: linear-gradient(135deg, var(--cafe-cream), #f0f9ff); border: 1.5px solid #7dd3fc; border-radius: 10px; padding: 11px 13px; margin-top: 10px; }
.totals-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 11.5px; color: #4b5563; }
.totals-row.grand { font-size: 15px; font-weight: 800; color: var(--cafe-espresso); border-top: 2px solid var(--cafe); padding-top: 7px; margin-top: 5px; }

/* Modal - compact */
.modal-bg { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(3,50,80,.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal-bg.open { display: flex; }
.modal-box { background: #fff; border-radius: 16px; padding: 18px 20px; max-width: 460px; width: 92%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(3,105,161,.15); animation: modalIn .3s ease; }
@keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(.97); } to { opacity: 1; transform: none; } }
.modal-title { font-size: 15px; font-weight: 800; color: var(--cafe-dark); margin: 0 0 12px; }

/* Pay cards */
.pay-methods { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin: 12px 0; }
.pay-card { padding: 14px 8px; border: 2px solid #f3f4f6; border-radius: 12px; text-align: center; cursor: pointer; transition: all .25s; background: #fafafa; }
.pay-card:hover { border-color: var(--cafe-gold); background: var(--cafe-cream); transform: translateY(-1px); }
.pay-card.selected { border-color: var(--cafe); background: var(--cafe-light); box-shadow: 0 0 0 3px rgba(3,105,161,.12); }
.pay-card .pay-icon { font-size: 24px; margin-bottom: 4px; }
.pay-card .pay-label { font-size: 11px; font-weight: 800; color: #374151; }

/* Invoice Preview - compact & elegant (kept in sync with printInvoice()'s inline CSS) */
.inv-preview { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: var(--shadow-lg); border: 1px solid rgba(3,105,161,.08); position: relative; }
.inv-preview-inner { padding: 22px 30px 24px; }
.inv-hdr-band { background: linear-gradient(135deg, var(--cafe-espresso) 0%, var(--cafe-dark) 50%, var(--cafe) 100%); padding: 18px 30px; color: #fff; position: relative; overflow: hidden; }
.inv-hdr-content { position: relative; z-index: 1; display: flex; align-items: center; gap: 14px; }
.inv-hdr-logo { width: 48px; height: 48px; border-radius: 11px; overflow: hidden; background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.25); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.inv-hdr-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 9px; }
.inv-hdr-logo .fallback-icon { font-size: 20px; font-weight: 900; color: #fff; }
.inv-hdr-name { font-size: 17px; font-weight: 800; letter-spacing: .3px; margin: 0; text-shadow: 0 1px 2px rgba(0,0,0,.25); }
.inv-hdr-tagline { font-size: 10.5px; color: rgba(255,255,255,.92); margin: 2px 0 0; font-style: italic; text-shadow: 0 1px 2px rgba(0,0,0,.2); }
.inv-hdr-contacts { font-size: 9.5px; font-weight: 600; color: rgba(255,255,255,.9); margin-top: 4px; display: flex; flex-wrap: wrap; gap: 8px; text-shadow: 0 1px 2px rgba(0,0,0,.2); }
.inv-title-bar { display: flex; justify-content: space-between; align-items: flex-start; padding: 14px 0 12px; margin: 0 0 12px; border-bottom: 2px solid var(--cafe-light); }
.inv-title-label { font-size: 20px; font-weight: 900; color: var(--cafe); letter-spacing: 1.5px; }
.inv-title-number { font-size: 11.5px; font-weight: 700; color: var(--cafe-dark); font-family: 'Courier New', monospace; margin-top: 3px; }
.inv-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; padding: 10px 14px; background: var(--cafe-cream); border-radius: 8px; border: 1px solid #bae6fd; }
.inv-meta-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #9ca3af; letter-spacing: .6px; margin-bottom: 2px; }
.inv-meta-val { font-size: 11.5px; font-weight: 700; color: #1f2937; }
.inv-items-tbl { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 14px; }
.inv-items-tbl thead th { background: linear-gradient(180deg, var(--cafe-espresso), var(--cafe-dark)); color: #fff; padding: 7px 10px; text-align: left; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .8px; }
.inv-items-tbl thead th:first-child { border-radius: 6px 0 0 0; }
.inv-items-tbl thead th:last-child { border-radius: 0 6px 0 0; text-align: right; }
.inv-items-tbl tbody td { padding: 7px 10px; border-bottom: 1px solid #e0f2fe; }
.inv-items-tbl tbody tr:nth-child(even) { background: #f0f9ff; }
.inv-items-tbl td:last-child { text-align: right; font-weight: 700; }
.inv-items-tbl td:nth-child(3) { text-align: center; }
.inv-items-tbl td:nth-child(4) { text-align: right; }
.inv-totals { margin-left: auto; width: 190px; }
.inv-total-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 11.5px; color: #6b7280; }
.inv-total-row.grand { font-size: 14.5px; font-weight: 900; color: var(--cafe-espresso); border-top: 2px solid var(--cafe); padding: 8px 0 0; margin-top: 5px; }
.inv-bank-box { clear: both; margin: 12px 0 4px; padding: 9px 12px; background: var(--cafe-cream); border: 1.5px dashed var(--cafe-gold); border-radius: 8px; }
.inv-bank-label { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--cafe); margin-bottom: 3px; }
.inv-bank-row { display: flex; align-items: baseline; gap: 8px; }
.inv-bank-bankname { font-size: 11px; font-weight: 800; color: var(--cafe-espresso); }
.inv-bank-num { font-size: 13px; font-weight: 900; color: var(--cafe-dark); font-family: "Courier New", monospace; letter-spacing: .4px; }
.inv-bank-holder { font-size: 10px; color: #6b7280; margin-top: 1px; }
.inv-pay-row { display: flex; gap: 10px; margin: 12px 0 4px; flex-wrap: wrap; clear: both; }
.inv-pay-row .inv-bank-box { margin: 0; flex: 1; min-width: 150px; }
.inv-qr-box { padding: 9px 12px; background: var(--cafe-cream); border: 1.5px dashed var(--cafe-gold); border-radius: 8px; text-align: center; flex-shrink: 0; }
.inv-qr-label { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--cafe); margin-bottom: 5px; }
.inv-qr-box img { width: 72px; height: 72px; object-fit: contain; background: #fff; border-radius: 6px; border: 1px solid #e5e7eb; padding: 3px; }
.inv-status-line { text-align: center; margin: 10px 0 4px; font-size: 10.5px; font-weight: 700; letter-spacing: .3px; }
.inv-status-line.paid { color: #059669; }
.inv-status-line.unpaid { color: #dc2626; }
.inv-footer-bar { background: linear-gradient(135deg, #f0f9ff, #e0f2fe); padding: 11px 30px; text-align: center; border-top: 1px solid #bae6fd; }
.inv-footer-bar .thanks { font-size: 12px; font-weight: 700; color: var(--cafe-dark); margin: 0 0 3px; }
.inv-footer-bar .tagline { font-size: 9.5px; color: #9ca3af; font-style: italic; }
.inv-footer-bar .legal { font-size: 8.5px; color: #d1d5db; margin-top: 4px; }

/* Anti-forgery watermark: color is set inline per status (green=PAID, red=UNPAID) */
.inv-watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-35deg); font-size: 64px; font-weight: 900; letter-spacing: 10px; text-transform: uppercase; pointer-events: none; z-index: 5; white-space: nowrap; border-width: 6px; border-style: solid; padding: 8px 32px; border-radius: 16px; }

@media print { body * { visibility: hidden; } #printArea, #printArea * { visibility: visible; } #printArea { position: absolute; left: 0; top: 0; width: 100%; } }
@media (max-width: 768px) {
    .inv-stats { grid-template-columns: repeat(2,1fr); }
    .item-row, .item-header { grid-template-columns: 1fr 60px 100px 36px; }
    .item-row .line-total-col, .item-header .line-total-col { display: none; }
    .inv-meta-grid { grid-template-columns: 1fr; }
    .inv-totals { width: 100%; }
}
</style>

<?php if ($successMsg): ?>
<div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#065f46;font-weight:700;box-shadow:0 2px 8px rgba(5,150,105,.08);"><?php echo $successMsg; ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border:1px solid #fca5a5;border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:#991b1b;font-weight:700;box-shadow:0 2px 8px rgba(220,38,38,.08);"><?php echo $errorMsg; ?></div>
<?php endif; ?>

<!-- VIEW: INVOICE LIST -->
<div id="viewList">

<div class="inv-stats">
    <div class="inv-stat s1"><h4>TOTAL INVOICE</h4><p class="val"><?php echo (int)$stats['total']; ?></p></div>
    <div class="inv-stat s2"><h4>BELUM BAYAR</h4><p class="val"><?php echo (int)$stats['unpaid_count']; ?></p><div class="sub"><?php echo formatCurrency($stats['unpaid_amount'] ?? 0); ?></div></div>
    <div class="inv-stat s3"><h4>LUNAS HARI INI</h4><p class="val"><?php echo formatCurrency($stats['today_paid'] ?? 0); ?></p></div>
    <div class="inv-stat s4"><h4>TOTAL LUNAS</h4><p class="val"><?php echo (int)$stats['paid_count']; ?></p></div>
</div>

<div class="inv-toolbar">
    <div class="inv-filters">
        <a href="?filter=all" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">Semua</a>
        <a href="?filter=unpaid" class="<?php echo $filter === 'unpaid' ? 'active' : ''; ?>">Belum Bayar</a>
        <a href="?filter=paid" class="<?php echo $filter === 'paid' ? 'active' : ''; ?>">Lunas</a>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari invoice..." class="cf-input" style="width:180px;padding:7px 13px;font-size:12px;">
            <button type="submit" class="btn-cafe btn-sm btn-search">Cari</button>
        </form>
        <button onclick="openBankModal()" class="btn-cafe btn-sm btn-ghost" title="Atur logo, rekening & QR pembayaran yang tampil di invoice">
            Setting Invoice
            <?php if ($invBankName || $invBankAccountNumber || $invQrUrl): ?>
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;margin-left:5px;" title="Sudah diatur"></span>
            <?php else: ?>
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#d1d5db;margin-left:5px;" title="Belum diatur"></span>
            <?php endif; ?>
        </button>
        <button onclick="showCreate()" class="btn-cafe btn-create">+ Buat Invoice</button>
    </div>
</div>

<div class="inv-table-wrap">
<?php if (empty($invoices)): ?>
    <div style="text-align:center;padding:50px 20px;">
        <div style="font-size:14px;font-weight:700;color:#9ca3af;">Belum ada invoice</div>
        <div style="font-size:12px;color:#d1d5db;margin-top:4px;">Klik <b>Buat Invoice</b> untuk mulai</div>
    </div>
<?php else: ?>
    <table class="inv-table">
        <thead><tr><th>No. Invoice</th><th>Pelanggan</th><th>Total</th><th>Metode</th><th>Status</th><th>Tanggal</th><th style="text-align:center;">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $inv): ?>
        <tr>
            <td><span class="inv-num"><?php echo htmlspecialchars($inv['invoice_number']); ?></span></td>
            <td>
                <div style="font-weight:700;font-size:12px;color:#1f2937;"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                <?php if ($inv['customer_phone']): ?><div style="font-size:10px;color:#9ca3af;margin-top:1px;">Tel: <?php echo htmlspecialchars($inv['customer_phone']); ?></div><?php endif; ?>
            </td>
            <td style="font-weight:800;color:var(--cafe-dark);"><?php echo formatCurrency($inv['total_amount']); ?></td>
            <td>
                <?php if ($inv['payment_method']): ?>
                    <span style="font-size:11px;font-weight:700;color:var(--cafe);background:var(--cafe-light);padding:3px 10px;border-radius:6px;"><?php echo ucfirst($inv['payment_method']); ?></span>
                <?php else: ?>
                    <span style="color:#d1d5db;">-</span>
                <?php endif; ?>
            </td>
            <td><span class="badge b-<?php echo $inv['status']; ?>"><?php echo $inv['status'] === 'paid' ? 'PAID' : ($inv['status'] === 'unpaid' ? 'UNPAID' : 'BATAL'); ?></span></td>
            <td style="font-size:11px;color:#6b7280;">
                <?php echo date('d/m/Y H:i', strtotime($inv['created_at'])); ?>
                <?php if ($inv['paid_at']): ?><br><span style="color:#059669;font-size:10px;font-weight:600;">Bayar: <?php echo date('d/m H:i', strtotime($inv['paid_at'])); ?></span><?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;">
                    <button onclick="viewInvoice(<?php echo $inv['id']; ?>)" class="btn-cafe btn-sm btn-view">Lihat</button>
                    <?php if ($inv['status'] === 'unpaid'): ?>
                    <button onclick="openPayModal(<?php echo $inv['id']; ?>, '<?php echo htmlspecialchars($inv['invoice_number']); ?>', <?php echo $inv['total_amount']; ?>)" class="btn-cafe btn-sm btn-pay">Bayar</button>
                    <?php endif; ?>
                    <?php if ($inv['status'] !== 'cancelled'): ?>
                    <button onclick="deleteInvoice(<?php echo $inv['id']; ?>, <?php echo $inv['status'] === 'paid' ? 'true' : 'false'; ?>)" class="btn-cafe btn-sm btn-del">Hapus</button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
</div>

<!-- VIEW: CREATE INVOICE -->
<div id="viewCreate" style="display:none;">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
        <button onclick="hideCreate()" class="btn-cafe btn-ghost btn-sm">&larr; Kembali</button>
        <div>
            <h2 style="font-size:17px;font-weight:800;color:var(--cafe-dark);margin:0;">Buat Invoice Baru</h2>
            <p style="font-size:11px;color:#9ca3af;margin:2px 0 0;">Isi detail pesanan pelanggan</p>
        </div>
    </div>
    <form method="POST" id="createForm">
        <input type="hidden" name="action" value="create_invoice">
        <div class="cf-card">
            <div class="cf-title">Info Pelanggan</div>
            <div class="cf-row">
                <div><label class="cf-label">Nama Pelanggan</label><input type="text" name="customer_name" class="cf-input" placeholder="Walk-in" value="Walk-in"></div>
                <div><label class="cf-label">No. HP</label><input type="text" name="customer_phone" class="cf-input" placeholder="081xxx"></div>
            </div>
            <div><label class="cf-label">Catatan untuk Pelanggan</label><textarea name="customer_note" class="cf-input" rows="2" placeholder="Opsional" style="resize:vertical;"></textarea></div>
        </div>
        <div class="cf-card">
            <div class="cf-title">Item Pesanan</div>
            <div class="item-header">
                <div>Nama Item</div><div>Qty</div><div>Harga</div><div class="line-total-col">Subtotal</div><div></div>
            </div>
            <div id="itemsContainer">
                <div class="item-row" data-index="0">
                    <div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required style="font-weight:600;"></div>
                    <div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>
                    <div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>
                    <div class="line-total-col" style="font-size:12px;font-weight:800;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>
                    <div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">&times;</button></div>
                </div>
            </div>
            <button type="button" onclick="addItem()" style="margin-top:10px;background:var(--cafe-cream);border:2px dashed var(--cafe-gold);color:var(--cafe);padding:10px 16px;border-radius:10px;font-size:12px;font-weight:800;cursor:pointer;width:100%;transition:all .2s;">+ Tambah Item</button>
            <div class="totals-box">
                <div class="cf-row" style="margin-bottom:8px;">
                    <div><label class="cf-label">Diskon (Rp)</label><input type="text" name="discount_amount" class="cf-input" value="0" onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()" id="discountInput"></div>
                    <div><label class="cf-label">Pajak (%)</label><input type="number" name="tax_percent" class="cf-input" value="0" min="0" max="100" step="0.5" onchange="calcTotals()" onkeyup="calcTotals()" id="taxInput"></div>
                </div>
                <div class="totals-row"><span>Subtotal</span><span id="dispSubtotal">Rp 0</span></div>
                <div class="totals-row"><span>Diskon</span><span id="dispDiscount" style="color:#dc2626;">- Rp 0</span></div>
                <div class="totals-row"><span>Pajak</span><span id="dispTax">Rp 0</span></div>
                <div class="totals-row grand"><span>TOTAL</span><span id="dispTotal">Rp 0</span></div>
            </div>
        </div>
        <div class="cf-card">
            <label class="cf-label">Catatan Internal</label>
            <textarea name="notes" class="cf-input" rows="2" placeholder="Catatan internal (opsional)" style="resize:vertical;"></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
            <button type="button" onclick="hideCreate()" class="btn-cafe btn-ghost" style="padding:11px 24px;">Batal</button>
            <button type="submit" class="btn-cafe btn-create">Simpan Invoice</button>
        </div>
    </form>
</div>

<!-- MODAL: SETTING INVOICE (Logo, Rekening, QR) -->
<div class="modal-bg" id="bankModal">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-title">Setting Invoice</div>
        <p style="font-size:11px;color:#9ca3af;margin:-8px 0 14px;">Atur logo, rekening & QR pembayaran yang tampil di invoice.</p>

        <!-- Logo -->
        <div style="border:1px solid #f3f4f6;border-radius:10px;padding:12px;margin-bottom:10px;">
            <div style="font-size:11px;font-weight:800;color:var(--cafe-dark);margin-bottom:8px;">Logo Perusahaan</div>
            <div style="display:flex;align-items:center;gap:10px;">
                <div id="logoPreviewBox" style="width:44px;height:44px;border-radius:10px;overflow:hidden;background:var(--cafe-cream);border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <?php if ($logoUrl): ?>
                        <img id="logoPreviewImg" src="<?php echo htmlspecialchars($logoUrl); ?>" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                        <span id="logoPreviewImg" style="font-size:16px;font-weight:900;color:var(--cafe);"><?php echo htmlspecialchars(strtoupper(substr($companyName, 0, 1))); ?></span>
                    <?php endif; ?>
                </div>
                <input type="file" id="logoFile" accept="image/*" class="cf-input" style="flex:1;padding:5px 8px;font-size:11px;">
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:8px;">
                <button onclick="uploadLogo()" class="btn-cafe btn-sm btn-pay" id="logoUploadBtn">Upload Logo</button>
            </div>
        </div>

        <!-- Rekening -->
        <div style="border:1px solid #f3f4f6;border-radius:10px;padding:12px;margin-bottom:10px;">
            <div style="font-size:11px;font-weight:800;color:var(--cafe-dark);margin-bottom:8px;">Rekening Pembayaran (Transfer)</div>
            <label class="cf-label">Nama Bank</label>
            <input type="text" id="bankName" class="cf-input" placeholder="Contoh: BCA" style="margin-bottom:10px;" value="<?php echo htmlspecialchars($invBankName); ?>">
            <label class="cf-label">Nomor Rekening</label>
            <input type="text" id="bankAccountNumber" class="cf-input" placeholder="Contoh: 1234567890" style="margin-bottom:10px;" value="<?php echo htmlspecialchars($invBankAccountNumber); ?>">
            <label class="cf-label">Atas Nama</label>
            <input type="text" id="bankAccountHolder" class="cf-input" placeholder="Contoh: PT Bens Cafe Indonesia" style="margin-bottom:10px;" value="<?php echo htmlspecialchars($invBankAccountHolder); ?>">
            <div style="display:flex;justify-content:flex-end;">
                <button onclick="saveBankInfo()" class="btn-cafe btn-sm btn-pay" id="bankSaveBtn">Simpan Rekening</button>
            </div>
        </div>

        <!-- QR Code -->
        <div style="border:1px solid #f3f4f6;border-radius:10px;padding:12px;margin-bottom:16px;">
            <div style="font-size:11px;font-weight:800;color:var(--cafe-dark);margin-bottom:8px;">QR Code Pembayaran (QRIS, dll)</div>
            <div id="qrPreviewWrap" style="display:<?php echo $invQrUrl ? 'flex' : 'none'; ?>;align-items:center;gap:10px;margin-bottom:8px;">
                <img id="qrPreviewImg" src="<?php echo htmlspecialchars($invQrUrl); ?>" style="width:52px;height:52px;object-fit:contain;border:1px solid #e5e7eb;border-radius:6px;background:#fff;padding:3px;">
                <button onclick="removeQr()" type="button" class="btn-cafe btn-sm btn-ghost">Hapus QR</button>
            </div>
            <input type="file" id="qrFile" accept="image/*" class="cf-input" style="padding:5px 8px;font-size:11px;margin-bottom:8px;">
            <div style="display:flex;justify-content:flex-end;">
                <button onclick="uploadQr()" class="btn-cafe btn-sm btn-pay" id="qrUploadBtn">Upload QR</button>
            </div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button onclick="closeBankModal()" class="btn-cafe btn-ghost">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL: PAY INVOICE -->
<div class="modal-bg" id="payModal">
    <div class="modal-box">
        <div class="modal-title">Bayar Invoice</div>
        <div style="background:linear-gradient(135deg,var(--cafe-cream),#f0f9ff);border-radius:12px;padding:16px;margin-bottom:16px;border:1px solid #bae6fd;">
            <div style="font-size:10px;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Invoice</div>
            <div style="font-size:16px;font-weight:800;color:var(--cafe-dark);margin-top:2px;" id="payInvNum"></div>
            <div style="font-size:24px;font-weight:900;color:var(--cafe);margin-top:6px;" id="payInvAmount"></div>
        </div>
        <label class="cf-label">Metode Pembayaran *</label>
        <div class="pay-methods" id="payMethods">
            <div class="pay-card" data-method="cash" onclick="selectPayMethod('cash')"><div class="pay-icon">$</div><div class="pay-label">Cash</div></div>
            <div class="pay-card" data-method="transfer" onclick="selectPayMethod('transfer')"><div class="pay-icon">Tf</div><div class="pay-label">Transfer</div></div>
            <div class="pay-card" data-method="qr" onclick="selectPayMethod('qr')"><div class="pay-icon">QR</div><div class="pay-label">QR Code</div></div>
            <div class="pay-card" data-method="debit" onclick="selectPayMethod('debit')"><div class="pay-icon">DC</div><div class="pay-label">Debit</div></div>
            <div class="pay-card" data-method="edc" onclick="selectPayMethod('edc')"><div class="pay-icon">EDC</div><div class="pay-label">EDC</div></div>
            <div class="pay-card" data-method="other" onclick="selectPayMethod('other')"><div class="pay-icon">...</div><div class="pay-label">Lainnya</div></div>
        </div>
        <label class="cf-label" style="margin-top:14px;">Uang Masuk ke Rekening *</label>
        <select class="cf-input" id="payAccount" style="margin-bottom:16px;">
            <option value="">-- Pilih Rekening --</option>
            <?php foreach ($cashAccounts as $acc): ?>
            <option value="<?php echo $acc['id']; ?>" data-type="<?php echo htmlspecialchars($acc['account_type']); ?>"><?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo ucfirst($acc['account_type']); ?>) - <?php echo formatCurrency($acc['current_balance']); ?></option>
            <?php endforeach; ?>
        </select>
        <div id="payAccountHint" style="font-size:11px;color:#9ca3af;margin-top:-10px;margin-bottom:16px;"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button onclick="closePayModal()" class="btn-cafe btn-ghost">Batal</button>
            <button onclick="submitPay()" class="btn-cafe btn-pay" style="padding:11px 28px;font-size:13px;" id="payBtn">Bayar Sekarang</button>
        </div>
    </div>
</div>

<!-- MODAL: VIEW INVOICE -->
<div class="modal-bg" id="viewModal">
    <div class="modal-box" style="max-width:800px;padding:0;overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 24px;background:#fafafa;border-bottom:1px solid #f3f4f6;">
            <div style="font-size:14px;font-weight:800;color:var(--cafe-dark);">Detail Invoice</div>
            <div style="display:flex;gap:6px;">
                <button onclick="printInvoice()" class="btn-cafe btn-sm btn-ghost">Print</button>
                <button onclick="document.getElementById('viewModal').classList.remove('open')" class="btn-cafe btn-sm btn-ghost">X</button>
            </div>
        </div>
        <div style="padding:0;max-height:75vh;overflow-y:auto;" id="printArea">
            <div class="inv-preview" id="invPreviewContent"></div>
        </div>
    </div>
</div>

<script>
var LOGO_URL = <?php echo json_encode($logoForInvoice); ?>;
var BIZ_ICON = <?php echo json_encode($businessIcon); ?>;
var COMPANY_NAME = <?php echo json_encode($companyName); ?>;
var COMPANY_ADDRESS = <?php echo json_encode($companyAddress); ?>;
var COMPANY_PHONE = <?php echo json_encode($companyPhone); ?>;
var COMPANY_EMAIL = <?php echo json_encode($companyEmail); ?>;
var COMPANY_TAGLINE = <?php echo json_encode($companyTagline); ?>;
var BANK_NAME = <?php echo json_encode($invBankName); ?>;
var BANK_ACCOUNT_NUMBER = <?php echo json_encode($invBankAccountNumber); ?>;
var BANK_ACCOUNT_HOLDER = <?php echo json_encode($invBankAccountHolder); ?>;
var QR_URL = <?php echo json_encode($invQrUrl); ?>;

function showCreate() { document.getElementById('viewList').style.display = 'none'; document.getElementById('viewCreate').style.display = 'block'; }
function hideCreate() { document.getElementById('viewList').style.display = 'block'; document.getElementById('viewCreate').style.display = 'none'; }

function openBankModal() { document.getElementById('bankModal').classList.add('open'); }
function closeBankModal() { document.getElementById('bankModal').classList.remove('open'); }
function saveBankInfo() {
    var btn = document.getElementById('bankSaveBtn');
    btn.disabled = true; btn.textContent = 'Menyimpan...';
    var fd = new FormData();
    fd.append('bank_name', document.getElementById('bankName').value.trim());
    fd.append('bank_account_number', document.getElementById('bankAccountNumber').value.trim());
    fd.append('bank_account_holder', document.getElementById('bankAccountHolder').value.trim());
    fetch('?ajax=save_bank_info', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false; btn.textContent = 'Simpan Rekening';
            if (data.success) {
                BANK_NAME = fd.get('bank_name');
                BANK_ACCOUNT_NUMBER = fd.get('bank_account_number');
                BANK_ACCOUNT_HOLDER = fd.get('bank_account_holder');
            } else { alert(data.message || 'Gagal menyimpan'); }
        }).catch(function() { btn.disabled = false; btn.textContent = 'Simpan Rekening'; alert('Network error'); });
}

function uploadLogo() {
    var fileInput = document.getElementById('logoFile');
    if (!fileInput.files.length) { alert('Pilih file logo dulu'); return; }
    var btn = document.getElementById('logoUploadBtn');
    btn.disabled = true; btn.textContent = 'Mengunggah...';
    var fd = new FormData();
    fd.append('logo', fileInput.files[0]);
    fetch('?ajax=upload_logo', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false; btn.textContent = 'Upload Logo';
            if (data.success) {
                LOGO_URL = data.url;
                document.getElementById('logoPreviewBox').innerHTML = '<img id="logoPreviewImg" src="' + escHtml(data.url) + '" style="width:100%;height:100%;object-fit:cover;">';
                fileInput.value = '';
            } else { alert(data.message || 'Gagal upload logo'); }
        }).catch(function() { btn.disabled = false; btn.textContent = 'Upload Logo'; alert('Network error'); });
}

function uploadQr() {
    var fileInput = document.getElementById('qrFile');
    if (!fileInput.files.length) { alert('Pilih file QR dulu'); return; }
    var btn = document.getElementById('qrUploadBtn');
    btn.disabled = true; btn.textContent = 'Mengunggah...';
    var fd = new FormData();
    fd.append('qr', fileInput.files[0]);
    fetch('?ajax=upload_qr', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false; btn.textContent = 'Upload QR';
            if (data.success) {
                QR_URL = data.url;
                document.getElementById('qrPreviewImg').src = data.url;
                document.getElementById('qrPreviewWrap').style.display = 'flex';
                fileInput.value = '';
            } else { alert(data.message || 'Gagal upload QR'); }
        }).catch(function() { btn.disabled = false; btn.textContent = 'Upload QR'; alert('Network error'); });
}

function removeQr() {
    if (!confirm('Hapus QR pembayaran dari invoice?')) return;
    fetch('?ajax=remove_qr', { method: 'POST' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                QR_URL = '';
                document.getElementById('qrPreviewWrap').style.display = 'none';
            } else { alert(data.message || 'Gagal menghapus'); }
        }).catch(function() { alert('Network error'); });
}

var itemIndex = 1;
function addItem() {
    var c = document.getElementById('itemsContainer');
    var row = document.createElement('div');
    row.className = 'item-row';
    row.dataset.index = itemIndex;
    row.innerHTML = '<div><input type="text" name="item_name[]" class="cf-input" placeholder="Nama menu/item" required style="font-weight:600;"></div>' +
        '<div><input type="number" name="item_qty[]" class="cf-input qty-input" value="1" min="1" onchange="calcTotals()" onkeyup="calcTotals()"></div>' +
        '<div><input type="text" name="item_price[]" class="cf-input price-input" placeholder="0" required onkeyup="formatPrice(this);calcTotals()" onchange="calcTotals()"></div>' +
        '<div class="line-total-col" style="font-size:12px;font-weight:800;color:var(--cafe);padding-top:8px;text-align:right;" data-linetotal>Rp 0</div>' +
        '<div><button type="button" class="remove-item" onclick="removeItem(this)" title="Hapus">&times;</button></div>';
    c.appendChild(row);
    itemIndex++;
    row.querySelector('input').focus();
}
function removeItem(btn) {
    if (document.querySelectorAll('#itemsContainer .item-row').length <= 1) return;
    btn.closest('.item-row').remove();
    calcTotals();
}
function parseRp(val) { return parseFloat(String(val).replace(/[^0-9]/g, '')) || 0; }
function formatPrice(el) { var v = el.value.replace(/[^0-9]/g, ''); if (v) el.value = parseInt(v).toLocaleString('id-ID'); }
function fmtRp(n) { return 'Rp ' + Math.round(n).toLocaleString('id-ID'); }
function calcTotals() {
    var subtotal = 0;
    document.querySelectorAll('#itemsContainer .item-row').forEach(function(row) {
        var qty = parseInt(row.querySelector('.qty-input').value) || 1;
        var price = parseRp(row.querySelector('.price-input').value);
        var line = qty * price;
        subtotal += line;
        var lt = row.querySelector('[data-linetotal]');
        if (lt) lt.textContent = fmtRp(line);
    });
    var discount = parseRp(document.getElementById('discountInput').value);
    var taxPct = parseFloat(document.getElementById('taxInput').value) || 0;
    var tax = Math.round(subtotal * taxPct / 100);
    var total = subtotal - discount + tax;
    document.getElementById('dispSubtotal').textContent = fmtRp(subtotal);
    document.getElementById('dispDiscount').textContent = '- ' + fmtRp(discount);
    document.getElementById('dispTax').textContent = fmtRp(tax);
    document.getElementById('dispTotal').textContent = fmtRp(total);
}

var payInvId = 0, payMethod = '';
function openPayModal(id, num, amount) {
    payInvId = id; payMethod = '';
    document.getElementById('payInvNum').textContent = num;
    document.getElementById('payInvAmount').textContent = fmtRp(amount);
    document.querySelectorAll('.pay-card').forEach(function(c) { c.classList.remove('selected'); });
    document.getElementById('payAccount').value = '';
    document.querySelectorAll('#payAccount option[data-type]').forEach(function(o) { o.hidden = false; o.disabled = false; });
    document.getElementById('payAccountHint').textContent = '';
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() { document.getElementById('payModal').classList.remove('open'); }
function selectPayMethod(method) {
    payMethod = method;
    document.querySelectorAll('.pay-card').forEach(function(c) { c.classList.remove('selected'); });
    document.querySelector('.pay-card[data-method="' + method + '"]').classList.add('selected');
    filterPayAccounts(method);
}
function filterPayAccounts(method) {
    // Enforce that the correct rekening (cash-type vs non-cash) is used for the
    // chosen payment method, so cash/edc/qr/transfer always post to the right account.
    var sel = document.getElementById('payAccount');
    var hint = document.getElementById('payAccountHint');
    var opts = sel.querySelectorAll('option[data-type]');
    var visibleCount = 0, firstVisibleValue = '';
    opts.forEach(function(opt) {
        var type = opt.getAttribute('data-type');
        var match = (method === 'cash') ? (type === 'cash') : (type !== 'cash');
        opt.hidden = !match;
        opt.disabled = !match;
        if (match) { visibleCount++; if (!firstVisibleValue) firstVisibleValue = opt.value; }
    });
    var current = sel.querySelector('option[value="' + sel.value + '"]');
    if (sel.value === '' || !current || current.hidden) {
        sel.value = visibleCount === 1 ? firstVisibleValue : '';
    }
    hint.textContent = visibleCount === 0
        ? '⚠️ Tidak ada rekening yang cocok untuk metode ini, hubungi admin untuk setup rekening.'
        : 'Menampilkan rekening yang sesuai dengan metode pembayaran ini.';
}
function submitPay() {
    if (!payMethod) { alert('Pilih metode pembayaran!'); return; }
    var accId = document.getElementById('payAccount').value;
    if (!accId) { alert('Pilih rekening tujuan!'); return; }
    var btn = document.getElementById('payBtn');
    btn.disabled = true; btn.textContent = 'Memproses...';
    var fd = new FormData();
    fd.append('invoice_id', payInvId);
    fd.append('payment_method', payMethod);
    fd.append('cash_account_id', accId);
    fetch('?ajax=pay', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { closePayModal(); location.reload(); }
            else { alert(data.message || 'Gagal'); btn.disabled = false; btn.textContent = 'Bayar Sekarang'; }
        }).catch(function() { alert('Network error'); btn.disabled = false; btn.textContent = 'Bayar Sekarang'; });
}

function buildLogoHtml() {
    if (LOGO_URL) return '<img src="' + escHtml(LOGO_URL) + '" alt="' + escHtml(COMPANY_NAME) + '" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">';
    return '<span class="fallback-icon">' + escHtml(BIZ_ICON) + '</span>';
}

function viewInvoice(id) {
    fetch('?ajax=get&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { alert('Invoice not found'); return; }
            var inv = data.invoice, items = data.items;
            var itemsHtml = '';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                itemsHtml += '<tr><td style="color:#9ca3af;font-weight:600;">' + String(i+1).padStart(2,'0') + '</td>' +
                    '<td style="font-weight:700;color:#1f2937;">' + escHtml(it.item_name) + '</td>' +
                    '<td style="text-align:center;">' + it.qty + 'x</td>' +
                    '<td style="text-align:right;">' + fmtRp(it.unit_price) + '</td>' +
                    '<td style="text-align:right;">' + fmtRp(it.subtotal) + '</td></tr>';
            }
            var isPaidStatus = inv.status === 'paid';
            var statusRgb = isPaidStatus ? '5,150,105' : '220,38,38';
            var watermarkHtml = '<div class="inv-watermark" style="color:rgba(' + statusRgb + ',.12);border-color:rgba(' + statusRgb + ',.10);">' + (isPaidStatus ? 'PAID' : 'UNPAID') + '</div>';
            var statusLineHtml = isPaidStatus
                ? '<div class="inv-status-line paid">Status: <b>PAID</b>' + ((inv.payment_method) ? ' &middot; ' + escHtml((inv.payment_method||'').toUpperCase()) : '') + (inv.paid_at ? ' &middot; ' + escHtml(inv.paid_at.substring(0,16)) : '') + '</div>'
                : '<div class="inv-status-line unpaid">Status: <b>UNPAID</b></div>';

            var discountHtml = parseFloat(inv.discount_amount) > 0
                ? '<div class="inv-total-row"><span>Diskon</span><span style="color:#dc2626;font-weight:700;">- ' + fmtRp(inv.discount_amount) + '</span></div>' : '';
            var taxHtml = parseFloat(inv.tax_amount) > 0
                ? '<div class="inv-total-row"><span>Pajak</span><span style="font-weight:700;">' + fmtRp(inv.tax_amount) + '</span></div>' : '';

            var phoneHtml = inv.customer_phone ? '<div class="inv-meta-item"><div class="inv-meta-label">Telepon</div><div class="inv-meta-val">' + escHtml(inv.customer_phone) + '</div></div>' : '';
            var noteHtml = inv.customer_note ? '<div class="inv-meta-item" style="grid-column:1/-1;"><div class="inv-meta-label">Catatan</div><div class="inv-meta-val">' + escHtml(inv.customer_note) + '</div></div>' : '';

            var bankHtml = '';
            if (BANK_NAME || BANK_ACCOUNT_NUMBER) {
                bankHtml = '<div class="inv-bank-box">' +
                    '<div class="inv-bank-label">Pembayaran via Transfer</div>' +
                    '<div class="inv-bank-row"><span class="inv-bank-bankname">' + escHtml(BANK_NAME) + '</span>' +
                    '<span class="inv-bank-num">' + escHtml(BANK_ACCOUNT_NUMBER) + '</span></div>' +
                    (BANK_ACCOUNT_HOLDER ? '<div class="inv-bank-holder">a.n. ' + escHtml(BANK_ACCOUNT_HOLDER) + '</div>' : '') +
                    '</div>';
            }
            var qrHtml = '';
            if (QR_URL) {
                qrHtml = '<div class="inv-qr-box"><div class="inv-qr-label">QRIS</div>' +
                    '<img src="' + escHtml(QR_URL) + '" alt="QR Pembayaran"></div>';
            }
            var payInfoHtml = (bankHtml || qrHtml) ? '<div class="inv-pay-row">' + bankHtml + qrHtml + '</div>' : '';

            document.getElementById('invPreviewContent').innerHTML =
                watermarkHtml +
                '<div class="inv-hdr-band"><div class="inv-hdr-content">' +
                '<div class="inv-hdr-logo">' + buildLogoHtml() + '</div>' +
                '<div class="inv-hdr-info"><div class="inv-hdr-name">' + escHtml(COMPANY_NAME) + '</div>' +
                '<div class="inv-hdr-tagline">' + escHtml(COMPANY_TAGLINE) + '</div>' +
                '<div class="inv-hdr-contacts">' + (COMPANY_ADDRESS ? '<span>' + escHtml(COMPANY_ADDRESS) + '</span>' : '') + '</div>' +
                '<div class="inv-hdr-contacts">' + (COMPANY_PHONE ? '<span>Tel: ' + escHtml(COMPANY_PHONE) + '</span>' : '') +
                (COMPANY_EMAIL ? '<span>' + escHtml(COMPANY_EMAIL) + '</span>' : '') + '</div>' +
                '</div></div></div>' +
                '<div class="inv-preview-inner">' +
                '<div class="inv-title-bar"><div><div class="inv-title-label">INVOICE</div>' +
                '<div class="inv-title-number">' + escHtml(inv.invoice_number) + '</div></div>' +
                '<div style="text-align:right;"><div style="font-size:10px;color:#9ca3af;font-weight:700;text-transform:uppercase;">Tanggal</div>' +
                '<div style="font-size:13px;font-weight:800;color:#1f2937;">' + (inv.created_at ? inv.created_at.substring(0,10) : '') + '</div></div></div>' +
                '<div class="inv-meta-grid"><div class="inv-meta-item"><div class="inv-meta-label">Pelanggan</div>' +
                '<div class="inv-meta-val">' + escHtml(inv.customer_name) + '</div></div>' + phoneHtml + noteHtml + '</div>' +
                '<table class="inv-items-tbl"><thead><tr><th style="width:36px;">#</th><th>Item</th>' +
                '<th style="text-align:center;width:50px;">Qty</th><th style="text-align:right;width:100px;">Harga</th>' +
                '<th style="text-align:right;width:100px;">Subtotal</th></tr></thead><tbody>' + itemsHtml + '</tbody></table>' +
                '<div class="inv-totals"><div class="inv-total-row"><span>Subtotal</span><span style="font-weight:700;">' + fmtRp(inv.subtotal) + '</span></div>' +
                discountHtml + taxHtml +
                '<div class="inv-total-row grand"><span>TOTAL</span><span>' + fmtRp(inv.total_amount) + '</span></div></div>' +
                payInfoHtml +
                statusLineHtml + '</div>' +
                '<div class="inv-footer-bar"><div class="thanks">Terima Kasih atas Kunjungan Anda!</div>' +
                '<div class="tagline">' + escHtml(COMPANY_NAME) + ' - ' + escHtml(COMPANY_TAGLINE) + '</div>' +
                '<div class="legal">Dokumen ini sah dan diproses secara elektronik</div></div>';

            document.getElementById('viewModal').classList.add('open');
        });
}

function printInvoice() {
    var content = document.getElementById('printArea').innerHTML;
    var w = window.open('', '_blank', 'width=794,height=1123');
    w.document.write('<!DOCTYPE html><html><head><title>Invoice</title><style>' +
        '@page { size: A4; margin: 0; }' +
        '* { margin:0;padding:0;box-sizing:border-box; } body { font-family:"Segoe UI",-apple-system,sans-serif;color:#1f2937;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;width:210mm;min-height:297mm;margin:0 auto;position:relative; }' +
        '.inv-preview { width:100%;min-height:297mm;display:flex;flex-direction:column;position:relative; } .inv-preview-inner { padding:22px 30px 24px;flex:1; }' +
        '.inv-hdr-band { background:linear-gradient(135deg,#0c4a6e,#075985,#0369a1)!important;padding:18px 30px;color:#fff; }' +
        '.inv-hdr-content { display:flex;align-items:center;gap:14px; } .inv-hdr-logo { width:48px;height:48px;border-radius:11px;overflow:hidden;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0; }' +
        '.inv-hdr-logo img { width:100%;height:100%;object-fit:cover;border-radius:9px; } .inv-hdr-logo .fallback-icon { font-size:20px;font-weight:900;color:#fff; }' +
        '.inv-hdr-name { font-size:17px;font-weight:800;text-shadow:0 1px 2px rgba(0,0,0,.25); } .inv-hdr-tagline { font-size:10.5px;color:rgba(255,255,255,.92);font-style:italic;text-shadow:0 1px 2px rgba(0,0,0,.2); } .inv-hdr-contacts { font-size:9.5px;font-weight:600;color:rgba(255,255,255,.9);margin-top:4px;display:flex;flex-wrap:wrap;gap:8px;text-shadow:0 1px 2px rgba(0,0,0,.2); }' +
        '.inv-title-bar { display:flex;justify-content:space-between;padding:14px 0 12px;margin-bottom:12px;border-bottom:2px solid #e0f2fe; }' +
        '.inv-title-label { font-size:20px;font-weight:900;color:#0369a1;letter-spacing:1.5px; } .inv-title-number { font-size:11.5px;font-weight:700;color:#075985;font-family:"Courier New",monospace;margin-top:3px; }' +
        '.inv-meta-grid { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f0f9ff;border-radius:8px;border:1px solid #bae6fd; }' +
        '.inv-meta-label { font-size:9px;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.6px;margin-bottom:2px; } .inv-meta-val { font-size:11.5px;font-weight:700;color:#1f2937; }' +
        '.inv-items-tbl { width:100%;border-collapse:collapse;font-size:11px;margin-bottom:14px; }' +
        '.inv-items-tbl thead th { background:linear-gradient(180deg,#0c4a6e,#075985)!important;color:#fff!important;padding:7px 10px;text-align:left;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px; }' +
        '.inv-items-tbl thead th:first-child { border-radius:6px 0 0 0; } .inv-items-tbl thead th:last-child { border-radius:0 6px 0 0;text-align:right; }' +
        '.inv-items-tbl tbody td { padding:7px 10px;border-bottom:1px solid #e0f2fe; } .inv-items-tbl tbody tr:nth-child(even) { background:#f0f9ff; }' +
        '.inv-items-tbl td:last-child { text-align:right;font-weight:700; } .inv-items-tbl td:nth-child(3) { text-align:center; } .inv-items-tbl td:nth-child(4) { text-align:right; }' +
        '.inv-totals { margin-left:auto;width:190px; } .inv-total-row { display:flex;justify-content:space-between;padding:4px 0;font-size:11.5px;color:#6b7280; }' +
        '.inv-total-row.grand { font-size:14.5px;font-weight:900;color:#0c4a6e;border-top:2px solid #0369a1;padding-top:8px;margin-top:5px; }' +
        '.inv-bank-box { clear:both;margin:12px 0 4px;padding:9px 12px;background:#f0f9ff!important;border:1.5px dashed #38bdf8;border-radius:8px; }' +
        '.inv-bank-label { font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#0369a1;margin-bottom:3px; }' +
        '.inv-bank-row { display:flex;align-items:baseline;gap:8px; } .inv-bank-bankname { font-size:11px;font-weight:800;color:#0c4a6e; }' +
        '.inv-bank-num { font-size:13px;font-weight:900;color:#075985;font-family:"Courier New",monospace;letter-spacing:.4px; }' +
        '.inv-bank-holder { font-size:10px;color:#6b7280;margin-top:1px; }' +
        '.inv-pay-row { display:flex;gap:10px;margin:12px 0 4px;flex-wrap:wrap;clear:both; } .inv-pay-row .inv-bank-box { margin:0;flex:1;min-width:150px; }' +
        '.inv-qr-box { padding:9px 12px;background:#f0f9ff!important;border:1.5px dashed #38bdf8;border-radius:8px;text-align:center;flex-shrink:0; }' +
        '.inv-qr-label { font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#0369a1;margin-bottom:5px; }' +
        '.inv-qr-box img { width:72px;height:72px;object-fit:contain;background:#fff;border-radius:6px;border:1px solid #e5e7eb;padding:3px; }' +
        '.inv-status-line { text-align:center;margin:10px 0 4px;font-size:10.5px;font-weight:700;letter-spacing:.3px; } .inv-status-line.paid { color:#059669; } .inv-status-line.unpaid { color:#dc2626; }' +
        '.inv-footer-bar { background:#f0f9ff!important;padding:11px 30px;text-align:center;border-top:1px solid #bae6fd;margin-top:auto; }' +
        '.inv-footer-bar .thanks { font-size:12px;font-weight:700;color:#075985; } .inv-footer-bar .tagline { font-size:9.5px;color:#9ca3af;font-style:italic; } .inv-footer-bar .legal { font-size:8.5px;color:#d1d5db;margin-top:4px; }' +
        '.inv-watermark { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:64px;font-weight:900;letter-spacing:10px;text-transform:uppercase;pointer-events:none;z-index:5;white-space:nowrap;border-width:6px;border-style:solid;padding:8px 32px;border-radius:16px; }' +
        '</style></head><body onload="window.print();window.close()">' + content + '</body></html>');
    w.document.close();
}

function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function deleteInvoice(id, isPaid) {
    var msg = isPaid
        ? 'Invoice ini SUDAH DIBAYAR. Menghapusnya akan menghapus catatan pemasukan terkait di bukukas dan mengembalikan saldo rekening. Lanjutkan?'
        : 'Hapus invoice ini?';
    if (!confirm(msg)) return;
    var fd = new FormData(); fd.append('invoice_id', id);
    fetch('?ajax=delete', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) { if (data.success) location.reload(); else alert(data.message); });
}

document.querySelectorAll('.modal-bg').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.classList.remove('open'); });
});
</script>

<?php include '../../includes/footer.php'; ?>
