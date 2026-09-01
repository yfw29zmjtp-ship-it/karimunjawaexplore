<?php

/** Sunsea - Fasilitas */
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
        'category' => trim($_POST['category'] ?? ''),
        'unit' => trim($_POST['unit'] ?? 'unit'),
        'price_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
        'price_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
        'stock_qty' => (float)($_POST['stock_qty'] ?? 0),
        'status' => $_POST['status'] ?? 'ready',
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($d['name'] === '') {
        $_SESSION['flash_message'] = 'Nama fasilitas wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: facilities.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE facilities SET name=?, category=?, unit=?, price_cost=?, price_sell=?, stock_qty=?, status=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?")
                ->execute([$d['name'], $d['category'], $d['unit'], $d['price_cost'], $d['price_sell'], $d['stock_qty'], $d['status'], $d['notes'], $d['is_active'], $id]);
        } else {
            $last = $pdo->query("SELECT facility_code FROM facilities ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            $code = 'SS-FC-' . str_pad($next, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO facilities (facility_code, name, category, unit, price_cost, price_sell, stock_qty, status, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$code, $d['name'], $d['category'], $d['unit'], $d['price_cost'], $d['price_sell'], $d['stock_qty'], $d['status'], $d['notes'], $d['is_active']]);
        }
        $_SESSION['flash_message'] = 'Data fasilitas tersimpan.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan fasilitas: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: facilities.php');
    exit;
}

$rows = [];
$dbError = '';
try {
    $rows = $pdo->query("SELECT * FROM facilities ORDER BY is_active DESC, name")->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
$pageTitle = 'Database Fasilitas Tambahan';
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
        <div class="ss-card-title" style="margin-bottom:12px;">Input Fasilitas</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div class="ss-form-group"><label class="ss-label">Nama Fasilitas</label><input class="ss-input" name="name" required placeholder="Snorkeling Set / Life Jacket"></div>
            <div class="ss-form-group"><label class="ss-label">Kategori</label><input class="ss-input" name="category" placeholder="Snorkeling / Dokumentasi / Transport"></div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group"><label class="ss-label">Unit</label><input class="ss-input" name="unit" value="unit"></div>
                <div class="ss-form-group"><label class="ss-label">Stock</label><input class="ss-input" type="number" step="0.01" name="stock_qty" value="0"></div>
            </div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group"><label class="ss-label">Harga Modal</label><input class="ss-input" name="price_cost"></div>
                <div class="ss-form-group"><label class="ss-label">Harga Jual</label><input class="ss-input" name="price_sell"></div>
            </div>
            <div class="ss-form-group"><label class="ss-label">Status</label><select name="status" class="ss-select">
                    <option value="ready">Ready</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="unavailable">Unavailable</option>
                </select></div>
            <div class="ss-form-group"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes"></textarea></div>
            <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Fasilitas</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Fasilitas</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th>Stock</th>
                        <th>Harga</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['facility_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['name']); ?></td>
                            <td><?php echo htmlspecialchars($r['category'] ?: '-'); ?></td>
                            <td><?php echo rtrim(rtrim(number_format((float)$r['stock_qty'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($r['unit']); ?></td>
                            <td>Modal <?php echo sunseaRupiah((float)$r['price_cost']); ?><br><small style="color:var(--ss-muted)">Jual <?php echo sunseaRupiah((float)$r['price_sell']); ?></small></td>
                            <td><?php echo '<span class="ss-status ss-status-' . ($r['status'] === 'ready' ? 'approved' : ($r['status'] === 'maintenance' ? 'partial' : 'draft')) . '">' . ucfirst($r['status']) . '</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
