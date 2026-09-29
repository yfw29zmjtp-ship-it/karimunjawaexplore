</div>

<?php
$obCurrentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$obNavItems = [
    ['file' => 'owner-dashboard.php', 'icon' => 'home', 'label' => 'Dashboard'],
    ['file' => 'owner-bookings.php', 'icon' => 'briefcase', 'label' => 'Reservasi'],
    ['file' => 'owner-calendar.php', 'icon' => 'calendar', 'label' => 'Kalender'],
    ['file' => 'owner-invoices.php', 'icon' => 'credit-card', 'label' => 'Invoice'],
    ['file' => 'owner-finance.php', 'icon' => 'dollar-sign', 'label' => 'Finance'],
];
$obActiveMap = [
    'owner-booking-add.php' => 'owner-bookings.php',
    'owner-booking-detail.php' => 'owner-bookings.php',
    'owner-invoice-add.php' => 'owner-invoices.php',
    'owner-invoice-detail.php' => 'owner-invoices.php',
    'owner-finance-add.php' => 'owner-finance.php',
];
$obActivePage = $obActiveMap[$obCurrentPage] ?? $obCurrentPage;
?>
<div class="ob-bottom-nav">
    <?php foreach ($obNavItems as $obNavItem): ?>
        <a href="<?php echo htmlspecialchars($obNavItem['file']); ?>" class="ob-navbtn<?php echo $obActivePage === $obNavItem['file'] ? ' ob-navbtn-active' : ''; ?>">
            <i data-feather="<?php echo htmlspecialchars($obNavItem['icon']); ?>"></i> <?php echo htmlspecialchars($obNavItem['label']); ?>
        </a>
    <?php endforeach; ?>
</div>

<script>
    if (window.feather) feather.replace();
</script>
</body>

</html>