<?php
/**
 * Developer Mode Indicator
 * Shows banner when logged in via developer access
 */

if (isset($_SESSION['developer_mode']) && $_SESSION['developer_mode'] === true): ?>
<div class="alert alert-warning alert-dismissible fade show m-3" role="alert" style="border-left: 4px solid #6f42c1;">
    <i class="bi bi-code-square me-2"></i>
    <strong>Developer Mode Active</strong> - You are logged in as <code><?php echo htmlspecialchars($_SESSION['username'] ?? 'unknown'); ?></code> 
    in <strong><?php echo htmlspecialchars($_SESSION['business_name'] ?? 'Unknown Business'); ?></strong>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
