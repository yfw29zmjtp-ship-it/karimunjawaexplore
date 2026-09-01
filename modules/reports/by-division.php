<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = getMasterBusinessId();
    
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion clause
$excludeOwnerCapital = '';
$ownerCapitalExcludeCondition = '';
if (!empty($ownerCapitalAccountIds)) {
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
    $ownerCapitalExcludeCondition = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Get Cash Account Balances from Master DB
$pettyCashBalance = 0;
$ownerCapitalBalance = 0;
try {
    // Get Petty Cash balance (Kas Besar - account_type = 'cash')
    $stmt = $masterDb->prepare("SELECT current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' AND is_default_account = 1");
    $stmt->execute([$businessId]);
    $pettyCashResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $pettyCashBalance = $pettyCashResult['current_balance'] ?? 0;
    
    // Get Owner Capital balance
    $stmt = $masterDb->prepare("SELECT current_balance FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $ownerCapitalBalance = $ownerCapitalResult['current_balance'] ?? 0;
} catch (Exception $e) {
    error_log("Error fetching cash account balances: " . $e->getMessage());
}

$pageTitle = 'Laporan Per Divisi';

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get company info for header
require_once '../../includes/report_helper.php';
$company = getCompanyInfo();

$params = ['start_date' => $start_date, 'end_date' => $end_date];

// Get all divisions with summary - Exclude owner capital from income AND expense
$divisionSummary = $db->fetchAll("
    SELECT 
        d.id,
        d.division_name,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as net_balance,
        COUNT(cb.id) as transaction_count
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_date BETWEEN :start_date AND :end_date
    GROUP BY d.id, d.division_name
    ORDER BY total_income DESC
", $params);

// Calculate grand totals
$grandIncome = 0;
$grandExpense = 0;
$grandNet = 0;
$grandTransactions = 0;

foreach ($divisionSummary as $div) {
    $grandIncome += $div['total_income'];
    $grandExpense += $div['total_expense'];
    $grandNet += $div['net_balance'];
    $grandTransactions += $div['transaction_count'];
}

include '../../includes/header.php';

// Convert logo path to browser-accessible URL
$displayLogo = $company['invoice_logo'] ?? $company['logo'];
$absoluteLogo = null;
if ($displayLogo) {
    if (strpos($displayLogo, 'http') === 0) {
        // Already a URL path
        $absoluteLogo = $displayLogo;
    } else {
        // Convert filename to URL path for browser display
        // Logo filenames are stored in DB, need to build full URL path
        $logoFilename = basename($displayLogo);
        $absoluteLogo = BASE_URL . '/uploads/logos/' . $logoFilename;
    }
}
?>

<!-- Print Content (Hidden from Screen, Visible in Print/PDF) -->
<div id="printContent" style="position: absolute; left: -9999px; top: -9999px; opacity: 0; width: 210mm; min-height: 297mm; margin: 0; padding: 8mm; background: white; color: #000;">
    <!-- Report Header -->
    <?php echo generateReportHeader('LAPORAN PER DIVISI', '', date('d M Y', strtotime($start_date)) . ' - ' . date('d M Y', strtotime($end_date)), $absoluteLogo); ?>
    
    <!-- Summary Cards for Print -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.3rem; margin-bottom: 0.6rem;">
        <div style="padding: 0.4rem 0.5rem; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
            <div style="font-size: 7px; color: #666; margin-bottom: 0.2rem;">Total Pemasukan</div>
            <div style="font-size: 13px; font-weight: bold; color: #10b981;">Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?></div>
        </div>
        <div style="padding: 0.4rem 0.5rem; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
            <div style="font-size: 7px; color: #666; margin-bottom: 0.2rem;">Total Pengeluaran</div>
            <div style="font-size: 13px; font-weight: bold; color: #ef4444;">Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?></div>
        </div>
        <div style="padding: 0.4rem 0.5rem; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
            <div style="font-size: 7px; color: #666; margin-bottom: 0.2rem;">Saldo Bersih</div>
            <div style="font-size: 13px; font-weight: bold; color: <?php echo $grandNet >= 0 ? '#10b981' : '#ef4444'; ?>;">Rp <?php echo number_format($grandNet, 0, ',', '.'); ?></div>
        </div>
        <div style="padding: 0.4rem 0.5rem; border: 1px solid #ddd; border-radius: 4px; text-align: center;">
            <div style="font-size: 7px; color: #666; margin-bottom: 0.2rem;">Total Divisi</div>
            <div style="font-size: 13px; font-weight: bold; color: #333;"><?php echo count($divisionSummary); ?></div>
        </div>
    </div>

    <!-- Division Summary Table for Print -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 0.6rem; font-size: 8px;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: left; font-weight: bold;">No</th>
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: left; font-weight: bold;">Nama Divisi</th>
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; font-weight: bold;">Pemasukan</th>
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; font-weight: bold;">Pengeluaran</th>
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; font-weight: bold;">Saldo Bersih</th>
                <th style="border: 1px solid #ddd; padding: 3px 4px; text-align: center; font-weight: bold;">Trx</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            foreach ($divisionSummary as $div): 
            ?>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: center;"><?php echo $no++; ?></td>
                    <td style="border: 1px solid #ddd; padding: 3px 4px;"><?php echo htmlspecialchars($div['division_name']); ?></td>
                    <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: #10b981; font-weight: 600;">Rp <?php echo number_format($div['total_income'], 0, ',', '.'); ?></td>
                    <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: #ef4444; font-weight: 600;">Rp <?php echo number_format($div['total_expense'], 0, ',', '.'); ?></td>
                    <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: <?php echo $div['net_balance'] >= 0 ? '#10b981' : '#ef4444'; ?>; font-weight: 700;">Rp <?php echo number_format($div['net_balance'], 0, ',', '.'); ?></td>
                    <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: center;"><?php echo $div['transaction_count']; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: #f5f5f5; font-weight: bold;">
                <td colspan="2" style="border: 1px solid #ddd; padding: 3px 4px;">TOTAL</td>
                <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: #10b981;">Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?></td>
                <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: #ef4444;">Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?></td>
                <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: right; color: <?php echo $grandNet >= 0 ? '#10b981' : '#ef4444'; ?>;">Rp <?php echo number_format($grandNet, 0, ',', '.'); ?></td>
                <td style="border: 1px solid #ddd; padding: 3px 4px; text-align: center;"><?php echo $grandTransactions; ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Signature Section -->
    <?php echo generateSignatureSection(); ?>
    
    <!-- Footer -->
    <?php echo generateReportFooter(); ?>
</div>

<!-- Main Content (Screen View)-->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tanggal Akhir</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
        </div>
        
        <button type="submit" class="btn btn-primary" style="height: 42px;">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Filter
        </button>
    </form>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--success); display: flex; align-items: center; justify-content: center;">
                <i data-feather="trending-up" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Pemasukan</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--success);">
                    Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--danger); display: flex; align-items: center; justify-content: center;">
                <i data-feather="trending-down" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Pengeluaran</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--danger);">
                    Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(99, 102, 241, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--primary-color); display: flex; align-items: center; justify-content: center;">
                <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Saldo Bersih</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    Rp <?php echo number_format($grandNet, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--warning); display: flex; align-items: center; justify-content: center;">
                <i data-feather="grid" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Divisi</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--text-primary);">
                    <?php echo count($divisionSummary); ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Saldo Petty Cash (Kas Besar) -->
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(6, 182, 212, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: #06b6d4; display: flex; align-items: center; justify-content: center;">
                <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: #0891b2; margin-bottom: 0.25rem;">💵 Petty Cash</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: #0891b2;">
                    Rp <?php echo number_format($pettyCashBalance, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Saldo Modal Owner -->
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: #10b981; display: flex; align-items: center; justify-content: center;">
                <i data-feather="trending-up" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: #059669; margin-bottom: 0.25rem;">🔥 Modal Owner</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: #059669;">
                    Rp <?php echo number_format($ownerCapitalBalance, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
            Rekap Per Divisi (<?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>)
        </h3>
        <div style="display: flex; gap: 0.5rem;">
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i data-feather="printer" style="width: 16px; height: 16px;"></i> Cetak
            </button>
            <button onclick="exportToPDF()" class="btn btn-primary btn-sm">
                <i data-feather="download" style="width: 16px; height: 16px;"></i> Export PDF
            </button>
        </div>
    </div>
    
    <?php if (empty($divisionSummary)): ?>
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <p>Tidak ada data divisi</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 5%;">No</th>
                        <th>Nama Divisi</th>
                        <th style="text-align: right;">Pemasukan</th>
                        <th style="text-align: right;">Pengeluaran</th>
                        <th style="text-align: right;">Saldo Bersih</th>
                        <th style="text-align: center; width: 12%;">Transaksi</th>
                        <th style="text-align: center; width: 10%;">Kontribusi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($divisionSummary as $div): 
                        $contribution = $grandIncome > 0 ? ($div['total_income'] / $grandIncome * 100) : 0;
                    ?>
                        <tr>
                            <td style="text-align: center; color: var(--text-muted);">
                                <?php echo $no++; ?>
                            </td>
                            <td style="font-weight: 600; color: var(--text-primary);">
                                <?php echo $div['division_name']; ?>
                            </td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;">
                                Rp <?php echo number_format($div['total_income'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; color: var(--danger); font-weight: 600;">
                                Rp <?php echo number_format($div['total_expense'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: <?php echo $div['net_balance'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                Rp <?php echo number_format($div['net_balance'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: center; color: var(--text-muted);">
                                <?php echo number_format($div['transaction_count'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: flex; align-items: center; gap: 0.5rem; justify-content: center;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-tertiary); border-radius: 3px; overflow: hidden; max-width: 60px;">
                                        <div style="width: <?php echo $contribution; ?>%; height: 100%; background: var(--success); transition: width 0.3s;"></div>
                                    </div>
                                    <span style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary); min-width: 45px;">
                                        <?php echo number_format($contribution, 1); ?>%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="border-top: 2px solid var(--bg-tertiary);">
                    <tr style="background: var(--bg-tertiary);">
                        <td colspan="2" style="font-weight: 700; color: var(--text-primary);">TOTAL</td>
                        <td style="text-align: right; color: var(--success); font-weight: 700;">
                            Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?>
                        </td>
                        <td style="text-align: right; color: var(--danger); font-weight: 700;">
                            Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?>
                        </td>
                        <td style="text-align: right; font-weight: 800; color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            Rp <?php echo number_format($grandNet, 0, ',', '.'); ?>
                        </td>
                        <td style="text-align: center; font-weight: 700;">
                            <?php echo number_format($grandTransactions, 0, ',', '.'); ?>
                        </td>
                        <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                            100%
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Division Details Cards -->
<div style="margin-top: 1.5rem;">
    <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1rem;">
        Detail Per Divisi
    </h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 1rem;">
        <?php foreach ($divisionSummary as $div): 
            if ($div['transaction_count'] == 0) continue;
            $profitMargin = $div['total_income'] > 0 ? (($div['net_balance'] / $div['total_income']) * 100) : 0;
            
            // Get transactions for this division
            $divTransactions = $db->fetchAll("
                SELECT 
                    cb.*,
                    c.category_name
                FROM cash_book cb
                LEFT JOIN categories c ON cb.category_id = c.id
                WHERE cb.division_id = :division_id 
                    AND cb.transaction_date BETWEEN :start_date AND :end_date
                ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
            ", [
                'division_id' => $div['id'],
                'start_date' => $start_date,
                'end_date' => $end_date
            ]);
        ?>
            <div class="card" style="padding: 1.25rem; border-left: 4px solid var(--primary-color);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h4 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                        <?php echo $div['division_name']; ?>
                    </h4>
                    <span style="background: var(--bg-tertiary); padding: 0.25rem 0.625rem; border-radius: var(--radius-md); font-size: 0.75rem; font-weight: 600; color: var(--text-muted);">
                        <?php echo $div['transaction_count']; ?> trx
                    </span>
                </div>
                
                <div style="display: grid; gap: 0.625rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.813rem; color: var(--text-muted);">Pemasukan</span>
                        <span style="font-weight: 600; color: var(--success);">
                            Rp <?php echo number_format($div['total_income'], 0, ',', '.'); ?>
                        </span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 0.813rem; color: var(--text-muted);">Pengeluaran</span>
                        <span style="font-weight: 600; color: var(--danger);">
                            Rp <?php echo number_format($div['total_expense'], 0, ',', '.'); ?>
                        </span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 0.625rem; border-top: 1px solid var(--bg-tertiary);">
                        <span style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);">Net Balance</span>
                        <span style="font-weight: 700; color: <?php echo $div['net_balance'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            Rp <?php echo number_format($div['net_balance'], 0, ',', '.'); ?>
                        </span>
                    </div>
                </div>
                
                <!-- Transaction Details -->
                <div style="max-height: 300px; overflow-y: auto;">
                    <h5 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.75rem;">
                        Rincian Transaksi:
                    </h5>
                    <?php if (empty($divTransactions)): ?>
                        <p style="text-align: center; color: var(--text-muted); font-size: 0.813rem; padding: 1rem 0;">
                            Tidak ada transaksi
                        </p>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 0.625rem;">
                            <?php foreach ($divTransactions as $trans): ?>
                                <div style="background: var(--bg-secondary); padding: 0.75rem; border-radius: var(--radius-md); border-left: 3px solid <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.375rem;">
                                        <div style="flex: 1;">
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.125rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($trans['transaction_date'] . ' ' . $trans['transaction_time'])); ?>
                                            </div>
                                            <div style="font-size: 0.813rem; font-weight: 600; color: var(--text-primary);">
                                                <?php echo $trans['category_name']; ?>
                                            </div>
                                        </div>
                                        <div style="text-align: right;">
                                            <span style="font-size: 0.625rem; background: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>; color: white; padding: 0.125rem 0.375rem; border-radius: 3px; display: inline-block; margin-bottom: 0.25rem;">
                                                <?php echo $trans['transaction_type'] === 'income' ? 'IN' : 'OUT'; ?>
                                            </span>
                                            <div style="font-size: 0.875rem; font-weight: 700; color: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                                <?php echo $trans['transaction_type'] === 'income' ? '+' : '-'; ?> Rp <?php echo number_format($trans['amount'], 0, ',', '.'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($trans['description'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.375rem; padding-top: 0.375rem; border-top: 1px solid var(--bg-tertiary);">
                                            <?php echo htmlspecialchars($trans['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
    @media print {
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        
        body {
            margin: 0;
            padding: 0;
            width: 210mm;
            height: 297mm;
            background: white !important;
        }
        
        main, .main-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100%;
        }
        
        #printContent {
            position: static !important;
            left: auto !important;
            top: auto !important;
            opacity: 1 !important;
            display: block !important;
        }
        
        /* Hide screen-only elements */
        .sidebar, .top-bar, .breadcrumb, .card, footer, nav, .form-control, .btn {
            display: none !important;
        }
        
        /* Print only the printContent */
        #printContent {
            display: block !important;
        }
        
        #printContent table {
            page-break-inside: avoid;
        }
        
        #printContent tr {
            page-break-inside: avoid;
        }
    }
</style>

<script>
    feather.replace();
    
    function exportToPDF() {
        const element = document.getElementById('printContent');
        if (!element) {
            alert('Konten laporan tidak ditemukan!');
            return;
        }
        showPDFPreview(element);
    }

    function showPDFPreview(element) {
        const previewModal = document.createElement('div');
        previewModal.id = 'pdfPreviewModal';
        previewModal.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7); display: flex; align-items: center;
            justify-content: center; z-index: 9999; padding: 20px;
        `;
        previewModal.innerHTML = `
            <div style="background: white; border-radius: 8px; max-width: 800px; width: 100%;
                max-height: 90vh; display: flex; flex-direction: column;">
                <div style="padding: 1.5rem; border-bottom: 1px solid #ddd; display: flex;
                    justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; color: #333;">Preview PDF</h3>
                    <button onclick="closePDFPreview()" style="border: none; background: none;
                        font-size: 24px; cursor: pointer; color: #666;">&times;</button>
                </div>
                <div id="previewContent" style="flex: 1; overflow-y: auto; padding: 1.5rem;
                    background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                    <div style="color: #999; font-size: 14px;">Generating preview...</div>
                </div>
                <div style="padding: 1.5rem; border-top: 1px solid #ddd; display: flex;
                    gap: 1rem; justify-content: flex-end;">
                    <button onclick="closePDFPreview()" style="padding: 0.75rem 1.5rem;
                        border: 1px solid #ddd; background: white; color: #666; border-radius: 4px;
                        cursor: pointer; font-weight: 500;">Cancel</button>
                    <button onclick="confirmPDFDownload()" style="padding: 0.75rem 1.5rem;
                        border: none; background: #dc3545; color: white; border-radius: 4px;
                        cursor: pointer; font-weight: 500;">Download PDF</button>
                </div>
            </div>
        `;
        document.body.appendChild(previewModal);
        renderPreview(element);
    }

    function renderPreview(element) {
        if (typeof html2canvas === 'undefined') {
            setTimeout(() => { renderPreview(element); }, 100);
            return;
        }
        
        const previewContent = document.getElementById('previewContent');
        if (previewContent) previewContent.innerHTML = '<div style="color: #999;">Rendering PDF...</div>';
        
        // Clone and make visible for rendering
        const clone = element.cloneNode(true);
        clone.style.position = 'fixed';
        clone.style.left = '0';
        clone.style.top = '0';
        clone.style.width = '210mm';
        clone.style.height = 'auto';
        clone.style.display = 'block';
        clone.style.opacity = '1';
        clone.style.zIndex = '-999';
        clone.style.visibility = 'hidden';
        
        document.body.appendChild(clone);
        
        // Wait for reflow
        setTimeout(() => {
            html2canvas(clone, {
                scale: 2,
                useCORS: true,
                allowTaint: true,
                backgroundColor: '#ffffff',
                logging: false,
                windowHeight: clone.scrollHeight,
                windowWidth: clone.scrollWidth,
                onclone: (clonedDoc) => {
                    // Ensure visibility in cloned doc
                    const content = clonedDoc.body.querySelector('#printContent') || clonedDoc.body.firstChild;
                    if (content) {
                        content.style.visibility = 'visible';
                        content.style.opacity = '1';
                    }
                }
            }).then(canvas => {
                document.body.removeChild(clone);
                
                const previewContent = document.getElementById('previewContent');
                if (!previewContent) return;
                
                previewContent.innerHTML = '';
                const img = document.createElement('img');
                img.src = canvas.toDataURL('image/jpeg', 0.95);
                img.style.cssText = 'max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;';
                previewContent.appendChild(img);
                
                window.pdfPreviewCanvas = canvas;
            }).catch(err => {
                document.body.removeChild(clone);
                
                const previewContent = document.getElementById('previewContent');
                if (previewContent) {
                    previewContent.innerHTML = '<div style="color: #d32f2f; font-weight: 500;">Error: ' + err.message + '</div>';
                }
            });
        }, 100);
    }

    function confirmPDFDownload() {
        if (!window.pdfPreviewCanvas) {
            alert('Preview tidak siap. Silakan coba lagi.');
            return;
        }
        
        // Check if jsPDF is available (it's in window.jsPDF from html2pdf bundle)
        if (typeof window.jsPDF === 'undefined' && typeof jsPDF === 'undefined') {
            // Fallback: wait for library and retry
            setTimeout(() => { confirmPDFDownload(); }, 500);
            return;
        }
        
        const filename = 'Laporan-PerDivisi-<?php echo date("Y-m-d"); ?>.pdf';
        
        try {
            const canvas = window.pdfPreviewCanvas;
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const PDF = window.jsPDF || jsPDF;
            const doc = new PDF({
                orientation: 'portrait',
                unit: 'mm',
                format: 'a4'
            });
            
            const imgWidth = 210;
            const pageHeight = 297;
            const imgHeight = (canvas.height * imgWidth) / canvas.width;
            
            let heightLeft = imgHeight;
            let position = 0;
            
            while (heightLeft >= 0) {
                doc.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                if (heightLeft > 0) {
                    doc.addPage();
                    position = heightLeft - imgHeight;
                }
            }
            
            doc.save(filename);
            closePDFPreview();
        } catch (e) {
            console.error('PDF save error:', e);
            alert('Gagal save PDF. Error: ' + e.message);
        }
    }

    function closePDFPreview() {
        const modal = document.getElementById('pdfPreviewModal');
        if (modal) {
            modal.remove();
        }
        window.pdfPreviewCanvas = null;
    }
</script>

<?php include '../../includes/footer.php'; ?>
