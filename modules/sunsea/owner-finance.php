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

$pageTitle = 'Finance';
include 'owner-mobile-header.php';
?>

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

<div class="ob-section">
    <?php if (empty($rows)): ?>
        <div class="ob-empty">Belum ada transaksi bulan ini.</div>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <div class="ob-row">
                <div>
                    <div class="ob-row-title"><?php echo htmlspecialchars($r['description'] ?: ($r['customer_name'] ?: '-')); ?></div>
                    <div class="ob-row-sub"><?php echo date('d M Y', strtotime($r['transaction_date'])); ?><?php echo $r['customer_name'] ? ' · ' . htmlspecialchars($r['customer_name']) : ''; ?></div>
                </div>
                <span class="ob-badge <?php echo $r['type'] === 'income' ? 'ob-badge-income' : 'ob-badge-expense'; ?>">
                    <?php echo ($r['type'] === 'income' ? '+' : '-') . sunseaRupiah((float)$r['amount'], true); ?>
                </span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
