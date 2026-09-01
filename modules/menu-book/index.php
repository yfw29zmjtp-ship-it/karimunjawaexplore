<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$bizMap = [
    'narayanahotel' => 'narayana-hotel',
    'benscafe' => 'bens-cafe',
    'eaatmeet' => 'eaat-meet',
    'eatmeet' => 'eaat-meet',
];
$activeBizRaw = (string)($_SESSION['active_business_id'] ?? (defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : ''));
$activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBizRaw));
$activeBizSlug = $bizMap[$activeBizNorm] ?? '';
if ($activeBizSlug === '') {
    http_response_code(403);
    echo 'Menu Buku Menu hanya tersedia untuk bisnis yang ditentukan.';
    exit;
}

$isDeveloperRole = (($_SESSION['role'] ?? '') === 'developer');
if (!$isDeveloperRole && !$auth->hasPermission('menu_book')) {
    http_response_code(403);
    echo 'Akses ditolak. Hubungi developer untuk pemberian izin menu_book.';
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Buku Menu';

function ensureMenuBookTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS menu_book_pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) DEFAULT NULL,
        image_path VARCHAR(255) NOT NULL,
        page_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_order (page_order),
        KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureMenuBookMenuRegistered(string $activeBizSlug): void
{
    try {
        $masterPdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $masterPdo->beginTransaction();

        $menuRow = $masterPdo->prepare('SELECT id FROM menu_items WHERE menu_code = ? LIMIT 1');
        $menuRow->execute(['menu_book']);
        $menuId = (int)($menuRow->fetchColumn() ?: 0);

        if ($menuId <= 0) {
            $insMenu = $masterPdo->prepare("INSERT INTO menu_items (menu_name, menu_code, menu_icon, menu_url, menu_order, is_active)
                VALUES (?, ?, ?, ?, ?, 1)");
            $insMenu->execute(['Buku Menu', 'menu_book', 'bi bi-book', 'modules/menu-book/index.php', 68]);
            $menuId = (int)$masterPdo->lastInsertId();
        }

        if ($menuId > 0) {
            $bizIds = [];
            $bizStmt = $masterPdo->prepare('SELECT id FROM businesses WHERE slug = ? OR LOWER(REPLACE(REPLACE(business_code, "-", ""), "_", "")) = ? LIMIT 1');
            foreach (['narayana-hotel', 'bens-cafe', 'eaat-meet'] as $slug) {
                $codeNorm = strtolower(str_replace(['-', '_'], '', $slug));
                $bizStmt->execute([$slug, $codeNorm]);
                $bid = (int)($bizStmt->fetchColumn() ?: 0);
                if ($bid > 0) {
                    $bizIds[] = $bid;
                }
            }

            $bizIds = array_values(array_unique($bizIds));
            $linkStmt = $masterPdo->prepare('INSERT IGNORE INTO business_menu_config (business_id, menu_id, is_enabled, created_at) VALUES (?, ?, 1, NOW())');
            foreach ($bizIds as $bid) {
                $linkStmt->execute([(int)$bid, $menuId]);
            }
        }

        $masterPdo->commit();
    } catch (Throwable $e) {
        error_log('menu-book register menu warning: ' . $e->getMessage());
    }
}

function parseIniSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    if ($unit === 'g') {
        return (int)round($number * 1024 * 1024 * 1024);
    }
    if ($unit === 'm') {
        return (int)round($number * 1024 * 1024);
    }
    if ($unit === 'k') {
        return (int)round($number * 1024);
    }
    return (int)round($number);
}

function formatBytesHuman(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = (int)floor(log($bytes, 1024));
    $pow = max(0, min($pow, count($units) - 1));
    $value = $bytes / (1024 ** $pow);
    return number_format($value, $pow === 0 ? 0 : 1) . ' ' . $units[$pow];
}

function normalizeUploadFiles(array $fileField): array
{
    $names = $fileField['name'] ?? [];
    $tmps = $fileField['tmp_name'] ?? [];
    $errs = $fileField['error'] ?? [];
    $sizes = $fileField['size'] ?? [];

    if (!is_array($names)) {
        $names = [$names];
    }
    if (!is_array($tmps)) {
        $tmps = [$tmps];
    }
    if (!is_array($errs)) {
        $errs = [$errs];
    }
    if (!is_array($sizes)) {
        $sizes = [$sizes];
    }

    $count = max(count($names), count($tmps), count($errs), count($sizes));
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => (string)($names[$i] ?? ''),
            'tmp_name' => (string)($tmps[$i] ?? ''),
            'error' => (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$i] ?? 0),
        ];
    }
    return $out;
}

function uploadErrorText(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'Melebihi upload_max_filesize server.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'Melebihi batas ukuran dari form.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload tidak lengkap (partial).';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Folder temporary upload tidak tersedia.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Gagal menulis file ke disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload dihentikan oleh extension PHP.';
        case UPLOAD_ERR_NO_FILE:
            return 'Tidak ada file dipilih.';
        case UPLOAD_ERR_OK:
            return 'OK';
        default:
            return 'Error upload tidak diketahui.';
    }
}

ensureMenuBookTable($pdo);
if (in_array((string)($_SESSION['role'] ?? ''), ['developer', 'owner'], true)) {
    ensureMenuBookMenuRegistered($activeBizSlug);
}

$uploadDirAbs = BASE_PATH . '/uploads/menu-books/' . $activeBizSlug;
$uploadDirRel = 'uploads/menu-books/' . $activeBizSlug;
if (!is_dir($uploadDirAbs)) {
    @mkdir($uploadDirAbs, 0777, true);
}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload_pages') {
            if (!$isDeveloperRole && !$auth->canCreate('menu_book')) {
                throw new Exception('Anda tidak punya hak create untuk menu ini.');
            }

            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMaxBytes = parseIniSizeToBytes((string)ini_get('post_max_size'));
            if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
                throw new Exception('Total file terlalu besar untuk server (post_max_size ' . formatBytesHuman($postMaxBytes) . '). Kurangi jumlah/ukuran gambar.');
            }

            if (!isset($_FILES['menu_images'])) {
                throw new Exception('File gambar belum dipilih.');
            }

            $files = normalizeUploadFiles((array)$_FILES['menu_images']);
            if (empty($files)) {
                throw new Exception('Tidak ada file yang terbaca dari request upload.');
            }

            $nextOrderRow = $pdo->query('SELECT COALESCE(MAX(page_order), 0) + 1 FROM menu_book_pages')->fetchColumn();
            $nextOrder = (int)$nextOrderRow;
            $insertStmt = $pdo->prepare('INSERT INTO menu_book_pages (title, image_path, page_order, is_active, created_by) VALUES (?, ?, ?, 1, ?)');

            $okCount = 0;
            $failReasons = [];
            foreach ($files as $i => $file) {
                $fileLabel = trim($file['name']) !== '' ? $file['name'] : ('file ke-' . ($i + 1));
                if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                    if ((int)$file['error'] !== UPLOAD_ERR_NO_FILE) {
                        $failReasons[] = $fileLabel . ': ' . uploadErrorText((int)$file['error']);
                    }
                    continue;
                }

                $tmp = (string)$file['tmp_name'];
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    $failReasons[] = $fileLabel . ': file temporary tidak valid.';
                    continue;
                }

                $imgInfo = @getimagesize($tmp);
                if (!$imgInfo) {
                    $failReasons[] = $fileLabel . ': bukan gambar yang valid.';
                    continue;
                }

                $w = (int)($imgInfo[0] ?? 0);
                $h = (int)($imgInfo[1] ?? 0);
                if ($w < 900 || $h < 900 || ($w * $h) < 1500000) {
                    $failReasons[] = $fileLabel . ': resolusi terlalu kecil (min 900x900 dan >1.5MP).';
                    continue;
                }

                $mime = (string)($imgInfo['mime'] ?? '');
                $extMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                if (!isset($extMap[$mime])) {
                    $failReasons[] = $fileLabel . ': format tidak didukung. Gunakan JPG/PNG/WEBP.';
                    continue;
                }

                $ext = $extMap[$mime];
                $newName = 'page_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $absTarget = $uploadDirAbs . '/' . $newName;
                $relTarget = $uploadDirRel . '/' . $newName;

                if (!move_uploaded_file($tmp, $absTarget)) {
                    $failReasons[] = $fileLabel . ': gagal dipindahkan ke folder upload.';
                    continue;
                }

                $title = pathinfo((string)$file['name'], PATHINFO_FILENAME);
                $insertStmt->execute([$title, $relTarget, $nextOrder, (int)($currentUser['id'] ?? 0)]);
                $nextOrder++;
                $okCount++;
            }

            if ($okCount <= 0) {
                $detail = '';
                if (!empty($failReasons)) {
                    $detail = ' Detail: ' . implode(' | ', array_slice($failReasons, 0, 3));
                }
                throw new Exception('Upload gagal. Pastikan file gambar valid dan tidak melebihi batas server.' . $detail);
            }

            $msg = $okCount . ' halaman menu berhasil diupload.';
            $failedCount = count($failReasons);
            if ($failedCount > 0) {
                $msg .= ' ' . $failedCount . ' file dilewati karena tidak valid.';
            }
            $msgType = 'success';
        }

        if ($action === 'save_pages') {
            if (!$isDeveloperRole && !$auth->canEdit('menu_book')) {
                throw new Exception('Anda tidak punya hak edit untuk menu ini.');
            }

            $titles = $_POST['title'] ?? [];
            $orders = $_POST['page_order'] ?? [];
            $actives = $_POST['is_active'] ?? [];

            $upd = $pdo->prepare('UPDATE menu_book_pages SET title = ?, page_order = ?, is_active = ? WHERE id = ?');
            foreach ($orders as $id => $orderVal) {
                $idInt = (int)$id;
                if ($idInt <= 0) {
                    continue;
                }
                $title = trim((string)($titles[$id] ?? ''));
                $order = max(0, (int)$orderVal);
                $active = isset($actives[$id]) ? 1 : 0;
                $upd->execute([$title !== '' ? $title : null, $order, $active, $idInt]);
            }

            $msg = 'Perubahan halaman menu berhasil disimpan.';
            $msgType = 'success';
        }

        if ($action === 'delete_page') {
            if (!$isDeveloperRole && !$auth->canDelete('menu_book')) {
                throw new Exception('Anda tidak punya hak delete untuk menu ini.');
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('ID halaman tidak valid.');
            }

            $rowStmt = $pdo->prepare('SELECT image_path FROM menu_book_pages WHERE id = ? LIMIT 1');
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('Halaman tidak ditemukan.');
            }

            $pdo->prepare('DELETE FROM menu_book_pages WHERE id = ?')->execute([$id]);

            $imgPath = trim((string)($row['image_path'] ?? ''));
            if ($imgPath !== '') {
                $abs = BASE_PATH . '/' . ltrim($imgPath, '/');
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            $msg = 'Halaman berhasil dihapus.';
            $msgType = 'success';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

$pages = $pdo->query('SELECT * FROM menu_book_pages ORDER BY page_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$publicUrl = BASE_URL . '/menu-book.php?biz=' . urlencode($activeBizSlug);
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($publicUrl);

include '../../includes/header.php';
?>

<style>
    .mb-wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 14px;
    }

    .mb-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 12px;
    }

    .mb-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }

    .mb-item {
        border: 1px solid #dbe4ee;
        border-radius: 10px;
        padding: 10px;
        background: #f8fafc;
    }

    .mb-item img {
        width: 100%;
        height: 160px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #dbe4ee;
        background: #fff;
    }

    .mb-row {
        margin-top: 8px;
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .mb-input {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
    }

    .mb-btn {
        border: 1px solid #334155;
        background: #334155;
        color: #fff !important;
        border-radius: 8px;
        padding: 7px 11px;
        cursor: pointer;
        font-weight: 600;
        text-decoration: none;
    }

    .mb-btn:link,
    .mb-btn:visited,
    .mb-btn:hover,
    .mb-btn:focus,
    .mb-btn:active {
        color: #fff !important;
    }

    .mb-btn-primary {
        background: #1d4ed8;
        color: #fff;
        border-color: #1d4ed8;
    }

    .mb-btn-danger {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    .mb-alert-ok {
        background: #ecfdf3;
        color: #166534;
        border: 1px solid #bbf7d0;
        padding: 8px 10px;
        border-radius: 8px;
        margin-bottom: 10px;
    }

    .mb-alert-err {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
        padding: 8px 10px;
        border-radius: 8px;
        margin-bottom: 10px;
    }

    .mb-qr {
        display: grid;
        grid-template-columns: 180px 1fr;
        gap: 12px;
        align-items: center;
    }

    .mb-qr img {
        width: 180px;
        height: 180px;
        border: 1px solid #dbe4ee;
        border-radius: 10px;
        background: #fff;
    }

    @media (max-width: 768px) {
        .mb-qr {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="mb-wrap">
    <div style="margin-bottom:10px;">
        <h2 style="margin:0; font-size:1.35rem; color:#0f172a;">Buku Menu</h2>
        <div style="color:#64748b; font-size:0.9rem;">Upload gambar menu high-res, urutkan halaman, dan pakai QR tetap untuk bisnis ini.</div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="<?php echo $msgType === 'success' ? 'mb-alert-ok' : 'mb-alert-err'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="mb-card">
        <h3 style="margin:0 0 8px 0; font-size:1rem;">QR Tetap (Tidak berubah saat ganti gambar)</h3>
        <div class="mb-qr">
            <img src="<?php echo htmlspecialchars($qrImageUrl); ?>" alt="QR Buku Menu">
            <div>
                <div style="font-size:0.85rem; color:#64748b; margin-bottom:4px;">URL publik bisnis ini:</div>
                <input class="mb-input" type="text" readonly value="<?php echo htmlspecialchars($publicUrl); ?>" onclick="this.select()">
                <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                    <a class="mb-btn" href="<?php echo htmlspecialchars($publicUrl); ?>" target="_blank">Buka Halaman Publik</a>
                    <a class="mb-btn" href="<?php echo htmlspecialchars($qrImageUrl); ?>" target="_blank">Download QR PNG</a>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-card">
        <h3 style="margin:0 0 8px 0; font-size:1rem;">Upload Halaman Menu (Gambar Resolusi Tinggi)</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_pages">
            <input type="file" name="menu_images[]" accept="image/jpeg,image/png,image/webp" multiple required>
            <div style="margin-top:7px; color:#64748b; font-size:0.82rem;">Format: JPG/PNG/WEBP. Minimal 900x900 dan total piksel > 1.5MP.</div>
            <button class="mb-btn mb-btn-primary" type="submit" style="margin-top:10px;">Upload Gambar</button>
        </form>
    </div>

    <div class="mb-card">
        <h3 style="margin:0 0 10px 0; font-size:1rem;">Kelola Halaman</h3>

        <?php if (empty($pages)): ?>
            <div style="color:#64748b;">Belum ada halaman menu.</div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="save_pages">
                <div class="mb-grid">
                    <?php foreach ($pages as $p): ?>
                        <div class="mb-item">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars((string)$p['image_path']); ?>" alt="page">
                            <div class="mb-row">
                                <label style="font-size:0.75rem; color:#64748b; min-width:40px;">Judul</label>
                                <input class="mb-input" type="text" name="title[<?php echo (int)$p['id']; ?>]" value="<?php echo htmlspecialchars((string)($p['title'] ?? '')); ?>">
                            </div>
                            <div class="mb-row">
                                <label style="font-size:0.75rem; color:#64748b; min-width:40px;">Urut</label>
                                <input class="mb-input" type="number" name="page_order[<?php echo (int)$p['id']; ?>]" value="<?php echo (int)$p['page_order']; ?>">
                            </div>
                            <div class="mb-row">
                                <label style="font-size:0.8rem;"><input type="checkbox" name="is_active[<?php echo (int)$p['id']; ?>]" <?php echo ((int)$p['is_active'] === 1) ? 'checked' : ''; ?>> Aktif</label>
                            </div>
                            <div class="mb-row" style="justify-content:flex-end;">
                                <button class="mb-btn mb-btn-danger" type="button" onclick="return submitDeletePage(<?php echo (int)$p['id']; ?>);">Hapus</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="mb-btn mb-btn-primary" type="submit" style="margin-top:12px;">Simpan Perubahan</button>
            </form>

            <form id="delete-page-form" method="post" style="display:none;">
                <input type="hidden" name="action" value="delete_page">
                <input type="hidden" name="id" id="delete-page-id" value="">
            </form>

            <script>
                function submitDeletePage(id) {
                    if (!confirm('Hapus halaman ini?')) {
                        return false;
                    }

                    const idInput = document.getElementById('delete-page-id');
                    const form = document.getElementById('delete-page-form');
                    if (!idInput || !form) {
                        return false;
                    }

                    idInput.value = String(id);
                    form.submit();
                    return false;
                }
            </script>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>