<?php
/**
 * One-off: backfill cash_book entry for an invoice whose paid_amount/payments
 * total is higher than what's already recorded in cash_book (e.g. DP diinput
 * manual di kolom paid_amount, bukan lewat form "Tambah Pembayaran" di invoices.php,
 * jadi tidak otomatis kecatat di Finance).
 *
 * Usage: buka di browser
 *   fix-missing-cashbook-invoice.php?invoice_no=SS-INV-2026-005
 *   fix-missing-cashbook-invoice.php?invoice_no=SS-INV-2026-005&apply=1   (untuk benar2 insert)
 * Tanpa &apply=1 hanya menampilkan diagnosa (dry-run), aman dijalankan berkali-kali.
 * HAPUS file ini dari server setelah selesai dipakai.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';

$pdo = getSunseaConnection();

$invoiceNo = trim($_GET['invoice_no'] ?? '');
$apply     = isset($_GET['apply']) && $_GET['apply'] === '1';

header('Content-Type: text/plain; charset=utf-8');

if ($invoiceNo === '') {
    echo "Kasih parameter ?invoice_no=SS-INV-XXXX-XXX\n";
    exit;
}

$inv = $pdo->prepare("SELECT i.id, i.invoice_no, i.total_amount, i.paid_amount, i.customer_id, c.name AS customer_name
    FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.invoice_no = ?");
$inv->execute([$invoiceNo]);
$invoice = $inv->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    echo "Invoice '$invoiceNo' tidak ditemukan.\n";
    exit;
}

echo "Invoice: {$invoice['invoice_no']} | Customer: {$invoice['customer_name']} | Total: {$invoice['total_amount']} | Paid (invoices.paid_amount): {$invoice['paid_amount']}\n";

$payStmt = $pdo->prepare("SELECT id, payment_date, amount, method, reference FROM payments WHERE invoice_id = ? ORDER BY payment_date, id");
$payStmt->execute([$invoice['id']]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentsTotal = 0;
echo "\n-- payments table --\n";
foreach ($payments as $p) {
    echo "  #{$p['id']} {$p['payment_date']} Rp{$p['amount']} ({$p['method']}) ref={$p['reference']}\n";
    $paymentsTotal += (float)$p['amount'];
}
echo "  Total payments: $paymentsTotal\n";

$cbStmt = $pdo->prepare("SELECT id, transaction_date, amount, description FROM cash_book WHERE invoice_id = ? AND type='income' ORDER BY transaction_date, id");
$cbStmt->execute([$invoice['id']]);
$cashbookRows = $cbStmt->fetchAll(PDO::FETCH_ASSOC);
$cashbookTotal = 0;
echo "\n-- cash_book rows linked to this invoice_id --\n";
foreach ($cashbookRows as $r) {
    echo "  #{$r['id']} {$r['transaction_date']} Rp{$r['amount']} {$r['description']}\n";
    $cashbookTotal += (float)$r['amount'];
}
echo "  Total cash_book: $cashbookTotal\n";

$paidAmount = (float)$invoice['paid_amount'];
$diff = round($paidAmount - $cashbookTotal, 2);

echo "\ninvoices.paid_amount ($paidAmount) - cash_book total ($cashbookTotal) = selisih $diff\n";

if ($diff <= 0) {
    echo "\nTidak ada selisih (atau cash_book sudah >= paid_amount). Tidak perlu backfill.\n";
    exit;
}

$lastPaymentDate = $payments ? end($payments)['payment_date'] : date('Y-m-d');

echo "\nAkan insert 1 baris cash_book baru: tanggal=$lastPaymentDate, amount=$diff, invoice_id={$invoice['id']}, customer_id={$invoice['customer_id']}\n";

if (!$apply) {
    echo "\n(DRY RUN — tambahkan &apply=1 di URL untuk benar-benar insert)\n";
    exit;
}

$pdo->prepare("
    INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, invoice_id, customer_id, created_by)
    VALUES (?, 'income', 'Penerimaan Trip', ?, ?, ?, ?, ?, ?)
")->execute([
    $lastPaymentDate,
    "Pembayaran Invoice {$invoice['invoice_no']} — {$invoice['customer_name']} (backfill selisih)",
    $diff,
    $invoice['invoice_no'],
    $invoice['id'],
    $invoice['customer_id'],
    'system-backfill',
]);

echo "\nBERHASIL: baris cash_book baru sudah diinsert.\n";
