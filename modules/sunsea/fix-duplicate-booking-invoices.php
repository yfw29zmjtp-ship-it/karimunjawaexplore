<?php

/**
 * One-off: cari booking yang punya LEBIH DARI 1 invoice tertaut (duplikat, biasanya
 * karena invoice lama pola 'Generated from Reservasi: <no>' tidak ke-detect saat
 * ensureInvoiceFromBooking() membuat invoice baru pola 'booking_id:<id>').
 * Invoice duplikat yang paid_amount=0 akan di-set status='cancelled' (soft, tidak
 * dihapus) supaya tidak muncul lagi di daftar invoice owner/system, invoice yang
 * sudah ada pembayaran (DP/Lunas) dipertahankan.
 *
 * Akses: harus login sebagai admin/owner/developer. Jalankan dengan ?apply=1 untuk
 * benar-benar mengubah data, tanpa parameter itu hanya preview (dry-run).
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once 'db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: owner-login.php');
    exit;
}
$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner', 'admin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = getSunseaConnection();
$apply = isset($_GET['apply']) && $_GET['apply'] == '1';

header('Content-Type: text/plain; charset=utf-8');
echo $apply ? "MODE: APPLY (data akan diubah)\n\n" : "MODE: PREVIEW (dry-run, tambahkan ?apply=1 untuk eksekusi)\n\n";

$bookings = $pdo->query("SELECT id, booking_no FROM booking_orders")->fetchAll(PDO::FETCH_ASSOC);

$totalDuplicateGroups = 0;
$totalCancelled = 0;

foreach ($bookings as $b) {
    $stmt = $pdo->prepare("SELECT id, invoice_no, status, total_amount, paid_amount FROM invoices WHERE internal_notes=? OR internal_notes=? ORDER BY id ASC");
    $stmt->execute(['booking_id:' . $b['id'], 'Generated from Reservasi: ' . $b['booking_no']]);
    $invs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hanya proses grup yang benar-benar duplikat (>1 invoice aktif, belum cancelled).
    $active = array_filter($invs, fn($i) => $i['status'] !== 'cancelled');
    if (count($active) <= 1) {
        continue;
    }

    $totalDuplicateGroups++;
    echo "Booking {$b['booking_no']} (id={$b['id']}) punya " . count($active) . " invoice aktif:\n";

    // Prioritaskan menyimpan invoice dengan paid_amount tertinggi (sudah ada DP/lunas),
    // kalau seri gunakan yang id paling kecil (paling lama/pertama dibuat).
    usort($active, function ($a, $c) {
        if ((float)$a['paid_amount'] !== (float)$c['paid_amount']) {
            return (float)$c['paid_amount'] <=> (float)$a['paid_amount'];
        }
        return $a['id'] <=> $c['id'];
    });
    $active = array_values($active);
    $keep = $active[0];
    echo "  - SIMPAN: {$keep['invoice_no']} (paid={$keep['paid_amount']}, total={$keep['total_amount']})\n";

    for ($i = 1; $i < count($active); $i++) {
        $dup = $active[$i];
        if ((float)$dup['paid_amount'] > 0) {
            echo "  - LEWATI (punya pembayaran, tidak aman dibatalkan otomatis): {$dup['invoice_no']} (paid={$dup['paid_amount']})\n";
            continue;
        }
        echo "  - BATALKAN (duplikat, belum ada pembayaran): {$dup['invoice_no']}\n";
        if ($apply) {
            $upd = $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=? AND paid_amount=0");
            $upd->execute([$dup['id']]);
            $totalCancelled++;
        }
    }
    echo "\n";
}

echo "Selesai. Grup duplikat ditemukan: {$totalDuplicateGroups}. Invoice dibatalkan: {$totalCancelled}.\n";
