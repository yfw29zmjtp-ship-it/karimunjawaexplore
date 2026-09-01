<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check if user is logged in
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Analisa Per Divisi';

// Get filter parameters
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Parse month
$filterDate = $month . '-01';
$year = date('Y', strtotime($filterDate));
$monthNum = date('m', strtotime($filterDate));
$monthName = date('F Y', strtotime($filterDate));

// Get all divisions
$divisions = [];
try {
    $divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");
} catch (\Throwable $e) {
    error_log("Error fetching divisions: " . $e->getMessage());
    // Only show error if debugging
    if (isset($_GET['debug'])) echo "Error fetching divisions: " . $e->getMessage();
}

// Get summary data per division for selected month
$divisionSummary = [];
try {
    $summaryQuery = "
    SELECT 
        d.id as division_id,
        d.division_name,
        d.division_code,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as net_balance,
        COUNT(cb.id) as transaction_count
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND YEAR(cb.transaction_date) = :year 
        AND MONTH(cb.transaction_date) = :month
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    ORDER BY net_balance DESC
";

    $divisionSummary = $db->fetchAll($summaryQuery, [
        'year' => $year,
        'month' => $monthNum
    ]);
} catch (\Throwable $e) {
    error_log("Error fetching division summary: " . $e->getMessage());
    if (isset($_GET['debug'])) echo "Error fetching division summary: " . $e->getMessage();
}

// If specific division is selected, get detailed data
$divisionDetail = null;
$divisionTransactions = [];
$categoryBreakdown = [];

if ($division_id > 0) {
    // Get division info
    $divisionDetail = $db->fetchOne("SELECT * FROM divisions WHERE id = ?", [$division_id]);

    // Get transactions for this division
    $masterDbName = DB_NAME;
    $divisionTransactions = $db->fetchAll("
        SELECT 
            cb.*,
            c.category_name,
            COALESCE(u.full_name, 'System') as created_by_name
        FROM cash_book cb
        LEFT JOIN categories c ON cb.category_id = c.id
        LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
        WHERE cb.division_id = :division_id
            AND YEAR(cb.transaction_date) = :year
            AND MONTH(cb.transaction_date) = :month
        ORDER BY cb.transaction_date DESC, cb.created_at DESC
    ", [
        'division_id' => $division_id,
        'year' => $year,
        'month' => $monthNum
    ]);

    // Get category breakdown
    $categoryBreakdown = $db->fetchAll("
        SELECT 
            c.category_name,
            cb.transaction_type,
            SUM(cb.amount) as total_amount,
            COUNT(*) as transaction_count
        FROM cash_book cb
        LEFT JOIN categories c ON cb.category_id = c.id
        WHERE cb.division_id = :division_id
            AND YEAR(cb.transaction_date) = :year
            AND MONTH(cb.transaction_date) = :month
        GROUP BY c.category_name, cb.transaction_type
        ORDER BY total_amount DESC
    ", [
        'division_id' => $division_id,
        'year' => $year,
        'month' => $monthNum
    ]);
}

include '../../includes/header.php';

// Division colors for visual variety
$divisionColors = [
    '#6366f1',
    '#8b5cf6',
    '#ec4899',
    '#f59e0b',
    '#10b981',
    '#3b82f6',
    '#ef4444',
    '#14b8a6',
    '#f97316',
    '#06b6d4'
];
?>

<style>
    .division-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 0.875rem;
    }

    .division-card {
        background: var(--bg-secondary);
        border-radius: 12px;
        padding: 0;
        overflow: hidden;
        border: 1px solid var(--bg-tertiary);
        transition: all 0.2s ease;
        position: relative;
    }

    .division-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.15);
        border-color: var(--primary-color);
    }

    .division-card-header {
        padding: 0.75rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--bg-tertiary);
    }

    .division-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .division-title {
        flex: 1;
        margin-left: 0.75rem;
    }

    .division-title h4 {
        font-size: 0.875rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
    }

    .division-title span {
        font-size: 0.65rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .division-badge {
        font-size: 0.65rem;
        padding: 0.2rem 0.5rem;
        border-radius: 20px;
        background: var(--bg-tertiary);
        color: var(--text-muted);
        font-weight: 600;
    }

    .division-stats {
        padding: 0.75rem 1rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }

    .division-stat {
        text-align: center;
        padding: 0.5rem;
        border-radius: 8px;
        background: var(--bg-primary);
    }

    .division-stat-label {
        font-size: 0.6rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }

    .division-stat-value {
        font-size: 0.8rem;
        font-weight: 700;
    }

    .division-stat-value.income {
        color: #10b981;
    }

    .division-stat-value.expense {
        color: #ef4444;
    }

    .division-footer {
        padding: 0.65rem 1rem;
        background: var(--bg-primary);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .division-net {
        font-size: 0.95rem;
        font-weight: 800;
    }

    .division-net.positive {
        color: #10b981;
    }

    .division-net.negative {
        color: #ef4444;
    }

    .division-link {
        position: absolute;
        inset: 0;
        z-index: 1;
    }

    .division-selected {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .division-selected::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        z-index: 2;
    }

    .filter-card {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(139, 92, 246, 0.04));
        border: 1px solid rgba(99, 102, 241, 0.15);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1.25rem;
    }

    .filter-form {
        display: flex;
        gap: 1rem;
        align-items: end;
        flex-wrap: wrap;
    }

    .filter-group {
        flex: 1;
        min-width: 180px;
    }

    .filter-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 0.4rem;
        display: block;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--bg-tertiary);
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .section-subtitle {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    .detail-banner {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 1rem 1.25rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .detail-banner h2 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .detail-banner span {
        font-size: 0.75rem;
        opacity: 0.9;
    }

    .category-item {
        display: flex;
        align-items: center;
        padding: 0.75rem;
        background: var(--bg-primary);
        border-radius: 10px;
        margin-bottom: 0.5rem;
        border-left: 3px solid;
    }

    .category-item.income {
        border-color: #10b981;
    }

    .category-item.expense {
        border-color: #ef4444;
    }

    .category-info {
        flex: 1;
    }

    .category-name {
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-primary);
    }

    .category-meta {
        font-size: 0.7rem;
        color: var(--text-muted);
    }

    .category-amount {
        font-weight: 800;
        font-size: 0.95rem;
    }

    @media (max-width: 768px) {
        .division-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.625rem;
        }

        .division-card-header {
            padding: 0.5rem 0.75rem;
        }

        .division-icon {
            width: 28px;
            height: 28px;
        }

        .division-title h4 {
            font-size: 0.8rem;
        }

        .division-stats {
            padding: 0.5rem 0.75rem;
        }

        .division-stat-value {
            font-size: 0.7rem;
        }

        .division-footer {
            padding: 0.5rem 0.75rem;
        }

        .division-net {
            font-size: 0.85rem;
        }

        .filter-form {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }
    }
</style>

<!-- Filter Section -->
<div class="filter-card">
    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label class="filter-label">📅 Periode Bulan</label>
            <input type="month" name="month" class="form-control" value="<?php echo $month; ?>">
        </div>

        <div class="filter-group">
            <label class="filter-label">🏢 Pilih Divisi</label>
            <select name="division_id" class="form-control">
                <option value="0">Semua Divisi</option>
                <?php foreach ($divisions as $div): ?>
                    <option value="<?php echo $div['id']; ?>" <?php echo $division_id == $div['id'] ? 'selected' : ''; ?>>
                        <?php echo $div['division_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 1.25rem;">
            <i data-feather="search" style="width: 15px; height: 15px;"></i> Tampilkan
        </button>
    </form>
</div>

<!-- Division Summary Cards -->
<div style="margin-bottom: 1.5rem;">
    <div class="section-header">
        <div>
            <h2 class="section-title">
                <span style="font-size: 1.1rem;">📊</span> Ringkasan Divisi
            </h2>
            <span class="section-subtitle"><?php echo $monthName; ?></span>
        </div>
        <span style="font-size: 0.75rem; color: var(--text-muted); background: var(--bg-tertiary); padding: 0.35rem 0.75rem; border-radius: 20px;">
            <?php echo count($divisionSummary); ?> divisi
        </span>
    </div>

    <div class="division-grid">
        <?php foreach ($divisionSummary as $index => $div):
            $color = $divisionColors[$index % count($divisionColors)];
            $isSelected = $division_id == $div['division_id'];
            $netClass = $div['net_balance'] >= 0 ? 'positive' : 'negative';
            // Hide cards with no transactions unless currently selected
            if ((int)$div['transaction_count'] === 0 && !$isSelected) continue;
        ?>
            <div class="division-card <?php echo $isSelected ? 'division-selected' : ''; ?>">
                <a href="?month=<?php echo $month; ?>&division_id=<?php echo $div['division_id']; ?>" class="division-link"></a>

                <div class="division-card-header">
                    <div class="division-icon" style="background: <?php echo $color; ?>;">
                        <?php echo strtoupper(substr($div['division_code'], 0, 2)); ?>
                    </div>
                    <div class="division-title">
                        <span><?php echo $div['division_code']; ?></span>
                        <h4><?php echo $div['division_name']; ?></h4>
                    </div>
                    <div class="division-badge"><?php echo $div['transaction_count']; ?> trx</div>
                </div>

                <div class="division-stats">
                    <div class="division-stat">
                        <div class="division-stat-label">Masuk</div>
                        <div class="division-stat-value income">
                            <?php echo $div['total_income'] > 0 ? formatCurrency($div['total_income']) : '-'; ?>
                        </div>
                    </div>
                    <div class="division-stat">
                        <div class="division-stat-label">Keluar</div>
                        <div class="division-stat-value expense">
                            <?php echo $div['total_expense'] > 0 ? formatCurrency($div['total_expense']) : '-'; ?>
                        </div>
                    </div>
                </div>

                <div class="division-footer">
                    <span style="font-size: 0.7rem; color: var(--text-muted);">Net Balance</span>
                    <span class="division-net <?php echo $netClass; ?>">
                        <?php echo formatCurrency($div['net_balance']); ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Division Detail Section -->
<?php if ($divisionDetail): ?>
    <div class="detail-banner">
        <div>
            <h2>📌 <?php echo $divisionDetail['division_name']; ?></h2>
            <span>Kode: <?php echo $divisionDetail['division_code']; ?> • <?php echo $monthName; ?></span>
        </div>
        <div style="display: flex; gap: 0.5rem; position: relative; z-index: 2;">
            <button onclick="window.print()" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: none;">
                <i data-feather="printer" style="width: 14px; height: 14px;"></i>
            </button>
            <a href="?month=<?php echo $month; ?>" class="btn btn-sm" style="background: rgba(255,255,255,0.2); color: white; border: none;">
                <i data-feather="x" style="width: 14px; height: 14px;"></i>
            </a>
        </div>
    </div>

    <!-- Category Breakdown -->
    <?php if (!empty($categoryBreakdown)): ?>
        <div class="card" style="margin-bottom: 1.25rem; padding: 1rem;">
            <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.5rem;">
                <h3 class="section-title" style="font-size: 0.9rem;">
                    <span>📊</span> Breakdown Kategori
                </h3>
            </div>

            <?php foreach ($categoryBreakdown as $cat):
                $isIncome = $cat['transaction_type'] === 'income';
            ?>
                <div class="category-item <?php echo $isIncome ? 'income' : 'expense'; ?>">
                    <div class="category-info">
                        <div class="category-name"><?php echo $cat['category_name'] ?: 'Lainnya'; ?></div>
                        <div class="category-meta">
                            <?php echo $cat['transaction_count']; ?> transaksi •
                            <?php echo $isIncome ? '📈 Pemasukan' : '📉 Pengeluaran'; ?>
                        </div>
                    </div>
                    <div class="category-amount" style="color: <?php echo $isIncome ? '#10b981' : '#ef4444'; ?>;">
                        <?php echo $isIncome ? '+' : '-'; ?><?php echo formatCurrency($cat['total_amount']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Transaction Details - Separated by type -->
    <?php if (!empty($divisionTransactions)):
        // Separate income and expense
        $incomeTransactions = array_filter($divisionTransactions, function ($t) {
            return $t['transaction_type'] === 'income';
        });
        $expenseTransactions = array_filter($divisionTransactions, function ($t) {
            return $t['transaction_type'] === 'expense';
        });
        $totalMasuk = array_sum(array_column($incomeTransactions, 'amount'));
        $totalKeluar = array_sum(array_column($expenseTransactions, 'amount'));
    ?>

        <!-- PEMASUKAN (Income) -->
        <?php if (!empty($incomeTransactions)): ?>
            <div class="card" style="padding: 1rem; margin-bottom: 1.25rem; border-left: 3px solid #10b981;">
                <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.5rem;">
                    <h3 class="section-title" style="font-size: 0.9rem;">
                        <span>📈</span> Pemasukan
                    </h3>
                    <span class="section-subtitle" style="color: #10b981; font-weight: 700;">
                        <?php echo count($incomeTransactions); ?> transaksi
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table" style="font-size: 0.8rem;">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Tanggal</th>
                                <th>Kategori</th>
                                <th>Metode</th>
                                <th>Keterangan</th>
                                <th class="text-right" style="width: 110px;">Jumlah</th>
                                <th>Input By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomeTransactions as $trans): ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?php echo date('d/m/y', strtotime($trans['transaction_date'])); ?></td>
                                    <td><?php echo $trans['category_name'] ?: '-'; ?></td>
                                    <td style="text-transform: uppercase; font-size: 0.7rem; font-weight: 600;"><?php echo strtoupper($trans['payment_method'] ?? '-'); ?></td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo $trans['description'] ?: '-'; ?></td>
                                    <td class="text-right" style="font-weight: 700; color: #10b981;"><?php echo formatCurrency($trans['amount']); ?></td>
                                    <td style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $trans['created_by_name'] ?? 'System'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(16,185,129,0.08); border-top: 2px solid #10b981;">
                                <td colspan="4" style="font-weight: 700; color: #10b981; font-size: 0.85rem;">Total Pemasukan</td>
                                <td class="text-right" style="font-weight: 800; color: #10b981; font-size: 0.9rem;">+<?php echo formatCurrency($totalMasuk); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- PENGELUARAN (Expense) -->
        <?php if (!empty($expenseTransactions)): ?>
            <div class="card" style="padding: 1rem; margin-bottom: 1.25rem; border-left: 3px solid #ef4444;">
                <div class="section-header" style="margin-bottom: 0.75rem; padding-bottom: 0.5rem;">
                    <h3 class="section-title" style="font-size: 0.9rem;">
                        <span>📉</span> Pengeluaran
                    </h3>
                    <span class="section-subtitle" style="color: #ef4444; font-weight: 700;">
                        <?php echo count($expenseTransactions); ?> transaksi
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table" style="font-size: 0.8rem;">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Tanggal</th>
                                <th>Kategori</th>
                                <th>Metode</th>
                                <th>Keterangan</th>
                                <th class="text-right" style="width: 110px;">Jumlah</th>
                                <th>Input By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenseTransactions as $trans): ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?php echo date('d/m/y', strtotime($trans['transaction_date'])); ?></td>
                                    <td><?php echo $trans['category_name'] ?: '-'; ?></td>
                                    <td style="text-transform: uppercase; font-size: 0.7rem; font-weight: 600;"><?php echo strtoupper($trans['payment_method'] ?? '-'); ?></td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo $trans['description'] ?: '-'; ?></td>
                                    <td class="text-right" style="font-weight: 700; color: #ef4444;"><?php echo formatCurrency($trans['amount']); ?></td>
                                    <td style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $trans['created_by_name'] ?? 'System'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(239,68,68,0.08); border-top: 2px solid #ef4444;">
                                <td colspan="4" style="font-weight: 700; color: #ef4444; font-size: 0.85rem;">Total Pengeluaran</td>
                                <td class="text-right" style="font-weight: 800; color: #ef4444; font-size: 0.9rem;">-<?php echo formatCurrency($totalKeluar); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- NET SUMMARY -->
        <div class="card" style="padding: 1rem; background: linear-gradient(135deg, #f8fafc, #f1f5f9);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="text-align: center; flex: 1;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Total Masuk</div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: #10b981;">+<?php echo formatCurrency($totalMasuk); ?></div>
                </div>
                <div style="font-size: 1.2rem; color: var(--text-muted);">−</div>
                <div style="text-align: center; flex: 1;">
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Total Keluar</div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: #ef4444;">-<?php echo formatCurrency($totalKeluar); ?></div>
                </div>
                <div style="font-size: 1.2rem; color: var(--text-muted);">=</div>
                <div style="text-align: center; flex: 1;">
                    <?php $netDiv = $totalMasuk - $totalKeluar; ?>
                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600;">Net Balance</div>
                    <div style="font-size: 1.1rem; font-weight: 800; color: <?php echo $netDiv >= 0 ? '#10b981' : '#ef4444'; ?>;">
                        <?php echo ($netDiv >= 0 ? '+' : '') . formatCurrency($netDiv); ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="card" style="padding: 2rem; text-align: center;">
            <div style="font-size: 2rem; margin-bottom: 0.5rem;">📭</div>
            <p style="color: var(--text-muted); margin: 0;">
                Tidak ada transaksi untuk divisi ini di bulan <?php echo $monthName; ?>
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    feather.replace();
</script>

<style>
    @media print {

        /* Hide navigation and filter */
        .sidebar,
        .topbar,
        .btn,
        form,
        a[href*="Tutup"] {
            display: none !important;
        }

        /* Reset page styles */
        body {
            background: white !important;
            color: black !important;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
            page-break-inside: avoid;
            margin-bottom: 1rem !important;
        }

        /* Table styles */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        table th {
            background-color: #f0f0f0 !important;
            font-weight: bold;
        }

        /* Badge styles */
        .badge {
            border: 1px solid;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
        }

        .badge-success {
            border-color: #10b981;
            color: #10b981;
        }

        .badge-danger {
            border-color: #ef4444;
            color: #ef4444;
        }

        /* Color overrides for print */
        .text-right {
            text-align: right !important;
        }

        /* Page breaks */
        .card {
            page-break-after: auto;
        }
    }
</style>

<?php include '../../includes/footer.php'; ?>