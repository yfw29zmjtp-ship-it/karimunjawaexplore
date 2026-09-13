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

// Step 2: cancel duplicate invoice 015 (same trip/booking, wrongly paid a second time
// with no transfer reference) — soft-cancel: remove its payment + cash_book entry, keep
// the invoice row itself for audit trail with status='cancelled'.
$dupInvId = 15;
$dup = $pdo->prepare("SELECT id, invoice_no, paid_amount, status FROM invoices WHERE id=?");
$dup->execute([$dupInvId]);
$dupInv = $dup->fetch(PDO::FETCH_ASSOC);
echo "\nDuplicate invoice before: " . print_r($dupInv, true) . "\n";

if (!empty($_GET['apply_cancel_dup'])) {
    $pdo->prepare("DELETE FROM cash_book WHERE invoice_id=?")->execute([$dupInvId]);
    $pdo->prepare("DELETE FROM payments WHERE invoice_id=?")->execute([$dupInvId]);
    $pdo->prepare("UPDATE invoices SET paid_amount=0, remaining_amount=total_amount, status='cancelled' WHERE id=?")->execute([$dupInvId]);
    echo "Cancelled duplicate invoice $dupInvId (payment + cash_book entry removed).\n";
} else {
    echo "Dry run only. Add ?apply_cancel_dup=1 to the URL to actually cancel invoice $dupInvId.\n";
}

