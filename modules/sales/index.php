<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Redirect to CQC-specific invoice page if current business is CQC
$currentBusiness = $_SESSION['active_business_id'] ?? '';
if (strtoupper($currentBusiness) === 'CQC' || strpos(strtoupper($currentBusiness), 'CQC') !== false) {
    header('Location: index-cqc.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Sales Invoices';

// Get filters
$payment_status = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';
$division_id = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Get divisions
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Build WHERE clause
$where = ["si.invoice_date BETWEEN :date_from AND :date_to"];
$params = ['date_from' => $date_from, 'date_to' => $date_to];

if ($payment_status) {
    $where[] = "si.payment_status = :payment_status";
    $params['payment_status'] = $payment_status;
}

if ($division_id > 0) {
    $where[] = "si.division_id = :division_id";
    $params['division_id'] = $division_id;
}

$whereClause = implode(' AND ', $where);

// Get invoices
$invoices = $db->fetchAll("
    SELECT 
        si.*,
        d.division_name,
        u.full_name as created_by_name,
        COUNT(sid.id) as items_count
    FROM sales_invoices_header si
    LEFT JOIN divisions d ON si.division_id = d.id
    LEFT JOIN users u ON si.created_by = u.id
    LEFT JOIN sales_invoices_detail sid ON si.id = sid.invoice_header_id
    WHERE $whereClause
    GROUP BY si.id
    ORDER BY si.invoice_date DESC, si.created_at DESC
    LIMIT 100
", $params);

// Calculate statistics
$stats = [
    'total_invoices' => count($invoices),
    'total_amount' => array_sum(array_column($invoices, 'total_amount')),
    'paid_amount' => array_sum(array_map(function($inv) {
        return $inv['payment_status'] === 'paid' ? $inv['total_amount'] : 0;
    }, $invoices)),
    'unpaid_amount' => array_sum(array_map(function($inv) {
        return in_array($inv['payment_status'], ['draft', 'unpaid']) ? $inv['total_amount'] : 0;
    }, $invoices))
];

include '../../includes/header.php';
?>

<?php if (isset($_SESSION['success'])): ?>
    <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(16,185,129,0.15); animation: slideInDown 0.5s ease-out;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i data-feather="check-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #065f46; font-size: 1.125rem;">✅ Berhasil!</div>
                <div style="color: #047857; font-size: 0.95rem;"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #059669; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<style>
@keyframes slideInDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📄 Sales Invoices
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola invoice penjualan & layanan</p>
        </div>
        <a href="create-invoice.php" class="btn btn-primary">
            <i data-feather="plus" style="width: 16px; height: 16px;"></i>
            Buat Invoice Baru
        </a>
    </div>
</div>

<!-- Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #6366f120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="file-text" style="width: 20px; height: 20px; color: #6366f1;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Total Invoice</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);"><?php echo $stats['total_invoices']; ?></div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #10b98120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="dollar-sign" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Total Penjualan</div>
                <div style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary);">Rp <?php echo number_format($stats['total_amount'], 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #10b98120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Terbayar</div>
                <div style="font-size: 1.125rem; font-weight: 700; color: #10b981;">Rp <?php echo number_format($stats['paid_amount'], 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
    
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #ef444420; display: flex; align-items: center; justify-content: center;">
                <i data-feather="clock" style="width: 20px; height: 20px; color: #ef4444;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Belum Bayar</div>
                <div style="font-size: 1.125rem; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($stats['unpaid_amount'], 0, ',', '.'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status Pembayaran</label>
            <select name="payment_status" class="form-control">
                <option value="">Semua Status</option>
                <option value="draft" <?php echo $payment_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                <option value="partial" <?php echo $payment_status === 'partial' ? 'selected' : ''; ?>>Sebagian</option>
                <option value="unpaid" <?php echo $payment_status === 'unpaid' ? 'selected' : ''; ?>>Belum Bayar</option>
            </select>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Divisi</label>
            <select name="division_id" class="form-control">
                <option value="0">Semua Divisi</option>
                <?php foreach ($divisions as $div): ?>
                    <option value="<?php echo $div['id']; ?>" <?php echo $division_id == $div['id'] ? 'selected' : ''; ?>>
                        <?php echo $div['division_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
        </div>
        
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
        </div>
        
        <button type="submit" class="btn btn-primary" style="height: 42px;">
            <i data-feather="filter" style="width: 16px; height: 16px;"></i> Filter
        </button>
    </form>
</div>

<!-- Invoices Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Invoice#</th>
                    <th>Tanggal</th>
                    <th>Customer</th>
                    <th>Divisi</th>
                    <th>Items</th>
                    <th class="text-right">Total</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 1rem;"></i>
                            <p>Tidak ada invoice</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);">
                                <?php echo $invoice['invoice_number']; ?>
                            </td>
                            <td><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo $invoice['customer_name']; ?></div>
                                <?php if ($invoice['customer_phone']): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $invoice['customer_phone']; ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $invoice['division_name']; ?></td>
                            <td><?php echo $invoice['items_count']; ?> items</td>
                            <td class="text-right" style="font-weight: 700; color: var(--text-primary);">
                                Rp <?php echo number_format($invoice['total_amount'], 0, ',', '.'); ?>
                            </td>
                            <td>
                                <?php
                                $status_colors = [
                                    'draft' => 'secondary',
                                    'paid' => 'success',
                                    'partial' => 'warning',
                                    'unpaid' => 'danger'
                                ];
                                $status_labels = [
                                    'draft' => 'DRAFT',
                                    'paid' => '✓ LUNAS',
                                    'partial' => '⏱ Sebagian',
                                    'unpaid' => '⏳ Belum Bayar'
                                ];
                                $badge_color = $status_colors[$invoice['payment_status']] ?? 'secondary';
                                $badge_label = $status_labels[$invoice['payment_status']] ?? ucfirst($invoice['payment_status']);
                                
                                // Define badge styles for each status
                                $badge_styles = [
                                    'draft' => 'background: #94a3b8; color: white;',
                                    'paid' => 'background: #10b981; color: white;',
                                    'partial' => 'background: #f59e0b; color: white;',
                                    'unpaid' => 'background: #ef4444; color: white;'
                                ];
                                $badge_style = $badge_styles[$invoice['payment_status']] ?? 'background: #94a3b8; color: white;';
                                ?>
                                <span style="<?php echo $badge_style; ?> padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                                    <?php echo $badge_label; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 0.5rem; justify-content: center;">
                                    <a href="view-invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-primary" title="View & Print">
                                        <i data-feather="eye" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="view-invoice.php?id=<?php echo $invoice['id']; ?>&print=1" target="_blank" class="btn btn-sm" style="background: #f59e0b; color: white;" title="Print">
                                        <i data-feather="printer" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    
                                    <?php if ($invoice['payment_status'] !== 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-success" title="Bayar Invoice" 
                                                onclick="openPaymentDialog(<?php echo $invoice['id']; ?>, '<?php echo $invoice['invoice_number']; ?>', <?php echo $invoice['total_amount']; ?>, '<?php echo $invoice['customer_name']; ?>')">
                                            <i data-feather="dollar-sign" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn btn-sm btn-danger" title="Hapus Invoice" 
                                            onclick="deleteInvoice(<?php echo $invoice['id']; ?>, '<?php echo $invoice['invoice_number']; ?>')">
                                        <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 500px; width: 90%; overflow: hidden;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700;">
                    <i data-feather="dollar-sign" style="width: 20px; height: 20px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Bayar Invoice
                </h3>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; opacity: 0.95;" id="paymentSubtitle"></p>
            </div>
            <button type="button" onclick="closePaymentModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.5rem; width: 32px; height: 32px; border-radius: 0.375rem; cursor: pointer;">&times;</button>
        </div>
        
        <form method="POST" action="pay-invoice.php" id="paymentForm">
            <div style="padding: 1.5rem;">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                
                <!-- Customer Info -->
                <div style="background: #f3f4f6; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.813rem; color: #6b7280;">Customer</div>
                    <div style="font-weight: 600; color: #1f2937;" id="paymentCustomerName"></div>
                </div>
                
                <!-- Amount Info -->
                <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; border-radius: 0.5rem; padding: 1.25rem; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.875rem; color: #065f46; margin-bottom: 0.5rem;">Total Pembayaran</div>
                    <div style="font-size: 1.75rem; font-weight: 700; color: #10b981;" id="paymentAmount"></div>
                </div>
                
                <!-- Payment Method -->
                <div class="form-group">
                    <label class="form-label">Metode Pembayaran</label>
                    <select name="payment_method" class="form-control" required>
                        <option value="cash">💵 Cash</option>
                        <option value="transfer">🔄 Transfer Bank</option>
                        <option value="debit">💳 Kartu Kredit/Debit</option>
                        <option value="qr">📱 QR Code / E-Wallet</option>
                        <option value="other">➕ Lainnya</option>
                    </select>
                </div>
                
                <!-- Notes -->
                <div class="form-group">
                    <label class="form-label">Catatan (Opsional)</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Contoh: Pembayaran lunas via BCA..."></textarea>
                </div>
                
                <!-- Warning -->
                <div style="background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 0.375rem; padding: 1rem; margin-top: 1.25rem;">
                    <div style="font-size: 0.813rem; color: #92400e;">
                        <strong>💡 Info:</strong> Pembayaran akan otomatis dicatat sebagai pendapatan di <strong>Buku Kas Besar</strong>
                    </div>
                </div>
                
                <!-- Buttons -->
                <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                    <button type="button" onclick="closePaymentModal()" class="btn btn-secondary" style="flex: 1;">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-success" style="flex: 2;">
                        <i data-feather="check" style="width: 16px; height: 16px;"></i>
                        Konfirmasi Pembayaran
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
feather.replace();

function openPaymentDialog(invoiceId, invoiceNumber, amount, customerName) {
    document.getElementById('paymentInvoiceId').value = invoiceId;
    document.getElementById('paymentSubtitle').textContent = invoiceNumber;
    document.getElementById('paymentCustomerName').textContent = customerName;
    document.getElementById('paymentAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
    document.getElementById('paymentModal').style.display = 'block';
    feather.replace();
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function deleteInvoice(invoiceId, invoiceNumber) {
    if (confirm('⚠️ PERHATIAN!\n\nApakah Anda yakin ingin menghapus invoice ' + invoiceNumber + '?\n\nData invoice ini akan dihapus permanen!')) {
        window.location.href = 'delete-invoice.php?id=' + invoiceId;
    }
}

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
    }
});
</script>

<script>
feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>
