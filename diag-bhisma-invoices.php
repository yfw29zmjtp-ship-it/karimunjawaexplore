<?php
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

header('Content-Type: text/plain');

$invIds = [11, 13, 15];
foreach ($invIds as $id) {
    $stmt = $pdo->prepare("SELECT id, invoice_no, customer_id, internal_notes, total_amount, status, created_at FROM invoices WHERE id=?");
    $stmt->execute([$id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Invoice #$id:\n";
    print_r($inv);
    echo "\n";
}

echo "--- Bookings for Bhisma ---\n";
$b = $pdo->query("SELECT bo.id, bo.booking_no, bo.booking_mode, bo.status, bo.notes, bo.created_at FROM booking_orders bo JOIN customers c ON c.id=bo.customer_id WHERE c.name LIKE '%Bhisma%'");
print_r($b->fetchAll(PDO::FETCH_ASSOC));
