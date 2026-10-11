<?php

/**
 * Anti-spam untuk form publik website (Minta Penawaran di Beranda & Kontak/Booking).
 *
 * Lapisan yang dipakai (semuanya di server, tidak bisa dilewati dengan mengubah HTML):
 *  1. Honeypot  : kolom tersembunyi "we_hp" (nama netral agar tidak diisi otomatis oleh browser) — manusia tidak melihatnya, bot mengisinya.
 *  2. Jebakan waktu : token bertanda tangan yang dibuat saat halaman dimuat; form yang dikirim < 3 detik
 *                     atau tanpa token valid (bot yang menembak langsung ke URL) ditolak.
 *  3. Rate limit : maks. beberapa kiriman per jam / per hari per alamat IP, dan nomor HP yang sama tidak boleh
 *                  mengirim berulang dalam 10 menit.
 *  4. Validasi isi : tanggal wajar (hari ini s/d 2 tahun ke depan, bukan 1997), nama huruf saja, HP 9–15 digit,
 *                    pesan tanpa link / email / promosi jasa.
 *
 * Kiriman bot (honeypot / waktu / rate limit) dijawab seolah-olah sukses supaya bot tidak belajar,
 * tetapi tidak disimpan & tidak mengirim notifikasi. Kiriman yang gagal validasi isi mendapat pesan
 * kesalahan biasa. Semua penolakan dicatat di tabel web_form_blocked untuk ditinjau.
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

const WE_GUARD_MIN_SECONDS = 3;       // form terlalu cepat dikirim = bot
const WE_GUARD_MAX_SECONDS = 86400;   // token kedaluwarsa (halaman terbuka > 24 jam)
const WE_GUARD_PER_HOUR = 5;          // kiriman per IP per jam per form
const WE_GUARD_PER_DAY = 12;          // kiriman per IP per hari (semua form)
const WE_GUARD_MAX_YEARS_AHEAD = 2;   // tanggal trip paling jauh

function weGuardSecret(): string
{
    return hash('sha256', (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|we-form-guard');
}

/** Token waktu bertanda tangan: "<timestamp>.<hmac>". */
function weGuardToken(?int $ts = null): string
{
    $ts = $ts ?? time();
    return $ts . '.' . substr(hash_hmac('sha256', (string)$ts, weGuardSecret()), 0, 24);
}

/** HTML kolom tersembunyi (honeypot + token waktu) — taruh tepat di dalam tag <form>. */
function weSpamFields(): string
{
    return '<div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;opacity:0;">'
        . '<label>Jangan diisi<input type="text" name="we_hp" value="" tabindex="-1" autocomplete="off"></label></div>'
        . '<input type="hidden" name="we_ts" value="' . htmlspecialchars(weGuardToken(), ENT_QUOTES) . '">';
}

function weGuardIp(): string
{
    // REMOTE_ADDR saja: header X-Forwarded-For bisa dipalsukan oleh penyerang
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function weGuardEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_form_hits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form VARCHAR(30) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        phone VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip_time (ip, created_at),
        KEY idx_phone_time (phone, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS web_form_blocked (
        id INT AUTO_INCREMENT PRIMARY KEY,
        form VARCHAR(30) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        reason VARCHAR(60) NOT NULL,
        name VARCHAR(120) NULL,
        phone VARCHAR(40) NULL,
        snippet VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Nomor HP → hanya digit; null bila tidak masuk akal. */
function weGuardPhoneDigits(string $phone): ?string
{
    $d = preg_replace('/\D+/', '', $phone);
    if (strlen($d) < 9 || strlen($d) > 15) return null;
    if (preg_match('/^(\d)\1+$/', $d)) return null; // 0000000000, 1111111111
    return $d;
}

/** Tanggal trip: format Y-m-d yang valid, dari hari ini sampai N tahun ke depan. */
function weGuardTripDateOk(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return false;
    }
    $today = date('Y-m-d');
    $max = date('Y-m-d', strtotime('+' . WE_GUARD_MAX_YEARS_AHEAD . ' years'));
    return $date >= $today && $date <= $max;
}

/** Nama orang: huruf, spasi, titik, apostrof, strip; tanpa angka/link. */
function weGuardNameOk(string $name): bool
{
    $len = mb_strlen($name);
    if ($len < 2 || $len > 60) return false;
    if (!preg_match('/^[\p{L}\p{M}\x27\x{2019}\.\-\s]+$/u', $name)) return false;
    if (preg_match('/https?:|www\.|\.(com|net|org|ru|xyz|top|info|online|id)\b/i', $name)) return false;
    if (preg_match('/(.)\1{4,}/u', $name)) return false; // "aaaaaa"
    return true;
}

/**
 * Skor pesan promosi/jasa: link, email, nomor HP, dan kata khas penawaran jasa. >= 2 dianggap spam.
 * (Satu kata saja, mis. "jasa dokumentasi", tidak cukup — hanya kombinasi.)
 */
function weGuardMessageScore(string $text): int
{
    $t = mb_strtolower($text);
    $score = 0;
    if (preg_match('/https?:\/\/|www\.|\b[a-z0-9-]+\.(com|net|org|ru|xyz|top|info|biz|co\.id|my\.id|id|site|online|shop|store)\b/i', $t)) $score += 2;
    if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $t)) $score += 2;
    if (preg_match('/(?:\+?62|0)8\d[\d\s\-]{7,}/', $text)) $score += 1;   // nomor HP di dalam pesan
    $kw = ['jasa', 'layanan kami', 'kami menawarkan', 'menawarkan', 'penawaran kerjasama', 'kerjasama', 'kerja sama', 'promosi', 'iklan',
        'seo', 'backlink', 'digital marketing', 'followers', 'pembuatan website', 'website anda', 'toko online', 'bisnis online', 'investasi',
        'pinjaman', 'kredit', 'judi', 'slot', 'togel', 'crypto', 'kripto', 'telegram', 'hubungi kami', 'klik', 'click here', 'visit', 'kunjungi',
        'free trial', 'diskon besar', 'supplier', 'distributor', 'katalog'];
    $hits = 0;
    foreach ($kw as $w) {
        if (mb_strpos($t, $w) !== false) $hits++;
    }
    return $score + min(3, $hits);
}

function weGuardLogBlocked(PDO $pdo, string $form, string $reason, array $f): void
{
    try {
        weGuardEnsureSchema($pdo);
        $pdo->prepare("INSERT INTO web_form_blocked (form, ip, reason, name, phone, snippet) VALUES (?,?,?,?,?,?)")
            ->execute([$form, weGuardIp(), $reason, mb_substr((string)($f['name'] ?? ''), 0, 120), mb_substr((string)($f['phone'] ?? ''), 0, 40),
                mb_substr(trim((string)($f['message'] ?? '') . ' ' . (string)($f['date'] ?? '')), 0, 250)]);
        // Rapikan: simpan paling banyak ~1000 baris terakhir
        if (mt_rand(1, 50) === 1) {
            $pdo->exec("DELETE FROM web_form_blocked WHERE id < (SELECT m FROM (SELECT MAX(id) - 1000 AS m FROM web_form_blocked) x)");
            $pdo->exec("DELETE FROM web_form_hits WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
        }
    } catch (Throwable $e) {
        error_log('weGuardLogBlocked: ' . $e->getMessage());
    }
}

/**
 * Periksa satu kiriman form.
 * $f: name, phone, date (Y-m-d), message, email (opsional). $form: 'quick_quote' | 'kontak'.
 * @return array{ok:bool, silent:bool, msg:string, reason:string}
 *   silent=true → jawab seolah sukses tanpa menyimpan (bot); silent=false & ok=false → tampilkan msg.
 */
function weSpamGuard(PDO $pdo, string $form, array $f): array
{
    $deny = fn(string $reason, string $msg, bool $silent) => ['ok' => false, 'silent' => $silent, 'msg' => $msg, 'reason' => $reason];
    try {
        // 1) Honeypot
        if (!empty($_POST['we_hp'])) {
            weGuardLogBlocked($pdo, $form, 'honeypot', $f);
            return $deny('honeypot', '', true);
        }

        // 2) Token waktu
        $tok = (string)($_POST['we_ts'] ?? '');
        $parts = explode('.', $tok, 2);
        $age = null;
        if (count($parts) === 2 && ctype_digit($parts[0]) && hash_equals(weGuardToken((int)$parts[0]), $tok)) {
            $age = time() - (int)$parts[0];
        }
        if ($age === null) {
            weGuardLogBlocked($pdo, $form, 'no_token', $f);
            return $deny('no_token', '', true);
        }
        if ($age < WE_GUARD_MIN_SECONDS) {
            weGuardLogBlocked($pdo, $form, 'too_fast', $f);
            return $deny('too_fast', '', true);
        }
        if ($age > WE_GUARD_MAX_SECONDS) {
            return $deny('token_expired', 'Halaman sudah terlalu lama terbuka. Muat ulang halaman lalu kirim lagi.', false);
        }

        // 3) Rate limit (IP + HP) — bila database bermasalah, lapisan ini dilewati (lapisan lain tetap jalan)
        $digits = weGuardPhoneDigits((string)($f['phone'] ?? ''));
        try {
            weGuardEnsureSchema($pdo);
            $ip = weGuardIp();
            $st = $pdo->prepare("SELECT COUNT(*) FROM web_form_hits WHERE ip = ? AND form = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $st->execute([$ip, $form]);
            $perHour = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM web_form_hits WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $st->execute([$ip]);
            $perDay = (int)$st->fetchColumn();
            $dupPhone = 0;
            if ($digits !== null) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM web_form_hits WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
                $st->execute([$digits]);
                $dupPhone = (int)$st->fetchColumn();
            }
            // setiap kiriman yang sampai sini dihitung (termasuk yang nanti gagal validasi), agar banjir kiriman tertahan
            $pdo->prepare("INSERT INTO web_form_hits (form, ip, phone) VALUES (?,?,?)")->execute([$form, $ip, $digits]);
            if ($perHour >= WE_GUARD_PER_HOUR || $perDay >= WE_GUARD_PER_DAY) {
                weGuardLogBlocked($pdo, $form, 'rate_limit', $f);
                return $deny('rate_limit', '', true);
            }
            if ($dupPhone > 0) {
                weGuardLogBlocked($pdo, $form, 'duplicate_phone', $f);
                return $deny('duplicate_phone', '', true);
            }
        } catch (Throwable $e) {
            error_log('weSpamGuard rate limit skipped: ' . $e->getMessage());
        }
        // 4) Validasi isi (pesan kesalahan biasa, mungkin salah ketik manusia)
        if (!weGuardNameOk((string)($f['name'] ?? ''))) {
            weGuardLogBlocked($pdo, $form, 'bad_name', $f);
            return $deny('bad_name', 'Nama tidak valid. Gunakan huruf saja (2–60 karakter).', false);
        }
        if ($digits === null) {
            weGuardLogBlocked($pdo, $form, 'bad_phone', $f);
            return $deny('bad_phone', 'No. WhatsApp tidak valid. Contoh: 081234567890.', false);
        }
        if (isset($f['date']) && !weGuardTripDateOk((string)$f['date'])) {
            weGuardLogBlocked($pdo, $form, 'bad_date', $f);
            return $deny('bad_date', 'Tanggal trip tidak valid. Pilih tanggal dari hari ini sampai ' . WE_GUARD_MAX_YEARS_AHEAD . ' tahun ke depan.', false);
        }
        if (!empty($f['email']) && !filter_var((string)$f['email'], FILTER_VALIDATE_EMAIL)) {
            weGuardLogBlocked($pdo, $form, 'bad_email', $f);
            return $deny('bad_email', 'Alamat email tidak valid.', false);
        }
        $msg = (string)($f['message'] ?? '');
        if (mb_strlen($msg) > 800) {
            weGuardLogBlocked($pdo, $form, 'long_message', $f);
            return $deny('long_message', 'Pesan terlalu panjang (maksimal 800 karakter).', false);
        }
        if ($msg !== '' && weGuardMessageScore($msg) >= 2) {
            weGuardLogBlocked($pdo, $form, 'promo_message', $f);
            return $deny('promo_message', 'Pesan Anda terdeteksi sebagai promosi/spam. Tulis kebutuhan trip Anda tanpa link atau penawaran jasa.', false);
        }
        return ['ok' => true, 'silent' => false, 'msg' => '', 'reason' => ''];
    } catch (Throwable $e) {
        // Gangguan pada pengaman tidak boleh memblokir pelanggan asli: lewatkan, tetapi catat
        error_log('weSpamGuard error: ' . $e->getMessage());
        return ['ok' => true, 'silent' => false, 'msg' => '', 'reason' => ''];
    }
}
