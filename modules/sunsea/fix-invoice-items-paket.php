<?php

/**
 * One-time cleanup: hapus baris rincian layanan paket (harga Rp 0) yang ikut
 * tercetak di invoice untuk booking mode 'paket'. Baris "Paket: ..." dan
 * baris fasilitas/manual tambahan (harga > 0) tetap dipertahankan.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo = getSunseaConnection();

$confirm = isset($_GET['confirm']);

$rows = $pdo->query("
    SELECT ii.id, ii.invoice_id, i.invoice_no, ii.description, ii.unit_price, ii.subtotal
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    JOIN booking_orders bo ON bo.booking_no = TRIM(REPLACE(i.internal_notes, 'Generated from Reservasi: ', ''))
    WHERE bo.booking_mode = 'paket'
      AND ii.unit_price = 0
      AND ii.subtotal = 0
      AND ii.description NOT LIKE 'Paket:%'
    ORDER BY ii.invoice_id, ii.sort_order
")->fetchAll(PDO::FETCH_ASSOC);

echo '<h3>Fix Invoice Items (Rincian Paket Rp 0)</h3>';

if (empty($rows)) {
    echo '<p>Tidak ada baris invoice bermasalah ditemukan. Aman.</p>';
    exit;
}

echo '<p>Ditemukan ' . count($rows) . ' baris invoice yang seharusnya tidak tampil (rincian modal internal paket):</p><ul>';
foreach ($rows as $r) {
    echo '<li>Invoice ' . htmlspecialchars($r['invoice_no']) . ' - ' . htmlspecialchars($r['description']) . '</li>';
}
echo '</ul>';

if (!$confirm) {
    echo '<p><a href="?confirm=1">Hapus Sekarang</a></p>';
    exit;
}

$del = $pdo->prepare("DELETE FROM invoice_items WHERE id=?");
foreach ($rows as $r) {
    $del->execute([$r['id']]);
}

echo '<p>Selesai. ' . count($rows) . ' baris berhasil dihapus.</p>';
