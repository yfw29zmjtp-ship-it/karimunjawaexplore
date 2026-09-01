<?php
/**
 * Laporan PO Belum Dibayar (Unpaid Purchase Orders)
 * Untuk pengajuan pembayaran tagihan ke Owner
 * Layout A4 Compact & Elegant
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Laporan PO Belum Dibayar';

// Get filters
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01', strtotime('-3 months'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'due_date';

// Get suppliers for filter
$suppliers = $db->fetchAll("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Get unpaid POs from purchase_orders_header (status NOT completed or cancelled)
$where = "poh.status NOT IN ('completed', 'cancelled')";
$params = [];

if ($supplier_id > 0) {
    $where .= " AND poh.supplier_id = :supplier_id";
    $params['supplier_id'] = $supplier_id;
}
if ($date_from) {
    $where .= " AND poh.po_date >= :date_from";
    $params['date_from'] = $date_from;
}
if ($date_to) {
    $where .= " AND poh.po_date <= :date_to";
    $params['date_to'] = $date_to;
}

$order_by = match($sort_by) {
    'amount_desc' => 'poh.grand_total DESC',
    'amount_asc' => 'poh.grand_total ASC',
    'oldest' => 'poh.po_date ASC',
    'newest' => 'poh.po_date DESC',
    'supplier' => 's.supplier_name ASC, poh.po_date ASC',
    default => 'poh.expected_delivery_date ASC, poh.po_date ASC'
};

$unpaidInvoices = $db->fetchAll("
    SELECT 
        poh.id, poh.po_number as invoice_number, poh.po_number, poh.po_date as invoice_date, 
        poh.expected_delivery_date as due_date,
        poh.total_amount, poh.discount_amount, poh.tax_amount, poh.grand_total,
        0 as paid_amount, poh.grand_total as remaining,
        poh.status as payment_status, poh.notes,
        s.supplier_name, s.contact_person, s.phone,
        s.bank_name, s.bank_account_number, s.bank_account_name,
        DATEDIFF(CURDATE(), COALESCE(poh.expected_delivery_date, poh.po_date)) as days_overdue
    FROM purchase_orders_header poh
    JOIN suppliers s ON poh.supplier_id = s.id
    WHERE {$where}
    ORDER BY {$order_by}
", $params);

// Calculate totals
$totalUnpaid = 0;
$totalPaid = 0;
$totalRemaining = 0;
$countOverdue = 0;

foreach ($unpaidInvoices as $inv) {
    $totalUnpaid += $inv['grand_total'];
    $totalPaid += $inv['paid_amount'] ?? 0;
    $totalRemaining += $inv['remaining'];
    if ($inv['days_overdue'] > 0) $countOverdue++;
}

// Group by supplier
$supplierSummary = [];
foreach ($unpaidInvoices as $inv) {
    $sname = $inv['supplier_name'];
    if (!isset($supplierSummary[$sname])) {
        $supplierSummary[$sname] = ['count' => 0, 'total' => 0, 'bank' => $inv['bank_name'], 'rekening' => $inv['bank_account_number'], 'atas_nama' => $inv['bank_account_name']];
    }
    $supplierSummary[$sname]['count']++;
    $supplierSummary[$sname]['total'] += $inv['remaining'];
}
arsort($supplierSummary);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= BUSINESS_NAME ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #1a1a1a;
            background: #f0f0f0;
        }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 10px auto;
            background: white;
            padding: 12mm 15mm;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Header */
        .header {
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .company-info h1 {
            font-size: 14pt;
            color: #1e3a8a;
            font-weight: 700;
            margin-bottom: 2px;
        }
        
        .company-info p {
            font-size: 8pt;
            color: #666;
        }
        
        .doc-info {
            text-align: right;
            font-size: 8pt;
        }
        
        .doc-info .doc-number {
            font-size: 10pt;
            font-weight: 700;
            color: #dc2626;
        }
        
        .report-title {
            text-align: center;
            margin: 10px 0;
            padding: 8px;
            background: #1e3a8a;
            color: white;
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        /* Summary */
        .summary-row {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }
        
        .summary-box {
            flex: 1;
            padding: 8px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        
        .summary-box.danger {
            background: #fef2f2;
            border-color: #fecaca;
        }
        
        .summary-box .label {
            font-size: 7pt;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 2px;
        }
        
        .summary-box .value {
            font-size: 11pt;
            font-weight: 700;
            color: #1e293b;
        }
        
        .summary-box.danger .value {
            color: #dc2626;
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            margin-bottom: 10px;
        }
        
        th {
            background: #1e3a8a;
            color: white;
            padding: 6px 5px;
            text-align: left;
            font-weight: 600;
            font-size: 7.5pt;
        }
        
        td {
            padding: 5px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        
        tr:nth-child(even) { background: #f9fafb; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .amount {
            font-family: 'Consolas', monospace;
            font-size: 8pt;
        }
        
        .amount-large {
            font-weight: 700;
            color: #dc2626;
        }
        
        /* Status badge */
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 6.5pt;
            font-weight: 600;
        }
        
        .badge-danger { background: #fef2f2; color: #dc2626; }
        .badge-warning { background: #fffbeb; color: #d97706; }
        .badge-success { background: #f0fdf4; color: #16a34a; }
        
        /* Total Footer */
        .total-section {
            background: #fef2f2;
            border: 2px solid #fecaca;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 9pt;
        }
        
        .total-row.grand {
            font-size: 12pt;
            font-weight: 700;
            color: #dc2626;
            border-top: 1px solid #fecaca;
            padding-top: 8px;
            margin-top: 5px;
        }
        
        /* Supplier Summary */
        .supplier-section {
            margin-top: 12px;
            page-break-inside: avoid;
        }
        
        .supplier-section h3 {
            font-size: 9pt;
            color: #1e3a8a;
            border-bottom: 1px solid #1e3a8a;
            padding-bottom: 3px;
            margin-bottom: 8px;
        }
        
        .supplier-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dotted #e5e7eb;
            font-size: 8pt;
        }
        
        .supplier-item .bank-info {
            font-size: 7pt;
            color: #64748b;
        }
        
        /* Signature */
        .signature-section {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            page-break-inside: avoid;
        }
        
        .signature-box {
            width: 28%;
            text-align: center;
        }
        
        .signature-box .title {
            font-size: 8pt;
            font-weight: 600;
            margin-bottom: 45px;
        }
        
        .signature-box .line {
            border-top: 1px solid #333;
            padding-top: 3px;
            font-size: 8pt;
        }
        
        /* Notes */
        .notes {
            font-size: 7pt;
            color: #64748b;
            margin-top: 15px;
            padding: 8px;
            background: #f8fafc;
            border-radius: 4px;
        }
        
        /* Print styles */
        @media print {
            body { background: white; }
            .page { 
                margin: 0; 
                box-shadow: none; 
                padding: 10mm 12mm;
                width: 100%;
            }
            .no-print { display: none !important; }
            @page { 
                size: A4; 
                margin: 5mm; 
            }
        }
        
        /* Filter bar (no print) */
        .filter-bar {
            width: 210mm;
            margin: 10px auto;
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            gap: 10px;
            align-items: end;
        }
        
        .filter-bar .form-group {
            flex: 1;
        }
        
        .filter-bar label {
            display: block;
            font-size: 8pt;
            font-weight: 600;
            margin-bottom: 4px;
            color: #475569;
        }
        
        .filter-bar select,
        .filter-bar input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 9pt;
        }
        
        .filter-bar button,
        .filter-bar a {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 9pt;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-filter { background: #3b82f6; color: white; }
        .btn-print { background: #1e3a8a; color: white; }
        .btn-back { background: #e5e7eb; color: #374151; }
    </style>
</head>
<body>
    <!-- Filter Bar -->
    <div class="filter-bar no-print">
        <form method="GET" style="display: flex; gap: 10px; width: 100%; align-items: end;">
            <div class="form-group">
                <label>Supplier</label>
                <select name="supplier_id">
                    <option value="">Semua</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $supplier_id == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Dari</label>
                <input type="date" name="date_from" value="<?= $date_from ?>">
            </div>
            <div class="form-group">
                <label>Sampai</label>
                <input type="date" name="date_to" value="<?= $date_to ?>">
            </div>
            <div class="form-group">
                <label>Urut</label>
                <select name="sort_by">
                    <option value="due_date" <?= $sort_by == 'due_date' ? 'selected' : '' ?>>Jatuh Tempo</option>
                    <option value="oldest" <?= $sort_by == 'oldest' ? 'selected' : '' ?>>Terlama</option>
                    <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>Terbaru</option>
                    <option value="amount_desc" <?= $sort_by == 'amount_desc' ? 'selected' : '' ?>>Terbesar</option>
                    <option value="supplier" <?= $sort_by == 'supplier' ? 'selected' : '' ?>>Supplier</option>
                </select>
            </div>
            <button type="submit" class="btn-filter">Filter</button>
            <button type="button" onclick="window.print()" class="btn-print">🖨️ Print</button>
            <a href="purchase-orders.php" class="btn-back">← Kembali</a>
        </form>
    </div>
    
    <!-- Report Page -->
    <div class="page">
        <!-- Header -->
        <div class="header">
            <div class="header-top">
                <div class="company-info">
                    <h1>🏨 <?= BUSINESS_NAME ?></h1>
                    <p>Karimunjawa, Jepara - Jawa Tengah</p>
                </div>
                <div class="doc-info">
                    <div class="doc-number">REF: INV-<?= date('Ymd-His') ?></div>
                    <div>Tanggal: <?= date('d F Y') ?></div>
                    <div>Periode: <?= date('d/m/Y', strtotime($date_from)) ?> - <?= date('d/m/Y', strtotime($date_to)) ?></div>
                </div>
            </div>
        </div>
        
        <div class="report-title">LAPORAN PO BELUM SELESAI / BELUM DIBAYAR</div>
        
        <!-- Summary -->
        <div class="summary-row">
            <div class="summary-box danger">
                <div class="label">Total Tagihan</div>
                <div class="value">Rp <?= number_format($totalRemaining, 0, ',', '.') ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Jumlah PO</div>
                <div class="value"><?= count($unpaidInvoices) ?></div>
            </div>
            <div class="summary-box">
                <div class="label">Jumlah Supplier</div>
                <div class="value"><?= count($supplierSummary) ?></div>
            </div>
            <div class="summary-box danger">
                <div class="label">Jatuh Tempo</div>
                <div class="value"><?= $countOverdue ?></div>
            </div>
        </div>
        
        <!-- Detail Table -->
        <table>
            <thead>
                <tr>
                    <th style="width:25px;">No</th>
                    <th style="width:80px;">No. PO</th>
                    <th>Supplier</th>
                    <th style="width:60px;" class="text-center">Tgl PO</th>
                    <th style="width:60px;" class="text-center">Jatuh Tempo</th>
                    <th style="width:85px;" class="text-right">Total</th>
                    <th style="width:85px;" class="text-right">Sisa</th>
                    <th style="width:55px;" class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($unpaidInvoices)): ?>
                    <tr><td colspan="8" class="text-center" style="padding:20px;">Tidak ada tagihan belum dibayar</td></tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($unpaidInvoices as $inv): ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td>
                                <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
                                <br><span style="font-size:7pt;color:#666;">Status: <?= ucfirst($inv['payment_status']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($inv['supplier_name']) ?></td>
                            <td class="text-center"><?= date('d/m/y', strtotime($inv['invoice_date'])) ?></td>
                            <td class="text-center"><?= $inv['due_date'] ? date('d/m/y', strtotime($inv['due_date'])) : '-' ?></td>
                            <td class="text-right amount"><?= number_format($inv['grand_total'], 0, ',', '.') ?></td>
                            <td class="text-right amount amount-large"><?= number_format($inv['remaining'], 0, ',', '.') ?></td>
                            <td class="text-center">
                                <?php if ($inv['days_overdue'] > 7): ?>
                                    <span class="badge badge-danger"><?= $inv['days_overdue'] ?>hr</span>
                                <?php elseif ($inv['days_overdue'] > 0): ?>
                                    <span class="badge badge-warning"><?= $inv['days_overdue'] ?>hr</span>
                                <?php else: ?>
                                    <span class="badge badge-success">OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Total Section -->
        <?php if (!empty($unpaidInvoices)): ?>
            <div class="total-section">
                <div class="total-row">
                    <span>Total PO (<?= count($unpaidInvoices) ?> PO)</span>
                    <span class="amount">Rp <?= number_format($totalUnpaid, 0, ',', '.') ?></span>
                </div>
                <?php if ($totalPaid > 0): ?>
                    <div class="total-row">
                        <span>Sudah Dibayar</span>
                        <span class="amount" style="color:#16a34a;">- Rp <?= number_format($totalPaid, 0, ',', '.') ?></span>
                    </div>
                <?php endif; ?>
                <div class="total-row grand">
                    <span>💰 TOTAL YANG HARUS DIBAYAR</span>
                    <span>Rp <?= number_format($totalRemaining, 0, ',', '.') ?></span>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Supplier Summary with Bank Info -->
        <?php if (!empty($supplierSummary)): ?>
            <div class="supplier-section">
                <h3>📋 Ringkasan per Supplier & Rekening Pembayaran</h3>
                <?php foreach ($supplierSummary as $sname => $data): ?>
                    <div class="supplier-item">
                        <div>
                            <strong><?= htmlspecialchars($sname) ?></strong> (<?= $data['count'] ?> PO)
                            <?php if ($data['rekening']): ?>
                                <div class="bank-info">🏦 <?= $data['bank'] ?>: <strong><?= $data['rekening'] ?></strong> a.n. <?= $data['atas_nama'] ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="amount amount-large">Rp <?= number_format($data['total'], 0, ',', '.') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Signature -->
        <div class="signature-section">
            <div class="signature-box">
                <div class="title">Dibuat Oleh</div>
                <div class="line"><?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) ?></div>
            </div>
            <div class="signature-box">
                <div class="title">Disetujui</div>
                <div class="line">Manager</div>
            </div>
            <div class="signature-box">
                <div class="title">Diotorisasi</div>
                <div class="line">Owner</div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="notes">
            <strong>Catatan:</strong> Dokumen ini sebagai pengajuan pembayaran tagihan kepada Owner. Mohon segera dilakukan pembayaran untuk tagihan yang sudah jatuh tempo.
        </div>
    </div>
</body>
</html>
