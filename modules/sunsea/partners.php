<?php

/**
 * Sunsea - Mitra Penginapan (Hotel & Homestay)
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
sunseaEnsureAccommodationSchema($pdo);

$pageError = '';

function safeFetchAllPartners(PDO $pdo, string $sql, array $params = [], string $context = ''): array
{
    global $pageError;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        if ($pageError === '') {
            $pageError = 'Gagal memuat data ' . ($context ?: 'penginapan') . ': ' . $e->getMessage();
        }
        return [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_partner') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'partner_type' => $_POST['partner_type'] ?? 'hotel',
            'name' => trim($_POST['name'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'location' => trim($_POST['location'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($id > 0) {
            $pdo->prepare("UPDATE accommodation_partners
                SET partner_type=?, name=?, contact_person=?, phone=?, email=?, address=?, location=?, notes=?, is_active=?, updated_at=NOW()
                WHERE id=?")
                ->execute([$data['partner_type'], $data['name'], $data['contact_person'], $data['phone'], $data['email'], $data['address'], $data['location'], $data['notes'], $data['is_active'], $id]);
            $_SESSION['flash_message'] = 'Mitra berhasil diperbarui.';
        } else {
            $last = $pdo->query("SELECT partner_code FROM accommodation_partners ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($last && preg_match('/(\\d+)$/', $last, $m)) $next = (int)$m[1] + 1;
            $code = 'SS-ACC-' . str_pad($next, 3, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO accommodation_partners
                (partner_code, partner_type, name, contact_person, phone, email, address, location, notes, is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$code, $data['partner_type'], $data['name'], $data['contact_person'], $data['phone'], $data['email'], $data['address'], $data['location'], $data['notes'], $data['is_active']]);
            $_SESSION['flash_message'] = 'Mitra baru berhasil ditambahkan.';
        }
        $_SESSION['flash_type'] = 'success';
        header('Location: partners.php');
        exit;
    }

    if ($action === 'save_room') {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $roomType = trim($_POST['room_type'] ?? '');
        if ($partnerId > 0 && $roomType !== '') {
            $pdo->prepare("INSERT INTO accommodation_rooms
                (partner_id, room_type, capacity, price_cost, price_sell, quota, notes, is_active)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $partnerId,
                    $roomType,
                    max(1, (int)($_POST['capacity'] ?? 2)),
                    (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
                    (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
                    max(0, (int)($_POST['quota'] ?? 0)),
                    trim($_POST['notes'] ?? ''),
                    isset($_POST['is_active']) ? 1 : 0,
                ]);
            $_SESSION['flash_message'] = 'Tipe kamar/homestay berhasil ditambahkan.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: partners.php');
        exit;
    }
}

$partners = safeFetchAllPartners($pdo, "SELECT * FROM accommodation_partners ORDER BY is_active DESC, partner_type, name", [], 'mitra penginapan');
$rooms = safeFetchAllPartners(
    $pdo,
    "SELECT r.*, p.name AS partner_name, p.partner_type
    FROM accommodation_rooms r
    JOIN accommodation_partners p ON p.id = r.partner_id
    ORDER BY p.name, r.room_type",
    [],
    'kamar penginapan'
);

$pageTitle = 'Database Hotel & Homestay';
$activePage = 'database';
include 'layout-header.php';
?>

<?php if ($pageError): ?>
    <div class="ss-alert ss-alert-error" style="margin-bottom:14px;">
        <i data-feather="alert-triangle"></i>
        <?php echo htmlspecialchars($pageError); ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Input Mitra Penginapan</div>
                <div class="ss-card-sub">Database Hotel & Homestay</div>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_partner">
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group">
                    <label class="ss-label">Tipe Mitra</label>
                    <select name="partner_type" class="ss-select">
                        <option value="hotel">Hotel</option>
                        <option value="homestay">Homestay</option>
                    </select>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Nama Mitra</label>
                    <input class="ss-input" name="name" required>
                </div>
                <div class="ss-form-group"><label class="ss-label">PIC</label><input class="ss-input" name="contact_person"></div>
                <div class="ss-form-group"><label class="ss-label">Telepon</label><input class="ss-input" name="phone"></div>
                <div class="ss-form-group"><label class="ss-label">Email</label><input class="ss-input" name="email"></div>
                <div class="ss-form-group"><label class="ss-label">Lokasi</label><input class="ss-input" name="location"></div>
                <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Alamat</label><textarea class="ss-textarea" name="address"></textarea></div>
                <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Catatan</label><textarea class="ss-textarea" name="notes"></textarea></div>
                <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            </div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan Mitra</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Input Tipe Kamar/Tarif</div>
                <div class="ss-card-sub">Inventaris tipe kamar dan harga</div>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_room">
            <div class="ss-form-grid cols-2">
                <div class="ss-form-group" style="grid-column:1/-1;">
                    <label class="ss-label">Mitra</label>
                    <select class="ss-select" name="partner_id" required>
                        <option value="">-- pilih mitra --</option>
                        <?php foreach ($partners as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name'] . ' (' . $p['partner_type'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ss-form-group"><label class="ss-label">Tipe Kamar</label><input class="ss-input" name="room_type" required></div>
                <div class="ss-form-group"><label class="ss-label">Kapasitas</label><input class="ss-input" name="capacity" type="number" value="2" min="1"></div>
                <div class="ss-form-group"><label class="ss-label">Harga Modal</label><input class="ss-input" name="price_cost"></div>
                <div class="ss-form-group"><label class="ss-label">Harga Jual</label><input class="ss-input" name="price_sell"></div>
                <div class="ss-form-group"><label class="ss-label">Quota</label><input class="ss-input" name="quota" type="number" value="0" min="0"></div>
                <div class="ss-form-group"><label class="ss-label">Catatan</label><input class="ss-input" name="notes"></div>
                <div class="ss-form-group"><label><input type="checkbox" name="is_active" checked> Aktif</label></div>
            </div>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="plus"></i> Tambah Room</button>
        </form>
    </div>
</div>

<div class="ss-card" style="margin-top:18px;">
    <div class="ss-card-title" style="margin-bottom:10px;">Daftar Mitra</div>
    <div class="ss-table-wrap">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Tipe</th>
                    <th>PIC</th>
                    <th>Kontak</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($partners as $p): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($p['partner_code'] ?? '-'); ?></td>
                        <td><strong><?php echo htmlspecialchars($p['name']); ?></strong><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($p['location'] ?? '-'); ?></small></td>
                        <td><?php echo ucfirst($p['partner_type']); ?></td>
                        <td><?php echo htmlspecialchars($p['contact_person'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($p['phone'] ?: '-'); ?></td>
                        <td><?php echo $p['is_active'] ? '<span class="ss-status ss-status-approved">Aktif</span>' : '<span class="ss-status ss-status-draft">Nonaktif</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ss-card" style="margin-top:18px;">
    <div class="ss-card-title" style="margin-bottom:10px;">Daftar Tipe Kamar / Homestay</div>
    <div class="ss-table-wrap">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Mitra</th>
                    <th>Tipe</th>
                    <th>Kapasitas</th>
                    <th>Harga Modal</th>
                    <th>Harga Jual</th>
                    <th>Quota</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rooms as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['partner_name']); ?><br><small style="color:var(--ss-muted)"><?php echo htmlspecialchars($r['partner_type']); ?></small></td>
                        <td><?php echo htmlspecialchars($r['room_type']); ?></td>
                        <td><?php echo (int)$r['capacity']; ?></td>
                        <td><?php echo sunseaRupiah((float)$r['price_cost']); ?></td>
                        <td><?php echo sunseaRupiah((float)$r['price_sell']); ?></td>
                        <td><?php echo (int)$r['quota']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout-footer.php';
