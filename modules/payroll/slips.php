<?php
// modules/payroll/slips.php - MODERN 2027 ENGLISH VERSION
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Salary Details';

$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');

$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get Period
$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
$slips = [];

if ($period) {
    $slips = $db->fetchAll("SELECT * FROM payroll_slips WHERE period_id = ? ORDER BY employee_name ASC", [$period['id']]);
}

include '../../includes/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════════════════
   SALARY DETAILS 2027 - LUXE DESIGN
   ══════════════════════════════════════════════════════════════════════════ */
:root {
    --sl-gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --sl-gradient-2: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --sl-radius: 16px;
    --sl-radius-sm: 10px;
}

.sl-page-wrapper {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0;
}

/* Header Hero */
.sl-header {
    background: var(--sl-gradient-1);
    border-radius: var(--sl-radius);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}

.sl-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 250px;
    height: 250px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.sl-header h1 {
    color: #fff;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
    position: relative;
    z-index: 2;
}

.sl-header p {
    color: rgba(255,255,255,0.8);
    margin: 0.15rem 0 0;
    font-size: 0.85rem;
}

.sl-filter {
    display: flex;
    gap: 0.5rem;
    position: relative;
    z-index: 2;
}

.sl-filter select {
    padding: 0.5rem 0.75rem;
    border: none;
    border-radius: 8px;
    background: rgba(255,255,255,0.2);
    color: #fff;
    font-size: 0.85rem;
    cursor: pointer;
    backdrop-filter: blur(10px);
}

.sl-filter select option { color: #333; }

/* Summary Stats */
.sl-summary {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}

.sl-summary-item {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--sl-radius-sm);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    flex: 0 0 auto;
}

.sl-summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.sl-summary-icon.purple { background: linear-gradient(135deg, rgba(102,126,234,0.15), rgba(118,75,162,0.15)); color: #667eea; }
.sl-summary-icon.green { background: linear-gradient(135deg, rgba(17,153,142,0.15), rgba(56,239,125,0.15)); color: #11998e; }
.sl-summary-icon.blue { background: linear-gradient(135deg, rgba(59,130,246,0.15), rgba(147,197,253,0.15)); color: #3b82f6; }

.sl-summary-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    margin-bottom: 0.1rem;
}

.sl-summary-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

/* Table Card */
.sl-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--sl-radius);
    overflow: hidden;
}

.sl-card-header {
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.sl-card-header h3 {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0;
}

.sl-status {
    padding: 0.3rem 0.75rem;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.sl-status.draft { background: rgba(156,163,175,0.15); color: #6b7280; }
.sl-status.submitted { background: rgba(59,130,246,0.15); color: #3b82f6; }
.sl-status.approved { background: rgba(34,197,94,0.15); color: #22c55e; }
.sl-status.paid { background: rgba(139,92,246,0.15); color: #8b5cf6; }

/* Elegant Table */
.sl-table {
    width: 100%;
    border-collapse: collapse;
}

.sl-table th {
    padding: 0.6rem 1rem;
    text-align: left;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-tertiary);
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
}

.sl-table td {
    padding: 0.7rem 1rem;
    border-bottom: 1px solid var(--border-light);
    vertical-align: middle;
    font-size: 0.85rem;
}

.sl-table tr:hover td { background: var(--bg-secondary); }
.sl-table tr:last-child td { border-bottom: none; }

.sl-employee-info {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

.sl-avatar {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: var(--sl-gradient-1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 600;
    font-size: 0.75rem;
    flex-shrink: 0;
}

.sl-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.85rem;
}

.sl-position {
    font-size: 0.72rem;
    color: var(--text-tertiary);
}

.sl-amount {
    font-family: 'SF Mono', 'Monaco', monospace;
    font-size: 0.82rem;
}

.sl-amount.positive { color: #22c55e; }
.sl-amount.negative { color: #ef4444; }
.sl-amount.highlight {
    font-weight: 700;
    background: var(--sl-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.sl-btn-print {
    padding: 0.35rem 0.65rem;
    border: 1px solid var(--primary-color);
    border-radius: 6px;
    background: transparent;
    color: var(--primary-color);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: all 0.2s;
    text-decoration: none;
}

.sl-btn-print:hover {
    background: var(--primary-color);
    border-color: var(--primary-color);
    color: #fff;
}

/* Empty State */
.sl-empty {
    text-align: center;
    padding: 2.5rem 1.5rem;
}

.sl-empty-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 0.75rem;
    border-radius: 50%;
    background: var(--bg-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-tertiary);
}

/* Table Footer */
.sl-table-footer {
    background: var(--bg-secondary);
    padding: 0.85rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
}

.sl-total-label {
    font-weight: 600;
    color: var(--text-secondary);
}

.sl-total-amount {
    font-size: 1.1rem;
    font-weight: 700;
    background: var(--sl-gradient-2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

@media (max-width: 768px) {
    .sl-header { flex-direction: column; align-items: stretch; text-align: center; }
    .sl-filter { justify-content: center; }
    .sl-summary { flex-direction: column; }
    .sl-table { font-size: 0.8rem; }
}
</style>

<div class="sl-page-wrapper">
    
    <!-- Header -->
    <div class="sl-header fade-in-up">
        <div>
            <h1>Salary Details</h1>
            <p>View and print employee salary slips</p>
        </div>
        <form method="GET" class="sl-filter">
            <select name="month" onchange="this.form.submit()">
                <?php foreach($months as $k => $v): ?>
                    <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" onchange="this.form.submit()">
                <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <?php if (!$period): ?>
    <div class="sl-card fade-in-up">
        <div class="sl-empty">
            <div class="sl-empty-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            </div>
            <h4 style="margin: 0 0 0.5rem; color: var(--text-secondary);">No Payroll Period</h4>
            <p style="margin: 0; color: var(--text-tertiary);">Go to <a href="process.php">Process Salary</a> to create a period first.</p>
        </div>
    </div>
    <?php elseif (empty($slips)): ?>
    <div class="sl-card fade-in-up">
        <div class="sl-empty">
            <div class="sl-empty-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
            </div>
            <h4 style="margin: 0 0 0.5rem; color: var(--text-secondary);">No Salary Slips</h4>
            <p style="margin: 0; color: var(--text-tertiary);">No employee salary data for this period.</p>
        </div>
    </div>
    <?php else: ?>

    <!-- Summary Stats -->
    <div class="sl-summary fade-in-up" style="animation-delay: 0.1s">
        <div class="sl-summary-item">
            <div class="sl-summary-icon purple">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            </div>
            <div>
                <p class="sl-summary-label">Total Employees</p>
                <h3 class="sl-summary-value"><?php echo count($slips); ?></h3>
            </div>
        </div>
        <div class="sl-summary-item">
            <div class="sl-summary-icon blue">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            </div>
            <div>
                <p class="sl-summary-label">Total Gross</p>
                <h3 class="sl-summary-value">Rp <?php echo number_format($period['total_gross'], 0, ',', '.'); ?></h3>
            </div>
        </div>
        <div class="sl-summary-item">
            <div class="sl-summary-icon green">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
            <div>
                <p class="sl-summary-label">Total Net Pay</p>
                <h3 class="sl-summary-value">Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?></h3>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="sl-card fade-in-up" style="animation-delay: 0.2s">
        <div class="sl-card-header">
            <h3><?php echo $months[$month] . ' ' . $year; ?> Salary Slips</h3>
            <span class="sl-status <?php echo $period['status']; ?>"><?php echo ucfirst($period['status']); ?></span>
        </div>
        
        <div style="overflow-x: auto;">
        <table class="sl-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th style="text-align: right;">Base Salary</th>
                    <th style="text-align: right;">Overtime</th>
                    <th style="text-align: right;">Allowances</th>
                    <th style="text-align: right;">Deductions</th>
                    <th style="text-align: right;">Net Pay</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($slips as $slip): 
                    $initials = strtoupper(substr($slip['employee_name'], 0, 1)) . strtoupper(substr(strrchr($slip['employee_name'], ' ') ?: $slip['employee_name'], 1, 1));
                    $allowances = $slip['incentive'] + $slip['allowance'] + ($slip['uang_makan'] ?? 0) + $slip['bonus'] + ($slip['other_income'] ?? 0);
                ?>
                <tr>
                    <td>
                        <div class="sl-employee-info">
                            <div class="sl-avatar"><?php echo $initials; ?></div>
                            <div>
                                <div class="sl-name"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                                <div class="sl-position"><?php echo htmlspecialchars($slip['position']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span class="sl-amount">Rp <?php echo number_format($slip['base_salary'], 0, ',', '.'); ?></span>
                    </td>
                    <td style="text-align: right;">
                        <span class="sl-amount positive">Rp <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?></span>
                        <div style="font-size: 0.68rem; color: var(--text-tertiary);">(<?php echo $slip['overtime_hours']; ?> hrs)</div>
                    </td>
                    <td style="text-align: right;">
                        <span class="sl-amount positive">Rp <?php echo number_format($allowances, 0, ',', '.'); ?></span>
                        <div style="font-size: 0.68rem; color: var(--text-tertiary);">
                            Incentive: Rp <?php echo number_format($slip['incentive'], 0, ',', '.'); ?><br>
                            Allowance: Rp <?php echo number_format($slip['allowance'], 0, ',', '.'); ?><br>
                            <strong>Uang Makan: Rp <?php echo number_format($slip['uang_makan'] ?? 0, 0, ',', '.'); ?></strong><br>
                            Bonus: Rp <?php echo number_format($slip['bonus'], 0, ',', '.'); ?><br>
                            Other: Rp <?php echo number_format($slip['other_income'] ?? 0, 0, ',', '.'); ?>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <span class="sl-amount negative">-Rp <?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?></span>
                    </td>
                    <td style="text-align: right;">
                        <span class="sl-amount highlight">Rp <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?></span>
                    </td>
                    <td style="text-align: center;">
                        <a href="print-slip.php?id=<?php echo $slip['id']; ?>" target="_blank" class="sl-btn-print">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                            Print
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        
        <div class="sl-table-footer">
            <span class="sl-total-label">Total Net Payroll:</span>
            <span class="sl-total-amount">Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?></span>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
