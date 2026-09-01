<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $new_status = '';

    switch ($action) {
        case 'receive_warehouse':
            if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse'))) {
                $result = ['success' => false, 'message' => 'Akses Gudang Nasita ditolak'];
                break;
            }
            $receivedItems = isset($_POST['received_qty']) && is_array($_POST['received_qty']) ? $_POST['received_qty'] : [];
            $notes = trim($_POST['warehouse_notes'] ?? '');
            $result = receivePurchaseOrderToGudang($po_id, $receivedItems, $currentUser['id'], $notes);
            break;
        case 'submit':
            $new_status = 'submitted';
            $result = updatePurchaseOrderStatus($po_id, $new_status, $currentUser['id']);
            break;
        case 'approve':
            // Use special approve function that posts to cash_book
            $options = [];

            // Handle file upload
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $options['attachment_file'] = $_FILES['attachment'];
            }

            $result = approvePurchaseOrderAndPay($po_id, $currentUser['id'], $options);
            break;
        case 'reject':
            $new_status = 'rejected';
            $result = updatePurchaseOrderStatus($po_id, $new_status, $currentUser['id']);
            break;
        case 'cancel':
            $new_status = 'cancelled';
            $result = updatePurchaseOrderStatus($po_id, $new_status, $currentUser['id']);
            break;
    }

    if (isset($result)) {
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: view-po.php?id=' . $po_id);
        exit;
    }
}

$po = getPurchaseOrder($po_id);

if ($po && !empty($po['business_id']) && !$auth->hasPermission('warehouse')) {
    $activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;
    if ($activeBusinessId > 0 && (int)$po['business_id'] !== $activeBusinessId) {
        http_response_code(403);
        echo 'PO ini bukan milik bisnis aktif.';
        exit;
    }
}

// Fix: Fetch attachment from new table 'transaction_attachments' if not in main table
if ($po && empty($po['attachment_path'])) {
    $attachment = $db->fetchOne("SELECT file_path FROM transaction_attachments WHERE transaction_type = 'purchase_order' AND transaction_id = ? ORDER BY id DESC LIMIT 1", [$po_id]);
    if ($attachment) {
        $po['attachment_path'] = $attachment['file_path'];
    }
}

if (!$po) {
    header('Location: purchase-orders.php');
    exit;
}

// Check if payment was deleted from cash book
$paymentDeleted = false;
if ($po['status'] === 'submitted' && $po['approved_at']) {
    // PO was approved but now back to submitted - payment was deleted
    $paymentDeleted = true;
}

$pageTitle = 'PO: ' . $po['po_number'];

include '../../includes/header.php';
?>

<?php if ($paymentDeleted): ?>
    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(245,158,11,0.15);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="alert-triangle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #92400e; font-size: 1.125rem; margin-bottom: 0.25rem;">⚠️ Perhatian!</div>
                <div style="color: #92400e; font-size: 0.95rem;">
                    Pembayaran PO ini telah <strong>dihapus dari Buku Kas Besar</strong>. Status PO dikembalikan ke "Menunggu Approve".<br>
                    Silakan approve ulang jika ingin melakukan pembayaran.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="purchase-orders.php" class="btn btn-secondary btn-sm">
                <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i>
            </a>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                    📋 <?php echo $po['po_number']; ?>
                </h2>
                <p style="color: var(--text-muted); font-size: 0.875rem;">PO internal bisnis untuk proses gudang dan transfer</p>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <?php if ($po['status'] === 'draft'): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="submit">
                    <button type="submit" class="btn btn-primary btn-sm">Submit PO</button>
                </form>
            <?php elseif (in_array($po['status'], ['submitted', 'approved'])): ?>
                <a href="gudang-transfer.php?po_id=<?php echo (int)$po['id']; ?>" class="btn btn-success btn-sm">
                    <i data-feather="send" style="width: 14px; height: 14px;"></i>
                    Siapkan Transfer Gudang
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i data-feather="printer" style="width: 14px; height: 14px;"></i> Print
            </button>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <?php echo $_SESSION['success'];
        unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <?php echo $_SESSION['error'];
        unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Informasi PO</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">PO Number</div>
                <div style="font-weight: 600;"><?php echo $po['po_number']; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Status</div>
                <?php
                $status_colors = [
                    'draft' => 'secondary',
                    'submitted' => 'warning',
                    'approved' => 'success',
                    'rejected' => 'danger',
                    'partially_received' => 'info',
                    'completed' => 'primary',
                    'cancelled' => 'danger'
                ];
                $badge_color = $status_colors[$po['status']] ?? 'secondary';
                ?>
                <span class="badge badge-<?php echo $badge_color; ?>">
                    <?php echo ucfirst(str_replace('_', ' ', $po['status'])); ?>
                </span>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">PO Date</div>
                <div><?php echo date('d M Y', strtotime($po['po_date'])); ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Expected Delivery</div>
                <div><?php echo $po['expected_delivery_date'] ? date('d M Y', strtotime($po['expected_delivery_date'])) : '-'; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Created By</div>
                <div><?php echo $po['created_by_name']; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Created At</div>
                <div><?php echo date('d M Y H:i', strtotime($po['created_at'])); ?></div>
            </div>
        </div>
        <?php if ($po['notes']): ?>
            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--bg-tertiary);">
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Notes</div>
                <div><?php echo nl2br(htmlspecialchars($po['notes'])); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Tujuan PO</h3>
        <div style="font-weight: 700; font-size: 1.125rem; margin-bottom: 0.25rem;">Gudang Nasita (Internal)</div>
        <div style="font-size: 0.813rem; color: var(--text-muted);">Tanpa supplier eksternal / tanpa pembayaran</div>
    </div>
</div>

<div class="card">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Items (<?php echo count($po['items']); ?>)</h3>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Name</th>
                    <th>Division</th>
                    <th class="text-right">Qty</th>
                    <th>Unit</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right">Received</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($po['items'] as $index => $item): ?>
                    <tr>
                        <td><?php echo ($index + 1); ?></td>
                        <td>
                            <div style="font-weight: 600;"><?php echo $item['item_name']; ?></div>
                            <?php if ($item['item_description']): ?>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $item['item_description']; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $item['division_name']; ?></td>
                        <td class="text-right"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td><?php echo $item['unit_of_measure']; ?></td>
                        <td class="text-right">Rp <?php echo number_format($item['unit_price'], 0, ',', '.'); ?></td>
                        <td class="text-right" style="font-weight: 600;">Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></td>
                        <td class="text-right">
                            <?php if (isset($item['received_quantity']) && $item['received_quantity'] > 0): ?>
                                <span style="color: var(--success);"><?php echo number_format($item['received_quantity'], 2); ?></span>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" class="text-right" style="font-weight: 600;">Subtotal:</td>
                    <td class="text-right" style="font-weight: 700;">Rp <?php echo number_format($po['total_amount'], 0, ',', '.'); ?></td>
                    <td></td>
                </tr>
                <?php if ($po['discount_amount'] > 0): ?>
                    <tr>
                        <td colspan="6" class="text-right">Discount:</td>
                        <td class="text-right" style="color: var(--danger);">-Rp <?php echo number_format($po['discount_amount'], 0, ',', '.'); ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                <?php if ($po['tax_amount'] > 0): ?>
                    <tr>
                        <td colspan="6" class="text-right">Tax:</td>
                        <td class="text-right">Rp <?php echo number_format($po['tax_amount'], 0, ',', '.'); ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                <tr style="background: var(--bg-secondary);">
                    <td colspan="6" class="text-right" style="font-weight: 800; font-size: 1.125rem;">Grand Total:</td>
                    <td class="text-right" style="font-weight: 800; font-size: 1.25rem; color: var(--primary-color);">
                        Rp <?php echo number_format($po['grand_total'], 0, ',', '.'); ?>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Receive to Gudang Modal -->
<div id="receiveModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 760px; width: 94%; max-height: 90vh; overflow-y: auto; margin: 0;">
        <div style="background: linear-gradient(135deg, #0f9d6a 0%, #10b981 100%); color: white; padding: 1.5rem; margin: -1.25rem -1.25rem 1.25rem -1.25rem; border-radius: 1rem 1rem 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700;">
                    <i data-feather="archive" style="width: 22px; height: 22px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Terima Barang ke Gudang Nasita
                </h3>
                <p style="margin: 0.35rem 0 0 0; font-size: 0.875rem; opacity: 0.95;">Isi qty yang benar-benar datang agar stok gudang bertambah</p>
            </div>
            <button type="button" onclick="document.getElementById('receiveModal').style.display='none'" style="background: rgba(255,255,255,0.2); border: none; cursor: pointer; color: white; font-size: 1.75rem; width: 36px; height: 36px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="receive_warehouse">
            <div style="padding: 0 0.25rem;">
                <div style="margin-bottom: 1rem; color: var(--text-muted); font-size: 0.875rem;">
                    PO: <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                </div>
                <div style="display: grid; gap: 0.85rem;">
                    <?php foreach ($po['items'] as $item):
                        $remainingQty = max(0, (float)$item['quantity'] - (float)($item['received_quantity'] ?? 0));
                    ?>
                        <div style="padding: 0.9rem; border: 1px solid #e5e7eb; border-radius: 0.85rem; background: #f8fafc;">
                            <div style="display: flex; justify-content: space-between; gap: 1rem; margin-bottom: 0.65rem;">
                                <div>
                                    <div style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <div style="font-size: 0.8rem; color: #64748b;">Qty PO: <?php echo number_format($item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?> | Sisa: <?php echo number_format($remainingQty, 2); ?></div>
                                </div>
                                <div style="min-width: 140px;">
                                    <input type="number" step="0.01" min="0" max="<?php echo $remainingQty; ?>" name="received_qty[<?php echo (int)$item['id']; ?>]" class="form-control" value="<?php echo $remainingQty; ?>" style="text-align: right;">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <label class="form-label">Catatan Gudang</label>
                    <textarea name="warehouse_notes" class="form-control" rows="3" placeholder="Catatan penerimaan barang dari supplier..."></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.25rem;">
                    <button type="button" onclick="document.getElementById('receiveModal').style.display='none'" class="btn btn-secondary">Batal</button>
                    <button type="submit" class="btn btn-success">Simpan ke Gudang</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 500px; width: 90%; margin: 0;">
        <!-- Header Modal -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 1.75rem; margin: -1.5rem -1.5rem 1.5rem -1.5rem; border-radius: 1rem 1rem 0 0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1.5rem; font-weight: 700; margin: 0 0 0.25rem 0;">
                    <i data-feather="check-circle" style="width: 24px; height: 24px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Approve & Bayar PO
                </h3>
                <p style="margin: 0; font-size: 0.875rem; opacity: 0.95;">Konfirmasi pembayaran dan persetujuan purchase order</p>
            </div>
            <button type="button" onclick="document.getElementById('approveModal').style.display='none'" style="background: rgba(255,255,255,0.2); border: none; cursor: pointer; color: white; font-size: 1.75rem; width: 36px; height: 36px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="approve">

            <!-- Info PO -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #10b981; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.75rem;">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div style="background: white; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,0.15);">
                        <i data-feather="file-text" style="width: 24px; height: 24px; color: #10b981;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Nomor PO</div>
                        <div style="font-size: 1.125rem; font-weight: 700; color: #1f2937;"><?php echo $po['po_number']; ?></div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="background: white; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,0.15);">
                        <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: #10b981;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Total Pembayaran</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;">Rp <?php echo number_format($po['total_amount'], 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Warning Box -->
            <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.75rem;">
                <div style="display: flex; gap: 1rem;">
                    <div style="flex-shrink: 0;">
                        <div style="width: 32px; height: 32px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem;">!</div>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: 700; color: #92400e; margin-bottom: 0.75rem; font-size: 1rem;">Perhatian Penting</div>
                        <ul style="margin: 0; padding-left: 1.25rem; color: #92400e; line-height: 1.8;">
                            <li style="margin-bottom: 0.5rem;">PO akan di-approve dan status menjadi <strong>COMPLETED</strong></li>
                            <li style="margin-bottom: 0.5rem;">Pembayaran akan dicatat ke <strong>Kas Besar</strong></li>
                            <li>Transaksi <strong>tidak dapat dibatalkan</strong> setelah di-approve</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Upload Section -->
            <div style="background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.75rem;">
                <label class="form-label" style="display: flex; align-items: center; gap: 0.5rem; font-size: 1rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">
                    <i data-feather="upload" style="width: 20px; height: 20px; color: #6b7280;"></i>
                    Upload Nota/Invoice (Optional)
                </label>
                <input type="file" name="attachment" class="form-control" accept="image/jpeg,image/png,application/pdf,image/gif" style="padding: 0.75rem; font-size: 0.95rem;">
                <div style="margin-top: 0.75rem; font-size: 0.875rem; color: #6b7280;">
                    <i data-feather="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                    Format: JPG, PNG, PDF • Ukuran maksimal: 5MB
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" onclick="document.getElementById('approveModal').style.display='none'" class="btn btn-secondary" style="padding: 0.875rem 1.75rem; font-size: 1rem; font-weight: 600;">
                    <i data-feather="x" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Batal
                </button>
                <button type="submit" class="btn btn-success" style="padding: 0.875rem 2rem; font-size: 1rem; font-weight: 600; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
                    <i data-feather="check" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Ya, Approve & Bayar
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    @media print {

        .sidebar,
        .topbar,
        .btn,
        form button,
        #approveModal {
            display: none !important;
        }

        body {
            background: white !important;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }
    }
</style>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>