<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();

// Get all orders with their container assignments
$stmt = $pdo->prepare("
    SELECT 
        o.id,
        o.order_code,
        o.order_date,
        o.product_name,
        o.description,
        o.dimensions,
        o.quantity,
        o.wood_color,
        o.finish,
        o.image_path,
        o.status,
        o.notes,
        c.customer_name,
        t.craftsman_name,
        COALESCE(CONCAT(cont.container_code, ' (', cont.container_no, ')'), '—') AS container_info,
        cont.container_type,
        ci.qty_shipped,
        cont.destination_country
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id = o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id = o.assigned_craftsman_id
    LEFT JOIN pwf_container_items ci ON ci.order_id = o.id
    LEFT JOIN pwf_containers cont ON cont.id = ci.container_id
    WHERE ci.container_id IS NOT NULL
    ORDER BY o.order_date DESC, o.id DESC
");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

pwfOfficeHeader('Rekap Order', 'rekap-order');
?>

<style>
    .recap-page {
        background: var(--card);
        padding: 20px;
        max-width: 1400px;
        margin: 0 auto;
    }

    .recap-header {
        margin-bottom: 24px;
        border-bottom: 3px solid var(--gold);
        padding-bottom: 16px;
    }

    .recap-header h2 {
        margin: 0 0 4px;
        font-size: 20px;
        font-weight: 700;
        color: var(--text);
    }

    .recap-header p {
        margin: 0;
        font-size: 12px;
        color: var(--muted);
    }

    .recap-controls {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .recap-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        background: var(--card);
        margin-bottom: 20px;
    }

    .recap-table thead {
        background: #2C2C30;
        color: #ECECEC;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    [data-theme="light"] .recap-table thead {
        background: #1C1511;
        color: white;
    }

    .recap-table th {
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border-bottom: 1px solid var(--border);
    }

    .recap-table td {
        padding: 10px 8px;
        border-bottom: 1px solid var(--border);
        vertical-align: top;
    }

    .recap-table tbody tr:nth-child(even) {
        background: var(--nav-hover);
    }

    .recap-table tbody tr:hover {
        background: var(--nav-hover);
    }

    .recap-img {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #E7E5E4;
    }

    .recap-img-empty {
        width: 50px;
        height: 50px;
        background: #F5F3F0;
        border-radius: 6px;
        border: 1px solid #E7E5E4;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #C2C1BF;
        font-size: 18px;
    }

    .recap-status {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 9px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .recap-status.completed {
        background: #F0FDF4;
        color: #166534;
    }

    .recap-status.shipped {
        background: #F0FDF4;
        color: #15803D;
    }

    .recap-status.ready_ship {
        background: #F0FDF4;
        color: #15803D;
    }

    .recap-container {
        background: var(--gold-bg);
        border: 1px solid var(--gold-border);
        color: #92600A;
        padding: 4px 8px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 10px;
    }

    .recap-container.empty {
        background: #F5F3F0;
        color: var(--muted);
        border: 1px solid var(--border);
    }

    .dimensions-text {
        font-size: 10px;
        color: var(--muted);
        white-space: nowrap;
    }

    .summary-box {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }

    .summary-card {
        background: white;
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px 14px;
        text-align: center;
    }

    .summary-label {
        font-size: 10.5px;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .summary-value {
        font-size: 18px;
        font-weight: 700;
        color: var(--gold);
    }

    @media print {
        .no-print {
            display: none !important;
        }

        body {
            background: white;
        }

        .recap-page {
            padding: 0;
            max-width: 100%;
        }

        .recap-table {
            page-break-inside: avoid;
        }

        .recap-table th {
            background: #1C1511;
            color: white;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
    }

    @page {
        size: A4 landscape;
        margin: 8mm;
    }
</style>

<div class="recap-page">
    <!-- Header -->
    <div class="recap-header">
        <h2>📦 Rekap Order (Order Summary)</h2>
        <p>Daftar order yang sudah dipilihkan container untuk pengiriman</p>
    </div>

    <!-- Controls -->
    <div class="recap-controls no-print">
        <button onclick="window.print()" class="btn" style="display: flex; align-items: center; gap: 6px">
            <i class="bi bi-printer"></i> Print / Save PDF
        </button>
        <a href="orders.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Kembali ke Orders
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="summary-box">
        <div class="summary-card">
            <div class="summary-label">Total Orders</div>
            <div class="summary-value"><?= count($orders) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Qty</div>
            <div class="summary-value"><?= number_format(array_sum(array_column($orders, 'quantity')), 0) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Containers</div>
            <div class="summary-value"><?= count(array_unique(array_filter(array_column($orders, 'container_info')))) ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Destination Countries</div>
            <div class="summary-value"><?= count(array_unique(array_filter(array_column($orders, 'destination_country')))) ?></div>
        </div>
    </div>

    <!-- Table -->
    <div class="pwf-card" style="overflow-x: auto">
        <table class="recap-table">
            <thead>
                <tr>
                    <th style="width: 30px; text-align: center">No</th>
                    <th style="width: 70px">Order Date</th>
                    <th style="width: 70px">Order Code</th>
                    <th style="min-width: 120px">Order Name</th>
                    <th style="width: 60px; text-align: center">Image</th>
                    <th style="width: 100px">Description</th>
                    <th style="width: 35px; text-align: center">W</th>
                    <th style="width: 35px; text-align: center">D</th>
                    <th style="width: 35px; text-align: center">H</th>
                    <th style="width: 45px; text-align: right">Qty</th>
                    <th style="width: 70px">Fabric/Color</th>
                    <th style="width: 80px">Production</th>
                    <th style="width: 140px">Container</th>
                    <th style="width: 60px">Status</th>
                    <th style="min-width: 100px">Customer</th>
                    <th style="min-width: 120px">Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="16" style="text-align: center; color: var(--muted); padding: 40px 20px">
                            <i class="bi bi-inbox" style="font-size: 32px; opacity: 0.3; display: block; margin-bottom: 8px"></i>
                            Belum ada order yang dipilihkan container
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $idx => $o):
                        // Parse dimensions W×D×H
                        $dims = explode('×', $o['dimensions'] ?? '');
                        $w = isset($dims[0]) ? trim($dims[0]) : '—';
                        $d = isset($dims[1]) ? trim($dims[1]) : '—';
                        $h = isset($dims[2]) ? trim($dims[2]) : '—';
                    ?>
                        <tr>
                            <td style="text-align: center; font-weight: 600; color: var(--muted)"><?= $idx + 1 ?></td>
                            <td style="font-size: 10.5px; white-space: nowrap"><?= date('d-M-y', strtotime($o['order_date'])) ?></td>
                            <td style="font-weight: 600; color: var(--gold); font-family: monospace; font-size: 10.5px"><?= htmlspecialchars($o['order_code']) ?></td>
                            <td style="font-weight: 600"><?= htmlspecialchars($o['product_name']) ?></td>
                            <td style="text-align: center">
                                <?php if ($o['image_path']): ?>
                                    <img src="<?= BASE_URL ?>/../<?= htmlspecialchars($o['image_path']) ?>" alt="Product" class="recap-img">
                                <?php else: ?>
                                    <div class="recap-img-empty">📷</div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 10px; color: var(--muted); word-break: break-word">
                                <?= htmlspecialchars(mb_strimwidth($o['description'] ?? '', 0, 60, '...')) ?>
                            </td>
                            <td style="text-align: center" class="dimensions-text"><?= htmlspecialchars($w) ?></td>
                            <td style="text-align: center" class="dimensions-text"><?= htmlspecialchars($d) ?></td>
                            <td style="text-align: center" class="dimensions-text"><?= htmlspecialchars($h) ?></td>
                            <td style="text-align: right; font-weight: 600"><?= number_format((float)$o['quantity'], 0) ?></td>
                            <td style="font-size: 10px">
                                <div><?= htmlspecialchars($o['wood_color'] ?? '—') ?></div>
                                <div style="color: var(--muted); font-size: 9px"><?= htmlspecialchars($o['finish'] ?? '—') ?></div>
                            </td>
                            <td style="font-size: 10px">
                                <?php if ($o['craftsman_name']): ?>
                                    <div style="font-weight: 600; color: var(--text)"><?= htmlspecialchars($o['craftsman_name']) ?></div>
                                <?php endif; ?>
                                <div style="color: var(--muted)"><?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?></div>
                            </td>
                            <td>
                                <?php if ($o['container_info'] !== '—'): ?>
                                    <div class="recap-container">
                                        <i class="bi bi-box-seam" style="margin-right: 4px"></i><?= htmlspecialchars($o['container_info']) ?>
                                    </div>
                                    <?php if ($o['destination_country']): ?>
                                        <div style="font-size: 9px; margin-top: 4px; color: var(--muted)">
                                            🌍 <?= htmlspecialchars($o['destination_country']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="recap-container empty">
                                        Belum di-assign
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center">
                                <span class="recap-status <?= htmlspecialchars($o['status']) ?>">
                                    <?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?>
                                </span>
                            </td>
                            <td style="font-size: 10.5px"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                            <td style="font-size: 10px; color: var(--muted); word-break: break-word">
                                <?= htmlspecialchars(mb_strimwidth($o['notes'] ?? '', 0, 80, '...')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer Info -->
    <div style="font-size: 10px; color: var(--muted); text-align: center; margin-top: 20px; border-top: 1px solid var(--border); padding-top: 12px">
        Generated: <?= date('d F Y, H:i') ?>
    </div>
</div>

<?php pwfOfficeFooter(); ?>