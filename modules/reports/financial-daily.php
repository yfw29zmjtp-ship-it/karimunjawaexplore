<?php
/**
 * Laporan Keuangan Harian - Financial Daily Report
 * Comprehensive income and expense report by division with PDF print support
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

// Check authentication
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Laporan Keuangan Harian';

// Get filter parameters
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

// Get company info for header
$company = getCompanyInfo();

// Convert logo path to browser-accessible URL
$displayLogo = $company['invoice_logo'] ?? $company['logo'];
$absoluteLogo = null;
if ($displayLogo) {
    if (strpos($displayLogo, 'http') === 0) {
        $absoluteLogo = $displayLogo;
    } else {
        $absoluteLogo = BASE_URL . '/uploads/' . basename($displayLogo);
    }
}

// ============================================
// QUERY: Income by Division with Category Details
// ============================================
$incomeByDivision = $db->fetchAll("
    SELECT 
        d.id as division_id,
        d.division_name,
        d.division_code,
        c.category_name,
        cb.description,
        cb.amount,
        cb.transaction_time,
        cb.id as transaction_id
    FROM cash_book cb
    INNER JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    WHERE cb.transaction_date = :report_date
        AND cb.transaction_type = 'income'
    ORDER BY d.division_name, cb.transaction_time
", ['report_date' => $report_date]);

// ============================================
// QUERY: Expense by Division with Category Details
// ============================================
$expenseByDivision = $db->fetchAll("
    SELECT 
        d.id as division_id,
        d.division_name,
        d.division_code,
        c.category_name,
        cb.description,
        cb.amount,
        cb.transaction_time,
        cb.id as transaction_id
    FROM cash_book cb
    INNER JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    WHERE cb.transaction_date = :report_date
        AND cb.transaction_type = 'expense'
    ORDER BY d.division_name, cb.transaction_time
", ['report_date' => $report_date]);

// ============================================
// QUERY: Purchase Details (from procurement module if exists)
// ============================================
$purchaseDetails = [];
try {
    $purchaseDetails = $db->fetchAll("
        SELECT 
            pd.item_name,
            pd.quantity,
            pd.unit_price,
            pd.subtotal,
            d.division_name,
            ph.invoice_number,
            ph.invoice_date,
            s.supplier_name
        FROM purchases_detail pd
        INNER JOIN purchases_header ph ON pd.invoice_number = ph.invoice_number
        INNER JOIN divisions d ON pd.division_id = d.id
        LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
        WHERE ph.invoice_date = :report_date
        ORDER BY d.division_name, pd.item_name
    ", ['report_date' => $report_date]);
} catch (Exception $e) {
    // Procurement tables might not exist
}

// ============================================
// GROUP DATA BY DIVISION
// ============================================

// Group income by division
$incomeGrouped = [];
$totalIncome = 0;
foreach ($incomeByDivision as $income) {
    $divId = $income['division_id'];
    if (!isset($incomeGrouped[$divId])) {
        $incomeGrouped[$divId] = [
            'division_name' => $income['division_name'],
            'division_code' => $income['division_code'],
            'total' => 0,
            'transactions' => []
        ];
    }
    $incomeGrouped[$divId]['transactions'][] = $income;
    $incomeGrouped[$divId]['total'] += $income['amount'];
    $totalIncome += $income['amount'];
}

// Group expense by division
$expenseGrouped = [];
$totalExpense = 0;
foreach ($expenseByDivision as $expense) {
    $divId = $expense['division_id'];
    if (!isset($expenseGrouped[$divId])) {
        $expenseGrouped[$divId] = [
            'division_name' => $expense['division_name'],
            'division_code' => $expense['division_code'],
            'total' => 0,
            'transactions' => []
        ];
    }
    $expenseGrouped[$divId]['transactions'][] = $expense;
    $expenseGrouped[$divId]['total'] += $expense['amount'];
    $totalExpense += $expense['amount'];
}

// Group purchases by division
$purchaseGrouped = [];
$totalPurchases = 0;
foreach ($purchaseDetails as $purchase) {
    $divName = $purchase['division_name'];
    if (!isset($purchaseGrouped[$divName])) {
        $purchaseGrouped[$divName] = [
            'items' => [],
            'total' => 0
        ];
    }
    $purchaseGrouped[$divName]['items'][] = $purchase;
    $purchaseGrouped[$divName]['total'] += $purchase['subtotal'];
    $totalPurchases += $purchase['subtotal'];
}

$netBalance = $totalIncome - $totalExpense;

include '../../includes/header.php';
?>

<style>
@media print {
    body { 
        margin: 0;
        padding: 0;
        background: white !important;
    }
    .no-print { display: none !important; }
    #printContent { 
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .page-header,
    .sidebar,
    .breadcrumb,
    .filter-card,
    nav { display: none !important; }
}

@media screen {
    #printContent { display: none; }
}

.division-section {
    margin-bottom: 1.5rem;
    page-break-inside: avoid;
}

.transaction-item {
    padding: 0.75rem;
    background: var(--bg-secondary);
    border-radius: var(--radius-md);
    margin-bottom: 0.5rem;
    border-left: 3px solid var(--primary-color);
}

.section-header {
    background: linear-gradient(135deg, var(--primary-color), rgba(99, 102, 241, 0.8));
    color: white;
    padding: 1rem;
    border-radius: var(--radius-lg);
    margin-bottom: 1rem;
    font-weight: 700;
}
</style>

<!-- Filter Section (Screen Only) -->
<div class="card filter-card no-print" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: flex; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0; flex: 1;">
            <label class="form-label">Tanggal Laporan</label>
            <input type="date" name="report_date" class="form-control" value="<?php echo $report_date; ?>" required>
        </div>
        <button type="submit" class="btn btn-primary">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Lihat Laporan
        </button>
        <button type="button" class="btn btn-success" onclick="window.print()">
            <i data-feather="printer" style="width: 16px; height: 16px;"></i> Cetak PDF
        </button>
    </form>
</div>

<!-- Screen View Summary Cards -->
<div class="no-print" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
        <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">💰 Total Pemasukan</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: var(--success);">
            <?php echo formatCurrency($totalIncome); ?>
        </div>
    </div>
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));">
        <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">💸 Total Pengeluaran</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: var(--danger);">
            <?php echo formatCurrency($totalExpense); ?>
        </div>
    </div>
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));">
        <div style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">📊 Saldo Bersih</div>
        <div style="font-size: 1.75rem; font-weight: 800; color: <?php echo $netBalance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
            <?php echo formatCurrency($netBalance); ?>
        </div>
    </div>
</div>

<!-- Screen View: Income by Division -->
<div class="card no-print" style="margin-bottom: 1.5rem;">
    <div class="section-header">
        <i data-feather="trending-up" style="width: 20px; height: 20px; vertical-align: middle;"></i>
        INCOME PER DIVISI
    </div>
    
    <?php if (empty($incomeGrouped)): ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <p>Tidak ada data pemasukan untuk tanggal ini</p>
        </div>
    <?php else: ?>
        <?php foreach ($incomeGrouped as $divId => $divData): ?>
            <div class="division-section">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--bg-tertiary); border-radius: var(--radius-md); margin-bottom: 0.75rem;">
                    <h3 style="margin: 0; font-size: 1.125rem; color: var(--text-primary);">
                        <?php echo $divData['division_name']; ?>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">
                            (<?php echo $divData['division_code']; ?>)
                        </span>
                    </h3>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--success);">
                        <?php echo formatCurrency($divData['total']); ?>
                    </div>
                </div>
                
                <?php foreach ($divData['transactions'] as $trans): ?>
                    <div class="transaction-item" style="border-left-color: var(--success);">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                                    <?php echo $trans['category_name'] ?? 'Tanpa Kategori'; ?>
                                </div>
                                <div style="font-size: 0.813rem; color: var(--text-muted);">
                                    <?php echo $trans['description'] ? htmlspecialchars($trans['description']) : '-'; ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    <i data-feather="clock" style="width: 12px; height: 12px;"></i>
                                    <?php echo $trans['transaction_time']; ?>
                                </div>
                            </div>
                            <div style="font-size: 1.125rem; font-weight: 700; color: var(--success); white-space: nowrap; margin-left: 1rem;">
                                <?php echo formatCurrency($trans['amount']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Screen View: Expenses by Division -->
<div class="card no-print" style="margin-bottom: 1.5rem;">
    <div class="section-header" style="background: linear-gradient(135deg, #ef4444, rgba(239, 68, 68, 0.8));">
        <i data-feather="trending-down" style="width: 20px; height: 20px; vertical-align: middle;"></i>
        EXPENSES PER DIVISI
    </div>
    
    <?php if (empty($expenseGrouped)): ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <p>Tidak ada data pengeluaran untuk tanggal ini</p>
        </div>
    <?php else: ?>
        <?php foreach ($expenseGrouped as $divId => $divData): ?>
            <div class="division-section">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--bg-tertiary); border-radius: var(--radius-md); margin-bottom: 0.75rem;">
                    <h3 style="margin: 0; font-size: 1.125rem; color: var(--text-primary);">
                        <?php echo $divData['division_name']; ?>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">
                            (<?php echo $divData['division_code']; ?>)
                        </span>
                    </h3>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--danger);">
                        <?php echo formatCurrency($divData['total']); ?>
                    </div>
                </div>
                
                <?php foreach ($divData['transactions'] as $trans): ?>
                    <div class="transaction-item" style="border-left-color: var(--danger);">
                        <div style="display: flex; justify-content: space-between; align-items: start;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">
                                    <?php echo $trans['category_name'] ?? 'Tanpa Kategori'; ?>
                                </div>
                                <div style="font-size: 0.813rem; color: var(--text-muted);">
                                    <?php echo $trans['description'] ? htmlspecialchars($trans['description']) : '-'; ?>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    <i data-feather="clock" style="width: 12px; height: 12px;"></i>
                                    <?php echo $trans['transaction_time']; ?>
                                </div>
                            </div>
                            <div style="font-size: 1.125rem; font-weight: 700; color: var(--danger); white-space: nowrap; margin-left: 1rem;">
                                <?php echo formatCurrency($trans['amount']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Purchases Detail (if exists) -->
<?php if (!empty($purchaseGrouped)): ?>
<div class="card no-print" style="margin-bottom: 1.5rem;">
    <div class="section-header" style="background: linear-gradient(135deg, #f59e0b, rgba(245, 158, 11, 0.8));">
        <i data-feather="shopping-cart" style="width: 20px; height: 20px; vertical-align: middle;"></i>
        DETAIL PEMBELIAN
    </div>
    
    <?php foreach ($purchaseGrouped as $divName => $purchaseData): ?>
        <div class="division-section">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--bg-tertiary); border-radius: var(--radius-md); margin-bottom: 0.75rem;">
                <h3 style="margin: 0; font-size: 1.125rem; color: var(--text-primary);">
                    <?php echo $divName; ?>
                </h3>
                <div style="font-size: 1.25rem; font-weight: 700; color: #f59e0b;">
                    <?php echo formatCurrency($purchaseData['total']); ?>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: var(--bg-tertiary); font-size: 0.875rem;">
                        <th style="padding: 0.5rem; text-align: left;">Item</th>
                        <th style="padding: 0.5rem; text-align: center; width: 100px;">Qty</th>
                        <th style="padding: 0.5rem; text-align: right; width: 120px;">Harga</th>
                        <th style="padding: 0.5rem; text-align: right; width: 140px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($purchaseData['items'] as $item): ?>
                        <tr style="border-bottom: 1px solid var(--bg-tertiary);">
                            <td style="padding: 0.5rem;">
                                <div style="font-weight: 600;"><?php echo $item['item_name']; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                    <?php echo $item['supplier_name']; ?> - <?php echo $item['invoice_number']; ?>
                                </div>
                            </td>
                            <td style="padding: 0.5rem; text-align: center;"><?php echo $item['quantity']; ?></td>
                            <td style="padding: 0.5rem; text-align: right;"><?php echo formatCurrency($item['unit_price']); ?></td>
                            <td style="padding: 0.5rem; text-align: right; font-weight: 600;"><?php echo formatCurrency($item['subtotal']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ============================================ -->
<!-- PRINT VIEW - PDF VERSION -->
<!-- ============================================ -->
<div id="printContent">
    <div style="width: 210mm; min-height: 297mm; margin: 0 auto; padding: 10mm; background: white;">
        <?php 
        $dateText = date('d F Y', strtotime($report_date));
        echo generateReportHeader('LAPORAN KEUANGAN HARIAN', 'Detailed Daily Financial Report', $dateText, $absoluteLogo); 
        ?>
        
        <!-- Summary Section for Print -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 1rem;">
            <div style="padding: 0.6rem; border: 1px solid #10b981; border-radius: 6px; text-align: center; background: rgba(16, 185, 129, 0.05);">
                <div style="font-size: 8px; color: #666; margin-bottom: 0.2rem;">Total Pemasukan</div>
                <div style="font-size: 14px; font-weight: bold; color: #10b981;">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
            </div>
            <div style="padding: 0.6rem; border: 1px solid #ef4444; border-radius: 6px; text-align: center; background: rgba(239, 68, 68, 0.05);">
                <div style="font-size: 8px; color: #666; margin-bottom: 0.2rem;">Total Pengeluaran</div>
                <div style="font-size: 14px; font-weight: bold; color: #ef4444;">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
            </div>
            <div style="padding: 0.6rem; border: 1px solid <?php echo $netBalance >= 0 ? '#3b82f6' : '#f59e0b'; ?>; border-radius: 6px; text-align: center; background: rgba(59, 130, 246, 0.05);">
                <div style="font-size: 8px; color: #666; margin-bottom: 0.2rem;">Saldo Bersih</div>
                <div style="font-size: 14px; font-weight: bold; color: <?php echo $netBalance >= 0 ? '#3b82f6' : '#f59e0b'; ?>;">Rp <?php echo number_format($netBalance, 0, ',', '.'); ?></div>
            </div>
        </div>
        
        <!-- DETAIL RINGKASAN TRANSAKSI HARIAN -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; margin-bottom: 1rem; page-break-inside: avoid;">
            
            <!-- Detail Pendapatan -->
            <div style="border: 2px solid #10b981; border-radius: 6px; padding: 0.5rem; background: #f0fdf4;">
                <div style="background: #10b981; color: white; padding: 0.3rem 0.5rem; margin: -0.5rem -0.5rem 0.4rem -0.5rem; border-radius: 4px 4px 0 0;">
                    <h4 style="margin: 0; font-size: 9px; font-weight: 700;">📊 DETAIL PENDAPATAN</h4>
                </div>
                
                <?php if (!empty($incomeByDivision)): ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 7px;">
                        <thead>
                            <tr style="background: rgba(16, 185, 129, 0.1);">
                                <th style="padding: 2px 3px; text-align: left; border-bottom: 1px solid #10b981;">Divisi</th>
                                <th style="padding: 2px 3px; text-align: left; border-bottom: 1px solid #10b981;">Kategori</th>
                                <th style="padding: 2px 3px; text-align: right; border-bottom: 1px solid #10b981; width: 60px;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeByDivision as $income): ?>
                                <tr>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #dcfce7; font-size: 6.5px;">
                                        <?php echo substr($income['division_name'], 0, 12); ?>
                                    </td>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #dcfce7; font-size: 6.5px;">
                                        <?php echo substr($income['category_name'] ?? '-', 0, 15); ?>
                                    </td>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #dcfce7; text-align: right; font-weight: 600; color: #10b981;">
                                        <?php echo number_format($income['amount'], 0, ',', '.'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: rgba(16, 185, 129, 0.15); font-weight: bold;">
                                <td colspan="2" style="padding: 3px; text-align: right; border-top: 2px solid #10b981;">TOTAL:</td>
                                <td style="padding: 3px; text-align: right; color: #10b981; border-top: 2px solid #10b981;">
                                    <?php echo number_format($totalIncome, 0, ',', '.'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 1rem; color: #999; font-size: 7px;">Tidak ada pendapatan</div>
                <?php endif; ?>
            </div>
            
            <!-- Detail Pengeluaran -->
            <div style="border: 2px solid #ef4444; border-radius: 6px; padding: 0.5rem; background: #fef2f2;">
                <div style="background: #ef4444; color: white; padding: 0.3rem 0.5rem; margin: -0.5rem -0.5rem 0.4rem -0.5rem; border-radius: 4px 4px 0 0;">
                    <h4 style="margin: 0; font-size: 9px; font-weight: 700;">💸 DETAIL PENGELUARAN</h4>
                </div>
                
                <?php if (!empty($expenseByDivision)): ?>
                    <table style="width: 100%; border-collapse: collapse; font-size: 7px;">
                        <thead>
                            <tr style="background: rgba(239, 68, 68, 0.1);">
                                <th style="padding: 2px 3px; text-align: left; border-bottom: 1px solid #ef4444;">Divisi</th>
                                <th style="padding: 2px 3px; text-align: left; border-bottom: 1px solid #ef4444;">Kategori</th>
                                <th style="padding: 2px 3px; text-align: right; border-bottom: 1px solid #ef4444; width: 60px;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseByDivision as $expense): ?>
                                <tr>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #fee2e2; font-size: 6.5px;">
                                        <?php echo substr($expense['division_name'], 0, 12); ?>
                                    </td>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #fee2e2; font-size: 6.5px;">
                                        <?php echo substr($expense['category_name'] ?? '-', 0, 15); ?>
                                    </td>
                                    <td style="padding: 2px 3px; border-bottom: 1px solid #fee2e2; text-align: right; font-weight: 600; color: #ef4444;">
                                        <?php echo number_format($expense['amount'], 0, ',', '.'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: rgba(239, 68, 68, 0.15); font-weight: bold;">
                                <td colspan="2" style="padding: 3px; text-align: right; border-top: 2px solid #ef4444;">TOTAL:</td>
                                <td style="padding: 3px; text-align: right; color: #ef4444; border-top: 2px solid #ef4444;">
                                    <?php echo number_format($totalExpense, 0, ',', '.'); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 1rem; color: #999; font-size: 7px;">Tidak ada pengeluaran</div>
                <?php endif; ?>
            </div>
            
        </div>
        
        <!-- Income Section for Print -->
        <div style="margin-bottom: 1rem; page-break-inside: avoid;">
            <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 0.5rem;">
                <h3 style="margin: 0; font-size: 11px; font-weight: 700;">💰 INCOME PER DIVISI</h3>
            </div>
            
            <?php if (empty($incomeGrouped)): ?>
                <div style="text-align: center; padding: 1rem; color: #999; font-size: 9px;">Tidak ada data pemasukan</div>
            <?php else: ?>
                <?php foreach ($incomeGrouped as $divId => $divData): ?>
                    <div style="margin-bottom: 0.8rem; page-break-inside: avoid;">
                        <div style="background: #f0fdf4; padding: 0.4rem 0.6rem; border-left: 3px solid #10b981; margin-bottom: 0.3rem; display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 10px; color: #1f2937;"><?php echo $divData['division_name']; ?></strong>
                            <strong style="font-size: 10px; color: #10b981;">Rp <?php echo number_format($divData['total'], 0, ',', '.'); ?></strong>
                        </div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 8px; margin-bottom: 0.3rem;">
                            <thead>
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 3px 5px; text-align: left; border-bottom: 1px solid #e5e7eb;">Kategori</th>
                                    <th style="padding: 3px 5px; text-align: left; border-bottom: 1px solid #e5e7eb;">Keterangan</th>
                                    <th style="padding: 3px 5px; text-align: center; border-bottom: 1px solid #e5e7eb; width: 50px;">Waktu</th>
                                    <th style="padding: 3px 5px; text-align: right; border-bottom: 1px solid #e5e7eb; width: 90px;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($divData['transactions'] as $trans): ?>
                                    <tr>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6;"><?php echo $trans['category_name'] ?? '-'; ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6;"><?php echo htmlspecialchars(substr($trans['description'] ?? '-', 0, 40)); ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: center;"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600; color: #10b981;">Rp <?php echo number_format($trans['amount'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Expense Section for Print -->
        <div style="margin-bottom: 1rem; page-break-inside: avoid;">
            <div style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 0.5rem;">
                <h3 style="margin: 0; font-size: 11px; font-weight: 700;">💸 EXPENSES PER DIVISI</h3>
            </div>
            
            <?php if (empty($expenseGrouped)): ?>
                <div style="text-align: center; padding: 1rem; color: #999; font-size: 9px;">Tidak ada data pengeluaran</div>
            <?php else: ?>
                <?php foreach ($expenseGrouped as $divId => $divData): ?>
                    <div style="margin-bottom: 0.8rem; page-break-inside: avoid;">
                        <div style="background: #fef2f2; padding: 0.4rem 0.6rem; border-left: 3px solid #ef4444; margin-bottom: 0.3rem; display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 10px; color: #1f2937;"><?php echo $divData['division_name']; ?></strong>
                            <strong style="font-size: 10px; color: #ef4444;">Rp <?php echo number_format($divData['total'], 0, ',', '.'); ?></strong>
                        </div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 8px; margin-bottom: 0.3rem;">
                            <thead>
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 3px 5px; text-align: left; border-bottom: 1px solid #e5e7eb;">Kategori</th>
                                    <th style="padding: 3px 5px; text-align: left; border-bottom: 1px solid #e5e7eb;">Keterangan</th>
                                    <th style="padding: 3px 5px; text-align: center; border-bottom: 1px solid #e5e7eb; width: 50px;">Waktu</th>
                                    <th style="padding: 3px 5px; text-align: right; border-bottom: 1px solid #e5e7eb; width: 90px;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($divData['transactions'] as $trans): ?>
                                    <tr>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6;"><?php echo $trans['category_name'] ?? '-'; ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6;"><?php echo htmlspecialchars(substr($trans['description'] ?? '-', 0, 40)); ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: center;"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></td>
                                        <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600; color: #ef4444;">Rp <?php echo number_format($trans['amount'], 0, ',', '.'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Purchase Details Section for Print -->
        <?php if (!empty($purchaseGrouped)): ?>
        <div style="margin-bottom: 1rem; page-break-inside: avoid;">
            <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 0.5rem 0.75rem; border-radius: 6px; margin-bottom: 0.5rem;">
                <h3 style="margin: 0; font-size: 11px; font-weight: 700;">🛒 DETAIL PEMBELIAN</h3>
            </div>
            
            <?php foreach ($purchaseGrouped as $divName => $purchaseData): ?>
                <div style="margin-bottom: 0.8rem; page-break-inside: avoid;">
                    <div style="background: #fffbeb; padding: 0.4rem 0.6rem; border-left: 3px solid #f59e0b; margin-bottom: 0.3rem; display: flex; justify-content: space-between; align-items: center;">
                        <strong style="font-size: 10px; color: #1f2937;"><?php echo $divName; ?></strong>
                        <strong style="font-size: 10px; color: #f59e0b;">Rp <?php echo number_format($purchaseData['total'], 0, ',', '.'); ?></strong>
                    </div>
                    <table style="width: 100%; border-collapse: collapse; font-size: 8px;">
                        <thead>
                            <tr style="background: #f9fafb;">
                                <th style="padding: 3px 5px; text-align: left; border-bottom: 1px solid #e5e7eb;">Item</th>
                                <th style="padding: 3px 5px; text-align: center; border-bottom: 1px solid #e5e7eb; width: 40px;">Qty</th>
                                <th style="padding: 3px 5px; text-align: right; border-bottom: 1px solid #e5e7eb; width: 70px;">Harga</th>
                                <th style="padding: 3px 5px; text-align: right; border-bottom: 1px solid #e5e7eb; width: 80px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchaseData['items'] as $item): ?>
                                <tr>
                                    <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6;">
                                        <div><?php echo $item['item_name']; ?></div>
                                        <div style="font-size: 7px; color: #999;"><?php echo $item['supplier_name']; ?></div>
                                    </td>
                                    <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: center;"><?php echo $item['quantity']; ?></td>
                                    <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: right;">Rp <?php echo number_format($item['unit_price'], 0, ',', '.'); ?></td>
                                    <td style="padding: 3px 5px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600;">Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Summary Footer -->
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px solid #e5e7eb; page-break-inside: avoid;">
            <table style="width: 100%; font-size: 9px;">
                <tr>
                    <td style="width: 50%; padding: 0.3rem; background: #f0fdf4; border: 1px solid #10b981;">
                        <strong>Total Pemasukan:</strong>
                    </td>
                    <td style="text-align: right; padding: 0.3rem; background: #f0fdf4; border: 1px solid #10b981; font-weight: bold; color: #10b981;">
                        Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0.3rem; background: #fef2f2; border: 1px solid #ef4444;">
                        <strong>Total Pengeluaran:</strong>
                    </td>
                    <td style="text-align: right; padding: 0.3rem; background: #fef2f2; border: 1px solid #ef4444; font-weight: bold; color: #ef4444;">
                        Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?>
                    </td>
                </tr>
                <tr style="font-size: 11px;">
                    <td style="padding: 0.4rem; background: <?php echo $netBalance >= 0 ? '#dbeafe' : '#fef3c7'; ?>; border: 2px solid <?php echo $netBalance >= 0 ? '#3b82f6' : '#f59e0b'; ?>;">
                        <strong>SALDO BERSIH:</strong>
                    </td>
                    <td style="text-align: right; padding: 0.4rem; background: <?php echo $netBalance >= 0 ? '#dbeafe' : '#fef3c7'; ?>; border: 2px solid <?php echo $netBalance >= 0 ? '#3b82f6' : '#f59e0b'; ?>; font-weight: bold; color: <?php echo $netBalance >= 0 ? '#3b82f6' : '#f59e0b'; ?>; font-size: 13px;">
                        Rp <?php echo number_format($netBalance, 0, ',', '.'); ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <?php echo generateSignatureSection(); ?>
        <?php echo generateReportFooter($currentUser['full_name'] ?? $currentUser['user_name'] ?? 'Administrator'); ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
