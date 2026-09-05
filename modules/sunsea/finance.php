<?php

/** Sunsea - Finance / Buku Kas Operasional (pengeluaran & pemasukan per trip/tamu) */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo  = getSunseaConnection();
sunseaEnsureFinanceSchema($pdo);

$user = $auth->getCurrentUser()['username'] ?? 'system';

$categoryOptions = [
    'Tiket & Retribusi',
    'Transportasi',
    'Penginapan',
    'Konsumsi / Catering',
    'Guide & Coordinator',
    'Fasilitas Tambahan',
    'Operasional Kantor',
    'Lainnya',
];

// ---- SAVE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $type        = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $date        = $_POST['transaction_date'] ?: date('Y-m-d');
    $category    = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount      = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
    $customerId  = (int)($_POST['customer_id'] ?? 0) ?: null;
    $bookingId   = (int)($_POST['booking_id'] ?? 0) ?: null;
    $reference   = trim($_POST['reference'] ?? '');

    if ($description === '' || $amount <= 0) {
        $_SESSION['flash_message'] = 'Keterangan dan jumlah wajib diisi (jumlah harus lebih dari 0).';
        $_SESSION['flash_type']    = 'error';
    } else {
        try {
            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, customer_id, booking_id, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([$date, $type, $category, $description, $amount, $reference, $customerId, $bookingId, $user]);
            $_SESSION['flash_message'] = 'Transaksi kas berhasil dicatat.';
            $_SESSION['flash_type']    = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal menyimpan: ' . $e->getMessage();
            $_SESSION['flash_type']    = 'error';
        }
    }
    header('Location: finance.php' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    exit;
}

// ---- DELETE ----
if (($_GET['action'] ?? '') === 'delete' && (int)($_GET['id'] ?? 0) > 0) {
    $delId = (int)$_GET['id'];
    $row = $pdo->prepare("SELECT invoice_id FROM cash_book WHERE id=?");
    $row->execute([$delId]);
    $existing = $row->fetch();
    if ($existing && $existing['invoice_id']) {
        $_SESSION['flash_message'] = 'Transaksi ini tercatat otomatis dari pembayaran invoice dan tidak bisa dihapus dari sini.';
        $_SESSION['flash_type']    = 'error';
    } else {
        $pdo->prepare("DELETE FROM cash_book WHERE id=?")->execute([$delId]);
        $_SESSION['flash_message'] = 'Transaksi kas dihapus.';
        $_SESSION['flash_type']    = 'success';
    }
    header('Location: finance.php');
    exit;
}

// ---- FILTERS ----
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');
$filterType = $_GET['type'] ?? '';
$filterCust = (int)($_GET['customer_id'] ?? 0);

$where  = ['cb.transaction_date BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if (in_array($filterType, ['income', 'expense'], true)) {
    $where[] = 'cb.type = ?';
    $params[] = $filterType;
}
if ($filterCust > 0) {
    $where[] = 'cb.customer_id = ?';
    $params[] = $filterCust;
}
$whereSql = implode(' AND ', $where);

$rows = $pdo->prepare("
    SELECT cb.*, c.name AS customer_name, bo.booking_no
    FROM cash_book cb
    LEFT JOIN customers c ON c.id = cb.customer_id
    LEFT JOIN booking_orders bo ON bo.id = cb.booking_id
    WHERE $whereSql
    ORDER BY cb.transaction_date DESC, cb.id DESC
");
$rows->execute($params);
$rows = $rows->fetchAll();

$totalIncome  = 0;
$totalExpense = 0;
foreach ($rows as $r) {
    if ($r['type'] === 'income') {
        $totalIncome += (float)$r['amount'];
    } else {
        $totalExpense += (float)$r['amount'];
    }
}
$balance = $totalIncome - $totalExpense;

// Ringkasan pengeluaran per tamu (dalam rentang tanggal terpilih)
$perGuest = $pdo->prepare("
    SELECT c.id, c.name, COUNT(cb.id) AS tx_count, SUM(cb.amount) AS total_expense
    FROM cash_book cb
    JOIN customers c ON c.id = cb.customer_id
    WHERE cb.type = 'expense' AND cb.transaction_date BETWEEN ? AND ?
    GROUP BY c.id, c.name
    ORDER BY total_expense DESC
");
$perGuest->execute([$dateFrom, $dateTo]);
$perGuest = $perGuest->fetchAll();

$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$bookings  = $pdo->query("
    SELECT bo.id, bo.booking_no, bo.customer_id, c.name AS customer_name
    FROM booking_orders bo
    JOIN customers c ON c.id = bo.customer_id
    ORDER BY bo.id DESC
    LIMIT 300
")->fetchAll();

$pageTitle  = 'Finance - Buku Kas Operasional';
$activePage = 'finance';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;">
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($totalIncome); ?></div>
    </div>
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($totalExpense); ?></div>
    </div>
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Saldo Kas (periode ini)</div>
        <div style="font-size:20px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah($balance); ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start;">
    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Transaksi Kas</div>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="redirect_qs" value="<?php echo htmlspecialchars(http_build_query($_GET)); ?>">
            <div class="ss-form-group">
                <label class="ss-label">Jenis</label>
                <select name="type" class="ss-select" id="typeSelect">
                    <option value="expense">Pengeluaran</option>
                    <option value="income">Pemasukan</option>
                </select>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Tanggal</label>
                <input type="date" name="transaction_date" class="ss-input" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Trip / Booking (opsional)</label>
                <select name="booking_id" class="ss-select" id="bookingSelect" onchange="autoFillGuestFromBooking()">
                    <option value="">-- Tidak terkait trip tertentu --</option>
                    <?php foreach ($bookings as $b): ?>
                        <option value="<?php echo $b['id']; ?>" data-customer-id="<?php echo $b['customer_id']; ?>">
                            <?php echo htmlspecialchars($b['booking_no']); ?> — <?php echo htmlspecialchars($b['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Tamu / Customer</label>
                <select name="customer_id" class="ss-select" id="customerSelect">
                    <option value="">-- Tidak terkait tamu tertentu --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?><?php echo $c['phone'] ? ' - ' . htmlspecialchars($c['phone']) : ''; ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="font-size:11px;color:var(--ss-muted);margin-top:3px;">* Otomatis terisi saat memilih Trip/Booking, tapi bisa diganti manual.</div>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Kategori</label>
                <select name="category" class="ss-select">
                    <option value="">-- Pilih kategori --</option>
                    <?php foreach ($categoryOptions as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Keterangan</label>
                <input type="text" name="description" class="ss-input" placeholder="Contoh: Bensin speedboat trip snorkeling" required>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Jumlah (Rp)</label>
                <input type="text" name="amount" class="ss-input" placeholder="0" required>
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Referensi (opsional)</label>
                <input type="text" name="reference" class="ss-input" placeholder="No. nota / kwitansi">
            </div>
            <button type="submit" class="ss-btn ss-btn-primary" style="width:100%;">
                <i data-feather="save"></i> Simpan Transaksi
            </button>
        </form>
    </div>

    <div>
        <div class="ss-card" style="margin-bottom:18px;">
            <div class="ss-card-header">
                <div class="ss-card-title">Buku Kas Operasional</div>
            </div>
            <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
                <div class="ss-form-group" style="margin:0;">
                    <label class="ss-label" style="font-size:11px;">Dari Tanggal</label>
                    <input type="date" name="date_from" class="ss-input" style="font-size:12px;padding:6px 8px;" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="ss-form-group" style="margin:0;">
                    <label class="ss-label" style="font-size:11px;">Sampai Tanggal</label>
                    <input type="date" name="date_to" class="ss-input" style="font-size:12px;padding:6px 8px;" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="ss-form-group" style="margin:0;">
                    <label class="ss-label" style="font-size:11px;">Jenis</label>
                    <select name="type" class="ss-select" style="font-size:12px;padding:6px 8px;">
                        <option value="">Semua</option>
                        <option value="income" <?php echo $filterType === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                        <option value="expense" <?php echo $filterType === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                    </select>
                </div>
                <div class="ss-form-group" style="margin:0;min-width:200px;">
                    <label class="ss-label" style="font-size:11px;">Tamu</label>
                    <select name="customer_id" class="ss-select" style="font-size:12px;padding:6px 8px;">
                        <option value="0">Semua Tamu</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filterCust === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="filter"></i> Filter</button>
                <?php if ($filterCust > 0): ?>
                    <a href="finance.php?date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>" class="ss-btn ss-btn-outline ss-btn-sm">Reset Tamu</a>
                <?php endif; ?>
            </form>

            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th style="width:100px;">Tanggal</th>
                            <th style="width:90px;">Jenis</th>
                            <th>Keterangan</th>
                            <th>Tamu / Trip</th>
                            <th>Kategori</th>
                            <th style="width:130px;">Jumlah</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" style="text-align:center;color:var(--ss-muted);padding:20px;">Belum ada transaksi pada periode ini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($r['transaction_date'])); ?></td>
                                <td>
                                    <?php if ($r['type'] === 'income'): ?>
                                        <span class="ss-status ss-status-approved">Masuk</span>
                                    <?php else: ?>
                                        <span class="ss-status ss-status-draft">Keluar</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['description']); ?></td>
                                <td>
                                    <?php echo $r['customer_name'] ? htmlspecialchars($r['customer_name']) : '<span style="color:var(--ss-muted);">-</span>'; ?>
                                    <?php if ($r['booking_no']): ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($r['booking_no']); ?></small><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['category'] ?: '-'); ?></td>
                                <td style="font-weight:600;color:<?php echo $r['type'] === 'income' ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;">
                                    <?php echo ($r['type'] === 'income' ? '+ ' : '- ') . sunseaRupiah((float)$r['amount']); ?>
                                </td>
                                <td>
                                    <?php if (!$r['invoice_id']): ?>
                                        <a href="finance.php?action=delete&id=<?php echo $r['id']; ?>"
                                            onclick="return confirm('Hapus transaksi ini?');"
                                            style="color:var(--ss-danger);"><i data-feather="trash-2" style="width:14px;height:14px;"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:12px;">Ringkasan Pengeluaran / Tamu</div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Tamu</th>
                            <th style="width:100px;">Jumlah Transaksi</th>
                            <th style="width:150px;">Total Pengeluaran</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($perGuest)): ?>
                            <tr><td colspan="4" style="text-align:center;color:var(--ss-muted);padding:20px;">Belum ada pengeluaran per tamu pada periode ini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($perGuest as $g): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($g['name']); ?></td>
                                <td><?php echo (int)$g['tx_count']; ?></td>
                                <td style="font-weight:600;color:var(--ss-danger);"><?php echo sunseaRupiah((float)$g['total_expense']); ?></td>
                                <td>
                                    <a href="finance.php?date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&type=expense&customer_id=<?php echo $g['id']; ?>"
                                        class="ss-btn ss-btn-outline ss-btn-sm">Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function autoFillGuestFromBooking() {
        var sel = document.getElementById('bookingSelect');
        var custSel = document.getElementById('customerSelect');
        if (!sel || !custSel) return;
        var opt = sel.options[sel.selectedIndex];
        var custId = opt ? opt.getAttribute('data-customer-id') : '';
        if (custId) {
            custSel.value = custId;
        }
    }
</script>

<?php include 'layout-footer.php'; ?>
