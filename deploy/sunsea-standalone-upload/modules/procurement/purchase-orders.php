<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';
require_once '../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user is gudang/warehouse - can see all businesses' POs
$isGudang = $auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse');

// Map of business slugs to database names
$businessDatabases = [
    'narayana-hotel' => 'adf_narayana_hotel',
    'bens-cafe' => 'adf_benscafe',
    'eaat-meet' => 'adf_eat_meet'
];

// Get active business config
$businessConfig = getActiveBusinessConfig();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Purchase Orders';

// For gudang users: will fetch from all DBs below
// For regular users: fetch from active business DB only
if (!$isGudang) {
    if (!empty($businessConfig['database'])) {
        Database::switchDatabase($businessConfig['database']);
    }
}

$db = Database::getInstance();
$activeBusinessId = isset($_SESSION['business_id']) ? (int)$_SESSION['business_id'] : 0;

// Get filters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$supplier_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Get suppliers for filter
$suppliers = $db->fetchAll("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY supplier_name");

// Build filters
$filters = [];
if ($status) $filters['status'] = $status;
if ($supplier_id > 0) $filters['supplier_id'] = $supplier_id;
if ($date_from) $filters['date_from'] = $date_from;
if ($date_to) $filters['date_to'] = $date_to;
if ($activeBusinessId > 0 && !$isGudang) $filters['business_id'] = $activeBusinessId;

// Get purchase orders
if ($isGudang) {
    // Gudang user: aggregate POs from ALL business databases
    $purchase_orders = [];
    $businessNames = [
        'adf_narayana_hotel' => 'Narayana Hotel',
        'adf_benscafe' => 'Bens Cafe',
        'adf_eat_meet' => 'Eat Meet'
    ];

    foreach ($businessDatabases as $bizSlug => $bizDb) {
        try {
            Database::switchDatabase($bizDb);
            $bizDbInstance = Database::getInstance();
            $bizName = $businessNames[$bizDb] ?? $bizSlug;
            $bizPOs = $bizDbInstance->fetchAll("
                SELECT 
                    poh.*,
                    s.supplier_name,
                    s.supplier_code,
                    u.full_name as created_by_name,
                    COUNT(pod.id) as items_count,
                    '{$bizSlug}' as source_business_slug,
                    '{$bizName}' as source_business_name
                FROM purchase_orders_header poh
                LEFT JOIN suppliers s ON poh.supplier_id = s.id
                LEFT JOIN users u ON poh.created_by = u.id
                LEFT JOIN purchase_orders_detail pod ON poh.id = pod.po_header_id
                WHERE poh.po_number NOT LIKE 'GDN-%'
                GROUP BY poh.id
                ORDER BY poh.id DESC
                LIMIT 1000
            ");
            $purchase_orders = array_merge($purchase_orders, $bizPOs ?? []);
        } catch (Throwable $e) {
            error_log("Error fetching POs from {$bizDb}: " . $e->getMessage());
        }
    }

    // Sort all combined POs by date DESC
    usort($purchase_orders, function ($a, $b) {
        return strtotime($b['po_date'] ?? '0') - strtotime($a['po_date'] ?? '0');
    });
    $purchase_orders = array_slice($purchase_orders, 0, 50);
} else {
    $purchase_orders = getPurchaseOrders($filters, 50, 0);
}

// Restore to master database before rendering (header.php needs master context for theme/menus)
Database::switchDatabase(DB_NAME);

include '../../includes/header.php';
?>

<?php if (isset($_SESSION['success'])): ?>
    <!-- Success Popup Modal -->
    <div id="successPopup" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px); animation: fadeIn 0.3s ease-out;">
        <div style="background: white; border-radius: 1rem; padding: 2rem; max-width: 420px; width: 90%; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3); animation: scaleIn 0.3s ease-out;">
            <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <i data-feather="check" style="width: 40px; height: 40px; color: white; stroke-width: 3;"></i>
            </div>
            <h3 style="font-size: 1.5rem; font-weight: 700; color: #065f46; margin-bottom: 0.75rem;">Berhasil!</h3>
            <div style="color: #047857; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.6;"><?php echo $_SESSION['success'];
                                                                                                        unset($_SESSION['success']); ?></div>
            <button onclick="document.getElementById('successPopup').style.display='none'" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; font-size: 1rem; cursor: pointer;">
                OK, Mengerti
            </button>
        </div>
    </div>
    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15); animation: slideInDown 0.5s ease-out;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; color: #991b1b; font-size: 1.125rem; margin-bottom: 0.25rem;">❌ Error!</div>
                <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error'];
                                                                    unset($_SESSION['error']); ?></div>
            </div>
            <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #dc2626; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
        </div>
    </div>
<?php endif; ?>

<style>
    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Action Button Styles */
    .po-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .po-action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .po-action-btn.view {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white !important;
    }

    .po-action-btn.submit {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white !important;
    }

    .po-action-btn.nota {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: white !important;
    }

    .po-action-btn.reject {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white !important;
    }

    .po-action-btn.update {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        color: white !important;
    }

    .po-action-btn svg {
        width: 16px;
        height: 16px;
        stroke: white !important;
    }

    .po-action-group {
        display: flex;
        gap: 0.35rem;
        justify-content: center;
        align-items: center;
    }

    .po-action-wide {
        width: auto;
        padding: 0.4rem 0.75rem;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 600;
    }
</style>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem;">
                📋 Purchase Orders
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola PO internal bisnis untuk proses gudang dan transfer</p>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <a href="business-stock-incoming.php" class="btn btn-outline-secondary">
                <i data-feather="inbox" style="width: 16px; height: 16px;"></i>
                Rekaman Stock Masuk
            </a>
            <a href="create-po.php" class="btn btn-primary">
                <i data-feather="plus" style="width: 16px; height: 16px;"></i>
                Buat PO Baru
            </a>
        </div>
    </div>
</div>

<!-- Filter Section -->
<div class="card" style="margin-bottom: 1.25rem;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 1rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">Semua Status</option>
                <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="submitted" <?php echo $status === 'submitted' ? 'selected' : ''; ?>>Menunggu Proses Gudang</option>
                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Disiapkan Gudang</option>
                <option value="partially_received" <?php echo $status === 'partially_received' ? 'selected' : ''; ?>>Partially Received</option>
                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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

<!-- Statistics Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $stats = [
        'draft' => ['label' => 'Draft', 'color' => '#6366f1', 'icon' => 'edit-3'],
        'submitted' => ['label' => 'Menunggu Proses Gudang', 'color' => '#f59e0b', 'icon' => 'clock'],
        'completed' => ['label' => 'Selesai', 'color' => '#10b981', 'icon' => 'check-circle']
    ];

    foreach ($stats as $stat_status => $stat_data) {
        $count = count(array_filter($purchase_orders, function ($po) use ($stat_status) {
            return $po['status'] === $stat_status;
        }));
        $total = array_sum(array_map(function ($po) use ($stat_status) {
            return $po['status'] === $stat_status ? ($po['total_amount'] ?? 0) : 0;
        }, $purchase_orders));
    ?>
        <div class="card" style="padding: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="width: 40px; height: 40px; border-radius: 8px; background: <?php echo $stat_data['color']; ?>20; display: flex; align-items: center; justify-content: center;">
                    <i data-feather="<?php echo $stat_data['icon']; ?>" style="width: 20px; height: 20px; color: <?php echo $stat_data['color']; ?>;"></i>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $stat_data['label']; ?></div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-primary);"><?php echo $count; ?></div>
                    <div style="font-size: 0.688rem; color: var(--text-muted);">Rp <?php echo number_format($total, 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Purchase Orders Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <?php if ($isGudang): ?>
                        <th>Bisnis</th>
                    <?php endif; ?>
                    <th>Tanggal</th>
                    <th>Tujuan</th>
                    <th>Status</th>
                    <th>Items</th>
                    <th class="text-right">Total</th>
                    <th>Dibuat Oleh</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchase_orders)): ?>
                    <tr>
                        <td colspan="<?php echo $isGudang ? 9 : 8; ?>" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3; margin-bottom: 1rem;"></i>
                            <p>Tidak ada purchase order</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($purchase_orders as $po): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);">
                                <?php echo $po['po_number']; ?>
                            </td>
                            <?php if ($isGudang): ?>
                                <td style="font-weight: 600; font-size: 0.9rem; color: #6b7280;">
                                    <?php echo htmlspecialchars($po['source_business_name'] ?? $po['source_business_slug'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo date('d M Y', strtotime($po['po_date'])); ?></td>
                            <td>
                                <div style="font-weight: 600;">Gudang Nasita (Internal)</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Tanpa supplier eksternal</div>
                            </td>
                            <td>
                                <?php
                                $status_colors = [
                                    'draft' => 'secondary',
                                    'submitted' => 'warning',
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    'partially_received' => 'info',
                                    'completed' => 'success',
                                    'cancelled' => 'danger'
                                ];
                                $status_labels = [
                                    'draft' => 'Draft',
                                    'submitted' => '🕐 Menunggu Proses Gudang',
                                    'approved' => 'Disiapkan Gudang',
                                    'completed' => '✓ Selesai',
                                    'rejected' => 'Rejected',
                                    'cancelled' => 'Cancelled'
                                ];
                                $badge_color = $status_colors[$po['status']] ?? 'secondary';
                                $badge_label = $status_labels[$po['status']] ?? ucfirst($po['status']);
                                ?>
                                <span class="badge badge-<?php echo $badge_color; ?>" style="font-size: 0.875rem;">
                                    <?php echo $badge_label; ?>
                                </span>
                            </td>
                            <td><?php echo $po['items_count']; ?> items</td>
                            <td class="text-right" style="font-weight: 700; color: var(--text-primary);">
                                Rp <?php echo number_format($po['total_amount'] ?? 0, 0, ',', '.'); ?>
                            </td>
                            <td style="font-size: 0.813rem;"><?php echo $po['created_by_name']; ?></td>
                            <td>
                                <div class="po-action-group">
                                    <a href="view-po.php?id=<?php echo $po['id']; ?>" class="po-action-btn view" title="View">
                                        <i data-feather="eye"></i>
                                    </a>

                                    <?php if ($po['status'] === 'draft'): ?>
                                        <form method="POST" action="submit-po.php" style="display: inline;">
                                            <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                                            <button type="submit" class="po-action-btn submit" title="Submit PO" onclick="return confirm('Submit PO ini?')">
                                                <i data-feather="send"></i>
                                            </button>
                                        </form>
                                    <?php elseif ($po['status'] === 'submitted'): ?>
                                        <a href="gudang-transfer.php?po_id=<?php echo (int)$po['id']; ?>" class="po-action-btn po-action-wide submit" title="Siapkan Transfer Gudang">
                                            <i data-feather="send"></i> Siapkan Transfer
                                        </a>
                                    <?php elseif ($po['status'] === 'completed'): ?>
                                        <span class="badge badge-success">Transfer Selesai</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; width: 90%; overflow: hidden;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 1.75rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.5rem; font-weight: 700;">
                    <i data-feather="check-circle" style="width: 24px; height: 24px; vertical-align: middle; margin-right: 0.5rem;"></i>
                    Approve & Bayar PO
                </h3>
                <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; opacity: 0.95;" id="modalSubtitle"></p>
            </div>
            <button type="button" onclick="closeApproveModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 1.75rem; width: 36px; height: 36px; border-radius: 0.5rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="approve-purchase.php" enctype="multipart/form-data" id="approveForm">
            <div style="padding: 2rem;">
                <input type="hidden" name="approve" value="1">
                <input type="hidden" name="po_id" id="modalPoId">

                <!-- Info PO -->
                <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-left: 4px solid #10b981; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                        <div style="background: white; width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,0.15);">
                            <i data-feather="dollar-sign" style="width: 24px; height: 24px; color: #10b981;"></i>
                        </div>
                        <div>
                            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.25rem;">Total Pembayaran</div>
                            <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;" id="modalAmount"></div>
                        </div>
                    </div>
                </div>

                <!-- Warning -->
                <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 4px solid #f59e0b; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem;">
                    <div style="display: flex; gap: 1rem;">
                        <div style="flex-shrink: 0;">
                            <div style="width: 32px; height: 32px; background: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 1.25rem;">!</div>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: #92400e; margin-bottom: 0.75rem; font-size: 1rem;">⚠️ Upload Nota Wajib</div>
                            <p style="margin: 0; color: #92400e; line-height: 1.6; font-size: 0.875rem;">
                                Silakan pilih file nota/invoice dari supplier. Setelah upload, PO akan otomatis di-approve dan pembayaran dicatat ke Kas Besar.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- File Upload -->
                <div style="background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; text-align: center; cursor: pointer;" onclick="document.getElementById('notaFile').click();">
                    <div style="margin-bottom: 1rem;">
                        <i data-feather="upload-cloud" style="width: 48px; height: 48px; color: #6b7280;"></i>
                    </div>
                    <div style="font-weight: 600; color: #374151; margin-bottom: 0.5rem;">
                        Klik untuk Upload Nota/Invoice
                    </div>
                    <div style="font-size: 0.875rem; color: #6b7280;">
                        JPG, PNG, PDF (Max 5MB)
                    </div>
                    <input type="file" name="nota_image" id="notaFile" accept="image/jpeg,image/png,image/jpg,application/pdf" required style="display: none;" onchange="updateFileName(this)">
                    <div id="fileName" style="margin-top: 1rem; font-size: 0.875rem; color: #10b981; font-weight: 600;"></div>
                </div>

                <!-- Buttons -->
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" onclick="closeApproveModal()" class="btn btn-secondary" style="padding: 0.875rem 1.75rem; font-size: 1rem; font-weight: 600;">
                        <i data-feather="x" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                        Batal
                    </button>
                    <button type="submit" class="btn btn-success" style="padding: 0.875rem 2rem; font-size: 1rem; font-weight: 600; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16,185,129,0.3);">
                        <i data-feather="check" style="width: 18px; height: 18px; vertical-align: middle; margin-right: 0.5rem;"></i>
                        Ya, Approve & Bayar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; backdrop-filter: blur(8px);">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
        <div style="margin-bottom: 2rem;">
            <div class="spinner-border" style="width: 80px; height: 80px; border: 6px solid rgba(16,185,129,0.2); border-top-color: #10b981; animation: spin 1s linear infinite;"></div>
        </div>

        <div style="background: white; padding: 2.5rem 3rem; border-radius: 1.5rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); min-width: 400px;">
            <div style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin-bottom: 1rem;">⏳ Processing...</div>
            <div style="font-size: 0.875rem; color: #6b7280;">Mengupload nota dan approve PO</div>
            <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 2px solid #f3f4f6; text-align: center;">
                <div style="font-size: 0.875rem; color: #10b981; font-weight: 600;">
                    Mohon tunggu, jangan tutup halaman ini
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<script>
    feather.replace();

    function openApproveDialog(poId, poNumber, amount) {
        document.getElementById('modalPoId').value = poId;
        document.getElementById('modalSubtitle').textContent = poNumber;
        document.getElementById('modalAmount').textContent = 'Rp ' + amount.toLocaleString('id-ID');
        document.getElementById('fileName').textContent = '';
        document.getElementById('notaFile').value = '';
        document.getElementById('approveModal').style.display = 'block';

        // Auto-open file picker
        setTimeout(() => {
            document.getElementById('notaFile').click();
        }, 300);

        feather.replace();
    }

    function closeApproveModal() {
        document.getElementById('approveModal').style.display = 'none';
    }

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            const fileName = input.files[0].name;
            const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
            document.getElementById('fileName').textContent = '✓ ' + fileName + ' (' + fileSize + ' MB)';
        }
    }

    document.getElementById('approveForm').addEventListener('submit', function(e) {
        const fileInput = document.getElementById('notaFile');
        if (!fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('❌ Harap upload nota terlebih dahulu!');
            return false;
        }

        // Show loading
        document.getElementById('loadingOverlay').style.display = 'block';
    });

    function rejectPO(poId, poNumber) {
        console.log('rejectPO called with:', poId, poNumber);

        if (confirm('⚠️ PERHATIAN!\n\nApakah Anda yakin ingin REJECT dan HAPUS PO ' + poNumber + '?\n\nData PO ini akan dihapus permanen!')) {
            console.log('User confirmed, redirecting...');

            // Redirect dengan GET parameter untuk lebih reliable
            window.location.href = 'reject-po.php?po_id=' + poId + '&confirm=1';
        } else {
            console.log('User cancelled');
        }
    }
</script>

<script>
    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>