<?php

/**
 * Sunsea - Database Catering
 */
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
        'vendor_name' => trim($_POST['vendor_name'] ?? ''),
        'menu_name' => trim($_POST['menu_name'] ?? ''),
        'category' => trim($_POST['category'] ?? ''),
        'portion_unit' => trim($_POST['portion_unit'] ?? 'porsi'),
        'price_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
        'price_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
        'phone' => trim($_POST['phone'] ?? ''),
        'location' => trim($_POST['location'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($payload['vendor_name'] === '' || $payload['menu_name'] === '') {
        $_SESSION['flash_message'] = 'Nama vendor dan nama menu wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: catering.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE caterings
                SET vendor_name=?, menu_name=?, category=?, portion_unit=?, price_cost=?, price_sell=?, phone=?, location=?, notes=?, is_active=?, updated_at=NOW()
                WHERE id=?")
                ->execute([
                    $payload['vendor_name'],
                    $payload['menu_name'],
                    $payload['category'],
                    $payload['portion_unit'],
                    $payload['price_cost'],
                    $payload['price_sell'],
                    $payload['phone'],
                    $payload['location'],
                    $payload['notes'],
                    $payload['is_active'],
                    $id
                ]);
            $_SESSION['flash_message'] = 'Data catering berhasil diperbarui.';
        } else {
            $lastCode = $pdo->query("SELECT catering_code FROM caterings ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($lastCode && preg_match('/(\\d+)$/', $lastCode, $m)) {
                $next = (int)$m[1] + 1;
            }
            $code = 'SS-CT-' . str_pad($next, 3, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO caterings
                (catering_code, vendor_name, menu_name, category, portion_unit, price_cost, price_sell, phone, location, notes, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code,
                    $payload['vendor_name'],
                    $payload['menu_name'],
                    $payload['category'],
                    $payload['portion_unit'],
                    $payload['price_cost'],
                    $payload['price_sell'],
                    $payload['phone'],
                    $payload['location'],
                    $payload['notes'],
                    $payload['is_active']
                ]);
            $_SESSION['flash_message'] = 'Database catering berhasil ditambahkan.';
        }
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan catering: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: catering.php');
    exit;
}

$rows = [];
try {
    $rows = $pdo->query("SELECT * FROM caterings ORDER BY is_active DESC, vendor_name, menu_name")->fetchAll();
} catch (Exception $e) {
    $_SESSION['flash_message'] = 'Tabel caterings belum ada. Jalankan update database Explore Karimunjawa terbaru terlebih dahulu.';
    $_SESSION['flash_type'] = 'error';
}

$pageTitle = 'Database Catering';
$activePage = 'database';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:18px;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Catering</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <div class="ss-form-group"><label class="ss-label">Nama Vendor *</label><input class="ss-input" name="vendor_name" required placeholder="Catering Bu Sari"></div>
            <div class="ss-form-group"><label class="ss-label">Nama Menu *</label><input class="ss-input" name="menu_name" required placeholder="Paket Nasi Box Seafood"></div>
            <div class="ss-form-group"><label class="ss-label">Kategori</label><input class="ss-input" name="category" placeholder="Breakfast / Lunch / Dinner / Snack"></div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group"><label class="ss-label">Unit</label><input class="ss-input" name="portion_unit" value="porsi"></div>
                <div class="ss-form-group"><label class="ss-label">Telepon</label><input class="ss-input" name="phone"></div>
            </div>
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group"><label class="ss-label">Harga Modal</label><input class="ss-input" name="price_cost" placeholder="35000"></div>
                <div class="ss-form-group"><label class="ss-label">Harga Jual</label><input class="ss-input" name="price_sell" placeholder="45000"></div>
            </div>
            <div class="ss-form-group"><label class="ss-label">Lokasi</label><input class="ss-input" name="location" placeholder="Karimunjawa"></div>
            <div class="ss-form-group"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes" placeholder="Khusus group minimal 15 pax"></textarea></div>
            <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Catering</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Database Catering</div>
        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Vendor</th>
                        <th>Menu</th>
                        <th>Kategori</th>
                        <th>Kontak</th>
                        <th>Harga</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;color:var(--ss-muted);">Belum ada data catering.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['catering_code'] ?? '-'); ?></td>
                                <td><strong><?php echo htmlspecialchars($r['vendor_name']); ?></strong><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['location'] ?: '-'); ?></small></td>
                                <td><?php echo htmlspecialchars($r['menu_name']); ?><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['portion_unit']); ?></small></td>
                                <td><?php echo htmlspecialchars($r['category'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['phone'] ?: '-'); ?></td>
                                <td>Modal <?php echo sunseaRupiah((float)$r['price_cost']); ?><br><small style="color:var(--ss-muted)">Jual <?php echo sunseaRupiah((float)$r['price_sell']); ?></small></td>
                                <td><?php echo $r['is_active'] ? '<span class="ss-status ss-status-approved">Aktif</span>' : '<span class="ss-status ss-status-draft">Nonaktif</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
