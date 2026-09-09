<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';
require_once '../../includes/SmtpMailer.php';
require_once '../sunsea/db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
$db = Database::getInstance();
$pageTitle = 'Tulis Email';
$activePage = 'email';
$currentUser = $auth->getCurrentUser();

$emailConfig = EmailHelper::resolveConfig($db);
$errorMsg = null;
$successMsg = null;

$to = trim((string)($_POST['to'] ?? $_GET['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? $_GET['subject'] ?? ''));
$body = (string)($_POST['body'] ?? '');

// Pre-fill quoted reply body when opened via "Balas" from view.php
$replyUid = (int)($_GET['reply_uid'] ?? 0);
$replyFolder = (string)($_GET['folder'] ?? 'INBOX');
if (!array_key_exists($replyFolder, EmailHelper::FOLDERS)) {
    $replyFolder = 'INBOX';
}
if ($replyUid > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST' && $emailConfig !== null) {
    try {
        $helper = new EmailHelper($emailConfig, $replyFolder);
        $original = $helper->getMessageByUid($replyUid);
        if ($to === '') {
            // "From" header often includes a display name (e.g. "Arif <a@b.com>") -
            // the "Kepada" field needs the bare address only, or the browser rejects it.
            $to = preg_match('/<([^<>]+)>/', $original['from'], $m) ? trim($m[1]) : trim($original['from']);
        }
        $subject = $subject !== '' ? $subject : (stripos($original['subject'], 're:') === 0 ? $original['subject'] : 'Re: ' . $original['subject']);
    } catch (Throwable $e) {
        // Ignore - user can still compose manually.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($emailConfig === null) {
        $errorMsg = 'Pengaturan email belum diisi. Buka menu Pengaturan Email terlebih dahulu.';
    } elseif ($to === '' || $subject === '' || trim($body) === '') {
        $errorMsg = 'Penerima, subjek dan isi email wajib diisi.';
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Alamat email penerima tidak valid.';
    } else {
        try {
            $mailer = new SmtpMailer(
                $emailConfig['host'],
                (int)($emailConfig['smtp_port'] ?? 465),
                $emailConfig['smtp_encryption'] ?? 'ssl',
                $emailConfig['user'],
                $emailConfig['pass']
            );
            $htmlBody = nl2br(htmlspecialchars($body));
            $rawSent = $mailer->send($to, $subject, $htmlBody, 'Karimunjawa Explore');
            EmailHelper::appendMessageToFolder($emailConfig, 'INBOX.Sent', $rawSent);
            $successMsg = 'Email berhasil dikirim ke ' . htmlspecialchars($to) . '.';
            $to = $subject = $body = '';
        } catch (Throwable $e) {
            $errorMsg = 'Gagal mengirim email: ' . $e->getMessage();
        }
    }
}

include '../sunsea/layout-header.php';
?>

<style>
    .ec-wrap {
        max-width: 100%;
    }

    .ec-card {
        background: var(--ss-white);
        border: 1px solid var(--ss-gray-2);
        border-radius: var(--ss-radius);
        padding: 20px;
    }

    .ec-field {
        margin-bottom: 14px;
    }

    .ec-field label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--ss-text);
        margin-bottom: 4px;
    }

    .ec-field input,
    .ec-field textarea {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid var(--ss-gray-2);
        border-radius: 6px;
        font-size: 0.9rem;
        font-family: inherit;
    }

    .ec-field textarea {
        min-height: 260px;
        resize: vertical;
    }

    .ec-msg {
        padding: 10px 14px;
        border-radius: 8px;
        margin-bottom: 14px;
        font-size: 0.9rem;
    }

    .ec-msg.success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #15803d;
    }

    .ec-msg.error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }

    .ec-btn {
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

<div class="ec-wrap">
    <a href="<?php echo BASE_URL; ?>/modules/email/index.php" style="display:inline-block;margin-bottom:12px;text-decoration:none;color:var(--ss-ocean);font-size:0.9rem;">&larr; Kembali ke Inbox</a>

    <div class="ec-card">
        <h2 style="margin-bottom:16px;font-size:1.1rem;color:var(--ss-text);">Tulis Email Baru</h2>

        <?php if ($successMsg): ?>
            <div class="ec-msg success"><?php echo $successMsg; ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="ec-msg error"><?php echo htmlspecialchars($errorMsg); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="ec-field">
                <label>Kepada</label>
                <input type="email" name="to" value="<?php echo htmlspecialchars($to); ?>" required>
            </div>

            <div class="ec-field">
                <label>Subjek</label>
                <input type="text" name="subject" value="<?php echo htmlspecialchars($subject); ?>" required>
            </div>

            <div class="ec-field">
                <label>Isi Email</label>
                <textarea name="body" required><?php echo htmlspecialchars($body); ?></textarea>
            </div>

            <button type="submit" class="ec-btn">Kirim</button>
        </form>
    </div>
</div>

<?php include '../sunsea/layout-footer.php'; ?>