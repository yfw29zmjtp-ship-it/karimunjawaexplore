<?php
require_once 'config/config.php';
require_once 'includes/auth.php';

$auth = new Auth();
$pageTitle = '403 - Access Denied';
include 'includes/header.php';
?>

<div style="text-align: center; padding: 4rem 2rem;">
    <h1 style="font-size: 4rem; color: #ef4444; margin-bottom: 1rem;">403</h1>
    <h2 style="color: var(--text-primary); margin-bottom: 1rem;">Access Denied</h2>
    <p style="color: var(--text-secondary); margin-bottom: 2rem;">
        You do not have permission to access this resource.
    </p>
    
    <?php if ($auth->isLoggedIn()): ?>
        <p style="color: var(--text-secondary); margin-bottom: 2rem;">
            <?php
            $user = $auth->getCurrentUser();
            echo "Logged in as: <strong>" . htmlspecialchars($user['username']) . "</strong> (Role: " . htmlspecialchars($user['role']) . ")";
            ?>
        </p>
        <a href="<?php echo BASE_URL; ?>/index.php" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; display: inline-block;">
            Return to Dashboard
        </a>
        &nbsp;
        <a href="<?php echo BASE_URL; ?>/check-permissions-frontdesk.php" style="background: linear-gradient(135deg, #3b82f6, #60a5fa); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; display: inline-block;">
            Check Permissions
        </a>
    <?php else: ?>
        <a href="<?php echo BASE_URL; ?>/login.php" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; display: inline-block;">
            Login
        </a>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
