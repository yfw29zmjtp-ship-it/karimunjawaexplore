<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Laporan Detail Pemasukan & Pengeluaran';

// Get filter parameters
$filter_month = isset($_GET['filter_month']) ? $_GET['filter_month'] : date('Y-m');
$transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : 'all';
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Calculate start and end date from month
$start_date = $filter_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Get all divisions
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

// Build WHERE clause
$whereConditions = ["cb.transaction_date BETWEEN :start_date AND :end_date"];
$params = ['start_date' => $start_date, 'end_date' => $end_date];

if ($transaction_type !== 'all') {
    $whereConditions[] = "cb.transaction_type = :transaction_type";
    $params['transaction_type'] = $transaction_type;
}

if ($division_id > 0) {
    $whereConditions[] = "cb.division_id = :division_id";
    $params['division_id'] = $division_id;
}

$whereClause = implode(' AND ', $whereConditions);

// Get detailed transactions
$transactions = $db->fetchAll("
    SELECT 
        cb.*,
        d.division_name,
        c.category_name,
        u.full_name as user_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.user_id
    WHERE $whereClause
    ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
", $params);

// Calculate summary
$totalIncome = 0;
$totalExpense = 0;

foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
    } else {
        $totalExpense += $trans['amount'];
    }
}

$netBalance = $totalIncome - $totalExpense;

include '../../includes/header.php';
?>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET">
        <div style="display: grid; grid-template-columns: 2fr 1.5fr 1.5fr auto; gap: 1rem; align-items: end; margin-bottom: 1rem;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Pilih Bulan</label>
                <input type="month" name="filter_month" class="form-control" value="<?php echo $filter_month; ?>" required>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Tipe Transaksi</label>
                <select name="transaction_type" class="form-control">
                    <option value="all" <?php echo $transaction_type === 'all' ? 'selected' : ''; ?>>Semua</option>
                    <option value="income" <?php echo $transaction_type === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                    <option value="expense" <?php echo $transaction_type === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Divisi</label>
                <select name="division_id" class="form-control">
                    <option value="0">-- Semua Divisi --</option>
                    <?php foreach ($divisions as $div): ?>
                        <option value="<?php echo $div['id']; ?>" <?php echo $division_id == $div['id'] ? 'selected' : ''; ?>>
                            <?php echo $div['division_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="height: 42px;">
                <i data-feather="search" style="width: 16px; height: 16px;"></i> Filter
            </button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--success); display: flex; align-items: center; justify-content: center;">
                <i data-feather="arrow-up" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Pemasukan</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--success);">
                    Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--danger); display: flex; align-items: center; justify-content: center;">
                <i data-feather="arrow-down" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Pengeluaran</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--danger);">
                    Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?>
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
                <div style="font-size: 1.375rem; font-weight: 700; color: <?php echo $netBalance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    Rp <?php echo number_format($netBalance, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: var(--warning); display: flex; align-items: center; justify-content: center;">
                <i data-feather="list" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Total Transaksi</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: var(--text-primary);">
                    <?php echo number_format(count($transactions), 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
            Detail Transaksi (<?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>)
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
    
    <?php if (empty($transactions)): ?>
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <p>Tidak ada transaksi untuk filter yang dipilih</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 10%;">Tanggal</th>
                        <th style="width: 8%;">Waktu</th>
                        <th style="width: 10%;">Tipe</th>
                        <th style="width: 15%;">Divisi</th>
                        <th style="width: 15%;">Kategori</th>
                        <th>Deskripsi</th>
                        <th style="text-align: right; width: 15%;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td style="font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo date('d M Y', strtotime($trans['transaction_date'])); ?>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-muted);">
                                <?php echo date('H:i', strtotime($trans['transaction_time'])); ?>
                            </td>
                            <td>
                                <?php if ($trans['transaction_type'] === 'income'): ?>
                                    <span class="badge" style="background: var(--success); color: white; padding: 0.25rem 0.625rem; border-radius: var(--radius-md); font-size: 0.75rem;">
                                        Pemasukan
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--danger); color: white; padding: 0.25rem 0.625rem; border-radius: var(--radius-md); font-size: 0.75rem;">
                                        Pengeluaran
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-primary);">
                                <?php echo $trans['division_name'] ?? '-'; ?>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-secondary);">
                                <?php echo $trans['category_name'] ?? '-'; ?>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-primary);">
                                <?php echo $trans['description']; ?>
                            </td>
                            <td style="text-align: right; font-weight: 600; color: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>;">
                                Rp <?php echo number_format($trans['amount'], 0, ',', '.'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="border-top: 2px solid var(--bg-tertiary);">
                    <tr style="background: var(--bg-tertiary);">
                        <td colspan="6" style="font-weight: 700; color: var(--text-primary); text-align: right;">
                            TOTAL
                        </td>
                        <td style="text-align: right; font-weight: 800; color: var(--text-primary);">
                            <?php if ($transaction_type === 'income'): ?>
                                <div style="color: var(--success);">Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
                            <?php elseif ($transaction_type === 'expense'): ?>
                                <div style="color: var(--danger);">Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
                            <?php else: ?>
                                <div style="color: var(--success);">+ Rp <?php echo number_format($totalIncome, 0, ',', '.'); ?></div>
                                <div style="color: var(--danger);">- Rp <?php echo number_format($totalExpense, 0, ',', '.'); ?></div>
                                <div style="color: <?php echo $netBalance >= 0 ? 'var(--success)' : 'var(--danger)'; ?>; border-top: 1px solid var(--bg-tertiary); padding-top: 0.25rem;">
                                    = Rp <?php echo number_format($netBalance, 0, ',', '.'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    feather.replace();
    
    function exportToPDF() {
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'pdf');
        window.open('detailed-pdf.php?' + params.toString(), '_blank');
    }
</script>

<?php include '../../includes/footer.php'; ?>
