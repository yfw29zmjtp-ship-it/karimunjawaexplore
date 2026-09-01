<?php

/**
 * Karimunjawa Explore (Sunsea) - Addon Domain Diagnostic & Setup
 * ------------------------------------------------------------------
 * One consolidated script to check/fix everything related to the
 * karimunjawaexplore.com addon domain, instead of jumping between
 * DNS Zone Editor / cPanel Domains / setup-addon-domain.php separately.
 *
 * Usage: log in as admin/developer, then open:
 *   https://adfsystem.online/diagnostic-karimunjawaexplore.php
 *
 * Checks performed:
 *   1. DNS resolution for karimunjawaexplore.com + www (A record)
 *   2. What HTTP/HTTPS actually returns right now (detects Rumahweb's
 *      "under construction" placeholder vs the real adf.system app)
 *   3. businesses.addon_domain row in the master DB (slug=sunsea)
 *   4. config/config.php landing-map wiring (static check)
 *   5. One-click button to (re)register addon_domain in the DB
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$DOMAIN = 'karimunjawaexplore.com';
$SLUG   = 'sunsea';
$EXPECTED_IP = null; // filled in below from adfsystem.online's own resolution

$actionMessage = '';
if (($_GET['action'] ?? '') === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        try {
            $pdo->query("SELECT addon_domain FROM businesses LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE businesses ADD COLUMN addon_domain VARCHAR(190) NULL UNIQUE AFTER slug");
        }
        $stmt = $pdo->prepare("SELECT * FROM businesses WHERE slug = ? LIMIT 1");
        $stmt->execute([$SLUG]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $actionMessage = "<p class='error'>✗ Business slug '{$SLUG}' tidak ditemukan di tabel businesses.</p>";
        } else {
            $upd = $pdo->prepare("UPDATE businesses SET addon_domain = ?, is_active = 1 WHERE id = ?");
            $upd->execute([$DOMAIN, $row['id']]);
            $actionMessage = "<p class='ok'>✓ addon_domain untuk '{$SLUG}' berhasil di-set ke {$DOMAIN}</p>";
        }
    } catch (Exception $e) {
        $actionMessage = "<p class='error'>✗ Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

function checkRow(string $label, bool $ok, string $detail = ''): string
{
    $icon = $ok ? "<span class='ok'>✓</span>" : "<span class='error'>✗</span>";
    $detailHtml = $detail !== '' ? " - " . htmlspecialchars($detail) : '';
    return "<p>{$icon} <strong>" . htmlspecialchars($label) . "</strong>{$detailHtml}</p>";
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Karimunjawa Explore - Domain Diagnostic</title>";
echo "<style>body{font-family:monospace;margin:20px;background:#0f172a;color:#e2e8f0;} .ok{color:#4ade80;} .error{color:#f87171;} .info{color:#60a5fa;} h1,h2{color:#fff;} .box{background:#1e293b;padding:16px 20px;border-radius:10px;margin-bottom:16px;} pre{background:#0f172a;padding:10px;border-radius:6px;overflow:auto;} button{background:#f97316;color:#fff;border:none;padding:10px 18px;border-radius:6px;cursor:pointer;font-family:monospace;font-size:14px;} a{color:#60a5fa;}</style>";
echo "</head><body>";
echo "<h1>🌊 Karimunjawa Explore - Domain Diagnostic</h1>";
echo "<p>Generated: " . date('Y-m-d H:i:s') . " | Domain: <strong>{$DOMAIN}</strong> | Business slug: <strong>{$SLUG}</strong></p>";

if ($actionMessage) {
    echo "<div class='box'>{$actionMessage}</div>";
}

// ------------------------------------------------------------------
// 1. DNS check
// ------------------------------------------------------------------
echo "<div class='box'><h2>1. DNS Resolution</h2>";
$refIp = gethostbyname('adfsystem.online');
echo checkRow('adfsystem.online resolves to', $refIp !== 'adfsystem.online', $refIp) . " <span class='info'>(IP referensi server)</span><br>";

$rootIp = gethostbyname($DOMAIN);
$rootOk = ($rootIp !== $DOMAIN) && ($rootIp === $refIp);
echo checkRow("{$DOMAIN} (root)", $rootOk, $rootIp === $DOMAIN ? 'Tidak resolve / NXDOMAIN' : $rootIp);

$wwwIp = gethostbyname('www.' . $DOMAIN);
$wwwOk = ($wwwIp !== 'www.' . $DOMAIN) && ($wwwIp === $refIp);
echo checkRow('www.' . $DOMAIN, $wwwOk, $wwwIp === 'www.' . $DOMAIN ? 'Tidak resolve / NXDOMAIN' : $wwwIp);
echo "</div>";

// ------------------------------------------------------------------
// 2. What does the domain actually serve right now?
// ------------------------------------------------------------------
echo "<div class='box'><h2>2. Isi Halaman Saat Ini (HTTP response)</h2>";
foreach (['https://' . $DOMAIN . '/', 'https://www.' . $DOMAIN . '/'] as $testUrl) {
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "<p><strong>" . htmlspecialchars($testUrl) . "</strong> -> HTTP {$code}</p>";
    if ($err) {
        echo checkRow('Koneksi', false, $err);
    } elseif ($body === false || $body === '') {
        echo checkRow('Response', false, 'Kosong / tidak ada balasan');
    } elseif (stripos($body, 'website') !== false && stripos($body, 'telah aktif') !== false) {
        echo checkRow('Isi halaman', false, 'Masih halaman "under construction" bawaan Rumahweb - document root BELUM di-share ke public_html');
    } elseif (stripos($body, 'NARAYANA_SESSION') !== false || stripos($body, 'ADF System') !== false || stripos($body, 'login') !== false) {
        echo checkRow('Isi halaman', true, 'Sudah menyajikan aplikasi adf.system (terdeteksi konten login/app)');
    } else {
        echo checkRow('Isi halaman', false, 'Konten tidak dikenali, cek manual');
        echo "<pre>" . htmlspecialchars(substr(strip_tags($body), 0, 300)) . "...</pre>";
    }
}
echo "</div>";

// ------------------------------------------------------------------
// 3. Database addon_domain check
// ------------------------------------------------------------------
echo "<div class='box'><h2>3. Database: businesses.addon_domain</h2>";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->prepare("SELECT slug, business_name, addon_domain, is_active FROM businesses WHERE slug = ?");
    $stmt->execute([$SLUG]);
    $bizRow = $stmt->fetch();

    if (!$bizRow) {
        echo checkRow("Business slug '{$SLUG}'", false, 'Tidak ditemukan di tabel businesses');
    } else {
        echo "<p><strong>Nama:</strong> " . htmlspecialchars($bizRow['business_name']) . "</p>";
        echo checkRow('addon_domain', $bizRow['addon_domain'] === $DOMAIN, $bizRow['addon_domain'] ?? '(kosong)');
        echo checkRow('is_active', (bool)$bizRow['is_active'], $bizRow['is_active'] ? 'Aktif' : 'Tidak aktif');
    }
} catch (Exception $e) {
    echo checkRow('Koneksi database', false, $e->getMessage());
}
echo "<form method='post' action='?action=register'><button type='submit'>🔄 (Re)Register addon_domain = {$DOMAIN} untuk slug={$SLUG}</button></form>";
echo "</div>";

// ------------------------------------------------------------------
// 4. Code wiring check (static, always true if this file itself is deployed)
// ------------------------------------------------------------------
echo "<div class='box'><h2>4. Kode Routing (config/config.php)</h2>";
$configSrc = file_get_contents(__DIR__ . '/config/config.php');
$hasLandingMap = strpos($configSrc, "'sunsea'        => '/login.php?biz=sunsea'") !== false
    || strpos($configSrc, "'sunsea' => '/login.php?biz=sunsea'") !== false;
echo checkRow("Landing map 'sunsea' => /login.php?biz=sunsea", $hasLandingMap);
echo checkRow('MASTER_DOMAIN constant', defined('MASTER_DOMAIN'), defined('MASTER_DOMAIN') ? MASTER_DOMAIN : '');
echo "</div>";

// ------------------------------------------------------------------
// 5. Summary / next steps
// ------------------------------------------------------------------
echo "<div class='box'><h2>5. Kesimpulan</h2><ul>";
if (!$rootOk || !$wwwOk) {
    echo "<li class='error'>DNS belum resolve ke server yang benar - cek A record di registrar domain (bukan di cPanel Zone Editor kalau domain masih pakai nameserver asal).</li>";
}
echo "<li>Kalau bagian 2 masih bilang \"under construction\": buka cPanel &rarr; <strong>Domains</strong> &rarr; cari {$DOMAIN} &rarr; pastikan Document Root ter-share dengan adfsystem.online (bukan folder baru). Kalau addon domain sudah kadung dibuat salah, hapus dulu lalu buat ulang dengan checkbox share document root dicentang.</li>";
echo "<li>Kalau bagian 3 addon_domain belum sesuai, klik tombol Register di atas.</li>";
echo "<li>Jangan lupa aktifkan SSL (Let's Encrypt) untuk {$DOMAIN} setelah document root benar.</li>";
echo "</ul></div>";

echo "<p><a href='" . htmlspecialchars($_SERVER['PHP_SELF']) . "'>🔄 Refresh diagnostic</a></p>";
echo "</body></html>";
