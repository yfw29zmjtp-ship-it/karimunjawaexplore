<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses Gudang Nasita ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Database Produk Gudang';

// Ensure table exists with unique constraint on nama_barang
try {
    $db->query("CREATE TABLE IF NOT EXISTS `gudang_nasita_barang` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `kode_barang` VARCHAR(30) NULL,
        `nama_barang` VARCHAR(200) NOT NULL,
        `kategori`   VARCHAR(100) DEFAULT 'lainnya',
        `satuan`     VARCHAR(30)  DEFAULT 'pcs',
        `deskripsi`  TEXT NULL,
        `harga_beli` DECIMAL(15,2) DEFAULT 0,
        `harga_jual` DECIMAL(15,2) DEFAULT 0,
        `min_stock`  DECIMAL(15,2) DEFAULT 0,
        `is_active`  TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_nama_barang` (`nama_barang`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}
try {
    $barangCols = array_column($db->fetchAll('SHOW COLUMNS FROM gudang_nasita_barang'), 'Field');
    if (!in_array('min_stock', $barangCols)) {
        $db->query('ALTER TABLE gudang_nasita_barang ADD COLUMN min_stock DECIMAL(15,2) DEFAULT 0 AFTER harga_jual');
    }
} catch (Throwable $e) {
}

$msg = '';
$msgType = 'success';

// Handle POST (create/update via regular form fallback when JS disabled)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama_barang'] ?? '');
        $kategori = trim($_POST['kategori'] ?? 'lainnya') ?: 'lainnya';
        $satuan   = trim($_POST['satuan'] ?? 'pcs') ?: 'pcs';
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $minStock = max(0, (float)($_POST['min_stock'] ?? 0));

        if ($nama === '') {
            $msg = 'Nama barang wajib diisi.';
            $msgType = 'danger';
        } else {
            $dupe = $db->fetchOne("SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) AND id != ? AND COALESCE(is_active,1) = 1 LIMIT 1", [$nama, $id]);
            if ($dupe) {
                $msg = "Nama \"$nama\" sudah ada di database (ID #{$dupe['id']}). Gunakan nama yang berbeda atau edit produk yang ada.";
                $msgType = 'danger';
            } else {
                $data = ['nama_barang' => $nama, 'kategori' => $kategori, 'satuan' => $satuan, 'deskripsi' => $deskripsi, 'min_stock' => $minStock, 'is_active' => 1];
                if ($id > 0) {
                    $db->update('gudang_nasita_barang', $data, 'id = :id', ['id' => $id]);
                    $msg = 'Produk berhasil diperbarui.';
                } else {
                    $prefix = 'BRG-';
                    $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
                    $seq = $last ? ((int)substr($last['kode_barang'], -4) + 1) : 1;
                    $data['kode_barang'] = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    $db->insert('gudang_nasita_barang', $data);
                    $msg = 'Produk berhasil ditambahkan.';
                }
            }
        }
    }

    if ($formAction === 'import_names') {
        $rawText = trim($_POST['import_text'] ?? '');
        $defaultKategori = trim($_POST['import_kategori'] ?? 'lainnya') ?: 'lainnya';
        $defaultSatuan   = trim($_POST['import_satuan'] ?? 'pcs') ?: 'pcs';

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/[\r\n;,]+/', $rawText)),
            fn($l) => $l !== ''
        ));

        if (empty($lines)) {
            $msg = 'Tidak ada nama barang yang valid untuk diimport.';
            $msgType = 'danger';
        } else {
            $added = 0;
            $skipped = 0;
            $prefix = 'BRG-';
            foreach ($lines as $line) {
                $nama = trim((string)$line);
                if ($nama === '') {
                    $skipped++;
                    continue;
                }
                $exist = $db->fetchOne('SELECT id FROM gudang_nasita_barang WHERE LOWER(nama_barang) = LOWER(?) LIMIT 1', [$nama]);
                if ($exist) {
                    $skipped++;
                    continue;
                }
                $last = $db->fetchOne('SELECT kode_barang FROM gudang_nasita_barang WHERE kode_barang LIKE ? ORDER BY kode_barang DESC LIMIT 1', [$prefix . '%']);
                $seq = $last ? ((int)substr($last['kode_barang'], -4) + 1) : 1;
                $db->insert('gudang_nasita_barang', [
                    'kode_barang' => $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT),
                    'nama_barang' => $nama,
                    'kategori'    => $defaultKategori,
                    'satuan'      => $defaultSatuan,
                    'harga_beli'  => 0,
                    'is_active'   => 1,
                ]);
                $added++;
            }
            $msg = "Import selesai: {$added} barang ditambahkan" . ($skipped > 0 ? ", {$skipped} dilewati (sudah ada atau kosong)." : '.');
            $msgType = $added > 0 ? 'success' : 'warning';
        }
    }

    if ($formAction === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $db->fetchOne('SELECT is_active FROM gudang_nasita_barang WHERE id = ?', [$id]);
        if ($cur) {
            $db->update('gudang_nasita_barang', ['is_active' => $cur['is_active'] ? 0 : 1], 'id = :id', ['id' => $id]);
            $msg = 'Status produk diubah.';
        }
    }

    if ($formAction === 'delete') {
        $ids = $_POST['ids'] ?? ($_POST['id'] ?? []);
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $cleanIds = [];
        foreach ($ids as $id) {
            $v = (int)$id;
            if ($v > 0) {
                $cleanIds[] = $v;
            }
        }
        $cleanIds = array_values(array_unique($cleanIds));

        if (!empty($cleanIds)) {
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            // Soft-delete: set is_active=0 so historical PO references remain intact
            $db->query('UPDATE gudang_nasita_barang SET is_active = 0 WHERE id IN (' . $placeholders . ')', $cleanIds);
            $msg = 'Produk yang dipilih berhasil dihapus (dinonaktifkan).';
            $msgType = 'success';
        } else {
            $msg = 'Tidak ada produk yang dipilih untuk dihapus.';
            $msgType = 'danger';
        }
    }
}

// Search & filter
$qSearch = trim($_GET['q'] ?? '');
$filterKategori = trim($_GET['kategori'] ?? '');
$showInactive = ($_GET['show_inactive'] ?? '') === '1';
$where = $showInactive ? 'WHERE 1=1' : 'WHERE COALESCE(is_active,1) = 1';
$params = [];
if ($qSearch !== '') {
    $where .= ' AND (nama_barang LIKE ? OR kode_barang LIKE ?)';
    $params[] = '%' . $qSearch . '%';
    $params[] = '%' . $qSearch . '%';
}
if ($filterKategori !== '') {
    $where .= ' AND kategori = ?';
    $params[] = $filterKategori;
}

$products = $db->fetchAll("SELECT * FROM gudang_nasita_barang $where ORDER BY nama_barang ASC", $params);
$allKategori = $db->fetchAll("SELECT DISTINCT COALESCE(kategori,'lainnya') AS kategori FROM gudang_nasita_barang WHERE COALESCE(is_active,1) = 1 ORDER BY kategori ASC");

$forceTheme = 'light';
include '../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h2 style="font-size:1.4rem; font-weight:700; margin:0; color:var(--text-primary);">Database Produk Gudang</h2>
        <p style="color:var(--text-muted); font-size:0.875rem; margin:0.25rem 0 0;">Master barang terpusat — cegah nama ganda seperti "Beer" vs "Bir"</p>
    </div>
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <button type="button" class="btn" style="background:#7c3aed;color:#fff;" onclick="openProdukModal(0)">
            <i data-feather="plus" style="width:15px;height:15px;"></i> Tambah Produk
        </button>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('importNamaModal').style.display='flex'">
            <i data-feather="upload" style="width:15px;height:15px;"></i> Import Nama Barang
        </button>
        <a href="gudang-nasita.php" class="btn btn-secondary">← Kembali ke Stock Gudang</a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom:1rem;"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- Search bar -->
<div class="card" style="margin-bottom:1rem; padding:0.85rem 1rem;">
    <form method="GET" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <input type="text" name="q" class="form-control" placeholder="Cari nama atau kode barang..." value="<?php echo htmlspecialchars($qSearch); ?>" style="min-width:220px;">
        <select name="kategori" class="form-control" style="width:160px;">
            <option value="">Semua Kategori</option>
            <?php foreach ($allKategori as $k): ?>
                <option value="<?php echo htmlspecialchars($k['kategori']); ?>" <?php echo $filterKategori === $k['kategori'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars(ucfirst($k['kategori'])); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label style="display:flex; align-items:center; gap:0.35rem; font-size:0.83rem; color:var(--text-muted); margin:0;">
            <input type="checkbox" name="show_inactive" value="1" <?php echo $showInactive ? 'checked' : ''; ?>>
            Tampilkan non-aktif
        </label>
        <button type="submit" class="btn btn-primary">Cari</button>
        <a href="gudang-produk.php" class="btn btn-secondary">Reset</a>
        <span style="margin-left:auto; font-size:0.83rem; color:var(--text-muted);"><?php echo count($products); ?> produk</span>
    </form>
</div>

<div class="card" style="margin-bottom:1rem; padding:0.85rem 1rem;">
    <div style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <label class="form-check" style="display:flex; align-items:center; gap:0.45rem; margin:0; font-size:0.85rem; color:var(--text-primary);">
            <input type="checkbox" id="selectAllProduk" aria-label="Centang semua produk">
            <span>Centang semua</span>
        </label>
        <button type="button" id="deleteSelectedProdukBtn" class="btn btn-sm btn-danger" disabled>Hapus yang ditandai</button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:42px;">
                        <input type="checkbox" id="selectAllProdukHeader" aria-label="Pilih semua produk" title="Pilih semua produk">
                    </th>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Satuan</th>
                    <th class="text-right">Harga Beli</th>
                    <th class="text-right">Stok Min</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada produk. Klik "Tambah Produk" untuk mulai.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="produk-select-check" value="<?php echo (int)$p['id']; ?>" aria-label="Pilih produk <?php echo htmlspecialchars($p['nama_barang']); ?>">
                            </td>
                            <td style="font-weight:600; font-size:0.82rem;"><?php echo htmlspecialchars($p['kode_barang'] ?? '-'); ?></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($p['nama_barang']); ?></div>
                                <?php if (!empty($p['deskripsi'])): ?>
                                    <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($p['deskripsi']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info" style="text-transform:capitalize;"><?php echo htmlspecialchars($p['kategori'] ?? 'lainnya'); ?></span></td>
                            <td><?php echo htmlspecialchars($p['satuan'] ?? 'pcs'); ?></td>
                            <td class="text-right" style="font-weight:600;">
                                <?php echo (float)($p['harga_beli'] ?? 0) > 0 ? 'Rp ' . number_format((float)$p['harga_beli'], 0, ',', '.') : '—'; ?>
                            </td>
                            <td class="text-right" style="color:#64748b;">
                                <?php echo (float)($p['min_stock'] ?? 0) > 0 ? number_format((float)$p['min_stock'], 0, ',', '.') : '—'; ?>
                            </td>
                            <td>
                                <?php if ($p['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#6b7280;color:#fff;">Non-aktif</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; gap:0.3rem; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openProdukModal(<?php echo (int)$p['id']; ?>, <?php echo htmlspecialchars(json_encode($p), ENT_QUOTES); ?>)">
                                        Edit
                                    </button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="form_action" value="delete">
                                        <input type="hidden" name="ids[]" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Hapus produk <?php echo htmlspecialchars(addslashes($p['nama_barang'])); ?> dari database master?')">
                                            Hapus
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="form_action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background:<?php echo $p['is_active'] ? '#6b7280' : '#0f9d6a'; ?>;color:#fff;"
                                            onclick="return confirm('<?php echo $p['is_active'] ? 'Non-aktifkan' : 'Aktifkan'; ?> produk ini?')">
                                            <?php echo $p['is_active'] ? 'Non-aktif' : 'Aktifkan'; ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Tambah / Edit Produk -->
<div id="produkModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:2000; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(520px,100%); max-height:92vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="produkModalTitle" style="font-size:1.05rem; margin:0;">Tambah Produk</h3>
            <button type="button" onclick="closeProdukModal()" class="btn btn-sm btn-outline-secondary">✕ Tutup</button>
        </div>
        <div id="produkModalMsg" style="display:none; padding:0.6rem 0.9rem; border-radius:0.5rem; margin-bottom:0.75rem; font-size:0.875rem;"></div>
        <form id="produkForm">
            <input type="hidden" id="produkId" name="id" value="0">
            <div style="display:grid; gap:0.85rem;">
                <div>
                    <label class="form-label">Nama Barang <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="produkNama" name="nama_barang" class="form-control" required placeholder="Contoh: Bir Bintang 330ml">
                    <div id="produkNamaHint" style="font-size:0.75rem; color:#0f9d6a; margin-top:2px; display:none;"></div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <div>
                        <label class="form-label">Kategori</label>
                        <input type="text" id="produkKategori" name="kategori" class="form-control" list="kategoriList" placeholder="minuman">
                        <datalist id="kategoriList">
                            <option value="minuman"></option>
                            <option value="frozen"></option>
                            <option value="alat"></option>
                            <option value="sayur"></option>
                            <option value="daging"></option>
                            <option value="sembako"></option>
                            <option value="bumbu"></option>
                            <option value="lainnya"></option>
                        </datalist>
                    </div>
                    <div>
                        <label class="form-label">Satuan</label>
                        <input type="text" id="produkSatuan" name="satuan" class="form-control" list="satuanList" placeholder="pcs">
                        <datalist id="satuanList">
                            <option value="pcs"></option>
                            <option value="kg"></option>
                            <option value="liter"></option>
                            <option value="botol"></option>
                            <option value="karton"></option>
                            <option value="lusin"></option>
                            <option value="gram"></option>
                        </datalist>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <div>
                        <label class="form-label">Harga Beli (Rp)</label>
                        <input type="number" id="produkHargaBeli" name="harga_beli" class="form-control" min="0" step="1" placeholder="0">
                    </div>
                    <div>
                        <label class="form-label">Stok Minimum</label>
                        <input type="number" id="produkMinStock" name="min_stock" class="form-control" min="0" step="0.01" placeholder="0">
                        <div style="font-size:0.73rem; color:#64748b; margin-top:2px;">Ditandai merah di PO bila ≤ ini</div>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <div>
                        <label class="form-label">Tanggal Kadaluarsa</label>
                        <input type="date" id="produkExpiry" name="expiry_date" class="form-control">
                        <div style="font-size:0.73rem; color:#64748b; margin-top:2px;">Dikosongkan jika tidak ada kadaluarsa</div>
                    </div>
                    <div>
                        <label class="form-label">Deskripsi / Catatan</label>
                        <textarea id="produkDeskripsi" name="deskripsi" class="form-control" rows="3" placeholder="Opsional"></textarea>
                    </div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.1rem;">
                <button type="button" onclick="closeProdukModal()" class="btn btn-secondary">Batal</button>
                <button type="button" onclick="saveProduk()" class="btn btn-success" id="produkSaveBtn">Simpan Produk</button>
            </div>
        </form>
    </div>
</div>

<script>
    if (typeof feather !== 'undefined') feather.replace();

    const BASE = '<?php echo BASE_URL; ?>';
    let produkNamaTimer;

    function openProdukModal(id, data) {
        document.getElementById('produkId').value = id || 0;
        document.getElementById('produkModalTitle').textContent = id ? 'Edit Produk' : 'Tambah Produk';
        document.getElementById('produkNama').value = data ? data.nama_barang : '';
        document.getElementById('produkKategori').value = data ? (data.kategori || '') : '';
        document.getElementById('produkSatuan').value = data ? (data.satuan || '') : '';
        document.getElementById('produkHargaBeli').value = data ? (parseFloat(data.harga_beli) || '') : '';
        document.getElementById('produkMinStock').value = data ? (parseFloat(data.min_stock) || '') : '';
        document.getElementById('produkExpiry').value = data ? (data.expiry_date || '') : '';
        document.getElementById('produkDeskripsi').value = data ? (data.deskripsi || '') : '';
        document.getElementById('produkModalMsg').style.display = 'none';
        document.getElementById('produkNamaHint').style.display = 'none';
        document.getElementById('produkNama').readOnly = false;
        document.getElementById('produkModal').style.display = 'flex';
        if (!id) setTimeout(() => document.getElementById('produkNama').focus(), 80);
    }

    function closeProdukModal() {
        document.getElementById('produkModal').style.display = 'none';
    }

    // Live duplicate check while typing
    document.getElementById('produkNama').addEventListener('input', function() {
        clearTimeout(produkNamaTimer);
        const val = this.value.trim();
        const hint = document.getElementById('produkNamaHint');
        if (!val) {
            hint.style.display = 'none';
            return;
        }
        produkNamaTimer = setTimeout(async () => {
            try {
                const r = await fetch(`${BASE}/api/gudang-produk-search.php?action=search&q=${encodeURIComponent(val)}`);
                const d = await r.json();
                const exact = (d.data || []).find(p => p.nama_barang.toLowerCase() === val.toLowerCase());
                if (exact) {
                    hint.textContent = `⚠️ "${exact.nama_barang}" sudah ada (${exact.kode_barang})`;
                    hint.style.color = '#dc2626';
                    hint.style.display = 'block';
                } else {
                    hint.style.display = 'none';
                }
            } catch (e) {}
        }, 400);
    });

    async function saveProduk() {
        const btn = document.getElementById('produkSaveBtn');
        const msgEl = document.getElementById('produkModalMsg');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        const form = document.getElementById('produkForm');
        const body = new URLSearchParams(new FormData(form));
        body.append('action', 'save');

        try {
            const r = await fetch(`${BASE}/api/gudang-produk-search.php?action=save`, {
                method: 'POST',
                body
            });
            const d = await r.json();
            msgEl.style.display = 'block';
            if (d.success) {
                msgEl.style.background = '#d1fae5';
                msgEl.style.color = '#065f46';
                msgEl.textContent = d.message;
                setTimeout(() => location.reload(), 900);
            } else {
                msgEl.style.background = '#fee2e2';
                msgEl.style.color = '#991b1b';
                msgEl.textContent = d.message;
                btn.disabled = false;
                btn.textContent = 'Simpan Produk';
            }
        } catch (e) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fee2e2';
            msgEl.style.color = '#991b1b';
            msgEl.textContent = 'Gagal menyimpan. Coba lagi.';
            btn.disabled = false;
            btn.textContent = 'Simpan Produk';
        }
    }

    const selectAllCheckboxes = document.querySelectorAll('#selectAllProduk, #selectAllProdukHeader');
    const produkCheckBoxes = () => Array.from(document.querySelectorAll('.produk-select-check'));

    function syncSelectAllState() {
        const checks = produkCheckBoxes();
        const selected = checks.filter(cb => cb.checked).length;
        const allChecked = checks.length > 0 && selected === checks.length;
        selectAllCheckboxes.forEach(el => {
            el.checked = allChecked;
        });
        const deleteBtn = document.getElementById('deleteSelectedProdukBtn');
        if (deleteBtn) deleteBtn.disabled = selected === 0;
    }

    selectAllCheckboxes.forEach(el => {
        el.addEventListener('change', () => {
            const checked = el.checked;
            produkCheckBoxes().forEach(cb => {
                cb.checked = checked;
            });
            syncSelectAllState();
        });
    });

    document.addEventListener('change', e => {
        if (e.target && e.target.classList.contains('produk-select-check')) {
            syncSelectAllState();
        }
    });

    document.getElementById('deleteSelectedProdukBtn')?.addEventListener('click', async () => {
        const ids = produkCheckBoxes().filter(cb => cb.checked).map(cb => Number(cb.value)).filter(Boolean);
        if (!ids.length) return;

        const confirmed = confirm(`Hapus ${ids.length} produk yang dipilih dari database master?`);
        if (!confirmed) return;

        const formData = new URLSearchParams();
        formData.append('action', 'delete');
        ids.forEach(id => formData.append('ids[]', String(id)));

        try {
            const response = await fetch(`${BASE}/api/gudang-produk-search.php?action=delete`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: formData.toString()
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
                return;
            }

            alert(result.message || 'Gagal menghapus produk yang dipilih.');
        } catch (error) {
            alert('Gagal menghapus produk yang dipilih. Coba lagi.');
        }
    });

    document.addEventListener('click', e => {
        if (e.target === document.getElementById('produkModal')) closeProdukModal();
        if (e.target === document.getElementById('importNamaModal')) document.getElementById('importNamaModal').style.display = 'none';
    });
</script>

<!-- Modal: Import Nama Barang -->
<div id="importNamaModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:2000; align-items:center; justify-content:center; padding:1rem;">
    <div class="card" style="width:min(540px,100%); max-height:92vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 style="font-size:1.05rem; margin:0;">Import Nama Barang</h3>
            <button type="button" onclick="document.getElementById('importNamaModal').style.display='none'" class="btn btn-sm btn-outline-secondary">✕ Tutup</button>
        </div>
        <p style="font-size:0.83rem; color:var(--text-muted); margin:0 0 1rem;">
            Tulis atau tempel daftar nama barang — satu nama per baris (atau pisahkan dengan koma/titik koma).<br>
            Nama yang sudah ada di database akan dilewati. <strong>Stok harus diisi manual setelah import.</strong>
        </p>
        <form method="POST">
            <input type="hidden" name="form_action" value="import_names">
            <div style="display:grid; gap:0.85rem;">
                <div>
                    <label class="form-label">Daftar Nama Barang <span style="color:#dc2626;">*</span></label>
                    <textarea name="import_text" class="form-control" rows="10"
                        placeholder="Bir Bintang 330ml&#10;Bir Bintang 620ml&#10;Absolut Vodka&#10;..." required></textarea>
                    <div style="font-size:0.75rem; color:#64748b; margin-top:3px;">Satu nama per baris, atau pisahkan dengan koma/titik koma</div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.7rem;">
                    <div>
                        <label class="form-label">Kategori default</label>
                        <input type="text" name="import_kategori" class="form-control" list="kategoriList" placeholder="minuman" value="lainnya">
                    </div>
                    <div>
                        <label class="form-label">Satuan default</label>
                        <input type="text" name="import_satuan" class="form-control" list="satuanList" placeholder="pcs" value="pcs">
                    </div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.5rem; margin-top:1.1rem;">
                <button type="button" onclick="document.getElementById('importNamaModal').style.display='none'" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i data-feather="upload" style="width:14px;height:14px;"></i> Import Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>