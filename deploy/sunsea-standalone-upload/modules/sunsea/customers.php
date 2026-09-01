<?php

/**
 * Sunsea - Manajemen Pelanggan (Customer Database)
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
$action  = $_GET['action'] ?? 'list';
$custId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- HANDLE POST (Add / Edit / Delete) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [
            'name'      => trim($_POST['name'] ?? ''),
            'type'      => $_POST['type'] ?? 'individual',
            'email'     => trim($_POST['email'] ?? ''),
            'phone'     => trim($_POST['phone'] ?? ''),
            'whatsapp'  => trim($_POST['whatsapp'] ?? ''),
            'address'   => trim($_POST['address'] ?? ''),
            'city'      => trim($_POST['city'] ?? ''),
            'country'   => trim($_POST['country'] ?? 'Indonesia'),
            'id_number' => trim($_POST['id_number'] ?? ''),
            'id_type'   => $_POST['id_type'] ?? 'ktp',
            'notes'     => trim($_POST['notes'] ?? ''),
        ];

        if (empty($data['name'])) {
            $_SESSION['flash_message'] = 'Nama pelanggan wajib diisi.';
            $_SESSION['flash_type']    = 'error';
        } else {
            if ($id > 0) {
                // Update
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
                $stmt = $pdo->prepare("UPDATE customers SET $set, updated_at=NOW() WHERE id = ?");
                $stmt->execute([...array_values($data), $id]);
                $_SESSION['flash_message'] = 'Data pelanggan berhasil diperbarui.';
                $_SESSION['flash_type']    = 'success';
            } else {
                // Auto generate code SS-CUST-001
                $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextNum  = 1;
                if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) $nextNum = (int)$m[1] + 1;
                $data['code'] = 'SS-CUST-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
                $vals = implode(', ', array_fill(0, count($data), '?'));
                $pdo->prepare("INSERT INTO customers ($cols) VALUES ($vals)")->execute(array_values($data));
                $_SESSION['flash_message'] = 'Pelanggan baru berhasil ditambahkan.';
                $_SESSION['flash_type']    = 'success';
            }
        }
        header('Location: customers.php');
        exit;
    } elseif ($postAction === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if customer has quotations / invoices
        $hasRef = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE customer_id=?");
        $hasRef->execute([$id]);
        if ($hasRef->fetchColumn() > 0) {
            $_SESSION['flash_message'] = 'Pelanggan tidak bisa dihapus karena memiliki penawaran.';
            $_SESSION['flash_type']    = 'error';
        } else {
            $pdo->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
            $_SESSION['flash_message'] = 'Pelanggan berhasil dihapus.';
            $_SESSION['flash_type']    = 'success';
        }
        header('Location: customers.php');
        exit;
    }
}

// ---- LOAD DATA ----
$editCustomer = null;
if ($action === 'edit' && $custId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$custId]);
    $editCustomer = $stmt->fetch();
    if (!$editCustomer) {
        header('Location: customers.php');
        exit;
    }
}

// List customers (with search)
$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE (name LIKE ? OR code LIKE ? OR phone LIKE ? OR email LIKE ?)" : "";
$params = $search ? ["%$search%", "%$search%", "%$search%", "%$search%"] : [];

$customers = $pdo->prepare("
    SELECT c.*, 
        (SELECT COUNT(*) FROM quotations WHERE customer_id=c.id) as total_quotations,
        (SELECT COUNT(*) FROM invoices    WHERE customer_id=c.id) as total_invoices
    FROM customers c $where
    ORDER BY c.name
");
$customers->execute($params);
$customers = $customers->fetchAll();

$pageTitle  = ($action === 'add' || $action === 'edit') ? (($editCustomer ? 'Edit Pelanggan' : 'Tambah Pelanggan')) : 'Database Pelanggan';
$activePage = 'database';

include 'layout-header.php';
?>

<?php if ($action === 'add' || $action === 'edit'): ?>
    <!-- ============ FORM ============ -->
    <div style="max-width:700px;">
        <div style="margin-bottom:20px;">
            <a href="customers.php" class="ss-btn ss-btn-outline ss-btn-sm">
                <i data-feather="arrow-left"></i> Kembali
            </a>
        </div>

        <div class="ss-card">
            <div class="ss-card-header">
                <div>
                    <div class="ss-card-title"><?php echo $editCustomer ? 'Edit Pelanggan' : 'Tambah Pelanggan Baru'; ?></div>
                    <div class="ss-card-sub"><?php echo $editCustomer ? htmlspecialchars($editCustomer['code']) : 'Kode akan digenerate otomatis'; ?></div>
                </div>
            </div>

            <form method="POST" action="customers.php">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editCustomer ? $editCustomer['id'] : 0; ?>">

                <div class="ss-form-grid cols-2">
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Nama Lengkap *</label>
                        <input type="text" name="name" class="ss-input" required
                            value="<?php echo htmlspecialchars($editCustomer['name'] ?? ''); ?>" placeholder="Nama pelanggan">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Tipe Pelanggan</label>
                        <select name="type" class="ss-select">
                            <?php foreach (['individual' => 'Individual', 'group' => 'Group', 'corporate' => 'Perusahaan', 'travel_agent' => 'Travel Agent'] as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo ($editCustomer['type'] ?? 'individual') === $v ? 'selected' : ''; ?>>
                                    <?php echo $l; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">No. HP / WhatsApp</label>
                        <input type="text" name="phone" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['phone'] ?? ''); ?>" placeholder="08xxxxxxxxxx">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">WhatsApp (jika beda)</label>
                        <input type="text" name="whatsapp" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['whatsapp'] ?? ''); ?>" placeholder="Kosongkan jika sama">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Email</label>
                        <input type="email" name="email" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['email'] ?? ''); ?>" placeholder="email@domain.com">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Kota</label>
                        <input type="text" name="city" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['city'] ?? ''); ?>" placeholder="Jakarta">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Negara</label>
                        <input type="text" name="country" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['country'] ?? 'Indonesia'); ?>">
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Tipe ID</label>
                        <select name="id_type" class="ss-select">
                            <option value="ktp" <?php echo ($editCustomer['id_type'] ?? '') === 'ktp' ? 'selected' : ''; ?>>KTP</option>
                            <option value="passport" <?php echo ($editCustomer['id_type'] ?? '') === 'passport' ? 'selected' : ''; ?>>Passport</option>
                            <option value="other" <?php echo ($editCustomer['id_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Lainnya</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">No. KTP / Passport</label>
                        <input type="text" name="id_number" class="ss-input"
                            value="<?php echo htmlspecialchars($editCustomer['id_number'] ?? ''); ?>" placeholder="Nomor identitas">
                    </div>
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Alamat</label>
                        <textarea name="address" class="ss-textarea" placeholder="Alamat lengkap"><?php echo htmlspecialchars($editCustomer['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <label class="ss-label">Catatan Internal</label>
                        <textarea name="notes" class="ss-textarea" rows="3" placeholder="Catatan pribadi (tidak ditampilkan ke customer)"><?php echo htmlspecialchars($editCustomer['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                    <a href="customers.php" class="ss-btn ss-btn-outline">Batal</a>
                    <button type="submit" class="ss-btn ss-btn-primary">
                        <i data-feather="save"></i>
                        <?php echo $editCustomer ? 'Simpan Perubahan' : 'Tambah Pelanggan'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- ============ LIST ============ -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Database Pelanggan</div>
                <div class="ss-card-sub"><?php echo count($customers); ?> pelanggan<?php echo $search ? " (filter: " . htmlspecialchars($search) . ")" : ''; ?></div>
            </div>
            <a href="customers.php?action=add" class="ss-btn ss-btn-primary">
                <i data-feather="user-plus"></i> Tambah Pelanggan
            </a>
        </div>

        <!-- Search -->
        <form method="GET" style="margin-bottom:16px;display:flex;gap:10px;">
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                class="ss-input" style="max-width:300px;" placeholder="Cari nama, kode, HP, email...">
            <button type="submit" class="ss-btn ss-btn-outline"><i data-feather="search"></i> Cari</button>
            <?php if ($search): ?><a href="customers.php" class="ss-btn ss-btn-outline"><i data-feather="x"></i></a><?php endif; ?>
        </form>

        <?php if (empty($customers)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">👥</div>
                <h3>Belum ada pelanggan</h3>
                <p>Tambahkan pelanggan pertama untuk mulai membuat penawaran</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Tipe</th>
                            <th>HP / WA</th>
                            <th>Kota</th>
                            <th>Penawaran</th>
                            <th>Invoice</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td style="font-size:11px;color:var(--ss-muted);"><?php echo htmlspecialchars($c['code']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                                    <?php if ($c['email']): ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($c['email']); ?></small><?php endif; ?>
                                </td>
                                <td><?php
                                    $typeLabels = ['individual' => 'Individual', 'group' => 'Group', 'corporate' => 'Perusahaan', 'travel_agent' => 'Travel Agent'];
                                    echo $typeLabels[$c['type']] ?? ucfirst($c['type']);
                                    ?></td>
                                <td><?php echo htmlspecialchars($c['phone'] ?: $c['whatsapp'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($c['city'] ?: '-'); ?></td>
                                <td style="text-align:center;"><?php echo (int)$c['total_quotations']; ?></td>
                                <td style="text-align:center;"><?php echo (int)$c['total_invoices']; ?></td>
                                <td>
                                    <div style="display:flex;gap:6px;">
                                        <a href="customers.php?action=edit&id=<?php echo $c['id']; ?>"
                                            class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="edit-2"></i></a>
                                        <a href="quotations.php?action=add&customer_id=<?php echo $c['id']; ?>"
                                            class="ss-btn ss-btn-primary ss-btn-sm" title="Buat penawaran">
                                            <i data-feather="file-plus"></i>
                                        </a>
                                        <?php if ($c['total_quotations'] == 0 && $c['total_invoices'] == 0): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus pelanggan ini?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="ss-btn ss-btn-danger ss-btn-sm"><i data-feather="trash-2"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>