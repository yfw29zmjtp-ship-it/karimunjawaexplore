<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

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

$pageTitle = 'Laporan Tahunan';

// Get filter parameters
$start_year = isset($_GET['start_year']) ? (int)$_GET['start_year'] : date('Y') - 4;
$end_year = isset($_GET['end_year']) ? (int)$_GET['end_year'] : date('Y');
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

// Get all divisions
$divisions = $db->fetchAll("SELECT * FROM divisions ORDER BY division_name");

// Build WHERE clause
$whereConditions = ["YEAR(cb.transaction_date) BETWEEN :start_year AND :end_year"];
$params = ['start_year' => $start_year, 'end_year' => $end_year];

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

// Get yearly summary - Exclude owner capital from income AND expense
$yearlySummary = $db->fetchAll("
    SELECT 
        YEAR(cb.transaction_date) as year,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as total_expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) - 
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense'{$ownerCapitalExcludeCondition} THEN cb.amount ELSE 0 END), 0) as net_balance,
        COUNT(*) as transaction_count
    FROM cash_book cb
    WHERE $whereClause
    GROUP BY YEAR(cb.transaction_date)
    ORDER BY year
", $params);

// Calculate totals
$grandIncome = 0;
$grandExpense = 0;
$grandNet = 0;
$grandTransactions = 0;

foreach ($yearlySummary as $yearly) {
    $grandIncome += $yearly['total_income'];
    $grandExpense += $yearly['total_expense'];
    $grandNet += $yearly['net_balance'];
    $grandTransactions += $yearly['transaction_count'];
}

// Get company info for print
$company = getCompanyInfo();
$dateRangeText = 'Tahun ' . $start_year . ' - ' . $end_year;

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

include '../../includes/header.php';
?>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.5rem;">
    <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tahun Mulai</label>
                <select name="start_year" class="form-control" required>
                    <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $start_year == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Tahun Akhir</label>
            <select name="end_year" class="form-control" required>
                <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $end_year == $y ? 'selected' : ''; ?>>
                        <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
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
        </form>
    </div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
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
                <i data-feather="activity" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.25rem;">Saldo Bersih</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: <?php echo $grandNet >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    Rp <?php echo number_format($grandNet, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Saldo Petty Cash (Kas Besar) -->
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: #10b981; display: flex; align-items: center; justify-content: center;">
                <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: #059669; margin-bottom: 0.25rem;">💵 Saldo Petty Cash</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: #059669;">
                    Rp <?php echo number_format($pettyCashBalance, 0, ',', '.'); ?>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-muted);">(Kas Besar - Operasional)</div>
            </div>
        </div>
    </div>

    <!-- Saldo Modal Owner -->
    <div class="card" style="padding: 1.25rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05));">
        <div style="display: flex; align-items: center; gap: 0.875rem;">
            <div style="width: 48px; height: 48px; border-radius: var(--radius-lg); background: #f59e0b; display: flex; align-items: center; justify-content: center;">
                <i data-feather="trending-up" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-size: 0.813rem; color: #d97706; margin-bottom: 0.25rem;">🔥 Saldo Modal Owner</div>
                <div style="font-size: 1.375rem; font-weight: 700; color: #d97706;">
                    Rp <?php echo number_format($ownerCapitalBalance, 0, ',', '.'); ?>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-muted);">(Untuk Expense Operasional)</div>
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
                    <?php echo number_format($grandTransactions, 0, ',', '.'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Data Table -->
<div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                Rekap Per Tahun (<?php echo $start_year; ?> - <?php echo $end_year; ?>)
            </h3>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="exportToPDF()" class="btn btn-danger btn-sm">
                    <i data-feather="file-text" style="width: 14px; height: 14px;"></i> Export PDF
                </button>
                <button onclick="window.print()" class="btn btn-secondary btn-sm">
                    <i data-feather="printer" style="width: 14px; height: 14px;"></i> Print
                </button>
            </div>
        </div>
    
    <?php if (empty($yearlySummary)): ?>
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
            <p>Tidak ada data untuk tahun yang dipilih</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 10%;">Tahun</th>
                        <th style="text-align: right;">Pemasukan</th>
                        <th style="text-align: right;">Pengeluaran</th>
                        <th style="text-align: right;">Saldo Bersih</th>
                        <th style="text-align: center; width: 15%;">Jumlah Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yearlySummary as $yearly): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--text-primary);">
                                <?php echo $yearly['year']; ?>
                            </td>
                            <td style="text-align: right; color: var(--success); font-weight: 600;">
                                Rp <?php echo number_format($yearly['total_income'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; color: var(--danger); font-weight: 600;">
                                Rp <?php echo number_format($yearly['total_expense'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: <?php echo $yearly['net_balance'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                Rp <?php echo number_format($yearly['net_balance'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: center; color: var(--text-muted);">
                                <?php echo number_format($yearly['transaction_count'], 0, ',', '.'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="border-top: 2px solid var(--bg-tertiary);">
                    <tr style="background: var(--bg-tertiary);">
                        <td style="font-weight: 700; color: var(--text-primary);">TOTAL</td>
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
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
    </div>

<script>
    feather.replace();
    
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
            
            doc.save('Laporan-Tahunan-<?php echo $start_year; ?>-<?php echo $end_year; ?>.pdf');
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

    function confirmPDFDownload() {
        if (!window.pdfPreviewCanvas) {
            alert('Preview tidak siap. Silakan coba lagi.');
            return;
        }
        
        const filename = 'Laporan-Tahunan-<?php echo $start_year; ?>-<?php echo $end_year; ?>.pdf';
        
        if (typeof jsPDF === 'undefined' || typeof html2pdf === 'undefined') {
            alert('Library PDF belum dimuat lengkap. Menggunakan Print...');
            window.print();
            closePDFPreview();
            return;
        }
        
        try {
            const canvas = window.pdfPreviewCanvas;
            const imgData = canvas.toDataURL('image/jpeg', 0.95);
            const doc = new jsPDF({
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
</style>

<!-- Print-Only Report Section -->
<div id="printContent">
    <div style="width: 210mm; min-height: 297mm; margin: 0 auto; padding: 8mm 8mm; background: white;">
        <?php echo generateReportHeader('LAPORAN TAHUNAN', 'Tahun ' . $start_year . ' - ' . $end_year . ($division_id > 0 ? ' - ' . end($divisions)['division_name'] : ''), $dateRangeText, $absoluteLogo); ?>
        
        <!-- Summary Cards for Print -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.3rem; margin-bottom: 0.6rem;">
            <?php
            echo generateSummaryCard('💰 TOTAL PEMASUKAN', formatCurrency($grandIncome), '#10b981', '');
            echo generateSummaryCard('💸 TOTAL PENGELUARAN', formatCurrency($grandExpense), '#ef4444', '');
            echo generateSummaryCard('📊 NET BALANCE', formatCurrency($grandNet), $grandNet >= 0 ? '#3b82f6' : '#f59e0b', '');
            echo generateSummaryCard('📈 TOTAL TRANSAKSI', number_format($grandTransactions), '#8b5cf6', '');
            ?>
        </div>
        
        <!-- Yearly Summary Table -->
        <h2 style="font-size: 10px; font-weight: 700; color: #1f2937; margin-bottom: 0.4rem; page-break-after: avoid;">
            Ringkasan Per Tahun
        </h2>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 0.6rem; font-size: 8px;">
            <thead>
                <tr style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); font-weight: 700;">
                    <th style="padding: 6px 8px; text-align: left; border-bottom: 1.5px solid #d1d5db;">Tahun</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Pemasukan</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Pengeluaran</th>
                    <th style="padding: 6px 8px; text-align: right; border-bottom: 1.5px solid #d1d5db;">Net Balance</th>
                    <th style="padding: 6px 8px; text-align: center; border-bottom: 1.5px solid #d1d5db;">Transaksi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rowCount = 0;
                foreach ($yearlySummary as $yearly): 
                    $bgColor = ($rowCount % 2 === 0) ? '#f9fafb' : '#ffffff';
                    $netColor = ($yearly['net_balance'] >= 0) ? '#10b981' : '#ef4444';
                    $rowCount++;
                ?>
                    <tr style="background: <?php echo $bgColor; ?>;">
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">
                            <?php echo $yearly['year']; ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #10b981; font-weight: 600;">
                            Rp <?php echo number_format($yearly['total_income'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: #ef4444; font-weight: 600;">
                            Rp <?php echo number_format($yearly['total_expense'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: right; color: <?php echo $netColor; ?>; font-weight: 700;">
                            Rp <?php echo number_format($yearly['net_balance'], 0, ',', '.'); ?>
                        </td>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #e5e7eb; text-align: center;">
                            <?php echo $yearly['transaction_count']; ?>
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
