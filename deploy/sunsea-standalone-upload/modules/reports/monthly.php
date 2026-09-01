<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

// Check if user is logged in
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
if (!empty($ownerCapitalAccountIds)) {
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Get Total Real Cash (All Time) - Exclude Owner Capital
$allTimeCashResult = $db->fetchOne(
    "SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as balance FROM cash_book WHERE 1=1" . $excludeOwnerCapital
);
$totalRealCash = $allTimeCashResult['balance'] ?? 0;

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

$pageTitle = 'Laporan Bulanan';

// Get filter parameters
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = date('Y', strtotime($selectedMonth));
$monthVal = date('m', strtotime($selectedMonth));
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Get all divisions for filter
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

// Build WHERE clause
$whereConditions = ["DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month"];
$params = ['month' => $selectedMonth];

if ($division_id > 0) {
    $whereConditions[] = "cb.division_id = :division_id";
    $params['division_id'] = $division_id;
}

$whereClause = implode(' AND ', $whereConditions);

// Build exclusion condition for CASE statement
$ownerCapitalExcludeCondition = '';
if (!empty($ownerCapitalAccountIds)) {
    $ownerCapitalExcludeCondition = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Get Daily Summary for the Month - Exclude owner capital from income AND expense
$dailySummary = $db->fetchAll("
    SELECT 
        DATE(cb.transaction_date) as date,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as net_balance,
        COUNT(*) as transaction_count
    FROM cash_book cb
    WHERE $whereClause
    GROUP BY DATE(cb.transaction_date)
    ORDER BY date ASC
", $params);

// Calculate totals
$grandIncome = 0;
$grandExpense = 0;
$grandNet = 0;
$grandTransactions = 0;

foreach ($dailySummary as $day) {
    $grandIncome += $day['total_income'];
    $grandExpense += $day['total_expense'];
    $grandNet += $day['net_balance'];
    $grandTransactions += $day['transaction_count'];
}

// Get Division with Highest Total Income
$biggestIncome = $db->fetchOne("
    SELECT d.division_name, SUM(cb.amount) as total_amount
    FROM cash_book cb
    JOIN divisions d ON cb.division_id = d.id
    WHERE $whereClause AND cb.transaction_type = 'income'
    GROUP BY d.id, d.division_name
    ORDER BY total_amount DESC
    LIMIT 1
", $params);

// Get Division with Highest Total Expense
$biggestExpense = $db->fetchOne("
    SELECT d.division_name, SUM(cb.amount) as total_amount
    FROM cash_book cb
    JOIN divisions d ON cb.division_id = d.id
    WHERE $whereClause AND cb.transaction_type = 'expense'
    GROUP BY d.id, d.division_name
    ORDER BY total_amount DESC
    LIMIT 1
", $params);

// Month names in Indonesian
$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$pageTitle = 'Laporan Bulan ' . $monthNames[(int)$monthVal] . ' ' . $year;
$dateRangeText = 'Bulan ' . $monthNames[(int)$monthVal] . ' ' . $year;

// Get company info for print
$company = getCompanyInfo();

include '../../includes/header.php';
?>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.5rem;">
    <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Bulan & Tahun</label>
            <input type="month" name="month" class="form-control" value="<?php echo $selectedMonth; ?>" required>
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
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Cari
        </button>
    </form>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Pemasukan</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: var(--success);">
            <?php echo formatCurrency($grandIncome); ?>
        </div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Pengeluaran</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: var(--danger);">
            <?php echo formatCurrency($grandExpense); ?>
        </div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Net Balance</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
            <?php echo formatCurrency($grandNet); ?>
        </div>
    </div>
    
    <!-- Saldo Petty Cash (Kas Besar) -->
    <div class="card" style="padding: 1rem; border-left: 4px solid #10b981;">
        <div style="font-size: 0.75rem; color: #059669; margin-bottom: 0.5rem;">💵 Saldo Petty Cash</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #059669;">
            <?php echo formatCurrency($pettyCashBalance); ?>
        </div>
        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 5px;">(Kas Besar - Operasional)</div>
    </div>
    
    <!-- Saldo Modal Owner -->
    <div class="card" style="padding: 1rem; border-left: 4px solid #f59e0b;">
        <div style="font-size: 0.75rem; color: #d97706; margin-bottom: 0.5rem;">🔥 Saldo Modal Owner</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #d97706;">
            <?php echo formatCurrency($ownerCapitalBalance); ?>
        </div>
        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 5px;">(Untuk Expense Operasional)</div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Transaksi</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color);">
            <?php echo number_format($grandTransactions); ?>
        </div>
    </div>
</div>

<!-- Key Highlights -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Biggest Income Division -->
    <div class="card" style="padding: 1rem; border-left: 4px solid var(--success);">
        <h4 style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem;">🏆 Pemasukan Terbesar (Divisi)</h4>
        <?php if ($biggestIncome): ?>
            <div style="font-size: 1.25rem; font-weight: 700; color: var(--success); margin-bottom: 0.25rem;">
                <?php echo formatCurrency($biggestIncome['total_amount']); ?>
            </div>
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="briefcase" style="width: 16px; height: 16px;"></i>
                <?php echo htmlspecialchars($biggestIncome['division_name']); ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
                Total kontribusi pendapatan bulan ini
            </div>
        <?php else: ?>
            <div style="color: var(--text-muted); font-size: 0.9rem;">- Belum ada data -</div>
        <?php endif; ?>
    </div>
    
    <!-- Biggest Expense Division -->
    <div class="card" style="padding: 1rem; border-left: 4px solid var(--danger);">
        <h4 style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem;">💸 Pengeluaran Terbesar (Divisi)</h4>
        <?php if ($biggestExpense): ?>
            <div style="font-size: 1.25rem; font-weight: 700; color: var(--danger); margin-bottom: 0.25rem;">
                <?php echo formatCurrency($biggestExpense['total_amount']); ?>
            </div>
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="briefcase" style="width: 16px; height: 16px;"></i>
                <?php echo htmlspecialchars($biggestExpense['division_name']); ?>
            </div>
            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">
                Total penggunaan dana bulan ini
            </div>
        <?php else: ?>
            <div style="color: var(--text-muted); font-size: 0.9rem;">- Belum ada data -</div>
        <?php endif; ?>
    </div>
</div>

<!-- Monthly Chart -->
<div class="card" style="margin-bottom: 1.5rem;">
        <h3 style="font-size: 0.95rem; color: var(--text-primary); font-weight: 600; margin-bottom: 1rem;">
            📊 Grafik Harian (<?php echo $monthNames[(int)$monthVal] . ' ' . $year; ?>)
        </h3>
        <canvas id="monthlyChart" style="max-height: 380px;"></canvas>
    </div>

    <!-- Monthly Summary Table -->
    <!-- Monthly Summary Table -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 1rem;">
            <h3 style="font-size: 0.95rem; color: var(--text-primary); font-weight: 600;">
                📊 Ringkasan Harian
            </h3>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="exportToPDF()" class="btn btn-danger btn-sm">
                    <i data-feather="file-text" style="width: 14px; height: 14px;"></i> Export PDF
                </button>
                <button onclick="window.print()" class="btn btn-secondary btn-sm">
                    <i data-feather="printer" style="width: 14px; height: 14px;"></i> Print
                </button>
                <button onclick="exportToExcel()" class="btn btn-success btn-sm">
                    <i data-feather="download" style="width: 14px; height: 14px;"></i> Export Excel
                </button>
            </div>
        </div>
    
    <?php if (empty($dailySummary)): ?>
        <p style="text-align: center; padding: 2rem; color: var(--text-muted);">
            Tidak ada data untuk periode yang dipilih
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table" id="monthlyTable">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th class="text-right">Pemasukan</th>
                        <th class="text-right">Pengeluaran</th>
                        <th class="text-right">Net Balance</th>
                        <th class="text-center">Transaksi</th>
                        <th class="text-center">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                    foreach ($dailySummary as $day): 
                        $dateObj = strtotime($day['date']);
                    ?>
                        <tr>
                            <td style="font-weight: 600; font-size: 0.813rem;">
                                <?php echo date('d/m/Y', $dateObj); ?> 
                                <span style="font-weight:normal; color:#666;">(<?php echo $dayNames[date('w', $dateObj)]; ?>)</span>
                            </td>
                            <td class="text-right" style="font-weight: 700; font-size: 0.875rem; color: var(--success);">
                                <?php echo formatCurrency($day['total_income']); ?>
                            </td>
                            <td class="text-right" style="font-weight: 700; font-size: 0.875rem; color: var(--danger);">
                                <?php echo formatCurrency($day['total_expense']); ?>
                            </td>
                            <td class="text-right" style="font-weight: 800; font-size: 0.938rem; color: <?php echo $day['net_balance'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo formatCurrency($day['net_balance']); ?>
                            </td>
                            <td class="text-center" style="font-size: 0.813rem;">
                                <span style="padding: 0.25rem 0.5rem; background: var(--bg-tertiary); border-radius: 4px;">
                                    <?php echo $day['transaction_count']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="daily.php?start_date=<?php echo $day['date']; ?>&end_date=<?php echo $day['date']; ?><?php echo $division_id > 0 ? '&division_id=' . $division_id : ''; ?>" 
                                   class="btn btn-primary btn-sm">
                                    <i data-feather="eye" style="width: 14px; height: 14px;"></i> Lihat
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--bg-tertiary); font-weight: 800;">
                        <td>TOTAL</td>
                        <td class="text-right" style="color: var(--success);">
                            <?php echo formatCurrency($grandIncome); ?>
                        </td>
                        <td class="text-right" style="color: var(--danger);">
                            <?php echo formatCurrency($grandExpense); ?>
                        </td>
                        <td class="text-right" style="color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo formatCurrency($grandNet); ?>
                        </td>
                        <td class="text-center">
                            <?php echo number_format($grandTransactions); ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    feather.replace();
    
    <?php
    // Generate daily data for chart (fill missing days)
    $daysInMonth = date('t', strtotime($selectedMonth . '-01'));
    $chartLabels = [];
    $incomeData = [];
    $expenseData = [];
    
    // Index existing data
    $indexedData = [];
    if (!empty($dailySummary)) {
        foreach ($dailySummary as $day) {
            $d = (int)date('d', strtotime($day['date']));
            $indexedData[$d] = $day;
        }
    }
    
    for ($i = 1; $i <= $daysInMonth; $i++) {
        $chartLabels[] = (string)$i;
        $incomeData[] = isset($indexedData[$i]) ? $indexedData[$i]['total_income'] : 0;
        $expenseData[] = isset($indexedData[$i]) ? $indexedData[$i]['total_expense'] : 0;
    }
    ?>

    // Prepare chart data
    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const incomeData = <?php echo json_encode($incomeData); ?>;
    const expenseData = <?php echo json_encode($expenseData); ?>;
    
    // Create chart
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    const monthlyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Pemasukan',
                    data: incomeData,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 3,
                    fill: true
                },
                {
                    label: 'Pengeluaran',
                    data: expenseData,
                    backgroundColor: 'rgba(239, 68, 68, 0.2)',
                    borderColor: 'rgba(239, 68, 68, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 3,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + value.toLocaleString('id-ID');
                        }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index',
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': Rp ' + context.parsed.y.toLocaleString('id-ID');
                        }
                    }
                }
            }
        }
    });
    
    // Export to PDF function
    function exportToPDF() {
        const element = document.getElementById('printContent');
        if (!element) {
            alert('Report tidak ditemukan!');
            return;
        }
        showPDFPreview(element);
    }

    function showPDFPreview(element) {
        const modal = document.createElement('div');
        modal.id = 'pdfPreviewModal';
        modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 20px;';
        
        modal.innerHTML = `<div style="background: white; border-radius: 8px; max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column;">
            <div style="padding: 1.5rem; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; color: #333;">Preview PDF</h3>
                <button onclick="closePDFPreview()" style="border: none; background: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>
            </div>
            <div id="previewContent" style="flex: 1; overflow-y: auto; padding: 1.5rem; background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                <div style="color: #999;">Loading PDF...</div>
            </div>
            <div style="padding: 1.5rem; border-top: 1px solid #ddd; display: flex; gap: 1rem; justify-content: flex-end;">
                <button onclick="closePDFPreview()" class="btn btn-secondary" style="border: 1px solid #ddd; background: white; color: #666; border-radius: 4px; cursor: pointer; padding: 0.75rem 1.5rem;">Cancel</button>
                <button onclick="confirmPDFDownload()" class="btn btn-danger" style="border: none; background: #dc3545; color: white; border-radius: 4px; cursor: pointer; padding: 0.75rem 1.5rem;">Download PDF</button>
            </div>
        </div>`;
        
        document.body.appendChild(modal);
        renderPreview(element);
    }

    function renderPreview(element) {
        // Check if html2canvas is ready, if not wait
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
                    previewContent.innerHTML = '<div style="color: #d32f2f; text-align: center;"><p>Error: ' + err.message + '</p><p style="font-size: 12px; color: #666;">Gunakan Print Preview</p></div>';
                }
            });
        }, 100);
    }

    function confirmPDFDownload() {
        if (!window.pdfPreviewCanvas) {
            alert('Preview not ready');
            return;
        }
        
        // Check if jsPDF is available (it's in window.jsPDF from html2pdf bundle)
        if (typeof window.jsPDF === 'undefined' && typeof jsPDF === 'undefined') {
            // Fallback: wait for library and retry
            setTimeout(() => { confirmPDFDownload(); }, 500);
            return;
        }
        
        try {
            const canvas = window.pdfPreviewCanvas;
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const PDF = window.jsPDF || jsPDF;
            const doc = new PDF({orientation: 'portrait', unit: 'mm', format: 'a4'});
            
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
            
            doc.save('Laporan-Bulanan-<?php echo $year; ?>.pdf');
            closePDFPreview();
        } catch (e) {
            console.error('PDF error:', e);
            alert('Error: ' + e.message);
        }
    }

    function closePDFPreview() {
        const modal = document.getElementById('pdfPreviewModal');
        if (modal) modal.remove();
        window.pdfPreviewCanvas = null;
    }


    function closePDFPreview() {
        const modal = document.getElementById('pdfPreviewModal');
        if (modal) {
            modal.remove();
        }
        window.pdfPreviewCanvas = null;
    }
    
    // Export to Excel function
    function exportToExcel() {
        const table = document.getElementById('monthlyTable');
        let html = '<table>';
        
        // Get table HTML
        html += table.outerHTML;
        html += '</table>';
        
        // Create downloadable file
        const blob = new Blob([html], {
            type: 'application/vnd.ms-excel'
        });
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'laporan-bulanan-<?php echo $year; ?>.xls';
        a.click();
        window.URL.revokeObjectURL(url);
    }
</script>

<style>
    /* Hide print content from normal view using position, not display */
    #printContent {
        position: absolute;
        left: -9999px;
        top: -9999px;
        width: 210mm;
        opacity: 0;
        pointer-events: none;
    }
    
    @media print {
        /* Show print content when printing */
        #printContent {
            position: static !important;
            left: auto !important;
            top: auto !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            display: block !important;
            width: 100%;
        }
        
        /* Hide screen elements when printing */
        .sidebar, .top-bar, .top-bar .user-info, .btn, .form-control, form, .filter-section, .no-print, .card, footer, nav, .breadcrumb {
            display: none !important;
        }
        
        main, .main-content {
            margin-left: 0 !important;
            margin-top: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        
        body {
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            margin: 0 !important;
            padding: 0 !important;
            width: 210mm !important;
        }
            padding: 0;
        }
        
        body {
            font-family: "Segoe UI", "Trebuchet MS", sans-serif;
            margin: 0;
            padding: 0;
        }
        
        canvas {
            max-width: 100%;
        }
        
        table {
            page-break-inside: avoid;
        }
        
        tr {
            page-break-inside: avoid;
        }
    }
</style>

<!-- Print-Only Report Section -->
<div id="printContent">
    <div style="width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm 8mm; background: white;">
        <?php echo generateReportHeader('LAPORAN BULANAN', 'Tahun ' . $year . ($division_id > 0 ? ' - ' . end($divisions)['division_name'] : ''), $dateRangeText, $absoluteLogo); ?>
        
        <!-- Summary Cards for Print -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.3rem; margin-bottom: 0.6rem;">
            <?php
            echo generateSummaryCard('💰 TOTAL PEMASUKAN', formatCurrency($grandIncome), '#10b981', '');
            echo generateSummaryCard('💸 TOTAL PENGELUARAN', formatCurrency($grandExpense), '#ef4444', '');
            echo generateSummaryCard('📊 NET BALANCE', formatCurrency($grandNet), $grandNet >= 0 ? '#3b82f6' : '#f59e0b', '');
            echo generateSummaryCard('📈 TOTAL TRANSAKSI', number_format($grandTransactions), '#8b5cf6', '');
            ?>
        </div>
        
        <!-- Monthly Summary Table -->
        <h2 style="font-size: 10px; font-weight: 700; color: #1f2937; margin-bottom: 0.4rem; page-break-after: avoid;">
            Ringkasan Per Bulan
        </h2>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 0.6rem; font-size: 8px;">
            <thead>
                <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 700;">
                    <th style="padding: 6px 8px; text-align: left; border-bottom: 1.5px solid #d1d5db;">Tanggal</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Pemasukan</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Pengeluaran</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Net Balance</th>
                    <th style="padding: 6px 8px; text-align: center; border-bottom: 1.5px solid #d1d5db;">Transaksi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                $rowCount = 0;
                foreach ($dailySummary as $day): 
                    $bgColor = ($rowCount % 2 === 0) ? '#f9fafb' : '#ffffff';
                    $netColor = ($day['net_balance'] >= 0) ? '#10b981' : '#ef4444';
                    $rowCount++;
                    $dateObj = strtotime($day['date']);
                ?>
                    <tr style="background: <?php echo $bgColor; ?>;">
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
                            <?php echo date('d/m/Y', $dateObj); ?> <span style="font-weight:normal; color:#666;">(<?php echo $dayNames[date('w', $dateObj)]; ?>)</span>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #10b981; font-weight: 600;">
                            Rp <?php echo number_format($day['total_income'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #ef4444; font-weight: 600;">
                            Rp <?php echo number_format($day['total_expense'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: <?php echo $netColor; ?>; font-weight: 700;">
                            Rp <?php echo number_format($day['net_balance'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                            <?php echo $day['transaction_count']; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 800;">
                    <td style="padding: 6px 8px; border-top: 1.5px solid #d1d5db; border-bottom: 1.5px solid #d1d5db;">TOTAL</td>
                    <td style="padding: 6px 8px; text-align: right; border-top: 1.5px solid #d1d5db; border-bottom: 1.5px solid #d1d5db; color: #10b981;">
                        Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 6px 8px; text-align: right; border-top: 1.5px solid #d1d5db; border-bottom: 1.5px solid #d1d5db; color: #ef4444;">
                        Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 6px 8px; text-align: right; border-top: 1.5px solid #d1d5db; border-bottom: 1.5px solid #d1d5db; color: <?php echo $grandNet >= 0 ? '#10b981' : '#ef4444'; ?>;">
                        Rp <?php echo number_format($grandNet, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 6px 8px; text-align: center; border-top: 1.5px solid #d1d5db; border-bottom: 1.5px solid #d1d5db;">
                        <?php echo $grandTransactions; ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        
        <?php echo generateSignatureSection(); ?>
        <?php echo generateReportFooter(); ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
