<?php

/** One-time cleanup: remove bad transport_items rows named after trip categories (Open Trip/Private Trip) that wrongly show up as Tiket Kapal options. Safe to delete this file after running once. */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

$badNames = ['Open Trip', 'Private Trip'];
$placeholders = implode(',', array_fill(0, count($badNames), '?'));

$stmt = $pdo->prepare("SELECT id, transport_code, transport_type, name, price_cost, price_sell FROM transport_items WHERE name IN ($placeholders)");
$stmt->execute($badNames);
$found = $stmt->fetchAll();

$deleted = [];
if (isset($_GET['confirm']) && $_GET['confirm'] === '1' && $found) {
    $del = $pdo->prepare("DELETE FROM transport_items WHERE name IN ($placeholders)");
    $del->execute($badNames);
    $deleted = $found;
    $found = [];
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Cleanup: Tiket Kapal salah kategori</h2>";

if ($deleted) {
    echo "<p style='color:green;'>Berhasil dihapus " . count($deleted) . " data:</p><ul>";
    foreach ($deleted as $r) {
        echo "<li>" . htmlspecialchars($r['name']) . " (" . htmlspecialchars($r['transport_code']) . ")</li>";
    }
    echo "</ul><p>Silakan kembali ke <a href='packages.php'>Paket Wisata</a> dan cek dropdown Tiket Kapal. File ini sudah boleh dihapus dari server.</p>";
} elseif ($found) {
    echo "<p>Ditemukan " . count($found) . " data transport_items yang bernama kategori trip (bukan tiket kapal asli):</p><ul>";
    foreach ($found as $r) {
        echo "<li>#" . $r['id'] . " - " . htmlspecialchars($r['name']) . " (" . htmlspecialchars($r['transport_type']) . ") - Modal Rp" . number_format((float)$r['price_cost'], 0, ',', '.') . " / Jual Rp" . number_format((float)$r['price_sell'], 0, ',', '.') . "</li>";
    }
    echo "</ul><p><a href='?confirm=1' style='padding:8px 16px;background:#dc2626;color:#fff;border-radius:6px;text-decoration:none;'>Hapus Sekarang</a></p>";
} else {
    echo "<p>Tidak ditemukan data 'Open Trip' / 'Private Trip' di tabel transport_items. Sudah bersih.</p>";
}
