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

// ---- BULANAN: rekap uang riil (kas beneran masuk/keluar) per tamu dalam 1 bulan, berdasarkan tanggal transaksi kas ----
$lapMonth = $_GET['month'] ?? date('Y-m');
$lapMonthStart = date('Y-m-01', strtotime($lapMonth . '-01'));
$lapMonthEnd = date('Y-m-t', strtotime($lapMonth . '-01'));
$lapBulananRows = lapFetchAll($pdo, "
    SELECT COALESCE(c.id, 0) AS customer_id, COALESCE(c.name, 'Operasional / Lainnya') AS customer_name,
        SUM(CASE WHEN cb.type='income' THEN cb.amount ELSE 0 END) AS diterima,
        SUM(CASE WHEN cb.type='expense' THEN cb.amount ELSE 0 END) AS pengeluaran,
        COALESCE((
            SELECT SUM(cb2.amount) FROM cash_book cb2
            WHERE cb2.customer_id = c.id AND cb2.type='income' AND (cb2.transaction_date < ? OR cb2.transaction_date > ?)
        ), 0) AS diterima_bulan_lain,
        (
            SELECT MIN(cb3.transaction_date) FROM cash_book cb3
            WHERE cb3.customer_id = c.id AND cb3.type='income' AND (cb3.transaction_date < ? OR cb3.transaction_date > ?)
        ) AS diterima_bulan_lain_tanggal
    FROM cash_book cb
    LEFT JOIN customers c ON c.id = cb.customer_id
    WHERE cb.transaction_date BETWEEN ? AND ?
    GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Operasional / Lainnya')
    ORDER BY customer_name
", [$lapMonthStart, $lapMonthEnd, $lapMonthStart, $lapMonthEnd, $lapMonthStart, $lapMonthEnd]);
$lapBulananTotalIn = 0;
$lapBulananTotalOut = 0;
foreach ($lapBulananRows as &$row) {
    $row['diterima_total'] = (float)$row['diterima'] + (float)$row['diterima_bulan_lain'];
    $row['saldo'] = $row['diterima_total'] - (float)$row['pengeluaran'];
    $lapBulananTotalIn += $row['diterima_total'];
    $lapBulananTotalOut += (float)$row['pengeluaran'];
}
unset($row);
$lapBulananSaldoRiil = $lapBulananTotalIn - $lapBulananTotalOut;

// ---- PER CUSTOMER: rekap keuangan semua booking milik 1 tamu ----
$lapCustomerId = (int)($_GET['customer_id'] ?? 0);
// Hanya tamu yang trip-nya sudah selesai: booking berstatus completed, atau confirmed/ongoing dengan tanggal selesai sudah lewat.
// Tamu yang sedang dipilih (mis. dari link lama) tetap ikut agar laporannya tidak hilang.
$lapCustomerList = lapFetchAll($pdo, "
    SELECT c.id, c.name, c.phone, COUNT(b.id) AS trips, MAX(b.end_date) AS last_end
    FROM customers c
    JOIN booking_orders b ON b.customer_id = c.id
        AND (b.status = 'completed' OR (b.status IN ('confirmed','ongoing') AND b.end_date < CURDATE()))
    WHERE c.is_active = 1
    GROUP BY c.id, c.name, c.phone
    ORDER BY last_end DESC, c.name
");
if ($lapCustomerId > 0 && !in_array($lapCustomerId, array_map('intval', array_column($lapCustomerList, 'id')), true)) {
    $extraC = $pdo->prepare("SELECT id, name, phone, 0 AS trips, NULL AS last_end FROM customers WHERE id = ?");
    $extraC->execute([$lapCustomerId]);
    if ($ec = $extraC->fetch(PDO::FETCH_ASSOC)) array_unshift($lapCustomerList, $ec);
}
$lapCustomerSelected = null;
foreach ($lapCustomerList as $__c) {
    if ((int)$__c['id'] === $lapCustomerId) { $lapCustomerSelected = $__c; break; }
}
$lapCustomerRows = [];
$lapCustomerInfo = null;
if ($lapCustomerId > 0) {
    $ci = $pdo->prepare("SELECT id, name, phone, email FROM customers WHERE id=?");
    $ci->execute([$lapCustomerId]);
    $lapCustomerInfo = $ci->fetch();

    $lapCustomerRows = lapFetchAll($pdo, "
        SELECT b.id, b.booking_no, b.start_date, b.end_date, p.name AS package_name,
            COALESCE((SELECT SUM(i.total_sell) FROM booking_order_items i WHERE i.booking_id=b.id), 0) AS pemasukan,
            COALESCE((SELECT SUM(cb.amount) FROM cash_book cb WHERE cb.type='expense' AND (cb.booking_id=b.id OR (cb.booking_id IS NULL AND cb.customer_id=b.customer_id AND NOT EXISTS (SELECT 1 FROM booking_orders b2 WHERE b2.customer_id=b.customer_id AND b2.id<>b.id)))), 0) AS pengeluaran,
            COALESCE((SELECT SUM(inv.paid_amount) FROM invoices inv WHERE inv.status != 'cancelled' AND (inv.internal_notes = CONCAT('booking_id:', b.id) COLLATE utf8mb4_general_ci OR inv.internal_notes = CONCAT('Generated from Reservasi: ', b.booking_no) COLLATE utf8mb4_general_ci)), 0) AS terbayar
        FROM booking_orders b
        LEFT JOIN trip_packages p ON p.id = b.package_id
        WHERE b.customer_id = ?
        ORDER BY b.start_date, b.id
    ", [$lapCustomerId]);
}
$lapCustomerTotalIn = 0;
$lapCustomerTotalOut = 0;
$lapCustomerTotalTerbayar = 0;
foreach ($lapCustomerRows as &$row) {
    $row['margin'] = (float)$row['pemasukan'] - (float)$row['pengeluaran'];
    $row['status_bayar'] = (float)$row['terbayar'] <= 0
        ? 'Belum Bayar'
        : ((float)$row['terbayar'] >= (float)$row['pemasukan'] ? 'Lunas' : 'DP');
    $lapCustomerTotalIn += (float)$row['pemasukan'];
    $lapCustomerTotalOut += (float)$row['pengeluaran'];
    $lapCustomerTotalTerbayar += (float)$row['terbayar'];
}
unset($row);
$lapCustomerTotalMargin = $lapCustomerTotalIn - $lapCustomerTotalOut;

// Detail semua transaksi kas (masuk & keluar) milik tamu ini - baik yang ditandai langsung
// lewat customer_id di cash_book, maupun yang tertaut lewat booking milik tamu tersebut.
$lapCustomerTxRows = [];
$lapCustomerTxIncome = 0;
$lapCustomerTxExpense = 0;
if ($lapCustomerId > 0) {
    $lapCustomerTxRows = lapFetchAll($pdo, "
        SELECT cb.*, bo.booking_no
        FROM cash_book cb
        LEFT JOIN booking_orders bo ON bo.id = cb.booking_id
        WHERE cb.customer_id = ? OR cb.booking_id IN (SELECT id FROM booking_orders WHERE customer_id = ?)
        ORDER BY cb.transaction_date, cb.id
    ", [$lapCustomerId, $lapCustomerId]);
    foreach ($lapCustomerTxRows as $tx) {
        if ($tx['type'] === 'income') $lapCustomerTxIncome += (float)$tx['amount'];
        else $lapCustomerTxExpense += (float)$tx['amount'];
    }
}

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
            <p style="margin:-8px 0 12px;color:#64748b;font-size:12px;">*Berdasarkan uang yang benar-benar sudah diterima/keluar (tanggal transaksi kas) di bulan ini, bukan estimasi.</p>
            <div class="lap-summary">
                <div class="lap-summary-box">
                    <div class="lbl">Total Diterima</div>
                    <div class="val" style="color:#16a34a;"><?php echo sunseaRupiah($lapBulananTotalIn); ?></div>
                </div>
                <div class="lap-summary-box">
                    <div class="lbl">Total Pengeluaran</div>
                    <div class="val" style="color:#dc2626;"><?php echo sunseaRupiah($lapBulananTotalOut); ?></div>
                </div>
                <div class="lap-summary-box">
                    <div class="lbl">Saldo Kas Riil</div>
                    <div class="val" style="color:<?php echo $lapBulananSaldoRiil < 0 ? '#dc2626' : '#0C4A6E'; ?>;"><?php echo sunseaRupiah($lapBulananSaldoRiil); ?></div>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Nama Tamu</th>
                        <th>Diterima</th>
                        <th>Pengeluaran</th>
                        <th>Saldo</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lapBulananRows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:#94a3b8;">Tidak ada transaksi kas pada bulan ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lapBulananRows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['diterima_total']); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                            <td><?php echo sunseaRupiah((float)$r['saldo']); ?></td>
                            <td><?php echo (float)$r['diterima_bulan_lain'] > 0 ? ('DP ' . sunseaRupiah((float)$r['diterima_bulan_lain']) . ' pada ' . date('d M Y', strtotime($r['diterima_bulan_lain_tanggal'])) . ' (bulan lain)') : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Semua</td>
                        <td><?php echo sunseaRupiah($lapBulananTotalIn); ?></td>
                        <td><?php echo sunseaRupiah($lapBulananTotalOut); ?></td>
                        <td><?php echo sunseaRupiah($lapBulananSaldoRiil); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

        <?php elseif ($tab === 'customer'): ?>
            <?php if (!$lapCustomerInfo): ?>
                <p style="color:#94a3b8;">Pilih tamu terlebih dahulu.</p>
            <?php else: ?>
                <p style="margin:-8px 0 14px;color:#64748b;"><?php echo htmlspecialchars($lapCustomerInfo['phone'] ?: ''); ?><?php echo ($lapCustomerInfo['phone'] && $lapCustomerInfo['email']) ? ' &middot; ' : ''; ?><?php echo htmlspecialchars($lapCustomerInfo['email'] ?: ''); ?></p>
                <p style="margin:-8px 0 12px;color:#64748b;font-size:12px;">*Estimasi Pendapatan Trip = nilai kontrak/harga jual trip, belum tentu sudah dibayar penuh. Untuk kas riil yang sudah masuk, lihat halaman Finance.</p>
                <div class="lap-summary">
                    <div class="lap-summary-box">
                        <div class="lbl">Total Estimasi Pendapatan Trip</div>
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
                            <th>Estimasi Pendapatan</th>
                            <th>Terbayar</th>
                            <th>Status Bayar</th>
                            <th>Pengeluaran</th>
                            <th>Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lapCustomerRows)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;color:#94a3b8;">Belum ada booking untuk tamu ini.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lapCustomerRows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['booking_no']); ?></td>
                                <td><?php echo date('d M Y', strtotime($r['start_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                                <td><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                                <td><?php echo sunseaRupiah((float)$r['terbayar']); ?></td>
                                <td><?php echo htmlspecialchars($r['status_bayar']); ?></td>
                                <td><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                                <td><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">Total Semua</td>
                            <td><?php echo sunseaRupiah($lapCustomerTotalTerbayar); ?></td>
                            <td></td>
                            <td><?php echo sunseaRupiah($lapCustomerTotalOut); ?></td>
                            <td><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></td>
                        </tr>
                    </tfoot>
                </table>

                <h3 style="margin:20px 0 8px;">Detail Transaksi Kas - <?php echo htmlspecialchars($lapCustomerInfo['name']); ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Tipe</th>
                            <th>Kategori</th>
                            <th>Deskripsi</th>
                            <th>No. Booking</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lapCustomerTxRows)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:#94a3b8;">Belum ada transaksi kas untuk tamu ini.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lapCustomerTxRows as $tx): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?></td>
                                <td><?php echo $tx['type'] === 'income' ? 'Masuk' : 'Keluar'; ?></td>
                                <td><?php echo htmlspecialchars($tx['category'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                <td><?php echo htmlspecialchars($tx['booking_no'] ?: '-'); ?></td>
                                <td><?php echo sunseaRupiah((float)$tx['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">Saldo Kas (Uang Diterima <?php echo sunseaRupiah($lapCustomerTotalTerbayar); ?> &minus; Pengeluaran <?php echo sunseaRupiah($lapCustomerTxExpense); ?>)</td>
                            <td><?php echo sunseaRupiah($lapCustomerTotalTerbayar - $lapCustomerTxExpense); ?></td>
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

    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:14px;">
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pemasukan</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($lapHarianIncome); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($lapHarianExpense); ?></div>
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

    <p style="margin:-6px 0 12px;color:var(--ss-muted);font-size:12px;">*Berdasarkan uang yang benar-benar sudah diterima/keluar (tanggal transaksi kas) di bulan ini, bukan estimasi.</p>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Diterima</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-success);"><?php echo sunseaRupiah($lapBulananTotalIn); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Total Pengeluaran</div>
            <div style="font-size:17px;font-weight:800;color:var(--ss-danger);"><?php echo sunseaRupiah($lapBulananTotalOut); ?></div>
        </div>
        <div class="ss-card">
            <div style="font-size:12px;color:var(--ss-muted);">Saldo Kas Riil</div>
            <div style="font-size:17px;font-weight:800;color:<?php echo $lapBulananSaldoRiil < 0 ? 'var(--ss-danger)' : 'var(--ss-ocean)'; ?>;"><?php echo sunseaRupiah($lapBulananSaldoRiil); ?></div>
        </div>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Rekap Kas Bulan <?php echo date('F Y', strtotime($lapMonthStart)); ?></div>
        <div class="ss-table-wrap">
            <table class="ss-table" style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Nama Tamu</th>
                        <th style="width:150px;">Diterima</th>
                        <th style="width:150px;">Pengeluaran</th>
                        <th style="width:150px;">Saldo</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lapBulananRows)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:var(--ss-muted);padding:20px;">Tidak ada transaksi kas pada bulan ini.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lapBulananRows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
                            <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$r['diterima_total']); ?></td>
                            <td style="color:var(--ss-danger);font-weight:600;"><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                            <td style="font-weight:700;"><?php echo sunseaRupiah((float)$r['saldo']); ?></td>
                            <td style="color:var(--ss-muted);font-size:11px;"><?php echo (float)$r['diterima_bulan_lain'] > 0 ? ('DP ' . sunseaRupiah((float)$r['diterima_bulan_lain']) . ' pada ' . date('d M Y', strtotime($r['diterima_bulan_lain_tanggal'])) . ' (bulan lain)') : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--ss-gray-2);background:#f1f5f9;">
                        <td style="padding:10px 8px;"><strong>Total Semua</strong></td>
                        <td style="padding:10px 8px;color:var(--ss-success);"><strong><?php echo sunseaRupiah($lapBulananTotalIn); ?></strong></td>
                        <td style="padding:10px 8px;color:var(--ss-danger);"><strong><?php echo sunseaRupiah($lapBulananTotalOut); ?></strong></td>
                        <td style="padding:10px 8px;"><strong><?php echo sunseaRupiah($lapBulananSaldoRiil); ?></strong></td>
                        <td style="padding:10px 8px;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

<?php elseif ($tab === 'customer'): ?>
    <style>
        .lc-field { min-width: 300px; max-width: 420px; flex: 1; }
        .lc-combo { position: relative; }
        .lc-trigger { width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; border: 1px solid var(--ss-gray-2, #e2e8f0); border-radius: 12px; cursor: pointer; text-align: left; font: inherit; transition: border-color .15s, box-shadow .15s; }
        .lc-trigger:hover { border-color: #cbd5e1; }
        .lc-trigger[aria-expanded="true"], .lc-trigger:focus-visible { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, .18); }
        .lc-avatar { flex: none; width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; background: linear-gradient(135deg, #38bdf8, #0369a1); }
        .lc-trigger-text, .lc-item-text { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
        .lc-trigger-text strong, .lc-item-text strong { font-size: 13px; font-weight: 700; color: var(--ss-text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lc-trigger-text small, .lc-item-text small { font-size: 11px; color: var(--ss-muted, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lc-placeholder { color: var(--ss-muted, #64748b); font-size: 13px; }
        .lc-chev { flex: none; width: 16px; height: 16px; color: var(--ss-muted, #64748b); transition: transform .15s; }
        .lc-trigger[aria-expanded="true"] .lc-chev { transform: rotate(180deg); }
        .lc-panel { position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid var(--ss-gray-2, #e2e8f0); border-radius: 14px; box-shadow: 0 18px 40px rgba(15, 23, 42, .16); overflow: hidden; }
        .lc-search { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--ss-gray-2, #e2e8f0); }
        .lc-search svg { width: 15px; height: 15px; color: var(--ss-muted, #64748b); flex: none; }
        .lc-search input { flex: 1; border: 0; outline: 0; font: inherit; font-size: 13px; background: transparent; color: var(--ss-text, #0f172a); }
        .lc-list { max-height: 300px; overflow-y: auto; padding: 6px; }
        .lc-item { width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 0; background: transparent; border-radius: 10px; cursor: pointer; text-align: left; font: inherit; }
        .lc-item:hover, .lc-item:focus-visible { background: #f1f5f9; outline: none; }
        .lc-item.is-active { background: #fff7ed; box-shadow: inset 0 0 0 1px #fed7aa; }
        .lc-empty { padding: 18px; text-align: center; font-size: 12.5px; color: var(--ss-muted, #64748b); }
    </style>
    <div class="ss-card" style="margin-bottom:14px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="tab" value="customer">
            <div class="lc-field">
                <label class="ss-label" style="font-size:11px;">Pilih Tamu <span style="font-weight:400;color:var(--ss-muted);">(trip sudah selesai)</span></label>
                <input type="hidden" name="customer_id" id="lcValue" value="<?php echo (int)$lapCustomerId; ?>">
                <div class="lc-combo" id="lcCombo">
                    <button type="button" class="lc-trigger" id="lcTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="lc-avatar"><?php echo $lapCustomerSelected ? htmlspecialchars(mb_strtoupper(mb_substr($lapCustomerSelected['name'], 0, 1))) : '?'; ?></span>
                        <span class="lc-trigger-text">
                            <?php if ($lapCustomerSelected): ?>
                                <strong><?php echo htmlspecialchars($lapCustomerSelected['name']); ?></strong>
                                <small><?php echo (int)$lapCustomerSelected['trips']; ?> trip<?php echo $lapCustomerSelected['last_end'] ? ' · selesai ' . date('d M Y', strtotime($lapCustomerSelected['last_end'])) : ''; ?></small>
                            <?php else: ?>
                                <span class="lc-placeholder">Pilih tamu...</span>
                            <?php endif; ?>
                        </span>
                        <i data-feather="chevron-down" class="lc-chev"></i>
                    </button>
                    <div class="lc-panel" id="lcPanel" hidden>
                        <div class="lc-search"><i data-feather="search"></i><input type="text" id="lcSearch" placeholder="Cari nama atau nomor tamu..." autocomplete="off"></div>
                        <div class="lc-list" id="lcList" role="listbox">
                            <?php foreach ($lapCustomerList as $c): ?>
                                <button type="button" class="lc-item<?php echo $lapCustomerId === (int)$c['id'] ? ' is-active' : ''; ?>" role="option" data-id="<?php echo (int)$c['id']; ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($c['name'] . ' ' . ($c['phone'] ?? ''))); ?>">
                                    <span class="lc-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($c['name'], 0, 1))); ?></span>
                                    <span class="lc-item-text">
                                        <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                                        <small><?php echo (int)$c['trips']; ?> trip<?php echo $c['last_end'] ? ' · selesai ' . date('d M Y', strtotime($c['last_end'])) : ''; ?><?php echo !empty($c['phone']) ? ' · ' . htmlspecialchars($c['phone']) : ''; ?></small>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                            <div class="lc-empty" id="lcEmpty" hidden>Tidak ada tamu yang cocok.</div>
                            <?php if (empty($lapCustomerList)): ?><div class="lc-empty">Belum ada tamu dengan trip yang sudah selesai.</div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($lapCustomerId > 0): ?>
                <a href="<?php echo lapPrintUrl('customer', ['customer_id' => $lapCustomerId]); ?>" target="_blank" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="printer"></i> Cetak / Simpan PDF</a>
            <?php endif; ?>
        </form>
    </div>

    <script>
        (function () {
            var combo = document.getElementById('lcCombo');
            if (!combo) return;
            var trigger = document.getElementById('lcTrigger'), panel = document.getElementById('lcPanel');
            var search = document.getElementById('lcSearch'), list = document.getElementById('lcList');
            var empty = document.getElementById('lcEmpty'), value = document.getElementById('lcValue');
            function setOpen(open) {
                panel.hidden = !open;
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) { search.value = ''; filter(); search.focus(); var a = list.querySelector('.is-active'); if (a) a.scrollIntoView({ block: 'nearest' }); }
            }
            function filter() {
                var q = search.value.trim().toLowerCase(), shown = 0;
                list.querySelectorAll('.lc-item').forEach(function (it) {
                    var ok = !q || it.getAttribute('data-search').indexOf(q) !== -1;
                    it.style.display = ok ? '' : 'none';
                    if (ok) shown++;
                });
                if (empty) empty.hidden = shown !== 0 || !list.querySelector('.lc-item');
            }
            trigger.addEventListener('click', function () { setOpen(panel.hidden); });
            search.addEventListener('input', filter);
            list.addEventListener('click', function (e) {
                var it = e.target.closest('.lc-item');
                if (!it) return;
                value.value = it.getAttribute('data-id');
                combo.closest('form').submit(); // langsung tampilkan laporan tamu terpilih
            });
            document.addEventListener('click', function (e) { if (!combo.contains(e.target)) setOpen(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
        })();
    </script>

    <?php if (!$lapCustomerInfo): ?>
        <div class="ss-card">
            <div style="text-align:center;color:var(--ss-muted);padding:20px;">Pilih tamu terlebih dahulu untuk melihat laporan finance-nya.</div>
        </div>
    <?php else: ?>
        <p style="margin:-6px 0 12px;color:var(--ss-muted);font-size:12px;">*Estimasi Pendapatan Trip = nilai kontrak/harga jual trip, belum tentu sudah dibayar penuh (lihat kolom "Terbayar"). Untuk kas riil yang sudah masuk/keluar, lihat halaman <strong>Finance</strong>.</p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
            <div class="ss-card">
                <div style="font-size:12px;color:var(--ss-muted);">Total Estimasi Pendapatan Trip</div>
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
                            <th style="width:150px;">Estimasi Pendapatan</th>
                            <th style="width:130px;">Terbayar</th>
                            <th style="width:100px;">Status Bayar</th>
                            <th style="width:130px;">Pengeluaran</th>
                            <th style="width:130px;">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lapCustomerRows)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center;color:var(--ss-muted);padding:20px;">Belum ada booking untuk tamu ini.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lapCustomerRows as $r): ?>
                            <?php
                            $statusColor = $r['status_bayar'] === 'Lunas' ? '#16a34a' : ($r['status_bayar'] === 'DP' ? '#d97706' : '#dc2626');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['booking_no']); ?></td>
                                <td><?php echo date('d M Y', strtotime($r['start_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['package_name'] ?: '-'); ?></td>
                                <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$r['pemasukan']); ?></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$r['terbayar']); ?></td>
                                <td><span style="font-weight:700;color:<?php echo $statusColor; ?>;"><?php echo htmlspecialchars($r['status_bayar']); ?></span></td>
                                <td style="color:var(--ss-danger);font-weight:600;"><?php echo sunseaRupiah((float)$r['pengeluaran']); ?></td>
                                <td style="font-weight:700;"><?php echo sunseaRupiah((float)$r['margin']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--ss-gray-2);background:#f1f5f9;">
                            <td colspan="4" style="padding:10px 8px;"><strong>Total Semua</strong></td>
                            <td style="padding:10px 8px;"><strong><?php echo sunseaRupiah($lapCustomerTotalTerbayar); ?></strong></td>
                            <td style="padding:10px 8px;"></td>
                            <td style="padding:10px 8px;color:var(--ss-danger);"><strong><?php echo sunseaRupiah($lapCustomerTotalOut); ?></strong></td>
                            <td style="padding:10px 8px;"><strong><?php echo sunseaRupiah($lapCustomerTotalMargin); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="ss-card" style="margin-top:14px;">
            <div class="ss-card-title" style="margin-bottom:4px;">Detail Transaksi Kas - <?php echo htmlspecialchars($lapCustomerInfo['name']); ?></div>
            <p style="margin:0 0 10px;color:var(--ss-muted);font-size:11.5px;">Semua uang masuk & keluar yang tercatat di Buku Kas untuk tamu ini (baik yang tertaut langsung maupun lewat booking-nya). Saldo Kas di bawah dihitung dari uang yang benar-benar sudah diterima (Terbayar), bukan cuma transaksi "Masuk" yang tercatat manual di kas — supaya tidak salah kelihatan minus.</p>
            <div class="ss-table-wrap">
                <table class="ss-table" style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th style="width:80px;">Tipe</th>
                            <th>Kategori</th>
                            <th>Deskripsi</th>
                            <th>No. Booking</th>
                            <th style="width:130px;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lapCustomerTxRows)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:var(--ss-muted);padding:20px;">Belum ada transaksi kas untuk tamu ini.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($lapCustomerTxRows as $tx): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?></td>
                                <td><span style="font-weight:700;color:<?php echo $tx['type'] === 'income' ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;"><?php echo $tx['type'] === 'income' ? 'Masuk' : 'Keluar'; ?></span></td>
                                <td><?php echo htmlspecialchars($tx['category'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($tx['description']); ?></td>
                                <td><?php echo htmlspecialchars($tx['booking_no'] ?: '-'); ?></td>
                                <td style="font-weight:600;color:<?php echo $tx['type'] === 'income' ? 'var(--ss-success)' : 'var(--ss-danger)'; ?>;"><?php echo sunseaRupiah((float)$tx['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php $lapCustomerKasSaldo = $lapCustomerTotalTerbayar - $lapCustomerTxExpense; ?>
                        <tr style="border-top:2px solid var(--ss-gray-2);background:#f1f5f9;">
                            <td colspan="5" style="padding:10px 8px;"><strong>Saldo Kas</strong> <span style="color:var(--ss-muted);font-weight:400;">(Uang Diterima <?php echo sunseaRupiah($lapCustomerTotalTerbayar); ?> &minus; Pengeluaran <?php echo sunseaRupiah($lapCustomerTxExpense); ?>)</span></td>
                            <td style="padding:10px 8px;"><strong style="color:<?php echo $lapCustomerKasSaldo < 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;"><?php echo sunseaRupiah($lapCustomerKasSaldo); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>