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
    .ob-charts-panel {
        background:linear-gradient(135deg,#e0f2fe 0%,#ede9fe 50%,#fce7f3 100%);
        border-radius:20px; padding:12px; margin-bottom:12px;
    }
    .ob-charts-row { display:flex; gap:10px; }
    .ob-chart-card {
        flex:1; background:rgba(255,255,255,.55); backdrop-filter:blur(12px) saturate(160%);
        -webkit-backdrop-filter:blur(12px) saturate(160%);
        border:1px solid rgba(255,255,255,.7); border-radius:16px;
        padding:12px 10px; box-shadow:0 8px 20px rgba(31,41,55,.08), inset 0 1px 0 rgba(255,255,255,.6);
        text-align:center;
    }
    .ob-chart-title { font-size:9.5px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px; }
    .ob-donut {
        width:82px; height:82px; border-radius:50%; margin:0 auto 8px; position:relative;
        background:conic-gradient(var(--success) 0% <?php echo $incomePct; ?>%, var(--danger) <?php echo $incomePct; ?>% 100%);
        box-shadow:0 4px 14px rgba(31,41,55,.15);
    }
    .ob-donut::before {
        content:''; position:absolute; inset:-4px; border-radius:50%;
        background:conic-gradient(var(--success) 0% <?php echo $incomePct; ?>%, var(--danger) <?php echo $incomePct; ?>% 100%);
        filter:blur(6px); opacity:.35; z-index:-1;
    }
    .ob-donut::after {
        content:''; position:absolute; inset:14px; background:rgba(255,255,255,.85);
        backdrop-filter:blur(4px); border-radius:50%; box-shadow:inset 0 1px 3px rgba(0,0,0,.06);
    }
    .ob-donut-label {
        position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
        font-size:13px; font-weight:700; color:var(--text); z-index:1;
    }
    .ob-chart-legend { display:flex; justify-content:center; gap:10px; font-size:9px; color:var(--muted); font-weight:500; }
    .ob-chart-legend span { display:inline-flex; align-items:center; gap:3px; }
    .ob-dot { width:7px; height:7px; border-radius:50%; display:inline-block; box-shadow:0 0 0 3px rgba(255,255,255,.5); }
    .ob-bars {
        display:flex; align-items:flex-end; justify-content:space-between; gap:3px;
        height:82px; margin-bottom:6px;
    }
    .ob-bar-col { flex:1; display:flex; align-items:flex-end; justify-content:center; gap:1.5px; height:100%; }
    .ob-bar { width:5px; border-radius:3px 3px 0 0; min-height:2px; }
    .ob-bar.inc { background:linear-gradient(180deg,#6ee7b7,var(--success)); box-shadow:0 0 6px rgba(16,185,129,.35); }
    .ob-bar.exp { background:linear-gradient(180deg,#fca5a5,var(--danger)); box-shadow:0 0 6px rgba(239,68,68,.35); }
    .ob-bar-labels { display:flex; justify-content:space-between; font-size:7.5px; color:var(--muted); font-weight:500; }
    .ob-tx-row {
        display:flex; align-items:center; gap:10px; padding:9px 4px;
        border-bottom:1px solid var(--border);
    }
    .ob-tx-row:last-child { border-bottom:none; }
    .ob-tx-date {
        flex-shrink:0; width:38px; text-align:center; border-radius:10px;
        background:rgba(99,102,241,.08); padding:4px 2px;
    }
    .ob-tx-date .d { font-size:12px; font-weight:600; color:var(--text); line-height:1.1; }
    .ob-tx-date .m { font-size:8px; color:var(--muted); text-transform:uppercase; }
    .ob-tx-mid { flex:1; min-width:0; }
    .ob-tx-title { font-size:11.5px; font-weight:500; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .ob-tx-sub { font-size:10px; color:var(--muted); font-weight:400; margin-top:1px; }
    .ob-tx-amount { font-size:11px; font-weight:600; flex-shrink:0; }
    .ob-summary { margin-bottom:10px; }
    .ob-summary-item { padding:8px 6px; }
    .ob-summary-label { font-size:8.5px; font-weight:500; }
    .ob-summary-value { font-size:12.5px; font-weight:600; margin-top:2px; }
    .ob-section-head { margin-bottom:6px; }
    .ob-section-title { font-size:12px; font-weight:600; color:var(--text); }
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

<div class="ob-charts-panel">
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
                <div class="ob-tx-date">
                    <div class="d"><?php echo date('d', strtotime($r['transaction_date'])); ?></div>
                    <div class="m"><?php echo date('M', strtotime($r['transaction_date'])); ?></div>
                </div>
                <div class="ob-tx-mid">
                    <div class="ob-tx-title"><?php echo htmlspecialchars($r['description'] ?: ($r['customer_name'] ?: '-')); ?></div>
                    <div class="ob-tx-sub"><?php echo $r['customer_name'] ? htmlspecialchars($r['customer_name']) : date('Y', strtotime($r['transaction_date'])); ?></div>
                </div>
                <div class="ob-tx-amount" style="color:<?php echo $r['type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo ($r['type'] === 'income' ? '+' : '-') . sunseaRupiah((float)$r['amount'], true); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
