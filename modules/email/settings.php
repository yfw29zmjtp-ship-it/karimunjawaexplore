<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';
require_once '../sunsea/db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
$db = Database::getInstance();
$pageTitle = 'Pengaturan Email Kantor';
$activePage = 'email';
$currentUser = $auth->getCurrentUser();

$msg = null;
$msgType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim((string)($_POST['host'] ?? ''));
    $port = (int)($_POST['port'] ?? 993);
    $encryption = ($_POST['encryption'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl';
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $smtpPort = (int)($_POST['smtp_port'] ?? 465);
    $smtpEncryption = ($_POST['smtp_encryption'] ?? 'ssl') === 'tls' ? 'tls' : 'ssl';

    if ($host === '' || $user === '') {
        $msg = 'Host dan Username wajib diisi.';
        $msgType = 'error';
    } else {
        try {
            EmailHelper::saveDbSettings($db, [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'user' => $user,
                'pass' => $pass,
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
            ]);
            $msg = 'Pengaturan email berhasil disimpan.';
            $msgType = 'success';
        } catch (Throwable $e) {
            $msg = 'Gagal menyimpan: ' . $e->getMessage();
            $msgType = 'error';
        }
    }
}

$current = EmailHelper::resolveConfig($db) ?? [
    'host' => 'mail.karimunjawaexplore.com',
    'port' => 993,
    'encryption' => 'ssl',
    'user' => 'office@karimunjawaexplore.com',
    'pass' => '',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
];

include '../sunsea/layout-header.php';
?>

<style>
    .es-wrap {
        max-width: 600px;
        margin: 0 auto;
    }

    .es-card {
        background: var(--ss-white);
        border: 1px solid var(--ss-gray-2);
        border-radius: var(--ss-radius);
        padding: 20px;
    }

    .es-field {
        margin-bottom: 14px;
    }

    .es-field label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--ss-text);
        margin-bottom: 4px;
    }

    .es-field input,
    .es-field select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--ss-gray-2);
        border-radius: 6px;
        font-size: 0.9rem;
    }

    .es-hint {
        font-size: 0.78rem;
        color: var(--ss-muted);
        margin-top: 4px;
    }

    .es-msg {
        padding: 10px 14px;
        border-radius: 8px;
        margin-bottom: 14px;
        font-size: 0.9rem;
    }

    .es-msg.success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #15803d;
    }

    .es-msg.error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }

    .es-btn {
        background: var(--ss-ocean);
        color: #ffffff;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.9rem;
    }
</style>

<div class="es-wrap">
    <a href="<?php echo BASE_URL; ?>/modules/email/index.php" style="display:inline-block;margin-bottom:12px;text-decoration:none;color:var(--ss-ocean);font-size:0.9rem;">&larr; Kembali ke Inbox</a>

    <div class="es-card">
        <h2 style="margin-bottom:16px;font-size:1.1rem;color:var(--ss-text);">Pengaturan Email Kantor</h2>

        <?php if ($msg): ?>
            <div class="es-msg <?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="es-field">
                <label>Incoming Server (IMAP Host)</label>
                <input type="text" name="host" value="<?php echo htmlspecialchars($current['host']); ?>" required>
                <div class="es-hint">Contoh: mail.karimunjawaexplore.com</div>
            </div>

            <div class="es-field">
                <label>Port</label>
                <input type="number" name="port" value="<?php echo (int)$current['port']; ?>" required>
                <div class="es-hint">993 untuk SSL, 143 untuk TLS/STARTTLS</div>
            </div>

            <div class="es-field">
                <label>Enkripsi</label>
                <select name="encryption">
                    <option value="ssl" <?php echo $current['encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL (port 993)</option>
                    <option value="tls" <?php echo $current['encryption'] === 'tls' ? 'selected' : ''; ?>>TLS/STARTTLS (port 143)</option>
                </select>
            </div>

            <div class="es-field">
                <label>Username (alamat email)</label>
                <input type="text" name="user" value="<?php echo htmlspecialchars($current['user']); ?>" required>
            </div>

            <div class="es-field">
                <label>Password</label>
                <input type="password" name="pass" value="" placeholder="<?php echo $current['pass'] !== '' ? '•••••••• (sudah tersimpan, kosongkan jika tidak ingin diubah)' : 'Masukkan password email'; ?>" autocomplete="new-password">
                <div class="es-hint">Password disimpan terenkripsi di database.</div>
            </div>

            <div class="es-field">
                <label>Outgoing Server Port (SMTP, untuk kirim email)</label>
                <input type="number" name="smtp_port" value="<?php echo (int)($current['smtp_port'] ?? 465); ?>" required>
                <div class="es-hint">465 untuk SSL, 587 untuk TLS/STARTTLS. Host SMTP sama dengan Incoming Server di atas.</div>
            </div>

            <div class="es-field">
                <label>Enkripsi SMTP</label>
                <select name="smtp_encryption">
                    <option value="ssl" <?php echo ($current['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL (port 465)</option>
                    <option value="tls" <?php echo ($current['smtp_encryption'] ?? 'ssl') === 'tls' ? 'selected' : ''; ?>>TLS/STARTTLS (port 587)</option>
                </select>
            </div>

            <button type="submit" class="es-btn">Simpan Pengaturan</button>
        </form>
    </div>
</div>

<?php include '../sunsea/layout-footer.php'; ?>