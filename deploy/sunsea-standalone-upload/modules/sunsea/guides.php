<?php

/** Sunsea - Guide Darat & Laut */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureMasterDataSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $payload = [
        'guide_type' => $_POST['guide_type'] ?? 'darat',
        'name' => trim($_POST['name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'daily_rate_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['daily_rate_cost'] ?? '0'),
        'daily_rate_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['daily_rate_sell'] ?? '0'),
        'status' => $_POST['status'] ?? 'available',
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($payload['name'] === '') {
        $_SESSION['flash_message'] = 'Nama guide wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: guides.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE guides SET guide_type=?, name=?, phone=?, email=?, daily_rate_cost=?, daily_rate_sell=?, status=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?")
                ->execute([$payload['guide_type'], $payload['name'], $payload['phone'], $payload['email'], $payload['daily_rate_cost'], $payload['daily_rate_sell'], $payload['status'], $payload['notes'], $payload['is_active'], $id]);
            $_SESSION['flash_message'] = 'Data guide diperbarui.';
        } else {
            $last = $pdo->query("SELECT guide_code FROM guides ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            $code = 'SS-GD-' . str_pad($next, 3, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO guides (guide_code, guide_type, name, phone, email, daily_rate_cost, daily_rate_sell, status, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$code, $payload['guide_type'], $payload['name'], $payload['phone'], $payload['email'], $payload['daily_rate_cost'], $payload['daily_rate_sell'], $payload['status'], $payload['notes'], $payload['is_active']]);
            $_SESSION['flash_message'] = 'Guide baru berhasil ditambah.';
        }
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan guide: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: guides.php');
    exit;
}

$guides = [];
$dbError = '';
try {
    $guides = $pdo->query("SELECT * FROM guides ORDER BY is_active DESC, guide_type, name")->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Database Guide';
$activePage = 'database';
include 'layout-header.php';
?>

<?php if ($dbError): ?>
    <div style="padding:12px;background:#fee;border:1px solid #f88;border-radius:4px;color:#c33;margin-bottom:12px;">
        <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:18px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Guide</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div class="ss-form-group"><label class="ss-label">Kategori Guide</label><select name="guide_type" class="ss-select">
                    <option value="darat">Guide Darat</option>
                    <option value="laut">Guide Laut</option>
                </select></div>
            <div class="ss-form-group"><label class="ss-label">Nama</label><input class="ss-input" name="name" required></div>
            <div class="ss-form-group"><label class="ss-label">Telepon</label><input class="ss-input" name="phone"></div>
            <div class="ss-form-group"><label class="ss-label">Email</label><input class="ss-input" name="email"></div>
            <div class="ss-form-group"><label class="ss-label">Rate Modal/Hari</label><input class="ss-input" name="daily_rate_cost"></div>
            <div class="ss-form-group"><label class="ss-label">Rate Jual/Hari</label><input class="ss-input" name="daily_rate_sell"></div>
            <div class="ss-form-group"><label class="ss-label">Status</label><select name="status" class="ss-select">
                    <option value="available">Available</option>
                    <option value="on_trip">On Trip</option>
                    <option value="off">Off</option>
                </select></div>
            <div class="ss-form-group"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes"></textarea></div>
            <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Guide</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Guide</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th>Kontak</th>
                        <th>Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guides as $g): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($g['guide_code'] ?? '-'); ?></td>
                            <td><strong><?php echo htmlspecialchars($g['name']); ?></strong></td>
                            <td><?php echo $g['guide_type'] === 'darat' ? 'Guide Darat' : 'Guide Laut'; ?></td>
                            <td><?php echo htmlspecialchars($g['phone'] ?: '-'); ?><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($g['email'] ?: '-'); ?></small></td>
                            <td>Modal <?php echo sunseaRupiah((float)$g['daily_rate_cost']); ?><br><small style="color:var(--ss-muted)">Jual <?php echo sunseaRupiah((float)$g['daily_rate_sell']); ?></small></td>
                            <td><?php echo '<span class="ss-status ss-status-' . ($g['status'] === 'available' ? 'approved' : ($g['status'] === 'on_trip' ? 'sent' : 'draft')) . '">' . ucfirst($g['status']) . '</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
