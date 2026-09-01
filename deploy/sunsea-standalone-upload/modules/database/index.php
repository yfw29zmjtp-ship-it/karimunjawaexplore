<?php
/**
 * Database Module - Main Dashboard
 * CQC System - Manage Suppliers, Customers, and Staff
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Database Master';

// Get counts
try {
    $supplierCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM suppliers WHERE is_active = 1")['cnt'] ?? 0;
} catch (Exception $e) {
    $supplierCount = 0;
}

try {
    $customerCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM customers WHERE is_active = 1")['cnt'] ?? 0;
} catch (Exception $e) {
    $customerCount = 0;
}

try {
    $staffCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM staff WHERE is_active = 1")['cnt'] ?? 0;
} catch (Exception $e) {
    $staffCount = 0;
}

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.5rem;">
    <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
        🗃️ Database Master
    </h2>
    <p style="color: var(--text-muted); font-size: 0.875rem;">
        Kelola data Supplier, Customer, dan Staf untuk keperluan Invoice dan Project
    </p>
</div>

<!-- Database Cards -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem;">
    
    <!-- Suppliers Card -->
    <a href="suppliers.php" class="card" style="text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;">
        <div style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #818cf8); display: flex; align-items: center; justify-content: center;">
                    <i data-feather="truck" style="width: 28px; height: 28px; color: white;"></i>
                </div>
                <div>
                    <div style="font-size: 2rem; font-weight: 700; color: var(--text-primary);"><?php echo $supplierCount; ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Total Aktif</div>
                </div>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                📦 Database Supplier
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 1rem;">
                Data vendor & supplier untuk pembelian material dan jasa
            </p>
            <div style="display: flex; align-items: center; color: #6366f1; font-size: 0.875rem; font-weight: 500;">
                Kelola Supplier
                <i data-feather="arrow-right" style="width: 16px; height: 16px; margin-left: 0.5rem;"></i>
            </div>
        </div>
    </a>
    
    <!-- Customers Card -->
    <a href="customers.php" class="card" style="text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;">
        <div style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, #10b981, #34d399); display: flex; align-items: center; justify-content: center;">
                    <i data-feather="users" style="width: 28px; height: 28px; color: white;"></i>
                </div>
                <div>
                    <div style="font-size: 2rem; font-weight: 700; color: var(--text-primary);"><?php echo $customerCount; ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Total Aktif</div>
                </div>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                👥 Database Customer
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 1rem;">
                Data pelanggan untuk pembuatan invoice dan project
            </p>
            <div style="display: flex; align-items: center; color: #10b981; font-size: 0.875rem; font-weight: 500;">
                Kelola Customer
                <i data-feather="arrow-right" style="width: 16px; height: 16px; margin-left: 0.5rem;"></i>
            </div>
        </div>
    </a>
    
    <!-- Staff Card -->
    <a href="staff.php" class="card" style="text-decoration: none; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;">
        <div style="padding: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                <div style="width: 60px; height: 60px; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #fbbf24); display: flex; align-items: center; justify-content: center;">
                    <i data-feather="user-check" style="width: 28px; height: 28px; color: white;"></i>
                </div>
                <div>
                    <div style="font-size: 2rem; font-weight: 700; color: var(--text-primary);"><?php echo $staffCount; ?></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Total Aktif</div>
                </div>
            </div>
            <h3 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                👷 Database Staf
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 1rem;">
                Data staf/karyawan untuk assignment project
            </p>
            <div style="display: flex; align-items: center; color: #f59e0b; font-size: 0.875rem; font-weight: 500;">
                Kelola Staf
                <i data-feather="arrow-right" style="width: 16px; height: 16px; margin-left: 0.5rem;"></i>
            </div>
        </div>
    </a>
    
</div>

<style>
.card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.15);
}
</style>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
