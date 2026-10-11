<?php

/**
 * Alat admin sekali pakai: cari & hapus permintaan penawaran/booking SPAM dari form website
 * (tanggal ngawur seperti 1997, link/promosi jasa di nama atau pesan).
 * Akses: admin / owner / developer. Aman secara bawaan: hanya menampilkan daftar; data baru dihapus
 * setelah dicentang & dikonfirmasi lewat POST. Hanya menghapus penawaran berstatus draft buatan 'website';
 * pelanggan ikut dihapus hanya bila tidak punya penawaran lain, booking, invoice, atau catatan kas.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'owner', 'developer'], true)) {
    http_response_code(403);
    die('Access denied.');
}

$pdo = getSunseaConnection();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/** Alasan sebuah penawaran dianggap spam (kosong = bukan spam). */
function spamReason(array $r): string
{
    $why = [];
    $d = (string)($r['trip_date'] ?? '');
    if ($d === '' || $d < '2020-01-01') $why[] = 'tanggal trip tidak wajar (' . ($d ?: 'kosong') . ')';
    if ($d !== '' && $d > date('Y-m-d', strtotime('+3 years'))) $why[] = 'tanggal terlalu jauh (' . $d . ')';
    $name = (string)($r['customer_name'] ?? '');
    if (preg_match('/https?:|www\.|\.(com|net|org|ru|xyz|top|info|online)\b/i', $name) || preg_match('/\d{3,}/', $name)) $why[] = 'nama berisi link/angka';
    if (preg_match('/https?:\/\/|www\.|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', (string)($r['notes'] ?? ''))) $why[] = 'catatan berisi link/email';
    return implode('; ', $why);
}

$rows = $pdo->query(
    "SELECT q.id, q.quotation_no, q.trip_date, q.status, q.notes, q.created_at, c.id AS customer_id, c.name AS customer_name, c.phone
     FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id
     WHERE q.created_by = 'website' AND (q.status IS NULL OR q.status = 'draft')
     ORDER BY q.id DESC LIMIT 1000"
)->fetchAll(PDO::FETCH_ASSOC);

$spam = [];
foreach ($rows as $r) {
    $reason = spamReason($r);
    if ($reason !== '') $spam[$r['id']] = $r + ['reason' => $reason];
}

$deletedQ = 0;
$deletedC = 0;
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => isset($spam[$i])));
    foreach ($ids as $qid) {
        $custId = (int)($spam[$qid]['customer_id'] ?? 0);
        $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ?")->execute([$qid]);
        $pdo->prepare("DELETE FROM quotations WHERE id = ? AND created_by = 'website'")->execute([$qid]);
        $deletedQ++;
        if ($custId <= 0) continue;
        // Pelanggan dihapus hanya bila tidak dipakai di tempat lain
        $used = 0;
        foreach (['quotations', 'booking_orders', 'invoices', 'cash_book'] as $tbl) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$tbl` WHERE customer_id = ?");
                $st->execute([$custId]);
                $used += (int)$st->fetchColumn();
            } catch (Throwable $e) {
                // tabel belum ada
            }
        }
        if ($used === 0) {
            $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$custId]);
            $deletedC++;
        }
    }
    $done = true;
    // daftar dimuat ulang tanpa baris yang sudah dihapus
    $spam = array_filter($spam, fn($r) => !in_array((int)$r['id'], $ids, true));
}

// Percobaan yang ditolak pengaman form (untuk meninjau apakah ada pelanggan asli yang ikut terblokir)
$blocked = [];
try {
    $blocked = $pdo->query("SELECT created_at, form, reason, name, phone, snippet, ip FROM web_form_blocked ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // tabel dibuat otomatis saat form pertama kali dikirim
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bersihkan Spam Penawaran</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; color: #1f2937; background: #f8fafc; }
        h1 { font-size: 1.2rem; } h2 { font-size: 1rem; margin-top: 28px; }
        table { border-collapse: collapse; width: 100%; background: #fff; font-size: 13px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; }
        .ok { background: #dcfce7; padding: 10px 14px; border-radius: 8px; margin: 12px 0; }
        button { background: #b91c1c; color: #fff; border: 0; padding: 9px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; }
        small { color: #64748b; }
    </style>
</head>

<body>
    <h1>Bersihkan spam dari form website</h1>
    <?php if ($done): ?><div class="ok">Selesai: <?php echo (int)$deletedQ; ?> penawaran dan <?php echo (int)$deletedC; ?> pelanggan spam dihapus.</div><?php endif; ?>

    <?php if (!$spam): ?>
        <p>Tidak ada penawaran spam yang terdeteksi.</p>
    <?php else: ?>
        <p>Ditemukan <b><?php echo count($spam); ?></b> penawaran mencurigakan (buatan website, masih draft). Hapus yang dicentang:</p>
        <form method="post" onsubmit="return confirm('Hapus penawaran yang dicentang? Tidak bisa dibatalkan.')">
            <input type="hidden" name="confirm" value="yes">
            <table>
                <tr><th></th><th>No.</th><th>Dibuat</th><th>Nama</th><th>HP</th><th>Tanggal trip</th><th>Alasan</th></tr>
                <?php foreach ($spam as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>" checked></td>
                        <td><?php echo $h($r['quotation_no']); ?></td>
                        <td><?php echo $h($r['created_at']); ?></td>
                        <td><?php echo $h($r['customer_name']); ?></td>
                        <td><?php echo $h($r['phone']); ?></td>
                        <td><?php echo $h($r['trip_date']); ?></td>
                        <td><?php echo $h($r['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p><button type="submit">Hapus yang dicentang</button></p>
        </form>
    <?php endif; ?>

    <h2>50 percobaan terakhir yang ditolak pengaman form</h2>
    <small>Honeypot / terlalu cepat / rate limit = bot (dijawab seolah sukses). Alasan lain = isi form tidak valid. Bila ada pelanggan asli di sini, longgarkan aturan di includes/website-spam-guard.php.</small>
    <?php if (!$blocked): ?>
        <p>Belum ada.</p>
    <?php else: ?>
        <table>
            <tr><th>Waktu</th><th>Form</th><th>Alasan</th><th>Nama</th><th>HP</th><th>Isi / tanggal</th><th>IP</th></tr>
            <?php foreach ($blocked as $b): ?>
                <tr>
                    <td><?php echo $h($b['created_at']); ?></td><td><?php echo $h($b['form']); ?></td><td><?php echo $h($b['reason']); ?></td>
                    <td><?php echo $h($b['name']); ?></td><td><?php echo $h($b['phone']); ?></td><td><?php echo $h($b['snippet']); ?></td><td><?php echo $h($b['ip']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</body>

</html>
