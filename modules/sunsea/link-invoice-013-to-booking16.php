<?php
// One-off: link invoice 013 (Bhisma) properly to booking 016 via internal_notes,
// so ensureInvoiceFromBooking() recognizes it and won't auto-generate another duplicate invoice.
// Delete this file after running once.
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

header('Content-Type: text/plain');

$invId = 13;
$bookingId = 16;

$stmt = $pdo->prepare("SELECT id, invoice_no, internal_notes FROM invoices WHERE id=?");
$stmt->execute([$invId]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    die("Invoice $invId not found.\n");
}
echo "Before: " . print_r($inv, true) . "\n";

if (!empty($_GET['apply'])) {
    $pdo->prepare("UPDATE invoices SET internal_notes=? WHERE id=?")->execute(["booking_id:$bookingId", $invId]);
    echo "Updated internal_notes to booking_id:$bookingId\n";
} else {
    echo "Dry run only. Add ?apply=1 to the URL to actually update.\n";
}
