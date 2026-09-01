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

// Get Cash Account Balances from Master DB (SAME LOGIC AS DASHBOARD)
$pettyCashBalance = 0;
$ownerCapitalBalance = 0;
$pettyCashIncome = 0;
$ownerCapitalIncome = 0;
$totalExpense = 0;
$hotelRevenue = 0;

try {
    // Get ALL Petty Cash account IDs (account_type = 'cash')
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get ALL Modal Owner account IDs (account_type = 'owner_capital')
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $modalOwnerAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Query Petty Cash balance and income from cash_book (business DB)
    if (!empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
        ";
        $stmt = $db->getConnection()->prepare($query);
        $stmt->execute($pettyCashAccounts);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pettyCashBalance = $result['balance'] ?? 0;
        $pettyCashIncome = $result['total_income'] ?? 0;
        $hotelRevenue = $pettyCashIncome; // Hotel revenue = Petty Cash income only
    }
    
    // Query Modal Owner balance and income from cash_book (business DB)
    if (!empty($modalOwnerAccounts)) {
        $placeholders = implode(',', array_fill(0, count($modalOwnerAccounts), '?'));
        $query = "
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as total_expense,
                COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) - 
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
        ";
        $stmt = $db->getConnection()->prepare($query);
        $stmt->execute($modalOwnerAccounts);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $ownerCapitalBalance = $result['balance'] ?? 0;
        $ownerCapitalIncome = $result['total_income'] ?? 0;
    }
    
    // Get total expense (from all accounts)
    $stmt = $db->getConnection()->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM cash_book 
        WHERE transaction_type = 'expense'
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalExpense = $result['total'] ?? 0;
    
} catch (Exception $e) {
    error_log("Error fetching cash account balances: " . $e->getMessage());
}

// ============================================
// DAILY-FILTERED: Owner Transfer for selected date range
// ============================================
$ownerTransferFiltered = 0;
$saldoCashBank = 0;
$saldoPettyCash = 0;
$sisaOwner = 0;
try {
    $filterStart = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $filterEnd = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    // Owner transfer for selected date
    if (!empty($modalOwnerAccounts ?? [])) {
        $placeholders = implode(',', array_fill(0, count($modalOwnerAccounts), '?'));
        $stmt = $db->getConnection()->prepare("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM cash_book 
            WHERE transaction_type = 'income' 
            AND transaction_date BETWEEN ? AND ?
            AND cash_account_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$filterStart, $filterEnd], $modalOwnerAccounts));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $ownerTransferFiltered = $result['total'] ?? 0;
    }
    
    // Daily-filtered income (excluding owner capital)
    $dailyIncomeFiltered = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'income' AND transaction_date BETWEEN ? AND ?" . $excludeOwnerCapital,
        [$filterStart, $filterEnd]
    );
    $filteredIncome = $dailyIncomeFiltered['total'] ?? 0;
    
    // Daily-filtered expense
    $dailyExpenseFiltered = $db->fetchOne(
        "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
         WHERE transaction_type = 'expense' AND transaction_date BETWEEN ? AND ?",
        [$filterStart, $filterEnd]
    );
    $filteredExpense = $dailyExpenseFiltered['total'] ?? 0;
    
    // Get filtered balances from cash_book for the selected date range
    // Query all cash/bank accounts (non-owner accounts)
    $stmt = $masterDb->prepare("
        SELECT id 
        FROM cash_accounts 
        WHERE business_id = ? AND account_type IN ('cash', 'bank') AND is_active = 1
    ");
    $stmt->execute([$businessId]);
    $cashBankAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Cash + Bank balance (filtered by date range)
    if (!empty($cashBankAccountIds)) {
        $placeholders = implode(',', array_fill(0, count($cashBankAccountIds), '?'));
        $stmt = $db->getConnection()->prepare("
            SELECT COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as total 
            FROM cash_book 
            WHERE transaction_date BETWEEN ? AND ?
            AND cash_account_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$filterStart, $filterEnd], $cashBankAccountIds));
        $saldoCashBank = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } else {
        $saldoCashBank = 0;
    }
    
    // Petty Cash balance only (account_type = 'cash') (filtered by date range)
    if (!empty($pettyCashAccounts ?? [])) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $stmt = $db->getConnection()->prepare("
            SELECT COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as total 
            FROM cash_book 
            WHERE transaction_date BETWEEN ? AND ?
            AND cash_account_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$filterStart, $filterEnd], $pettyCashAccounts));
        $saldoPettyCash = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } else {
        $saldoPettyCash = 0;
    }
    
    // Sisa Owner balance (account_type = 'owner_capital') (filtered by date range)
    if (!empty($modalOwnerAccounts ?? [])) {
        $placeholders = implode(',', array_fill(0, count($modalOwnerAccounts), '?'));
        $stmt = $db->getConnection()->prepare("
            SELECT COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) as total 
            FROM cash_book 
            WHERE transaction_date BETWEEN ? AND ?
            AND cash_account_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$filterStart, $filterEnd], $modalOwnerAccounts));
        $sisaOwner = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    } else {
        $sisaOwner = 0;
    }
    
} catch (Exception $e) {
    error_log("Error fetching daily-filtered data: " . $e->getMessage());
}

$pageTitle = 'Laporan Harian';

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Get all divisions for filter
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

// Build WHERE clause
$whereConditions = ["cb.transaction_date BETWEEN :start_date AND :end_date"];
$params = [
    'start_date' => $start_date,
    'end_date' => $end_date
];

if ($division_id > 0) {
    $whereConditions[] = "cb.division_id = :division_id";
    $params['division_id'] = $division_id;
}

$whereClause = implode(' AND ', $whereConditions);

// Add owner capital exclusion to operational income queries
$whereClauseWithExclusion = $whereClause . str_replace('cash_account_id', 'cb.cash_account_id', $excludeOwnerCapital);

// Get Opening Balance (All transactions before start_date) - Exclude Owner Capital
$openingParams = ['start_date' => $start_date];
$openingWhere = "transaction_date < :start_date" . $excludeOwnerCapital;
if ($division_id > 0) {
    $openingWhere .= " AND division_id = :division_id";
    $openingParams['division_id'] = $division_id;
}

// Profit for the selected period starts at zero and accumulates net income minus expense
$runningBalance = 0;

// Build exclusion condition for CASE statement
$ownerCapitalExcludeCondition = '';
if (!empty($ownerCapitalAccountIds)) {
    $ownerCapitalExcludeCondition = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Get daily summary - Exclude owner capital ONLY from income, NOT from expense
$dailySummary = $db->fetchAll("
    SELECT 
        DATE(cb.transaction_date) as date,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as net_balance,
        COUNT(*) as transaction_count
    FROM cash_book cb
    WHERE $whereClause
    GROUP BY DATE(cb.transaction_date)
    ORDER BY date ASC
", $params);

// Calculate totals and running profit
$grandIncome = 0;
$grandExpense = 0;
$grandNet = 0;
$grandTransactions = 0;

// Re-index to sort by date ASC properly for running profit
foreach ($dailySummary as &$day) {
    $grandIncome += $day['total_income'];
    $grandExpense += $day['total_expense'];
    $grandNet += $day['net_balance'];
    $grandTransactions += $day['transaction_count'];
    
    // Add cumulative profit to row
    $runningBalance += $day['net_balance'];
    $day['closing_balance'] = $runningBalance;
}
unset($day);

// If only 1 day is selected, showing "total net" is less useful than "closing balance"
// We will sort back to DESC for display if needed, but usually reports are ASC or DESC
// Original query was DESC, let's keep it DESC for the table or just array_reverse
$dailySummaryDisplay = array_reverse($dailySummary); // Newest first

// ============================================
// GET DETAIL TRANSACTIONS FOR PRINT
// ============================================

// Get income details from cash book - Exclude Owner Capital
$incomeDetails = $db->fetchAll("
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.transaction_time,
        cb.description,
        cb.amount,
        d.division_name,
        c.category_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    WHERE $whereClauseWithExclusion AND cb.transaction_type = 'income'
    ORDER BY cb.transaction_date, cb.transaction_time
", $params);

// Get expense details from cash book (Modified to join PO/Supplier and Items)
$expenseDetails = $db->fetchAll("
    SELECT 
        cb.id,
        cb.transaction_date,
        cb.transaction_time,
        cb.description,
        cb.amount,
        d.division_name,
        c.category_name,
        'cashbook' as source,
        cb.source_type,
        cb.reference_no,
        s.supplier_name,
        poh.po_number,
        GROUP_CONCAT(CONCAT(pod.item_name, ' (', pod.quantity, ' ', pod.unit_of_measure, ')') SEPARATOR ', ') as po_items
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN purchase_orders_header poh ON cb.source_type = 'purchase_order' AND cb.reference_no = poh.po_number
    LEFT JOIN suppliers s ON poh.supplier_id = s.id
    LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
    WHERE $whereClause AND cb.transaction_type = 'expense'
    GROUP BY cb.id
    ORDER BY cb.transaction_date, cb.transaction_time
", $params);

// PO details logic removed/updated as we now use cash_book direct linkage
$purchaseDetails = []; 
// If you still have standalone purchases not in cashbook (unlikely now), keep logic, else ignore.
// We merged everything into expenseDetails above.

$allExpenseDetails = $expenseDetails;

// Sort by date and time
usort($allExpenseDetails, function($a, $b) {
    $dateCompare = strcmp($a['transaction_date'], $b['transaction_date']);
    if ($dateCompare === 0) {
        return strcmp($a['transaction_time'], $b['transaction_time']);
    }
    return $dateCompare;
});

include '../../includes/header.php';

// Get company info for print
$company = getCompanyInfo();
$dateRangeText = date('d M Y', strtotime($start_date)) . ' - ' . date('d M Y', strtotime($end_date));

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

<script>
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
        
        doc.save('Laporan-Harian-<?php echo date("Y-m-d"); ?>.pdf');
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
</script>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.5rem;">
        <form method="GET" id="filterForm" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto auto; gap: 1rem; align-items: end;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Dari Tanggal</label>
                <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
            </div>
            
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Sampai Tanggal</label>
                <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
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
            
            <button type="button" onclick="setToday()" class="btn btn-info" style="height: 42px; color: white;">
                <i data-feather="calendar" style="width: 16px; height: 16px;"></i> Hari Ini
            </button>
        </form>
        <script>
        function setToday() {
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;
            
            document.getElementById('start_date').value = todayStr;
            document.getElementById('end_date').value = todayStr;
            document.getElementById('filterForm').submit();
        }
        </script>
    </div>

<!-- Laporan Harian Summary -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.65rem; margin-bottom: 1.25rem;">
    
    <!-- Saldo Cash & Bank -->
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #6366f1; margin: 0;">
        <div style="font-size: 0.65rem; color: #6366f1; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">🏦 Saldo Cash & Bank</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: <?php echo $saldoCashBank >= 0 ? '#4f46e5' : '#dc2626'; ?>;">
            <?php echo formatCurrency($saldoCashBank); ?>
        </div>
    </div>
    
    <!-- Saldo Petty Cash Saat Ini -->
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #f59e0b; margin: 0;">
        <div style="font-size: 0.65rem; color: #d97706; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">💵 Saldo Cash Saat Ini</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #d97706;">
            <?php echo formatCurrency($pettyCashBalance); ?>
        </div>
    </div>
    
    <!-- Sisa dari Owner -->
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #8b5cf6; margin: 0;">
        <div style="font-size: 0.65rem; color: #7c3aed; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">👤 Sisa Owner</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #7c3aed;">
            <?php echo formatCurrency($sisaOwner); ?>
        </div>
    </div>
    
    <!-- Pemasukan -->
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #10b981; margin: 0;">
        <div style="font-size: 0.65rem; color: #059669; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">📈 Pemasukan</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #059669;">
            <?php echo formatCurrency($filteredIncome); ?>
        </div>
    </div>
    
    <!-- Pengeluaran -->
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #ef4444; margin: 0;">
        <div style="font-size: 0.65rem; color: #dc2626; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">📉 Pengeluaran</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #dc2626;">
            <?php echo formatCurrency($filteredExpense); ?>
        </div>
    </div>
    
    <!-- Transfer dari Owner - ONLY if > 0 -->
    <?php if ($ownerTransferFiltered > 0): ?>
    <div class="card" style="padding: 0.85rem 1rem; border-left: 4px solid #0ea5e9; margin: 0;">
        <div style="font-size: 0.65rem; color: #0284c7; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 0.3rem;">💸 Transfer Owner</div>
        <div style="font-size: 1.35rem; font-weight: 800; color: #0284c7;">
            <?php echo formatCurrency($ownerTransferFiltered); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Old Summary Cards - Hidden -->
<div style="display: none;">
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
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Profit / Laba Bersih</div>
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
    
    <!-- TOTAL KAS OPERASIONAL (Same logic as dashboard) -->
    <div class="card" style="padding: 1rem; border-left: 4px solid #3b82f6; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);">
        <div style="font-size: 0.75rem; color: #2563eb; margin-bottom: 0.5rem; font-weight: 600;">💰 TOTAL KAS OPERASIONAL</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: #1d4ed8;">
            <?php echo formatCurrency($pettyCashBalance + $ownerCapitalBalance); ?>
        </div>
        <div style="font-size: 0.7rem; color: #2563eb; margin-top: 5px;">(Petty Cash + Modal Owner)</div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.5rem;">Total Transaksi</div>
        <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color);">
            <?php echo number_format($grandTransactions); ?>
        </div>
    </div>
</div>
</div>

<!-- Daily Summary Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0 0.5rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 1rem;">
        <h3 style="font-size: 0.95rem; color: var(--text-primary); font-weight: 600;">
            📊 Ringkasan Per Hari
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
            <table class="table" id="dailyTable">
                        <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Hari</th>
                        <th class="text-right">Pemasukan</th>
                        <th class="text-right">Pengeluaran</th>
                        <th class="text-right">Profit</th>
                        <th class="text-center">Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailySummaryDisplay as $day): ?>
                        <tr>
                            <td style="font-weight: 600; font-size: 0.813rem;">
                                <?php echo date('d/m/Y', strtotime($day['date'])); ?>
                            </td>
                            <td style="font-size: 0.813rem;">
                                <?php 
                                $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                                echo $dayNames[date('w', strtotime($day['date']))]; 
                                ?>
                            </td>
                            <td class="text-right" style="font-weight: 700; font-size: 0.875rem; color: var(--success);">
                                <?php echo formatCurrency($day['total_income']); ?>
                            </td>
                            <td class="text-right" style="font-weight: 700; font-size: 0.875rem; color: var(--danger);">
                                <?php echo formatCurrency($day['total_expense']); ?>
                            </td>
                            <td class="text-right" style="font-weight: 800; font-size: 0.938rem; color: <?php echo $day['closing_balance'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo formatCurrency($day['closing_balance']); ?>
                            </td>
                            <td class="text-center" style="font-size: 0.813rem;">
                                <span style="padding: 0.25rem 0.5rem; background: var(--bg-tertiary); border-radius: 4px;">
                                    <?php echo $day['transaction_count']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--bg-tertiary); font-weight: 800;">
                        <td colspan="2">TOTAL</td>
                        <td class="text-right" style="color: var(--success);">
                            <?php echo formatCurrency($grandIncome); ?>
                        </td>
                        <td class="text-right" style="color: var(--danger);">
                            <?php echo formatCurrency($grandExpense); ?>
                        </td>
                        <td class="text-right" style="font-size: 1rem; color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                            <?php echo formatCurrency($grandNet); ?>
                        </td>
                        <td class="text-center">
                            <?php echo number_format($grandTransactions); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>

<!-- Detail Transaksi Section (Screen View) -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: 1.5rem;">
    
    <!-- Detail Pemasukan -->
    <div class="card">
        <div style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 1rem; border-radius: var(--radius-lg) var(--radius-lg) 0 0; margin: -1px -1px 0 -1px;">
            <h3 style="margin: 0; font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="trending-up" style="width: 20px; height: 20px;"></i>
                💰 DETAIL PEMASUKAN
            </h3>
        </div>
        
        <div style="padding: 1rem;">
            <?php if (!empty($incomeDetails)): ?>
                <div style="max-height: 500px; overflow-y: auto;">
                    <table class="table" style="font-size: 0.813rem;">
                        <thead style="position: sticky; top: 0; background: var(--bg-secondary); z-index: 1;">
                            <tr>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Tanggal</th>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Divisi</th>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Kategori</th>
                                <th class="text-right" style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prevDate = '';
                            foreach ($incomeDetails as $income): 
                                $currentDate = date('d/m/Y', strtotime($income['transaction_date']));
                                $isNewDate = ($currentDate !== $prevDate);
                                $prevDate = $currentDate;
                            ?>
                                <tr style="<?php echo $isNewDate ? 'border-top: 2px solid var(--bg-tertiary);' : ''; ?>">
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <?php if ($isNewDate): ?>
                                            <div style="font-weight: 600; color: var(--text-primary);">
                                                <?php echo $currentDate; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 0.688rem; color: var(--text-muted);">
                                            <?php echo date('H:i', strtotime($income['transaction_time'])); ?>
                                        </div>
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 600; color: var(--text-primary);">
                                            <?php echo $income['division_name'] ?? '-'; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 600; color: var(--text-primary);">
                                            <?php echo $income['category_name'] ?? 'Lainnya'; ?>
                                        </div>
                                        <?php if (!empty($income['description'])): ?>
                                            <div style="font-size: 0.688rem; color: var(--text-muted); margin-top: 0.125rem;">
                                                <?php echo htmlspecialchars(substr($income['description'], 0, 50)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right" style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 700; color: var(--success); font-size: 0.875rem;">
                                            <?php echo formatCurrency($income['amount']); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="position: sticky; bottom: 0; background: var(--bg-secondary); border-top: 2px solid var(--success);">
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right" style="padding: 0.75rem;">TOTAL PEMASUKAN:</td>
                                <td class="text-right" style="padding: 0.75rem; color: var(--success); font-size: 1rem;">
                                    <?php echo formatCurrency($grandIncome); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; color: var(--text-muted);"></i>
                    <p>Tidak ada data pemasukan untuk periode ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Detail Pengeluaran -->
    <div class="card">
        <div style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 1rem; border-radius: var(--radius-lg) var(--radius-lg) 0 0; margin: -1px -1px 0 -1px;">
            <h3 style="margin: 0; font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="trending-down" style="width: 20px; height: 20px;"></i>
                💸 DETAIL PENGELUARAN
            </h3>
        </div>
        
        <div style="padding: 1rem;">
            <?php if (!empty($allExpenseDetails)): ?>
                <div style="max-height: 500px; overflow-y: auto;">
                    <table class="table" style="font-size: 0.813rem;">
                        <thead style="position: sticky; top: 0; background: var(--bg-secondary); z-index: 1;">
                            <tr>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Tanggal</th>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Divisi</th>
                                <th style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Kategori/Item</th>
                                <th class="text-right" style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prevDate = '';
                            foreach ($allExpenseDetails as $expense): 
                                $currentDate = date('d/m/Y', strtotime($expense['transaction_date']));
                                $isNewDate = ($currentDate !== $prevDate);
                                $prevDate = $currentDate;
                                $isPO = (isset($expense['source']) && $expense['source'] === 'purchase_order');
                            ?>
                                <tr style="<?php echo $isNewDate ? 'border-top: 2px solid var(--bg-tertiary);' : ''; ?>">
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <?php if ($isNewDate): ?>
                                            <div style="font-weight: 600; color: var(--text-primary);">
                                                <?php echo $currentDate; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$isPO): ?>
                                            <div style="font-size: 0.688rem; color: var(--text-muted);">
                                                <?php echo date('H:i', strtotime($expense['transaction_time'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 600; color: var(--text-primary);">
                                            <?php echo $expense['division_name'] ?? '-'; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.25rem;">
                                            <?php if ($isPO): ?>
                                                <span style="background: #f59e0b; color: white; padding: 0.125rem 0.375rem; border-radius: 3px; font-size: 0.625rem; font-weight: 700;">PO</span>
                                            <?php endif; ?>
                                            <?php echo $expense['category_name'] ?? 'Lainnya'; ?>
                                        </div>
                                        <?php if (!empty($expense['description']) || !empty($expense['po_items'])): ?>
                                            <div style="font-size: 0.688rem; color: var(--text-muted); margin-top: 0.125rem;">
                                                <?php 
                                                if ($isPO && !empty($expense['po_items'])) {
                                                    echo '<span style="color: #4b5563;">' . htmlspecialchars(mb_strimwidth($expense['po_items'], 0, 60, "...")) . '</span>';
                                                } elseif ($isPO) {
                                                    echo htmlspecialchars(substr($expense['description'], 0, 40));
                                                    if (isset($expense['quantity'])) {
                                                        echo ' <span style="color: var(--text-primary); font-weight: 600;">(' . $expense['quantity'] . ' pcs @ ' . formatCurrency($expense['unit_price']) . ')</span>';
                                                    }
                                                } else {
                                                    echo htmlspecialchars(substr($expense['description'], 0, 50));
                                                }
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isPO && !empty($expense['supplier_name'])): ?>
                                            <div style="font-size: 0.688rem; color: var(--text-muted); margin-top: 0.25rem; display: flex; align-items: center; gap: 0.25rem;">
                                                <span style="color: #f59e0b;">📦</span>
                                                Supplier: <strong><?php echo htmlspecialchars($expense['supplier_name']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isPO && !empty($expense['invoice_number'])): ?>
                                            <div style="font-size: 0.625rem; color: var(--text-muted); margin-top: 0.125rem;">
                                                Invoice: <?php echo htmlspecialchars($expense['invoice_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right" style="padding: 0.5rem 0.75rem; vertical-align: top;">
                                        <div style="font-weight: 700; color: var(--danger); font-size: 0.875rem;">
                                            <?php echo formatCurrency($expense['amount']); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot style="position: sticky; bottom: 0; background: var(--bg-secondary); border-top: 2px solid var(--danger);">
                            <tr style="font-weight: 700;">
                                <td colspan="3" class="text-right" style="padding: 0.75rem;">TOTAL PENGELUARAN:</td>
                                <td class="text-right" style="padding: 0.75rem; color: var(--danger); font-size: 1rem;">
                                    <?php echo formatCurrency($grandExpense); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; color: var(--text-muted);"></i>
                    <p>Tidak ada data pengeluaran untuk periode ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
</div>

<script>
    feather.replace();
    
    // Export to Excel function
    function exportToExcel() {
        const table = document.getElementById('dailyTable');
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
        a.download = 'laporan-harian-<?php echo date('Y-m-d'); ?>.xls';
        a.click();
        window.URL.revokeObjectURL(url);
    }
    
    // Re-initialize feather icons after page load
    document.addEventListener('DOMContentLoaded', function() {
        feather.replace();
    });
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
        
        table {
            page-break-inside: avoid;
        }
        
        tr {
            page-break-inside: avoid;
        }
    }
    
    .report-header {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 3px solid var(--primary-color);
        align-items: flex-start;
    }
    
    .report-header-logo {
        flex-shrink: 0;
        width: 80px;
        height: 80px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-secondary);
        border-radius: 8px;
        font-size: 48px;
    }
    
    .report-header-info {
        flex: 1;
    }
    
    .report-header-title {
        font-size: 28px;
        font-weight: 900;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        letter-spacing: -0.5px;
    }
    
    .report-header-company {
        font-size: 11px;
        color: #666;
        line-height: 1.6;
    }
    
    .report-meta {
        text-align: right;
        min-width: 200px;
    }
    
    .report-meta-item {
        font-size: 12px;
        color: #666;
        margin-bottom: 1rem;
    }
    
    .report-footer {
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid #ddd;
        text-align: center;
        font-size: 10px;
        color: #999;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .summary-card-print {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-color) 100%);
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 1rem;
        text-align: center;
        page-break-inside: avoid;
    }
</style>

<!-- Print-Only Report Section -->
<div id="printContent">
    <div style="width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm 8mm; background: white;">
        <?php echo generateReportHeader('LAPORAN HARIAN', htmlspecialchars($division_id > 0 ? end($divisions)['division_name'] : 'Semua Divisi'), $dateRangeText, $absoluteLogo); ?>
        
        <!-- Summary Cards for Print -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.3rem; margin-bottom: 0.6rem;">
            <?php
            echo generateSummaryCard('💰 TOTAL PEMASUKAN', formatCurrency($grandIncome), '#10b981', '');
            echo generateSummaryCard('💸 TOTAL PENGELUARAN', formatCurrency($grandExpense), '#ef4444', '');
            echo generateSummaryCard('📊 PROFIT / LABA BERSIH', formatCurrency($grandNet), $grandNet >= 0 ? '#3b82f6' : '#ef4444', '');
            echo generateSummaryCard('📈 TOTAL TRANSAKSI', number_format($grandTransactions), '#8b5cf6', '');
            ?>
        </div>
        
        <!-- Daily Summary Table -->
        <h2 style="font-size: 11px; font-weight: 700; color: #1f2937; margin-bottom: 0.4rem; page-break-after: avoid;">
            Ringkasan Per Hari
        </h2>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 0.6rem; font-size: 10px;">
            <thead>
                <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 700;">
                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #d1d5db;">Tanggal</th>
                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #d1d5db;">Hari</th>
                    <th style="padding: 4px 5px; text-align: right; border-bottom: 1px solid #d1d5db;">Pemasukan</th>
                    <th style="padding: 4px 5px; text-align: right; border-bottom: 1px solid #d1d5db;">Pengeluaran</th>
                    <th style="padding: 4px 5px; text-align: right; border-bottom: 1px solid #d1d5db;">Profit</th>
                    <th style="padding: 4px 5px; text-align: center; border-bottom: 1px solid #d1d5db;">Transaksi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                $rowCount = 0;
                foreach ($dailySummary as $day): 
                    $bgColor = ($rowCount % 2 === 0) ? '#f9fafb' : '#ffffff';
                    $closingColor = ($day['closing_balance'] >= 0) ? '#3b82f6' : '#ef4444'; // Use blue for positive balance like screen
                    $rowCount++;
                ?>
                    <tr style="background: <?php echo $bgColor; ?>;">
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
                            <?php echo date('d/m/Y', strtotime($day['date'])); ?>
                        </td>
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb;">
                            <?php echo $dayNames[date('w', strtotime($day['date']))]; ?>
                        </td>
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #10b981; font-weight: 600;">
                            Rp <?php echo number_format($day['total_income'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #ef4444; font-weight: 600;">
                            Rp <?php echo number_format($day['total_expense'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb; text-align: right; color: <?php echo $closingColor; ?>; font-weight: 700;">
                            Rp <?php echo number_format($day['closing_balance'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 6px 9px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                            <?php echo $day['transaction_count']; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 800; font-size: 11px;">
                    <td colspan="2" style="padding: 5px 6px; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db;">TOTAL</td>
                    <td style="padding: 5px 6px; text-align: right; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; color: #10b981;">
                        Rp <?php echo number_format($grandIncome, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 5px 6px; text-align: right; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; color: #ef4444;">
                        Rp <?php echo number_format($grandExpense, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 5px 6px; text-align: right; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db; color: <?php echo $grandNet >= 0 ? '#10b981' : '#ef4444'; ?>;">
                        Rp <?php echo number_format($grandNet, 0, ',', '.'); ?>
                    </td>
                    <td style="padding: 5px 6px; text-align: center; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db;">
                        <?php echo $grandTransactions; ?>
                    </td>
                </tr>
            </tfoot>
        </table>
        
        <!-- DETAIL TRANSAKSI -->
        <div style="margin-top: 1rem; page-break-inside: avoid;">
            <h2 style="font-size: 10px; font-weight: 700; color: #1f2937; margin-bottom: 0.5rem;">
                📋 Detail Transaksi Periode
            </h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                
                <!-- Detail Pemasukan -->
                <div style="border: 2px solid #10b981; border-radius: 6px; background: #f0fdf4; padding: 0.4rem;">
                    <div style="background: #10b981; color: white; padding: 0.3rem 0.5rem; margin: -0.4rem -0.4rem 0.4rem -0.4rem; border-radius: 4px 4px 0 0;">
                        <h3 style="margin: 0; font-size: 12px; font-weight: 700;">💰 DETAIL PEMASUKAN</h3>
                    </div>
                    
                    <?php if (!empty($incomeDetails)): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                            <thead>
                                <tr style="background: rgba(16, 185, 129, 0.1);">
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #10b981; font-size: 9px;">Tanggal</th>
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #10b981; font-size: 9px;">Divisi</th>
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #10b981; font-size: 9px;">Kategori</th>
                                    <th style="padding: 4px 5px; text-align: right; border-bottom: 1px solid #10b981; font-size: 9px; width: 65px;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomeDetails as $income): ?>
                                    <tr>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #dcfce7; font-size: 10px;">
                                            <?php echo date('d/m', strtotime($income['transaction_date'])); ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #dcfce7; font-size: 10px;">
                                            <?php echo substr($income['division_name'] ?? '-', 0, 10); ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #dcfce7; font-size: 10px;">
                                            <div style="font-weight: 600;"><?php echo substr($income['category_name'] ?? 'Lainnya', 0, 12); ?></div>
                                            <?php if (!empty($income['description'])): ?>
                                                <div style="color: #666; font-size: 8.5px;"><?php echo substr($income['description'], 0, 25); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #dcfce7; text-align: right; font-weight: 600; color: #10b981; font-size: 10px;">
                                            <?php echo number_format($income['amount'], 0, ',', '.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background: rgba(16, 185, 129, 0.15); font-weight: bold;">
                                    <td colspan="3" style="padding: 5px 6px; text-align: right; border-top: 2px solid #10b981; font-size: 11px;">TOTAL PEMASUKAN:</td>
                                    <td style="padding: 5px 6px; text-align: right; color: #10b981; border-top: 2px solid #10b981; font-size: 11px;">
                                        <?php echo number_format($grandIncome, 0, ',', '.'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 1rem; color: #999; font-size: 7px;">Tidak ada pemasukan</div>
                    <?php endif; ?>
                </div>
                
                <!-- Detail Pengeluaran -->
                <div style="border: 2px solid #ef4444; border-radius: 6px; background: #fef2f2; padding: 0.4rem;">
                    <div style="background: #ef4444; color: white; padding: 0.3rem 0.5rem; margin: -0.4rem -0.4rem 0.4rem -0.4rem; border-radius: 4px 4px 0 0;">
                        <h3 style="margin: 0; font-size: 12px; font-weight: 700;">💸 DETAIL PENGELUARAN</h3>
                    </div>
                    
                    <?php if (!empty($allExpenseDetails)): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                            <thead>
                                <tr style="background: rgba(239, 68, 68, 0.1);">
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #ef4444; font-size: 9px;">Tanggal</th>
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #ef4444; font-size: 9px;">Divisi</th>
                                    <th style="padding: 4px 5px; text-align: left; border-bottom: 1px solid #ef4444; font-size: 9px;">Kategori/Item</th>
                                    <th style="padding: 4px 5px; text-align: right; border-bottom: 1px solid #ef4444; font-size: 9px; width: 65px;">Jumlah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allExpenseDetails as $expense): ?>
                                    <tr>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #fee2e2; font-size: 10px;">
                                            <?php echo date('d/m', strtotime($expense['transaction_date'])); ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #fee2e2; font-size: 10px;">
                                            <?php echo substr($expense['division_name'] ?? '-', 0, 10); ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #fee2e2; font-size: 10px;">
                                            <div style="font-weight: 600;"><?php echo substr($expense['category_name'] ?? 'Lainnya', 0, 12); ?></div>
                                            <?php if (!empty($expense['description']) || !empty($expense['po_items'])): ?>
                                                <div style="color: #666; font-size: 8.5px;">
                                                    <?php 
                                                    // PO Details logic
                                                    if (isset($expense['source_type']) && $expense['source_type'] == 'purchase_order') {
                                                         if (!empty($expense['supplier_name'])) {
                                                            echo 'Splr: ' . substr($expense['supplier_name'], 0, 12) . '<br>';
                                                         }
                                                         if (!empty($expense['po_items'])) {
                                                             echo substr($expense['po_items'], 0, 40);
                                                         } else {
                                                             echo substr($expense['description'], 0, 30);
                                                         }
                                                    } else {
                                                         echo substr($expense['description'], 0, 20);
                                                    }
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 4px 5px; border-bottom: 1px solid #fee2e2; text-align: right; font-weight: 600; color: #ef4444; font-size: 10px;">
                                            <?php echo number_format($expense['amount'], 0, ',', '.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background: rgba(239, 68, 68, 0.15); font-weight: bold;">
                                    <td colspan="3" style="padding: 5px 6px; text-align: right; border-top: 2px solid #ef4444; font-size: 11px;">TOTAL PENGELUARAN:</td>
                                    <td style="padding: 5px 6px; text-align: right; color: #ef4444; border-top: 2px solid #ef4444; font-size: 11px;">
                                        <?php echo number_format($grandExpense, 0, ',', '.'); ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 1rem; color: #999; font-size: 7px;">Tidak ada pengeluaran</div>
                    <?php endif; ?>
                </div>
                
            </div>
        </div>
        
        <?php echo generateSignatureSection(); ?>
        <?php echo generateReportFooter($currentUser['full_name'] ?? $currentUser['user_name'] ?? 'Administrator'); ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
