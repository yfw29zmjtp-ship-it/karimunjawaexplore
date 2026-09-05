<?php

/**
 * Sunsea - Paket Wisata (Trip Packages)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo    = getSunseaConnection();
sunseaEnsurePackageItemsSchema($pdo);
$action = $_GET['action'] ?? 'list';
$pkgId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$packageItemTypes = [
    'tiket_kapal' => 'Tiket Kapal',
    'penginapan'  => 'Penginapan',
    'transport'   => 'Transport / Rental',
    'guide'       => 'Guide/Pemandu',
    'catering'    => 'Catering/Konsumsi',
    'fasilitas'   => 'Fasilitas',
    'dokumentasi' => 'Dokumentasi',
    'lainnya'     => 'Lainnya',
];

// ---- HANDLE POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'name'            => trim($_POST['name'] ?? ''),
            'category'        => $_POST['category'] ?? 'open_trip',
            'duration_days'   => (int)($_POST['duration_days']   ?? 1),
            'duration_nights' => (int)($_POST['duration_nights'] ?? 0),
            'min_pax'         => (int)($_POST['min_pax'] ?? 1),
            'max_pax'         => (int)($_POST['max_pax'] ?? 20),
            'base_price'      => (float)str_replace(['.', ','], ['', '.'], $_POST['base_price'] ?? '0'),
            'description'     => trim($_POST['description'] ?? ''),
            'includes'        => trim($_POST['includes'] ?? ''),
            'excludes'        => trim($_POST['excludes'] ?? ''),
            'itinerary'       => trim($_POST['itinerary'] ?? ''),
            'notes'           => trim($_POST['notes'] ?? ''),
            'is_active'       => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (empty($data['name'])) {
            $_SESSION['flash_message'] = 'Nama paket wajib diisi.';
            $_SESSION['flash_type']    = 'error';
            header('Location: packages.php?action=' . ($id > 0 ? "edit&id=$id" : 'add'));
            exit;
        }

        if ($id > 0) {
            $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($data)));
            $pdo->prepare("UPDATE trip_packages SET $set, updated_at=NOW() WHERE id=?")
                ->execute([...array_values($data), $id]);
            $_SESSION['flash_message'] = 'Paket berhasil diperbarui.';
        } else {
            $lastCode = $pdo->query("SELECT code FROM trip_packages ORDER BY id DESC LIMIT 1")->fetchColumn();
            $nextNum  = 1;
            if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) $nextNum = (int)$m[1] + 1;
            $data['code'] = 'SS-PKG-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
            $vals = implode(', ', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO trip_packages ($cols) VALUES ($vals)")->execute(array_values($data));
            $_SESSION['flash_message'] = 'Paket baru berhasil ditambahkan.';
        }
        $_SESSION['flash_type'] = 'success';
        header('Location: packages.php');
        exit;
    } elseif ($postAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE trip_packages SET is_active = !is_active WHERE id=?")->execute([$id]);
        header('Location: packages.php');
        exit;
    } elseif ($postAction === 'save_package_item') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $itemName  = trim($_POST['item_name'] ?? '');
        if ($packageId > 0 && $itemName !== '') {
            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM trip_package_items WHERE package_id=?");
            $sortStmt->execute([$packageId]);
            $nextSort = (int)$sortStmt->fetchColumn();
            $pdo->prepare("INSERT INTO trip_package_items
                (package_id, item_type, item_name, cost_basis, estimated_cost, notes, sort_order)
                VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $packageId,
                    $_POST['item_type'] ?? 'lainnya',
                    $itemName,
                    ($_POST['cost_basis'] ?? 'per_pax') === 'flat' ? 'flat' : 'per_pax',
                    (float)str_replace(['.', ','], ['', '.'], $_POST['estimated_cost'] ?? '0'),
                    trim($_POST['notes'] ?? ''),
                    $nextSort,
                ]);
            $_SESSION['flash_message'] = 'Layanan paket berhasil ditambahkan.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: packages.php?action=edit&id=' . $packageId);
        exit;
    } elseif ($postAction === 'delete_package_item') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $itemId    = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $pdo->prepare("DELETE FROM trip_package_items WHERE id=? AND package_id=?")->execute([$itemId, $packageId]);
            $_SESSION['flash_message'] = 'Layanan paket berhasil dihapus.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: packages.php?action=edit&id=' . $packageId);
        exit;
    }
}

// ---- LOAD DATA ----
$editPkg = null;
$packageItems = [];
if (in_array($action, ['edit', 'view']) && $pkgId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM trip_packages WHERE id=?");
    $stmt->execute([$pkgId]);
    $editPkg = $stmt->fetch();
    if (!$editPkg) {
        header('Location: packages.php');
        exit;
    }
    $itemsStmt = $pdo->prepare("SELECT * FROM trip_package_items WHERE package_id=? ORDER BY sort_order");
    $itemsStmt->execute([$pkgId]);
    $packageItems = $itemsStmt->fetchAll();
}

$categoryMap = [
    'open_trip'   => ['label' => 'Open Trip',    'icon' => '🌍'],
    'private_trip' => ['label' => 'Private Trip',  'icon' => '👥'],
    'snorkeling'  => ['label' => 'Snorkeling',    'icon' => '🤿'],
    'diving'      => ['label' => 'Diving',        'icon' => '🐠'],
    'island_tour' => ['label' => 'Island Tour',   'icon' => '🏝️'],
    'custom'      => ['label' => 'Custom',        'icon' => '✨'],
];

$packages = $pdo->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM quotations WHERE package_id=p.id) as used_count
    FROM trip_packages p
    ORDER BY p.is_active DESC, p.name
")->fetchAll();

$pageTitle  = in_array($action, ['add', 'edit']) ? ($editPkg ? 'Edit Paket' : 'Tambah Paket Baru') : 'Paket Wisata';
$activePage = 'packages';

include 'layout-header.php';
?>

<?php if (in_array($action, ['add', 'edit'])): ?>
    <!-- ============ FORM ============ -->
    <div style="max-width:750px;">
        <div style="margin-bottom:20px;">
            <a href="packages.php" class="ss-btn ss-btn-outline ss-btn-sm">
                <i data-feather="arrow-left"></i> Kembali
            </a>
        </div>

        <div class="ss-card">
            <div class="ss-card-header">
                <div>
                    <div class="ss-card-title"><?php echo $editPkg ? 'Edit Paket Wisata' : 'Tambah Paket Baru'; ?></div>
                    <div class="ss-card-sub"><?php echo $editPkg ? htmlspecialchars($editPkg['code']) : 'Kode otomatis'; ?></div>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editPkg['id'] ?? 0; ?>">

                <div class="ss-form-grid cols-2">
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Nama Paket *</label>
                        <input type="text" name="name" class="ss-input" required
                            value="<?php echo htmlspecialchars($editPkg['name'] ?? ''); ?>"
                            placeholder="Contoh: Karimunjawa Open Trip 3D2N">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Kategori</label>
                        <select name="category" class="ss-select">
                            <?php foreach ($categoryMap as $v => $info): ?>
                                <option value="<?php echo $v; ?>" <?php echo ($editPkg['category'] ?? 'open_trip') === $v ? 'selected' : ''; ?>>
                                    <?php echo $info['icon'] . ' ' . $info['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Harga Dasar / Orang (Rp)</label>
                        <input type="text" name="base_price" class="ss-input"
                            value="<?php echo number_format($editPkg['base_price'] ?? 0, 0, ',', '.'); ?>"
                            placeholder="0" id="basePriceInput">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Durasi (Hari)</label>
                        <input type="number" name="duration_days" class="ss-input" min="1"
                            value="<?php echo $editPkg['duration_days'] ?? 1; ?>">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Durasi (Malam)</label>
                        <input type="number" name="duration_nights" class="ss-input" min="0"
                            value="<?php echo $editPkg['duration_nights'] ?? 0; ?>">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Min. Peserta</label>
                        <input type="number" name="min_pax" class="ss-input" min="1"
                            value="<?php echo $editPkg['min_pax'] ?? 1; ?>">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Maks. Peserta</label>
                        <input type="number" name="max_pax" class="ss-input" min="1"
                            value="<?php echo $editPkg['max_pax'] ?? 20; ?>">
                    </div>
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Deskripsi Paket</label>
                        <textarea name="description" class="ss-textarea" rows="3"
                            placeholder="Deskripsi singkat paket wisata ini"><?php echo htmlspecialchars($editPkg['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Sudah Termasuk (Include)</label>
                        <textarea name="includes" class="ss-textarea" rows="5"
                            placeholder="- Transportasi laut&#10;- Penginapan&#10;- Makan 3x sehari&#10;- Guide lokal"><?php echo htmlspecialchars($editPkg['includes'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Belum Termasuk (Exclude)</label>
                        <textarea name="excludes" class="ss-textarea" rows="5"
                            placeholder="- Tiket kereta/pesawat ke Semarang&#10;- Pengeluaran pribadi&#10;- Tips guide"><?php echo htmlspecialchars($editPkg['excludes'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Itinerary (Jadwal Perjalanan)</label>
                        <textarea name="itinerary" class="ss-textarea" rows="6"
                            placeholder="Hari 1: Check-in, Snorkeling spot A&#10;Hari 2: Island hopping&#10;Hari 3: Check-out"><?php echo htmlspecialchars($editPkg['itinerary'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Catatan Tambahan</label>
                        <textarea name="notes" class="ss-textarea" rows="2"><?php echo htmlspecialchars($editPkg['notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1"
                                <?php echo ($editPkg['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="ss-label" style="margin:0;">Paket Aktif (ditampilkan saat buat penawaran)</span>
                        </label>
                    </div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                    <a href="packages.php" class="ss-btn ss-btn-outline">Batal</a>
                    <button type="submit" class="ss-btn ss-btn-primary">
                        <i data-feather="save"></i> <?php echo $editPkg ? 'Simpan Perubahan' : 'Tambah Paket'; ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($editPkg): ?>
            <div class="ss-card" style="margin-top:16px;">
                <div class="ss-card-header">
                    <div>
                        <div class="ss-card-title">Detail Layanan dalam Paket</div>
                        <div class="ss-card-sub">Isi tiket kapal, penginapan, transport, guide, dll agar tagihan mitra yang belum dibayar akurat saat booking memakai paket ini.</div>
                    </div>
                </div>

                <?php if (empty($packageItems)): ?>
                    <div style="font-size:12.5px;color:var(--ss-muted);margin-bottom:10px;">Belum ada detail layanan. Tambahkan minimal tiket kapal, penginapan, dan transport supaya checklist pembayaran mitra bisa dihitung otomatis.</div>
                <?php else: ?>
                    <div class="ss-table-wrap" style="margin-bottom:12px;">
                        <table class="ss-table">
                            <thead>
                                <tr>
                                    <th>Tipe</th>
                                    <th>Nama Layanan</th>
                                    <th>Basis Biaya</th>
                                    <th>Estimasi Modal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($packageItems as $pi): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($packageItemTypes[$pi['item_type']] ?? $pi['item_type']); ?></td>
                                        <td><?php echo htmlspecialchars($pi['item_name']); ?><?php if (!empty($pi['notes'])): ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($pi['notes']); ?></small><?php endif; ?></td>
                                        <td><?php echo $pi['cost_basis'] === 'flat' ? 'Flat (sekali)' : 'Per Pax'; ?></td>
                                        <td><?php echo sunseaRupiah((float)$pi['estimated_cost']); ?></td>
                                        <td>
                                            <form method="POST" onsubmit="return confirm('Hapus layanan ini dari paket?');">
                                                <input type="hidden" name="action" value="delete_package_item">
                                                <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$pi['id']; ?>">
                                                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#dc2626;border-color:#dc2626;"><i data-feather="trash-2"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="ss-card-title" style="margin:10px 0 8px;font-size:13px;">+ Tambah Layanan</div>
                <form method="POST">
                    <input type="hidden" name="action" value="save_package_item">
                    <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                    <div class="ss-form-grid cols-2">
                        <div class="ss-form-group">
                            <label class="ss-label">Tipe Layanan</label>
                            <select name="item_type" class="ss-select">
                                <?php foreach ($packageItemTypes as $v => $label): ?>
                                    <option value="<?php echo $v; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">Nama Layanan *</label>
                            <input type="text" name="item_name" class="ss-input" required placeholder="Contoh: Tiket Kapal Express PP">
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">Basis Biaya</label>
                            <select name="cost_basis" class="ss-select">
                                <option value="per_pax">Per Pax (dikali jumlah tamu)</option>
                                <option value="flat">Flat (sekali per booking)</option>
                            </select>
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">Estimasi Modal (Rp)</label>
                            <input type="text" name="estimated_cost" class="ss-input" placeholder="0">
                        </div>
                        <div class="ss-form-group" style="grid-column:1/-1;">
                            <label class="ss-label">Catatan</label>
                            <input type="text" name="notes" class="ss-input" placeholder="Contoh: dibayar ke mitra kapal Bahari Express">
                        </div>
                    </div>
                    <button type="submit" class="ss-btn ss-btn-primary ss-btn-sm" style="margin-top:6px;"><i data-feather="plus"></i> Tambah Layanan</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ============ LIST ============ -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Paket Wisata</div>
                <div class="ss-card-sub"><?php echo count($packages); ?> paket tersedia</div>
            </div>
            <a href="packages.php?action=add" class="ss-btn ss-btn-primary">
                <i data-feather="plus"></i> Tambah Paket
            </a>
        </div>

        <?php if (empty($packages)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">🏝️</div>
                <h3>Belum ada paket wisata</h3>
                <p>Tambahkan paket wisata untuk digunakan saat membuat penawaran</p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
                <?php foreach ($packages as $pkg): ?>
                    <div class="ss-card" style="padding:20px;box-shadow:none;border:1.5px solid var(--ss-gray-2);
                opacity:<?php echo $pkg['is_active'] ? '1' : '0.55'; ?>;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                            <div>
                                <div style="font-size:20px;margin-bottom:4px;">
                                    <?php echo $categoryMap[$pkg['category']]['icon'] ?? '🏝️'; ?>
                                </div>
                                <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                <div style="font-size:11px;color:var(--ss-muted);margin-top:2px;">
                                    <?php echo $categoryMap[$pkg['category']]['label'] ?? ''; ?>
                                    · <?php echo $pkg['duration_days']; ?>H<?php echo $pkg['duration_nights']; ?>M
                                </div>
                            </div>
                            <span class="ss-badge <?php echo $pkg['is_active'] ? 'ss-badge-ocean' : ''; ?>"
                                style="<?php echo !$pkg['is_active'] ? 'background:#F1F5F9;color:#94A3B8' : ''; ?>">
                                <?php echo $pkg['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                            </span>
                        </div>

                        <div style="font-size:18px;font-weight:800;color:var(--ss-ocean);margin-bottom:8px;">
                            <?php echo sunseaRupiah((float)$pkg['base_price']); ?>
                            <span style="font-size:11px;font-weight:400;color:var(--ss-muted);">/ orang</span>
                        </div>

                        <div style="font-size:11px;color:var(--ss-muted);margin-bottom:14px;">
                            Min <?php echo $pkg['min_pax']; ?> - Max <?php echo $pkg['max_pax']; ?> pax
                            <?php if ($pkg['used_count'] > 0): ?>
                                · Dipakai <?php echo $pkg['used_count']; ?>x penawaran
                            <?php endif; ?>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <a href="packages.php?action=edit&id=<?php echo $pkg['id']; ?>"
                                class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="edit-2"></i> Edit</a>
                            <a href="quotations.php?action=add&package_id=<?php echo $pkg['id']; ?>"
                                class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="file-plus"></i> Buat Penawaran</a>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"
                                    title="<?php echo $pkg['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                    <i data-feather="<?php echo $pkg['is_active'] ? 'eye-off' : 'eye'; ?>"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
    // Format harga input
    var inp = document.getElementById('basePriceInput');
    if (inp) {
        inp.addEventListener('input', function() {
            var raw = this.value.replace(/\D/g, '');
            this.value = raw ? parseInt(raw).toLocaleString('id-ID') : '';
        });
        inp.addEventListener('blur', function() {
            var raw = this.value.replace(/\D/g, '');
            this.value = raw || '0';
        });
    }
</script>

<?php include 'layout-footer.php'; ?>