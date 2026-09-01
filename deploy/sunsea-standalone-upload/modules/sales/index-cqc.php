<?php
/**
 * CQC Faktur Termin - Sales Invoices for Contractor Progress Billing
 * Theme: Navy + Gold (CQC Engineering)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Get CQC database connection
require_once '../cqc-projects/db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
    // Ensure termin table exists
    ensureCQCTerminTable($pdo);
    ensureCQCGeneralInvoiceTable($pdo);
    ensureCQCQuotationTable($pdo);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get active tab (termin or general)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'termin';

// Get filters
$payment_status = isset($_GET['status']) ? $_GET['status'] : '';
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Get projects for filter
$projects = $pdo->query("SELECT id, project_code, project_name, client_name FROM cqc_projects ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause
$where = ["ti.invoice_date BETWEEN :date_from AND :date_to"];
$params = ['date_from' => $date_from, 'date_to' => $date_to];

if ($payment_status) {
    $where[] = "ti.payment_status = :payment_status";
    $params['payment_status'] = $payment_status;
}

if ($project_id > 0) {
    $where[] = "ti.project_id = :project_id";
    $params['project_id'] = $project_id;
}

$whereClause = implode(' AND ', $where);

// Get termin invoices
$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            ti.*,
            p.project_code,
            p.project_name,
            p.client_name,
            p.client_phone,
            p.solar_capacity_kwp
        FROM cqc_termin_invoices ti
        LEFT JOIN cqc_projects p ON ti.project_id = p.id
        WHERE $whereClause
        ORDER BY ti.invoice_date DESC, ti.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Calculate stats
$stats = [
    'total_invoices' => count($invoices),
    'total_amount' => array_sum(array_column($invoices, 'total_amount')),
    'paid_amount' => array_sum(array_map(function($inv) {
        return $inv['payment_status'] === 'paid' ? floatval($inv['total_amount']) : 0;
    }, $invoices)),
    'unpaid_amount' => array_sum(array_map(function($inv) {
        return in_array($inv['payment_status'], ['draft', 'sent', 'overdue']) ? floatval($inv['total_amount']) : 0;
    }, $invoices))
];

// Get general invoices for "Invoice Umum" tab
$generalInvoices = [];
try {
    $genWhere = ["invoice_date BETWEEN :date_from AND :date_to"];
    $genParams = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($payment_status) {
        $genWhere[] = "payment_status = :payment_status";
        $genParams['payment_status'] = $payment_status;
    }
    
    $genWhereClause = implode(' AND ', $genWhere);
    
    $stmtGen = $pdo->prepare("
        SELECT * FROM cqc_general_invoices
        WHERE $genWhereClause
        ORDER BY invoice_date DESC, created_at DESC
        LIMIT 100
    ");
    $stmtGen->execute($genParams);
    $generalInvoices = $stmtGen->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Calculate stats for general invoices
$generalStats = [
    'total_invoices' => count($generalInvoices),
    'total_amount' => array_sum(array_column($generalInvoices, 'total_amount')),
    'paid_amount' => array_sum(array_map(function($inv) {
        return $inv['payment_status'] === 'paid' ? floatval($inv['total_amount']) : 0;
    }, $generalInvoices)),
    'unpaid_amount' => array_sum(array_map(function($inv) {
        return in_array($inv['payment_status'], ['draft', 'sent', 'overdue']) ? floatval($inv['total_amount']) : 0;
    }, $generalInvoices))
];

// Get quotations for "Quotation" tab
$quotations = [];
try {
    $quotWhere = ["quote_date BETWEEN :date_from AND :date_to"];
    $quotParams = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($payment_status) {
        $quotWhere[] = "status = :status";
        $quotParams['status'] = $payment_status;
    }
    
    $quotWhereClause = implode(' AND ', $quotWhere);
    
    $stmtQuot = $pdo->prepare("
        SELECT * FROM cqc_quotations
        WHERE $quotWhereClause
        ORDER BY quote_date DESC, created_at DESC
        LIMIT 100
    ");
    $stmtQuot->execute($quotParams);
    $quotations = $stmtQuot->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Calculate stats for quotations
$quotationStats = [
    'total_invoices' => count($quotations),
    'total_amount' => array_sum(array_column($quotations, 'total_amount')),
    'paid_amount' => array_sum(array_map(function($q) {
        return $q['status'] === 'approved' ? floatval($q['total_amount']) : 0;
    }, $quotations)),
    'unpaid_amount' => array_sum(array_map(function($q) {
        return in_array($q['status'], ['draft', 'sent']) ? floatval($q['total_amount']) : 0;
    }, $quotations))
];

// Use stats based on active tab
$displayStats = $activeTab === 'general' ? $generalStats : ($activeTab === 'quotation' ? $quotationStats : $stats);

$pageTitle = "Invoice CQC";
include '../../includes/header.php';
?>

<style>
    /* CQC Theme: Navy + Gold */
    :root {
        --cqc-primary: #0d1f3c;
        --cqc-primary-light: #1a3a5c;
        --cqc-accent: #f0b429;
        --cqc-accent-dark: #d4960d;
        --cqc-success: #10b981;
        --cqc-warning: #f59e0b;
        --cqc-danger: #ef4444;
        --cqc-text: #0d1f3c;
        --cqc-muted: #64748b;
        --cqc-border: #e2e8f0;
        --cqc-bg: #f8fafc;
    }

    .cqc-container { 
        max-width: 100%; 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }

    /* Header */
    .cqc-header {
        background: #fff;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 16px;
        display: flex; justify-content: space-between; align-items: center;
        border: 1px solid var(--cqc-border);
        border-left: 4px solid var(--cqc-accent);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }
    .cqc-header h1 { 
        font-size: 18px; font-weight: 700; color: var(--cqc-primary); 
        margin: 0 0 4px; letter-spacing: -0.3px;
    }
    .cqc-header p { font-size: 12px; margin: 0; color: var(--cqc-muted); font-weight: 500; }
    .cqc-header .btn-create {
        background: var(--cqc-accent); color: var(--cqc-primary); border: none;
        padding: 10px 18px; border-radius: 8px; font-weight: 700;
        font-size: 12px; cursor: pointer; transition: all 0.2s;
        display: flex; align-items: center; gap: 6px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        text-decoration: none;
    }
    .cqc-header .btn-create:hover { background: #ffc942; transform: translateY(-1px); }

    /* Stats Grid */
    .cqc-stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; margin-bottom: 12px; }
    .cqc-stat-card {
        background: #fff; padding: 10px 12px; border-radius: 8px;
        border: 1px solid var(--cqc-border);
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        position: relative; overflow: hidden;
    }
    .cqc-stat-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
        background: var(--cqc-border);
    }
    .cqc-stat-card.accent::before { background: var(--cqc-accent); }
    .cqc-stat-card.success::before { background: var(--cqc-success); }
    .cqc-stat-card.danger::before { background: var(--cqc-danger); }
    
    .cqc-stat-icon { 
        width: 24px; height: 24px; border-radius: 6px; 
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; margin-bottom: 6px; background: var(--cqc-bg);
    }
    .cqc-stat-label { 
        font-size: 10px; color: var(--cqc-muted); text-transform: uppercase; 
        font-weight: 600; letter-spacing: 0.5px; margin-bottom: 2px; 
    }
    .cqc-stat-value { 
        font-size: 16px; font-weight: 700; color: var(--cqc-primary); 
        letter-spacing: -0.5px; line-height: 1;
    }

    /* Filter Card */
    .cqc-filter-card {
        background: #fff; padding: 16px; border-radius: 10px;
        border: 1px solid var(--cqc-border); margin-bottom: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .cqc-filter-grid {
        display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 12px; align-items: end;
    }
    .cqc-filter-label { font-size: 11px; font-weight: 600; color: var(--cqc-muted); margin-bottom: 6px; text-transform: uppercase; }
    .cqc-filter-select, .cqc-filter-input {
        width: 100%; padding: 8px 12px; border: 1px solid var(--cqc-border);
        border-radius: 6px; font-size: 12px; color: var(--cqc-text);
        background: #fff; transition: all 0.15s;
    }
    .cqc-filter-select:focus, .cqc-filter-input:focus {
        border-color: var(--cqc-accent); outline: none;
        box-shadow: 0 0 0 3px rgba(240,180,41,0.15);
    }
    .cqc-filter-btn {
        background: var(--cqc-primary); color: #fff; border: none;
        padding: 8px 20px; border-radius: 6px; font-weight: 600;
        font-size: 12px; cursor: pointer; transition: all 0.2s;
        display: flex; align-items: center; gap: 6px;
    }
    .cqc-filter-btn:hover { background: var(--cqc-primary-light); }

    /* Table */
    .cqc-table-card {
        background: #fff; border-radius: 10px; overflow: hidden;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        border: 1px solid var(--cqc-border);
    }
    .cqc-table { width: 100%; border-collapse: collapse; }
    .cqc-table th {
        background: var(--cqc-bg); color: var(--cqc-primary);
        padding: 12px 14px; text-align: left;
        font-weight: 700; font-size: 11px; text-transform: uppercase; 
        letter-spacing: 0.4px; border-bottom: 2px solid var(--cqc-accent);
    }
    .cqc-table td {
        padding: 12px 14px; border-bottom: 1px solid #f1f5f9;
        font-size: 12px; color: var(--cqc-text);
    }
    .cqc-table tr:hover { background: #fafbfc; }
    .cqc-table tr:last-child td { border-bottom: none; }

    /* Status Badges */
    .cqc-status { 
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 700; 
        text-transform: uppercase; letter-spacing: 0.3px;
    }
    .cqc-status-draft { background: #f1f5f9; color: #475569; }
    .cqc-status-sent { background: #dbeafe; color: #1d4ed8; }
    .cqc-status-paid { background: #dcfce7; color: #15803d; }
    .cqc-status-partial { background: #fef3c7; color: #b45309; }
    .cqc-status-overdue { background: #fee2e2; color: #dc2626; }

    /* Action Buttons */
    .cqc-actions { display: flex; gap: 4px; }
    .cqc-action-btn {
        padding: 5px 8px; background: #fff; color: var(--cqc-text);
        border-radius: 5px; text-decoration: none; font-size: 10px; font-weight: 600;
        border: 1px solid var(--cqc-border); transition: all 0.15s;
    }
    .cqc-action-btn:hover { background: var(--cqc-bg); border-color: var(--cqc-accent); }
    .cqc-action-btn.btn-view { color: #3b82f6; border-color: #bfdbfe; }
    .cqc-action-btn.btn-pay { color: #10b981; border-color: #a7f3d0; }
    .cqc-action-btn.btn-delete { color: #ef4444; border-color: #fecaca; }

    /* Empty State */
    .cqc-empty { text-align: center; padding: 50px 20px; color: var(--cqc-muted); }
    .cqc-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.5; }
    .cqc-empty h3 { font-size: 14px; font-weight: 600; color: var(--cqc-text); margin-bottom: 6px; }
    .cqc-empty p { font-size: 12px; }

    /* Termin Badge */
    .cqc-termin-badge {
        display: inline-flex; align-items: center; justify-content: center;
        width: 28px; height: 28px; border-radius: 50%;
        background: linear-gradient(135deg, var(--cqc-accent), var(--cqc-accent-dark));
        color: var(--cqc-primary); font-weight: 800; font-size: 11px;
    }

    /* Project Code */
    .cqc-project-code {
        font-size: 10px; background: rgba(240,180,41,0.15); 
        padding: 2px 6px; border-radius: 3px; color: var(--cqc-primary);
        font-family: monospace; font-weight: 600;
    }

    @media (max-width: 768px) {
        .cqc-stats-grid { grid-template-columns: repeat(2,1fr); }
        .cqc-filter-grid { grid-template-columns: 1fr; }
    }

    /* Tabs */
    .cqc-tabs { display: flex; gap: 4px; background: var(--cqc-bg); padding: 3px; border-radius: 10px; margin-bottom: 12px; border: 1px solid var(--cqc-border); }
    .cqc-tab {
        flex: 1; padding: 8px 14px; border: none; background: transparent;
        border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 12px;
        color: var(--cqc-muted); transition: all 0.2s; text-decoration: none;
        text-align: center; display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .cqc-tab:hover { background: #fff; color: var(--cqc-text); }
    .cqc-tab.active { background: var(--cqc-accent); color: var(--cqc-primary); box-shadow: 0 2px 8px rgba(240,180,41,0.4); font-weight: 800; }
    .cqc-tab .badge { background: rgba(0,0,0,0.08); padding: 2px 6px; border-radius: 4px; font-size: 10px; }
    .cqc-tab.active .badge { background: rgba(13,31,60,0.15); color: var(--cqc-primary); }
</style>

<?php if (isset($_SESSION['success'])): ?>
<div style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 18px;">✅</span>
    <span style="color: #166534; font-weight: 600; font-size: 13px;"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div style="background: linear-gradient(135deg, #fee2e2, #fecaca); border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
    <span style="font-size: 18px;">⚠️</span>
    <span style="color: #991b1b; font-weight: 600; font-size: 13px;"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
</div>
<?php endif; ?>

<div class="cqc-container">
    <!-- Header -->
    <div class="cqc-header">
        <div>
            <h1>📄 Invoice</h1>
            <p>Kelola tagihan progress proyek kontraktor</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="invoice-settings.php" class="btn-settings" style="background: #f1f5f9; color: #475569; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;">
                ⚙️ PDF Settings
            </a>
            <?php if ($activeTab === 'general'): ?>
            <a href="add-invoice.php" class="btn-create">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Buat Invoice Umum
            </a>
            <?php elseif ($activeTab === 'quotation'): ?>
            <a href="add-quotation.php" class="btn-create">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Buat Quotation
            </a>
            <?php else: ?>
            <a href="create-termin.php" class="btn-create">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Buat Invoice Termin
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="cqc-tabs">
        <a href="?tab=termin&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="cqc-tab <?php echo $activeTab === 'termin' ? 'active' : ''; ?>">
            📋 Invoice Termin <span class="badge"><?php echo count($invoices); ?></span>
        </a>
        <a href="?tab=general&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="cqc-tab <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
            📄 Invoice Umum <span class="badge"><?php echo count($generalInvoices); ?></span>
        </a>
        <a href="?tab=quotation&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="cqc-tab <?php echo $activeTab === 'quotation' ? 'active' : ''; ?>">
            📝 Quotation <span class="badge"><?php echo count($quotations); ?></span>
        </a>
    </div>

    <!-- Stats Grid -->
    <div class="cqc-stats-grid">
        <div class="cqc-stat-card">
            <div class="cqc-stat-icon">📋</div>
            <div class="cqc-stat-label">Total Faktur</div>
            <div class="cqc-stat-value"><?php echo $displayStats['total_invoices']; ?></div>
        </div>
        <div class="cqc-stat-card accent">
            <div class="cqc-stat-icon">💰</div>
            <div class="cqc-stat-label">Total Tagihan</div>
            <div class="cqc-stat-value">Rp <?php echo number_format($displayStats['total_amount'], 0, ',', '.'); ?></div>
        </div>
        <div class="cqc-stat-card success">
            <div class="cqc-stat-icon">✅</div>
            <div class="cqc-stat-label">Terbayar</div>
            <div class="cqc-stat-value">Rp <?php echo number_format($displayStats['paid_amount'], 0, ',', '.'); ?></div>
        </div>
        <div class="cqc-stat-card danger">
            <div class="cqc-stat-icon">⏳</div>
            <div class="cqc-stat-label">Belum Bayar</div>
            <div class="cqc-stat-value">Rp <?php echo number_format($displayStats['unpaid_amount'], 0, ',', '.'); ?></div>
        </div>
    </div>

    <!-- Filter -->
    <div class="cqc-filter-card">
        <form method="GET" class="cqc-filter-grid">
            <input type="hidden" name="tab" value="<?php echo $activeTab; ?>">
            <div>
                <div class="cqc-filter-label">Status Pembayaran</div>
                <select name="status" class="cqc-filter-select">
                    <option value="">Semua Status</option>
                    <option value="draft" <?php echo $payment_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $payment_status === 'sent' ? 'selected' : ''; ?>>Terkirim</option>
                    <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                    <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Sebagian</option>
                    <option value="overdue" <?php echo $payment_status === 'overdue' ? 'selected' : ''; ?>>Jatuh Tempo</option>
                </select>
            </div>
            <?php if ($activeTab === 'termin'): ?>
            <div>
                <div class="cqc-filter-label">Proyek</div>
                <select name="project_id" class="cqc-filter-select">
                    <option value="0">Semua Proyek</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>" <?php echo $project_id == $proj['id'] ? 'selected' : ''; ?>>
                            [<?php echo $proj['project_code']; ?>] <?php echo $proj['project_name']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            <div>
                <div class="cqc-filter-label">Dari Tanggal</div>
                <input type="date" name="date_from" class="cqc-filter-input" value="<?php echo $date_from; ?>">
            </div>
            <div>
                <div class="cqc-filter-label">Sampai Tanggal</div>
                <input type="date" name="date_to" class="cqc-filter-input" value="<?php echo $date_to; ?>">
            </div>
            <button type="submit" class="cqc-filter-btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/></svg>
                Filter
            </button>
        </form>
    </div>

    <!-- Table -->
    <?php if ($activeTab === 'termin'): ?>
    <!-- Termin Invoices Table -->
    <div class="cqc-table-card">
        <table class="cqc-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Termin</th>
                    <th>No. Faktur</th>
                    <th>Tanggal</th>
                    <th>Proyek</th>
                    <th>Klien</th>
                    <th style="text-align: center;">%</th>
                    <th style="text-align: right;">DPP</th>
                    <th style="text-align: right;">PPN</th>
                    <th style="text-align: right;">Total</th>
                    <th>Status</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="11">
                            <div class="cqc-empty">
                                <div class="cqc-empty-icon">📋</div>
                                <h3>Tidak ada invoice termin</h3>
                                <p>Buat invoice pertama untuk memulai penagihan proyek.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="cqc-termin-badge"><?php echo $inv['termin_number']; ?></span>
                            </td>
                            <td>
                                <strong style="color: var(--cqc-primary);"><?php echo htmlspecialchars($inv['invoice_number']); ?></strong>
                            </td>
                            <td style="font-size: 11px;"><?php echo date('d/m/Y', strtotime($inv['invoice_date'])); ?></td>
                            <td>
                                <code class="cqc-project-code"><?php echo htmlspecialchars($inv['project_code']); ?></code>
                                <div style="font-size: 10px; color: var(--cqc-muted); margin-top: 2px;"><?php echo htmlspecialchars(mb_substr($inv['project_name'], 0, 25)); ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($inv['client_name']); ?></div>
                            </td>
                            <td style="text-align: center;">
                                <strong style="color: var(--cqc-accent-dark);"><?php echo number_format($inv['percentage'], 0); ?>%</strong>
                            </td>
                            <td style="text-align: right; font-size: 11px;">
                                Rp <?php echo number_format($inv['base_amount'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; font-size: 11px; color: var(--cqc-muted);">
                                Rp <?php echo number_format($inv['ppn_amount'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: var(--cqc-primary);">
                                Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = [
                                    'draft' => 'cqc-status-draft',
                                    'sent' => 'cqc-status-sent',
                                    'paid' => 'cqc-status-paid',
                                    'partial' => 'cqc-status-partial',
                                    'overdue' => 'cqc-status-overdue'
                                ];
                                $statusLabel = [
                                    'draft' => 'Draft',
                                    'sent' => 'Terkirim',
                                    'paid' => '✓ Lunas',
                                    'partial' => 'Sebagian',
                                    'overdue' => '! Jatuh Tempo'
                                ];
                                ?>
                                <span class="cqc-status <?php echo $statusClass[$inv['payment_status']] ?? 'cqc-status-draft'; ?>">
                                    <?php echo $statusLabel[$inv['payment_status']] ?? ucfirst($inv['payment_status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="cqc-actions">
                                    <a href="view-termin.php?id=<?php echo $inv['id']; ?>" class="cqc-action-btn btn-view" title="Lihat & Cetak">
                                        👁
                                    </a>
                                    <?php if ($inv['payment_status'] !== 'paid'): ?>
                                    <button type="button" class="cqc-action-btn btn-pay" title="Bayar" onclick="openPaymentModal(<?php echo $inv['id']; ?>, '<?php echo $inv['invoice_number']; ?>', <?php echo $inv['total_amount']; ?>)">
                                        💰
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="cqc-action-btn btn-delete" title="Hapus" onclick="deleteInvoice(<?php echo $inv['id']; ?>, '<?php echo $inv['invoice_number']; ?>')">
                                        🗑
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($activeTab === 'general'): ?>
    <!-- General Invoices Table -->
    <div class="cqc-table-card">
        <table class="cqc-table">
            <thead>
                <tr>
                    <th style="width: 40px;">No</th>
                    <th>No. Faktur</th>
                    <th>Tanggal</th>
                    <th>Klien</th>
                    <th>Subject</th>
                    <th style="text-align: right;">Subtotal</th>
                    <th style="text-align: right;">Total</th>
                    <th>Status</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($generalInvoices)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="cqc-empty">
                                <div class="cqc-empty-icon">📄</div>
                                <h3>Tidak ada invoice umum</h3>
                                <p>Buat invoice umum pertama untuk memulai penagihan.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($generalInvoices as $idx => $inv): ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="cqc-termin-badge"><?php echo $idx + 1; ?></span>
                            </td>
                            <td>
                                <strong style="color: var(--cqc-primary);"><?php echo htmlspecialchars($inv['invoice_number']); ?></strong>
                            </td>
                            <td style="font-size: 11px;"><?php echo date('d/m/Y', strtotime($inv['invoice_date'])); ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($inv['client_name']); ?></div>
                                <?php if ($inv['client_phone']): ?>
                                <div style="font-size: 10px; color: var(--cqc-muted);"><?php echo htmlspecialchars($inv['client_phone']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11px;">
                                <?php echo htmlspecialchars(mb_substr($inv['subject'] ?: '-', 0, 30)); ?>
                            </td>
                            <td style="text-align: right; font-size: 11px;">
                                Rp <?php echo number_format($inv['subtotal'], 0, ',', '.'); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: var(--cqc-primary);">
                                Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = [
                                    'draft' => 'cqc-status-draft',
                                    'sent' => 'cqc-status-sent',
                                    'paid' => 'cqc-status-paid',
                                    'partial' => 'cqc-status-partial',
                                    'overdue' => 'cqc-status-overdue'
                                ];
                                $statusLabel = [
                                    'draft' => 'Draft',
                                    'sent' => 'Terkirim',
                                    'paid' => '✓ Lunas',
                                    'partial' => 'Sebagian',
                                    'overdue' => '! Jatuh Tempo'
                                ];
                                ?>
                                <span class="cqc-status <?php echo $statusClass[$inv['payment_status']] ?? 'cqc-status-draft'; ?>">
                                    <?php echo $statusLabel[$inv['payment_status']] ?? ucfirst($inv['payment_status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="cqc-actions">
                                    <a href="view-invoice.php?id=<?php echo $inv['id']; ?>" class="cqc-action-btn btn-view" title="Lihat & Cetak">
                                        👁
                                    </a>
                                    <?php if ($inv['payment_status'] !== 'paid'): ?>
                                    <button type="button" class="cqc-action-btn btn-pay" title="Bayar" onclick="openGeneralPaymentModal(<?php echo $inv['id']; ?>, '<?php echo $inv['invoice_number']; ?>', <?php echo $inv['total_amount']; ?>)">
                                        💰
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="cqc-action-btn btn-delete" title="Hapus" onclick="deleteGeneralInvoice(<?php echo $inv['id']; ?>, '<?php echo $inv['invoice_number']; ?>')">
                                        🗑
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Quotation Tab Content -->
    <?php if ($activeTab === 'quotation'): ?>
    <div class="cqc-table-responsive">
        <table class="cqc-table">
            <thead>
                <tr>
                    <th style="width: 40px; text-align: center;">No</th>
                    <th>No. Quotation</th>
                    <th>Tanggal</th>
                    <th>Klien</th>
                    <th>Subject</th>
                    <th style="text-align: right;">Total</th>
                    <th>Status</th>
                    <th style="text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($quotations)): ?>
                    <tr>
                        <td colspan="8">
                            <div class="cqc-empty">
                                <div class="cqc-empty-icon">📝</div>
                                <h3>Tidak ada quotation</h3>
                                <p>Buat quotation pertama untuk penawaran harga.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($quotations as $idx => $quot): ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="cqc-termin-badge"><?php echo $idx + 1; ?></span>
                            </td>
                            <td>
                                <strong style="color: var(--cqc-primary);"><?php echo htmlspecialchars($quot['quote_number']); ?></strong>
                            </td>
                            <td style="font-size: 11px;"><?php echo date('d/m/Y', strtotime($quot['quote_date'])); ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($quot['client_name']); ?></div>
                                <?php if ($quot['client_attn']): ?>
                                <div style="font-size: 10px; color: var(--cqc-muted);">Attn: <?php echo htmlspecialchars($quot['client_attn']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11px;">
                                <?php echo htmlspecialchars(mb_substr($quot['subject'] ?: '-', 0, 30)); ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: var(--cqc-primary);">
                                Rp <?php echo number_format($quot['total_amount'], 0, ',', '.'); ?>
                            </td>
                            <td>
                                <?php
                                $quotStatusClass = [
                                    'draft' => 'cqc-status-draft',
                                    'sent' => 'cqc-status-sent',
                                    'approved' => 'cqc-status-paid',
                                    'rejected' => 'cqc-status-overdue',
                                    'expired' => 'cqc-status-partial'
                                ];
                                $quotStatusLabel = [
                                    'draft' => 'Draft',
                                    'sent' => 'Terkirim',
                                    'approved' => '✓ Disetujui',
                                    'rejected' => '✗ Ditolak',
                                    'expired' => '⌛ Expired'
                                ];
                                ?>
                                <span class="cqc-status <?php echo $quotStatusClass[$quot['status']] ?? 'cqc-status-draft'; ?>">
                                    <?php echo $quotStatusLabel[$quot['status']] ?? ucfirst($quot['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="cqc-actions">
                                    <a href="view-quotation.php?id=<?php echo $quot['id']; ?>" class="cqc-action-btn btn-view" title="Lihat & Cetak">
                                        👁
                                    </a>
                                    <a href="edit-quotation.php?id=<?php echo $quot['id']; ?>" class="cqc-action-btn" title="Edit" style="background: #f1f5f9; color: #475569;">
                                        ✏️
                                    </a>
                                    <?php if ($quot['status'] !== 'approved'): ?>
                                    <button type="button" class="cqc-action-btn" title="ACC - Terima & Buat Proyek" style="background: #22c55e; color: white; font-weight: 700;" onclick="accQuotation(<?php echo $quot['id']; ?>, '<?php echo addslashes($quot['quote_number']); ?>')">
                                        ✓ ACC
                                    </button>
                                    <?php else: ?>
                                    <span class="cqc-action-btn" style="background: #dcfce7; color: #166534; cursor: default;">
                                        ✓
                                    </span>
                                    <?php endif; ?>
                                    <a href="create-termin.php?from_quotation=<?php echo $quot['id']; ?>" class="cqc-action-btn" title="Buat Invoice Termin 1 dari Quotation" style="background: #e8f5e9; color: #2e7d32; font-size: 13px;" onclick="return confirm('Buat Invoice Termin 1 dari Quotation <?php echo addslashes($quot['quote_number']); ?>?')">
                                        🧾
                                    </a>
                                    <button type="button" class="cqc-action-btn btn-delete" title="Hapus" onclick="deleteQuotation(<?php echo $quot['id']; ?>, '<?php echo $quot['quote_number']; ?>')">
                                        🗑
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Payment Modal -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(13,31,60,0.85); z-index: 9999; backdrop-filter: blur(6px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 450px; width: 90%; overflow: hidden;">
        <div style="background: linear-gradient(135deg, var(--cqc-primary) 0%, var(--cqc-primary-light) 100%); color: white; padding: 18px 20px;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700;">💰 Konfirmasi Pembayaran</h3>
            <p style="margin: 4px 0 0 0; font-size: 12px; opacity: 0.9;" id="paymentInvoiceNum"></p>
        </div>
        <form method="POST" action="pay-termin.php" id="paymentForm">
            <div style="padding: 20px;">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                
                <div style="background: linear-gradient(135deg, rgba(240,180,41,0.1), rgba(240,180,41,0.05)); border-left: 3px solid var(--cqc-accent); padding: 14px; border-radius: 6px; margin-bottom: 16px;">
                    <div style="font-size: 11px; color: var(--cqc-muted); margin-bottom: 4px;">Total Pembayaran</div>
                    <div style="font-size: 22px; font-weight: 800; color: var(--cqc-primary);" id="paymentAmount"></div>
                </div>
                
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: var(--cqc-muted); margin-bottom: 6px; text-transform: uppercase;">Metode Pembayaran</label>
                    <select name="payment_method" class="cqc-filter-select" required>
                        <option value="transfer">🏦 Transfer Bank</option>
                        <option value="cash">💵 Cash</option>
                        <option value="giro">📝 Giro/Cek</option>
                        <option value="other">➕ Lainnya</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 14px;">
                    <label style="display: block; font-size: 11px; font-weight: 600; color: var(--cqc-muted); margin-bottom: 6px; text-transform: uppercase;">Catatan (Opsional)</label>
                    <textarea name="notes" class="cqc-filter-input" rows="2" placeholder="No. rekening, kode transfer, dll."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 18px;">
                    <button type="button" onclick="closePaymentModal()" style="flex: 1; padding: 10px; border: 1px solid var(--cqc-border); background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; color: var(--cqc-muted);">Batal</button>
                    <button type="submit" style="flex: 2; padding: 10px 20px; background: var(--cqc-success); color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 700;">✓ Konfirmasi Bayar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openPaymentModal(id, invoiceNum, amount) {
    document.getElementById('paymentInvoiceId').value = id;
    document.getElementById('paymentInvoiceNum').textContent = invoiceNum;
    document.getElementById('paymentAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
    document.getElementById('paymentForm').action = 'pay-termin.php';
    document.getElementById('paymentModal').style.display = 'block';
}

function openGeneralPaymentModal(id, invoiceNum, amount) {
    document.getElementById('paymentInvoiceId').value = id;
    document.getElementById('paymentInvoiceNum').textContent = invoiceNum;
    document.getElementById('paymentAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
    document.getElementById('paymentForm').action = 'pay-general-invoice.php';
    document.getElementById('paymentModal').style.display = 'block';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function deleteInvoice(id, invoiceNum) {
    if (confirm('⚠️ Hapus faktur ' + invoiceNum + '?\n\nData akan dihapus permanen!')) {
        window.location.href = 'delete-termin.php?id=' + id;
    }
}

function deleteGeneralInvoice(id, invoiceNum) {
    if (confirm('⚠️ Hapus faktur ' + invoiceNum + '?\n\nData akan dihapus permanen!')) {
        window.location.href = 'delete-general-invoice.php?id=' + id;
    }
}

function deleteQuotation(id, quoteNum) {
    if (confirm('⚠️ Hapus quotation ' + quoteNum + '?\n\nData akan dihapus permanen!')) {
        window.location.href = 'delete-quotation.php?id=' + id;
    }
}

function accQuotation(id, quoteNum) {
    if (confirm('✅ ACC Quotation ' + quoteNum + '?\n\n• Status quotation akan berubah menjadi "Disetujui"\n• Proyek baru akan dibuat otomatis dari data quotation ini\n• Data customer akan ditambahkan ke database\n\nLanjutkan?')) {
        // Show loading state
        const btn = event.target;
        btn.disabled = true;
        btn.innerHTML = '⏳';
        
        fetch('acc-quotation.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Sukses!\n\n• Quotation telah di-ACC\n• Proyek baru telah dibuat: ' + (data.project_code || ''));
                    window.location.reload();
                } else {
                    alert('❌ Gagal: ' + (data.message || 'Terjadi kesalahan'));
                    btn.disabled = false;
                    btn.innerHTML = '✓ ACC';
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = '✓ ACC';
            });
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePaymentModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
