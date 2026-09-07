<?php

/** Sunsea - Database Transportasi Karimunjawa (Darat/Laut) */
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM transport_items WHERE id=?")->execute([$id]);
        $_SESSION['flash_message'] = 'Data transportasi dihapus.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: transport.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $d = [
        'transport_type' => ($_POST['transport_type'] ?? 'darat') === 'laut' ? 'laut' : 'darat',
        'name' => trim($_POST['name'] ?? ''),
        'unit' => trim($_POST['unit'] ?? 'trip'),
        'price_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
        'price_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($d['name'] === '') {
        $_SESSION['flash_message'] = 'Nama transportasi wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: transport.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE transport_items SET transport_type=?, name=?, unit=?, price_cost=?, price_sell=?, notes=?, is_active=?, updated_at=NOW() WHERE id=?")
                ->execute([$d['transport_type'], $d['name'], $d['unit'], $d['price_cost'], $d['price_sell'], $d['notes'], $d['is_active'], $id]);
        } else {
            $last = $pdo->query("SELECT transport_code FROM transport_items ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            $code = 'SS-TR-' . str_pad($next, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO transport_items (transport_code, transport_type, name, unit, price_cost, price_sell, notes, is_active) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$code, $d['transport_type'], $d['name'], $d['unit'], $d['price_cost'], $d['price_sell'], $d['notes'], $d['is_active']]);
        }
        $_SESSION['flash_message'] = 'Data transportasi tersimpan.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan transportasi: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    header('Location: transport.php');
    exit;
}

$rows = [];
$dbError = '';
try {
    $rows = $pdo->query("SELECT * FROM transport_items ORDER BY is_active DESC, transport_type, name")->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM transport_items WHERE id=?");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch();
}

$pageTitle = 'Database Transportasi Karimunjawa';
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
        <div class="ss-card-title" style="margin-bottom:12px;"><?php echo $editRow ? 'Edit Transportasi' : 'Input Transportasi'; ?></div>
        <div style="font-size:11.5px;color:var(--ss-muted);margin-bottom:10px;">Kategori <strong>Laut</strong> akan muncul sebagai pilihan Tiket Kapal saat susun paket. Isi hanya tiket kapal/speedboat/perahu asli (bukan nama kategori trip seperti Open Trip/Private Trip).</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $editRow['id'] ?? 0; ?>">
            <div class="ss-form-group"><label class="ss-label">Kategori</label><select name="transport_type" class="ss-select">
                    <option value="darat" <?php echo ($editRow['transport_type'] ?? 'darat') === 'darat' ? 'selected' : ''; ?>>Darat (mobil/motor jemput, trip darat)</option>
                    <option value="laut" <?php echo ($editRow['transport_type'] ?? '') === 'laut' ? 'selected' : ''; ?>>Laut (kapal/speedboat trip)</option>
                </select></div>
            <div class="ss-form-group"><label class="ss-label">Nama</label><input class="ss-input" name="name" required placeholder="Mobil Jemput Pelabuhan / Antar-Jemput / Mobil Trip Darat" value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>"></div>
            <div class="ss-form-group"><label class="ss-label">Satuan</label><input class="ss-input" name="unit" value="<?php echo htmlspecialchars($editRow['unit'] ?? 'trip'); ?>"></div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group"><label class="ss-label">Harga Modal</label><input class="ss-input" name="price_cost" placeholder="150000" value="<?php echo htmlspecialchars($editRow['price_cost'] ?? ''); ?>"></div>
                <div class="ss-form-group"><label class="ss-label">Harga Jual</label><input class="ss-input" name="price_sell" placeholder="200000" value="<?php echo htmlspecialchars($editRow['price_sell'] ?? ''); ?>"></div>
            </div>
            <div class="ss-form-group"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes"><?php echo htmlspecialchars($editRow['notes'] ?? ''); ?></textarea></div>
            <div class="ss-form-group"><label><input type="checkbox" name="is_active" <?php echo ($editRow['is_active'] ?? 1) ? 'checked' : ''; ?>> Aktif</label></div>
            <div style="display:flex;gap:8px;">
                <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> <?php echo $editRow ? 'Simpan Perubahan' : 'Simpan Transportasi'; ?></button>
                <?php if ($editRow): ?><a href="transport.php" class="ss-btn ss-btn-outline">Batal</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Transportasi</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Kategori</th>
                        <th>Harga</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['transport_code'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($r['name']); ?><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['unit']); ?></small></td>
                            <td><?php echo $r['transport_type'] === 'laut' ? 'Laut' : 'Darat'; ?></td>
                            <td>Modal <?php echo sunseaRupiah((float)$r['price_cost']); ?><br><small style="color:var(--ss-muted)">Jual <?php echo sunseaRupiah((float)$r['price_sell']); ?></small></td>
                            <td><?php echo $r['is_active'] ? '<span class="ss-status ss-status-approved">Aktif</span>' : '<span class="ss-status ss-status-draft">Nonaktif</span>'; ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <a href="transport.php?edit=<?php echo $r['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="Edit"><i data-feather="edit-2"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus <?php echo htmlspecialchars(addslashes($r['name'])); ?>?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#dc2626;border-color:#dc2626;" title="Hapus"><i data-feather="trash-2"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
