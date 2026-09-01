<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();

// Get customer list
$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll(PDO::FETCH_ASSOC);

// Get selected customer ID from query param or POST
$selectedCustomerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);

$orders = [];
$customerName = '';
$totalQty = 0;
$totalPrice = 0;

if ($selectedCustomerId > 0) {
    // Get customer name
    $stmt = $pdo->prepare('SELECT customer_name FROM pwf_customers WHERE id = ?');
    $stmt->execute([$selectedCustomerId]);
    $customerData = $stmt->fetch(PDO::FETCH_ASSOC);
    $customerName = $customerData['customer_name'] ?? '';

    // Get all orders for this customer
    $stmt = $pdo->prepare('
        SELECT id, order_code, order_date, product_name, description, dimensions,
               quantity, unit_price, total_price, wood_color, finish, notes, image_path, status
        FROM pwf_orders
        WHERE customer_id = ?
        ORDER BY order_date DESC
    ');
    $stmt->execute([$selectedCustomerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    foreach ($orders as $o) {
        $totalQty += (float)$o['quantity'];
        $totalPrice += (float)$o['total_price'];
    }
}

pwfOfficeHeader('Customer Orders Summary', 'customers');
?>

<style>
    .summary-header {
        background: linear-gradient(135deg, #D4A017 0%, #B8860B 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .summary-header h3 {
        margin: 0 0 8px;
        font-size: 22px;
        font-weight: 700;
    }

    .summary-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }

    .stat-box {
        background: rgba(255, 255, 255, 0.2);
        padding: 12px 14px;
        border-radius: 8px;
        border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .stat-label {
        font-size: 11px;
        opacity: 0.9;
        text-transform: uppercase;
        font-weight: 600;
    }

    .stat-value {
        font-size: 18px;
        font-weight: 700;
        margin-top: 4px;
    }

    .order-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        background: white;
    }

    .order-table th {
        background: #1C1511;
        color: white;
        padding: 10px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .order-table td {
        padding: 11px 12px;
        border-bottom: 1px solid #E7E5E4;
        vertical-align: top;
    }

    .order-table tr:nth-child(even) {
        background: #FAFAF9;
    }

    .order-table tr:hover {
        background: #F5F3F0;
    }

    .order-image {
        width: 50px;
        height: 50px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #E7E5E4;
    }

    .order-image-empty {
        width: 50px;
        height: 50px;
        background: #F5F3F0;
        border-radius: 6px;
        border: 1px solid #E7E5E4;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #C2C1BF;
        font-size: 20px;
    }

    .status-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        text-transform: capitalize;
    }

    .status-draft {
        background: #EFF6FF;
        color: #1D4ED8;
    }

    .status-on_progress {
        background: #FFF7ED;
        color: #C2410C;
    }

    .status-completed {
        background: #F0FDF4;
        color: #15803D;
    }

    .total-row {
        background: #1C1511;
        color: white;
        font-weight: 700;
    }

    .total-row td {
        padding: 12px;
        border-bottom: none;
    }

    .dimensions-cell {
        font-size: 11px;
        color: var(--muted);
    }

    @media print {
        .no-print {
            display: none;
        }

        body {
            background: white;
        }

        .summary-header {
            page-break-after: avoid;
        }

        .order-table {
            page-break-inside: avoid;
        }
    }
</style>

<div class="pwf-card no-print" style="margin-bottom: 16px">
    <div style="padding: 18px">
        <form method="post" id="selectForm">
            <div style="display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: flex-end">
                <div>
                    <label style="display: block; font-size: 11.5px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase">
                        Select Customer
                    </label>
                    <select name="customer_id" onchange="this.form.submit()" style="width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--input-bg); color: var(--text); font-size: 13px; font-family: inherit">
                        <option value="">— Choose a customer —</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selectedCustomerId === $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selectedCustomerId > 0): ?>
                    <button type="button" class="btn" onclick="window.print()" style="display: flex; align-items: center; gap: 6px">
                        <i class="bi bi-printer"></i> Print
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedCustomerId > 0 && !empty($orders)): ?>

    <!-- Summary Header -->
    <div class="summary-header">
        <h3><?= htmlspecialchars($customerName) ?></h3>
        <div style="font-size: 12px; opacity: 0.95">Order Summary & Pricing</div>
        <div class="summary-stats">
            <div class="stat-box">
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?= count($orders) ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Total Quantity</div>
                <div class="stat-value"><?= number_format($totalQty, 0) ?> pcs</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Total Value</div>
                <div class="stat-value" style="font-size: 14px">Rp <?= number_format($totalPrice, 0, ',', '.') ?></div>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="pwf-card">
        <div style="overflow-x: auto">
            <table class="order-table">
                <thead>
                    <tr>
                        <th style="width: 30px; text-align: center">No</th>
                        <th style="width: 65px">Date</th>
                        <th style="min-width: 140px">Order Name</th>
                        <th style="width: 60px; text-align: center">Image</th>
                        <th style="width: 120px">Description</th>
                        <th style="width: 40px; text-align: center">W</th>
                        <th style="width: 40px; text-align: center">D</th>
                        <th style="width: 40px; text-align: center">H</th>
                        <th style="width: 50px; text-align: right">Qty</th>
                        <th style="width: 90px; text-align: right">Prices</th>
                        <th style="width: 110px; text-align: right">Total Prices</th>
                        <th style="width: 70px">Wood</th>
                        <th style="width: 80px">Finish</th>
                        <th style="width: 100px">Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $idx => $o):
                        // Parse dimensions W×D×H
                        $dims = explode('×', $o['dimensions'] ?? '');
                        $w = isset($dims[0]) ? trim($dims[0]) : '—';
                        $d = isset($dims[1]) ? trim($dims[1]) : '—';
                        $h = isset($dims[2]) ? trim($dims[2]) : '—';
                    ?>
                        <tr>
                            <td style="text-align: center; font-weight: 600; color: var(--muted)"><?= $idx + 1 ?></td>
                            <td style="font-size: 11px; white-space: nowrap"><?= date('d-M-y', strtotime($o['order_date'])) ?></td>
                            <td style="font-weight: 600; color: var(--text)">
                                <?= htmlspecialchars($o['product_name']) ?><br>
                                <span style="font-size: 9px; color: var(--muted)"><?= htmlspecialchars($o['order_code']) ?></span>
                            </td>
                            <td style="text-align: center">
                                <?php if ($o['image_path']): ?>
                                    <img src="<?= BASE_URL ?>/../<?= htmlspecialchars($o['image_path']) ?>" alt="Product" class="order-image">
                                <?php else: ?>
                                    <div class="order-image-empty">📷</div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11px; color: var(--muted); word-break: break-word">
                                <?= htmlspecialchars(mb_strimwidth($o['description'] ?? '', 0, 80, '...')) ?>
                            </td>
                            <td style="text-align: center; font-size: 10.5px; color: var(--muted)"><?= htmlspecialchars($w) ?></td>
                            <td style="text-align: center; font-size: 10.5px; color: var(--muted)"><?= htmlspecialchars($d) ?></td>
                            <td style="text-align: center; font-size: 10.5px; color: var(--muted)"><?= htmlspecialchars($h) ?></td>
                            <td style="text-align: right; font-weight: 600; color: var(--text)"><?= number_format((float)$o['quantity'], 0) ?></td>
                            <td style="text-align: right; font-size: 11px; color: var(--text)">
                                <?php if ((float)$o['unit_price'] > 0): ?>
                                    <?= number_format((float)$o['unit_price'], 0, ',', '.') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: var(--gold); background: rgba(212, 160, 23, 0.05)">
                                <?php if ((float)$o['total_price'] > 0): ?>
                                    <?= number_format((float)$o['total_price'], 0, ',', '.') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 10.5px; color: var(--muted)"><?= htmlspecialchars($o['wood_color'] ?? '—') ?></td>
                            <td style="font-size: 10.5px; color: var(--muted)"><?= htmlspecialchars($o['finish'] ?? '—') ?></td>
                            <td style="font-size: 10px; color: var(--muted); word-break: break-word">
                                <?= htmlspecialchars(mb_strimwidth($o['notes'] ?? '', 0, 60, '...')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Total Row -->
                    <tr class="total-row">
                        <td colspan="8" style="text-align: right; padding: 13px 12px; font-weight: 700">TOTAL</td>
                        <td style="text-align: right; padding: 13px 12px; font-weight: 700"><?= number_format($totalQty, 0) ?></td>
                        <td></td>
                        <td style="text-align: right; padding: 13px 12px; font-weight: 700"><?= number_format($totalPrice, 0, ',', '.') ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($selectedCustomerId > 0 && empty($orders)): ?>

    <div class="pwf-card" style="text-align: center; padding: 40px">
        <i class="bi bi-inbox" style="font-size: 48px; color: var(--muted); display: block; margin-bottom: 12px; opacity: 0.5"></i>
        <div style="font-size: 14px; color: var(--muted)">
            No orders found for <?= htmlspecialchars($customerName) ?>
        </div>
    </div>

<?php else: ?>

    <div class="pwf-card" style="text-align: center; padding: 60px 20px">
        <i class="bi bi-search" style="font-size: 52px; color: var(--muted); display: block; margin-bottom: 16px; opacity: 0.4"></i>
        <div style="font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 8px">Select a Customer</div>
        <div style="font-size: 13px; color: var(--muted)">
            Choose a customer from the dropdown above to view their orders and pricing summary
        </div>
    </div>

<?php endif; ?>

<?php pwfOfficeFooter();
