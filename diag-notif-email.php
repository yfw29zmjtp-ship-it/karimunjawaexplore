<?php

/**
 * One-off diagnostic: checks why "Notifikasi Email Booking Baru" isn't reaching admins.
 * Access with ?token=cekemail2026 (avoid public exposure). Safe to delete after use.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/modules/sunsea/db-helper.php';

if (($_GET['token'] ?? '') !== 'cekemail2026') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = getSunseaConnection();

echo "=== 1. Alamat Email Admin (notif_admin_emails) ===\n";
$emails = sunseaNotifAdminEmails($pdo);
if (!$emails) {
    echo "KOSONG! Tidak ada alamat email admin yang valid tersimpan.\n";
} else {
    foreach ($emails as $e) {
        echo "- {$e}\n";
    }
}

echo "\n=== 2. Konfigurasi SMTP (Email Kantor - Pengaturan Email) ===\n";
require_once __DIR__ . '/includes/EmailHelper.php';
require_once __DIR__ . '/includes/SmtpMailer.php';
$db = Database::getInstance();
$emailConfig = EmailHelper::resolveConfig($db);
if ($emailConfig === null) {
    echo "BELUM DIKONFIGURASI! Kolom 'email_imap_host' di tabel settings masih kosong.\n";
    echo "-> Buka menu Email Kantor -> Pengaturan Email, isi host/port/user/password SMTP & IMAP dulu.\n";
} else {
    echo "host          : {$emailConfig['host']}\n";
    echo "user          : {$emailConfig['user']}\n";
    echo "pass terisi?  : " . ($emailConfig['pass'] !== '' ? 'YA' : 'TIDAK (kosong!)') . "\n";
    echo "smtp_port     : {$emailConfig['smtp_port']}\n";
    echo "smtp_enc      : {$emailConfig['smtp_encryption']}\n";
}

echo "\n=== 3. Percobaan kirim email tes langsung ke alamat admin ===\n";
if ($emailConfig === null) {
    echo "Dilewati (SMTP belum dikonfigurasi, lihat poin 2).\n";
} elseif (!$emails) {
    echo "Dilewati (tidak ada alamat admin, lihat poin 1).\n";
} else {
    try {
        $mailer = new SmtpMailer(
            $emailConfig['host'],
            (int)($emailConfig['smtp_port'] ?? 465),
            $emailConfig['smtp_encryption'] ?? 'ssl',
            $emailConfig['user'],
            $emailConfig['pass']
        );
        foreach ($emails as $to) {
            try {
                $mailer->send($to, 'Tes Notifikasi Booking - ' . date('H:i:s'), '<p>Ini email tes diagnostik notifikasi booking.</p>', 'Karimunjawa Explore');
                echo "OK  -> berhasil kirim ke {$to}\n";
            } catch (Throwable $e) {
                echo "GAGAL -> {$to} : " . $e->getMessage() . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "GAGAL membuat SmtpMailer: " . $e->getMessage() . "\n";
    }
}

echo "\nSelesai. Hapus file ini (diag-notif-email.php) setelah selesai dicek.\n";

echo "\n=== 4. Panggil FUNGSI ASLI sunseaNotifyAdminNewQuotation() pada penawaran website terbaru ===\n";
$latestId = (int)($pdo->query("SELECT id FROM quotations WHERE created_by = 'website' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($latestId <= 0) {
    echo "Tidak ada penawaran created_by='website' ditemukan.\n";
} else {
    echo "Memanggil sunseaNotifyAdminNewQuotation() untuk quotation id={$latestId} ...\n";
    try {
        sunseaNotifyAdminNewQuotation($pdo, $latestId);
        echo "Selesai dipanggil tanpa exception yang lolos ke sini (cek Inbox/Spam sekarang,";
        echo " dan cek juga poin 5 di bawah untuk error internal yang mungkin di-log).\n";
    } catch (Throwable $e) {
        echo "EXCEPTION LOLOS: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 5. Isi error_log terbaru (kalau file ditemukan & bisa dibaca) ===\n";
$logCandidates = [
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    dirname(__DIR__, 2) . '/logs/' . ($_SERVER['HTTP_HOST'] ?? '') . '.error.log',
];
$foundLog = false;
foreach ($logCandidates as $logPath) {
    if (is_readable($logPath)) {
        $foundLog = true;
        echo "-- {$logPath} (50 baris terakhir) --\n";
        $lines = file($logPath);
        echo implode('', array_slice($lines, -50));
    }
}
if (!$foundLog) {
    echo "Tidak ditemukan file error_log yang bisa dibaca dari lokasi umum. Cek manual lewat\n";
    echo "cPanel -> Metrics -> Errors, filter kata 'sunseaNotifyAdminNewQuotation'.\n";
}
