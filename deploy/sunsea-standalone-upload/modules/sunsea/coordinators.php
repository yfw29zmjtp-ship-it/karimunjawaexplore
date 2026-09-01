<?php

/** Sunsea - Koordinator */
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
    $d = [
        'name' => trim($_POST['name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'area' => trim($_POST['area'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($d['name'] === '') {
        $_SESSION['flash_message'] = 'Nama koordinator wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: coordinators.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE coordinators SET name=?, phone=?, email=?, area=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?")
                ->execute([$d['name'], $d['phone'], $d['email'], $d['area'], $d['notes'], $d['is_active'], $id]);
        } else {
            $last = $pdo->query("SELECT coordinator_code FROM coordinators ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            $code = 'SS-KO-' . str_pad($next, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO coordinators (coordinator_code, name, phone, email, area, notes, is_active) VALUES (?,?,?,?,?,?,?)")
                ->execute([$code, $d['name'], $d['phone'], $d['email'], $d['area'], $d['notes'], $d['is_active']]);
        }
        $_SESSION['flash_message'] = 'Data koordinator tersimpan.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan koordinator: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: coordinators.php');
    exit;
}

$rows = [];
$dbError = '';
try {
    $rows = $pdo->query("SELECT * FROM coordinators ORDER BY is_active DESC, name")->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Koordinator';
$activePage = 'coordinators';
include 'layout-header.php';
?>

<?php if ($dbError): ?>
    <div style="padding:12px;background:#fee;border:1px solid #f88;border-radius:4px;color:#c33;margin-bottom:12px;">
        <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:18px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Koordinator</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div class="ss-form-group"><label class="ss-label">Nama</label><input class="ss-input" name="name" required></div>
            <div class="ss-form-group"><label class="ss-label">Telepon</label><input class="ss-input" name="phone"></div>
            <div class="ss-form-group"><label class="ss-label">Email</label><input class="ss-input" name="email"></div>
            <div class="ss-form-group"><label class="ss-label">Area</label><input class="ss-input" name="area" placeholder="Karimunjawa / Jepara / Semarang"></div>
            <div class="ss-form-group"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes"></textarea></div>
            <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Koordinator</button>
        </form>
    </div>
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Koordinator</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kontak</th>
                        <th>Area</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['coordinator_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['name']); ?></td>
                            <td><?php echo htmlspecialchars($r['phone'] ?: '-'); ?><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['email'] ?: '-'); ?></small></td>
                            <td><?php echo htmlspecialchars($r['area'] ?: '-'); ?></td>
                            <td><?php echo $r['is_active'] ? '<span class="ss-status ss-status-approved">Aktif</span>' : '<span class="ss-status ss-status-draft">Nonaktif</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
