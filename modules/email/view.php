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

$uid = (int)($_GET['uid'] ?? 0);
$folder = (string)($_GET['folder'] ?? 'INBOX');
if (!array_key_exists($folder, EmailHelper::FOLDERS)) {
    $folder = 'INBOX';
}
$pageTitle = 'Email Kantor';
$activePage = 'email';
$currentUser = $auth->getCurrentUser();

$errorMsg = null;
$mail = null;
$emailConfig = EmailHelper::resolveConfig($db);

if ($emailConfig === null) {
    $errorMsg = 'Pengaturan email belum diisi. Buka menu Pengaturan Email untuk mengisi host, user dan password.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        try {
            $emailHelper = new EmailHelper($emailConfig, $folder);
            $emailHelper->deleteMessage($uid);
            header('Location: ' . BASE_URL . '/modules/email/index.php?folder=' . urlencode($folder));
            exit;
        } catch (Throwable $e) {
            $errorMsg = 'Gagal menghapus email: ' . $e->getMessage();
        }
    }

    try {
        $emailHelper = new EmailHelper($emailConfig, $folder);
        $mail = $emailHelper->getMessageByUid($uid);
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

include '../sunsea/layout-header.php';
?>

<style>
    .em-wrap {
        max-width: 900px;
        margin: 0 auto;
    }

    .em-card {
        background: var(--ss-white);
        border: 1px solid var(--ss-gray-2);
        border-radius: var(--ss-radius);
        padding: 16px;
    }

    .em-meta {
        border-bottom: 1px solid var(--ss-gray-1);
        padding-bottom: 12px;
        margin-bottom: 12px;
    }

    .em-meta h2 {
        font-size: 1.1rem;
        margin-bottom: 8px;
        color: var(--ss-text);
    }

    .em-meta div {
        font-size: 0.85rem;
        color: var(--ss-muted);
        margin-bottom: 2px;
    }

    .em-back {
        display: inline-block;
        margin-bottom: 12px;
        text-decoration: none;
        color: var(--ss-ocean);
        font-size: 0.9rem;
    }

    .em-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 14px 16px;
        border-radius: 10px;
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .em-body-frame {
        width: 100%;
        min-height: 400px;
        border: none;
    }
</style>

<div class="em-wrap">
    <a class="em-back" href="<?php echo BASE_URL; ?>/modules/email/index.php?folder=<?php echo urlencode($folder); ?>">&larr; Kembali ke <?php echo htmlspecialchars(EmailHelper::FOLDERS[$folder]); ?></a>

    <?php if ($errorMsg): ?>
        <div class="em-error"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php else: ?>
        <div class="em-card">
            <div class="em-meta">
                <h2><?php echo htmlspecialchars($mail['subject']); ?></h2>
                <div><strong>Dari:</strong> <?php echo htmlspecialchars($mail['from']); ?></div>
                <div><strong>Kepada:</strong> <?php echo htmlspecialchars($mail['to']); ?></div>
                <div><strong>Tanggal:</strong> <?php echo htmlspecialchars($mail['date'] !== '' ? date('d M Y H:i', strtotime($mail['date'])) : ''); ?></div>
            </div>

            <?php if (!empty($mail['body_html'])): ?>
                <iframe class="em-body-frame" sandbox="" srcdoc="<?php echo htmlspecialchars($mail['body_html']); ?>"></iframe>
            <?php else: ?>
                <pre style="white-space: pre-wrap; font-family: inherit; font-size: 0.9rem; color: var(--ss-text);"><?php echo htmlspecialchars($mail['body_plain']); ?></pre>
            <?php endif; ?>

            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--ss-gray-1);display:flex;gap:10px;">
                <a href="<?php echo BASE_URL; ?>/modules/email/compose.php?reply_uid=<?php echo (int)$uid; ?>&folder=<?php echo urlencode($folder); ?>"
                    style="text-decoration:none;padding:8px 18px;background:var(--ss-ocean);color:#ffffff !important;border-radius:6px;font-size:0.85rem;font-weight:600;">&#8617; Balas</a>
                <form method="post" onsubmit="return confirm('<?php echo $folder === 'INBOX.Trash' ? 'Hapus permanen email ini?' : 'Pindahkan email ini ke Sampah?'; ?>');" style="margin:0;">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" style="padding:8px 18px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;">&#128465; Hapus</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'compose-widget.php'; ?>
<?php include '../sunsea/layout-footer.php'; ?>
