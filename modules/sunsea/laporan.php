<?php

/**
 * Sunsea - Laporan (Harian / Bulanan / Per Customer)
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
sunseaEnsureFinanceSchema($pdo);
sunseaEnsureBookingSchema($pdo);

function lapFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

$tab = in_array($_GET['tab'] ?? '', ['harian', 'bulanan', 'customer'], true) ? $_GET['tab'] : 'harian';

// ---- HARIAN: semua transaksi kas hari ini ----
$lapDate = $_GET['date'] ?? date('Y-m-d');
$lapHarianRows = lapFetchAll($pdo, "
    SELECT cb.*, c.name AS customer_name, bo.booking_no
    FROM cash_book cb
    LEFT JOIN customers c ON c.id = cb.customer_id
    LEFT JOIN booking_orders bo ON bo.id = cb.booking_id
    WHERE cb.transaction_date = ?
    ORDER BY cb.id
", [$lapDate]);
$lapHarianIncome = 0;
$lapHarianExpense = 0;
foreach ($lapHarianRows as $r) {
    if ($r['type'] === 'income') $lapHarianIncome += (float)$r['amount'];
    else $lapHarianExpense += (float)$r['amount'];
}
$lapHarianNet = $lapHarianIncome - $lapHarianExpense;

// ---- BULANAN: rekap per booking/trip dalam 1 bulan (nama tamu, paket, pemasukan, pengeluaran, margin) ----
$lapMonth = $_GET['month'] ?? date('Y-m');
$lapMonthStart = date('Y-m-01', strtotime($lapMonth . '-01'));
$lapMonthEnd = date('Y-m-t', strtotime($lapMonth . '-01'));
$lapBulananRows = lapFetchAll($pdo, "
    SELECT b.id, b.booking_no, b.start_date, b.end_date, c.name AS customer_name, p.name AS package_name,
        COALESCE((SELECT SUM(i.total_sell) FROM booking_order_items i WHERE i.booking_id=b.id), 0) AS pemasukan,
        COALESCE((SELECT SUM(cb.amount) FROM cash_book cb WHERE cb.booking_id=b.id AND cb.type='expense'), 0) AS pengeluaran
    FROM booking_orders b
    JOIN customers c ON c.id = b.customer_id
    LEFT JOIN trip_packages p ON p.id = b.package_id
    WHERE b.start_date BETWEEN ? AND ?
    ORDER BY b.start_date, b.id
", [$lapMonthStart, $lapMonthEnd]);
$lapBulananTotalIn = 0;
$lapBulananTotalOut = 0;
foreach ($lapBulananRows as &$row) {
    $row['margin'] = (float)$row['pemasukan'] - (float)$row['pengeluaran'];
    $lapBulananTotalIn += (float)$row['pemasukan'];
    $lapBulananTotalOut += (float)$row['pengeluaran'];
}
unset($row);
$lapBulananTotalMargin = $lapBulananTotalIn - $lapBulananTotalOut;

// ---- PER CUSTOMER: rekap keuangan semua booking milik 1 tamu ----
$lapCustomerId = (int)($_GET['customer_id'] ?? 0);
$lapCustomerList = lapFetchAll($pdo, "SELECT id, name FROM customers WHERE is_active=1 ORDER BY name");
$lapCustomerRows = [];
$lapCustomerInfo = null;
if ($lapCustomerId > 0) {
    $ci = $pdo->prepare("SELECT id, name, phone, email FROM customers WHERE id=?");
    $ci->execute([$lapCustomerId]);
    $lapCustomerInfo = $ci->fetch();

    $lapCustomerRows = lapFetchAll($pdo, "
        SELECT b.id, b.booking_no, b.start_date, b.end_date, p.name AS package_name,
            COALESCE((SELECT SUM(i.total_sell) FROM booking_order_items i WHERE i.booking_id=b.id), 0) AS pemasukan,
            COALESCE((SELECT SUM(cb.amount) FROM cash_book cb WHERE cb.booking_id=b.id AND cb.type='expense'), 0) AS pengeluaran
        FROM booking_orders b
        LEFT JOIN trip_packages p ON p.id = b.package_id
        WHERE b.customer_id = ?
        ORDER BY b.start_date, b.id
    ", [$lapCustomerId]);
}
$lapCustomerTotalIn = 0;
$lapCustomerTotalOut = 0;
foreach ($lapCustomerRows as &$row) {
    $row['margin'] = (float)$row['pemasukan'] - (float)$row['pengeluaran'];
    $lapCustomerTotalIn += (float)$row['pemasukan'];
    $lapCustomerTotalOut += (float)$row['pengeluaran'];
}
unset($row);
$lapCustomerTotalMargin = $lapCustomerTotalIn - $lapCustomerTotalOut;

// ---- PRINT MODE: halaman bersih tanpa sidebar, langsung window.print() ----
if (($_GET['print'] ?? '') === '1') {
    $companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
    $companyAddress = sunseaSetting($pdo, 'company_address', '');
    $companyPhone = implode(' / ', sunseaCompanyPhones($pdo));
    $printLogoSrc = sunseaAssetUrl(sunseaSetting($pdo, 'company_logo', ''));

    $printTitle = 'Laporan';
    if ($tab === 'harian') $printTitle = 'Laporan Keuangan Harian - ' . date('d F Y', strtotime($lapDate));
    if ($tab === 'bulanan') $printTitle = 'Laporan Keuangan Bulanan - ' . date('F Y', strtotime($lapMonthStart));
    if ($tab === 'customer') $printTitle = 'Laporan Finance Customer - ' . ($lapCustomerInfo['name'] ?? '-');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($printTitle); ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1e293b;
            padding: 28px;
            font-size: 12.5px;
        }

        .lap-head {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 3px solid #0C4A6E;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .lap-head img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .lap-head .brand-name {
            font-size: 17px;
            font-weight: 800;
            color: #0C4A6E;
            margin: 0;
        }

        .lap-head .brand-meta {
            font-size: 11px;
            color: #64748b;
        }

        h1.lap-title {
            font-size: 15px;
            margin: 0 0 16px;
            color: #0C4A6E;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        th,
        td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            text-align: left;
            font-size: 11.5px;
        }

        th {
            background: #F0F9FF;
            color: #0C4A6E;
            font-weight: 700;
        }

        tfoot td {
            font-weight: 800;
            background: #F8FAFC;
        }

        .lap-summary {
            display: flex;
            gap: 14px;
            margin-bottom: 16px;
        }

        .lap-summary-box {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .lap-summary-box .lbl {
            font-size: 10.5px;
            color: #64748b;
            text-transform: uppercase;
        }

        .lap-summary-box .val {
            font-size: 15px;
            font-weight: 800;
            margin-top: 3px;
        }

        @media print {
            body {
                padding: 10px;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="lap-head">
        <?php if ($printLogoSrc): ?><img src="<?php echo htmlspecialchars($printLogoSrc); ?>" alt="Logo"><?php endif; ?>
        <div>
            <p class="brand-name"><?php echo htmlspecialchars($companyName); ?></p>
            <div class="brand-meta"><?php echo htmlspecialchars($companyAddress); ?><?php echo ($companyAddress && $companyPhone) ? ' &middot; ' : ''; ?><?php echo htmlspecialchars($companyPhone); ?></div>
        </div>
    </div>
    <h1 class="lap-title"><?php echo htmlspecialchars($printTitle); ?></h1>

    <?php if ($tab === 'harian'): ?>
        <div class="lap-summary">
            <div class="lap-summary-box">
                <div class="lbl">Total Pemasukan</div>
                <div class="val" style="color:#16a34a;"><?php echo sunseaRupiah($lapHarianIncome); ?></div>
            </div>
            <div class="lap-summary-box">
                <div class="lbl">Total Pengeluaran</div>
                <div class="val" style="color:#dc2626;"><?php echo sunseaRupiah($lapHarianExpense); ?></div>
            </div>
            <div class="lap-summary-box">
                <div class="lbl">Saldo Bersih</div>
                <div class="val" style="color:#0C4A6E;"><?php echo sunseaRupiah($lapHarianNet); ?></div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Jenis</th>
                    <th>Keterangan</th>
                    <th>Tamu / Trip</th>
                    <th>Kategori</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lapHarianRows)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:#94a3b8;">Tidak ada transaksi pada tanggal ini.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($lapHarianRows as $r): ?>
                    <tr>
                        <td><?php echo $r['type'] === 'income' ? 'Masuk' : 'Keluar'; ?></td>
                        <td><?php echo htmlspecialchars($r['description']); ?></td>
                        <td><?php echo htmlspecialchars($r['customer_name'] ?: '-'); ?><?php echo $r['booking_no'] ? ' (' . htmlspecialchars($r['booking_no']) . ')' : ''; ?></td>
                        <td><?php echo htmlspecialchars($r['category'] ?: '-'); ?></td>
                        <td><?php echo ($r['type'] === 'income' ? '+ ' : '- ') . sunseaRupiah((float)$r['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($tab === 'bulanan'): ?>
        <div class="lap-summary">
            <div class="lap-summary-box">
                <div class="lbl">Total Pemasukan</div>
                <div class="val" style="color:#16a34a;"><?php echo sunseaRupiah($lapBulananTotalIn); ?></div>
            </div>
            <div class="lap-summary-box">
                <div class="lbl">Total Pengeluaran</div>
                <div class="val" style="color:#dc2626;"><?php echo sunseaRupiah($lapBulananTotalOut); ?></div>
            </div>
            <div class="lap-summary-box">
                <div class="lbl">Total Margin</div>
                <div class="val" style="color:#0C4A6E;"><?php echo sunseaRupiah($lapBulananTotalMargin); ?></div>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Nama Tamu</th>
                    <th>Paket</th>
                    <th>Pemasukan</th>
                    <th>Pengeluaran</th>
                    <th>Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lapBulananRows)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;color:#94a3b8;">Tidak ada trip pada bulan ini.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($lapBulananRows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                        <td><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                        <td><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                        <td><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total Semua</td>
                    <td><?php echo sunseaRupiah($lapBulananTotalIn); ?></td>
                    <td><?php echo sunseaRupiah($lapBulananTotalOut); ?></td>
                    <td><?php echo sunseaRupiah($lapBulananTotalMargin); ?></td>
                </tr>
            </tfoot>
        </table>

    <?php elseif ($tab === 'customer'): ?>
        <?php if (!$lapCustomerInfo): ?>
            <p style="color:#94a3b8;">Pilih tamu terlebih dahulu.</p>
        <?php else: ?>
            <p style="margin:-8px 0 14px;color:#64748b;"><?php echo htmlspecialchars($lapCustomerInfo['phone'] ?: ''); ?><?php echo ($lapCustomerInfo['phone'] && $lapCustomerInfo['email']) ? ' &middot; ' : ''; ?><?php echo htmlspecialchars($lapCustomerInfo['email'] ?: ''); ?></p>
            <div class="lap-summary">
                <div class="lap-summary-box">
                    <div class="lbl">Total Pemasukan</div>
                    <div class="val" style="color:#16a34a;"><?php echo sunseaRupiah($lapCustomerTotalIn); ?></div>
                </div>
                <div class="lap-summary-box">
                    <div class="lbl">Total Pengeluaran</div>
                    <div class="val" style="color:#dc2626;"><?php echo sunseaRupiah($lapCustomerTotalOut); ?></div>
                </div>
                <div class="lap-summary-box">
                    <div class="lbl">Net Margin</div>
                    <div class="val" style="color:#0C4A6E;"><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></div>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>No. Booking</th>
                        <th>Tanggal Trip</th>
                        <th>Paket</th>
                        <th>Pemasukan</th>
                        <th>Pengeluaran</th>
                        <th>Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lapCustomerRows)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#94a3b8;">Belum ada booking untuk tamu ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lapCustomerRows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['booking_no']); ?></td>
                            <td><?php echo date('d M Y', strtotime($r['start_date'])); ?></td>
                            <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Total Semua</td>
                        <td><?php echo sunseaRupiah($lapCustomerTotalIn); ?></td>
                        <td><?php echo sunseaRupiah($lapCustomerTotalOut); ?></td>
                        <td><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</body>

</html>
<?php
    exit;
}

$pageTitle = 'Laporan';
$activePage = 'laporan';
include 'layout-header.php';

function lapPrintUrl(string $tab, array $extra = []): string
{
    return '?' . http_build_query(array_merge(['tab' => $tab, 'print' => '1'], $extra));
}
?>

<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e0e7ef;flex-wrap:wrap;">
    <a href="?tab=harian" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'harian' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        📅 Laporan Harian
    </a>
    <a href="?tab=bulanan" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'bulanan' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        🗓️ Laporan Bulanan
    </a>
    <a href="?tab=customer" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'customer' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        👤 Laporan Per Customer
    </a>
</div>

<?php if ($tab === 'harian'): ?>
    <div class="ss-card" style="margin-bottom:14px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="tab" value="harian">
            <div class="ss-form-group" style="margin:0;">
                <label class="ss-label" style="font-size:11px;">Tanggal</label>
                <input type="date" name="date" class="ss-input" style="font-size:12px;padding:6px 8px;" value="<?php echo htmlspecialchars($lapDate); ?>">
            </div>
            <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="filter"></i> Tampilkan</button>
            <a href="<?php echo lapPrintUrl('harian', ['date' => $lapDate]); ?>" target="_blank" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="printer"></i> Cetak / Simpan PDF</a>
        </form>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($lapHarianIncome); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($lapHarianExpense); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Saldo Bersih</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah($lapHarianNet); ?></div>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Transaksi Tanggal <?php echo date('d M Y', strtotime($lapDate)); ?></div>
        <div class="ss-table-wrap">
            <table class="ss-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th style="width:90px;">Jenis</th>
                        <th>Keterangan</th>
                        <th>Tamu / Trip</th>
                        <th>Kategori</th>
                        <th style="width:130px;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lapHarianRows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--ss-muted);padding:20px;">Tidak ada transaksi pada tanggal ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lapHarianRows as $r): ?>
                        <tr>
                            <td>
                                <?php if ($r['type'] === 'income'): ?>
                                    <span class="ss-status ss-status-approved" style="font-size:11px;">Masuk</span>
                                <?php else: ?>
                                    <span class="ss-status ss-status-rejected" style="font-size:11px;">Keluar</span>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'bulanan'): ?>
    <div class="ss-card" style="margin-bottom:14px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="tab" value="bulanan">
            <div class="ss-form-group" style="margin:0;">
                <label class="ss-label" style="font-size:11px;">Bulan</label>
                <input type="month" name="month" class="ss-input" style="font-size:12px;padding:6px 8px;" value="<?php echo htmlspecialchars($lapMonth); ?>">
            </div>
            <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="filter"></i> Tampilkan</button>
            <a href="<?php echo lapPrintUrl('bulanan', ['month' => $lapMonth]); ?>" target="_blank" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="printer"></i> Cetak / Simpan PDF</a>
        </form>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($lapBulananTotalIn); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($lapBulananTotalOut); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Margin</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah($lapBulananTotalMargin); ?></div>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Rekap Trip Bulan <?php echo date('F Y', strtotime($lapMonthStart)); ?></div>
        <div class="ss-table-wrap">
            <table class="ss-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Nama Tamu</th>
                        <th>Paket</th>
                        <th style="width:130px;">Pemasukan</th>
                        <th style="width:130px;">Pengeluaran</th>
                        <th style="width:130px;">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lapBulananRows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--ss-muted);padding:20px;">Tidak ada trip pada bulan ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lapBulananRows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['customer_name']); ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($r['booking_no']); ?></small></td>
                            <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                            <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                            <td style="color:var(--ss-danger);font-weight:600;"><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                            <td style="font-weight:700;"><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--ss-gray-2);">
                        <td colspan="2"><strong>Total Semua</strong></td>
                        <td style="color:var(--ss-success);"><strong><?php echo sunseaRupiah($lapBulananTotalIn); ?></strong></td>
                        <td style="color:var(--ss-danger);"><strong><?php echo sunseaRupiah($lapBulananTotalOut); ?></strong></td>
                        <td><strong><?php echo sunseaRupiah($lapBulananTotalMargin); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'customer'): ?>
    <div class="ss-card" style="margin-bottom:14px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="tab" value="customer">
            <div class="ss-form-group" style="margin:0;min-width:220px;">
                <label class="ss-label" style="font-size:11px;">Pilih Tamu</label>
                <select name="customer_id" class="ss-select" style="font-size:12px;padding:6px 8px;">
                    <option value="0">-- Pilih Tamu --</option>
                    <?php foreach ($lapCustomerList as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $lapCustomerId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="filter"></i> Tampilkan</button>
            <?php if ($lapCustomerId > 0): ?>
                <a href="<?php echo lapPrintUrl('customer', ['customer_id' => $lapCustomerId]); ?>" target="_blank" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="printer"></i> Cetak / Simpan PDF</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$lapCustomerInfo): ?>
        <div class="ss-card">
            <div style="text-align:center;color:var(--ss-muted);padding:20px;">Pilih tamu terlebih dahulu untuk melihat laporan finance-nya.</div>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
            <div class="ss-card">
                <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
                <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($lapCustomerTotalIn); ?></div>
            </div>
            <div class="ss-card">
                <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
                <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($lapCustomerTotalOut); ?></div>
            </div>
            <div class="ss-card">
                <div style="font-size:12px;color:var(--ss-muted);">Net Margin</div>
                <div style="font-size:17px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></div>
            </div>
        </div>

        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:10px;">Riwayat Booking - <?php echo htmlspecialchars($lapCustomerInfo['name']); ?></div>
            <div class="ss-table-wrap">
                <table class="ss-table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>No. Booking</th>
                            <th>Tanggal Trip</th>
                            <th>Paket</th>
                            <th style="width:130px;">Pemasukan</th>
                            <th style="width:130px;">Pengeluaran</th>
                            <th style="width:130px;">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lapCustomerRows)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:var(--ss-muted);padding:20px;">Belum ada booking untuk tamu ini.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lapCustomerRows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['booking_no']); ?></td>
                                <td><?php echo date('d M Y', strtotime($r['start_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                                <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                                <td style="color:var(--ss-danger);font-weight:600;"><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                                <td style="font-weight:700;"><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--ss-gray-2);">
                            <td colspan="3"><strong>Total Semua</strong></td>
                            <td style="color:var(--ss-success);"><strong><?php echo sunseaRupiah($lapCustomerTotalIn); ?></strong></td>
                            <td style="color:var(--ss-danger);"><strong><?php echo sunseaRupiah($lapCustomerTotalOut); ?></strong></td>
                            <td><strong><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>
