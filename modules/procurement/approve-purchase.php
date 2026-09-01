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

$po_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0);

// Get PO details first (needed for validation logic)
$po = getPurchaseOrder($po_id);
if (!$po) {
    $_SESSION['error'] = 'Purchase Order tidak ditemukan';
    header('Location: purchase-orders.php');
    exit;
}

// Handle approval with file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    
    // Check if payment already exists (Recovery Mode)
    $payment_exists = $db->fetchOne("SELECT id FROM cash_book WHERE reference_no = ?", [$po['po_number']]);
    
    // Validate file upload ONLY if payment doesn't exist yet
    if (!$payment_exists && (!isset($_FILES['nota_image']) || $_FILES['nota_image']['error'] !== UPLOAD_ERR_OK)) {
        $_SESSION['error'] = '❌ Upload gambar nota wajib dilakukan sebelum approve!';
        header('Location: purchase-orders.php');
        exit;
    }
    
    $options = [];
    if (isset($_FILES['nota_image'])) {
        $options['attachment_file'] = $_FILES['nota_image'];
    }
    
    // Approve and post to cash book
    $result = approvePurchaseOrderAndPay($po_id, $currentUser['id'], $options);
    
    if ($result['success']) {
        $_SESSION['success'] = '✅ Purchase Order berhasil di-approve dan dibayar!<br>
            💰 <strong>Kas Besar berkurang sebesar Rp ' . number_format($result['amount'], 0, ',', '.') . '</strong><br>
            📝 Transaksi tercatat di Buku Kas Besar.<br>
            <a href="../../modules/cashbook/index.php" style="color: #059669; text-decoration: underline; font-weight: 600;">👉 Lihat di Buku Kas</a>';
        header('Location: purchases.php'); // Redirect to Purchases List (Completed)
        exit;
    } else {
        $_SESSION['error'] = '❌ Gagal approve: ' . $result['message'];
        header('Location: purchase-orders.php');
        exit;
    }
}

// $po is already fetched above
// remove redundant check
if (!in_array($po['status'], ['submitted', 'approved'])) {
    $_SESSION['error'] = 'PO harus berstatus Submitted untuk diproses';
    header('Location: purchase-orders.php');
    exit;
}

$pageTitle = 'Approve Purchase Invoice - ' . $po['po_number'];

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="purchase-orders.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i>
        </a>
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                💳 Purchase Invoice
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Approve dan posting ke Kas Besar - <?php echo $po['po_number']; ?></p>
        </div>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<!-- PO Information -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
    <div class="card">
        <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Informasi PO</h3>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">PO Number</div>
                <div style="font-weight: 600; font-size: 1.125rem;"><?php echo $po['po_number']; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Status</div>
                <span class="badge badge-warning" style="font-size: 0.875rem;">
                    <?php echo ucfirst($po['status']); ?>
                </span>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Supplier</div>
                <div style="font-weight: 600;"><?php echo $po['supplier_name']; ?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $po['supplier_code']; ?></div>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Tanggal PO</div>
                <div><?php echo date('d M Y', strtotime($po['po_date'])); ?></div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem;">Dibuat Oleh</div>
                <div><?php echo $po['created_by_name']; ?> - <?php echo date('d M Y H:i', strtotime($po['created_at'])); ?></div>
            </div>
        </div>
    </div>
    
    <div class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; opacity: 0.9;">Total Pembayaran</h3>
        <div style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem;">
            Rp <?php echo number_format($po['total_amount'], 0, ',', '.'); ?>
        </div>
        <div style="font-size: 0.75rem; opacity: 0.8;">
            <?php echo count($po['items']); ?> items
        </div>
    </div>
</div>

<!-- Items List -->
<div class="card" style="margin-bottom: 1.25rem;">
    <h3 style="font-size: 1rem; font-weight: 600; margin-bottom: 1rem;">Detail Items</h3>
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>Divisi</th>
                <th class="text-right">Qty</th>
                <th>Unit</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Subtotal</th>
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
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: var(--bg-secondary); font-weight: 700;">
                <td colspan="6" class="text-right" style="font-size: 1.125rem;">TOTAL:</td>
                <td class="text-right" style="font-size: 1.25rem; color: var(--primary-color);">
                    Rp <?php echo number_format($po['total_amount'], 0, ',', '.'); ?>
                </td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- Approval Form -->
<div class="card" style="background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%); border: 3px solid #ffc107; box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);">
    <div style="background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%); padding: 1.25rem; border-radius: 0.75rem 0.75rem 0 0; margin: -1.25rem -1.25rem 1.5rem -1.25rem; color: white;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 56px; height: 56px; background: rgba(255,255,255,0.25); backdrop-filter: blur(10px); border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid rgba(255,255,255,0.5);">
                <i data-feather="alert-circle" style="width: 28px; height: 28px; color: white; stroke-width: 2.5;"></i>
            </div>
            <div>
                <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 0.25rem; text-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    📸 Upload Nota Sebelum Approve
                </h3>
                <p style="font-size: 0.875rem; opacity: 0.95; margin: 0;">Wajib upload bukti pembayaran untuk validasi</p>
            </div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
        <div style="padding: 1rem; background: white; border-radius: 0.5rem; border-left: 4px solid #ff9800;">
            <div style="font-size: 0.75rem; color: #666; margin-bottom: 0.25rem; font-weight: 600; text-transform: uppercase;">💰 Total Pembayaran</div>
            <div style="font-size: 1.5rem; font-weight: 800; color: #ff6f00;">Rp <?php echo number_format($po['total_amount'], 0, ',', '.'); ?></div>
        </div>
        <div style="padding: 1rem; background: white; border-radius: 0.5rem; border-left: 4px solid #4caf50;">
            <div style="font-size: 0.75rem; color: #666; margin-bottom: 0.25rem; font-weight: 600; text-transform: uppercase;">✓ Status Setelah Approve</div>
            <div style="font-size: 1.125rem; font-weight: 700; color: #2e7d32;">COMPLETED</div>
        </div>
    </div>
    
    <div style="background: white; padding: 1.25rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 2px dashed #ffc107;">
        <div style="font-weight: 700; color: #e65100; margin-bottom: 0.75rem; font-size: 0.875rem;">⚠️ PERHATIAN:</div>
        <ul style="margin: 0; padding-left: 1.5rem; color: #d84315; line-height: 1.8;">
            <li><strong>Uang akan keluar dari Kas Besar</strong> dan tercatat otomatis</li>
            <li>Transaksi <strong>tidak dapat dibatalkan</strong> setelah approve</li>
            <li><strong>Wajib upload foto/scan nota</strong> asli dari supplier</li>
        </ul>
    </div>
    
    <form method="POST" enctype="multipart/form-data" id="approveForm">
        <input type="hidden" name="approve" value="1">
        
        <div class="form-group">
            <label class="form-label" style="font-weight: 700; font-size: 1rem;">
                <i data-feather="image" style="width: 16px; height: 16px;"></i>
                Upload Nota/Invoice *
            </label>
            <input type="file" name="nota_image" class="form-control" accept="image/jpeg,image/png,image/jpg,application/pdf" required id="notaFile">
            <small style="color: var(--text-muted);">Format: JPG, PNG, atau PDF (Max 5MB)</small>
        </div>
        
        <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem;">
            <a href="purchase-orders.php" class="btn btn-secondary">
                <i data-feather="x" style="width: 16px; height: 16px;"></i>
                Batal
            </a>
            <button type="submit" class="btn btn-success" style="min-width: 200px;" id="submitBtn">
                <i data-feather="check-circle" style="width: 16px; height: 16px;"></i>
                Approve & Bayar
            </button>
        </div>
    </form>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
        <!-- Animated Circle -->
        <div style="margin-bottom: 2rem;">
            <div class="spinner-border" style="width: 80px; height: 80px; border: 6px solid rgba(16,185,129,0.2); border-top-color: #10b981; animation: spin 1s linear infinite;"></div>
        </div>
        
        <!-- Progress Steps -->
        <div style="background: white; padding: 2.5rem 3rem; border-radius: 1.5rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); min-width: 400px;">
            <div id="step1" class="loading-step active">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div class="step-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; flex-shrink: 0;">1</div>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">Mengupload Nota</div>
                        <div style="font-size: 0.875rem; color: #6b7280;">Sedang memproses file...</div>
                    </div>
                    <div class="spinner-border spinner-border-sm" style="color: #10b981;"></div>
                </div>
            </div>
            
            <div id="step2" class="loading-step" style="opacity: 0.5;">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div class="step-icon" style="width: 40px; height: 40px; background: #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #6b7280; font-weight: 700; flex-shrink: 0;">2</div>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">Approve Purchase Order</div>
                        <div style="font-size: 0.875rem; color: #6b7280;">Mengubah status PO...</div>
                    </div>
                </div>
            </div>
            
            <div id="step3" class="loading-step" style="opacity: 0.5;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="step-icon" style="width: 40px; height: 40px; background: #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #6b7280; font-weight: 700; flex-shrink: 0;">3</div>
                    <div style="text-align: left; flex: 1;">
                        <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">Posting ke Kas Besar</div>
                        <div style="font-size: 0.875rem; color: #6b7280;">Mencatat pembayaran...</div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #f3f4f6; text-align: center;">
                <div style="font-size: 0.875rem; color: #10b981; font-weight: 600;">
                    <i data-feather="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                    Mohon tunggu, jangan tutup halaman ini
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.loading-step.active {
    animation: pulse 2s ease-in-out infinite;
}

.loading-step.active .step-icon {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
}

.loading-step.completed .step-icon {
    background: linear-gradient(135deg, #10b981, #059669) !important;
    color: white !important;
}
</style>

<script>
    feather.replace();
    
    document.getElementById('approveForm').addEventListener('submit', function(e) {
        const fileInput = document.getElementById('notaFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('❌ Harap upload nota terlebih dahulu!');
            return;
        }
        
        // Show loading overlay
        document.getElementById('loadingOverlay').style.display = 'block';
        document.getElementById('submitBtn').disabled = true;
        
        // Simulate progress steps
        setTimeout(function() {
            document.getElementById('step1').classList.add('completed');
            document.getElementById('step1').style.opacity = '1';
            document.getElementById('step2').classList.add('active');
            document.getElementById('step2').style.opacity = '1';
        }, 1000);
        
        setTimeout(function() {
            document.getElementById('step2').classList.add('completed');
            document.getElementById('step3').classList.add('active');
            document.getElementById('step3').style.opacity = '1';
        }, 2000);
    });
</script>

<?php include '../../includes/footer.php'; ?>
