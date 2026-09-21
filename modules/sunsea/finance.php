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

// ---- SAVE (tambah / edit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $editId      = (int)($_POST['edit_id'] ?? 0);
    $type        = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $date        = $_POST['transaction_date'] ?: date('Y-m-d');
    $time        = $_POST['transaction_time'] ?: date('H:i');
    $category    = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount      = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
    $customerId  = (int)($_POST['customer_id'] ?? 0) ?: null;
    $bookingId   = (int)($_POST['booking_id'] ?? 0) ?: null;
    $reference   = trim($_POST['reference'] ?? '');
    $inputBy     = trim($_POST['input_by'] ?? '') ?: $user;

    if ($description === '' || $amount <= 0) {
        $_SESSION['flash_message'] = 'Keterangan dan jumlah wajib diisi (jumlah harus lebih dari 0).';
        $_SESSION['flash_type']    = 'error';
    } elseif ($editId > 0) {
        $chk = $pdo->prepare("SELECT invoice_id, booking_item_id FROM cash_book WHERE id=?");
        $chk->execute([$editId]);
        $existing = $chk->fetch();
        if (!$existing) {
            $_SESSION['flash_message'] = 'Transaksi tidak ditemukan.';
            $_SESSION['flash_type']    = 'error';
        } elseif ($existing['invoice_id'] || $existing['booking_item_id']) {
            $_SESSION['flash_message'] = 'Transaksi otomatis dari invoice/pembayaran mitra tidak bisa diedit dari sini.';
            $_SESSION['flash_type']    = 'error';
        } else {
            try {
                $pdo->prepare("
                    UPDATE cash_book SET transaction_date=?, transaction_time=?, type=?, category=?, description=?, amount=?, reference=?, customer_id=?, booking_id=?, created_by=?
                    WHERE id=?
                ")->execute([$date, $time, $type, $category, $description, $amount, $reference, $customerId, $bookingId, $inputBy, $editId]);
                $_SESSION['flash_message'] = 'Transaksi kas berhasil diperbarui.';
                $_SESSION['flash_type']    = 'success';
            } catch (Exception $e) {
                $_SESSION['flash_message'] = 'Gagal menyimpan: ' . $e->getMessage();
                $_SESSION['flash_type']    = 'error';
            }
        }
    } else {
        try {
            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, transaction_time, type, category, description, amount, reference, customer_id, booking_id, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$date, $time, $type, $category, $description, $amount, $reference, $customerId, $bookingId, $inputBy]);
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

// ---- PAY / PELUNASAN INVOICE (dari popup Buku Kas yang sama) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_invoice') {
    $iId       = (int)($_POST['invoice_id'] ?? 0);
    $payAmount = (float)str_replace(['.', ','], ['', '.'], $_POST['pay_amount'] ?? '0');
    $payMethod = $_POST['pay_method'] ?? 'transfer';
    $payDate   = $_POST['pay_date'] ?: date('Y-m-d');
    $payRef    = trim($_POST['pay_reference'] ?? '');
    $payNotes  = trim($_POST['pay_notes'] ?? '');
    $payBy     = trim($_POST['pay_input_by'] ?? '') ?: $user;

    if ($iId <= 0 || $payAmount <= 0) {
        $_SESSION['flash_message'] = 'Pilih invoice dan isi jumlah pembayaran yang valid.';
        $_SESSION['flash_type']    = 'error';
    } else {
        $invStmt = $pdo->prepare("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
        $invStmt->execute([$iId]);
        $payInv = $invStmt->fetch();
        if (!$payInv) {
            $_SESSION['flash_message'] = 'Invoice tidak ditemukan.';
            $_SESSION['flash_type']    = 'error';
        } else {
            $pdo->prepare("
                INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$iId, $payDate, $payAmount, $payMethod, $payRef, $payNotes, $payBy]);
            $paymentId = (int)$pdo->lastInsertId();

            $totalPaidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?");
            $totalPaidStmt->execute([$iId]);
            $paid      = (float)$totalPaidStmt->fetchColumn();
            $total     = (float)$payInv['total_amount'];
            $remaining = max(0, $total - $paid);
            $newStatus = $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued');
            $paidAt    = $remaining <= 0 ? ', paid_at=NOW()' : '';
            $pdo->prepare("UPDATE invoices SET paid_amount=?, remaining_amount=?, status=? $paidAt WHERE id=?")
                ->execute([$paid, $remaining, $newStatus, $iId]);

            // Cari booking terkait (kalau invoice ini berasal dari booking) - pola sama seperti invoices.php.
            $payLinkedBookingId = null;
            if (preg_match('/Generated from Reservasi:\s*(\S+)/', (string)($payInv['internal_notes'] ?? ''), $mBk)) {
                $bkStmt = $pdo->prepare("SELECT id FROM booking_orders WHERE booking_no=?");
                $bkStmt->execute([$mBk[1]]);
                $payLinkedBookingId = $bkStmt->fetchColumn() ?: null;
            } elseif (preg_match('/^booking_id:(\d+)$/', (string)($payInv['internal_notes'] ?? ''), $mBk)) {
                $payLinkedBookingId = (int)$mBk[1];
            }

            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, transaction_time, type, category, description, amount, reference, invoice_id, payment_id, customer_id, booking_id, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $payDate,
                date('H:i:s'),
                'income',
                'Penerimaan Trip',
                "Pembayaran Invoice {$payInv['invoice_no']} — {$payInv['customer_name']}",
                $payAmount,
                $payRef ?: $payInv['invoice_no'],
                $iId,
                $paymentId,
                (int)$payInv['customer_id'],
                $payLinkedBookingId,
                $payBy
            ]);

            $_SESSION['flash_message'] = $remaining <= 0
                ? "Invoice {$payInv['invoice_no']} berhasil dilunasi."
                : "Pembayaran invoice {$payInv['invoice_no']} berhasil dicatat.";
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: finance.php' . (!empty($_POST['redirect_qs']) ? '?' . $_POST['redirect_qs'] : ''));
    exit;
}

// ---- DELETE ----
if (($_GET['action'] ?? '') === 'delete' && (int)($_GET['id'] ?? 0) > 0) {
    $delId = (int)$_GET['id'];
    $row = $pdo->prepare("SELECT invoice_id, booking_item_id FROM cash_book WHERE id=?");
    $row->execute([$delId]);
    $existing = $row->fetch();
    if ($existing && $existing['invoice_id']) {
        $_SESSION['flash_message'] = 'Transaksi ini tercatat otomatis dari pembayaran invoice dan tidak bisa dihapus dari sini.';
        $_SESSION['flash_type']    = 'error';
    } elseif ($existing && $existing['booking_item_id']) {
        $_SESSION['flash_message'] = 'Transaksi ini tercatat otomatis dari Pembayaran ke Mitra. Batalkan centang di halaman booking untuk menghapusnya.';
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

// Untuk tombol navigasi bulan (Bulan Sebelumnya / Bulan Ini / Bulan Berikutnya), dihitung dari bulan date_from yang aktif.
$finMonthBase      = strtotime($dateFrom) ?: time();
$finPrevMonthStart = date('Y-m-01', strtotime('-1 month', $finMonthBase));
$finPrevMonthEnd   = date('Y-m-t', strtotime('-1 month', $finMonthBase));
$finThisMonthStart = date('Y-m-01');
$finThisMonthEnd   = date('Y-m-d');
$finNextMonthStart = date('Y-m-01', strtotime('+1 month', $finMonthBase));
$finNextMonthEnd   = date('Y-m-t', strtotime('+1 month', $finMonthBase));
$finMonthQs = ($filterType ? '&type=' . urlencode($filterType) : '') . ($filterCust > 0 ? '&customer_id=' . $filterCust : '');

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

// Invoice yang masih ada sisa tagihan - dipakai di tab "Pelunasan Invoice" pada popup Buku Kas.
$outstandingInvoices = $pdo->query("
    SELECT i.id, i.invoice_no, i.total_amount, i.paid_amount, c.name AS customer_name
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    WHERE i.status IN ('issued','partial')
    ORDER BY i.created_at DESC
")->fetchAll();
foreach ($outstandingInvoices as &$_oi) {
    $_oi['remaining_amount'] = max(0, (float)$_oi['total_amount'] - (float)$_oi['paid_amount']);
}
unset($_oi);
$outstandingInvoices = array_values(array_filter($outstandingInvoices, fn($oi) => $oi['remaining_amount'] > 0));

$pageTitle  = 'Finance - Buku Kas Operasional';
$activePage = 'finance';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;">
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
        <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($totalIncome); ?></div>
    </div>
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
        <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($totalExpense); ?></div>
    </div>
    <div class="ss-card">
        <div style="font-size:12px;color:var(--ss-muted);">Saldo Kas (periode ini)</div>
        <div style="font-size:17px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah($balance); ?></div>
    </div>
</div>

<div>
    <div class="ss-card" style="margin-bottom:18px;">
        <div class="ss-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div class="ss-card-title" style="font-size:13px;">Buku Kas Operasional</div>
            <button type="button" onclick="openTxModal()" class="fin-add-btn">
                <i data-feather="plus" style="width:12px;height:12px;"></i>
                <span>Tambah Transaksi</span>
            </button>
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
            <div style="display:flex;gap:6px;margin-left:auto;">
                <a href="finance.php?date_from=<?php echo $finPrevMonthStart; ?>&date_to=<?php echo $finPrevMonthEnd . $finMonthQs; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="<?php echo date('F Y', strtotime($finPrevMonthStart)); ?>"><i data-feather="chevron-left"></i> Bulan Sebelumnya</a>
                <a href="finance.php?date_from=<?php echo $finThisMonthStart; ?>&date_to=<?php echo $finThisMonthEnd . $finMonthQs; ?>" class="ss-btn ss-btn-outline ss-btn-sm">Bulan Ini</a>
                <a href="finance.php?date_from=<?php echo $finNextMonthStart; ?>&date_to=<?php echo $finNextMonthEnd . $finMonthQs; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="<?php echo date('F Y', strtotime($finNextMonthStart)); ?>">Bulan Berikutnya <i data-feather="chevron-right"></i></a>
            </div>
        </form>

        <div class="ss-table-wrap">
            <table class="ss-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="width:100px;font-size:11px;">Tanggal</th>
                        <th style="width:60px;font-size:11px;">Jam</th>
                        <th style="width:90px;font-size:11px;">Jenis</th>
                        <th style="font-size:11px;">Keterangan</th>
                        <th style="font-size:11px;">Tamu / Trip</th>
                        <th style="font-size:11px;">Kategori</th>
                        <th style="width:130px;font-size:11px;">Jumlah</th>
                        <th style="width:110px;font-size:11px;">Diinput Oleh</th>
                        <th style="width:40px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="9" style="text-align:center;color:var(--ss-muted);padding:20px;font-size:12px;">Belum ada transaksi pada periode ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php $finLastDate = null; ?>
                    <?php foreach ($rows as $r): ?>
                        <?php if ($r['transaction_date'] !== $finLastDate): $finLastDate = $r['transaction_date']; ?>
                            <tr>
                                <td colspan="9" style="background:var(--ss-gray-1);font-weight:700;font-size:11.5px;padding:6px 10px;color:var(--ss-ocean);">
                                    <?php echo htmlspecialchars(date('d M Y', strtotime($finLastDate))); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="font-size:12px;"><?php echo date('d/m/Y', strtotime($r['transaction_date'])); ?></td>
                            <td style="font-size:12px;color:var(--ss-muted);"><?php echo $r['transaction_time'] ? date('H:i', strtotime($r['transaction_time'])) : '-'; ?></td>
                            <td>
                                <?php if ($r['type'] === 'income'): ?>
                                    <span class="ss-status ss-status-approved" style="font-size:11px;">Masuk</span>
                                <?php else: ?>
                                    <span class="ss-status ss-status-rejected" style="font-size:11px;">Keluar</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($r['description']); ?></td>
                            <td style="font-size:12px;">
                                <?php echo $r['customer_name'] ? htmlspecialchars($r['customer_name']) : '<span style="color:var(--ss-muted);">-</span>'; ?>
                                <?php if ($r['booking_no']): ?><br><small style="color:var(--ss-muted);font-size:11px;"><?php echo htmlspecialchars($r['booking_no']); ?></small><?php endif; ?>
                            </td>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($r['category'] ?: '-'); ?></td>
                            <td style="font-size:12px;font-weight:600;color:<?php echo $r['type'] === 'income' ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;">
                                <?php echo ($r['type'] === 'income' ? '+ ' : '- ') . sunseaRupiah((float)$r['amount']); ?>
                            </td>
                            <td style="font-size:11.5px;color:var(--ss-muted);"><?php echo htmlspecialchars($r['created_by'] ?: '-'); ?></td>
                            <td>
                                <?php if (!$r['invoice_id'] && !$r['booking_item_id']): ?>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <a href="javascript:void(0)" onclick='openEditTx(<?php echo json_encode([
                                                                                                "id" => (int)$r["id"],
                                                                                                "type" => $r["type"],
                                                                                                "date" => $r["transaction_date"],
                                                                                                "time" => $r["transaction_time"] ? date("H:i", strtotime($r["transaction_time"])) : "",
                                                                                                "category" => $r["category"],
                                                                                                "description" => $r["description"],
                                                                                                "amount" => (float)$r["amount"],
                                                                                                "customer_id" => $r["customer_id"],
                                                                                                "booking_id" => $r["booking_id"],
                                                                                                "reference" => $r["reference"],
                                                                                                "input_by" => $r["created_by"],
                                                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)' style="color:var(--ss-ocean);" title="Edit transaksi"><i data-feather="edit-3" style="width:14px;height:14px;"></i></a>
                                        <a href="finance.php?action=delete&id=<?php echo $r['id']; ?>"
                                            onclick="return confirm('Hapus transaksi ini?');"
                                            style="color:var(--ss-danger);" title="Hapus transaksi"><i data-feather="trash-2" style="width:14px;height:14px;"></i></a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if (!empty($rows)): ?>
                    <tfoot>
                        <tr style="border-top:2px solid var(--ss-gray-2);">
                            <td colspan="6" style="font-size:12px;text-align:right;"><strong>Total <?php echo count($rows); ?> transaksi (sesuai filter di atas):</strong></td>
                            <td style="font-size:12px;font-weight:800;">
                                <?php if ($filterType === 'income'): ?>
                                    <span style="color:var(--ss-success);">+ <?php echo sunseaRupiah($totalIncome); ?></span>
                                <?php elseif ($filterType === 'expense'): ?>
                                    <span style="color:var(--ss-danger);">- <?php echo sunseaRupiah($totalExpense); ?></span>
                                <?php else: ?>
                                    <span style="color:<?php echo $balance < 0 ? 'var(--ss-danger)' : 'var(--ss-ocean)'; ?>;"><?php echo sunseaRupiah($balance); ?></span>
                                <?php endif; ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;font-size:13px;">Ringkasan Pengeluaran / Tamu</div>
        <div class="ss-table-wrap">
            <table class="ss-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="font-size:11px;">Tamu</th>
                        <th style="width:100px;font-size:11px;">Jumlah Transaksi</th>
                        <th style="width:150px;font-size:11px;">Total Pengeluaran</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($perGuest)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;color:var(--ss-muted);padding:20px;font-size:12px;">Belum ada pengeluaran per tamu pada periode ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($perGuest as $g): ?>
                        <tr>
                            <td style="font-size:12px;"><?php echo htmlspecialchars($g['name']); ?></td>
                            <td style="font-size:12px;"><?php echo (int)$g['tx_count']; ?></td>
                            <td style="font-size:12px;font-weight:600;color:var(--ss-danger);"><?php echo sunseaRupiah((float)$g['total_expense']); ?></td>
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

<!-- Modal: Tambah Transaksi Kas -->
<div id="txModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="width:100%;max-width:720px;max-height:90vh;display:flex;flex-direction:column;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 50px rgba(15,23,42,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid var(--ss-gray-1);flex-shrink:0;background:linear-gradient(135deg,#FFF7ED,#fff);">
            <div>
                <div style="font-size:15px;font-weight:800;color:var(--ss-ocean);" id="txModalTitle">Input Transaksi Kas</div>
                <div style="font-size:11px;color:var(--ss-muted);margin-top:2px;">Catat pemasukan/pengeluaran, atau lunasi invoice tamu.</div>
            </div>
            <button type="button" onclick="closeTxModal()" style="background:#fff;border:1px solid var(--ss-gray-1);border-radius:8px;cursor:pointer;color:var(--ss-muted);padding:6px;">
                <i data-feather="x" style="width:16px;height:16px;"></i>
            </button>
        </div>

        <div class="fin-tab-bar">
            <button type="button" class="fin-tab-btn active" id="finTabBtnManual" onclick="switchTxTab('manual')">
                <i data-feather="edit-3" style="width:13px;height:13px;"></i> Transaksi Manual
            </button>
            <button type="button" class="fin-tab-btn" id="finTabBtnInvoice" onclick="switchTxTab('invoice')">
                <i data-feather="check-circle" style="width:13px;height:13px;"></i> Pelunasan Invoice
            </button>
        </div>

        <div style="flex:1;overflow:auto;padding:18px 22px;">
            <!-- TAB 1: Transaksi manual (pemasukan/pengeluaran biasa) -->
            <div id="finTabManual">
                <form method="POST" id="txForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="edit_id" id="editIdInput" value="">
                    <input type="hidden" name="redirect_qs" value="<?php echo htmlspecialchars(http_build_query($_GET)); ?>">
                    <select name="type" id="typeSelect" style="display:none;">
                        <option value="expense">Pengeluaran</option>
                        <option value="income">Pemasukan</option>
                    </select>

                    <div class="fin-type-toggle">
                        <button type="button" class="fin-type-btn fin-type-expense active" data-type="expense" onclick="setTxType('expense')">
                            <i data-feather="arrow-down-circle"></i> Pengeluaran
                        </button>
                        <button type="button" class="fin-type-btn fin-type-income" data-type="income" onclick="setTxType('income')">
                            <i data-feather="arrow-up-circle"></i> Pemasukan
                        </button>
                    </div>

                    <div class="fin-section-title">Detail Transaksi</div>
                    <div class="fin-modal-grid">
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Tanggal</label>
                            <input type="date" name="transaction_date" class="ss-input" style="font-size:12px;" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Jam Transaksi</label>
                            <input type="time" name="transaction_time" class="ss-input" style="font-size:12px;" value="<?php echo date('H:i'); ?>" required>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Kategori</label>
                            <select name="category" class="ss-select" style="font-size:12px;">
                                <option value="">-- Pilih kategori --</option>
                                <?php foreach ($categoryOptions as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Jumlah (Rp)</label>
                            <input type="text" name="amount" class="ss-input" style="font-size:12px;font-weight:700;" placeholder="0" required>
                        </div>
                        <div class="ss-form-group" style="margin:0;grid-column:1 / -1;">
                            <label class="ss-label" style="font-size:11px;">Keterangan</label>
                            <input type="text" name="description" class="ss-input" style="font-size:12px;" placeholder="Contoh: Bensin speedboat trip snorkeling" required>
                        </div>
                    </div>

                    <div class="fin-section-title">Kaitkan ke Tamu / Trip <span style="font-weight:400;color:var(--ss-muted);">(opsional)</span></div>
                    <div class="fin-modal-grid">
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Trip / Booking</label>
                            <select name="booking_id" class="ss-select" id="bookingSelect" style="font-size:12px;" onchange="autoFillGuestFromBooking()">
                                <option value="">-- Tidak terkait trip tertentu --</option>
                                <?php foreach ($bookings as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" data-customer-id="<?php echo $b['customer_id']; ?>">
                                        <?php echo htmlspecialchars($b['booking_no']); ?> — <?php echo htmlspecialchars($b['customer_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Tamu / Customer</label>
                            <select name="customer_id" class="ss-select" id="customerSelect" style="font-size:12px;">
                                <option value="">-- Operasional Perusahaan (bukan tamu tertentu) --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?><?php echo $c['phone'] ? ' - ' . htmlspecialchars($c['phone']) : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="grid-column:1 / -1;font-size:10.5px;color:var(--ss-muted);margin-top:-4px;">* Tamu otomatis terisi saat memilih Trip/Booking, tapi bisa diganti manual.</div>
                    </div>

                    <div class="fin-section-title">Lainnya</div>
                    <div class="fin-modal-grid">
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Referensi (opsional)</label>
                            <input type="text" name="reference" class="ss-input" style="font-size:12px;" placeholder="No. nota / kwitansi">
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Diinput Oleh</label>
                            <input type="text" name="input_by" class="ss-input" style="font-size:12px;" value="<?php echo htmlspecialchars($user); ?>" placeholder="Nama staff yang input" required>
                        </div>
                    </div>

                    <button type="submit" class="ss-btn ss-btn-primary" style="width:100%;font-size:12px;margin-top:16px;" id="txSubmitBtn">
                        <i data-feather="save"></i> Simpan Transaksi
                    </button>
                </form>
            </div>

            <!-- TAB 2: Pelunasan / pembayaran invoice tamu yang masih ada tagihan -->
            <div id="finTabInvoice" style="display:none;">
                <form method="POST" id="payInvoiceForm">
                    <input type="hidden" name="action" value="pay_invoice">
                    <input type="hidden" name="redirect_qs" value="<?php echo htmlspecialchars(http_build_query($_GET)); ?>">

                    <div class="fin-section-title" style="margin-top:0;">Pilih Invoice yang Belum Lunas</div>
                    <div class="ss-form-group" style="margin:0;">
                        <select name="invoice_id" id="payInvoiceSelect" class="ss-select" style="font-size:12px;" onchange="onPayInvoiceChange()" required>
                            <option value="">-- Pilih invoice --</option>
                            <?php foreach ($outstandingInvoices as $oi): ?>
                                <option value="<?php echo $oi['id']; ?>"
                                    data-total="<?php echo (float)$oi['total_amount']; ?>"
                                    data-paid="<?php echo (float)$oi['paid_amount']; ?>"
                                    data-remaining="<?php echo (float)$oi['remaining_amount']; ?>">
                                    <?php echo htmlspecialchars($oi['invoice_no']); ?> — <?php echo htmlspecialchars($oi['customer_name']); ?> (Sisa <?php echo sunseaRupiah($oi['remaining_amount']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($outstandingInvoices)): ?>
                            <div style="font-size:11.5px;color:var(--ss-success);margin-top:6px;">🎉 Semua invoice sudah lunas, tidak ada tagihan tersisa.</div>
                        <?php endif; ?>
                    </div>

                    <div id="payInvoiceSummary" class="fin-pis-card" style="display:none;">
                        <div><span>Total Tagihan</span><strong id="pisTotal">Rp 0</strong></div>
                        <div><span>Sudah Dibayar</span><strong id="pisPaid" style="color:var(--ss-success);">Rp 0</strong></div>
                        <div><span>Sisa Tagihan</span><strong id="pisRemaining" style="color:var(--ss-danger);">Rp 0</strong></div>
                    </div>

                    <div class="fin-section-title">Detail Pembayaran</div>
                    <div class="fin-modal-grid">
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Jumlah Dibayar (Rp)</label>
                            <input type="text" name="pay_amount" id="payAmountInput" class="ss-input" style="font-size:12px;font-weight:700;" placeholder="0" required>
                            <div style="font-size:10.5px;color:var(--ss-muted);margin-top:4px;">Otomatis terisi sisa tagihan (pelunasan penuh) - bisa diubah untuk DP bertahap.</div>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Tanggal Bayar</label>
                            <input type="date" name="pay_date" class="ss-input" style="font-size:12px;" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">Metode</label>
                            <select name="pay_method" class="ss-select" style="font-size:12px;">
                                <option value="transfer">Transfer Bank</option>
                                <option value="cash">Tunai</option>
                                <option value="qris">QRIS</option>
                                <option value="other">Lainnya</option>
                            </select>
                        </div>
                        <div class="ss-form-group" style="margin:0;">
                            <label class="ss-label" style="font-size:11px;">No. Referensi (opsional)</label>
                            <input type="text" name="pay_reference" class="ss-input" style="font-size:12px;" placeholder="No. bukti transfer">
                        </div>
                        <div class="ss-form-group" style="margin:0;grid-column:1 / -1;">
                            <label class="ss-label" style="font-size:11px;">Catatan (opsional)</label>
                            <textarea name="pay_notes" class="ss-textarea" style="font-size:12px;" rows="2"></textarea>
                        </div>
                        <div class="ss-form-group" style="margin:0;grid-column:1 / -1;">
                            <label class="ss-label" style="font-size:11px;">Diinput Oleh</label>
                            <input type="text" name="pay_input_by" class="ss-input" style="font-size:12px;" value="<?php echo htmlspecialchars($user); ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="ss-btn" style="width:100%;font-size:12px;margin-top:16px;background:var(--ss-success);border-color:var(--ss-success);color:#fff;">
                        <i data-feather="check-circle"></i> Catat Pembayaran / Pelunasan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .fin-modal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px 14px;
    }

    @media (max-width: 560px) {
        .fin-modal-grid {
            grid-template-columns: 1fr;
        }
    }

    .fin-add-btn {
        display: flex;
        align-items: center;
        gap: 5px;
        height: 28px;
        padding: 0 12px;
        background: var(--ss-ocean);
        border: 1px solid var(--ss-ocean);
        border-radius: 6px;
        color: #fff;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }

    .fin-add-btn:hover {
        opacity: .9;
    }

    .fin-tab-bar {
        display: flex;
        gap: 6px;
        padding: 10px 22px 0;
        border-bottom: 1px solid var(--ss-gray-1);
        flex-shrink: 0;
    }

    .fin-tab-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 9px 16px;
        background: none;
        border: none;
        border-bottom: 2px solid transparent;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--ss-muted);
        cursor: pointer;
    }

    .fin-tab-btn.active {
        color: var(--ss-ocean);
        border-bottom-color: var(--ss-ocean);
    }

    .fin-type-toggle {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }

    .fin-type-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 10px;
        border-radius: 8px;
        border: 1.5px solid var(--ss-gray-1);
        background: #fff;
        color: var(--ss-muted);
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
    }

    .fin-type-btn.active.fin-type-expense {
        background: #FEF2F2;
        border-color: var(--ss-danger);
        color: var(--ss-danger);
    }

    .fin-type-btn.active.fin-type-income {
        background: #ECFDF5;
        border-color: var(--ss-success);
        color: var(--ss-success);
    }

    .fin-section-title {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--ss-ocean);
        text-transform: uppercase;
        letter-spacing: .03em;
        margin: 16px 0 8px;
        padding-bottom: 6px;
        border-bottom: 1px dashed var(--ss-gray-1);
    }

    .fin-pis-card {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        background: #FFF7ED;
        border: 1px solid #FED7AA;
        border-radius: 10px;
        padding: 12px 14px;
        margin: 12px 0 4px;
    }

    .fin-pis-card>div {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .fin-pis-card span {
        font-size: 10.5px;
        color: var(--ss-muted);
    }

    .fin-pis-card strong {
        font-size: 13px;
    }

    @media (max-width: 560px) {
        .fin-pis-card {
            grid-template-columns: 1fr;
        }
    }
</style>

<script>
    function switchTxTab(tab) {
        var isManual = tab === 'manual';
        document.getElementById('finTabManual').style.display = isManual ? '' : 'none';
        document.getElementById('finTabInvoice').style.display = isManual ? 'none' : '';
        document.getElementById('finTabBtnManual').classList.toggle('active', isManual);
        document.getElementById('finTabBtnInvoice').classList.toggle('active', !isManual);
    }

    function setTxType(type) {
        document.getElementById('typeSelect').value = type;
        document.querySelectorAll('.fin-type-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-type') === type);
        });
    }

    function onPayInvoiceChange() {
        var sel = document.getElementById('payInvoiceSelect');
        var opt = sel.options[sel.selectedIndex];
        var summary = document.getElementById('payInvoiceSummary');
        if (!opt || !opt.value) {
            summary.style.display = 'none';
            return;
        }
        var total = parseFloat(opt.getAttribute('data-total')) || 0;
        var paid = parseFloat(opt.getAttribute('data-paid')) || 0;
        var remaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
        document.getElementById('pisTotal').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
        document.getElementById('pisPaid').textContent = 'Rp ' + Math.round(paid).toLocaleString('id-ID');
        document.getElementById('pisRemaining').textContent = 'Rp ' + Math.round(remaining).toLocaleString('id-ID');
        summary.style.display = 'grid';
        document.getElementById('payAmountInput').value = Math.round(remaining).toLocaleString('id-ID');
    }

    function openTxModal() {
        document.getElementById('txForm').reset();
        document.getElementById('payInvoiceForm').reset();
        document.getElementById('payInvoiceSummary').style.display = 'none';
        document.getElementById('editIdInput').value = '';
        document.getElementById('txModalTitle').textContent = 'Input Transaksi Kas';
        document.getElementById('txSubmitBtn').innerHTML = '<i data-feather="save"></i> Simpan Transaksi';
        document.getElementById('txForm').querySelector('[name="transaction_date"]').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('txForm').querySelector('[name="transaction_time"]').value = '<?php echo date('H:i'); ?>';
        document.getElementById('txForm').querySelector('[name="input_by"]').value = '<?php echo htmlspecialchars($user, ENT_QUOTES); ?>';
        setTxType('expense');
        switchTxTab('manual');
        document.getElementById('txModalOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (window.feather) feather.replace();
    }

    function openEditTx(tx) {
        var form = document.getElementById('txForm');
        form.querySelector('[name="type"]').value = tx.type;
        form.querySelector('[name="transaction_date"]').value = tx.date;
        form.querySelector('[name="transaction_time"]').value = tx.time || '00:00';
        form.querySelector('[name="booking_id"]').value = tx.booking_id || '';
        form.querySelector('[name="customer_id"]').value = tx.customer_id || '';
        form.querySelector('[name="category"]').value = tx.category || '';
        form.querySelector('[name="amount"]').value = tx.amount ? Math.round(tx.amount).toLocaleString('id-ID') : '';
        form.querySelector('[name="description"]').value = tx.description || '';
        form.querySelector('[name="reference"]').value = tx.reference || '';
        form.querySelector('[name="input_by"]').value = tx.input_by || '<?php echo htmlspecialchars($user, ENT_QUOTES); ?>';
        document.getElementById('editIdInput').value = tx.id;
        document.getElementById('txModalTitle').textContent = 'Edit Transaksi Kas';
        document.getElementById('txSubmitBtn').innerHTML = '<i data-feather="save"></i> Simpan Perubahan';
        setTxType(tx.type === 'income' ? 'income' : 'expense');
        switchTxTab('manual');
        document.getElementById('txModalOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (window.feather) feather.replace();
    }

    function closeTxModal() {
        document.getElementById('txModalOverlay').style.display = 'none';
        document.body.style.overflow = '';
    }

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

    <?php if (isset($_SESSION['flash_type']) && $_SESSION['flash_type'] === 'error' && strpos($_SESSION['flash_message'] ?? '', 'wajib diisi') !== false): ?>
        // keep modal closed on validation error handled via flash message on list page
    <?php endif; ?>
</script>

<?php include 'layout-footer.php'; ?>