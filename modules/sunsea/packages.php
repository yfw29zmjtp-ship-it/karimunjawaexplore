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
sunseaEnsureMasterDataSchema($pdo);
sunseaEnsureAccommodationSchema($pdo);
sunseaEnsurePackageMediaSchema($pdo);
$action = $_GET['action'] ?? 'list';
$pkgId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$packageItemTypes = [
    'tiket_kapal'   => 'Tiket Kapal',
    'tiket_lainnya' => 'Tiket Lainnya (Pesawat/BTN/Retribusi)',
    'penginapan'    => 'Penginapan',
    'transport'     => 'Transport / Rental',
    'guide'         => 'Guide/Pemandu',
    'catering'      => 'Catering/Konsumsi',
    'fasilitas'     => 'Fasilitas',
    'dokumentasi'   => 'Dokumentasi',
    'lainnya'       => 'Lainnya',
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

        $uploadDir = __DIR__ . '/../../uploads/sunsea/website/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $coverUploadErr = '';
        if (!empty($_FILES['cover_image']['tmp_name'])) {
            if ((int)($_FILES['cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $coverUploadErr = 'Upload cover gagal (kode error: ' . $_FILES['cover_image']['error'] . ').';
            } else {
                $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $coverUploadErr = 'Format cover harus PNG, JPG, JPEG, WEBP, atau GIF.';
                } else {
                    $fname = 'pkgcover_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploadDir . $fname)) {
                        $data['cover_image'] = 'uploads/sunsea/website/' . $fname;
                    } else {
                        $coverUploadErr = 'Gagal menyimpan file cover ke server.';
                    }
                }
            }
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
            $id = (int)$pdo->lastInsertId();
            $_SESSION['flash_message'] = 'Paket baru berhasil ditambahkan.';
        }
        if ($coverUploadErr) {
            $_SESSION['flash_message'] .= ' Namun: ' . $coverUploadErr;
            $_SESSION['flash_type'] = 'error';
        } else {
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: packages.php?action=edit&id=' . $id);
        exit;
    } elseif ($postAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE trip_packages SET is_active = !is_active WHERE id=?")->execute([$id]);
        header('Location: packages.php');
        exit;
    } elseif ($postAction === 'move') {
        $id  = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $ordered = $pdo->query("SELECT id, display_order FROM trip_packages ORDER BY display_order, name")->fetchAll();
        $idx = array_search($id, array_column($ordered, 'id'));
        $swapIdx = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx !== false && $swapIdx >= 0 && $swapIdx < count($ordered)) {
            $a = $ordered[$idx];
            $b = $ordered[$swapIdx];
            $pdo->prepare("UPDATE trip_packages SET display_order=? WHERE id=?")->execute([$b['display_order'], $a['id']]);
            $pdo->prepare("UPDATE trip_packages SET display_order=? WHERE id=?")->execute([$a['display_order'], $b['id']]);
        }
        header('Location: packages.php');
        exit;
    } elseif ($postAction === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE package_id=?");
            $chk->execute([$id]);
            if ((int)$chk->fetchColumn() > 0) {
                $_SESSION['flash_message'] = 'Paket tidak bisa dihapus karena sudah dipakai di penawaran. Nonaktifkan saja paketnya.';
                $_SESSION['flash_type']    = 'error';
            } else {
                $pdo->prepare("DELETE FROM trip_package_items WHERE package_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM trip_packages WHERE id=?")->execute([$id]);
                $_SESSION['flash_message'] = 'Paket berhasil dihapus.';
                $_SESSION['flash_type']    = 'success';
            }
        }
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
                (package_id, item_type, item_name, cost_basis, estimated_cost, estimated_sell, notes, sort_order)
                VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $packageId,
                    $_POST['item_type'] ?? 'lainnya',
                    $itemName,
                    ($_POST['cost_basis'] ?? 'per_pax') === 'flat' ? 'flat' : 'per_pax',
                    (float)str_replace(['.', ','], ['', '.'], $_POST['estimated_cost'] ?? '0'),
                    (float)str_replace(['.', ','], ['', '.'], $_POST['estimated_sell'] ?? '0'),
                    trim($_POST['notes'] ?? ''),
                    $nextSort,
                ]);
            $_SESSION['flash_message'] = 'Layanan paket berhasil ditambahkan.';
            $_SESSION['flash_type'] = 'success';
        }
        header('Location: packages.php?action=edit&id=' . $packageId);
        exit;
    } elseif ($postAction === 'update_package_item') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $itemId    = (int)($_POST['item_id'] ?? 0);
        $itemName  = trim($_POST['item_name'] ?? '');
        if ($packageId > 0 && $itemId > 0 && $itemName !== '') {
            $pdo->prepare("UPDATE trip_package_items
                SET item_type=?, item_name=?, cost_basis=?, estimated_cost=?, estimated_sell=?, notes=?
                WHERE id=? AND package_id=?")
                ->execute([
                    $_POST['item_type'] ?? 'lainnya',
                    $itemName,
                    ($_POST['cost_basis'] ?? 'per_pax') === 'flat' ? 'flat' : 'per_pax',
                    (float)str_replace(['.', ','], ['', '.'], $_POST['estimated_cost'] ?? '0'),
                    (float)str_replace(['.', ','], ['', '.'], $_POST['estimated_sell'] ?? '0'),
                    trim($_POST['notes'] ?? ''),
                    $itemId,
                    $packageId,
                ]);
            $_SESSION['flash_message'] = 'Harga layanan paket berhasil diperbarui.';
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
    } elseif ($postAction === 'gallery_add') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $uploadDir = __DIR__ . '/../../uploads/sunsea/website/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        if ($packageId > 0 && !empty($_FILES['gallery_image']['tmp_name'])) {
            if ((int)($_FILES['gallery_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $_SESSION['flash_message'] = 'Upload foto galeri gagal (kode error: ' . $_FILES['gallery_image']['error'] . ').';
                $_SESSION['flash_type'] = 'error';
            } else {
                $ext = strtolower(pathinfo($_FILES['gallery_image']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $_SESSION['flash_message'] = 'Format foto galeri harus PNG, JPG, JPEG, WEBP, atau GIF.';
                    $_SESSION['flash_type'] = 'error';
                } else {
                    $fname = 'pkggallery_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['gallery_image']['tmp_name'], $uploadDir . $fname)) {
                        $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM trip_package_gallery WHERE package_id=?");
                        $sortStmt->execute([$packageId]);
                        $pdo->prepare("INSERT INTO trip_package_gallery (package_id, image_path, caption, sort_order) VALUES (?,?,?,?)")
                            ->execute([$packageId, 'uploads/sunsea/website/' . $fname, trim($_POST['caption'] ?? ''), (int)$sortStmt->fetchColumn()]);
                        $_SESSION['flash_message'] = 'Foto galeri paket berhasil ditambahkan.';
                        $_SESSION['flash_type'] = 'success';
                    } else {
                        $_SESSION['flash_message'] = 'Gagal menyimpan foto galeri ke server.';
                        $_SESSION['flash_type'] = 'error';
                    }
                }
            }
        }
        header('Location: packages.php?action=edit&id=' . $packageId);
        exit;
    } elseif ($postAction === 'gallery_delete') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $galleryId = (int)($_POST['gallery_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT image_path FROM trip_package_gallery WHERE id=? AND package_id=?");
        $stmt->execute([$galleryId, $packageId]);
        $old = $stmt->fetchColumn();
        if ($old && file_exists(__DIR__ . '/../../' . $old)) @unlink(__DIR__ . '/../../' . $old);
        $pdo->prepare("DELETE FROM trip_package_gallery WHERE id=? AND package_id=?")->execute([$galleryId, $packageId]);
        $_SESSION['flash_message'] = 'Foto galeri paket dihapus.';
        $_SESSION['flash_type'] = 'success';
        header('Location: packages.php?action=edit&id=' . $packageId);
        exit;
    }
}

// ---- LOAD DATA ----
$editPkg = null;
$packageItems = [];
$packageGallery = [];
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

    $galleryStmt = $pdo->prepare("SELECT * FROM trip_package_gallery WHERE package_id=? ORDER BY sort_order, id");
    $galleryStmt->execute([$pkgId]);
    $packageGallery = $galleryStmt->fetchAll();
}

$pkgItemsCostTotal = 0.0;
$pkgItemsSellTotal = 0.0;
foreach ($packageItems as $pi) {
    $pkgItemsCostTotal += (float)$pi['estimated_cost'];
    $pkgItemsSellTotal += (float)$pi['estimated_sell'];
}
$pkgItemsMargin = $pkgItemsSellTotal - $pkgItemsCostTotal;

// ---- MASTER DATA (untuk dropdown referensi harga otomatis) ----
$masterItemOptions = []; // grouped by item_type => [ [label, cost, sell, name], ... ]
try {
    $g = $pdo->query("SELECT name, daily_rate_cost AS cost, daily_rate_sell AS sell FROM guides WHERE is_active=1 ORDER BY name")->fetchAll();
    foreach ($g as $r) {
        $masterItemOptions['guide'][] = ['label' => $r['name'], 'name' => $r['name'], 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
    }
    $c = $pdo->query("SELECT vendor_name, menu_name, price_cost AS cost, price_sell AS sell FROM caterings WHERE is_active=1 ORDER BY vendor_name")->fetchAll();
    foreach ($c as $r) {
        $label = $r['vendor_name'] . ' - ' . $r['menu_name'];
        $masterItemOptions['catering'][] = ['label' => $label, 'name' => $label, 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
    }
    $f = $pdo->query("SELECT name, price_cost AS cost, price_sell AS sell FROM facilities WHERE is_active=1 ORDER BY name")->fetchAll();
    foreach ($f as $r) {
        $masterItemOptions['fasilitas'][] = ['label' => $r['name'], 'name' => $r['name'], 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
    }
    $t = $pdo->query("SELECT name, transport_type, price_cost AS cost, price_sell AS sell FROM transport_items WHERE is_active=1 ORDER BY name")->fetchAll();
    foreach ($t as $r) {
        $masterItemOptions['transport'][] = ['label' => $r['name'], 'name' => $r['name'], 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
        if ($r['transport_type'] === 'laut') {
            $masterItemOptions['tiket_kapal'][] = ['label' => $r['name'], 'name' => $r['name'], 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
        }
    }
    $tk = $pdo->query("SELECT ticket_type, ticket_name, price_cost AS cost, price_sell AS sell FROM tickets WHERE is_active=1 ORDER BY ticket_name")->fetchAll();
    $tiketKapalTypes = ['express_bahari', 'ferry'];
    foreach ($tk as $r) {
        $itemType = in_array($r['ticket_type'], $tiketKapalTypes, true) ? 'tiket_kapal' : 'tiket_lainnya';
        $masterItemOptions[$itemType][] = ['label' => $r['ticket_name'], 'name' => $r['ticket_name'], 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
    }
    $a = $pdo->query("SELECT ap.name AS partner_name, ar.room_type, ar.price_cost AS cost, ar.price_sell AS sell
                       FROM accommodation_rooms ar JOIN accommodation_partners ap ON ap.id = ar.partner_id
                       WHERE ar.is_active=1 AND ap.is_active=1 ORDER BY ap.name")->fetchAll();
    foreach ($a as $r) {
        $label = $r['partner_name'] . ' - ' . $r['room_type'];
        $masterItemOptions['penginapan'][] = ['label' => $label, 'name' => $label, 'cost' => (float)$r['cost'], 'sell' => (float)$r['sell']];
    }
} catch (Exception $e) {
    error_log('packages.php masterItemOptions error: ' . $e->getMessage());
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
    ORDER BY p.is_active DESC, p.display_order, p.name
")->fetchAll();

$pageTitle  = in_array($action, ['add', 'edit']) ? ($editPkg ? 'Edit Paket' : 'Tambah Paket Baru') : 'Paket Wisata';
$activePage = 'packages';

include 'layout-header.php';
?>

<?php if (in_array($action, ['add', 'edit'])): ?>
    <!-- ============ FORM ============ -->
    <div style="margin-bottom:20px;">
        <a href="packages.php" class="ss-btn ss-btn-outline ss-btn-sm">
            <i data-feather="arrow-left"></i> Kembali
        </a>
    </div>

    <div style="display:grid;grid-template-columns:<?php echo $editPkg ? '1fr 1fr' : '1fr'; ?>;gap:20px;align-items:start;">
        <div>
            <div class="ss-card">
                <div class="ss-card-header">
                    <div>
                        <div class="ss-card-title"><?php echo $editPkg ? 'Edit Paket Wisata' : 'Tambah Paket Baru'; ?></div>
                        <div class="ss-card-sub"><?php echo $editPkg ? htmlspecialchars($editPkg['code']) : 'Kode otomatis'; ?></div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo $editPkg['id'] ?? 0; ?>">

                    <div class="ss-form-grid cols-2">
                        <div class="ss-form-group" style="grid-column:1/-1;">
                            <label class="ss-label">Foto Cover Paket</label>
                            <?php if (!empty($editPkg['cover_image'])): ?>
                                <img src="<?php echo htmlspecialchars(sunseaAssetUrl($editPkg['cover_image'])); ?>" alt="" style="width:100%;max-height:160px;object-fit:cover;border-radius:8px;margin-bottom:8px;display:block;">
                            <?php endif; ?>
                            <input type="file" name="cover_image" accept="image/*" class="ss-input">
                            <div style="font-size:11px;margin-top:4px;color:var(--ss-muted);">Foto ini tampil di kartu paket &amp; halaman detail di website publik. Kosongkan kalau tidak ingin ganti.</div>
                        </div>
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
                            <?php if ($editPkg && !empty($packageItems)): ?>
                                <div style="font-size:11px;margin-top:4px;color:var(--ss-muted);">
                                    Total Modal Aktual (dari Detail Layanan): <strong style="color:#dc2626;"><?php echo sunseaRupiah($pkgItemsCostTotal); ?></strong>
                                    · Margin saat ini: <strong style="color:<?php echo ((float)($editPkg['base_price'] ?? 0) - $pkgItemsCostTotal) < 0 ? '#dc2626' : '#16a34a'; ?>;"><?php echo sunseaRupiah((float)($editPkg['base_price'] ?? 0) - $pkgItemsCostTotal); ?></strong>
                                </div>
                            <?php endif; ?>
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

        </div>

        <div>
            <?php if ($editPkg): ?>
                <div class="ss-card" style="margin-bottom:20px;">
                    <div class="ss-card-header">
                        <div>
                            <div class="ss-card-title">Galeri Foto Trip</div>
                            <div class="ss-card-sub">Foto-foto ini tampil di halaman detail paket pada website publik.</div>
                        </div>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
                        <input type="hidden" name="action" value="gallery_add">
                        <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                        <div style="flex:1;min-width:160px;">
                            <label class="ss-label">File Foto</label>
                            <input type="file" name="gallery_image" accept="image/*" class="ss-input" required>
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label class="ss-label">Keterangan</label>
                            <input type="text" name="caption" class="ss-input" placeholder="Opsional">
                        </div>
                        <button type="submit" class="ss-btn ss-btn-primary ss-btn-sm">
                            <i data-feather="plus"></i> Tambah
                        </button>
                    </form>
                    <?php if (!$packageGallery): ?>
                        <div style="font-size:12px;color:var(--ss-muted);">Belum ada foto galeri untuk paket ini.</div>
                    <?php else: ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:10px;">
                            <?php foreach ($packageGallery as $g): ?>
                                <div style="border:1px solid var(--ss-border);border-radius:8px;overflow:hidden;">
                                    <img src="<?php echo htmlspecialchars(sunseaAssetUrl($g['image_path'])); ?>" alt="" style="width:100%;height:80px;object-fit:cover;display:block;">
                                    <form method="POST" onsubmit="return confirm('Hapus foto ini?');" style="padding:4px;">
                                        <input type="hidden" name="action" value="gallery_delete">
                                        <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                                        <input type="hidden" name="gallery_id" value="<?php echo (int)$g['id']; ?>">
                                        <button type="submit" style="width:100%;font-size:11px;color:#b91c1c;background:none;border:none;cursor:pointer;">Hapus</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ss-card">
                    <div class="ss-card-header">
                        <div>
                            <div class="ss-card-title">Detail Layanan dalam Paket</div>
                            <div class="ss-card-sub">Isi tiket kapal, penginapan, transport, guide, dll agar tagihan mitra yang belum dibayar akurat saat booking memakai paket ini.</div>
                        </div>
                    </div>
                    <script>
                        var pkgMasterOptions = <?php echo json_encode($masterItemOptions); ?>;

                        function pkgRefreshOptions(prefix) {
                            var typeSel = document.getElementById(prefix + '_item_type');
                            var refSel = document.getElementById(prefix + '_item_ref');
                            if (!typeSel || !refSel) return;
                            var list = pkgMasterOptions[typeSel.value] || [];
                            refSel.innerHTML = '<option value="">-- Pilih manual --</option>';
                            list.forEach(function(item, idx) {
                                var opt = document.createElement('option');
                                opt.value = idx;
                                opt.textContent = item.label + ' (Modal Rp' + item.cost.toLocaleString('id-ID') + ' / Jual Rp' + item.sell.toLocaleString('id-ID') + ')';
                                opt.dataset.name = item.name;
                                opt.dataset.cost = item.cost;
                                opt.dataset.sell = item.sell;
                                refSel.appendChild(opt);
                            });
                        }

                        function pkgApplyRef(prefix) {
                            var refSel = document.getElementById(prefix + '_item_ref');
                            var opt = refSel.options[refSel.selectedIndex];
                            if (!opt || opt.dataset.name === undefined) return;
                            document.getElementById(prefix + '_item_name').value = opt.dataset.name;
                            document.getElementById(prefix + '_item_cost').value = opt.dataset.cost;
                            document.getElementById(prefix + '_item_sell').value = opt.dataset.sell;
                        }

                        function togglePkgItemEdit(id) {
                            var editRow = document.getElementById('pkg-item-edit-' + id);
                            if (!editRow) return;
                            var opening = editRow.style.display === 'none';
                            editRow.style.display = opening ? 'table-row' : 'none';
                            if (opening) pkgRefreshOptions('edit' + id);
                        }
                        document.addEventListener('DOMContentLoaded', function() {
                            pkgRefreshOptions('add');
                        });
                    </script>

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
                                        <th>Harga Jual</th>
                                        <th>Margin</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($packageItems as $pi): ?>
                                        <?php $piMargin = (float)$pi['estimated_sell'] - (float)$pi['estimated_cost']; ?>
                                        <tr id="pkg-item-row-<?php echo (int)$pi['id']; ?>">
                                            <td><?php echo htmlspecialchars($packageItemTypes[$pi['item_type']] ?? $pi['item_type']); ?></td>
                                            <td><?php echo htmlspecialchars($pi['item_name']); ?><?php if (!empty($pi['notes'])): ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($pi['notes']); ?></small><?php endif; ?></td>
                                            <td><?php echo $pi['cost_basis'] === 'flat' ? 'Flat (sekali)' : 'Per Pax'; ?></td>
                                            <td><?php echo sunseaRupiah((float)$pi['estimated_cost']); ?></td>
                                            <td><?php echo sunseaRupiah((float)$pi['estimated_sell']); ?></td>
                                            <td style="color:<?php echo $piMargin < 0 ? '#dc2626' : '#16a34a'; ?>;font-weight:700;"><?php echo sunseaRupiah($piMargin); ?></td>
                                            <td style="white-space:nowrap;">
                                                <button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="togglePkgItemEdit(<?php echo (int)$pi['id']; ?>)"><i data-feather="edit-2"></i></button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus layanan ini dari paket?');">
                                                    <input type="hidden" name="action" value="delete_package_item">
                                                    <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int)$pi['id']; ?>">
                                                    <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#dc2626;border-color:#dc2626;"><i data-feather="trash-2"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <tr id="pkg-item-edit-<?php echo (int)$pi['id']; ?>" style="display:none;background:#fffbeb;">
                                            <td colspan="7">
                                                <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:8px 2px;">
                                                    <input type="hidden" name="action" value="update_package_item">
                                                    <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int)$pi['id']; ?>">
                                                    <div class="ss-form-group" style="margin:0;min-width:130px;">
                                                        <label class="ss-label">Tipe</label>
                                                        <select name="item_type" id="edit<?php echo (int)$pi['id']; ?>_item_type" class="ss-select" onchange="pkgRefreshOptions('edit<?php echo (int)$pi['id']; ?>')">
                                                            <?php foreach ($packageItemTypes as $v => $label): ?>
                                                                <option value="<?php echo $v; ?>" <?php echo $pi['item_type'] === $v ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;min-width:190px;">
                                                        <label class="ss-label">Pilih dari Database</label>
                                                        <select id="edit<?php echo (int)$pi['id']; ?>_item_ref" class="ss-select" onchange="pkgApplyRef('edit<?php echo (int)$pi['id']; ?>')">
                                                            <option value="">-- Pilih manual --</option>
                                                        </select>
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;min-width:160px;flex:1;">
                                                        <label class="ss-label">Nama Layanan</label>
                                                        <input type="text" name="item_name" id="edit<?php echo (int)$pi['id']; ?>_item_name" class="ss-input" required value="<?php echo htmlspecialchars($pi['item_name']); ?>">
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;min-width:140px;">
                                                        <label class="ss-label">Basis Biaya</label>
                                                        <select name="cost_basis" class="ss-select">
                                                            <option value="per_pax" <?php echo $pi['cost_basis'] === 'per_pax' ? 'selected' : ''; ?>>Per Pax</option>
                                                            <option value="flat" <?php echo $pi['cost_basis'] === 'flat' ? 'selected' : ''; ?>>Flat (sekali)</option>
                                                        </select>
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;width:120px;">
                                                        <label class="ss-label">Modal (Rp)</label>
                                                        <input type="text" name="estimated_cost" id="edit<?php echo (int)$pi['id']; ?>_item_cost" class="ss-input" value="<?php echo (float)$pi['estimated_cost']; ?>">
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;width:120px;">
                                                        <label class="ss-label">Jual (Rp)</label>
                                                        <input type="text" name="estimated_sell" id="edit<?php echo (int)$pi['id']; ?>_item_sell" class="ss-input" value="<?php echo (float)$pi['estimated_sell']; ?>">
                                                    </div>
                                                    <div class="ss-form-group" style="margin:0;min-width:160px;flex:1;">
                                                        <label class="ss-label">Catatan</label>
                                                        <input type="text" name="notes" class="ss-input" value="<?php echo htmlspecialchars($pi['notes'] ?? ''); ?>">
                                                    </div>
                                                    <button type="submit" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="save"></i> Simpan</button>
                                                    <button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="togglePkgItemEdit(<?php echo (int)$pi['id']; ?>)">Batal</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="font-weight:700;">
                                        <td colspan="3" style="text-align:right;">Total Rincian Layanan</td>
                                        <td><?php echo sunseaRupiah($pkgItemsCostTotal); ?></td>
                                        <td><?php echo sunseaRupiah($pkgItemsSellTotal); ?></td>
                                        <td style="color:<?php echo $pkgItemsMargin < 0 ? '#dc2626' : '#16a34a'; ?>;"><?php echo sunseaRupiah($pkgItemsMargin); ?></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div style="font-size:11.5px;color:var(--ss-muted);margin:-6px 0 12px;">* Total Harga Jual rincian layanan sebaiknya mendekati/menyamai Harga Dasar paket per pax, agar margin paket akurat.</div>
                    <?php endif; ?>

                    <div class="ss-card-title" style="margin:10px 0 8px;font-size:13px;">+ Tambah Layanan</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_package_item">
                        <input type="hidden" name="package_id" value="<?php echo (int)$editPkg['id']; ?>">
                        <div class="ss-form-grid cols-2">
                            <div class="ss-form-group">
                                <label class="ss-label">Tipe Layanan</label>
                                <select name="item_type" id="add_item_type" class="ss-select" onchange="pkgRefreshOptions('add')">
                                    <?php foreach ($packageItemTypes as $v => $label): ?>
                                        <option value="<?php echo $v; ?>"><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Pilih dari Database (opsional)</label>
                                <select id="add_item_ref" class="ss-select" onchange="pkgApplyRef('add')">
                                    <option value="">-- Pilih manual --</option>
                                </select>
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Nama Layanan *</label>
                                <input type="text" name="item_name" id="add_item_name" class="ss-input" required placeholder="Contoh: Tiket Kapal Express PP">
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
                                <input type="text" name="estimated_cost" id="add_item_cost" class="ss-input" placeholder="0">
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Harga Jual (Rp)</label>
                                <input type="text" name="estimated_sell" id="add_item_sell" class="ss-input" placeholder="0">
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
    </div>

<?php else: ?>
    <!-- ============ LIST ============ -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Paket Wisata</div>
                <div class="ss-card-sub"><?php echo count($packages); ?> paket tersedia &middot; urutan di sini menentukan urutan tampil di website</div>
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
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                <input type="hidden" name="dir" value="up">
                                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" title="Naikkan urutan"><i data-feather="arrow-up"></i></button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                <input type="hidden" name="dir" value="down">
                                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" title="Turunkan urutan"><i data-feather="arrow-down"></i></button>
                            </form>
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
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus paket <?php echo htmlspecialchars(addslashes($pkg['name'])); ?>? Tindakan ini tidak bisa dibatalkan.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $pkg['id']; ?>">
                                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" title="Hapus" style="color:#dc2626;border-color:#dc2626;">
                                    <i data-feather="trash-2"></i>
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