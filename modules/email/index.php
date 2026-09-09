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

$pageTitle = 'Email Kantor';
$activePage = 'email';
$currentUser = $auth->getCurrentUser();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$folder = (string)($_GET['folder'] ?? 'INBOX');
if (!array_key_exists($folder, EmailHelper::FOLDERS)) {
    $folder = 'INBOX';
}

$errorMsg = null;
$total = 0;
$messages = [];
$deleteMsg = null;
$emailConfig = EmailHelper::resolveConfig($db);

if ($emailConfig === null) {
    $errorMsg = 'Pengaturan email belum diisi. Klik "Pengaturan Email" di atas untuk mengisi host, user dan password.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
        $deleteUid = (int)($_POST['uid'] ?? 0);
        try {
            $emailHelper = new EmailHelper($emailConfig, $folder);
            $emailHelper->deleteMessage($deleteUid);
            $deleteMsg = $folder === 'INBOX.Trash' ? 'Email berhasil dihapus permanen.' : 'Email dipindahkan ke Sampah.';
        } catch (Throwable $e) {
            $errorMsg = 'Gagal menghapus email: ' . $e->getMessage();
        }
    }

    try {
        $emailHelper = new EmailHelper($emailConfig, $folder);
        $result = $emailHelper->listMessages($perPage, $offset);
        $total = $result['total'];
        $messages = $result['messages'];
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
    }
}

$totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;

include '../sunsea/layout-header.php';
?>

<style>
    .em-wrap {
        max-width: 1180px;
        margin: 0 auto;
    }

    .em-layout {
        display: flex;
        gap: 18px;
        align-items: flex-start;
    }

    .em-sidebar {
        width: 128px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        border-right: 1px solid var(--ss-gray-2);
        padding-right: 12px;
    }

    .em-sidebar a {
        display: block;
        padding: 8px 4px 8px 10px;
        border-left: 3px solid transparent;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 500;
        background: transparent !important;
        color: var(--ss-muted) !important;
    }

    .em-sidebar a.active {
        border-left-color: var(--ss-ocean) !important;
        font-weight: 700;
        color: var(--ss-text) !important;
    }

    .em-sidebar a:hover {
        border-left-color: var(--ss-gray-3) !important;
    }

    .em-main {
        flex: 1;
        min-width: 0;
    }

    @media (max-width: 720px) {
        .em-layout {
            flex-direction: column;
        }

        .em-sidebar {
            width: 100%;
            flex-direction: row;
            flex-wrap: wrap;
            border-right: none;
            border-bottom: 1px solid var(--ss-gray-2);
            padding-right: 0;
            padding-bottom: 6px;
        }

        .em-sidebar a {
            flex: 1;
            text-align: center;
            min-width: 80px;
            border-left: none;
            border-bottom: 3px solid transparent;
        }

        .em-sidebar a.active {
            border-left-color: transparent !important;
            border-bottom-color: var(--ss-ocean) !important;
        }
    }

    .em-card {
        background: var(--ss-white);
        border: 1px solid var(--ss-gray-2);
        border-radius: var(--ss-radius);
        overflow: hidden;
    }

    .em-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--ss-gray-1);
        text-decoration: none;
        color: inherit;
    }

    .em-row:hover {
        background: var(--ss-gray-1);
    }

    .em-row.unread {
        background: #fff7ed;
        font-weight: 600;
    }

    .em-from {
        width: 220px;
        flex-shrink: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.9rem;
    }

    .em-subject {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 0.9rem;
    }

    .em-date {
        width: 150px;
        flex-shrink: 0;
        text-align: right;
        font-size: 0.8rem;
        color: var(--ss-muted);
    }

    .em-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        padding: 14px 16px;
        border-radius: 10px;
        margin-bottom: 14px;
        font-size: 0.9rem;
        line-height: 1.6;
    }

    .em-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .em-pager {
        display: flex;
        gap: 8px;
        justify-content: center;
        padding: 14px;
    }

    .em-pager a {
        padding: 6px 12px;
        border: 1px solid var(--ss-gray-2);
        border-radius: 6px;
        text-decoration: none;
        color: var(--ss-text);
        font-size: 0.85rem;
    }
</style>

<div class="em-wrap">
    <div class="em-toolbar">
        <div style="font-size:0.85rem;color:var(--ss-muted);"><?php echo htmlspecialchars(EmailHelper::FOLDERS[$folder]); ?>: <?php echo htmlspecialchars($emailConfig['user'] ?? 'office@karimunjawaexplore.com'); ?> &bull; <?php echo (int)$total; ?> email</div>
        <div style="display:flex;gap:8px;">
            <a href="compose.php" style="text-decoration:none;padding:6px 14px;background:var(--ss-ocean);border-radius:6px;font-size:0.85rem;color:#ffffff !important;font-weight:600;">+ Tulis Email</a>
            <a href="settings.php" style="text-decoration:none;padding:6px 14px;border:1px solid var(--ss-gray-2);border-radius:6px;font-size:0.85rem;color:var(--ss-text);">Pengaturan Email</a>
            <a href="index.php?folder=<?php echo urlencode($folder); ?>" style="text-decoration:none;padding:6px 14px;border:1px solid var(--ss-gray-2);border-radius:6px;font-size:0.85rem;color:var(--ss-text);">Refresh</a>
        </div>
    </div>

    <?php if ($deleteMsg): ?>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:0.9rem;"><?php echo htmlspecialchars($deleteMsg); ?></div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="em-error">
            <strong>Gagal memuat email.</strong><br>
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="em-layout">
        <div class="em-sidebar">
            <?php foreach (EmailHelper::FOLDERS as $key => $label): ?>
                <a href="?folder=<?php echo urlencode($key); ?>" class="<?php echo $key === $folder ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="em-main">
            <div class="em-card">
                <?php if (!$errorMsg && empty($messages)): ?>
                    <div style="padding:24px;text-align:center;color:var(--ss-muted);">Tidak ada email.</div>
                <?php endif; ?>

                <?php foreach ($messages as $m): ?>
                    <div class="em-row <?php echo $m['seen'] ? '' : 'unread'; ?>">
                        <a href="view.php?uid=<?php echo (int)$m['uid']; ?>&folder=<?php echo urlencode($folder); ?>" style="display:flex;flex:1;gap:12px;align-items:center;text-decoration:none;color:inherit;min-width:0;">
                            <div class="em-from"><?php echo htmlspecialchars($m['from']); ?></div>
                            <div class="em-subject"><?php echo htmlspecialchars($m['subject']); ?></div>
                            <div class="em-date"><?php echo htmlspecialchars($m['date'] !== '' ? date('d M Y H:i', strtotime($m['date'])) : ''); ?></div>
                        </a>
                        <form method="post" onsubmit="return confirm('<?php echo $folder === 'INBOX.Trash' ? 'Hapus permanen email ini?' : 'Pindahkan email ini ke Sampah?'; ?>');" style="flex-shrink:0;margin:0;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="uid" value="<?php echo (int)$m['uid']; ?>">
                            <button type="submit" title="Hapus" style="background:none;border:none;color:#b91c1c;cursor:pointer;font-size:0.9rem;padding:6px 8px;">&#128465;</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="em-pager">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <a href="?folder=<?php echo urlencode($folder); ?>&page=<?php echo $p; ?>" style="<?php echo $p === $page ? 'background:var(--ss-ocean);color:#ffffff !important;border-color:var(--ss-ocean);' : ''; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'compose-widget.php'; ?>
<?php include '../sunsea/layout-footer.php'; ?>
