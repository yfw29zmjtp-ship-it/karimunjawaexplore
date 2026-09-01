<?php
/**
 * CQC Project Completion Report
 * Laporan pemasukan, pengeluaran, dan profit proyek — Compact & Elegant
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$justCompleted = isset($_GET['just_completed']) && $_GET['just_completed'] == '1';

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Get project details
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("Proyek tidak ditemukan.");
}

// === PENGELUARAN ===
$stmt = $pdo->prepare("
    SELECT pe.*, ec.category_name, ec.category_icon 
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    ORDER BY pe.expense_date ASC
");
$stmt->execute([$id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT ec.category_name, ec.category_icon, 
           SUM(pe.amount) as total, COUNT(*) as count
    FROM cqc_project_expenses pe
    LEFT JOIN cqc_expense_categories ec ON pe.category_id = ec.id
    WHERE pe.project_id = ?
    GROUP BY pe.category_id
    ORDER BY total DESC
");
$stmt->execute([$id]);
$expenseByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalExpense = array_sum(array_column($expenses, 'amount'));

// === PEMASUKAN ===
$stmt = $pdo->prepare("SELECT * FROM cqc_termin_invoices WHERE project_id = ? ORDER BY termin_number ASC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$totalPaid = array_sum(array_column($invoices, 'paid_amount'));
$totalUnpaid = $totalInvoiced - $totalPaid;

$db = Database::getInstance();

// Cash book income linked to project
$cashbookIncome = $db->fetchAll(
    "SELECT * FROM cash_book WHERE transaction_type = 'income' AND description LIKE ? ORDER BY transaction_date ASC",
    ['%[CQC_PROJECT:' . $id . ']%']
);
$totalCashbookIncome = array_sum(array_column($cashbookIncome, 'amount'));

// Cash book expenses linked
$cashbookExpenses = $db->fetchAll(
    "SELECT * FROM cash_book WHERE transaction_type = 'expense' AND description LIKE ? ORDER BY transaction_date ASC",
    ['%[CQC_PROJECT:' . $id . ']%']
);

// === PROFIT ===
$contractValue = !empty($invoices) ? floatval($invoices[0]['contract_value']) : 0;
$totalIncome = $totalPaid;
$profit = $totalIncome - $totalExpense;
$profitMargin = $totalIncome > 0 ? round(($profit / $totalIncome) * 100, 1) : 0;
$budgetEfficiency = floatval($project['budget_idr']) > 0 ? round(($totalExpense / floatval($project['budget_idr'])) * 100, 1) : 0;

$startDate = $project['start_date'] ? new DateTime($project['start_date']) : null;
$endDate = $project['actual_completion'] ? new DateTime($project['actual_completion']) : ($project['end_date'] ? new DateTime($project['end_date']) : new DateTime());
$duration = $startDate ? $startDate->diff($endDate)->days : 0;

include '../../includes/header.php';
?>

<style>
:root { --gold: #f0b429; --gold-light: #fef3c7; --gold-dark: #d4960d; --navy: #0d1f3c; }

.rpt { max-width: 100%; margin: 0; padding: 0 12px; }

/* Hero - Clean Light with Gold Gradient */
.rpt-hero {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 50%, #fff 100%);
    border-radius: 8px; padding: 12px 14px; margin-bottom: 8px;
    border: 1px solid #f0b429; border-left: 4px solid var(--gold);
    position: relative;
}
.rpt-hero-top { display: flex; justify-content: space-between; align-items: start; }
.rpt-hero-tag { font-size: 8px; color: var(--gold-dark); font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 1px; }
.rpt-hero h2 { font-size: 14px; font-weight: 700; margin: 0 0 2px 0; color: var(--navy); }
.rpt-hero-sub { font-size: 10px; color: #64748b; }
.rpt-hero-pills { display: flex; gap: 5px; margin-top: 6px; font-size: 9px; flex-wrap: wrap; }
.rpt-pill { padding: 2px 6px; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; color: #475569; }
.rpt-pill-gold { background: var(--gold); color: var(--navy); border-color: var(--gold); font-weight: 600; }
.rpt-badge {
    padding: 3px 8px; border-radius: 4px; font-size: 9px; font-weight: 700;
    background: #059669; color: #fff; white-space: nowrap;
}

/* 4 Cards Row - Clean with Gold Top */
.rpt-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 8px; }
.rpt-card {
    background: linear-gradient(180deg, #fffbeb 0%, #fff 30%);
    border-radius: 6px; padding: 8px 6px; border: 1px solid #e2e8f0;
    text-align: center; border-top: 2px solid var(--gold);
}
.rpt-card-lbl { font-size: 8px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.2px; margin-bottom: 2px; }
.rpt-card-val { font-size: 11px; font-weight: 700; }
.rpt-card-note { font-size: 7px; color: #94a3b8; margin-top: 1px; }
.rpt-card.highlight { border: 1px solid #059669; border-top: 2px solid #059669; background: linear-gradient(180deg, #dcfce7 0%, #fff 30%); }

/* Profit Strip - Elegant Gold Gradient */
.rpt-profit {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid var(--gold); border-radius: 6px; padding: 10px 12px; margin-bottom: 8px;
    display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; gap: 8px; align-items: center;
}
.rpt-profit-item { text-align: center; }
.rpt-profit-lbl { font-size: 8px; color: #92400e; text-transform: uppercase; letter-spacing: 0.2px; margin-bottom: 1px; font-weight: 600; }
.rpt-profit-val { font-size: 12px; font-weight: 700; color: var(--navy); }
.rpt-profit-val.green { color: #059669; }
.rpt-profit-val.red { color: #dc2626; }
.rpt-profit-val.gold { color: var(--gold-dark); font-size: 14px; }
.rpt-profit-op { font-size: 14px; color: #d4960d; text-align: center; font-weight: 300; }

/* Sections */
.rpt-sec {
    background: #fff; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 8px; overflow: hidden;
}
.rpt-sec-hdr {
    padding: 6px 10px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 5px;
    background: linear-gradient(90deg, #fffbeb, #fff); border-left: 3px solid var(--gold);
}
.rpt-sec-ttl { font-size: 11px; font-weight: 700; color: var(--navy); }
.rpt-sec-sub { font-size: 8px; color: #94a3b8; margin-left: auto; }
.rpt-sec-body { padding: 0; }

/* Table */
.rpt-tbl { width: 100%; border-collapse: collapse; font-size: 9px; }
.rpt-tbl th {
    padding: 5px 6px; text-align: left; background: #fafbfc; border-bottom: 1px solid #e2e8f0;
    font-size: 8px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px;
}
.rpt-tbl td { padding: 4px 6px; border-bottom: 1px solid #f8fafc; }
.rpt-tbl tr:hover { background: #fffbeb; }
.rpt-tbl .tot td { background: linear-gradient(90deg, #fffbeb, #fff); font-weight: 700; border-top: 1px solid var(--gold); }

/* Status badges */
.st { padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: 600; }
.st-paid { background: #dcfce7; color: #166534; }
.st-partial { background: #fef3c7; color: #92400e; }
.st-pending { background: #f1f5f9; color: #475569; }
.st-overdue { background: #fee2e2; color: #991b1b; }

/* Category bar */
.cat-bar { width: 35px; height: 3px; background: #e2e8f0; border-radius: 2px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 2px; }
.cat-bar-fill { height: 100%; background: linear-gradient(90deg, var(--gold), var(--gold-dark)); border-radius: 2px; }

/* Buttons */
.rpt-actions { display: flex; gap: 5px; justify-content: center; margin: 12px 0; flex-wrap: wrap; }
.rpt-btn {
    display: inline-flex; align-items: center; gap: 3px; padding: 5px 12px;
    border-radius: 5px; text-decoration: none; font-size: 10px; font-weight: 600;
    transition: all 0.15s; border: none; cursor: pointer;
}
.rpt-btn-gold { background: linear-gradient(135deg, var(--gold), var(--gold-dark)); color: var(--navy); }
.rpt-btn-gold:hover { background: linear-gradient(135deg, var(--gold-dark), #b7800a); }
.rpt-btn-out { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
.rpt-btn-out:hover { background: #f8fafc; border-color: #cbd5e1; }
.rpt-btn-green { background: linear-gradient(135deg, #059669, #047857); color: #fff; }
.rpt-btn-green:hover { background: linear-gradient(135deg, #047857, #065f46); }

/* Empty state */
.rpt-empty { text-align: center; padding: 16px; color: #94a3b8; font-size: 10px; }

<?php if ($justCompleted): ?>
.confetti {
    background: linear-gradient(135deg, #059669, #10b981); border-radius: 6px; padding: 10px;
    text-align: center; margin-bottom: 10px; color: #fff; animation: slideIn 0.4s ease;
}
@keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
<?php endif; ?>

@media (max-width: 768px) {
    .rpt-cards { grid-template-columns: repeat(2, 1fr); }
    .rpt-profit { grid-template-columns: 1fr; gap: 6px; }
    .rpt-profit-op { display: none; }
}
@media print {
    .sidebar, .navbar, .rpt-actions, .confetti { display: none !important; }
    .rpt { max-width: 100%; margin: 0; }
    .rpt-hero { break-after: avoid; }
    .rpt-sec { break-inside: avoid; }
}
</style>

<div class="rpt">

<?php if ($justCompleted): ?>
<div class="confetti">
    <div style="font-size: 28px; margin-bottom: 4px;">🎉</div>
    <div style="font-size: 15px; font-weight: 700;">Proyek Selesai!</div>
    <div style="font-size: 12px; opacity: 0.9; margin-top: 2px;"><?php echo htmlspecialchars($project['project_name']); ?> telah berhasil diselesaikan</div>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="rpt-hero">
    <div class="rpt-hero-top">
        <div>
            <div class="rpt-hero-tag">📊 Laporan Proyek</div>
            <h2><?php echo htmlspecialchars($project['project_name']); ?></h2>
            <div class="rpt-hero-sub">
                <?php echo htmlspecialchars($project['project_code']); ?> • <?php echo htmlspecialchars($project['client_name'] ?? '-'); ?>
                <?php if ($project['location']): ?> • <?php echo htmlspecialchars($project['location']); ?><?php endif; ?>
            </div>
            <div class="rpt-hero-pills">
                <span class="rpt-pill">📅 <?php echo $project['start_date'] ? date('d M Y', strtotime($project['start_date'])) : '-'; ?> — <?php echo $project['actual_completion'] ? date('d M Y', strtotime($project['actual_completion'])) : ($project['end_date'] ? date('d M Y', strtotime($project['end_date'])) : 'Ongoing'); ?></span>
                <span class="rpt-pill">⏱ <?php echo $duration; ?> hari</span>
                <?php if (floatval($project['solar_capacity_kwp']) > 0): ?>
                <span class="rpt-pill rpt-pill-gold">⚡ <?php echo number_format($project['solar_capacity_kwp'], 1); ?> kWp</span>
                <?php endif; ?>
            </div>
        </div>
        <span class="rpt-badge">
            <?php echo $project['status'] === 'completed' ? '✓ COMPLETED' : strtoupper($project['status']); ?>
        </span>
    </div>
</div>

<!-- Summary Cards -->
<div class="rpt-cards">
    <div class="rpt-card">
        <div class="rpt-card-lbl">Nilai Kontrak</div>
        <div class="rpt-card-val" style="color: var(--navy);">Rp <?php echo number_format($contractValue, 0, ',', '.'); ?></div>
    </div>
    <div class="rpt-card">
        <div class="rpt-card-lbl">Total Pemasukan</div>
        <div class="rpt-card-val" style="color: #059669;">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
        <?php if ($totalUnpaid > 0): ?>
        <div class="rpt-card-note" style="color: #dc2626;">Belum bayar: Rp <?php echo number_format($totalUnpaid, 0, ',', '.'); ?></div>
        <?php endif; ?>
    </div>
    <div class="rpt-card">
        <div class="rpt-card-lbl">Total Pengeluaran</div>
        <div class="rpt-card-val" style="color: #dc2626;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
        <div class="rpt-card-note">Budget: Rp <?php echo number_format($project['budget_idr'], 0, ',', '.'); ?></div>
    </div>
    <div class="rpt-card highlight" style="border-color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">
        <div class="rpt-card-lbl">Profit / Loss</div>
        <div class="rpt-card-val" style="color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">
            <?php echo $profit >= 0 ? '+' : ''; ?>Rp <?php echo number_format($profit, 0, ',', '.'); ?>
        </div>
        <div class="rpt-card-note" style="color: <?php echo $profit >= 0 ? '#059669' : '#dc2626'; ?>;">Margin: <?php echo $profitMargin; ?>%</div>
    </div>
</div>

<!-- Profit Strip -->
<div class="rpt-profit">
    <div class="rpt-profit-item">
        <div class="rpt-profit-lbl">Pemasukan</div>
        <div class="rpt-profit-val" style="color: #4ade80;">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
    </div>
    <div class="rpt-profit-op">−</div>
    <div class="rpt-profit-item">
        <div class="rpt-profit-lbl">Pengeluaran</div>
        <div class="rpt-profit-val" style="color: #f87171;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
    </div>
    <div class="rpt-profit-op">=</div>
    <div class="rpt-profit-item">
        <div class="rpt-profit-lbl"><?php echo $profit >= 0 ? 'PROFIT' : 'RUGI'; ?></div>
        <div class="rpt-profit-val" style="font-size: 22px; color: <?php echo $profit >= 0 ? 'var(--gold)' : '#f87171'; ?>;">
            <?php echo $profit >= 0 ? '+' : ''; ?>Rp <?php echo number_format($profit, 0, ',', '.'); ?>
        </div>
    </div>
</div>

<!-- Invoice & Pembayaran -->
<div class="rpt-sec">
    <div class="rpt-sec-hdr">
        <span style="font-size: 15px;">💰</span>
        <div>
            <div class="rpt-sec-ttl">Pemasukan — Invoice & Pembayaran</div>
            <div class="rpt-sec-sub"><?php echo count($invoices); ?> invoice termin</div>
        </div>
    </div>
    <div class="rpt-sec-body">
        <?php if (!empty($invoices)): ?>
        <table class="rpt-tbl">
            <thead>
                <tr>
                    <th>No</th><th>Invoice</th><th>Termin</th><th>Tanggal</th>
                    <th style="text-align:right;">Nilai</th><th style="text-align:right;">Dibayar</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i => $inv): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><strong><?php echo htmlspecialchars($inv['invoice_number']); ?></strong></td>
                    <td>Termin <?php echo $inv['termin_number']; ?> (<?php echo $inv['percentage']; ?>%)</td>
                    <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
                    <td style="text-align:right; font-family:monospace;">Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?></td>
                    <td style="text-align:right; font-family:monospace; color:<?php echo $inv['paid_amount'] > 0 ? '#059669' : '#94a3b8'; ?>;">
                        Rp <?php echo number_format($inv['paid_amount'], 0, ',', '.'); ?>
                    </td>
                    <td>
                        <span class="st st-<?php echo $inv['payment_status']; ?>"><?php echo ucfirst($inv['payment_status']); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="4" style="text-align:right;">TOTAL</td>
                    <td style="text-align:right; font-family:monospace;">Rp <?php echo number_format($totalInvoiced, 0, ',', '.'); ?></td>
                    <td style="text-align:right; font-family:monospace; color:#059669;">Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="rpt-empty">📭 Belum ada invoice termin</div>
        <?php endif; ?>
    </div>
</div>

<!-- Ringkasan per Kategori -->
<div class="rpt-sec">
    <div class="rpt-sec-hdr">
        <span style="font-size: 15px;">📊</span>
        <div>
            <div class="rpt-sec-ttl">Ringkasan Pengeluaran per Kategori</div>
            <div class="rpt-sec-sub">Efisiensi budget: <?php echo $budgetEfficiency; ?>%</div>
        </div>
    </div>
    <div class="rpt-sec-body">
        <?php if (!empty($expenseByCategory)): ?>
        <table class="rpt-tbl">
            <thead>
                <tr><th>Kategori</th><th style="text-align:center;">Transaksi</th><th style="text-align:right;">Total</th><th style="text-align:right;">%</th></tr>
            </thead>
            <tbody>
                <?php foreach ($expenseByCategory as $cat):
                    $pct = $totalExpense > 0 ? round(($cat['total'] / $totalExpense) * 100, 1) : 0;
                ?>
                <tr>
                    <td><span style="margin-right:4px;"><?php echo $cat['category_icon'] ?? '📦'; ?></span> <?php echo htmlspecialchars($cat['category_name'] ?? 'Lainnya'); ?></td>
                    <td style="text-align:center;"><?php echo $cat['count']; ?>x</td>
                    <td style="text-align:right; font-family:monospace; font-weight:600;">Rp <?php echo number_format($cat['total'], 0, ',', '.'); ?></td>
                    <td style="text-align:right;">
                        <span class="cat-bar"><span class="cat-bar-fill" style="width:<?php echo $pct; ?>%"></span></span>
                        <?php echo $pct; ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td>TOTAL</td>
                    <td style="text-align:center;"><?php echo count($expenses); ?>x</td>
                    <td style="text-align:right; font-family:monospace;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></td>
                    <td style="text-align:right;">100%</td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="rpt-empty">📭 Belum ada pengeluaran</div>
        <?php endif; ?>
    </div>
</div>

<!-- Detail Pengeluaran -->
<div class="rpt-sec">
    <div class="rpt-sec-hdr">
        <span style="font-size: 15px;">📋</span>
        <div>
            <div class="rpt-sec-ttl">Detail Pengeluaran</div>
            <div class="rpt-sec-sub"><?php echo count($expenses); ?> transaksi</div>
        </div>
    </div>
    <div class="rpt-sec-body">
        <?php if (!empty($expenses)): ?>
        <table class="rpt-tbl">
            <thead>
                <tr><th>No</th><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th style="text-align:right;">Jumlah</th></tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $i => $exp): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></td>
                    <td><span style="margin-right:3px;"><?php echo $exp['category_icon'] ?? '📦'; ?></span> <?php echo htmlspecialchars($exp['category_name'] ?? 'Lainnya'); ?></td>
                    <td><?php echo htmlspecialchars($exp['description']); ?></td>
                    <td style="text-align:right; font-family:monospace; color:#dc2626;">Rp <?php echo number_format($exp['amount'], 0, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="tot">
                    <td colspan="4" style="text-align:right;">TOTAL PENGELUARAN</td>
                    <td style="text-align:right; font-family:monospace; color:#dc2626;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="rpt-empty">📭 Belum ada pengeluaran</div>
        <?php endif; ?>
    </div>
</div>

<!-- Actions -->
<div class="rpt-actions">
    <a href="dashboard.php" class="rpt-btn rpt-btn-out">← Kembali</a>
    <a href="detail.php?id=<?php echo $id; ?>" class="rpt-btn rpt-btn-out">📁 Detail Proyek</a>
    <a href="berita-acara.php?id=<?php echo $id; ?>" class="rpt-btn rpt-btn-gold">📄 Cetak Berita Acara</a>
    <button onclick="window.print();" class="rpt-btn rpt-btn-green">🖨 Print Laporan</button>
</div>

</div>

<?php include '../../includes/footer.php'; ?>
