<?php

/**
 * Sunsea - Owner mobile view: Invoice (read-only list)
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

$statusFilter = $_GET['status'] ?? '';
$where = '';
$params = [];
if (in_array($statusFilter, ['issued', 'partial', 'paid'], true)) {
    $where = 'WHERE i.status = ?';
    $params[] = $statusFilter;
}

$invoices = $pdo->prepare("
    SELECT i.invoice_no, i.status, i.total_amount, i.remaining_amount, i.due_date, c.name AS customer_name
    FROM invoices i JOIN customers c ON c.id = i.customer_id
    $where
    ORDER BY i.created_at DESC
    LIMIT 100
");
$invoices->execute($params);
$invoices = $invoices->fetchAll();

$statusBadge = [
    'issued'  => ['ob-badge-issued', 'Belum Bayar'],
    'partial' => ['ob-badge-partial', 'Sebagian'],
    'paid'    => ['ob-badge-paid', 'Lunas'],
];

$pageTitle = 'Invoice';
include 'owner-mobile-header.php';
?>

<div class="ob-tabs">
    <a href="owner-invoices.php" class="ob-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>">Semua</a>
    <a href="owner-invoices.php?status=issued" class="ob-tab <?php echo $statusFilter === 'issued' ? 'active' : ''; ?>">Belum Bayar</a>
    <a href="owner-invoices.php?status=partial" class="ob-tab <?php echo $statusFilter === 'partial' ? 'active' : ''; ?>">Sebagian</a>
    <a href="owner-invoices.php?status=paid" class="ob-tab <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>">Lunas</a>
</div>

<div class="ob-section">
    <?php if (empty($invoices)): ?>
        <div class="ob-empty">Belum ada data invoice.</div>
    <?php else: ?>
        <?php foreach ($invoices as $inv): ?>
            <?php $badge = $statusBadge[$inv['status']] ?? ['ob-badge-draft', $inv['status']]; ?>
            <div class="ob-row">
                <div>
                    <div class="ob-row-title"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                    <div class="ob-row-sub"><?php echo htmlspecialchars($inv['customer_name']); ?> · JT <?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></div>
                    <div class="ob-row-sub">Sisa: <?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></div>
                </div>
                <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include 'owner-mobile-footer.php'; ?>
