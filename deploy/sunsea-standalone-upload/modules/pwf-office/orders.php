<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';

$pdo = getPwfOfficePdo();

// ── PRINT SPK BY FILTER ─────────────────────────────────────────────────────
if (isset($_GET['print_spk']) && $_GET['print_spk'] === '1') {
    $baseUrl = rtrim(BASE_URL, '/');
    $filterCid = (int)($_GET['customer_id'] ?? 0);
    $filterTid = (int)($_GET['craftsman_id'] ?? 0);

    $companyName = 'Prapen Wood Furniture';
    $companyAddress = 'Jl. Ngabul - Batealit No.KM. 5 Godang, Mindahan, Kec. Batealit, Kab. Jepara, Jawa Tengah 59400';
    $companyPhone = '';
    $companyLogo = '';
    try {
        $settingRows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_company_address','pwf_company_phone','pwf_login_logo')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($settingRows['pwf_company_name'])) {
            $companyName = (string)$settingRows['pwf_company_name'];
        }
        if (!empty($settingRows['pwf_company_address'])) {
            $companyAddress = (string)$settingRows['pwf_company_address'];
        }
        if (!empty($settingRows['pwf_company_phone'])) {
            $companyPhone = (string)$settingRows['pwf_company_phone'];
        }
        if (!empty($settingRows['pwf_login_logo'])) {
            $companyLogo = (string)$settingRows['pwf_login_logo'];
        }
    } catch (Exception $e) {
    }
    $companyLogoUrl = '';
    if ($companyLogo !== '') {
        $companyLogoUrl = preg_match('#^https?://#i', $companyLogo) ? $companyLogo : ($baseUrl . '/' . ltrim($companyLogo, '/'));
    }

    $whereParts = [];
    $whereArgs = [];
    if ($filterCid > 0) {
        $whereParts[] = 'o.customer_id = ?';
        $whereArgs[] = $filterCid;
    }
    if ($filterTid > 0) {
        $whereParts[] = 'o.assigned_craftsman_id = ?';
        $whereArgs[] = $filterTid;
    }
    $whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    $stmt = $pdo->prepare("SELECT
            o.id,
            o.order_code,
            o.order_date,
            o.due_date,
            o.product_name,
            o.specification,
            o.dimensions,
            o.quantity,
            o.unit_price,
            o.total_price,
            o.finish,
            o.wood_color,
            o.image_path,
            o.status,
            o.notes,
            o.progress_percent,
            o.qty_done,
            c.customer_name,
            t.craftsman_name
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id = o.customer_id
        LEFT JOIN pwf_craftsmen t ON t.id = o.assigned_craftsman_id
        $whereClause
        ORDER BY o.id DESC");
    $stmt->execute($whereArgs);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusLabels = [
        'draft' => 'Draft',
        'on_progress' => 'On Progress',
        'qc' => 'Quality Check',
        'ready_ship' => 'Ready to Ship',
        'partial_ship' => 'Partial Ship',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $filterText = 'Semua Customer • Semua Pengrajin';
    if ($filterCid > 0 && $filterTid > 0) {
        $filterText = 'Filter Customer + Pengrajin';
    } elseif ($filterCid > 0) {
        $filterText = 'Filter Customer';
    } elseif ($filterTid > 0) {
        $filterText = 'Filter Pengrajin';
    }

    header('Content-Type: text/html; charset=utf-8');
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Purchase Order</title>
        <style>
            :root {
                --ink: #111827;
                --muted: #6b7280;
                --line: #d1d5db;
                --soft: #f8fafc;
                --gold: #a16207;
                --gold-soft: #fffbeb;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                font-family: "Segoe UI", Tahoma, Arial, sans-serif;
                color: var(--ink);
                background: #eef2f7;
                padding: 10px;
            }

            .sheet {
                max-width: 920px;
                margin: 0 auto;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 14px;
                overflow: hidden;
                box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            }

            .toolbar {
                padding: 10px 12px;
                border-bottom: 1px solid #e5e7eb;
                background: #fff;
                display: flex;
                gap: 8px;
            }

            .btn {
                border: 1px solid #d1d5db;
                background: #fff;
                color: #111827;
                border-radius: 8px;
                padding: 7px 10px;
                font-size: 11px;
                cursor: pointer;
                text-decoration: none;
            }

            .btn-primary {
                background: #a16207;
                border-color: #a16207;
                color: #fff;
                font-weight: 600;
            }

            .head {
                padding: 12px;
                border-bottom: 2px solid #111827;
            }

            .head-top {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                align-items: flex-start;
            }

            .company-block {
                display: flex;
                gap: 10px;
                align-items: flex-start;
                margin-bottom: 8px;
            }

            .company-logo {
                width: 70px;
                height: 70px;
                object-fit: contain;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                background: #fff;
                padding: 3px;
            }

            .company-name {
                font-size: 14px;
                font-weight: 800;
                color: #0f172a;
                line-height: 1.2;
            }

            .company-addr {
                color: var(--muted);
                font-size: 10px;
                margin-top: 3px;
                max-width: 460px;
                line-height: 1.35;
            }

            .title {
                font-size: 16px;
                font-weight: 800;
                margin-bottom: 3px;
                text-align: center;
            }

            .sub {
                color: var(--muted);
                font-size: 10px;
                text-align: center;
            }

            .meta {
                text-align: right;
                font-size: 11px;
                color: var(--muted);
            }

            .title-wrap {
                margin-top: 6px;
            }

            .summary {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 6px;
                padding: 10px 12px;
                border-bottom: 1px solid #e5e7eb;
                background: #fcfdff;
            }

            .sum-card {
                border: 1px solid #e5e7eb;
                background: #fff;
                border-radius: 10px;
                padding: 6px 8px;
            }

            .sum-label {
                color: var(--muted);
                font-size: 9px;
                text-transform: uppercase;
                letter-spacing: .4px;
                font-weight: 700;
            }

            .sum-value {
                font-size: 15px;
                font-weight: 800;
                margin-top: 2px;
            }

            .items {
                padding: 10px;
            }

            .spk-item {
                border: 1px solid var(--line);
                border-radius: 10px;
                margin-bottom: 8px;
                overflow: hidden;
                page-break-inside: avoid;
            }

            .spk-item-head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 7px 9px;
                background: var(--gold-soft);
                border-bottom: 1px solid #f3e8c2;
            }

            .spk-code {
                font-family: Consolas, "Courier New", monospace;
                font-weight: 800;
                color: #92400e;
                font-size: 12px;
            }

            .spk-status {
                font-size: 10px;
                font-weight: 700;
                padding: 2px 8px;
                border-radius: 999px;
                border: 1px solid #d1d5db;
                background: #fff;
            }

            .spk-body {
                padding: 9px;
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 9px;
            }

            .thumb {
                width: 120px;
                height: 90px;
                border: 1px solid #d1d5db;
                border-radius: 10px;
                background: #f8fafc;
                object-fit: cover;
            }

            .thumb-empty {
                width: 120px;
                height: 90px;
                border: 1px dashed #cbd5e1;
                border-radius: 10px;
                color: #94a3b8;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
            }

            .prod {
                font-size: 13px;
                font-weight: 800;
                margin-bottom: 5px;
            }

            .grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 4px 10px;
                font-size: 11px;
            }

            .label {
                color: var(--muted);
            }

            .val {
                font-weight: 700;
            }

            .notes {
                margin-top: 7px;
                padding: 6px 8px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #fcfcfc;
                font-size: 10px;
                line-height: 1.4;
                white-space: pre-wrap;
            }

            .footer {
                text-align: center;
                color: #94a3b8;
                font-size: 10px;
                padding: 8px 10px 12px;
            }

            @media print {
                body {
                    background: #fff;
                    padding: 0;
                }

                .toolbar {
                    display: none;
                }

                .sheet {
                    border: none;
                    border-radius: 0;
                    box-shadow: none;
                    max-width: 100%;
                }

                .spk-item {
                    break-inside: avoid;
                }
            }
        </style>
    </head>

    <body>
        <div class="sheet">
            <div class="toolbar">
                <button class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
                <button class="btn" onclick="window.close()">Close</button>
            </div>

            <div class="head">
                <div class="head-top">
                    <div>
                        <div class="company-block">
                            <?php if ($companyLogoUrl !== ''): ?>
                                <img class="company-logo" src="<?= htmlspecialchars($companyLogoUrl) ?>" alt="Company Logo">
                            <?php endif; ?>
                            <div>
                                <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
                                <div class="company-addr">
                                    <?= htmlspecialchars($companyAddress) ?>
                                    <?php if ($companyPhone !== ''): ?>
                                        <br>Tel: <?= htmlspecialchars($companyPhone) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="meta">
                        <div><strong><?= htmlspecialchars($filterText) ?></strong></div>
                        <div>Generated: <?= date('d M Y H:i') ?></div>
                    </div>
                </div>
                <div class="title-wrap">
                    <div class="title">Purchase Order</div>
                    <div class="sub">Format detail per item dengan gambar, siap cetak untuk produksi</div>
                </div>
            </div>

            <div class="summary">
                <div class="sum-card">
                    <div class="sum-label">Total SPK</div>
                    <div class="sum-value"><?= count($rows) ?></div>
                </div>
                <div class="sum-card">
                    <div class="sum-label">Total Qty</div>
                    <div class="sum-value"><?= rtrim(rtrim(number_format((float)array_sum(array_map(static fn($r) => (float)($r['quantity'] ?? 0), $rows)), 2), '0'), '.') ?></div>
                </div>
                <div class="sum-card">
                    <div class="sum-label">Qty Done</div>
                    <div class="sum-value"><?= rtrim(rtrim(number_format((float)array_sum(array_map(static fn($r) => (float)($r['qty_done'] ?? 0), $rows)), 2), '0'), '.') ?></div>
                </div>
                <div class="sum-card">
                    <div class="sum-label">Progress Avg</div>
                    <div class="sum-value"><?= count($rows) > 0 ? (int)round(array_sum(array_map(static fn($r) => (float)($r['progress_percent'] ?? 0), $rows)) / count($rows)) : 0 ?>%</div>
                </div>
            </div>

            <div class="items">
                <?php if (empty($rows)): ?>
                    <div style="text-align:center;padding:28px 12px;color:#64748b;font-size:13px;border:1px dashed #cbd5e1;border-radius:12px;">
                        Tidak ada order sesuai filter.
                    </div>
                <?php else: ?>
                    <?php foreach ($rows as $i => $r): ?>
                        <?php
                        $imgSrc = '';
                        if (!empty($r['image_path'])) {
                            $imgSrc = preg_match('#^https?://#i', (string)$r['image_path']) ? (string)$r['image_path'] : ($baseUrl . '/' . ltrim((string)$r['image_path'], '/'));
                        }
                        $finish = trim((string)($r['finish'] ?? ''));
                        if ($finish === '') {
                            $finish = trim((string)($r['wood_color'] ?? ''));
                        }
                        $totalPrice = (float)($r['total_price'] ?? 0);
                        if ($totalPrice <= 0) {
                            $totalPrice = ((float)($r['quantity'] ?? 0)) * ((float)($r['unit_price'] ?? 0));
                        }
                        ?>
                        <div class="spk-item">
                            <div class="spk-item-head">
                                <div class="spk-code"><?= ($i + 1) ?>. <?= htmlspecialchars((string)$r['order_code']) ?></div>
                                <div class="spk-status"><?= htmlspecialchars($statusLabels[$r['status']] ?? (string)$r['status']) ?></div>
                            </div>
                            <div class="spk-body">
                                <div>
                                    <?php if ($imgSrc !== ''): ?>
                                        <img class="thumb" src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars((string)$r['product_name']) ?>">
                                    <?php else: ?>
                                        <div class="thumb-empty">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="prod"><?= htmlspecialchars((string)$r['product_name']) ?></div>
                                    <div class="grid">
                                        <div><span class="label">Customer:</span> <span class="val"><?= htmlspecialchars((string)($r['customer_name'] ?? '-')) ?></span></div>
                                        <div><span class="label">Pengrajin:</span> <span class="val"><?= htmlspecialchars((string)($r['craftsman_name'] ?? '-')) ?></span></div>
                                        <div><span class="label">Order Date:</span> <span class="val"><?= !empty($r['order_date']) ? date('d M Y', strtotime((string)$r['order_date'])) : '-' ?></span></div>
                                        <div><span class="label">Deadline:</span> <span class="val"><?= !empty($r['due_date']) ? date('d M Y', strtotime((string)$r['due_date'])) : '-' ?></span></div>
                                        <div><span class="label">Qty:</span> <span class="val"><?= rtrim(rtrim(number_format((float)($r['quantity'] ?? 0), 2), '0'), '.') ?> pcs</span></div>
                                        <div><span class="label">Qty Done:</span> <span class="val"><?= rtrim(rtrim(number_format((float)($r['qty_done'] ?? 0), 2), '0'), '.') ?> pcs (<?= (int)($r['progress_percent'] ?? 0) ?>%)</span></div>
                                        <div><span class="label">Dimensi:</span> <span class="val"><?= htmlspecialchars((string)($r['dimensions'] ?: '-')) ?></span></div>
                                        <div><span class="label">Finish:</span> <span class="val"><?= htmlspecialchars($finish !== '' ? $finish : '-') ?></span></div>
                                        <div><span class="label">Harga Total:</span> <span class="val">Rp <?= number_format((int)$totalPrice, 0, ',', '.') ?></span></div>
                                        <div><span class="label">Status:</span> <span class="val"><?= htmlspecialchars($statusLabels[$r['status']] ?? (string)$r['status']) ?></span></div>
                                    </div>

                                    <?php if (!empty($r['specification'])): ?>
                                        <div class="notes"><strong>Specification:</strong><br><?= nl2br(htmlspecialchars((string)$r['specification'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($r['notes'])): ?>
                                        <div class="notes"><strong>Notes:</strong><br><?= nl2br(htmlspecialchars((string)$r['notes'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="footer">Generated by PWF Office - SPK Filter Print • Powerby AdFsystem</div>
        </div>
    </body>

    </html>
<?php
    exit;
}

// ── EXPORT PDF PRINT ────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $baseUrl = rtrim(BASE_URL, '/');
    $filterCid  = (int)($_GET['customer_id']  ?? 0);
    $filterTid  = (int)($_GET['craftsman_id'] ?? 0);
    $where = 'WHERE 1=1';
    if ($filterCid) $where .= ' AND o.customer_id=' . $filterCid;
    if ($filterTid) $where .= ' AND o.assigned_craftsman_id=' . $filterTid;
    $rows = $pdo->query("
         SELECT o.order_code, c.customer_name, o.order_date, o.due_date,
             o.product_name, o.specification, o.dimensions, o.finish, o.wood_color, o.quantity,
               o.unit_price, o.image_path,
               t.craftsman_name, o.status, o.notes
        FROM pwf_orders o
        LEFT JOIN pwf_customers c ON c.id=o.customer_id
        LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
        $where ORDER BY o.id DESC")->fetchAll();
    $titleFilter = $filterCid ? ' - Customer #' . $filterCid : ($filterTid ? ' - Craftsman #' . $filterTid : '');
    $statusLabels = ['draft' => 'Draft', 'on_progress' => 'On Progress', 'qc' => 'QC', 'ready_ship' => 'Ready to Ship', 'partial_ship' => 'Partial Ship', 'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    header('Content-Type: text/html; charset=utf-8');
?>
    <!doctype html>
    <html>

    <head>
        <meta charset="utf-8">
        <title>Orders Export<?= htmlspecialchars($titleFilter) ?></title>
        <style>
            :root {
                --ink: #1f2937;
                --muted: #6b7280;
                --line: #e5e7eb;
                --bg-soft: #fffdf7;
                --accent: #b8860b;
                --accent-dark: #7c5a08;
            }

            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                font-size: 12px;
                color: var(--ink);
                margin: 0;
                padding: 16px;
                background: radial-gradient(circle at top right, #fff8e6 0%, #ffffff 40%);
            }

            .sheet {
                max-width: 1360px;
                margin: 0 auto;
                background: #fff;
                border: 1px solid #f3ead2;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(17, 24, 39, .08);
                padding: 16px;
            }

            h2 {
                font-size: 18px;
                margin: 0 0 4px;
                letter-spacing: .2px;
            }

            .sub {
                color: var(--muted);
                font-size: 11px;
                margin-bottom: 16px
            }

            .title-wrap {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 12px;
            }

            .title-tag {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                font-size: 10px;
                font-weight: 700;
                color: var(--accent-dark);
                border: 1px solid #f1ddb0;
                background: var(--bg-soft);
                border-radius: 999px;
                text-transform: uppercase;
                letter-spacing: .6px;
            }

            .table-wrap {
                border: 1px solid var(--line);
                border-radius: 10px;
                overflow: hidden;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px
            }

            th {
                background: #2C2C30;
                color: #ECECEC;
                padding: 7px 10px;
                text-align: left;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: .4px
            }

            [data-theme="light"] th {
                background: #1c1511;
                color: #fff;
            }

            td {
                padding: 7px 10px;
                border-bottom: 1px solid var(--border);
                vertical-align: top
            }

            tr:nth-child(even) td {
                background: var(--nav-hover)
            }

            .badge {
                display: inline-block;
                padding: 2px 7px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 600
            }

            .draft {
                background: var(--status-blue-bg);
                color: var(--status-blue-text)
            }

            .on_progress {
                background: var(--status-orange-bg);
                color: var(--status-orange-text)
            }

            .completed {
                background: var(--status-green-bg);
                color: var(--status-green-text)
            }

            .cancelled {
                background: var(--status-red-bg);
                color: var(--status-red-text)
            }

            .shipped,
            .ready_ship {
                background: var(--status-green-bg);
                color: var(--status-green-text)
            }

            .qc {
                background: var(--status-purple-bg);
                color: var(--status-purple-text)
            }

            table td img {
                display: block;
                border-radius: 4px
            }

            @media print {
                body {
                    padding: 0
                }

                .no-print {
                    display: none
                }

                .sheet {
                    box-shadow: none;
                    border: none;
                    border-radius: 0;
                    padding: 0;
                    max-width: 100%;
                }

                table {
                    width: 100%;
                    max-width: 1200px
                }
            }
        </style>
    </head>

    <body>
        <div class="sheet">
            <div class="no-print" style="margin-bottom:12px">
                <button onclick="window.print()" style="background:var(--gold);color:#fff;border:none;padding:8px 18px;border-radius:7px;font-size:12px;cursor:pointer">&#128438; Print / Save PDF</button>
                <button onclick="window.close()" style="margin-left:8px;background:var(--nav-hover);border:1px solid var(--border);padding:8px 14px;border-radius:7px;font-size:12px;cursor:pointer">Close</button>
            </div>
            <div class="title-wrap">
                <div>
                    <h2>Order Report<?= htmlspecialchars($titleFilter) ?></h2>
                    <div class="sub">Generated: <?= date('d F Y, H:i') ?> &nbsp;|&nbsp; Total: <?= count($rows) ?> orders</div>
                </div>
                <span class="title-tag">PWF Office</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:70px">Image</th>
                            <th>Code</th>
                            <th>Order Name</th>
                            <th style="width:90px">Order Date</th>
                            <th>Customer</th>
                            <th>Specification</th>
                            <th>Finish Color</th>
                            <th>Craftsman</th>
                            <th style="width:90px">Due</th>
                            <th style="width:60px;text-align:right">Qty</th>
                            <th style="width:90px;text-align:right;color:var(--gold)">Price</th>
                            <th style="width:120px">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?php if ($r['image_path']): ?><img src="<?= htmlspecialchars($baseUrl . '/' . $r['image_path']) ?>" style="max-width:60px;max-height:50px;border-radius:4px" alt="Order"><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
                                <td><?= htmlspecialchars($r['order_code']) ?></td>
                                <td><strong><?= htmlspecialchars($r['product_name']) ?></strong></td>
                                <td><?= $r['order_date'] ? date('d M Y', strtotime($r['order_date'])) : '—' ?></td>
                                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                                <td><?php if ($r['specification']): ?><span style="color:var(--muted);font-size:10px"><?= htmlspecialchars(mb_substr($r['specification'], 0, 50)) ?></span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
                                <td><?php $finishColor = trim((string)($r['finish'] ?? ''));
                                    if ($finishColor === '') {
                                        $finishColor = trim((string)($r['wood_color'] ?? ''));
                                    }
                                    echo $finishColor !== '' ? htmlspecialchars(mb_substr($finishColor, 0, 50)) : '<span style="color:var(--muted)">—</span>'; ?></td>
                                <td><?= htmlspecialchars($r['craftsman_name'] ?? '—') ?></td>
                                <td><?= $r['due_date'] ? date('d M Y', strtotime($r['due_date'])) : '—' ?></td>
                                <td style="text-align:right"><?= rtrim(rtrim(number_format((float)$r['quantity'], 2), '0'), '.') ?></td>
                                <td style="text-align:right;color:var(--gold);font-weight:600"><?php $total = (float)$r['quantity'] * (float)($r['unit_price'] ?? 0);
                                                                                                echo 'Rp ' . number_format((int)$total, 0, ',', '.'); ?></td>
                                <td><?= htmlspecialchars(mb_substr($r['notes'] ?? '', 0, 60)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}

// ── AJAX: ORDER DETAIL ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detail') {
    ob_clean();
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }
    try {
        $orderStmt = $pdo->prepare("
            SELECT o.*, c.customer_name, t.craftsman_name
            FROM pwf_orders o
            LEFT JOIN pwf_customers c ON c.id=o.customer_id
            LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
            WHERE o.id=?");
        $orderStmt->execute([$id]);
        $orderDetail = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$orderDetail) {
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        $progressLogs = [];
        try {
            $progStmt = $pdo->prepare("
                SELECT p.progress_date, p.achievement_percent, p.work_note, t.craftsman_name
                FROM pwf_order_progress p
                LEFT JOIN pwf_craftsmen t ON t.id=p.craftsman_id
                WHERE p.order_id=?
                ORDER BY p.id DESC LIMIT 20");
            $progStmt->execute([$id]);
            $progressLogs = $progStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist */
        }

        $shippingLogs = [];
        try {
            $shipStmt = $pdo->prepare("
                SELECT ci.qty_shipped, ci.notes AS item_notes,
                       co.container_code, co.container_no, co.container_type,
                       co.shipment_date, co.destination_country, co.destination_port,
                       co.forwarder, co.tracking_no, co.bl_no, co.status AS container_status,
                       co.dropped_at
                FROM pwf_container_items ci
                JOIN pwf_containers co ON co.id=ci.container_id
                WHERE ci.order_id=?
                ORDER BY ci.id DESC");
            $shipStmt->execute([$id]);
            $shippingLogs = $shipStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist */
        }

        echo json_encode(['order' => $orderDetail, 'progress' => $progressLogs, 'shipping' => $shippingLogs]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/layout.php';
$msg = '';
$msgType = 'success';

// ── Helper: upload image ──────────────────────────────────────────────────────
function uploadOrderImage(string $field): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || empty($file['name']) || !is_uploaded_file($file['tmp_name'])) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) return null;
    if ($file['size'] > 8 * 1024 * 1024) return null;
    $dir = BASE_PATH . '/uploads/pwf-orders/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'pwf-' . time() . '-' . mt_rand(100, 999) . '.' . $ext;
    return @move_uploaded_file($file['tmp_name'], $dir . $name) ? 'uploads/pwf-orders/' . $name : null;
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'create';

    if ($action === 'create') {
        $customerId  = (int)($_POST['customer_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        if ($customerId > 0 && $productName !== '') {
            try {
                $imgPath = uploadOrderImage('order_image');
                $quantity = (float)($_POST['quantity'] ?? 1);
                $unitPrice = (float)($_POST['unit_price'] ?? 0);
                $totalPrice = $quantity * $unitPrice;

                $insStmt = $pdo->prepare('INSERT INTO pwf_orders
                    (order_code,customer_id,order_date,due_date,product_name,specification,description,dimensions,quantity,unit_price,total_price,wood_color,finish,image_path,assigned_craftsman_id,notes,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

                // Retry on duplicate order_code (race / deleted rows).
                $attempts = 0;
                while (true) {
                    $code = genPwfCode($pdo, 'ORD');
                    try {
                        $insStmt->execute([
                            $code,
                            $customerId,
                            ($_POST['order_date'] ?? '') ?: date('Y-m-d'),
                            ($_POST['due_date'] ?? '') ?: null,
                            $productName,
                            trim($_POST['specification'] ?? ''),
                            trim($_POST['description'] ?? ''),
                            trim($_POST['dimensions'] ?? ''),
                            $quantity,
                            $unitPrice,
                            $totalPrice,
                            trim($_POST['wood_color'] ?? ''),
                            trim($_POST['finish'] ?? ''),
                            $imgPath,
                            (int)($_POST['assigned_craftsman_id'] ?? 0) ?: null,
                            trim($_POST['notes'] ?? ''),
                            $_SESSION['user_id'] ?? null
                        ]);
                        break;
                    } catch (\PDOException $dup) {
                        // 23000 = duplicate key; retry up to 5 times.
                        if ($dup->getCode() === '23000' && $attempts < 5) {
                            $attempts++;
                            continue;
                        }
                        throw $dup;
                    }
                }
                $msg = 'Order saved successfully.';
            } catch (\Throwable $e) {
                error_log('PWF orders create error: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Gagal menyimpan order: ' . $e->getMessage();
            }
        } else {
            $msgType = 'warning';
            $msg = 'Customer dan Product Name wajib diisi.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['order_id'] ?? 0);
        if ($id > 0) {
            try {
                $existImg = $pdo->prepare('SELECT image_path FROM pwf_orders WHERE id=?');
                $existImg->execute([$id]);
                $existImg = $existImg->fetchColumn();
                $imgPath = uploadOrderImage('order_image') ?: $existImg;

                $quantity = (float)($_POST['quantity'] ?? 1);
                $unitPrice = (float)($_POST['unit_price'] ?? 0);
                $totalPrice = $quantity * $unitPrice;

                $pdo->prepare('UPDATE pwf_orders SET
                    customer_id=?, order_date=?, due_date=?, product_name=?,
                    specification=?, description=?, dimensions=?, quantity=?, unit_price=?, total_price=?,
                    wood_color=?, finish=?, image_path=?,
                    assigned_craftsman_id=?, status=?, notes=?, updated_at=NOW()
                    WHERE id=?')
                    ->execute([
                        (int)($_POST['customer_id'] ?? 0),
                        ($_POST['order_date'] ?? '') ?: date('Y-m-d'),
                        ($_POST['due_date'] ?? '') ?: null,
                        trim($_POST['product_name'] ?? ''),
                        trim($_POST['specification'] ?? ''),
                        trim($_POST['description'] ?? ''),
                        trim($_POST['dimensions'] ?? ''),
                        $quantity,
                        $unitPrice,
                        $totalPrice,
                        trim($_POST['wood_color'] ?? ''),
                        trim($_POST['finish'] ?? ''),
                        $imgPath,
                        (int)($_POST['assigned_craftsman_id'] ?? 0) ?: null,
                        $_POST['status'] ?? 'draft',
                        trim($_POST['notes'] ?? ''),
                        $id
                    ]);
                $msg = 'Order updated successfully.';
            } catch (\Throwable $e) {
                error_log('PWF orders update error: ' . $e->getMessage());
                $msgType = 'warning';
                $msg = 'Gagal memperbarui order: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'start_work') {
        $id = (int)($_POST['order_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE pwf_orders SET status=?, updated_at=NOW() WHERE id=? AND status=?')
                ->execute(['on_progress', $id, 'draft']);
            $msg = 'Order status changed to On Progress.';
        }
    } elseif ($action === 'update_progress') {
        $id      = (int)($_POST['order_id'] ?? 0);
        $qtyDone = max(0, (float)($_POST['qty_done'] ?? 0));
        if ($id > 0) {
            $row = $pdo->prepare('SELECT quantity FROM pwf_orders WHERE id=?');
            $row->execute([$id]);
            $qty = max(0.0001, (float)($row->fetchColumn() ?: 1));
            $pct       = min(100, (int)round($qtyDone / $qty * 100));
            $newStatus = ($qtyDone >= $qty) ? 'completed' : 'on_progress';
            $pdo->prepare('UPDATE pwf_orders SET qty_done=?, progress_percent=?, status=?, updated_at=NOW() WHERE id=?')
                ->execute([$qtyDone, $pct, $newStatus, $id]);
            $msg = 'Progress updated.' . ($newStatus === 'completed' ? ' Order otomatis Completed!' : '');
        }
    } elseif ($action === 'bulk_delete') {
        $rawIds = $_POST['order_ids'] ?? [];
        $ids = [];
        foreach ((array)$rawIds as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) $ids[$id] = $id;
        }
        $ids = array_values($ids);

        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                $pdo->beginTransaction();

                try {
                    $pdo->prepare("DELETE FROM pwf_container_items WHERE order_id IN ($ph)")->execute($ids);
                } catch (Exception $e) {
                    // Skip if shipping tables are not present.
                }

                try {
                    $pdo->prepare("DELETE FROM pwf_order_progress WHERE order_id IN ($ph)")->execute($ids);
                } catch (Exception $e) {
                    // Skip if progress log table is not present.
                }

                $pdo->prepare("DELETE FROM pwf_orders WHERE id IN ($ph)")->execute($ids);
                $pdo->commit();
                $msg = count($ids) . ' order(s) deleted successfully.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msgType = 'warning';
                $msg = 'Failed to delete selected orders: ' . $e->getMessage();
            }
        }
    }
}

// ── DATA ──────────────────────────────────────────────────────────────────────
$customers = $pdo->query('SELECT id, customer_name FROM pwf_customers ORDER BY customer_name')->fetchAll();
$craftsmen = $pdo->query('SELECT id, craftsman_name FROM pwf_craftsmen WHERE is_active=1 ORDER BY craftsman_name')->fetchAll();

$filterCustomerId  = (int)($_GET['customer_id']  ?? 0);
$filterCraftsmanId = (int)($_GET['craftsman_id'] ?? 0);
$whereParts = [];
$whereArgs  = [];
if ($filterCustomerId) {
    $whereParts[] = 'o.customer_id=?';
    $whereArgs[] = $filterCustomerId;
}
if ($filterCraftsmanId) {
    $whereParts[] = 'o.assigned_craftsman_id=?';
    $whereArgs[] = $filterCraftsmanId;
}
$whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$stmtOrders = $pdo->prepare("
    SELECT o.*, c.customer_name, t.craftsman_name
    FROM pwf_orders o
    LEFT JOIN pwf_customers c ON c.id=o.customer_id
    LEFT JOIN pwf_craftsmen t ON t.id=o.assigned_craftsman_id
    $whereClause
    ORDER BY o.id DESC
");
$stmtOrders->execute($whereArgs);
$orders = $stmtOrders->fetchAll();

$statusOptions = ['draft' => 'Draft', 'on_progress' => 'On Progress', 'qc' => 'QC', 'ready_ship' => 'Ready to Ship', 'partial_ship' => 'Partial Ship', 'shipped' => 'Shipped', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$baseUrl = rtrim(BASE_URL, '/');

pwfOfficeHeader('Orders', 'orders');
?>

<style>
    /* ── DETAIL MODAL TABS ── */
    .dm-tab {
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        cursor: pointer;
        transition: all .15s;
        margin-bottom: -1px
    }

    .dm-tab:hover {
        color: var(--text)
    }

    .dm-tab.active {
        color: var(--gold);
        border-bottom-color: var(--gold)
    }

    .dm-section-title {
        font-size: 10.5px;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .5px;
        margin: 16px 0 8px
    }

    .dm-row {
        display: flex;
        gap: 6px;
        align-items: flex-start;
        padding: 5px 0;
        border-bottom: 1px solid var(--border);
        font-size: 12.5px
    }

    .dm-row:last-child {
        border-bottom: none
    }

    .dm-label {
        color: var(--muted);
        min-width: 110px;
        flex-shrink: 0
    }

    .dm-val {
        color: var(--text);
        font-weight: 600;
        word-break: break-word
    }

    .dm-timeline-item {
        display: flex;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
        font-size: 12px
    }

    .dm-timeline-item:last-child {
        border-bottom: none
    }

    .dm-timeline-dot {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--gold-bg);
        border: 2px solid var(--gold-border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        color: #92600A;
        flex-shrink: 0;
        margin-top: 1px
    }

    .dm-ship-card {
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 10px;
        background: var(--nav-hover)
    }

    /* ── MODALS ── */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .45);
        backdrop-filter: blur(2px);
        z-index: 9000;
        display: none;
        align-items: center;
        justify-content: center
    }

    .modal-overlay.open {
        display: flex
    }

    .modal-box {
        background: var(--card);
        border-radius: 16px;
        width: min(950px, 96vw);
        max-height: 92vh;
        overflow-y: auto;
        box-shadow: 0 24px 80px rgba(0, 0, 0, .25)
    }

    .modal-header {
        padding: 16px 22px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        background: var(--card);
        z-index: 1
    }

    .modal-header h5 {
        color: var(--text)
    }

    .modal-header h5 {
        margin: 0;
        font-size: 15px;
        font-weight: 700
    }

    .modal-body {
        padding: 22px;
        background: var(--card);
        color: var(--text)
    }

    /* ── FORM ELEMENTS IN MODAL ── */
    .modal-body .input,
    .modal-body .select,
    .modal-body input[type=text],
    .modal-body input[type=date],
    .modal-body input[type=number],
    .modal-body input[type=email],
    .modal-body input[type=file],
    .modal-body select,
    .modal-body textarea {
        background: var(--input-bg);
        color: var(--text);
        border-color: var(--border)
    }

    .modal-body .input:focus,
    .modal-body input[type=text]:focus,
    .modal-body input[type=date]:focus,
    .modal-body input[type=number]:focus,
    .modal-body input[type=email]:focus,
    .modal-body select:focus,
    .modal-body textarea:focus {
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(184, 134, 11, .12)
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--muted);
        line-height: 1;
        padding: 2px 6px;
        border-radius: 6px
    }

    .modal-close:hover {
        background: var(--nav-hover);
        color: var(--text)
    }

    /* ── FILTER BAR ── */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        padding: 12px 16px;
        background: var(--nav-hover);
        border-bottom: 1px solid var(--border)
    }

    /* ── ORDER CARD GRID ── */
    .order-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 14px;
        padding: 16px
    }

    .order-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        transition: box-shadow .2s, transform .15s;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .order-select-float {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 3;
        width: 22px;
        height: 22px;
        border-radius: 6px;
        background: var(--card);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .12)
    }

    .order-select-checkbox {
        width: 14px;
        height: 14px;
        cursor: pointer
    }

    .order-card:hover {
        box-shadow: 0 6px 24px rgba(0, 0, 0, .09);
        transform: translateY(-2px)
    }

    .order-card-img {
        width: 100%;
        height: 140px;
        object-fit: cover;
        background: var(--nav-hover);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--muted);
        font-size: 13px
    }

    .order-card-img img {
        width: 100%;
        height: 140px;
        object-fit: cover
    }

    .order-card-body {
        padding: 12px 14px;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 5px
    }

    .order-card-code {
        font-size: 11px;
        color: var(--gold);
        font-weight: 700;
        font-family: monospace
    }

    .order-card-name {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--text);
        line-height: 1.3
    }

    .order-card-meta {
        font-size: 11.5px;
        color: var(--muted);
        display: flex;
        align-items: center;
        gap: 6px
    }

    .order-card-footer {
        padding: 10px 14px;
        border-top: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap
    }

    /* toggle view */
    .view-toggle {
        display: flex;
        gap: 4px
    }

    .view-btn {
        background: transparent;
        border: 1px solid var(--border);
        color: var(--muted);
        padding: 4px 9px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        transition: all .15s
    }

    .view-btn.active {
        background: var(--gold-bg);
        border-color: var(--gold-border);
        color: var(--gold)
    }

    /* table view */
    #tableView {
        display: none
    }

    #tableView.visible {
        display: block
    }

    #cardView {
        display: block
    }

    #cardView.hidden {
        display: none
    }
</style>

<?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'warning' ?>" style="margin-bottom:16px">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>

<!-- ── TOP BAR ──────────────────────────────────────────────────────────────── -->
<div class="pwf-card" style="margin-bottom:16px">
    <div class="filter-bar">
        <form method="get" style="display:contents">
            <select class="select" name="customer_id" onchange="this.form.submit()" style="width:180px;flex:0 0 auto">
                <option value="">All Customers</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCustomerId == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['customer_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="select" name="craftsman_id" onchange="this.form.submit()" style="width:180px;flex:0 0 auto">
                <option value="">All Craftsmen</option>
                <?php foreach ($craftsmen as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $filterCraftsmanId == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['craftsman_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterCustomerId || $filterCraftsmanId): ?>
                <a href="orders.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Clear</a>
            <?php endif; ?>
        </form>

        <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
            <div class="view-toggle">
                <button class="view-btn active" id="btnCard" onclick="setView('card')" title="Card view"><i class="bi bi-grid-3x3-gap"></i></button>
                <button class="view-btn" id="btnTable" onclick="setView('table')" title="Table view"><i class="bi bi-list-ul"></i></button>
            </div>
            <button type="button" id="bulkDeleteBtn" class="btn btn-sm btn-outline-danger" onclick="submitBulkDelete()" style="display:none;gap:6px">
                <i class="bi bi-trash"></i> Hapus Terpilih (<span id="selectedCount">0</span>)
            </button>
            <?php if ($filterCustomerId): ?>
                <a href="customer-report.php?customer_id=<?= $filterCustomerId ?>" target="_blank" class="btn btn-export btn-sm"><i class="bi bi-printer"></i> Print</a>
            <?php endif; ?>
            <a href="?print_spk=1&customer_id=<?= $filterCustomerId ?>&craftsman_id=<?= $filterCraftsmanId ?>" target="_blank"
                class="btn btn-export btn-sm"><i class="bi bi-printer"></i> Cetak SPK</a>
            <a href="?export=pdf&customer_id=<?= $filterCustomerId ?>&craftsman_id=<?= $filterCraftsmanId ?>" target="_blank"
                class="btn btn-export btn-sm"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
            <button class="btn btn-sm" onclick="openCreate()" style="gap:6px">
                <i class="bi bi-plus-lg"></i> New Order
            </button>
        </div>
    </div>
</div>

<!-- ── CARD VIEW ────────────────────────────────────────────────────────────── -->
<div id="cardView">
    <?php if (empty($orders)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--muted)">
            <i class="bi bi-clipboard2-x" style="font-size:40px;display:block;margin-bottom:12px;opacity:.4"></i>
            No orders found.
        </div>
    <?php else: ?>
        <div class="order-grid">
            <?php foreach ($orders as $o): ?>
                <div class="order-card">
                    <label class="order-select-float" title="Pilih order" onclick="event.stopPropagation()">
                        <input type="checkbox" class="order-select-checkbox" data-order-id="<?= (int)$o['id'] ?>" onchange="onOrderCheckboxChange(this)">
                    </label>
                    <?php if ($o['image_path']): ?>
                        <div class="order-card-img"><img src="<?= $baseUrl ?>/<?= htmlspecialchars($o['image_path']) ?>" alt="blueprint" loading="lazy"></div>
                    <?php else: ?>
                        <div class="order-card-img"><i class="bi bi-image" style="font-size:32px;opacity:.3"></i></div>
                    <?php endif; ?>
                    <div class="order-card-body" style="cursor:pointer" onclick="openDetail(<?= (int)$o['id'] ?>)">
                        <div class="order-card-code"><?= htmlspecialchars($o['order_code']) ?></div>
                        <div class="order-card-name"><?= htmlspecialchars($o['product_name']) ?></div>
                        <div style="height:1px;background:var(--border);margin:5px 0"></div>
                        <div class="order-card-meta"><i class="bi bi-calendar2" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">Order Date</span><b style="color:var(--text)"><?= $o['order_date'] ? date('d M Y', strtotime($o['order_date'])) : '—' ?></b></div>
                        <div class="order-card-meta"><i class="bi bi-person" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">Customer</span><b style="color:var(--text)"><?= htmlspecialchars($o['customer_name'] ?? '—') ?></b></div>
                        <div class="order-card-meta"><i class="bi bi-hash" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">Qty</span><b style="color:var(--text)"><?= rtrim(rtrim(number_format((float)$o['quantity'], 2), '0'), '.') ?></b></div>
                        <?php if ($o['craftsman_name']): ?>
                            <div class="order-card-meta"><i class="bi bi-hammer" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">Craftsman</span><b style="color:var(--text)"><?= htmlspecialchars($o['craftsman_name']) ?></b></div>
                        <?php endif; ?>
                        <?php if ($o['dimensions']): ?>
                            <div class="order-card-meta"><i class="bi bi-rulers" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">P&times;L&times;T</span><b style="color:var(--text)"><?= htmlspecialchars($o['dimensions']) ?></b></div>
                        <?php endif; ?>
                        <?php if ($o['due_date']): ?>
                            <div class="order-card-meta"><i class="bi bi-calendar-event" style="color:var(--gold)"></i><span style="color:var(--muted);min-width:64px">Deadline</span><b style="color:var(--text)"><?= date('d M Y', strtotime($o['due_date'])) ?></b></div>
                        <?php endif; ?>
                        <?php if (!empty($o['notes'])): ?>
                            <div style="margin-top:4px;font-size:11px;color:var(--muted);background:#FAFAF9;border-radius:6px;padding:5px 8px;border-left:2px solid var(--gold-border);line-height:1.45"><?= htmlspecialchars(mb_substr($o['notes'], 0, 80)) ?><?= mb_strlen($o['notes']) > 80 ? '…' : '' ?></div>
                        <?php endif; ?>
                        <?php if (!in_array($o['status'], ['draft', 'cancelled'])): $pct = (int)$o['progress_percent']; ?>
                            <div style="margin-top:8px">
                                <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                                    <span style="font-size:10.5px;color:var(--muted)"><?= rtrim(rtrim(number_format((float)$o['qty_done'], 2), '0'), '.') ?>&nbsp;/&nbsp;<?= rtrim(rtrim(number_format((float)$o['quantity'], 2), '0'), '.') ?> pcs done</span>
                                    <span style="font-size:11px;font-weight:700;color:var(--gold)"><?= $pct ?>%</span>
                                </div>
                                <div style="height:5px;background:var(--border);border-radius:20px;overflow:hidden">
                                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? '#15803D' : 'var(--gold)' ?>;border-radius:20px"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="order-card-footer">
                        <div style="display:flex;flex-direction:column;gap:3px">
                            <span class="status-badge status-<?= htmlspecialchars($o['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $o['status'])) ?></span>
                            <?php if ($o['status'] === 'partial_ship'): ?>
                                <?php $sisaProd = max(0, (float)$o['quantity'] - (float)$o['qty_done']); ?>
                                <?php if ($sisaProd > 0): ?>
                                    <span style="font-size:9.5px;color:#1D4ED8;font-weight:600;background:#EFF6FF;border-radius:6px;padding:2px 6px">
                                        Sisa Produksi: <?= rtrim(rtrim(number_format($sisaProd, 2), '0'), '.') ?> pcs
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div style="margin-left:auto;display:flex;gap:5px">
                            <?php if ($o['status'] === 'draft'): ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Start work on this order?')">
                                    <input type="hidden" name="_action" value="start_work">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                    <button class="btn btn-sm" type="submit" title="Start Work"
                                        style="background:#FFF7ED;border:1px solid #FED7AA;color:#C2410C;padding:4px 9px;font-size:11px">
                                        <i class="bi bi-play-circle"></i> Start
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ($o['status'] === 'on_progress'): ?>
                                <button class="btn btn-sm" title="Update Progress"
                                    onclick="openProgress(<?= htmlspecialchars(json_encode(['id' => (int)$o['id'], 'code' => $o['order_code'], 'name' => $o['product_name'], 'qty' => (float)$o['quantity'], 'qty_done' => (float)$o['qty_done'], 'pct' => (int)$o['progress_percent']]), ENT_QUOTES) ?>)"
                                    style="background:#F5F3FF;border:1px solid #DDD6FE;color:#6D28D9;padding:4px 9px;font-size:11px">
                                    <i class="bi bi-bar-chart-line"></i>
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                onclick="openEdit(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="spk.php?id=<?= (int)$o['id'] ?>" target="_blank"
                                class="btn btn-sm btn-outline-secondary" title="Print SPK">
                                <i class="bi bi-file-earmark-text"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ── TABLE VIEW ───────────────────────────────────────────────────────────── -->
<div id="tableView">
    <div class="pwf-card">
        <div style="overflow-x:auto">
            <table class="pwf-table">
                <thead>
                    <tr>
                        <th style="width:42px;text-align:center">
                            <input type="checkbox" id="selectAllOrders" class="order-select-checkbox" title="Pilih semua" onchange="toggleSelectAll(this)">
                        </th>
                        <th style="width:60px">Image</th>
                        <th>Code</th>
                        <th style="width:85px">Order Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Craftsman</th>
                        <th>Due</th>
                        <th style="width:60px;text-align:right">Qty</th>
                        <th style="width:90px;text-align:right">Price</th>
                        <th style="width:120px">Remarks</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td style="text-align:center">
                                <input type="checkbox" class="order-select-checkbox" data-order-id="<?= (int)$o['id'] ?>" onchange="onOrderCheckboxChange(this)">
                            </td>
                            <td>
                                <?php if ($o['image_path']): ?>
                                    <img src="<?= $baseUrl ?>/<?= htmlspecialchars($o['image_path']) ?>" style="width:44px;height:44px;object-fit:cover;border-radius:7px;border:1px solid var(--border)">
                                <?php else: ?>
                                    <div style="width:44px;height:44px;border-radius:7px;background:#F5F3F0;display:flex;align-items:center;justify-content:center"><i class="bi bi-image" style="color:var(--muted);font-size:16px"></i></div>
                                <?php endif; ?>
                            </td>
                            <td onclick="openDetail(<?= (int)$o['id'] ?>)" style="cursor:pointer">
                                <code style="font-size:11px;color:var(--gold)"><?= htmlspecialchars($o['order_code']) ?></code>
                            </td>
                            <td style="font-size:11px;color:var(--muted);white-space:nowrap"><?= $o['order_date'] ? date('d M Y', strtotime($o['order_date'])) : '—' ?></td>
                            <td><?= htmlspecialchars($o['customer_name'] ?? '—') ?></td>
                            <td onclick="openDetail(<?= (int)$o['id'] ?>)" style="cursor:pointer">
                                <?= htmlspecialchars($o['product_name']) ?><div class="small"><?= htmlspecialchars($o['dimensions'] ?? '') ?></div>
                            </td>
                            <td><?= htmlspecialchars($o['craftsman_name'] ?? '—') ?></td>
                            <td><?= $o['due_date'] ? date('d M Y', strtotime($o['due_date'])) : '—' ?></td>
                            <td style="font-size:11px;text-align:right;font-weight:600;color:var(--text)"><?= rtrim(rtrim(number_format((float)$o['quantity'], 2), '0'), '.') ?> pcs</td>
                            <td style="font-size:11px;text-align:right;color:var(--gold);font-weight:600">
                                <?php if ((float)$o['total_price'] > 0): ?>
                                    Rp <?= number_format((float)$o['total_price'], 0, ',', '.') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td style="font-size:10px;color:var(--muted);word-break:break-word">
                                <?= htmlspecialchars(mb_strimwidth($o['notes'] ?? '', 0, 60, '...')) ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:4px;align-items:center">
                                    <button class="btn btn-sm btn-outline-secondary" title="Detail" onclick="openDetail(<?= (int)$o['id'] ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($o['status'] === 'draft'): ?>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Start work?')">
                                            <input type="hidden" name="_action" value="start_work">
                                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                            <button class="btn btn-sm" type="submit"
                                                style="background:var(--status-orange-bg);border:1px solid var(--gold-border);color:var(--status-orange-text);padding:4px 8px;font-size:11px">
                                                <i class="bi bi-play-circle"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                        onclick="openEdit(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="spk.php?id=<?= (int)$o['id'] ?>" target="_blank"
                                        class="btn btn-sm btn-outline-secondary" title="Print SPK">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($orders)): ?><tr>
                            <td colspan="9" style="text-align:center;color:var(--muted);padding:24px">No orders found.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form method="post" id="bulkDeleteForm" style="display:none">
    <input type="hidden" name="_action" value="bulk_delete">
    <div id="bulkDeleteInputs"></div>
</form>

<!-- ── DETAIL MODAL ─────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box" style="width:min(820px,96vw)">
        <div class="modal-header">
            <div>
                <h5 id="dm_title" style="margin:0;font-size:15px;font-weight:700"></h5>
                <div id="dm_code" style="font-size:11px;color:var(--muted);font-family:monospace;margin-top:2px"></div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span id="dm_status_badge"></span>
                <button class="modal-close" onclick="closeDetail()">&times;</button>
            </div>
        </div>
        <!-- Tabs -->
        <div style="border-bottom:1px solid var(--border);padding:0 22px;display:flex;gap:0;background:var(--nav-hover)">
            <button class="dm-tab active" id="dm_tab_info" onclick="showDmTab('info')">📋 Detail Pesanan</button>
            <button class="dm-tab" id="dm_tab_progress" onclick="showDmTab('progress')">📈 Progress Produksi</button>
            <button class="dm-tab" id="dm_tab_shipping" onclick="showDmTab('shipping')">🚢 Pengiriman</button>
        </div>
        <div class="modal-body" style="padding:20px 22px;min-height:280px">
            <!-- Loading -->
            <div id="dm_loading" style="text-align:center;padding:48px;color:var(--muted)">
                <div style="font-size:22px;margin-bottom:8px">⏳</div>
                <div style="font-size:13px">Memuat data...</div>
            </div>

            <!-- Info Tab -->
            <div id="dm_info" style="display:none">
                <div style="display:grid;grid-template-columns:200px 1fr;gap:18px;align-items:start">
                    <div>
                        <div id="dm_img" style="border-radius:10px;overflow:hidden;border:1px solid var(--border);background:var(--nav-hover);height:170px;display:flex;align-items:center;justify-content:center;color:var(--muted)">
                            <i class="bi bi-image" style="font-size:32px;opacity:.3"></i>
                        </div>
                        <div style="margin-top:10px" id="dm_spk_link"></div>
                    </div>
                    <div>
                        <div class="dm-section-title" style="margin-top:0">Informasi Order</div>
                        <div id="dm_info_rows"></div>
                    </div>
                </div>
                <div style="margin-top:16px" id="dm_spec_block"></div>
                <div style="margin-top:10px" id="dm_notes_block"></div>
                <div style="margin-top:16px;display:flex;gap:8px" id="dm_action_btns"></div>
            </div>

            <!-- Progress Tab -->
            <div id="dm_progress" style="display:none">
                <div id="dm_progress_bar_section" style="margin-bottom:18px"></div>
                <div class="dm-section-title" style="margin-top:0">Riwayat Progress</div>
                <div id="dm_progress_log"></div>
            </div>

            <!-- Shipping Tab -->
            <div id="dm_shipping" style="display:none">
                <div id="dm_shipping_summary" style="margin-bottom:14px"></div>
                <div class="dm-section-title" style="margin-top:0">Riwayat Pengiriman</div>
                <div id="dm_shipping_list"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── NEW ORDER MODAL ───────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="bi bi-plus-circle me-2" style="color:var(--gold)"></i>New Order</h5>
            <button class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="create">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <!-- Customer -->
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Customer</label>
                        <select class="select" name="customer_id" required style="font-size:13px">
                            <option value="">— Select Customer —</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Order Date + Deadline -->
                    <div class="pwf-form-group"><label style="font-size:12px">Order Date</label><input class="input" type="date" name="order_date" value="<?= date('Y-m-d') ?>" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Deadline</label><input class="input" type="date" name="due_date" style="font-size:13px"></div>

                    <!-- Product Name + Specification -->
                    <div class="pwf-form-group"><label style="font-size:12px">Product Name</label><input class="input" name="product_name" placeholder="Product name" required style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Specification</label><textarea name="specification" placeholder="Material, color, finishing..." style="height:36px;font-size:13px"></textarea></div>

                    <!-- Dimensions, Color, Finish -->
                    <div class="pwf-form-group"><label style="font-size:12px">Dimensions</label><input class="input" name="dimensions" placeholder="120×60×75" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Wood Color</label><input class="input" name="wood_color" placeholder="Mahogany" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Finish</label><input class="input" name="finish" placeholder="Lacquer" style="font-size:13px"></div>

                    <!-- Description (hidden but still in form) -->
                    <input type="hidden" name="description" value="">

                    <!-- Pricing Section -->
                    <div style="grid-column:1/-1;height:1px;background:linear-gradient(to right, transparent, var(--border) 20%, var(--border) 80%, transparent);margin:6px 0;opacity:.4"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Quantity</label><input class="input" type="number" step="1" name="quantity" id="new_qty" value="1" oninput="createCalculateTotal()" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Unit Price (Rp)</label><input class="input" type="number" step="1" name="unit_price" id="new_unit_price" value="0" oninput="createCalculateTotal()" style="font-size:13px"></div>
                    <div class="pwf-form-group" style="background:var(--status-orange-bg);border-radius:8px;padding:10px;border:1px solid var(--gold-border);grid-column:1/-1">
                        <label style="font-size:11px;color:var(--status-orange-text);text-transform:uppercase;font-weight:600;letter-spacing:.3px">Total Price (Rp)</label>
                        <div id="new_total_price" style="font-size:18px;font-weight:700;color:var(--gold);margin-top:4px;font-family:monospace">0</div>
                    </div>

                    <!-- Photo & Notes -->
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Photo / Blueprint</label><input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp" style="font-size:13px">
                        <div style="font-size:10px;color:var(--muted);margin-top:2px">Max 8MB</div>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Assign Craftsman</label>
                        <select class="select" name="assigned_craftsman_id" style="font-size:13px">
                            <option value="">— Not assigned yet —</option>
                            <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Notes / Remarks</label><textarea name="notes" placeholder="Special requests..." style="height:36px;font-size:13px"></textarea></div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-plus-circle"></i> Save Order</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('createModal').classList.remove('open')" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── EDIT MODAL ──────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5><i class="bi bi-pencil-square me-2" style="color:var(--gold)"></i>Edit Order — <span id="editModalCode"></span></h5>
            <button class="modal-close" onclick="closeEdit()">&times;</button>
        </div>
        <div class="modal-body">
            <form method="post" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="order_id" id="ef_id">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
                    <!-- Customer + Status -->
                    <div class="pwf-form-group"><label style="font-size:12px">Customer</label>
                        <select class="select" name="customer_id" id="ef_customer" required style="font-size:13px">
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['customer_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pwf-form-group"><label style="font-size:12px">Status</label>
                        <select class="select" name="status" id="ef_status" style="font-size:13px">
                            <?php foreach ($statusOptions as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Order Date + Deadline -->
                    <div class="pwf-form-group"><label style="font-size:12px">Order Date</label><input class="input" type="date" name="order_date" id="ef_order_date" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Deadline</label><input class="input" type="date" name="due_date" id="ef_due_date" style="font-size:13px"></div>

                    <!-- Product + Specification -->
                    <div class="pwf-form-group"><label style="font-size:12px">Product Name</label><input class="input" name="product_name" id="ef_product" required style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Specification</label><textarea name="specification" id="ef_spec" style="height:36px;font-size:13px"></textarea></div>

                    <!-- Dimensions + Color + Finish -->
                    <div class="pwf-form-group"><label style="font-size:12px">Dimensions</label><input class="input" name="dimensions" id="ef_dim" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Wood Color</label><input class="input" name="wood_color" id="ef_wood_color" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Finish</label><input class="input" name="finish" id="ef_finish" style="font-size:13px"></div>

                    <!-- Description (hidden) -->
                    <input type="hidden" name="description" id="ef_description" value="">

                    <!-- Pricing -->
                    <div style="grid-column:1/-1;height:1px;background:linear-gradient(to right, transparent, var(--border) 20%, var(--border) 80%, transparent);margin:6px 0;opacity:.4"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Quantity</label><input class="input" type="number" step="1" name="quantity" id="ef_qty" oninput="calculateTotal()" style="font-size:13px"></div>
                    <div class="pwf-form-group"><label style="font-size:12px">Unit Price (Rp)</label><input class="input" type="number" step="1" name="unit_price" id="ef_unit_price" oninput="calculateTotal()" style="font-size:13px"></div>
                    <div class="pwf-form-group" style="background:#F5F3F0;border-radius:8px;padding:10px;border:1px solid var(--border);grid-column:1/-1">
                        <label style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600">Total Price (Rp)</label>
                        <div id="ef_total_price" style="font-size:18px;font-weight:700;color:var(--gold);margin-top:4px">0</div>
                    </div>

                    <!-- Photo + Craftsman + Notes -->
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Photo / Blueprint</label>
                        <div id="ef_img_preview" style="margin-bottom:6px"></div>
                        <input class="input" type="file" name="order_image" accept=".jpg,.jpeg,.png,.webp" style="font-size:13px">
                        <div style="font-size:10px;color:var(--muted);margin-top:2px">Leave empty to keep current</div>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Assign Craftsman</label>
                        <select class="select" name="assigned_craftsman_id" id="ef_craftsman" style="font-size:13px">
                            <option value="">— Not assigned —</option>
                            <?php foreach ($craftsmen as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['craftsman_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pwf-form-group" style="grid-column:1/-1"><label style="font-size:12px">Notes / Remarks</label><textarea name="notes" id="ef_notes" style="height:36px;font-size:13px"></textarea></div>
                </div>
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn" type="submit" style="font-size:13px"><i class="bi bi-check-circle"></i> Save Changes</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="closeEdit()" style="font-size:13px">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const selectedOrderIds = new Set();

    // ── VIEW TOGGLE ───────────────────────────────────────────────────────────────
    function setView(v) {
        const saved = v;
        localStorage.setItem('pwf_order_view', saved);
        document.getElementById('cardView').classList.toggle('hidden', v === 'table');
        document.getElementById('tableView').classList.toggle('visible', v === 'table');
        document.getElementById('btnCard').classList.toggle('active', v === 'card');
        document.getElementById('btnTable').classList.toggle('active', v === 'table');
    }
    (function() {
        const v = localStorage.getItem('pwf_order_view') || 'card';
        if (v === 'table') setView('table');
    })();

    function onOrderCheckboxChange(el) {
        const id = parseInt(el.dataset.orderId || '0', 10);
        if (!id) return;

        if (el.checked) selectedOrderIds.add(id);
        else selectedOrderIds.delete(id);

        document.querySelectorAll('.order-select-checkbox[data-order-id="' + id + '"]').forEach(cb => {
            if (cb !== el) cb.checked = el.checked;
        });
        syncBulkDeleteUI();
    }

    function toggleSelectAll(master) {
        document.querySelectorAll('.order-select-checkbox[data-order-id]').forEach(cb => {
            cb.checked = master.checked;
            const id = parseInt(cb.dataset.orderId || '0', 10);
            if (!id) return;
            if (master.checked) selectedOrderIds.add(id);
            else selectedOrderIds.delete(id);
        });
        syncBulkDeleteUI();
    }

    function syncBulkDeleteUI() {
        const count = selectedOrderIds.size;
        const bulkBtn = document.getElementById('bulkDeleteBtn');
        const countEl = document.getElementById('selectedCount');
        if (countEl) countEl.textContent = String(count);
        if (bulkBtn) bulkBtn.style.display = count > 0 ? 'inline-flex' : 'none';

        const all = Array.from(document.querySelectorAll('.order-select-checkbox[data-order-id]'));
        const master = document.getElementById('selectAllOrders');
        if (master) {
            const uniqueIds = new Set(all.map(cb => cb.dataset.orderId));
            master.checked = uniqueIds.size > 0 && count === uniqueIds.size;
        }
    }

    function submitBulkDelete() {
        if (selectedOrderIds.size === 0) {
            alert('Pilih minimal 1 order untuk dihapus.');
            return;
        }
        if (!confirm('Hapus ' + selectedOrderIds.size + ' order terpilih? Tindakan ini tidak bisa dibatalkan.')) {
            return;
        }
        const wrap = document.getElementById('bulkDeleteInputs');
        wrap.innerHTML = '';
        selectedOrderIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order_ids[]';
            input.value = String(id);
            wrap.appendChild(input);
        });
        document.getElementById('bulkDeleteForm').submit();
    }

    // ── DETAIL MODAL ──────────────────────────────────────────────────────────────
    const STATUS_LABELS = {
        draft: 'Draft',
        on_progress: 'On Progress',
        qc: 'QC',
        ready_ship: 'Ready to Ship',
        partial_ship: 'Partial Ship',
        shipped: 'Shipped',
        completed: 'Completed',
        cancelled: 'Cancelled'
    };
    const STATUS_COLORS = {
        draft: '#1D4ED8',
        on_progress: '#C2410C',
        qc: '#6D28D9',
        ready_ship: '#15803D',
        partial_ship: '#1D4ED8',
        shipped: '#15803D',
        completed: '#166534',
        cancelled: '#991B1B'
    };
    const STATUS_BG = {
        draft: '#EFF6FF',
        on_progress: '#FFF7ED',
        qc: '#F5F3FF',
        ready_ship: '#F0FDF4',
        partial_ship: '#EFF6FF',
        shipped: '#F0FDF4',
        completed: '#F0FDF4',
        cancelled: '#FEF2F2'
    };

    let _dmCurrentId = null;
    let _dmCurrentTab = 'info';
    let _dmData = null;

    function openDetail(orderId) {
        _dmCurrentId = orderId;
        _dmCurrentTab = 'info';
        _dmData = null;
        document.getElementById('detailModal').classList.add('open');
        document.getElementById('dm_loading').style.display = 'block';
        ['dm_info', 'dm_progress', 'dm_shipping'].forEach(id => document.getElementById(id).style.display = 'none');
        document.getElementById('dm_title').textContent = '';
        document.getElementById('dm_code').textContent = '';
        document.getElementById('dm_status_badge').innerHTML = '';
        ['dm_tab_info', 'dm_tab_progress', 'dm_tab_shipping'].forEach(id => document.getElementById(id).classList.remove('active'));
        document.getElementById('dm_tab_info').classList.add('active');

        fetch('orders.php?ajax=detail&id=' + orderId)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('dm_loading').innerHTML = '<div style="color:#991B1B;text-align:center;padding:40px"><i class="bi bi-exclamation-circle" style="font-size:28px;display:block;margin-bottom:8px"></i>' + data.error + '</div>';
                    return;
                }
                _dmData = data;
                renderDetail(data);
            })
            .catch(err => {
                document.getElementById('dm_loading').innerHTML = '<div style="color:#991B1B;text-align:center;padding:40px"><i class="bi bi-exclamation-circle" style="font-size:28px;display:block;margin-bottom:8px"></i>Gagal memuat data.</div>';
            });
    }

    function closeDetail() {
        document.getElementById('detailModal').classList.remove('open');
    }

    function showDmTab(tab) {
        _dmCurrentTab = tab;
        ['info', 'progress', 'shipping'].forEach(t => {
            document.getElementById('dm_' + t).style.display = t === tab ? 'block' : 'none';
            document.getElementById('dm_tab_' + t).classList.toggle('active', t === tab);
        });
    }

    function fmtQty(v) {
        const n = parseFloat(v) || 0;
        return n % 1 === 0 ? n.toFixed(0) : parseFloat(n.toFixed(2)).toString();
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    function fmtDatetime(s) {
        if (!s) return '—';
        const d = new Date(s);
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ' ' + hh + ':' + mm;
    }

    function renderDetail(data) {
        document.getElementById('dm_loading').style.display = 'none';
        const o = data.order;
        const st = o.status || 'draft';

        // Header
        document.getElementById('dm_title').textContent = o.product_name || '—';
        document.getElementById('dm_code').textContent = o.order_code || '';
        document.getElementById('dm_status_badge').innerHTML =
            `<span style="background:${STATUS_BG[st]||'#eee'};color:${STATUS_COLORS[st]||'#333'};padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700">${STATUS_LABELS[st] || st}</span>`;

        // ── INFO TAB ──
        const imgEl = document.getElementById('dm_img');
        if (o.image_path) {
            imgEl.innerHTML = `<img src="<?= $baseUrl ?>/${o.image_path}" style="width:100%;height:170px;object-fit:cover">`;
        } else {
            imgEl.innerHTML = '<i class="bi bi-image" style="font-size:32px;opacity:.3"></i>';
        }

        document.getElementById('dm_spk_link').innerHTML =
            `<a href="spk.php?id=${o.id}" target="_blank" class="btn btn-sm btn-outline-secondary" style="width:100%;text-align:center"><i class="bi bi-file-earmark-text me-1"></i>Cetak SPK</a>`;

        const rows = [
            ['Customer', o.customer_name || '—'],
            ['Tanggal Order', fmtDate(o.order_date)],
            ['Deadline', fmtDate(o.due_date)],
            ['Dimensi (P×L×T)', o.dimensions || '—'],
            ['Quantity', fmtQty(o.quantity) + ' pcs'],
            ['Craftsman', o.craftsman_name || '—'],
        ];
        document.getElementById('dm_info_rows').innerHTML = rows.map(([l, v]) =>
            `<div class="dm-row"><span class="dm-label">${l}</span><span class="dm-val">${v}</span></div>`
        ).join('');

        const specBlock = document.getElementById('dm_spec_block');
        if (o.specification) {
            specBlock.innerHTML = `<div class="dm-section-title">Spesifikasi</div>
                <div style="font-size:12.5px;color:var(--text);background:#FAFAF9;border-radius:8px;padding:10px 14px;border:1px solid var(--border);white-space:pre-line;line-height:1.6">${escHtml(o.specification)}</div>`;
        } else {
            specBlock.innerHTML = '';
        }

        const notesBlock = document.getElementById('dm_notes_block');
        if (o.notes) {
            notesBlock.innerHTML = `<div class="dm-section-title">Catatan</div>
                <div style="font-size:12.5px;color:var(--text);background:#FFFBEB;border-radius:8px;padding:10px 14px;border:1px solid #FDE68A;border-left:3px solid #D97706;white-space:pre-line;line-height:1.6">${escHtml(o.notes)}</div>`;
        } else {
            notesBlock.innerHTML = '';
        }

        const actionBtns = document.getElementById('dm_action_btns');
        actionBtns.innerHTML = `
            <button class="btn btn-sm" onclick="closeDetail(); openEdit(${JSON.stringify(o).replace(/'/g, "\\'")})" style="font-size:12px">
                <i class="bi bi-pencil me-1"></i>Edit Order
            </button>`;
        if (st === 'on_progress') {
            actionBtns.innerHTML += `
            <button class="btn btn-sm" onclick="closeDetail(); openProgress(${JSON.stringify({id:parseInt(o.id),code:o.order_code,name:o.product_name,qty:parseFloat(o.quantity),qty_done:parseFloat(o.qty_done||0),pct:parseInt(o.progress_percent||0)})})"
                style="background:#F5F3FF;border:1px solid #DDD6FE;color:#6D28D9;font-size:12px">
                <i class="bi bi-bar-chart-line me-1"></i>Update Progress
            </button>`;
        }

        // ── PROGRESS TAB ──
        const pct = parseInt(o.progress_percent) || 0;
        const qtyDone = parseFloat(o.qty_done) || 0;
        const qty = parseFloat(o.quantity) || 1;
        document.getElementById('dm_progress_bar_section').innerHTML = `
            <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 18px">
                <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                    <span style="font-size:13px;color:var(--muted)">
                        <b style="color:var(--text);font-size:15px">${fmtQty(qtyDone)}</b> / ${fmtQty(qty)} pcs selesai
                    </span>
                    <span style="font-size:20px;font-weight:800;color:${pct>=100?'#15803D':'var(--gold)'}">${pct}%</span>
                </div>
                <div style="height:10px;background:var(--border);border-radius:20px;overflow:hidden">
                    <div style="width:${pct}%;height:100%;background:${pct>=100?'#15803D':'var(--gold)'};border-radius:20px;transition:width .4s"></div>
                </div>
                <div style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap">
                    <span style="font-size:11.5px;color:var(--muted)">Status: <b style="color:${STATUS_COLORS[st]||'#333'}">${STATUS_LABELS[st]||st}</b></span>
                    ${qtyDone < qty && qty - qtyDone > 0 ? `<span style="font-size:11.5px;color:var(--muted)">Sisa produksi: <b style="color:#1D4ED8">${fmtQty(qty - qtyDone)} pcs</b></span>` : ''}
                </div>
            </div>`;

        const progLog = document.getElementById('dm_progress_log');
        if (!data.progress || data.progress.length === 0) {
            progLog.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);font-size:13px">Belum ada riwayat progress.</div>';
        } else {
            progLog.innerHTML = data.progress.map((p, i) => `
                <div class="dm-timeline-item">
                    <div class="dm-timeline-dot">${p.achievement_percent}%</div>
                    <div style="flex:1">
                        <div style="font-size:12.5px;font-weight:600;color:var(--text)">${escHtml(p.work_note || '—')}</div>
                        <div style="font-size:11px;color:var(--muted);margin-top:3px">
                            ${p.craftsman_name ? `<span><i class="bi bi-hammer me-1"></i>${escHtml(p.craftsman_name)}</span> &nbsp;` : ''}
                            <span><i class="bi bi-clock me-1"></i>${fmtDatetime(p.progress_date)}</span>
                        </div>
                    </div>
                </div>`).join('');
        }

        // ── SHIPPING TAB ──
        const totalShipped = (data.shipping || []).reduce((s, r) => s + parseFloat(r.qty_shipped || 0), 0);
        const shipSummary = document.getElementById('dm_shipping_summary');
        shipSummary.innerHTML = `
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:10px 18px;flex:1;min-width:120px">
                    <div style="font-size:10.5px;color:#166534;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Total Dikirim</div>
                    <div style="font-size:22px;font-weight:800;color:#15803D;margin-top:2px">${fmtQty(totalShipped)} <span style="font-size:13px;font-weight:400">pcs</span></div>
                </div>
                <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:10px 18px;flex:1;min-width:120px">
                    <div style="font-size:10.5px;color:#1D4ED8;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Total Pesanan</div>
                    <div style="font-size:22px;font-weight:800;color:#1D4ED8;margin-top:2px">${fmtQty(qty)} <span style="font-size:13px;font-weight:400">pcs</span></div>
                </div>
                <div style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px;padding:10px 18px;flex:1;min-width:120px">
                    <div style="font-size:10.5px;color:#C2410C;font-weight:600;text-transform:uppercase;letter-spacing:.4px">Belum Dikirim</div>
                    <div style="font-size:22px;font-weight:800;color:#C2410C;margin-top:2px">${fmtQty(Math.max(0, qty - totalShipped))} <span style="font-size:13px;font-weight:400">pcs</span></div>
                </div>
            </div>`;

        const shipList = document.getElementById('dm_shipping_list');
        if (!data.shipping || data.shipping.length === 0) {
            shipList.innerHTML = '<div style="text-align:center;padding:24px;color:var(--muted);font-size:13px">Belum ada data pengiriman.</div>';
        } else {
            shipList.innerHTML = data.shipping.map(s => {
                const sst = s.container_status || '';
                const sstBg = sst === 'delivered' ? '#F0FDF4' : sst === 'shipped' ? '#EFF6FF' : '#FAFAF9';
                const sstColor = sst === 'delivered' ? '#166534' : sst === 'shipped' ? '#1D4ED8' : '#555';
                return `
                <div class="dm-ship-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;gap:6px">
                        <div>
                            <span style="font-size:13px;font-weight:800;color:var(--gold);font-family:monospace">${escHtml(s.container_code || '—')}</span>
                            ${s.container_no ? `<span style="font-size:11px;color:var(--muted);margin-left:8px">${escHtml(s.container_no)}</span>` : ''}
                        </div>
                        <span style="background:${sstBg};color:${sstColor};padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:capitalize">${escHtml(sst.replace(/_/g,' '))}</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px 16px;font-size:12px">
                        <div><span style="color:var(--muted)">Qty Dikirim:</span> <b>${fmtQty(s.qty_shipped)} pcs</b></div>
                        ${s.shipment_date ? `<div><span style="color:var(--muted)">Tgl Kirim:</span> <b>${fmtDate(s.shipment_date)}</b></div>` : '<div></div>'}
                        ${s.destination_country ? `<div><span style="color:var(--muted)">Tujuan:</span> <b>${escHtml(s.destination_country)}${s.destination_port ? ' / ' + escHtml(s.destination_port) : ''}</b></div>` : '<div></div>'}
                        ${s.forwarder ? `<div><span style="color:var(--muted)">Forwarder:</span> <b>${escHtml(s.forwarder)}</b></div>` : '<div></div>'}
                        ${s.tracking_no ? `<div><span style="color:var(--muted)">Tracking:</span> <b>${escHtml(s.tracking_no)}</b></div>` : ''}
                        ${s.bl_no ? `<div><span style="color:var(--muted)">BL No:</span> <b>${escHtml(s.bl_no)}</b></div>` : ''}
                        ${s.dropped_at ? `<div><span style="color:var(--muted)">Tiba:</span> <b>${fmtDate(s.dropped_at)}</b></div>` : ''}
                    </div>
                    ${s.item_notes ? `<div style="margin-top:8px;font-size:11.5px;color:var(--muted);border-top:1px solid var(--border);padding-top:6px">${escHtml(s.item_notes)}</div>` : ''}
                </div>`;
            }).join('');
        }

        // Show info tab
        showDmTab('info');
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.getElementById('detailModal').addEventListener('click', function(e) {
        if (e.target === this) closeDetail();
    });


    // ── MODALS ────────────────────────────────────────────────────────────────────
    function openCreate() {
        createCalculateTotal();
        document.getElementById('createModal').classList.add('open');
    }

    function openEdit(o) {
        document.getElementById('ef_id').value = o.id;
        document.getElementById('editModalCode').textContent = o.order_code;
        document.getElementById('ef_customer').value = o.customer_id;
        document.getElementById('ef_status').value = o.status;
        document.getElementById('ef_order_date').value = o.order_date;
        document.getElementById('ef_due_date').value = o.due_date || '';
        document.getElementById('ef_product').value = o.product_name;
        document.getElementById('ef_spec').value = o.specification || '';
        document.getElementById('ef_description').value = o.description || '';
        document.getElementById('ef_dim').value = o.dimensions || '';
        document.getElementById('ef_wood_color').value = o.wood_color || '';
        document.getElementById('ef_finish').value = o.finish || '';
        document.getElementById('ef_qty').value = o.quantity;
        document.getElementById('ef_unit_price').value = o.unit_price || 0;
        document.getElementById('ef_craftsman').value = o.assigned_craftsman_id || '';
        document.getElementById('ef_notes').value = o.notes || '';
        const prev = document.getElementById('ef_img_preview');
        prev.innerHTML = o.image_path ?
            `<img src="<?= $baseUrl ?>/${o.image_path}" style="max-height:130px;border-radius:8px;border:1px solid #E7E5E4">` :
            '<span style="font-size:11.5px;color:var(--muted)">No image yet</span>';
        calculateTotal();
        document.getElementById('editModal').classList.add('open');
    }

    function calculateTotal() {
        const qty = parseFloat(document.getElementById('ef_qty').value) || 0;
        const price = parseFloat(document.getElementById('ef_unit_price').value) || 0;
        const total = qty * price;
        document.getElementById('ef_total_price').textContent = new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(total);
    }

    function createCalculateTotal() {
        const qty = parseFloat(document.getElementById('new_qty').value) || 0;
        const price = parseFloat(document.getElementById('new_unit_price').value) || 0;
        const total = qty * price;
        document.getElementById('new_total_price').textContent = new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(total);
    }

    function closeEdit() {
        document.getElementById('editModal').classList.remove('open');
    }
    // Close on backdrop click
    ['createModal', 'editModal'].forEach(id => {
        document.getElementById(id).addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });
    });

    <?php if ($msg): ?>
        // Auto-open modal on success not needed — message already shown inline
    <?php endif; ?>

    function openProgress(o) {
        document.getElementById('pmTitle').textContent = o.name;
        document.getElementById('pmCode').textContent = o.code;
        document.getElementById('pmOrderId').value = o.id;
        document.getElementById('pmQtyTotal').textContent = o.qty;
        const inp = document.getElementById('pmQtyDone');
        inp.max = o.qty;
        inp.value = o.qty_done || 0;
        updatePbar();
        new bootstrap.Modal(document.getElementById('progressModal')).show();
    }

    function updatePbar() {
        const done = parseFloat(document.getElementById('pmQtyDone').value) || 0;
        const total = parseFloat(document.getElementById('pmQtyTotal').textContent) || 1;
        const pct = Math.min(100, Math.round(done / total * 100));
        document.getElementById('pmPct').textContent = pct + '%';
        document.getElementById('pmBar').style.width = pct + '%';
    }
</script>

<!-- ── PROGRESS MODAL ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="progressModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content" style="background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 18px;background:var(--card)">
                <div>
                    <div id="pmTitle" style="font-size:13px;font-weight:700;color:var(--text)"></div>
                    <div id="pmCode" style="font-size:11px;color:var(--muted)"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="_action" value="update_progress">
                <input type="hidden" name="order_id" id="pmOrderId">
                <div class="modal-body" style="padding:18px;background:var(--card)">
                    <div style="margin-bottom:16px">
                        <label style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:6px">Qty Done / Total</label>
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="number" name="qty_done" id="pmQtyDone" min="0" step="0.5"
                                style="width:90px;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:16px;font-weight:700;font-family:inherit;outline:none"
                                oninput="updatePbar()">
                            <span style="color:var(--muted);font-size:13px">/ <strong id="pmQtyTotal" style="color:var(--text)"></strong> pcs</span>
                        </div>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
                            <span style="font-size:11px;color:var(--muted)">Completion</span>
                            <span id="pmPct" style="font-size:14px;font-weight:700;color:var(--gold)">0%</span>
                        </div>
                        <div style="height:8px;background:var(--border);border-radius:20px;overflow:hidden">
                            <div id="pmBar" style="width:0%;height:100%;background:var(--gold);border-radius:20px;transition:width .3s"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;background:var(--card);gap:8px">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" style="background:var(--gold);color:#fff;border:none;font-weight:600">Save Progress</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php pwfOfficeFooter();
