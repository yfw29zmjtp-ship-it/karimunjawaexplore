<?php
/**
 * SPK (Surat Perintah Kerja) / Job Order — Printable
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { echo 'Invalid order ID.'; exit; }

$order = $pdo->prepare("
    SELECT o.*, c.customer_name, c.phone AS customer_phone, c.address AS customer_address,
           t.craftsman_name, t.phone AS craftsman_phone, t.specialty AS craftsman_specialty
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id = o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id = o.assigned_craftsman_id
    WHERE o.id = ?
");
$order->execute([$id]);
$o = $order->fetch(PDO::FETCH_ASSOC);

if (!$o) { echo 'Order not found.'; exit; }

// Progress logs
$logs = $pdo->prepare("
    SELECT p.progress_date, p.achievement_percent, p.work_note, t.craftsman_name
    FROM pwf_order_progress p
    LEFT JOIN pwf_craftsmen t ON t.id = p.craftsman_id
    WHERE p.order_id = ?
    ORDER BY p.progress_date DESC
    LIMIT 10
");
$logs->execute([$id]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

// Company info from settings
$companyName = 'Prapen Wood Furniture';
$companyAddress = 'Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan, Kec. Batealit, Kab. Jepara, Jawa Tengah 59400';
$companyPhone = '';
$logoUrl = '';
try {
    $settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_address','pwf_company_phone','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($settingRows['pwf_company_name'])) $companyName = $settingRows['pwf_company_name'];
    if (!empty($settingRows['pwf_company_address'])) $companyAddress = $settingRows['pwf_company_address'];
    if (!empty($settingRows['pwf_company_phone'])) $companyPhone = $settingRows['pwf_company_phone'];
    if (!empty($settingRows['pwf_login_logo'])) $logoUrl = $settingRows['pwf_login_logo'];
} catch (Exception $e) {}

$statusLabels = [
    'draft' => 'Draft', 'on_progress' => 'On Progress', 'qc' => 'Quality Check',
    'ready_ship' => 'Ready to Ship', 'shipped' => 'Shipped',
    'completed' => 'Completed', 'cancelled' => 'Cancelled',
];
$spkNo = 'SPK/' . $o['order_code'];
$printDate = date('d F Y');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SPK – <?= htmlspecialchars($o['order_code']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Arial',sans-serif;background:#fff;color:#111;font-size:13px;line-height:1.5}
.page{max-width:780px;margin:0 auto;padding:30px}

/* HEADER */
.spk-header{display:flex;align-items:center;justify-content:space-between;padding-bottom:14px;border-bottom:3px solid #B8860B;margin-bottom:18px}
.company-logo{height:60px;object-fit:contain}
.logo-fallback{font-size:28px;font-weight:900;color:#B8860B;letter-spacing:-1px}
.company-info{text-align:right}
.company-info .name{font-size:18px;font-weight:700;color:#111}
.company-info .addr{font-size:11px;color:#555;max-width:300px}
.spk-title-bar{background:#B8860B;color:#fff;text-align:center;padding:10px;border-radius:6px;margin-bottom:18px}
.spk-title-bar h2{font-size:17px;font-weight:700;letter-spacing:1px;margin:0}
.spk-title-bar p{font-size:11px;margin:2px 0 0;opacity:.85}

/* INFO GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.info-box{border:1px solid #ddd;border-radius:6px;overflow:hidden}
.info-box-title{background:#f5f5f5;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:6px 10px;border-bottom:1px solid #ddd}
.info-row{display:flex;padding:5px 10px;border-bottom:1px solid #f0f0f0}
.info-row:last-child{border-bottom:none}
.info-lbl{width:40%;font-weight:600;color:#555;font-size:12px}
.info-val{flex:1;font-size:12px}

/* PRODUCT */
.product-box{border:1px solid #B8860B;border-radius:6px;overflow:hidden;margin-bottom:18px}
.product-box-title{background:#B8860B;color:#fff;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.5px;padding:7px 12px}
.product-row{display:flex;padding:7px 12px;border-bottom:1px solid #f5e9c8}
.product-row:last-child{border-bottom:none}
.product-lbl{width:30%;font-weight:600;color:#555;font-size:12px}
.product-val{flex:1;font-size:12px}
.product-spec{padding:8px 12px;background:#fffdf5;font-size:12px;border-top:1px solid #f5e9c8;white-space:pre-wrap}

/* BLUEPRINT */
.blueprint-section{margin-bottom:18px}
.blueprint-title{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #ddd}
.blueprint-img{max-width:100%;max-height:320px;object-fit:contain;border:1px solid #ddd;border-radius:6px;display:block;margin:0 auto}
.no-blueprint{text-align:center;padding:40px;background:#f9f9f9;border:1px dashed #ccc;border-radius:6px;color:#999;font-size:12px}

/* PROGRESS */
.progress-table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:12px}
.progress-table th{background:#f5f5f5;padding:7px 10px;border:1px solid #ddd;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.progress-table td{padding:7px 10px;border:1px solid #ddd}

/* SIGNATURES */
.sig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:24px}
.sig-box{text-align:center}
.sig-title{font-size:11px;font-weight:700;text-transform:uppercase;color:#555;margin-bottom:48px}
.sig-line{border-top:1px solid #333;margin-top:4px;padding-top:4px;font-size:11px}

/* FOOTER */
.spk-footer{margin-top:20px;padding-top:10px;border-top:1px solid #ddd;text-align:center;font-size:10px;color:#999}

/* PRINT */
@media print {
    .no-print{display:none!important}
    body{background:#fff}
    .page{padding:15px;max-width:100%}
}
</style>
</head>
<body>
<div class="page">

  <!-- Print / Close buttons (screen only) -->
  <div class="no-print" style="margin-bottom:16px;display:flex;gap:8px">
    <button onclick="window.print()" style="padding:9px 20px;background:#B8860B;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
      🖨 Print / Save PDF
    </button>
    <button onclick="window.close()" style="padding:9px 16px;background:#f5f5f5;border:1px solid #ddd;border-radius:8px;cursor:pointer;font-size:13px">
      ✕ Close
    </button>
  </div>

  <!-- HEADER -->
  <div class="spk-header">
    <div>
      <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" class="company-logo" alt="Logo">
      <?php else: ?>
        <div class="logo-fallback">PWF</div>
      <?php endif; ?>
    </div>
    <div class="company-info">
      <div class="name"><?= htmlspecialchars($companyName) ?></div>
      <div class="addr"><?= htmlspecialchars($companyAddress) ?></div>
      <?php if ($companyPhone): ?><div class="addr">📞 <?= htmlspecialchars($companyPhone) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- TITLE BAR -->
  <div class="spk-title-bar">
    <h2>SURAT PERINTAH KERJA (SPK) / JOB ORDER</h2>
    <p>No: <?= htmlspecialchars($spkNo) ?> &nbsp;|&nbsp; Printed: <?= $printDate ?></p>
  </div>

  <!-- INFO GRID -->
  <div class="info-grid">
    <!-- Order Info -->
    <div class="info-box">
      <div class="info-box-title">Order Information</div>
      <div class="info-row"><div class="info-lbl">Order Code</div><div class="info-val"><strong><?= htmlspecialchars($o['order_code']) ?></strong></div></div>
      <div class="info-row"><div class="info-lbl">Order Date</div><div class="info-val"><?= htmlspecialchars($o['order_date']) ?></div></div>
      <div class="info-row"><div class="info-lbl">Deadline</div><div class="info-val"><?= $o['due_date'] ? htmlspecialchars($o['due_date']) : '—' ?></div></div>
      <div class="info-row"><div class="info-lbl">Status</div><div class="info-val"><strong><?= htmlspecialchars($statusLabels[$o['status']] ?? $o['status']) ?></strong></div></div>
      <div class="info-row"><div class="info-lbl">Progress</div><div class="info-val">
        <?= (int)$o['progress_percent'] ?>%
        <div style="background:#eee;border-radius:20px;height:6px;margin-top:3px;overflow:hidden">
          <div style="width:<?= (int)$o['progress_percent'] ?>%;background:#B8860B;height:100%;border-radius:20px"></div>
        </div>
      </div></div>
    </div>
    <!-- Customer & Craftsman -->
    <div class="info-box">
      <div class="info-box-title">Customer</div>
      <div class="info-row"><div class="info-lbl">Name</div><div class="info-val"><strong><?= htmlspecialchars($o['customer_name'] ?? '—') ?></strong></div></div>
      <div class="info-row"><div class="info-lbl">Phone</div><div class="info-val"><?= htmlspecialchars($o['customer_phone'] ?? '—') ?></div></div>
      <div class="info-row"><div class="info-lbl">Address</div><div class="info-val"><?= htmlspecialchars($o['customer_address'] ?? '—') ?></div></div>
      <div class="info-box-title" style="margin-top:4px">Assigned Craftsman</div>
      <div class="info-row"><div class="info-lbl">Name</div><div class="info-val"><strong><?= htmlspecialchars($o['craftsman_name'] ?? 'Not Assigned') ?></strong></div></div>
      <div class="info-row"><div class="info-lbl">Specialty</div><div class="info-val"><?= htmlspecialchars($o['craftsman_specialty'] ?? '—') ?></div></div>
      <div class="info-row"><div class="info-lbl">Phone</div><div class="info-val"><?= htmlspecialchars($o['craftsman_phone'] ?? '—') ?></div></div>
    </div>
  </div>

  <!-- PRODUCT DETAIL -->
  <div class="product-box">
    <div class="product-box-title">Product / Order Detail</div>
    <div class="product-row"><div class="product-lbl">Product Name</div><div class="product-val"><strong><?= htmlspecialchars($o['product_name']) ?></strong></div></div>
    <div class="product-row"><div class="product-lbl">Dimensions</div><div class="product-val"><?= htmlspecialchars($o['dimensions'] ?: '—') ?></div></div>
    <div class="product-row"><div class="product-lbl">Quantity</div><div class="product-val"><strong><?= htmlspecialchars($o['quantity']) ?> <?= htmlspecialchars($o['unit'] ?? 'pcs') ?></strong></div></div>
    <?php if ($o['specification']): ?>
    <div class="product-spec"><strong>Specification / Notes:</strong><br><?= nl2br(htmlspecialchars($o['specification'])) ?></div>
    <?php endif; ?>
    <?php if ($o['notes']): ?>
    <div class="product-spec" style="background:#fff9f0;border-top:1px solid #f5e9c8"><strong>Additional Notes:</strong><br><?= nl2br(htmlspecialchars($o['notes'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- BLUEPRINT / IMAGE -->
  <div class="blueprint-section">
    <div class="blueprint-title">📐 Blueprint / Design Reference</div>
    <?php if ($o['image_path']): ?>
      <img class="blueprint-img"
           src="<?= htmlspecialchars(rtrim(BASE_URL,'/') . '/' . ltrim($o['image_path'],'/')) ?>"
           alt="Blueprint <?= htmlspecialchars($o['order_code']) ?>">
    <?php else: ?>
      <div class="no-blueprint">No blueprint image attached to this order.<br>Upload a blueprint via the Orders page.</div>
    <?php endif; ?>
  </div>

  <!-- PROGRESS LOG -->
  <?php if (!empty($logs)): ?>
  <div style="margin-bottom:18px">
    <div class="blueprint-title">📋 Progress Log (Recent)</div>
    <table class="progress-table">
      <thead><tr><th>Date</th><th>Craftsman</th><th>Achievement</th><th>Work Notes</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= htmlspecialchars($l['progress_date']) ?></td>
            <td><?= htmlspecialchars($l['craftsman_name'] ?? '—') ?></td>
            <td><strong><?= (int)$l['achievement_percent'] ?>%</strong></td>
            <td><?= htmlspecialchars($l['work_note'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- SIGNATURES -->
  <div class="sig-grid">
    <div class="sig-box">
      <div class="sig-title">Issued by</div>
      <div class="sig-line">( _____________________ )<br>Production Manager</div>
    </div>
    <div class="sig-box">
      <div class="sig-title">Received by</div>
      <div class="sig-line">( _____________________ )<br><?= htmlspecialchars($o['craftsman_name'] ?? 'Craftsman') ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-title">Acknowledged by</div>
      <div class="sig-line">( _____________________ )<br>Customer / Owner</div>
    </div>
  </div>

  <div class="spk-footer">
    Generated by PWF Office System &nbsp;·&nbsp; <?= $printDate ?>
    &nbsp;|&nbsp; <?= htmlspecialchars($companyName) ?>
  </div>

</div>
</body>
</html>
