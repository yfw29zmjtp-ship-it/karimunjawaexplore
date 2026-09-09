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
if (!$auth->isLoggedIn()) {
    header('Location: owner-login.php');
    exit;
}
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
    SELECT i.id, i.invoice_no, i.status, i.total_amount, i.remaining_amount, i.due_date, c.name AS customer_name
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

<a href="owner-invoice-add.php" class="ob-qbtn" style="width:100%;margin-bottom:12px;">
    <i data-feather="plus-circle"></i> Tambah Invoice Baru
</a>

<div class="ob-tabs">
    <a href="owner-invoices.php" class="ob-tab <?php echo $statusFilter === '' ? 'active' : ''; ?>">Semua</a>
    <a href="owner-invoices.php?status=issued" class="ob-tab <?php echo $statusFilter === 'issued' ? 'active' : ''; ?>">Belum Bayar</a>
    <a href="owner-invoices.php?status=partial" class="ob-tab <?php echo $statusFilter === 'partial' ? 'active' : ''; ?>">Sebagian</a>
    <a href="owner-invoices.php?status=paid" class="ob-tab <?php echo $statusFilter === 'paid' ? 'active' : ''; ?>">Lunas</a>
</div>

<?php if (empty($invoices)): ?>
    <div class="ob-section">
        <div class="ob-empty">Belum ada data invoice.</div>
    </div>
<?php else: ?>
    <?php foreach ($invoices as $inv): ?>
        <?php $badge = $statusBadge[$inv['status']] ?? ['ob-badge-draft', $inv['status']]; ?>
        <a href="owner-invoice-detail.php?id=<?php echo (int)$inv['id']; ?>" class="ob-bcard" style="display:block;text-decoration:none;color:inherit;">
            <div class="ob-bcard-top">
                <div class="ob-bcard-avatar" style="background:linear-gradient(135deg,#0EA5E9,#0369A1);"><i data-feather="file-text" style="width:16px;height:16px;"></i></div>
                <div class="ob-bcard-info">
                    <div class="ob-bcard-name"><?php echo htmlspecialchars($inv['invoice_no']); ?></div>
                    <div class="ob-bcard-no"><?php echo htmlspecialchars($inv['customer_name']); ?></div>
                </div>
                <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
            </div>
            <div class="ob-bcard-meta">
                <i data-feather="calendar"></i> JT <?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?>
                &nbsp;·&nbsp; Sisa: <strong><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></strong>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'owner-mobile-footer.php'; ?>