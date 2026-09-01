<?php
/**
 * BILLS MODULE - Laporan Pencairan Tagihan
 * Generate report for requesting fund disbursement from owner
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/report_helper.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();
require_once __DIR__ . '/auto-migrate.php';

$pageTitle = 'Laporan Pencairan Tagihan';
$pageSubtitle = 'Ajukan pencairan dana untuk pembayaran tagihan';

// Get company info
$company = getCompanyInfo();

// Get current month filter
$filterMonth = getGet('month', date('Y-m'));
$periodDisplay = date('F Y', strtotime($filterMonth . '-01'));

// Get unpaid bills (pending + overdue)
$bills = $db->fetchAll(
    "SELECT 
        br.*,
        bt.bill_name,
        bt.bill_category,
        bt.vendor_name,
        bt.account_number,
        bt.is_fixed_amount,
        d.division_name
    FROM bill_records br
    JOIN bill_templates bt ON br.template_id = bt.id
    LEFT JOIN divisions d ON bt.division_id = d.id
    WHERE br.bill_period = :month AND br.status IN ('pending', 'overdue')
    ORDER BY br.due_date ASC, bt.bill_name ASC",
    ['month' => $filterMonth]
);

$categories = [
    'electricity' => ['label' => 'Listrik', 'icon' => '⚡', 'color' => '#f59e0b'],
    'tax' => ['label' => 'Pajak', 'icon' => '🏛️', 'color' => '#8b5cf6'],
    'wifi' => ['label' => 'WiFi/Internet', 'icon' => '📶', 'color' => '#3b82f6'],
    'vehicle' => ['label' => 'Kendaraan', 'icon' => '🏍️', 'color' => '#06b6d4'],
    'po' => ['label' => 'Tagihan PO', 'icon' => '📦', 'color' => '#f97316'],
    'receivable' => ['label' => 'Piutang', 'icon' => '💳', 'color' => '#ec4899'],
    'other' => ['label' => 'Lainnya', 'icon' => '📋', 'color' => '#64748b'],
];

// Calculate totals
$totalPending = 0;
$totalOverdue = 0;
$countPending = 0;
$countOverdue = 0;

foreach ($bills as $bill) {
    if ($bill['status'] === 'overdue') {
        $totalOverdue += $bill['amount'];
        $countOverdue++;
    } else {
        $totalPending += $bill['amount'];
        $countPending++;
    }
}

$totalAll = $totalPending + $totalOverdue;

include '../../includes/header.php';
?>

<style>
/* ===== LAPORAN PENCAIRAN - ELEGANT DESIGN ===== */
.laporan-container { max-width: 100%; margin: 0 auto; padding: 1rem 0.5rem; }

.action-buttons { display: flex; gap: 0.5rem; justify-content: flex-end; margin-bottom: 1rem; flex-wrap: wrap; }
.action-buttons .btn { padding: 0.45rem 1.1rem; border: none; border-radius: 8px; font-weight: 700; font-size: 0.8rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; color: #fff; letter-spacing: 0.3px; text-decoration: none; }
.action-buttons .btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.2); }
.btn-generate { background: #4f46e5; color: #fff; }
.btn-generate:hover { background: #4338ca; }
.btn-generate:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
.btn-print { background: #475569; color: #fff; }
.btn-print:hover { background: #334155; }
.btn-wa { background: #22c55e; color: #fff; }
.btn-wa:hover { background: #16a34a; }
.btn-back { background: #64748b; color: #fff; }
.btn-back:hover { background: #475569; }

/* Report Header - Same as Frontdesk */
.report-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.6rem; border-bottom: 2px solid #4f46e5; margin-bottom: 1rem; gap: 0.75rem; }
[data-theme="dark"] .report-header { border-bottom-color: #6366f1; }
.report-header-left { display: flex; align-items: center; gap: 0.75rem; }
.report-logo { width: 48px; height: 48px; border-radius: 10px; object-fit: contain; flex-shrink: 0; }
.report-logo-icon { width: 48px; height: 48px; border-radius: 10px; background: #eef2ff; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
[data-theme="dark"] .report-logo-icon { background: #312e81; }
.report-header-left .hotel-name { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }
[data-theme="dark"] .report-header-left .hotel-name { color: #e2e8f0; }
.report-header-left .hotel-detail { font-size: 0.65rem; color: #64748b; line-height: 1.4; margin-top: 2px; }
.report-header-right { text-align: right; flex-shrink: 0; }
.report-header-right .report-title { font-size: 0.75rem; font-weight: 700; color: #4f46e5; letter-spacing: 1px; text-transform: uppercase; margin: 0; }
[data-theme="dark"] .report-header-right .report-title { color: #818cf8; }
.report-header-right .report-date { font-size: 0.7rem; color: #64748b; margin-top: 2px; }

/* Stats Row */
.stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; margin-bottom: 1.25rem; }
.stat-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 0.5rem; text-align: center; transition: all 0.2s; }
.stat-item:hover { border-color: #c7d2fe; background: #eef2ff; }
[data-theme="dark"] .stat-item { background: #1e293b; border-color: #334155; }
[data-theme="dark"] .stat-item:hover { border-color: #4f46e5; background: #1e1b4b; }
.stat-item .stat-val { font-size: 1.2rem; font-weight: 800; color: #4f46e5; line-height: 1; }
[data-theme="dark"] .stat-item .stat-val { color: #818cf8; }
.stat-item .stat-lbl { font-size: 0.6rem; color: #94a3b8; font-weight: 500; margin-top: 0.2rem; text-transform: uppercase; letter-spacing: 0.5px; }
.stat-item.pending .stat-val { color: #f59e0b; }
.stat-item.overdue .stat-val { color: #ef4444; }
.stat-item.total .stat-val { color: #4f46e5; }

/* Filter Bar */
.filter-bar { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
[data-theme="dark"] .filter-bar { background: #1e293b; border-color: #334155; }
.filter-bar label { font-size: 0.72rem; font-weight: 600; color: #64748b; }
.filter-bar input[type="month"] { background: #fff; border: 1px solid #e2e8f0; color: #1e293b; font-size: 0.75rem; padding: 0.4rem 0.6rem; border-radius: 6px; }
[data-theme="dark"] .filter-bar input[type="month"] { background: #334155; border-color: #475569; color: #e2e8f0; }
.filter-bar .btn-filter { background: #4f46e5; color: #fff; border: none; padding: 0.4rem 0.8rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; cursor: pointer; }

/* Section */
.rpt-section { margin-bottom: 0.75rem; }
.rpt-section-head { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e2e8f0; margin-bottom: 0.35rem; }
[data-theme="dark"] .rpt-section-head { border-bottom-color: #475569; }
.rpt-section-head .sec-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 0.35rem; }
[data-theme="dark"] .rpt-section-head .sec-title { color: #e2e8f0; }
.rpt-section-head .sec-action { display: flex; align-items: center; gap: 0.5rem; }
.select-all-label { font-size: 0.68rem; color: #64748b; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; }
.select-all-label input { width: 16px; height: 16px; accent-color: #4f46e5; }

/* Table */
.rpt-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.rpt-table th { background: #f8fafc; padding: 0.45rem 0.5rem; text-align: left; font-weight: 600; font-size: 0.68rem; color: #64748b; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; letter-spacing: 0.3px; }
[data-theme="dark"] .rpt-table th { background: #1e293b; color: #94a3b8; border-bottom-color: #334155; }
.rpt-table td { padding: 0.45rem 0.5rem; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.78rem; vertical-align: middle; }
[data-theme="dark"] .rpt-table td { border-bottom-color: #1e293b; color: #cbd5e1; }
.rpt-table tbody tr { transition: all 0.15s; }
.rpt-table tbody tr:hover { background: #f8fafc; }
[data-theme="dark"] .rpt-table tbody tr:hover { background: #1e293b; }
.rpt-table tbody tr.selected { background: #eef2ff; }
[data-theme="dark"] .rpt-table tbody tr.selected { background: rgba(99,102,241,0.15); }
.rpt-table tbody tr.row-overdue { background: rgba(239,68,68,0.04); }
[data-theme="dark"] .rpt-table tbody tr.row-overdue { background: rgba(239,68,68,0.1); }
.rpt-table .bill-check { width: 16px; height: 16px; accent-color: #4f46e5; cursor: pointer; }

/* Badges */
.cat-badge { display: inline-flex; align-items: center; gap: 0.2rem; padding: 0.12rem 0.4rem; border-radius: 4px; font-size: 0.65rem; font-weight: 600; }
.status-badge { display: inline-block; padding: 0.12rem 0.4rem; border-radius: 4px; font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
.status-badge.pending { background: rgba(245,158,11,0.15); color: #d97706; }
.status-badge.overdue { background: rgba(239,68,68,0.15); color: #dc2626; }
[data-theme="dark"] .status-badge.pending { background: rgba(245,158,11,0.2); color: #fbbf24; }
[data-theme="dark"] .status-badge.overdue { background: rgba(239,68,68,0.2); color: #f87171; }

/* Summary Box */
.summary-box { background: linear-gradient(135deg, #4f46e5, #6366f1); border-radius: 12px; padding: 1rem 1.25rem; margin-top: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
.summary-info { display: flex; gap: 2rem; }
.summary-item { text-align: center; }
.summary-item .sum-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; color: rgba(255,255,255,0.8) !important; margin-bottom: 0.15rem; }
.summary-item .sum-value { font-size: 1.1rem; font-weight: 800; color: #fff !important; }
.summary-actions { display: flex; gap: 0.5rem; }

/* Report Stamp */
.report-stamp { text-align: center; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px dashed #e2e8f0; }
[data-theme="dark"] .report-stamp { border-top-color: #334155; }
.report-stamp .stamp-line { font-size: 0.6rem; color: #94a3b8; line-height: 1.6; }
.report-stamp .stamp-system { font-weight: 600; color: #4f46e5; font-size: 0.6rem; letter-spacing: 0.5px; }
[data-theme="dark"] .report-stamp .stamp-system { color: #818cf8; }

/* Print Area */\n.print-area { display: none; }\n.print-area.show { display: block; background: #fff; color: #1a1a2e; padding: 1.5rem; border-radius: 12px; margin-top: 1.25rem; border: 1px solid #e2e8f0; }

.print-area .print-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 0.75rem; border-bottom: 2px solid #4f46e5; margin-bottom: 1rem; gap: 0.75rem; }
.print-area .print-logo { width: 52px; height: 52px; border-radius: 10px; object-fit: contain; }
.print-area .print-logo-icon { width: 52px; height: 52px; border-radius: 10px; background: #eef2ff; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; }
.print-area .print-company { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin: 0; }
.print-area .print-address { font-size: 0.65rem; color: #64748b; line-height: 1.4; margin-top: 2px; }
.print-area .print-title-box { text-align: right; }
.print-area .print-doc-title { font-size: 0.8rem; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 1px; margin: 0; }
.print-area .print-doc-date { font-size: 0.7rem; color: #64748b; margin-top: 2px; }

.print-area .print-meta { display: flex; justify-content: space-between; margin-bottom: 1rem; font-size: 0.75rem; color: #475569; padding: 0.5rem 0; border-bottom: 1px solid #e2e8f0; }

.print-area .print-table { width: 100%; border-collapse: collapse; font-size: 0.75rem; margin-bottom: 1rem; }
.print-area .print-table th { background: #f1f5f9; padding: 0.5rem; text-align: left; font-weight: 600; border: 1px solid #e2e8f0; color: #374151; font-size: 0.68rem; text-transform: uppercase; }
.print-area .print-table td { padding: 0.5rem; border: 1px solid #e2e8f0; color: #334155; }
.print-area .print-table .text-right { text-align: right; }
.print-area .print-table .text-center { text-align: center; }
.print-area .print-table tfoot td { background: #f8fafc; font-weight: 700; }
.print-area .print-table .bill-name { font-weight: 600; color: #1e293b; }
.print-area .print-table .bill-vendor { font-size: 0.68rem; color: #64748b; }

.print-area .print-note { background: #f8fafc; border-radius: 8px; padding: 0.75rem; margin: 1rem 0; font-size: 0.72rem; color: #475569; border-left: 3px solid #4f46e5; }

.print-area .signature-row { display: flex; justify-content: space-between; margin-top: 2rem; }
.print-area .signature-box { text-align: center; min-width: 160px; }
.print-area .sig-title { font-size: 0.7rem; color: #64748b; margin-bottom: 3rem; }
.print-area .sig-line { border-top: 1px solid #cbd5e1; padding-top: 0.5rem; font-size: 0.75rem; font-weight: 600; color: #1e293b; }

.print-area .print-footer { text-align: center; margin-top: 1.5rem; padding-top: 0.75rem; border-top: 1px dashed #e2e8f0; font-size: 0.6rem; color: #94a3b8; }
.print-area .print-footer .sys { font-weight: 600; color: #4f46e5; }

/* Supplier Quick Add */
.supplier-add-box { background: linear-gradient(135deg, #fef3c7, #fde68a); border: 1px solid #fcd34d; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
[data-theme="dark"] .supplier-add-box { background: linear-gradient(135deg, #422006, #78350f); border-color: #92400e; }
.supplier-add-box .box-title { font-size: 0.85rem; font-weight: 700; color: #92400e; margin: 0 0 0.75rem; display: flex; align-items: center; gap: 0.4rem; }
[data-theme="dark"] .supplier-add-box .box-title { color: #fcd34d; }
.supplier-form { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-end; }
.supplier-form .form-group { flex: 1; min-width: 150px; }
.supplier-form .form-group label { display: block; font-size: 0.68rem; font-weight: 600; color: #78350f; margin-bottom: 0.25rem; }
[data-theme="dark"] .supplier-form .form-group label { color: #fde68a; }
.supplier-form input, .supplier-form select { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid #fcd34d; border-radius: 6px; font-size: 0.78rem; background: #fff; color: #1e293b; }
[data-theme="dark"] .supplier-form input, [data-theme="dark"] .supplier-form select { background: #1e293b; border-color: #92400e; color: #e2e8f0; }
.supplier-form .btn-add-supplier { background: #f59e0b; color: #fff; border: none; padding: 0.45rem 1rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
.supplier-form .btn-add-supplier:hover { background: #d97706; }

/* Category with Add Button */
.category-wrapper { display: flex; gap: 0.35rem; align-items: flex-end; }
.category-wrapper select { flex: 1; }
.btn-add-cat { background: #78350f; color: #fff; border: none; width: 32px; height: 32px; border-radius: 6px; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.btn-add-cat:hover { background: #92400e; }
[data-theme="dark"] .btn-add-cat { background: #fcd34d; color: #78350f; }

/* Add Category Modal */
.cat-modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
.cat-modal-overlay.show { display: flex; }
.cat-modal { background: #fff; border-radius: 12px; padding: 1.25rem; width: 90%; max-width: 360px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
[data-theme="dark"] .cat-modal { background: #1e293b; }
.cat-modal-title { font-size: 0.9rem; font-weight: 700; color: #1e293b; margin: 0 0 1rem; display: flex; align-items: center; gap: 0.4rem; }
[data-theme="dark"] .cat-modal-title { color: #e2e8f0; }
.cat-modal .form-group { margin-bottom: 0.75rem; }
.cat-modal .form-group label { display: block; font-size: 0.72rem; font-weight: 600; color: #64748b; margin-bottom: 0.25rem; }
.cat-modal .form-group input, .cat-modal .form-group select { width: 100%; padding: 0.5rem 0.65rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.78rem; background: #fff; color: #1e293b; }
[data-theme="dark"] .cat-modal .form-group input, [data-theme="dark"] .cat-modal .form-group select { background: #334155; border-color: #475569; color: #e2e8f0; }
.cat-modal-actions { display: flex; gap: 0.5rem; margin-top: 1rem; }
.cat-modal-actions .btn { flex: 1; padding: 0.5rem; border: none; border-radius: 6px; font-size: 0.78rem; font-weight: 600; cursor: pointer; }
.cat-modal-actions .btn-cancel { background: #e2e8f0; color: #64748b; }
.cat-modal-actions .btn-cancel:hover { background: #cbd5e1; }
.cat-modal-actions .btn-save { background: #f59e0b; color: #fff; }
.cat-modal-actions .btn-save:hover { background: #d97706; }

/* Added Supplier List */
.added-suppliers { margin-top: 0.75rem; }
.added-suppliers .supplier-item { display: flex; align-items: center; justify-content: space-between; background: #fff; border: 1px solid #fcd34d; border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 0.4rem; gap: 0.5rem; }
[data-theme="dark"] .added-suppliers .supplier-item { background: #1e293b; border-color: #92400e; }
.supplier-item .supplier-check { width: 16px; height: 16px; accent-color: #f59e0b; cursor: pointer; }
.supplier-item .supplier-info { flex: 1; }
.supplier-item .supplier-name { font-size: 0.78rem; font-weight: 600; color: #1e293b; }
[data-theme="dark"] .supplier-item .supplier-name { color: #e2e8f0; }
.supplier-item .supplier-cat { font-size: 0.65rem; color: #78350f; }
[data-theme="dark"] .supplier-item .supplier-cat { color: #fcd34d; }
.supplier-item .supplier-amount { font-size: 0.85rem; font-weight: 700; color: #d97706; }
.supplier-item .btn-remove { background: #fee2e2; color: #dc2626; border: none; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.65rem; cursor: pointer; }
.supplier-item .btn-remove:hover { background: #fecaca; }

/* Empty State */
.empty-state { text-align: center; padding: 3rem 1rem; color: #94a3b8; }
.empty-state-icon { font-size: 3rem; margin-bottom: 0.75rem; opacity: 0.5; }
.empty-state-text { font-size: 0.88rem; margin-bottom: 0.35rem; color: #64748b; }
.empty-state-sub { font-size: 0.72rem; }

/* Print Styles */
@media print {
    body * { visibility: hidden; }
    .print-area.show, .print-area.show * { visibility: visible; }
    .print-area.show { position: absolute; left: 0; top: 0; width: 100%; padding: 12mm 15mm; background: white !important; }
    .print-area .print-table th { background: #f3f4f6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-area .print-logo-icon { background: #eef2ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .print-area .print-note { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .action-buttons, .filter-bar, .summary-box, .supplier-add-box { display: none !important; }
}

@media (max-width: 640px) {
    .laporan-container { padding: 0.75rem; }
    .stats-row { grid-template-columns: 1fr; }
    .report-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
    .report-header-right { text-align: left; }
    .summary-box { flex-direction: column; text-align: center; }
    .summary-info { flex-direction: column; gap: 0.75rem; }
    .action-buttons { flex-direction: column; }
    .action-buttons .btn { width: 100%; justify-content: center; }
}
</style>

<div class="laporan-container">
    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="<?= BASE_URL ?>/modules/bills/" class="btn btn-back">
            <i data-feather="arrow-left" style="width:14px;height:14px"></i> Kembali
        </a>
    </div>

    <!-- Report Header - Same as Frontdesk -->
    <div class="report-header">
        <div class="report-header-left">
            <?php
            $logoUrl = $company['invoice_logo'] ?? $company['logo'] ?? null;
            if ($logoUrl): ?>
            <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo" class="report-logo">
            <?php else: ?>
            <div class="report-logo-icon"><?php echo $company['icon']; ?></div>
            <?php endif; ?>
            <div>
                <div class="hotel-name"><?php echo htmlspecialchars($company['name']); ?></div>
                <div class="hotel-detail">
                    <?php if ($company['address']): echo htmlspecialchars($company['address']); endif; ?>
                    <?php if ($company['phone']): ?> | Tel: <?php echo htmlspecialchars($company['phone']); ?><?php endif; ?>
                    <?php if ($company['email']): ?> | <?php echo htmlspecialchars($company['email']); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="report-header-right">
            <div class="report-title">Pengajuan Pencairan</div>
            <div class="report-date"><?= date('l, d F Y') ?></div>
        </div>
    </div>

    <!-- Filter -->
    <form method="GET" class="filter-bar">
        <label>Periode Tagihan:</label>
        <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
        <button type="submit" class="btn-filter">
            <i data-feather="filter" style="width:12px;height:12px;display:inline;vertical-align:-2px"></i> Filter
        </button>
    </form>

    <!-- Quick Add Supplier Bills -->
    <div class="supplier-add-box">
        <div class="box-title">🛒 Tambah Tagihan Belanja / Supplier</div>
        <div class="supplier-form">
            <div class="form-group" style="flex:2">
                <label>Nama Supplier / Toko</label>
                <input type="text" id="supplierName" placeholder="Contoh: Toko Sembako Pak Joko">
            </div>
            <div class="form-group">
                <label>Kategori</label>
                <div class="category-wrapper">
                    <select id="supplierCategory">
                        <option value="food">🍽️ Makanan/Bahan</option>
                        <option value="grocery">🛒 Belanja Harian</option>
                        <option value="supply">📦 Perlengkapan</option>
                        <option value="transport">🚗 Transport</option>
                        <option value="gas">⛽ BBM/Gas</option>
                        <option value="other">📋 Lainnya</option>
                    </select>
                    <button type="button" class="btn-add-cat" onclick="openCategoryModal()" title="Tambah Kategori">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>Nominal (Rp)</label>
                <input type="number" id="supplierAmount" placeholder="0" min="0">
            </div>
            <button type="button" class="btn-add-supplier" onclick="addSupplierBill()">
                ➕ Tambah
            </button>
        </div>
        <div class="added-suppliers" id="addedSuppliersList">
            <!-- Dynamically filled -->
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="cat-modal-overlay" id="catModal">
        <div class="cat-modal">
            <div class="cat-modal-title">➕ Tambah Kategori Baru</div>
            <div class="form-group">
                <label>Nama Kategori</label>
                <input type="text" id="newCatName" placeholder="Contoh: Laundry">
            </div>
            <div class="form-group">
                <label>Icon/Emoji</label>
                <select id="newCatIcon">
                    <option value="🍽️">🍽️ Makanan</option>
                    <option value="🛒">🛒 Belanja</option>
                    <option value="📦">📦 Paket</option>
                    <option value="🚗">🚗 Kendaraan</option>
                    <option value="⛽">⛽ BBM</option>
                    <option value="🧹">🧹 Kebersihan</option>
                    <option value="🔧">🔧 Perbaikan</option>
                    <option value="💡">💡 Listrik</option>
                    <option value="📱">📱 Komunikasi</option>
                    <option value="🏥">🏥 Kesehatan</option>
                    <option value="👕">👕 Laundry</option>
                    <option value="🎨">🎨 Dekorasi</option>
                    <option value="📋">📋 Lainnya</option>
                </select>
            </div>
            <div class="cat-modal-actions">
                <button type="button" class="btn btn-cancel" onclick="closeCategoryModal()">Batal</button>
                <button type="button" class="btn btn-save" onclick="saveNewCategory()">Simpan</button>
            </div>
        </div>
    </div>

    <?php if (empty($bills)): ?>
    <!-- Empty State for Template Bills -->
    <div class="rpt-section" style="margin-top: 1rem;">
        <div class="rpt-section-head">
            <h3 class="sec-title">📋 Tagihan Rutin</h3>
        </div>
        <div class="empty-state" style="padding: 1.5rem;">
            <div class="empty-state-icon">✅</div>
            <div class="empty-state-text">Tidak ada tagihan rutin yang perlu dibayar</div>
            <div class="empty-state-sub">Semua tagihan periode <?= date('F Y', strtotime($filterMonth . '-01')) ?> sudah lunas</div>
        </div>
    </div>

    <!-- Summary Box (still show for supplier bills) -->
    <div class="summary-box">
        <div class="summary-info">
            <div class="summary-item">
                <div class="sum-label">Jumlah Tagihan</div>
                <div class="sum-value" id="selectedCount">0</div>
            </div>
            <div class="summary-item">
                <div class="sum-label">Total Pengajuan</div>
                <div class="sum-value" id="selectedTotal">Rp 0</div>
            </div>
        </div>
        <div class="summary-actions">
            <button type="button" class="btn btn-generate" onclick="generateReport()" id="btnGenerate" disabled>
                📄 Cetak Laporan
            </button>
            <button type="button" class="btn btn-wa" onclick="shareWhatsApp()" id="btnWa" disabled>
                📱 Kirim WA
            </button>
        </div>
    </div>

    <?php else: ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-item pending">
            <div class="stat-val"><?= formatCurrency($totalPending) ?></div>
            <div class="stat-lbl">Menunggu Bayar (<?= $countPending ?>)</div>
        </div>
        <div class="stat-item overdue">
            <div class="stat-val"><?= formatCurrency($totalOverdue) ?></div>
            <div class="stat-lbl">Jatuh Tempo (<?= $countOverdue ?>)</div>
        </div>
        <div class="stat-item total">
            <div class="stat-val"><?= formatCurrency($totalAll) ?></div>
            <div class="stat-lbl">Total Tagihan</div>
        </div>
    </div>

    <!-- Selection Table -->
    <div class="rpt-section">
        <div class="rpt-section-head">
            <h3 class="sec-title">📋 Pilih Tagihan untuk Pencairan</h3>
            <div class="sec-action">
                <label class="select-all-label">
                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    Pilih Semua
                </label>
            </div>
        </div>
        <table class="rpt-table">
            <thead>
                <tr>
                    <th style="width:35px;text-align:center">✓</th>
                    <th>Tagihan</th>
                    <th>Kategori</th>
                    <th>Jatuh Tempo</th>
                    <th>No. Rek/ID</th>
                    <th>Status</th>
                    <th style="text-align:right">Nominal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bills as $bill): 
                    $catInfo = $categories[$bill['bill_category']] ?? $categories['other'];
                    $isOverdue = $bill['status'] === 'overdue';
                ?>
                <tr class="<?= $isOverdue ? 'row-overdue' : '' ?>" data-id="<?= $bill['id'] ?>">
                    <td style="text-align:center">
                        <input type="checkbox" class="bill-check bill-item"
                               data-id="<?= $bill['id'] ?>"
                               data-name="<?= htmlspecialchars($bill['bill_name']) ?>"
                               data-vendor="<?= htmlspecialchars($bill['vendor_name'] ?? '-') ?>"
                               data-account="<?= htmlspecialchars($bill['account_number'] ?? '-') ?>"
                               data-due="<?= date('d M Y', strtotime($bill['due_date'])) ?>"
                               data-amount="<?= $bill['amount'] ?>"
                               data-status="<?= $bill['status'] ?>"
                               data-category="<?= $catInfo['label'] ?>"
                               data-icon="<?= $catInfo['icon'] ?>"
                               onchange="updateSelection()">
                    </td>
                    <td>
                        <div style="font-weight:600;color:var(--text-heading)"><?= htmlspecialchars($bill['bill_name']) ?></div>
                        <div style="font-size:0.68rem;color:var(--text-muted)"><?= htmlspecialchars($bill['vendor_name'] ?? '-') ?></div>
                    </td>
                    <td>
                        <span class="cat-badge" style="background:<?= $catInfo['color'] ?>18;color:<?= $catInfo['color'] ?>">
                            <?= $catInfo['icon'] ?> <?= $catInfo['label'] ?>
                        </span>
                    </td>
                    <td style="font-size:0.75rem"><?= date('d M Y', strtotime($bill['due_date'])) ?></td>
                    <td style="font-size:0.72rem;color:var(--text-muted)"><?= htmlspecialchars($bill['account_number'] ?? '-') ?></td>
                    <td>
                        <span class="status-badge <?= $bill['status'] ?>">
                            <?= $bill['status'] === 'overdue' ? 'Terlambat' : 'Menunggu' ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:600"><?= formatCurrency($bill['amount']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary Box -->
    <div class="summary-box">
        <div class="summary-info">
            <div class="summary-item">
                <div class="sum-label">Jumlah Tagihan</div>
                <div class="sum-value" id="selectedCount">0</div>
            </div>
            <div class="summary-item">
                <div class="sum-label">Total Pengajuan</div>
                <div class="sum-value" id="selectedTotal">Rp 0</div>
            </div>
        </div>
        <div class="summary-actions">
            <button type="button" class="btn btn-generate" onclick="generateReport()" id="btnGenerate" disabled>
                📄 Cetak Laporan
            </button>
            <button type="button" class="btn btn-wa" onclick="shareWhatsApp()" id="btnWa" disabled>
                📱 Kirim WA
            </button>
        </div>
    </div>

    <?php endif; ?>

    <!-- Print Area (moved outside if-else so it works for supplier-only cases too) -->
    <div class="print-area" id="printArea2">
        <div class="print-header">
            <div style="display:flex;align-items:center;gap:0.75rem">
                <?php if ($logoUrl): ?>
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo" class="print-logo">
                <?php else: ?>
                <div class="print-logo-icon"><?php echo $company['icon']; ?></div>
                <?php endif; ?>
                <div>
                    <div class="print-company"><?php echo htmlspecialchars($company['name']); ?></div>
                    <div class="print-address">
                        <?php if ($company['address']): echo htmlspecialchars($company['address']); endif; ?>
                        <?php if ($company['phone']): ?> | Tel: <?php echo htmlspecialchars($company['phone']); ?><?php endif; ?>
                        <?php if ($company['email']): ?> | <?php echo htmlspecialchars($company['email']); ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="print-title-box">
                <div class="print-doc-title">Pengajuan Pencairan Dana</div>
                <div class="print-doc-date">Pembayaran Tagihan</div>
            </div>
        </div>

        <div class="print-meta">
            <div><strong>Periode:</strong> <?= date('F Y', strtotime($filterMonth . '-01')) ?></div>
            <div><strong>Tanggal:</strong> <?= date('d M Y') ?></div>
        </div>

        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:30px" class="text-center">No</th>
                    <th>Tagihan</th>
                    <th>Kategori</th>
                    <th>Keterangan</th>
                    <th class="text-right">Nominal</th>
                </tr>
            </thead>
            <tbody id="printTableBody2">
                <!-- Filled by JS -->
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right"><strong>TOTAL PENCAIRAN</strong></td>
                    <td class="text-right" id="printTotal2">Rp 0</td>
                </tr>
            </tfoot>
        </table>

        <div class="print-note">
            <strong>Catatan:</strong> Mohon pencairan dana untuk pembayaran tagihan di atas. Dana akan digunakan untuk operasional bisnis sesuai dengan tagihan yang tertera.
        </div>

        <div class="signature-row">
            <div class="signature-box">
                <div class="sig-title">Diajukan oleh,</div>
                <div class="sig-line"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff') ?></div>
            </div>
            <div class="signature-box">
                <div class="sig-title">Disetujui oleh,</div>
                <div class="sig-line">Owner</div>
            </div>
        </div>

        <div class="print-footer">
            Dicetak dari <span class="sys">ADF System</span> — <?= htmlspecialchars($company['name']) ?> © <?= date('Y') ?>
            <br><?= date('d M Y, H:i') ?> WIB
        </div>
    </div>
</div>

<script>
let selectedBills = [];
let addedSuppliers = [];
let supplierIdCounter = 1;

const supplierCategories = {
    'food': { label: 'Makanan/Bahan', icon: '🍽️', color: '#ef4444' },
    'grocery': { label: 'Belanja Harian', icon: '🛒', color: '#f59e0b' },
    'supply': { label: 'Perlengkapan', icon: '📦', color: '#3b82f6' },
    'transport': { label: 'Transport', icon: '🚗', color: '#06b6d4' },
    'gas': { label: 'BBM/Gas', icon: '⛽', color: '#f97316' },
    'other': { label: 'Lainnya', icon: '📋', color: '#64748b' }
};

// Category Modal Functions
function openCategoryModal() {
    document.getElementById('catModal').classList.add('show');
    document.getElementById('newCatName').value = '';
    document.getElementById('newCatName').focus();
}

function closeCategoryModal() {
    document.getElementById('catModal').classList.remove('show');
}

function saveNewCategory() {
    const name = document.getElementById('newCatName').value.trim();
    const icon = document.getElementById('newCatIcon').value;
    
    if (!name) {
        alert('Masukkan nama kategori!');
        document.getElementById('newCatName').focus();
        return;
    }
    
    // Create unique key
    const key = 'custom_' + Date.now();
    
    // Add to supplierCategories
    supplierCategories[key] = {
        label: name,
        icon: icon,
        color: '#64748b'
    };
    
    // Add to dropdown
    const select = document.getElementById('supplierCategory');
    const option = document.createElement('option');
    option.value = key;
    option.textContent = icon + ' ' + name;
    select.appendChild(option);
    
    // Select the new category
    select.value = key;
    
    // Close modal
    closeCategoryModal();
}

// Close modal on overlay click
document.getElementById('catModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCategoryModal();
});

// Add supplier bill
function addSupplierBill() {
    const nameInput = document.getElementById('supplierName');
    const categoryInput = document.getElementById('supplierCategory');
    const amountInput = document.getElementById('supplierAmount');
    
    const name = nameInput.value.trim();
    const category = categoryInput.value;
    const amount = parseInt(amountInput.value) || 0;
    
    if (!name) {
        alert('Masukkan nama supplier/toko!');
        nameInput.focus();
        return;
    }
    if (amount <= 0) {
        alert('Masukkan nominal tagihan!');
        amountInput.focus();
        return;
    }
    
    const catInfo = supplierCategories[category] || supplierCategories['other'];
    const supplier = {
        id: 'supplier_' + supplierIdCounter++,
        name: name,
        vendor: name,
        account: '-',
        due: '<?= date('d M Y') ?>',
        amount: amount,
        status: 'supplier',
        category: catInfo.label,
        icon: catInfo.icon,
        isSupplier: true
    };
    
    addedSuppliers.push(supplier);
    renderSupplierList();
    updateSelection();
    
    // Clear inputs
    nameInput.value = '';
    amountInput.value = '';
    nameInput.focus();
}

// Remove supplier bill
function removeSupplierBill(id) {
    addedSuppliers = addedSuppliers.filter(s => s.id !== id);
    renderSupplierList();
    updateSelection();
}

// Toggle supplier selection
function toggleSupplier(id) {
    const supplier = addedSuppliers.find(s => s.id === id);
    if (supplier) {
        supplier.selected = !supplier.selected;
        updateSelection();
    }
}

// Render supplier list
function renderSupplierList() {
    const container = document.getElementById('addedSuppliersList');
    if (addedSuppliers.length === 0) {
        container.innerHTML = '';
        return;
    }
    
    let html = '';
    addedSuppliers.forEach(s => {
        const checked = s.selected !== false ? 'checked' : '';
        html += `<div class="supplier-item">
            <input type="checkbox" class="supplier-check" ${checked} onchange="toggleSupplier('${s.id}')">
            <div class="supplier-info">
                <div class="supplier-name">${s.name}</div>
                <div class="supplier-cat">${s.icon} ${s.category}</div>
            </div>
            <div class="supplier-amount">Rp ${s.amount.toLocaleString('id-ID')}</div>
            <button type="button" class="btn-remove" onclick="removeSupplierBill('${s.id}')">✕ Hapus</button>
        </div>`;
    });
    container.innerHTML = html;
}

function toggleSelectAll(checkbox) {
    const items = document.querySelectorAll('.bill-item');
    items.forEach(item => {
        item.checked = checkbox.checked;
    });
    // Also toggle supplier items
    addedSuppliers.forEach(s => {
        s.selected = checkbox.checked;
    });
    renderSupplierList();
    updateSelection();
}

function updateSelection() {
    selectedBills = [];
    let total = 0;
    
    // Get checked template bills
    const items = document.querySelectorAll('.bill-item:checked');
    items.forEach(item => {
        selectedBills.push({
            id: item.dataset.id,
            name: item.dataset.name,
            vendor: item.dataset.vendor,
            account: item.dataset.account,
            due: item.dataset.due,
            amount: parseInt(item.dataset.amount),
            status: item.dataset.status,
            category: item.dataset.category,
            icon: item.dataset.icon,
            isSupplier: false
        });
        total += parseInt(item.dataset.amount);
    });
    
    // Add selected supplier bills
    addedSuppliers.forEach(s => {
        if (s.selected !== false) {
            selectedBills.push(s);
            total += s.amount;
        }
    });
    
    document.getElementById('selectedCount').textContent = selectedBills.length;
    document.getElementById('selectedTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
    
    const btnGenerate = document.getElementById('btnGenerate');
    const btnWa = document.getElementById('btnWa');
    if (btnGenerate) btnGenerate.disabled = selectedBills.length === 0;
    if (btnWa) btnWa.disabled = selectedBills.length === 0;
    
    // Update row highlight
    document.querySelectorAll('.rpt-table tbody tr').forEach(row => {
        const checkbox = row.querySelector('.bill-item');
        if (checkbox && checkbox.checked) {
            row.classList.add('selected');
        } else {
            row.classList.remove('selected');
        }
    });
    
    // Update selectAll checkbox
    const allItems = document.querySelectorAll('.bill-item');
    const checkedItems = document.querySelectorAll('.bill-item:checked');
    const selectAllEl = document.getElementById('selectAll');
    if (selectAllEl) {
        const allSupplierSelected = addedSuppliers.length === 0 || addedSuppliers.every(s => s.selected !== false);
        selectAllEl.checked = allItems.length > 0 && allItems.length === checkedItems.length && allSupplierSelected;
    }
}

function generateReport() {
    if (selectedBills.length === 0) {
        alert('Pilih minimal 1 tagihan!');
        return;
    }
    
    // Use printArea2 which is outside the if-else block
    const tbody = document.getElementById('printTableBody2');
    let html = '';
    let total = 0;
    
    selectedBills.forEach((bill, idx) => {
        total += bill.amount;
        const keterangan = bill.isSupplier ? 'Belanja Supplier' : `Jatuh tempo: ${bill.due}`;
        html += `<tr>
            <td class="text-center">${idx + 1}</td>
            <td>
                <div class="bill-name">${bill.name}</div>
                <div class="bill-vendor">${bill.vendor}</div>
            </td>
            <td>${bill.icon} ${bill.category}</td>
            <td>${keterangan}</td>
            <td class="text-right">Rp ${bill.amount.toLocaleString('id-ID')}</td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
    document.getElementById('printTotal2').textContent = 'Rp ' + total.toLocaleString('id-ID');
    
    // Show print area
    document.getElementById('printArea2').classList.add('show');
    
    // Scroll to print area
    document.getElementById('printArea2').scrollIntoView({ behavior: 'smooth' });
    
    // Trigger print after a short delay
    setTimeout(() => {
        window.print();
    }, 500);
}

function shareWhatsApp() {
    if (selectedBills.length === 0) {
        alert('Pilih minimal 1 tagihan!');
        return;
    }
    
    let total = 0;
    let message = `*PENGAJUAN PENCAIRAN DANA*\n`;
    message += `_<?= htmlspecialchars($company['name']) ?>_\n`;
    message += `━━━━━━━━━━━━━━━\n\n`;
    message += `📅 *Periode:* <?= date('F Y', strtotime($filterMonth . '-01')) ?>\n`;
    message += `📆 *Tanggal:* <?= date('d M Y') ?>\n\n`;
    message += `*Daftar Tagihan:*\n\n`;
    
    selectedBills.forEach((bill, idx) => {
        total += bill.amount;
        const statusIcon = bill.isSupplier ? '🛒' : (bill.status === 'overdue' ? '🔴' : '🟡');
        message += `*${idx + 1}. ${bill.name}*\n`;
        message += `   ${bill.icon} ${bill.category}\n`;
        message += `   ${statusIcon} Rp ${bill.amount.toLocaleString('id-ID')}\n`;
        if (!bill.isSupplier) {
            message += `   📆 Jatuh tempo: ${bill.due}\n`;
        }
        if (bill.account && bill.account !== '-') {
            message += `   🏦 No: ${bill.account}\n`;
        }
        message += `\n`;
    });
    
    message += `━━━━━━━━━━━━━━━\n`;
    message += `💰 *TOTAL: Rp ${total.toLocaleString('id-ID')}*\n\n`;
    message += `Mohon persetujuan untuk pencairan dana.\n\n`;
    message += `_Diajukan oleh:_\n`;
    message += `*<?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'Staff') ?>*\n`;
    message += `_<?= date('d M Y, H:i') ?> WIB_`;
    
    const encoded = encodeURIComponent(message);
    window.open(`https://wa.me/?text=${encoded}`, '_blank');
}

// Initialize feather icons
if (typeof feather !== 'undefined') feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>