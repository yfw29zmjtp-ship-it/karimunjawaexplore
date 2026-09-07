<?php

/**
 * Sunsea - Owner mobile view: Finance (read-only list, bulan berjalan)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureFinanceSchema($pdo);

$dateFrom = date('Y-m-01');
$dateTo   = date('Y-m-t');

$rows = $pdo->prepare("
    SELECT cb.type, cb.amount, cb.transaction_date, cb.description, c.name AS customer_name
    FROM cash_book cb
    LEFT JOIN customers c ON c.id = cb.customer_id
    WHERE cb.transaction_date BETWEEN ? AND ?
    ORDER BY cb.transaction_date DESC, cb.id DESC
    LIMIT 150
");
$rows->execute([$dateFrom, $dateTo]);
$rows = $rows->fetchAll();

$totalIncome = 0;
$totalExpense = 0;
foreach ($rows as $r) {
    if ($r['type'] === 'income') {
        $totalIncome += (float)$r['amount'];
    } else {
        $totalExpense += (float)$r['amount'];
    }
}
$balance = $totalIncome - $totalExpense;

// Pie: proporsi masuk vs keluar bulan ini
$pieTotal = $totalIncome + $totalExpense;
$incomePct = $pieTotal > 0 ? round($totalIncome / $pieTotal * 100) : 0;
$expensePct = 100 - $incomePct;

// Mini bar: tren 7 hari terakhir
$trendFrom = date('Y-m-d', strtotime('-6 days'));
$trendRows = $pdo->prepare("
    SELECT DATE(transaction_date) AS d,
           SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS inc,
           SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS exp
    FROM cash_book
    WHERE transaction_date BETWEEN ? AND ?
    GROUP BY DATE(transaction_date)
");
$trendRows->execute([$trendFrom, date('Y-m-d')]);
$trendMap = [];
foreach ($trendRows->fetchAll() as $tr) {
    $trendMap[$tr['d']] = ['inc' => (float)$tr['inc'], 'exp' => (float)$tr['exp']];
}
$trendDays = [];
$trendMax = 1;
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $inc = $trendMap[$d]['inc'] ?? 0;
    $exp = $trendMap[$d]['exp'] ?? 0;
    $trendDays[] = ['label' => date('d/m', strtotime($d)), 'inc' => $inc, 'exp' => $exp];
    $trendMax = max($trendMax, $inc, $exp);
}

$pageTitle = 'Finance';
include 'owner-mobile-header.php';
?>

<style>
    .ob-charts-row { display:flex; gap:10px; margin-bottom:12px; }
    .ob-chart-card {
        flex:1; background:#fff; border:1px solid var(--border); border-radius:14px;
        padding:12px 10px; box-shadow:0 1px 3px rgba(0,0,0,.05); text-align:center;
    }
    .ob-chart-title { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; margin-bottom:8px; }
    .ob-donut {
        width:84px; height:84px; border-radius:50%; margin:0 auto 8px; position:relative;
        background:conic-gradient(var(--success) 0% <?php echo $incomePct; ?>%, var(--danger) <?php echo $incomePct; ?>% 100%);
    }
    .ob-donut::after {
        content:''; position:absolute; inset:12px; background:#fff; border-radius:50%;
    }
    .ob-donut-label {
        position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
        font-size:12px; font-weight:800; color:var(--text); z-index:1;
    }
    .ob-chart-legend { display:flex; justify-content:center; gap:10px; font-size:9.5px; color:var(--muted); }
    .ob-chart-legend span { display:inline-flex; align-items:center; gap:3px; }
    .ob-dot { width:7px; height:7px; border-radius:50%; display:inline-block; }
    .ob-bars {
        display:flex; align-items:flex-end; justify-content:space-between; gap:3px;
        height:84px; margin-bottom:6px;
    }
    .ob-bar-col { flex:1; display:flex; align-items:flex-end; justify-content:center; gap:1.5px; height:100%; }
    .ob-bar { width:5px; border-radius:2px 2px 0 0; min-height:2px; }
    .ob-bar.inc { background:var(--success); }
    .ob-bar.exp { background:var(--danger); }
    .ob-bar-labels { display:flex; justify-content:space-between; font-size:7.5px; color:var(--muted); }
    .ob-tx-row {
        display:flex; justify-content:space-between; align-items:center; padding:8px 4px;
        border-bottom:1px solid var(--border);
    }
    .ob-tx-row:last-child { border-bottom:none; }
    .ob-tx-title { font-size:11.5px; font-weight:700; color:var(--text); }
    .ob-tx-sub { font-size:10px; color:var(--muted); margin-top:1px; }
    .ob-tx-amount { font-size:11px; font-weight:700; flex-shrink:0; padding-left:8px; }
    .ob-summary { margin-bottom:10px; }
    .ob-summary-item { padding:8px 6px; }
    .ob-summary-label { font-size:8.5px; }
    .ob-summary-value { font-size:12.5px; margin-top:2px; }
    .ob-section-head { margin-bottom:6px; }
    .ob-section-title { font-size:12px; font-weight:700; color:var(--text); }
</style>

<div class="ob-summary">
    <div class="ob-summary-item">
        <div class="ob-summary-label">Masuk</div>
        <div class="ob-summary-value" style="color:var(--success);"><?php echo sunseaRupiah($totalIncome, true); ?></div>
    </div>
    <div class="ob-summary-item">
        <div class="ob-summary-label">Keluar</div>
        <div class="ob-summary-value" style="color:var(--danger);"><?php echo sunseaRupiah($totalExpense, true); ?></div>
    </div>
    <div class="ob-summary-item">
        <div class="ob-summary-label">Saldo</div>
        <div class="ob-summary-value" style="color:<?php echo $balance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;"><?php echo sunseaRupiah($balance, true); ?></div>
    </div>
</div>

<div class="ob-charts-row">
    <div class="ob-chart-card">
        <div class="ob-chart-title">Masuk vs Keluar</div>
        <div class="ob-donut">
            <div class="ob-donut-label"><?php echo $incomePct; ?>%</div>
        </div>
        <div class="ob-chart-legend">
            <span><span class="ob-dot" style="background:var(--success);"></span> Masuk</span>
            <span><span class="ob-dot" style="background:var(--danger);"></span> Keluar</span>
        </div>
    </div>
    <div class="ob-chart-card">
        <div class="ob-chart-title">Tren 7 Hari</div>
        <div class="ob-bars">
            <?php foreach ($trendDays as $t): ?>
                <div class="ob-bar-col">
                    <div class="ob-bar inc" style="height:<?php echo max(2, round($t['inc'] / $trendMax * 100)); ?>%;"></div>
                    <div class="ob-bar exp" style="height:<?php echo max(2, round($t['exp'] / $trendMax * 100)); ?>%;"></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ob-bar-labels">
            <?php foreach ($trendDays as $i => $t): if ($i % 2 === 0): ?>
                <span><?php echo $t['label']; ?></span>
            <?php endif; endforeach; ?>
        </div>
    </div>
</div>

<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Riwayat Transaksi</div>
    </div>
    <?php if (empty($rows)): ?>
        <div class="ob-empty">Belum ada transaksi bulan ini.</div>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <div class="ob-tx-row">
                <div style="min-width:0;">
                    <div class="ob-tx-title"><?php echo htmlspecialchars($r['description'] ?: ($r['customer_name'] ?: '-')); ?></div>
                    <div class="ob-tx-sub"><?php echo date('d M Y', strtotime($r['transaction_date'])); ?><?php echo $r['customer_name'] ? ' · ' . htmlspecialchars($r['customer_name']) : ''; ?></div>
                </div>
                <div class="ob-tx-amount" style="color:<?php echo $r['type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo ($r['type'] === 'income' ? '+' : '-') . sunseaRupiah((float)$r['amount'], true); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
