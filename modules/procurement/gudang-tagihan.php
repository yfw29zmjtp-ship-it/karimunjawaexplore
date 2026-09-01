<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Tagihan Gudang';
$currentUser = $auth->getCurrentUser();

// ── Ensure TKBM table exists ─────────────────────────────────────────────────
try {
    $db->query("CREATE TABLE IF NOT EXISTS gudang_nasita_tkbm (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tanggal DATE NOT NULL,
        total_biaya DECIMAL(15,2) NOT NULL DEFAULT 0,
        keterangan TEXT NULL,
        jumlah_bisnis TINYINT DEFAULT 3,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}

// ── POST: tambah TKBM ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_tkbm') {
    $tanggal    = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $biaya      = (float)($_POST['total_biaya'] ?? 0);
    $ket        = trim($_POST['keterangan'] ?? '');
    $jmlBisnis  = max(1, (int)($_POST['jumlah_bisnis'] ?? 3));
    if ($biaya > 0) {
        $db->insert('gudang_nasita_tkbm', [
            'tanggal'       => $tanggal,
            'total_biaya'   => $biaya,
            'keterangan'    => $ket ?: null,
            'jumlah_bisnis' => $jmlBisnis,
            'created_by'    => (int)($currentUser['id'] ?? 0),
        ]);
        $_SESSION['success'] = 'TKBM berhasil ditambahkan.';
    }
    header('Location: gudang-tagihan.php');
    exit;
}

// ── POST: hapus TKBM ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tkbm') {
    $tid = (int)($_POST['tkbm_id'] ?? 0);
    if ($tid > 0) {
        $db->query('DELETE FROM gudang_nasita_tkbm WHERE id = ?', [$tid]);
        $_SESSION['success'] = 'TKBM dihapus.';
    }
    header('Location: gudang-tagihan.php');
    exit;
}

// ── TKBM records ─────────────────────────────────────────────────────────────
$tkbmRows = [];
try {
    $tkbmRows = $db->fetchAll('SELECT * FROM gudang_nasita_tkbm ORDER BY tanggal DESC LIMIT 100') ?: [];
} catch (Throwable $e) {
}
$tkbmTotal = array_sum(array_column($tkbmRows, 'total_biaya'));

// ── Tagihan ke Supplier ───────────────────────────────────────────────────────
$supplierBills = [];
try {
    // Tagihan supplier dihitung dari received_quantity × unit_price (bukan total PO yang dipesan)
    $supplierBills = $db->fetchAll(
        "SELECT poh.id, poh.po_number, poh.po_date, poh.status,
                COALESCE(s.supplier_name, '-') AS supplier_name,
                COALESCE(SUM(pod.received_quantity), 0)   AS received_qty,
                COALESCE(SUM(pod.quantity), 0)            AS ordered_qty,
                COALESCE(SUM(pod.received_quantity * pod.unit_price), 0) AS total_amount
         FROM purchase_orders_header poh
         LEFT JOIN suppliers s ON s.id = poh.supplier_id
         LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
         WHERE poh.status NOT IN ('cancelled', 'draft')
         GROUP BY poh.id
         HAVING received_qty > 0
         ORDER BY poh.po_date DESC
         LIMIT 100"
    ) ?: [];
} catch (Throwable $e) {
    error_log('gudang-tagihan supplier bills: ' . $e->getMessage());
}

// ── Tagihan ke Bisnis — switch ke Gudang DB karena tabelnya ada di sana ──────
$bizBills = [];
$bizTransferDetail = [];
$detailByBiz = [];
try {
    $gudangCfgPath = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    $gudangDbName  = '';
    if (file_exists($gudangCfgPath)) {
        $gc = require $gudangCfgPath;
        $gudangDbName = (string)($gc['database'] ?? '');
    }
    $originDb = Database::getCurrentDatabase();
    if ($gudangDbName && $gudangDbName !== $originDb) {
        $gudangDb = Database::switchDatabase($gudangDbName);
    } else {
        $gudangDb = $db;
    }

    // Self-heal historical transfer items that were stored with Rp 0 unit_price/subtotal
    // (e.g. transfers created before the price-fallback fix in transferGudangNasitaStock()).
    gudangNasitaBackfillZeroPriceTransferItems($gudangDb);

    $bizBills = $gudangDb->fetchAll(
        "SELECT gt.target_business_name,
                COUNT(DISTINCT gt.id)                                                                    AS transfer_count,
                COALESCE(SUM(gti.quantity), 0)                                                           AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0)    AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.target_business_name
         ORDER BY total_nilai DESC"
    ) ?: [];

    $bizTransferDetail = $gudangDb->fetchAll(
        "SELECT gt.id, gt.transfer_number, gt.target_business_name, gt.status,
                gt.created_at,
                COALESCE(SUM(gti.quantity), 0)                                                           AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0)    AS total_nilai,
                COUNT(gti.id) AS items_count
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled')
         GROUP BY gt.id
         ORDER BY gt.target_business_name ASC, gt.created_at DESC
         LIMIT 500"
    ) ?: [];

    if ($gudangDbName && $gudangDbName !== $originDb) {
        Database::switchDatabase($originDb);
    }
} catch (Throwable $e) {
    error_log('gudang-tagihan biz bills: ' . $e->getMessage());
}

// Group transfer detail by business name
$detailByBiz = [];
foreach ($bizTransferDetail as $row) {
    $biz = $row['target_business_name'] ?? '-';
    $detailByBiz[$biz][] = $row;
}

// ── 3 bisnis operasional tetap (dengan ikon) yang menerima transfer dari Gudang Nasita ──
$gudangMonthlyBizList = [
    ['slug' => 'narayana-hotel', 'name' => 'Narayana Hotel', 'icon' => '🏨'],
    ['slug' => 'bens-cafe',      'name' => 'Bens Cafe',      'icon' => '☕'],
    ['slug' => 'eaat-meet',      'name' => 'Eat Meet',       'icon' => '🍽️'],
];

// Perpindahan barang ANTAR BISNIS (bukan dari Gudang) juga harus memindahkan tagihan:
// bisnis pengirim dikurangi sebesar nilai barang, bisnis penerima ditambah sebesar nilai yang sama
// (kecuali barang dikembalikan ke Gudang, yang hanya mengurangi tagihan pengirim).
$interBizAdjAllTime = getBusinessInterStockTransferBillAdjustments(array_column($gudangMonthlyBizList, 'slug'));
foreach ($bizBills as &$bizBillRow) {
    $bizSlugForAdj = gudangTagihanMatchBizSlug((string)($bizBillRow['target_business_name'] ?? ''));
    if ($bizSlugForAdj !== null && isset($interBizAdjAllTime[$bizSlugForAdj])) {
        $bizBillRow['total_nilai'] = max(0, (float)$bizBillRow['total_nilai'] + $interBizAdjAllTime[$bizSlugForAdj]);
    }
}
unset($bizBillRow);

// gudangTagihanMatchBizSlug() now lives in includes/procurement_functions.php (shared with
// api/pay-gudang-bill.php so the Bills menu's Gudang tab uses the exact same matching logic).

// Resolve each business' own company logo (from its own DB settings, config, or uploads/logos file) so
// the billing cards show the real logo instead of a generic emoji.
function gudangTagihanResolveBizLogoUrl(string $slug): ?string
{
    static $cache = [];
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    $cache[$slug] = null;

    $cfgPath = __DIR__ . '/../../config/businesses/' . $slug . '.php';
    if (!file_exists($cfgPath)) {
        return null;
    }
    $cfg = require $cfgPath;
    $dbName = (string)($cfg['database'] ?? '');
    if ($dbName === '') {
        return null;
    }

    $originDb = Database::getCurrentDatabase();
    try {
        $bizDb = ($dbName !== $originDb) ? Database::switchDatabase($dbName) : Database::getInstance();

        $val = null;
        $row = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1", ['company_logo_' . $slug]);
        $val = $row['setting_value'] ?? null;
        if (!$val) {
            $row = $bizDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo' LIMIT 1");
            $val = $row['setting_value'] ?? null;
        }
        if (!$val && !empty($cfg['logo'])) {
            $val = $cfg['logo'];
        }

        if ($val) {
            if (strpos($val, 'http') === 0) {
                $cache[$slug] = $val;
            } else {
                $logoPath = __DIR__ . '/../../uploads/logos/' . $val;
                if (file_exists($logoPath)) {
                    $cache[$slug] = BASE_URL . '/uploads/logos/' . $val . '?v=' . filemtime($logoPath);
                }
            }
        }

        if ($cache[$slug] === null) {
            foreach (['png', 'jpg', 'jpeg', 'gif'] as $ext) {
                $defaultLogo = __DIR__ . '/../../uploads/logos/' . $slug . '_logo.' . $ext;
                if (file_exists($defaultLogo)) {
                    $cache[$slug] = BASE_URL . '/uploads/logos/' . $slug . '_logo.' . $ext . '?v=' . filemtime($defaultLogo);
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('gudang-tagihan biz logo [' . $slug . ']: ' . $e->getMessage());
    } finally {
        if ($dbName !== '' && $dbName !== $originDb) {
            Database::switchDatabase($originDb);
        }
    }

    return $cache[$slug];
}

// ── Pembayaran Tagihan Bulanan (bisnis -> Gudang Nasita) ─────────────────────────
// gudangTagihanEnsurePaymentsTable(), gudangTagihanGetGudangDb() and gudangTagihanPayMonthlyBill()
// now live in includes/procurement_functions.php (shared with api/pay-gudang-bill.php).

// ── POST: bayar tagihan bulanan (bisnis -> Gudang Nasita, potong rekening bank bisnis) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_monthly_bill') {
    $paySlug  = trim((string)($_POST['slug'] ?? ''));
    $payMonth = trim((string)($_POST['bulan'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}$/', $payMonth)) {
        $payMonth = date('Y-m');
    }

    try {
        $payMsg = gudangTagihanPayMonthlyBill($paySlug, $payMonth, (int)($currentUser['id'] ?? 0));
        setFlash('success', $payMsg);
    } catch (Throwable $e) {
        error_log('gudang-tagihan pay monthly bill: ' . $e->getMessage());
        setFlash('error', 'Gagal memproses pembayaran: ' . $e->getMessage());
    }
    header('Location: gudang-tagihan.php?bulan=' . urlencode($payMonth));
    exit;
}

// ── Rekap Tagihan Bulanan per Bisnis (transfer bulan berjalan + share TKBM) ──────
$selectedMonth = trim((string)($_GET['bulan'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$tkbmMonthTotal = 0.0;
try {
    $tkbmRow = $db->fetchOne(
        'SELECT COALESCE(SUM(total_biaya), 0) AS t FROM gudang_nasita_tkbm WHERE tanggal BETWEEN ? AND ?',
        [$monthStart, $monthEnd]
    );
    $tkbmMonthTotal = (float)($tkbmRow['t'] ?? 0);
} catch (Throwable $e) {
    error_log('gudang-tagihan tkbm month total: ' . $e->getMessage());
}
$tkbmShareThisMonth = $tkbmMonthTotal / count($gudangMonthlyBizList);

$monthlyTransferBySlug = [];
$monthlyTransferRowsBySlug = [];
try {
    $gudangCfgPath2 = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    $gudangDbName2  = '';
    if (file_exists($gudangCfgPath2)) {
        $gc2 = require $gudangCfgPath2;
        $gudangDbName2 = (string)($gc2['database'] ?? '');
    }
    $originDb2 = Database::getCurrentDatabase();
    $gudangDb2 = ($gudangDbName2 && $gudangDbName2 !== $originDb2)
        ? Database::switchDatabase($gudangDbName2)
        : $db;

    $monthRows = $gudangDb2->fetchAll(
        "SELECT gt.target_business_name,
                COUNT(DISTINCT gt.id) AS transfer_count,
                COALESCE(SUM(gti.quantity), 0) AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled') AND gt.created_at BETWEEN ? AND ?
         GROUP BY gt.target_business_name",
        [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']
    ) ?: [];

    // Per-transfer breakdown for the month, used to build the click-to-view billing detail / print invoice.
    $monthTransferDetailRows = $gudangDb2->fetchAll(
        "SELECT gt.transfer_number, gt.target_business_name, gt.status, gt.created_at,
                COALESCE(SUM(gti.quantity), 0) AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled') AND gt.created_at BETWEEN ? AND ?
         GROUP BY gt.id
         ORDER BY gt.target_business_name ASC, gt.created_at DESC",
        [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']
    ) ?: [];

    if ($gudangDbName2 && $gudangDbName2 !== $originDb2) {
        Database::switchDatabase($originDb2);
    }

    foreach ($monthRows as $mr) {
        $slug = gudangTagihanMatchBizSlug((string)($mr['target_business_name'] ?? ''));
        if ($slug === null) {
            continue;
        }
        $monthlyTransferBySlug[$slug] = $mr;
    }

    // Terapkan penyesuaian tagihan dari transfer stok ANTAR BISNIS bulan ini (lihat catatan di
    // getBusinessInterStockTransferBillAdjustments()) sehingga tagihan ikut pindah ke bisnis penerima.
    $interBizAdjThisMonth = getBusinessInterStockTransferBillAdjustments(
        array_column($gudangMonthlyBizList, 'slug'),
        $monthStart . ' 00:00:00',
        $monthEnd . ' 23:59:59'
    );
    foreach ($gudangMonthlyBizList as $bizForAdj) {
        $slugForAdj = $bizForAdj['slug'];
        $adj = $interBizAdjThisMonth[$slugForAdj] ?? 0.0;
        if ($adj == 0.0) {
            continue;
        }
        if (!isset($monthlyTransferBySlug[$slugForAdj])) {
            $monthlyTransferBySlug[$slugForAdj] = ['total_nilai' => 0, 'total_qty' => 0, 'transfer_count' => 0];
        }
        $monthlyTransferBySlug[$slugForAdj]['total_nilai'] = max(0, (float)$monthlyTransferBySlug[$slugForAdj]['total_nilai'] + $adj);
    }

    foreach ($monthTransferDetailRows as $tr) {
        $slug = gudangTagihanMatchBizSlug((string)($tr['target_business_name'] ?? ''));
        if ($slug === null) {
            continue;
        }
        $monthlyTransferRowsBySlug[$slug][] = $tr;
    }
} catch (Throwable $e) {
    error_log('gudang-tagihan monthly transfer per biz: ' . $e->getMessage());
}

// ── Status pembayaran tagihan bulanan (per bisnis) + total uang diterima Gudang Nasita ──
$paidMapThisMonth = [];
$totalReceivedFromBusinesses = 0.0;
try {
    [$gudangDb3, $originDb3, $gudangDbName3] = gudangTagihanGetGudangDb();
    gudangTagihanEnsurePaymentsTable($gudangDb3);

    $paidRows = $gudangDb3->fetchAll(
        'SELECT business_slug, amount, paid_at FROM gudang_nasita_tagihan_payments WHERE bill_month = ?',
        [$selectedMonth]
    ) ?: [];
    foreach ($paidRows as $pr) {
        $paidMapThisMonth[$pr['business_slug']] = $pr;
    }

    $totalReceivedRow = $gudangDb3->fetchOne('SELECT COALESCE(SUM(amount), 0) AS t FROM gudang_nasita_tagihan_payments');
    $totalReceivedFromBusinesses = (float)($totalReceivedRow['t'] ?? 0);

    if ($gudangDbName3 && $gudangDbName3 !== $originDb3) {
        Database::switchDatabase($originDb3);
    }
} catch (Throwable $e) {
    error_log('gudang-tagihan paid status: ' . $e->getMessage());
}

$monthlyRecap = [];
$monthlyRecapGrandTotal = 0.0;
foreach ($gudangMonthlyBizList as $bizInfo) {
    $mr = $monthlyTransferBySlug[$bizInfo['slug']] ?? null;
    $transferNilai = (float)($mr['total_nilai'] ?? 0);
    $total = $transferNilai + $tkbmShareThisMonth;
    $monthlyRecapGrandTotal += $total;

    $bizTransferRows = $monthlyTransferRowsBySlug[$bizInfo['slug']] ?? [];
    $detailRows = array_map(function ($tr) {
        return [
            (string)($tr['transfer_number'] ?? '-'),
            $tr['created_at'] ? date('d M Y', strtotime((string)$tr['created_at'])) : '-',
            number_format((float)($tr['total_qty'] ?? 0), 2),
            'Rp ' . number_format((float)($tr['total_nilai'] ?? 0), 0, ',', '.'),
            strtoupper((string)($tr['status'] ?? '-')),
        ];
    }, $bizTransferRows);
    $detailRows[] = ['— Share TKBM —', '-', '-', 'Rp ' . number_format($tkbmShareThisMonth, 0, ',', '.'), 'TKBM'];

    $bizInterAdj = $interBizAdjThisMonth[$bizInfo['slug']] ?? 0.0;
    if ($bizInterAdj != 0.0) {
        $detailRows[] = [
            '— Transfer Antar Bisnis —',
            '-',
            '-',
            ($bizInterAdj > 0 ? '+' : '') . 'Rp ' . number_format($bizInterAdj, 0, ',', '.'),
            'ANTAR BISNIS',
        ];
    }

    $paidInfo = $paidMapThisMonth[$bizInfo['slug']] ?? null;

    $monthlyRecap[] = [
        'slug'            => $bizInfo['slug'],
        'icon'            => $bizInfo['icon'],
        'name'            => $bizInfo['name'],
        'logo_url'        => gudangTagihanResolveBizLogoUrl($bizInfo['slug']),
        'transfer_count'  => (int)($mr['transfer_count'] ?? 0),
        'transfer_qty'    => (float)($mr['total_qty'] ?? 0),
        'transfer_nilai'  => $transferNilai,
        'tkbm_share'      => $tkbmShareThisMonth,
        'total'           => $total,
        'detail_rows'     => $detailRows,
        'is_paid'         => $paidInfo !== null,
        'paid_at'         => $paidInfo ? date('d M Y H:i', strtotime((string)$paidInfo['paid_at'])) : null,
    ];
}

$forceTheme = 'light';
include '../../includes/header.php';

$statusColors = [
    'submitted'          => ['#fef3c7', '#92400e'],
    'approved'           => ['#dbeafe', '#1e40af'],
    'received'           => ['#d1fae5', '#065f46'],
    'partially_received' => ['#ede9fe', '#5b21b6'],
    'completed'          => ['#d1fae5', '#065f46'],
];
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h2 style="font-size:1.4rem; font-weight:700; margin:0; color:var(--text-primary);">Tagihan Gudang Nasita</h2>
        <p style="color:var(--text-muted); font-size:0.875rem; margin:0.25rem 0 0;">Rekap tagihan ke supplier dan tagihan ke bisnis berdasarkan PO / transfer</p>
    </div>
    <a href="gudang-nasita.php" class="btn btn-secondary" style="font-size:0.85rem;">← Kembali ke Stock Gudang</a>
</div>

<div style="margin-bottom:1.25rem; padding:0.85rem 1.1rem; background:linear-gradient(135deg,#0f9d6a,#0b7a52); border-radius:0.75rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; color:#fff;">
    <div>
        <div style="font-size:0.8rem; font-weight:600; opacity:0.9;">💰 Total Uang Diterima Gudang Nasita (dari pembayaran tagihan bisnis)</div>
        <div style="font-size:0.72rem; opacity:0.8; margin-top:0.15rem;">Uang ini tersedia di rekening bank Gudang Nasita untuk dibayarkan ke semua supplier</div>
    </div>
    <div style="font-size:1.3rem; font-weight:800;">Rp&nbsp;<?php echo number_format($totalReceivedFromBusinesses, 0, ',', '.'); ?></div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; align-items:start;">

    <!-- ── KIRI: Tagihan ke Supplier ───────────────────────────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
                <div>
                    <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan ke Supplier</h3>
                    <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">PO yang dibuat ke supplier Gudang Nasita</p>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <a href="suppliers.php" class="btn btn-sm btn-secondary" style="font-size:0.78rem;">Kelola Supplier</a>
                    <a href="gudang-po-supplier.php" class="btn btn-sm btn-primary">Buat PO Baru</a>
                </div>
            </div>
            <?php
            $totalSupplier = array_sum(array_column($supplierBills, 'total_amount'));
            ?>
            <div class="table-responsive" style="max-height:480px; overflow-y:auto;">
                <table class="table" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>No PO</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th class="text-right" style="color:#94a3b8;">Dipesan</th>
                            <th class="text-right" style="color:#0f9d6a;">Diterima</th>
                            <th class="text-right">Tagihan</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplierBills)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada barang yang diterima dari supplier</td>
                            </tr>
                            <?php else: foreach ($supplierBills as $bill):
                                $st = $bill['status'] ?? '-';
                                [$bg, $fc] = $statusColors[$st] ?? ['#f1f5f9', '#475569'];
                            ?>
                                <tr>
                                    <td style="font-weight:700; color:#4f46e5;"><?php echo htmlspecialchars($bill['po_number']); ?></td>
                                    <td><?php echo !empty($bill['po_date']) ? date('d M Y', strtotime($bill['po_date'])) : '-'; ?></td>
                                    <td style="font-weight:600;"><?php echo htmlspecialchars($bill['supplier_name']); ?></td>
                                    <td>
                                        <span style="background:<?php echo $bg; ?>; color:<?php echo $fc; ?>; padding:2px 8px; border-radius:999px; font-size:0.73rem; font-weight:600; white-space:nowrap;">
                                            <?php echo ucfirst(str_replace('_', ' ', $st)); ?>
                                        </span>
                                    </td>
                                    <td class="text-right" style="color:#94a3b8;"><?php echo number_format((float)$bill['ordered_qty'], 2); ?></td>
                                    <td class="text-right" style="font-weight:600; color:#0f9d6a;"><?php echo number_format((float)$bill['received_qty'], 2); ?></td>
                                    <td class="text-right" style="font-weight:700; color:<?php echo (float)$bill['total_amount'] > 0 ? '#0f9d6a' : '#94a3b8'; ?>;">
                                        <?php echo (float)$bill['total_amount'] > 0 ? 'Rp&nbsp;' . number_format((float)$bill['total_amount'], 0, ',', '.') : '—'; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="gudang-po-supplier.php?view=<?php echo (int)$bill['id']; ?>" class="btn btn-sm btn-secondary" style="font-size:0.73rem; padding:2px 8px;">Lihat</a>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                    <?php if ($totalSupplier > 0): ?>
                        <tfoot>
                            <tr style="background:#f8fafc; font-weight:700;">
                                <td colspan="6">Total Tagihan (diterima)</td>
                                <td class="text-right" style="color:#0f9d6a;">Rp&nbsp;<?php echo number_format($totalSupplier, 0, ',', '.'); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- ── KANAN: Tagihan ke Bisnis ────────────────────────────────────────── -->
    <div>
        <div class="card" style="margin-bottom:1rem;">
            <div style="margin-bottom:1rem;">
                <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan ke Bisnis</h3>
                <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">Berdasarkan transfer barang dari Gudang Nasita ke tiap bisnis</p>
            </div>
            <?php if (empty($bizBills)): ?>
                <div style="text-align:center; padding:2rem; color:var(--text-muted);">Belum ada transfer ke bisnis</div>
            <?php else: ?>
                <div style="display:grid; gap:0.75rem;">
                    <?php
                    $totalBizAll = 0;
                    $gudangBizIcons = ['narayana-hotel' => '🏨', 'bens-cafe' => '☕', 'eaat-meet' => '🍽️'];
                    foreach ($bizBills as $biz):
                        $bizName = $biz['target_business_name'] ?? '-';
                        $bizSlugMatch = gudangTagihanMatchBizSlug($bizName);
                        $bizIcon = $gudangBizIcons[$bizSlugMatch] ?? '🏢';
                        $bizLogoUrl = $bizSlugMatch ? gudangTagihanResolveBizLogoUrl($bizSlugMatch) : null;
                        $nilai = (float)$biz['total_nilai'];
                        $totalBizAll += $nilai;
                        $transfers = $detailByBiz[$bizName] ?? [];
                    ?>
                        <div style="border:1px solid #e2e8f0; border-radius:0.75rem; overflow:hidden;">
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:0.7rem 1rem; background:#f8fafc; cursor:pointer;"
                                onclick="toggleBizDetail('biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i', '_', $bizName)); ?>')">
                                <div style="display:flex; align-items:center; gap:0.6rem;">
                                    <?php if ($bizLogoUrl): ?>
                                        <img src="<?php echo htmlspecialchars($bizLogoUrl); ?>" alt="" style="width:28px; height:28px; object-fit:contain; border-radius:4px;">
                                    <?php else: ?>
                                        <span style="font-size:1.3rem;"><?php echo $bizIcon; ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700; font-size:0.9rem;"><?php echo htmlspecialchars($bizName); ?></div>
                                        <div style="font-size:0.75rem; color:var(--text-muted);"><?php echo (int)$biz['transfer_count']; ?> transfer &mdash; <?php echo number_format((float)$biz['total_qty'], 2); ?> qty total</div>
                                    </div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-weight:800; color:#0f9d6a; font-size:0.95rem;"><?php echo $nilai > 0 ? 'Rp&nbsp;' . number_format($nilai, 0, ',', '.') : '—'; ?></div>
                                    <div style="font-size:0.68rem; color:#94a3b8;">▼ detail</div>
                                </div>
                            </div>
                            <div id="biz-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/i', '_', $bizName)); ?>" style="display:none; max-height:200px; overflow-y:auto;">
                                <table class="table" style="font-size:0.78rem; margin:0;">
                                    <thead>
                                        <tr>
                                            <th>No Transfer</th>
                                            <th>Tanggal</th>
                                            <th class="text-right">Qty</th>
                                            <th class="text-right">Nilai</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transfers as $tr):
                                            $tst = $tr['status'] ?? '-';
                                            [$tbg, $tfc] = $statusColors[$tst] ?? ['#f1f5f9', '#475569'];
                                        ?>
                                            <tr>
                                                <td style="font-weight:600; color:#4f46e5;"><?php echo htmlspecialchars($tr['transfer_number']); ?></td>
                                                <td><?php echo date('d M Y', strtotime($tr['created_at'])); ?></td>
                                                <td class="text-right"><?php echo number_format((float)$tr['total_qty'], 2); ?></td>
                                                <td class="text-right" style="font-weight:700; color:#0f9d6a;"><?php echo (float)$tr['total_nilai'] > 0 ? 'Rp&nbsp;' . number_format((float)$tr['total_nilai'], 0, ',', '.') : '—'; ?></td>
                                                <td><span style="background:<?php echo $tbg; ?>; color:<?php echo $tfc; ?>; padding:1px 6px; border-radius:999px; font-size:0.7rem; font-weight:600;"><?php echo ucfirst($tst); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:0.85rem; padding:0.65rem 1rem; background:#f0fdf4; border-radius:0.6rem; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.85rem; font-weight:700; color:#065f46;">Total Tagihan ke Semua Bisnis</span>
                    <span style="font-size:1rem; font-weight:800; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($totalBizAll, 0, ',', '.'); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    function toggleBizDetail(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
    if (typeof feather !== 'undefined') feather.replace();
</script>

<!-- ── Tagihan Bulanan per Bisnis (transfer + share TKBM bulan berjalan) ─────── -->
<div class="card" style="margin-top:1.5rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
            <h3 style="font-size:1rem; font-weight:700; margin:0;">📅 Tagihan Bulanan per Bisnis</h3>
            <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">Total PO/transfer dari Gudang Nasita ke tiap bisnis bulan ini + bagian TKBM (dibagi 3 bisnis)</p>
        </div>
        <form method="GET" style="display:flex; gap:0.5rem; align-items:center;">
            <input type="month" name="bulan" class="form-control" style="width:160px;" value="<?php echo htmlspecialchars($selectedMonth); ?>" onchange="this.form.submit()">
            <button type="submit" class="btn btn-sm btn-secondary">Tampilkan</button>
        </form>
    </div>

    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:0; border:1px solid #e2e8f0; border-radius:0.75rem; overflow:hidden;">
        <?php foreach ($monthlyRecap as $i => $mrec):
            $mrDetail = [
                'title'    => $mrec['name'],
                'subtitle' => 'Periode ' . date('F Y', strtotime($monthStart)),
                'logo_url' => $mrec['logo_url'],
                'columns'  => [['label' => 'No Transfer'], ['label' => 'Tanggal'], ['label' => 'Qty', 'right' => true], ['label' => 'Nilai', 'right' => true], ['label' => 'Status']],
                'rows'     => $mrec['detail_rows'],
                'total'    => 'Rp ' . number_format($mrec['total'], 0, ',', '.'),
                'slug'     => $mrec['slug'],
                'bulan'    => $selectedMonth,
                'is_paid'  => $mrec['is_paid'],
                'paid_at'  => $mrec['paid_at'],
            ];
        ?>
            <div class="gt-monthly-card" style="padding:1rem; cursor:pointer; position:relative; <?php echo $i > 0 ? 'border-left:1px solid #e2e8f0;' : ''; ?>"
                data-detail="<?php echo htmlspecialchars(json_encode($mrDetail), ENT_QUOTES); ?>" onclick="openTagihanBulananDetail(this)">
                <?php if ($mrec['is_paid']): ?>
                    <span style="position:absolute; top:0.6rem; right:0.6rem; background:#d1fae5; color:#065f46; font-size:0.65rem; font-weight:700; padding:2px 8px; border-radius:999px;">✅ Lunas</span>
                <?php endif; ?>
                <div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.75rem;">
                    <?php if ($mrec['logo_url']): ?>
                        <img src="<?php echo htmlspecialchars($mrec['logo_url']); ?>" alt="" style="width:32px; height:32px; object-fit:contain; border-radius:4px;">
                    <?php else: ?>
                        <span style="font-size:1.6rem;"><?php echo $mrec['icon']; ?></span>
                    <?php endif; ?>
                    <div style="font-weight:700; font-size:0.95rem;"><?php echo htmlspecialchars($mrec['name']); ?></div>
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); display:flex; justify-content:space-between; margin-bottom:0.3rem;">
                    <span>Transfer bulan ini (<?php echo $mrec['transfer_count']; ?>x, <?php echo number_format($mrec['transfer_qty'], 2); ?> qty)</span>
                    <span style="font-weight:600; color:var(--text-primary);">Rp&nbsp;<?php echo number_format($mrec['transfer_nilai'], 0, ',', '.'); ?></span>
                </div>
                <div style="font-size:0.78rem; color:var(--text-muted); display:flex; justify-content:space-between; margin-bottom:0.6rem;">
                    <span>Share TKBM bulan ini</span>
                    <span style="font-weight:600; color:var(--text-primary);">Rp&nbsp;<?php echo number_format($mrec['tkbm_share'], 0, ',', '.'); ?></span>
                </div>
                <div style="border-top:1px dashed #e2e8f0; padding-top:0.6rem; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:0.82rem; font-weight:700;">Total Tagihan Bulan Ini</span>
                    <span style="font-size:1.05rem; font-weight:800; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($mrec['total'], 0, ',', '.'); ?></span>
                </div>
                <div style="margin-top:0.5rem; font-size:0.7rem; color:#94a3b8; text-align:center;"><?php echo $mrec['is_paid'] ? 'Klik untuk lihat detail &amp; cetak tagihan' : 'Klik untuk lihat detail, bayar &amp; cetak tagihan'; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:1rem; padding:0.65rem 1rem; background:#f0fdf4; border-radius:0.6rem; display:flex; justify-content:space-between; align-items:center;">
        <span style="font-size:0.85rem; font-weight:700; color:#065f46;">Total Tagihan Bulan Ini (3 Bisnis)</span>
        <span style="font-size:1rem; font-weight:800; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($monthlyRecapGrandTotal, 0, ',', '.'); ?></span>
    </div>
</div>

<!-- ── TKBM Section ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-top:1.5rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
            <h3 style="font-size:1rem; font-weight:700; margin:0;">Tagihan TKBM <span style="font-size:0.78rem; color:var(--text-muted); font-weight:400;">(Tenaga Kerja Bongkar Muat)</span></h3>
            <p style="font-size:0.78rem; color:var(--text-muted); margin:0.15rem 0 0;">Biaya jasa angkut dari pelabuhan ke Gudang Nasita — dibagi rata ke semua bisnis</p>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('tkbmAddForm').style.display='flex'">+ Tambah TKBM</button>
    </div>

    <!-- Add TKBM form -->
    <form id="tkbmAddForm" method="POST" style="display:none; gap:0.65rem; flex-wrap:wrap; align-items:flex-end; background:#f8fafc; padding:0.85rem 1rem; border-radius:0.65rem; margin-bottom:1rem;">
        <input type="hidden" name="action" value="add_tkbm">
        <div>
            <label class="form-label" style="font-size:0.78rem;">Tanggal</label>
            <input type="date" name="tanggal" class="form-control" style="width:140px;" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div>
            <label class="form-label" style="font-size:0.78rem;">Total Biaya TKBM (Rp)</label>
            <input type="number" name="total_biaya" class="form-control" style="width:160px;" placeholder="0" min="1" step="1" required>
        </div>
        <div>
            <label class="form-label" style="font-size:0.78rem;">Dibagi ke (bisnis)</label>
            <input type="number" name="jumlah_bisnis" class="form-control" style="width:80px;" value="3" min="1" max="10">
        </div>
        <div style="flex:1; min-width:180px;">
            <label class="form-label" style="font-size:0.78rem;">Keterangan</label>
            <input type="text" name="keterangan" class="form-control" placeholder="Mis: pengiriman Jepara 17 Agt">
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button type="submit" class="btn btn-sm btn-success">Simpan</button>
            <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('tkbmAddForm').style.display='none'">Batal</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table" style="font-size:0.83rem;">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Keterangan</th>
                    <th class="text-right">Total Biaya</th>
                    <th class="text-center">Dibagi</th>
                    <th class="text-right" style="color:#0f9d6a;">Per Bisnis</th>
                    <th class="text-center">Hapus</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tkbmRows)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:1.5rem; color:var(--text-muted);">Belum ada data TKBM</td>
                    </tr>
                    <?php else: foreach ($tkbmRows as $tkbm):
                        $perBisnis = (float)$tkbm['total_biaya'] / max(1, (int)$tkbm['jumlah_bisnis']);
                    ?>
                        <tr>
                            <td><?php echo date('d M Y', strtotime($tkbm['tanggal'])); ?></td>
                            <td><?php echo htmlspecialchars($tkbm['keterangan'] ?? '-'); ?></td>
                            <td class="text-right" style="font-weight:700;">Rp&nbsp;<?php echo number_format((float)$tkbm['total_biaya'], 0, ',', '.'); ?></td>
                            <td class="text-center" style="color:#64748b;"><?php echo (int)$tkbm['jumlah_bisnis']; ?> bisnis</td>
                            <td class="text-right" style="font-weight:700; color:#0f9d6a;">Rp&nbsp;<?php echo number_format($perBisnis, 0, ',', '.'); ?></td>
                            <td class="text-center">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus entri TKBM ini?')">
                                    <input type="hidden" name="action" value="delete_tkbm">
                                    <input type="hidden" name="tkbm_id" value="<?php echo (int)$tkbm['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px; font-size:0.73rem;">Hapus</button>
                                </form>
                            </td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
            <?php if ($tkbmTotal > 0): ?>
                <tfoot>
                    <tr style="background:#f8fafc; font-weight:700;">
                        <td colspan="2">Total TKBM</td>
                        <td class="text-right" style="color:#0f9d6a;">Rp&nbsp;<?php echo number_format($tkbmTotal, 0, ',', '.'); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Detail tagihan bulanan / cetak tagihan modal -->
<div id="tagihanBulananModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.55); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:0.9rem; width:92%; max-width:640px; max-height:82vh; overflow-y:auto; box-shadow:0 20px 50px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid #e2e8f0;">
            <div style="display:flex; align-items:center; gap:0.6rem;">
                <img id="tbmLogo" src="" alt="" style="width:34px; height:34px; object-fit:contain; border-radius:4px; display:none;">
                <div>
                    <div style="font-weight:800; font-size:1rem;" id="tbmTitle">-</div>
                    <div style="font-size:0.8rem; color:var(--text-muted);" id="tbmSubtitle">-</div>
                </div>
            </div>
            <button type="button" onclick="closeTagihanBulananDetail()" style="background:none; border:none; font-size:1.3rem; line-height:1; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:1rem 1.25rem;">
            <table class="table" style="width:100%; font-size:0.83rem;">
                <thead id="tbmThead"></thead>
                <tbody id="tbmBody"></tbody>
            </table>
            <div style="margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-weight:700;">Total Tagihan</span>
                <span style="font-weight:800; color:#0f9d6a; font-size:1.05rem;" id="tbmTotal">-</span>
            </div>
        </div>
        <div style="padding:0.85rem 1.25rem; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <span id="tbmPaidBadge" style="display:none; font-size:0.8rem; font-weight:700; color:#065f46; background:#d1fae5; padding:5px 12px; border-radius:999px;"></span>
            <div style="margin-left:auto; display:flex; gap:0.5rem;">
                <button type="button" id="tbmPayBtn" class="btn btn-success" onclick="bayarTagihanBulanan()">💰 Bayar Tagihan</button>
                <button type="button" class="btn btn-primary" onclick="printTagihanBulanan()">🖨️ Cetak Tagihan</button>
            </div>
        </div>
    </div>
</div>

<form id="tagihanBayarForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="pay_monthly_bill">
    <input type="hidden" name="slug" id="tbmPaySlug" value="">
    <input type="hidden" name="bulan" id="tbmPayBulan" value="">
</form>

<script>
    let tbmCurrentDetail = null;

    function tbmEscapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [c]));
    }

    function openTagihanBulananDetail(cardEl) {
        let detail;
        try {
            detail = JSON.parse(cardEl.getAttribute('data-detail') || '{}');
        } catch (e) {
            detail = {};
        }
        tbmCurrentDetail = detail;

        document.getElementById('tbmTitle').textContent = detail.title || '-';
        document.getElementById('tbmSubtitle').textContent = detail.subtitle || '';

        const logoEl = document.getElementById('tbmLogo');
        if (detail.logo_url) {
            logoEl.src = detail.logo_url;
            logoEl.style.display = 'inline-block';
        } else {
            logoEl.style.display = 'none';
        }

        const cols = detail.columns || [];
        document.getElementById('tbmThead').innerHTML = '<tr>' + cols.map(c => '<th' + (c.right ? ' class="text-right"' : '') + '>' + tbmEscapeHtml(c.label || '') + '</th>').join('') + '</tr>';

        const rows = detail.rows || [];
        const tbody = document.getElementById('tbmBody');
        tbody.innerHTML = !rows.length ?
            '<tr><td colspan="' + (cols.length || 1) + '" style="text-align:center; color:var(--text-muted); padding:1rem;">Tidak ada transfer bulan ini.</td></tr>' :
            rows.map(r => '<tr>' + r.map((v, i) => '<td' + (cols[i] && cols[i].right ? ' class="text-right"' : '') + '>' + tbmEscapeHtml(v) + '</td>').join('') + '</tr>').join('');

        document.getElementById('tbmTotal').textContent = detail.total || '-';

        const payBtn = document.getElementById('tbmPayBtn');
        const paidBadge = document.getElementById('tbmPaidBadge');
        if (detail.is_paid) {
            payBtn.style.display = 'none';
            paidBadge.style.display = 'inline-block';
            paidBadge.textContent = '✅ Lunas — dibayar ' + (detail.paid_at || '');
        } else {
            payBtn.style.display = 'inline-block';
            paidBadge.style.display = 'none';
        }

        document.getElementById('tagihanBulananModal').style.display = 'flex';
    }

    function closeTagihanBulananDetail() {
        document.getElementById('tagihanBulananModal').style.display = 'none';
    }

    function bayarTagihanBulanan() {
        const detail = tbmCurrentDetail;
        if (!detail || !detail.slug || !detail.bulan) return;
        if (detail.is_paid) return;
        const confirmMsg = 'Konfirmasi bayar tagihan ' + (detail.title || '') + ' sebesar ' + (detail.total || '') + '?\n\n' +
            'Uang akan dipotong dari rekening bank ' + (detail.title || '') + ' dan dicatat sebagai pemasukan Gudang Nasita.';
        if (!confirm(confirmMsg)) return;

        document.getElementById('tbmPaySlug').value = detail.slug;
        document.getElementById('tbmPayBulan').value = detail.bulan;
        document.getElementById('tagihanBayarForm').submit();
    }


    function printTagihanBulanan() {
        const detail = tbmCurrentDetail;
        if (!detail) return;

        const cols = detail.columns || [];
        const rows = detail.rows || [];
        const rowsHtml = rows.map(r => '<tr>' + r.map((v, i) => '<td style="' + (cols[i] && cols[i].right ? 'text-align:right;' : '') + 'padding:6px 10px; border-bottom:1px solid #e2e8f0;">' + tbmEscapeHtml(v) + '</td>').join('') + '</tr>').join('');
        const theadHtml = '<tr>' + cols.map(c => '<th style="' + (c.right ? 'text-align:right;' : 'text-align:left;') + 'padding:6px 10px; border-bottom:2px solid #0f172a;">' + tbmEscapeHtml(c.label || '') + '</th>').join('') + '</tr>';
        const logoHtml = detail.logo_url ? '<img src="' + detail.logo_url + '" alt="" style="width:48px;height:48px;object-fit:contain;border-radius:4px;margin-bottom:8px;">' : '';

        const win = window.open('', '_blank', 'width=800,height=900');
        win.document.write(`
            <html>
            <head>
                <title>Tagihan - ${tbmEscapeHtml(detail.title || '')}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #0f172a; }
                    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                    .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 12px; }
                    .total-row { display:flex; justify-content:space-between; margin-top:14px; padding-top:10px; border-top:2px solid #0f172a; font-weight:bold; font-size:1.1rem; }
                </style>
            </head>
            <body>
                <div class="header">
                    ${logoHtml}
                    <h2 style="margin:0;">Tagihan Gudang Nasita</h2>
                    <div>${tbmEscapeHtml(detail.title || '')} &mdash; ${tbmEscapeHtml(detail.subtitle || '')}</div>
                </div>
                <table>
                    <thead>${theadHtml}</thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
                <div class="total-row"><span>Total Tagihan</span><span>${tbmEscapeHtml(detail.total || '-')}</span></div>
                <p style="margin-top:24px; font-size:0.8rem; color:#64748b;">Dicetak pada ${new Date().toLocaleString('id-ID')}</p>
            </body>
            </html>
        `);
        win.document.close();
        win.focus();
        win.print();
    }
</script>

<?php include '../../includes/footer.php'; ?>