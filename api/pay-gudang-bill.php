<?php
// Pays the CURRENT active business' own monthly Gudang Nasita bill from the Tagihan menu's
// "Gudang" tab. Reuses gudangTagihanPayMonthlyBill() (includes/procurement_functions.php) —
// the exact same tested logic used by modules/procurement/gudang-tagihan.php's "Bayar Tagihan"
// button, so the recorded cash_book entries stay 100% consistent between both entry points.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$auth = new Auth();
$auth->requireLogin();

$slug = (string)($_SESSION['active_business_id'] ?? '');
$allowedSlugs = ['bens-cafe', 'eaat-meet', 'narayana-hotel'];
if (!in_array($slug, $allowedSlugs, true)) {
    echo json_encode(['success' => false, 'message' => 'Tagihan Gudang tidak tersedia untuk bisnis ini']);
    exit;
}

$month = (string)($_POST['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$currentUser = $auth->getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);

try {
    $message = gudangTagihanPayMonthlyBill($slug, $month, $userId);
    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    error_log('pay-gudang-bill: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
