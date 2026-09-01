<?php

/**
 * Customer Order Report — Printable
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();
$customerId = (int)($_GET['customer_id'] ?? 0);

if (!$customerId) {
  echo 'Select a customer first.';
  exit;
}

$customer = $pdo->prepare('SELECT * FROM pwf_customers WHERE id=?');
$customer->execute([$customerId]);
$customer = $customer->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
  echo 'Customer not found.';
  exit;
}

$orders = $pdo->prepare("
    SELECT o.*, t.craftsman_name, t.specialty AS craftsman_specialty
    FROM pwf_orders o
    LEFT JOIN pwf_craftsmen t ON t.id = o.assigned_craftsman_id
    WHERE o.customer_id = ?
    ORDER BY o.order_date DESC
");
$orders->execute([$customerId]);
$orders = $orders->fetchAll(PDO::FETCH_ASSOC);

// Summary
$totalOrders  = count($orders);
$totalQty     = array_sum(array_column($orders, 'quantity'));
$totalPrice   = array_sum(array_column($orders, 'total_price'));
$finished     = count(array_filter($orders, fn($r) => in_array($r['status'], ['completed', 'shipped'])));
$inProgress   = count(array_filter($orders, fn($r) => $r['status'] === 'on_progress'));

// Company info
$companyName = 'Prapen Wood Furniture';
$logoUrl = '';
try {
  $settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
  if (!empty($settingRows['pwf_company_name'])) $companyName = $settingRows['pwf_company_name'];
  if (!empty($settingRows['pwf_login_logo'])) $logoUrl = $settingRows['pwf_login_logo'];
} catch (Exception $e) {
}

$printDate = date('d F Y');
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Customer Report – <?= htmlspecialchars($customer['customer_name']) ?></title>
  <style>
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0
    }

    body {
      font-family: 'Arial', sans-serif;
      background: #fff;
      color: #111;
      font-size: 12px;
      line-height: 1.5
    }

    .page {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px
    }

    .rpt-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-bottom: 10px;
      border-bottom: 3px solid #B8860B;
      margin-bottom: 12px
    }

    .logo-fallback {
      font-size: 26px;
      font-weight: 900;
      color: #B8860B
    }

    .company-info {
      text-align: right
    }

    .company-info .name {
      font-size: 16px;
      font-weight: 700
    }

    .company-info .addr {
      font-size: 10px;
      color: #555
    }

    .rpt-title-bar {
      background: #1C1511;
      color: #fff;
      padding: 8px 14px;
      border-radius: 6px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px
    }

    .rpt-title-bar h2 {
      font-size: 15px;
      font-weight: 700;
      margin: 0
    }

    .rpt-title-bar p {
      font-size: 10px;
      opacity: .7;
      margin: 0
    }

    .customer-box {
      border: 1px solid #B8860B;
      border-radius: 6px;
      overflow: hidden;
      margin-bottom: 12px;
      display: grid;
      grid-template-columns: 1fr 1fr
    }

    .customer-box-left,
    .customer-box-right {
      padding: 12px 16px
    }

    .customer-box-left {
      border-right: 1px solid #f5e9c8
    }

    .cust-name {
      font-size: 16px;
      font-weight: 700;
      color: #111;
      margin-bottom: 4px
    }

    .cust-meta {
      font-size: 11px;
      color: #555
    }

    .summary-cards {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
      margin-bottom: 12px
    }

    .sum-card {
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 12px;
      text-align: center
    }

    .sum-val {
      font-size: 22px;
      font-weight: 800;
      color: #B8860B
    }

    .sum-lbl {
      font-size: 10px;
      color: #777;
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: .4px
    }

    .rpt-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 12px;
      font-size: 11px
    }

    .table-wrap {
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      overflow: hidden;
      margin-bottom: 12px;
      box-shadow: 0 8px 18px rgba(17, 24, 39, 0.05);
    }

    .rpt-table th {
      background: #1C1511;
      color: #fff;
      padding: 6px 8px;
      border: 1px solid #2e2622;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .4px;
      font-weight: 700
    }

    .rpt-table td {
      padding: 6px 8px;
      border: 1px solid #eee;
      vertical-align: top
    }

    .rpt-table tr:nth-child(even) td {
      background: #fafafa
    }

    .note-cell {
      max-width: 190px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      color: #4b5563;
      font-size: 10px;
    }

    .bar-wrap {
      background: #eee;
      border-radius: 20px;
      height: 5px;
      overflow: hidden;
      margin-top: 3px;
      width: 60px
    }

    .bar-fill {
      height: 100%;
      background: #B8860B;
      border-radius: 20px
    }

    .blueprint-thumb {
      max-width: 80px;
      max-height: 60px;
      object-fit: cover;
      border-radius: 4px;
      border: 1px solid #ddd
    }

    .rpt-footer {
      margin-top: 16px;
      padding-top: 10px;
      border-top: 1px solid #ddd;
      text-align: center;
      font-size: 10px;
      color: #999
    }

    @media print {
      @page {
        size: A4 landscape;
        margin: 8mm;
      }

      .no-print {
        display: none !important
      }

      body {
        background: #fff;
        margin: 0;
        padding: 0
      }

      .page {
        padding: 12px;
        max-width: 100%;
        margin: 0
      }

      .rpt-table {
        font-size: 10.5px
      }

      .rpt-table th,
      .rpt-table td {
        padding: 6px 8px
      }
    }
  </style>
</head>

<body>
  <div class="page">

    <div class="no-print" style="margin-bottom:14px;display:flex;gap:8px">
      <button onclick="window.print()" style="padding:8px 18px;background:#B8860B;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">🖨 Print / Save PDF</button>
      <button onclick="window.close()" style="padding:8px 14px;background:#f5f5f5;border:1px solid #ddd;border-radius:8px;cursor:pointer;font-size:13px">✕ Close</button>
    </div>

    <!-- HEADER -->
    <div class="rpt-header">
      <div>
        <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" style="height:52px;object-fit:contain" alt="Logo">
        <?php else: ?><div class="logo-fallback">PWF</div><?php endif; ?>
      </div>
      <div class="company-info">
        <div class="name"><?= htmlspecialchars($companyName) ?></div>
        <div class="addr">Jl. Ngabul - Batealit No.KM. 5, Jepara, Jawa Tengah</div>
      </div>
    </div>

    <!-- TITLE -->
    <div class="rpt-title-bar">
      <h2>Customer Order Report</h2>
      <p>Printed: <?= $printDate ?></p>
    </div>

    <!-- CUSTOMER BOX -->
    <div class="customer-box">
      <div class="customer-box-left">
        <div class="cust-name"><?= htmlspecialchars($customer['customer_name']) ?></div>
        <?php if ($customer['phone']): ?><div class="cust-meta">📞 <?= htmlspecialchars($customer['phone']) ?></div><?php endif; ?>
        <?php if ($customer['address']): ?><div class="cust-meta">📍 <?= htmlspecialchars($customer['address']) ?></div><?php endif; ?>
      </div>
      <div class="customer-box-right">
        <div class="cust-meta" style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#B8860B;font-weight:700;margin-bottom:4px">Customer Code</div>
        <div style="font-size:16px;font-weight:700"><?= htmlspecialchars($customer['customer_code']) ?></div>
        <?php if ($customer['notes']): ?><div class="cust-meta" style="margin-top:6px"><?= htmlspecialchars($customer['notes']) ?></div><?php endif; ?>
      </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="summary-cards">
      <div class="sum-card">
        <div class="sum-val"><?= $totalOrders ?></div>
        <div class="sum-lbl">Total Orders</div>
      </div>
      <div class="sum-card">
        <div class="sum-val"><?= number_format($totalQty, 0) ?></div>
        <div class="sum-lbl">Total Qty</div>
      </div>
      <div class="sum-card">
        <div class="sum-val" style="font-size:18px"><?= $totalPrice > 0 ? 'Rp ' . number_format($totalPrice, 0, ',', '.') : '—' ?></div>
        <div class="sum-lbl">Total Value</div>
      </div>
      <div class="sum-card">
        <div class="sum-val" style="color:#C2410C"><?= $inProgress ?></div>
        <div class="sum-lbl">In Progress</div>
      </div>
    </div>

    <!-- ORDERS TABLE -->
    <div class="table-wrap">
    <table class="rpt-table">
      <thead>
        <tr>
          <th>Order Code</th>
          <th>Product</th>
          <th>Dimensions</th>
          <th>Finish Color</th>
          <th>Description / Material</th>
          <th>Order Note</th>
          <th>Qty</th>
          <th>Prices</th>
          <th>Total Prices</th>
          <th>Order Date</th>
          <th>Deadline</th>
          <th>Craftsman</th>
          <th>Blueprint</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $ord): ?>
          <tr>
            <td><strong><?= htmlspecialchars($ord['order_code']) ?></strong></td>
            <td>
              <strong><?= htmlspecialchars($ord['product_name']) ?></strong>
            </td>
            <td><?= htmlspecialchars($ord['dimensions'] ?: '—') ?></td>
            <td>
              <?php
              $finishColor = trim((string)($ord['finish'] ?? ''));
              if ($finishColor === '') {
                $finishColor = trim((string)($ord['wood_color'] ?? ''));
              }
              ?>
              <?= $finishColor !== '' ? htmlspecialchars($finishColor) : '—' ?>
            </td>
            <td>
              <?php if ($ord['specification']): ?>
                <strong><?= htmlspecialchars($ord['specification']) ?></strong>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="note-cell" title="<?= htmlspecialchars((string)($ord['notes'] ?? '')) ?>">
              <?php if (!empty($ord['notes'])): ?>
                <?= htmlspecialchars((string)$ord['notes']) ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td style="text-align:right"><strong><?= htmlspecialchars($ord['quantity']) ?></strong></td>
            <td style="text-align:right;font-size:10px">
              <?php if ((float)($ord['unit_price'] ?? 0) > 0): ?>
                <?= number_format((float)$ord['unit_price'], 0, ',', '.') ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;background:#fffdf5;color:#B8860B">
              <?php if ((float)($ord['total_price'] ?? 0) > 0): ?>
                <?= number_format((float)$ord['total_price'], 0, ',', '.') ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($ord['order_date']) ?></td>
            <td><?= $ord['due_date'] ? htmlspecialchars($ord['due_date']) : '—' ?></td>
            <td>
              <?= htmlspecialchars($ord['craftsman_name'] ?? '—') ?>
              <?php if ($ord['craftsman_specialty']): ?><div style="font-size:10px;color:#999"><?= htmlspecialchars($ord['craftsman_specialty']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($ord['image_path']): ?>
                <img class="blueprint-thumb" src="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/' . ltrim($ord['image_path'], '/')) ?>" alt="Blueprint">
              <?php else: ?>
                <span style="color:#ccc">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
          <tr>
            <td colspan="13" style="text-align:center;padding:20px;color:#999">No orders found for this customer.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <div class="rpt-footer">
      <?= htmlspecialchars($companyName) ?> · PWF Office System · Printed: <?= $printDate ?>
    </div>

  </div>
</body>

</html>