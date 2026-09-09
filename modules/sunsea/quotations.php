<?php

/**
 * Sunsea - Penawaran Harga (Quotations)
 * List, Create, View, Edit penawaran ke customer
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$pdo    = getSunseaConnection();
sunseaEnsureMasterDataSchema($pdo);
sunseaEnsureAccommodationSchema($pdo);
sunseaEnsureQuotationItinerarySchema($pdo);
sunseaEnsurePackageItemsSchema($pdo);
$action = $_GET['action'] ?? 'list';
$qId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- HANDLE POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Save quotation (header + items)
    if ($postAction === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        if ($customerId <= 0 && $newCustomerName !== '') {
            $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
            $nextNum = 1;
            if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) $nextNum = (int)$m[1] + 1;
            $newCode = 'SS-CUST-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO customers (code, name, type, email, phone, whatsapp, country) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $newCode,
                    $newCustomerName,
                    'individual',
                    trim($_POST['new_customer_email'] ?? ''),
                    trim($_POST['new_customer_phone'] ?? ''),
                    trim($_POST['new_customer_phone'] ?? ''),
                    'Indonesia'
                ]);
            $customerId = (int)$pdo->lastInsertId();
        }
        $packageId  = (int)($_POST['package_id'] ?? 0) ?: null;
        $taxPct     = (float)($_POST['tax_pct'] ?? 11);
        $discount   = (float)str_replace(['.', ','], ['', '.'], $_POST['discount_amount'] ?? '0');
        $tripDate   = $_POST['trip_date']     ?: null;
        $tripEnd    = $_POST['trip_end_date'] ?: null;
        $itinerary  = trim($_POST['itinerary'] ?? '');
        $paxCount   = max(1, (int)($_POST['pax_count'] ?? 1));
        $notes      = trim($_POST['notes'] ?? '');
        $intNotes   = trim($_POST['internal_notes'] ?? '');
        $validDays  = (int)($_POST['valid_days'] ?? 7);
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        // Items from form
        $descriptions = $_POST['item_description'] ?? [];
        $itemTypes    = $_POST['item_type']        ?? [];
        $qtys         = $_POST['item_qty']         ?? [];
        $units        = $_POST['item_unit']        ?? [];
        $prices       = $_POST['item_price']       ?? [];

        // Calculate totals
        $subtotal = 0;
        $items    = [];
        foreach ($descriptions as $i => $desc) {
            $desc = trim($desc);
            if (empty($desc)) continue;
            $qty  = max(0, (float)$qtys[$i]);
            $price = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $sub  = $qty * $price;
            $subtotal += $sub;
            $items[] = [
                'item_type'   => $itemTypes[$i]  ?? 'other',
                'description' => $desc,
                'qty'         => $qty,
                'unit'        => trim($units[$i] ?? 'pax'),
                'unit_price'  => $price,
                'subtotal'    => $sub,
                'sort_order'  => $i,
            ];
        }

        $taxAmount = round($subtotal * $taxPct / 100, 2);
        $total     = $subtotal + $taxAmount - $discount;

        if (!$customerId) {
            $_SESSION['flash_message'] = 'Pilih customer terlebih dahulu.';
            $_SESSION['flash_type']    = 'error';
            header('Location: quotations.php?action=' . ($id > 0 ? "edit&id=$id" : 'add'));
            exit;
        }

        $currentUser = $auth->getCurrentUser();
        $createdBy   = $currentUser['username'] ?? 'system';

        if ($id > 0) {
            $pdo->prepare("
                UPDATE quotations SET customer_id=?, package_id=?, trip_date=?, trip_end_date=?,
                itinerary=?, pax_count=?, subtotal=?, tax_pct=?, tax_amount=?, discount_amount=?, total_amount=?,
                notes=?, internal_notes=?, valid_until=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $customerId,
                $packageId,
                $tripDate,
                $tripEnd,
                $itinerary,
                $paxCount,
                $subtotal,
                $taxPct,
                $taxAmount,
                $discount,
                $total,
                $notes,
                $intNotes,
                $validUntil,
                $id
            ]);
            // Replace items
            $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id=?")->execute([$id]);
        } else {
            $qNo = sunseaNextNumber($pdo, 'quotation');
            $pdo->prepare("
                INSERT INTO quotations 
                (quotation_no, customer_id, package_id, trip_date, trip_end_date, itinerary, pax_count,
                 subtotal, tax_pct, tax_amount, discount_amount, total_amount,
                 notes, internal_notes, valid_until, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $qNo,
                $customerId,
                $packageId,
                $tripDate,
                $tripEnd,
                $itinerary,
                $paxCount,
                $subtotal,
                $taxPct,
                $taxAmount,
                $discount,
                $total,
                $notes,
                $intNotes,
                $validUntil,
                $createdBy
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        // Insert items
        $insItem = $pdo->prepare("
            INSERT INTO quotation_items 
            (quotation_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($items as $item) {
            $insItem->execute([
                $id,
                $item['item_type'],
                $item['description'],
                $item['qty'],
                $item['unit'],
                $item['unit_price'],
                $item['subtotal'],
                $item['sort_order']
            ]);
        }

        $_SESSION['flash_message'] = 'Penawaran berhasil disimpan.';
        $_SESSION['flash_type']    = 'success';
        header('Location: quotations.php?action=view&id=' . $id);
        exit;

        // Change status (sent / approved / rejected / expired)
    } elseif ($postAction === 'setstatus') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $allowed = ['draft', 'sent', 'approved', 'rejected', 'expired'];
        if ($id > 0 && in_array($status, $allowed)) {
            $extra = $status === 'sent' ? ', sent_at=NOW()' : ($status === 'approved' ? ', approved_at=NOW()' : '');
            $pdo->prepare("UPDATE quotations SET status=? $extra WHERE id=?")->execute([$status, $id]);
        }
        header('Location: quotations.php?action=view&id=' . $id);
        exit;

        // Convert to invoice
    } elseif ($postAction === 'convert') {
        $qId2 = (int)($_POST['quotation_id'] ?? 0);
        $q    = $pdo->prepare("SELECT * FROM quotations WHERE id=? AND status='approved'");
        $q->execute([$qId2]);
        $quote = $q->fetch();

        if ($quote) {
            $invNo = sunseaNextNumber($pdo, 'invoice');
            $dueDate = date('Y-m-d', strtotime('+14 days'));
            $currentUser = $auth->getCurrentUser();

            $pdo->prepare("
                INSERT INTO invoices 
                (invoice_no, quotation_id, customer_id, trip_date, trip_end_date, pax_count,
                 subtotal, tax_pct, tax_amount, discount_amount, total_amount, remaining_amount,
                 notes, due_date, status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',?)
            ")->execute([
                $invNo,
                $qId2,
                $quote['customer_id'],
                $quote['trip_date'],
                $quote['trip_end_date'],
                $quote['pax_count'],
                $quote['subtotal'],
                $quote['tax_pct'],
                $quote['tax_amount'],
                $quote['discount_amount'],
                $quote['total_amount'],
                $quote['total_amount'],
                $quote['notes'],
                $dueDate,
                $currentUser['username'] ?? 'system'
            ]);
            $newInvId = (int)$pdo->lastInsertId();

            // Copy items from quotation to invoice
            $qItems = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id=?");
            $qItems->execute([$qId2]);
            $insInvItem = $pdo->prepare("
                INSERT INTO invoice_items (invoice_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            foreach ($qItems->fetchAll() as $qi) {
                $insInvItem->execute([
                    $newInvId,
                    $qi['item_type'],
                    $qi['description'],
                    $qi['qty'],
                    $qi['unit'],
                    $qi['unit_price'],
                    $qi['subtotal'],
                    $qi['sort_order']
                ]);
            }

            // Mark quotation as converted
            $pdo->prepare("UPDATE quotations SET status='converted', converted_invoice_id=? WHERE id=?")
                ->execute([$newInvId, $qId2]);

            $_SESSION['flash_message'] = 'Penawaran berhasil dikonversi ke Invoice ' . $invNo;
            $_SESSION['flash_type']    = 'success';
            header('Location: invoices.php?action=view&id=' . $newInvId);
            exit;
        }
    }
}

// ---- LOAD DATA ----
$quotation   = null;
$qItems      = [];
$qPackageItems = [];
if (in_array($action, ['view', 'edit', 'print']) && $qId > 0) {
    $s = $pdo->prepare("
        SELECT q.*, c.name as customer_name, c.phone as customer_phone,
               c.whatsapp as customer_whatsapp,
               c.email as customer_email, c.address as customer_address, c.city as customer_city,
               p.name as package_name, p.includes as package_includes
        FROM quotations q
        JOIN customers c ON c.id = q.customer_id
        LEFT JOIN trip_packages p ON p.id = q.package_id
        WHERE q.id = ?
    ");
    $s->execute([$qId]);
    $quotation = $s->fetch();
    if (!$quotation) {
        header('Location: quotations.php');
        exit;
    }

    $si = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY sort_order");
    $si->execute([$qId]);
    $qItems = $si->fetchAll();

    if (!empty($quotation['package_id'])) {
        $pi = $pdo->prepare("SELECT item_type, item_name, notes FROM trip_package_items WHERE package_id=? ORDER BY sort_order");
        $pi->execute([(int)$quotation['package_id']]);
        $qPackageItems = $pi->fetchAll();
    }
}

// Customers & packages for form
$customers = $pdo->query("SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
$packages  = $pdo->query("SELECT id, name, base_price, duration_days, duration_nights, itinerary FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();

// Master data untuk quick-add item penawaran (Tiket, Transportasi, Penginapan, Makanan, Fasilitas)
function qSafeAll(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}
$mdTickets    = qSafeAll($pdo, "SELECT id, ticket_name, ticket_type, price_sell, unit FROM tickets WHERE is_active=1 ORDER BY ticket_type, ticket_name");
$mdTransport  = qSafeAll($pdo, "SELECT id, name, transport_type, price_sell, unit FROM transport_items WHERE is_active=1 ORDER BY transport_type, name");
$mdRooms      = qSafeAll($pdo, "SELECT r.id, r.room_type, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type");
$mdCaterings  = qSafeAll($pdo, "SELECT id, menu_name, vendor_name, price_sell, portion_unit FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name");
$mdFacilities = qSafeAll($pdo, "SELECT id, name, price_sell, unit FROM facilities WHERE is_active=1 ORDER BY name");

// List
$filter = $_GET['status'] ?? '';
$whereClause = $filter ? "WHERE q.status=?" : "";
$listParams  = $filter ? [$filter] : [];

$quotations = $pdo->prepare("
    SELECT q.id, q.quotation_no, q.status, q.total_amount, q.trip_date, q.valid_until, q.created_at,
           q.created_by, c.name as customer_name, q.pax_count
    FROM quotations q
    JOIN customers c ON c.id = q.customer_id
    $whereClause
    ORDER BY q.created_at DESC
    LIMIT 100
");
$quotations->execute($listParams);
$quotations = $quotations->fetchAll();

$pageTitle  = match ($action) {
    'add'   => 'Buat Penawaran Baru',
    'edit'  => 'Edit Penawaran',
    'view'  => 'Detail Penawaran',
    'print' => 'Cetak Penawaran',
    default => 'Daftar Penawaran'
};
$activePage = 'quotations';

// ---- PRINT VIEW ----
if ($action === 'print' && $quotation):
    $companyName    = sunseaSetting($pdo, 'company_name', 'Explore Karimunjawa');
    $companyAddress = sunseaSetting($pdo, 'company_address', '');
    $companyPhone   = sunseaSetting($pdo, 'company_phone', '');
    $printLogoPath  = sunseaSetting($pdo, 'invoice_logo', '') ?: sunseaSetting($pdo, 'company_logo', '');
    $printLogoSrc   = sunseaAssetUrl($printLogoPath);
    $stampPath      = sunseaSetting($pdo, 'invoice_stamp', '');
    $stampSrc       = sunseaAssetUrl($stampPath);
    $bankName       = sunseaSetting($pdo, 'bank_name', '');
    $bankAccount    = sunseaSetting($pdo, 'bank_account', '');
    $bankHolder     = sunseaSetting($pdo, 'bank_holder', '');
    $footer         = sunseaSetting($pdo, 'invoice_footer', '');

    // Fasilitas yang didapat: pakai detail layanan paket terstruktur bila ada,
    // fallback ke teks bebas "includes" pada paket.
    $facilityLines = [];
    foreach ($qPackageItems as $pi) {
        $facilityLines[] = trim($pi['item_name']) . (!empty($pi['notes']) ? ' (' . trim($pi['notes']) . ')' : '');
    }
    if (empty($facilityLines) && !empty($quotation['package_includes'])) {
        foreach (preg_split('/\r\n|\r|\n/', $quotation['package_includes']) as $line) {
            $line = trim($line, " \t-•");
            if ($line !== '') $facilityLines[] = $line;
        }
    }
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>Penawaran <?php echo htmlspecialchars($quotation['quotation_no']); ?></title>
        <style>
            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 12.5px;
                padding: 32px 40px;
                color: #1e293b;
                background: #fff;
            }

            .accent-bar {
                height: 6px;
                border-radius: 4px;
                background: linear-gradient(90deg, #7C2D12, #C2410C 55%, #EA580C);
                margin-bottom: 22px;
            }

            .head {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding-bottom: 18px;
                border-bottom: 1px solid #E2E8F0;
            }

            .brand-logo {
                width: 64px;
                height: 64px;
                object-fit: contain;
                margin-right: 14px;
                border-radius: 6px;
            }

            .brand-name {
                font-size: 19px;
                font-weight: 800;
                margin: 0;
                color: #7C2D12;
                letter-spacing: .2px;
            }

            .brand-meta {
                font-size: 11px;
                color: #64748B;
                margin-top: 3px;
                line-height: 1.5;
                max-width: 320px;
            }

            .quo-tag {
                font-size: 24px;
                font-weight: 800;
                letter-spacing: 1.5px;
                color: #C2410C;
                margin: 0;
            }

            .quo-no {
                font-size: 12px;
                color: #64748B;
                margin-top: 4px;
                font-weight: 600;
            }

            .cust-row {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-top: 18px;
            }

            .cust-row .cust-name {
                font-size: 15px;
                font-weight: 700;
                color: #1e293b;
            }

            .cust-row .cust-label {
                font-size: 10px;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: .5px;
            }

            .meta-box {
                display: flex;
                justify-content: space-between;
                gap: 16px;
                background: #FFF7ED;
                border: 1px solid #FDE4CC;
                border-radius: 8px;
                padding: 14px 18px;
                margin-top: 18px;
            }

            .meta-box .item {
                font-size: 11px;
                color: #7C2D12;
            }

            .meta-box .item b {
                display: block;
                font-size: 12.5px;
                color: #1e293b;
                margin-top: 2px;
                font-weight: 700;
            }

            .section-title {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .5px;
                color: #7C2D12;
                margin: 20px 0 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 8px;
            }

            thead th {
                background: #7C2D12;
                color: #fff;
                font-size: 10.5px;
                text-transform: uppercase;
                letter-spacing: .4px;
                padding: 9px 10px;
                text-align: left;
            }

            thead th:nth-child(3),
            thead th:nth-child(4),
            thead th:nth-child(5),
            thead th:nth-child(6) {
                text-align: right;
            }

            tbody td {
                padding: 8px 10px;
                border-bottom: 1px solid #EEF2F7;
                font-size: 12px;
            }

            tbody td:nth-child(3),
            tbody td:nth-child(5),
            tbody td:nth-child(6) {
                text-align: right;
                white-space: nowrap;
            }

            tbody tr:nth-child(even) {
                background: #FAFBFC;
            }

            .facility-box {
                background: #F0FDF4;
                border: 1px solid #BBF7D0;
                border-radius: 8px;
                padding: 12px 16px;
                margin-top: 6px;
            }

            .facility-list {
                columns: 2;
                column-gap: 24px;
                margin: 0;
                padding-left: 18px;
                font-size: 11.5px;
                color: #15803D;
                line-height: 1.7;
            }

            .bottom-flex {
                display: flex;
                justify-content: space-between;
                gap: 24px;
                margin-top: 20px;
            }

            .bank-box {
                flex: 1;
                font-size: 11px;
                color: #475569;
            }

            .bank-card {
                background: #F8FAFC;
                border: 1px solid #E2E8F0;
                border-radius: 6px;
                padding: 8px 10px;
                margin-bottom: 6px;
            }

            .total {
                width: 280px;
            }

            .row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                font-size: 12px;
            }

            .row.final {
                font-size: 15px;
                font-weight: 800;
                color: #C2410C;
                border-top: 2px solid #C2410C;
                padding-top: 8px;
                margin-top: 4px;
            }

            .itinerary-box {
                font-size: 11.5px;
                color: #334155;
                line-height: 1.7;
            }

            .itinerary-day {
                font-weight: 700;
                color: #7C2D12;
                margin-top: 8px;
            }

            .signature-area {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }

            .notes-col {
                flex: 1;
                font-size: 11px;
                color: #475569;
            }

            .sign-col {
                width: 220px;
                text-align: center;
                font-size: 11.5px;
                position: relative;
            }

            .sign-place {
                margin-bottom: 46px;
            }

            .stamp-img {
                position: absolute;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                width: 90px;
                opacity: .9;
                mix-blend-mode: multiply;
            }

            .sign-line {
                border-top: 1px solid #94a3b8;
                margin-top: 4px;
                padding-top: 4px;
                font-weight: 700;
            }

            .footer-note {
                margin-top: 26px;
                padding-top: 12px;
                border-top: 1px solid #E2E8F0;
                font-size: 10.5px;
                color: #94a3b8;
                text-align: center;
                font-style: italic;
            }

            @media print {
                body {
                    padding: 15px;
                }
            }
        </style>
    </head>

    <body onload="window.print()">
        <div class="accent-bar"></div>
        <div class="head">
            <div style="display:flex;align-items:center;">
                <?php if ($printLogoSrc): ?><img class="brand-logo" src="<?php echo htmlspecialchars($printLogoSrc); ?>" alt="Logo"> <?php endif; ?>
                <div>
                    <p class="brand-name"><?php echo htmlspecialchars($companyName); ?></p>
                    <div class="brand-meta">
                        <?php echo htmlspecialchars($companyAddress); ?><?php echo ($companyAddress && $companyPhone) ? ' &middot; ' : ''; ?><?php echo htmlspecialchars($companyPhone); ?>
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                <p class="quo-tag"><?php echo htmlspecialchars($quotation['quotation_no']); ?></p>
                <div class="quo-no">SURAT PENAWARAN HARGA</div>
                <div class="quo-no">Tanggal: <?php echo date('d M Y', strtotime($quotation['created_at'])); ?></div>
                <div class="quo-no">Berlaku s/d: <?php echo $quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-'; ?></div>
            </div>
        </div>

        <div class="cust-row">
            <div>
                <div class="cust-label">Kepada Yth.</div>
                <div class="cust-name"><?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                <?php if ($quotation['customer_address'] || $quotation['customer_city']): ?>
                    <div style="font-size:11px;color:#64748B;"><?php echo htmlspecialchars(trim($quotation['customer_address'] . ' ' . $quotation['customer_city'])); ?></div>
                <?php endif; ?>
                <?php if ($quotation['customer_phone']): ?><div style="font-size:11px;color:#64748B;">📞 <?php echo htmlspecialchars($quotation['customer_phone']); ?></div><?php endif; ?>
            </div>
        </div>

        <div class="meta-box">
            <div class="item">Jumlah Peserta<b><?php echo (int)$quotation['pax_count']; ?> orang</b></div>
            <?php if ($quotation['package_name']): ?><div class="item">Paket<b><?php echo htmlspecialchars($quotation['package_name']); ?></b></div><?php endif; ?>
            <?php if ($quotation['trip_date']): ?>
                <div class="item">Tanggal Trip<b><?php echo date('d M Y', strtotime($quotation['trip_date'])); ?><?php echo $quotation['trip_end_date'] ? ' - ' . date('d M Y', strtotime($quotation['trip_end_date'])) : ''; ?></b></div>
            <?php endif; ?>
        </div>

        <div class="section-title">Rincian Penawaran</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Keterangan</th>
                    <th>Qty</th>
                    <th>Sat.</th>
                    <th>Harga Satuan</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($qItems as $i => $item): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td><?php echo $item['qty'] == intval($item['qty']) ? (int)$item['qty'] : $item['qty']; ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
                        <td style="font-weight:600;"><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($facilityLines)): ?>
            <div class="section-title">Fasilitas yang Didapat</div>
            <div class="facility-box">
                <ul class="facility-list">
                    <?php foreach ($facilityLines as $line): ?>
                        <li><?php echo htmlspecialchars($line); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="bottom-flex">
            <div class="bank-box">
                <?php if ($bankName || $bankAccount): ?>
                    <div class="bank-card"><b>Transfer ke:</b> <?php echo htmlspecialchars($bankName ?: '-'); ?> &mdash; <?php echo htmlspecialchars($bankAccount ?: '-'); ?> a.n. <?php echo htmlspecialchars($bankHolder ?: '-'); ?></div>
                <?php endif; ?>
                <?php if ($quotation['notes']): ?><div style="margin-top:6px;"><strong>Catatan:</strong> <?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></div><?php endif; ?>
            </div>
            <div class="total">
                <div class="row"><span>Subtotal</span><strong><?php echo sunseaRupiah((float)$quotation['subtotal']); ?></strong></div>
                <?php if ($quotation['discount_amount'] > 0): ?>
                    <div class="row"><span>Diskon</span><strong>-<?php echo sunseaRupiah((float)$quotation['discount_amount']); ?></strong></div>
                <?php endif; ?>
                <div class="row"><span>PPN <?php echo (float)$quotation['tax_pct']; ?>%</span><strong><?php echo sunseaRupiah((float)$quotation['tax_amount']); ?></strong></div>
                <div class="row final"><span>TOTAL</span><strong><?php echo sunseaRupiah((float)$quotation['total_amount']); ?></strong></div>
            </div>
        </div>

        <?php if (!empty($quotation['itinerary'])): ?>
            <div class="section-title">Itinerary (Jadwal Perjalanan)</div>
            <div class="itinerary-box">
                <?php foreach (preg_split('/\r\n|\r|\n/', trim($quotation['itinerary'])) as $line):
                    $line = trim($line);
                    if ($line === '') continue;
                    $isDayHeader = (bool)preg_match('/^\.?\s*Hari\s*\d+/i', $line);
                ?>
                    <div class="<?php echo $isDayHeader ? 'itinerary-day' : ''; ?>"><?php echo htmlspecialchars($line); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="signature-area">
            <div class="notes-col"></div>
            <div class="sign-col">
                <div class="sign-place"><?php echo date('d M Y'); ?></div>
                <div>Hormat kami,</div>
                <?php if ($stampSrc): ?><img class="stamp-img" src="<?php echo htmlspecialchars($stampSrc); ?>" alt="Stempel"><?php endif; ?>
                <div class="sign-line"><?php echo htmlspecialchars($companyName); ?></div>
            </div>
        </div>

        <?php if ($footer): ?><div class="footer-note"><?php echo nl2br(htmlspecialchars($footer)); ?></div><?php endif; ?>
    </body>

    </html>
<?php exit;
endif;

include 'layout-header.php';
?>

<?php if ($action === 'view' && $quotation): ?>
    <!-- ============ VIEW DETAIL ============ -->
    <?php
    $waPhone = $quotation['customer_whatsapp'] ?: $quotation['customer_phone'];
    $waMessage = "Halo {$quotation['customer_name']}, berikut penawaran perjalanan dari " . sunseaSetting($pdo, 'company_name', 'Explore Karimunjawa') . " nomor {$quotation['quotation_no']} sebesar " . sunseaRupiah((float)$quotation['total_amount']) . ". Berlaku sampai " . ($quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-') . ". Terima kasih.";
    $waLink = $waPhone ? sunseaWaLink($waPhone, $waMessage) : '';
    ?>
    <div style="margin-bottom:18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        <a href="quotations.php?action=print&id=<?php echo $quotation['id']; ?>" target="_blank"
            class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i> Cetak / PDF</a>
        <?php if ($waLink): ?>
            <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank"
                class="ss-btn ss-btn-sm" style="background:#25D366;color:#fff;border-color:#25D366;">
                <i data-feather="message-circle"></i> WA Customer
            </a>
        <?php endif; ?>
        <?php if ($quotation['status'] === 'draft'): ?>
            <a href="quotations.php?action=edit&id=<?php echo $quotation['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm">
                <i data-feather="edit-2"></i> Edit
            </a>
        <?php endif; ?>
        <!-- Status buttons -->
        <?php $statusBtns = ['draft' => 'Ubah ke Draft', 'sent' => 'Tandai Terkirim', 'approved' => 'Approve', 'rejected' => 'Tolak', 'expired' => 'Tandai Kadaluarsa']; ?>
        <?php foreach ($statusBtns as $st => $lbl): ?>
            <?php if ($st !== $quotation['status'] && !in_array($quotation['status'], ['converted', 'rejected']) && !($quotation['status'] === 'approved' && $st === 'sent')): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="setstatus">
                    <input type="hidden" name="id" value="<?php echo $quotation['id']; ?>">
                    <input type="hidden" name="status" value="<?php echo $st; ?>">
                    <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><?php echo $lbl; ?></button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($quotation['status'] === 'approved'): ?>
            <form method="POST" onsubmit="return confirm('Konversi ke Invoice?')">
                <input type="hidden" name="action" value="convert">
                <input type="hidden" name="quotation_id" value="<?php echo $quotation['id']; ?>">
                <button type="submit" class="ss-btn ss-btn-primary ss-btn-sm">
                    <i data-feather="arrow-right-circle"></i> Konversi ke Invoice
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 300px;gap:16px;">
        <div>
            <div class="ss-card" style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                    <div>
                        <div style="font-size:17px;font-weight:800;color:var(--ss-ocean);letter-spacing:.2px;"><?php echo htmlspecialchars($quotation['quotation_no']); ?></div>
                        <div style="font-size:12px;color:var(--ss-muted);">untuk <?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                    </div>
                    <span class="ss-status ss-status-<?php echo $quotation['status']; ?>" style="font-size:11px;padding:4px 10px;">
                        <?php echo ucfirst($quotation['status']); ?>
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;padding:12px 14px;background:var(--ss-sky);border-radius:8px;">
                    <div>
                        <div style="font-size:9px;color:var(--ss-muted);text-transform:uppercase;letter-spacing:.3px;">Tanggal Trip</div>
                        <div style="font-size:12.5px;font-weight:600;"><?php echo $quotation['trip_date'] ? date('d M Y', strtotime($quotation['trip_date'])) : '-'; ?></div>
                    </div>
                    <div>
                        <div style="font-size:9px;color:var(--ss-muted);text-transform:uppercase;letter-spacing:.3px;">Peserta</div>
                        <div style="font-size:12.5px;font-weight:600;"><?php echo $quotation['pax_count']; ?> orang</div>
                    </div>
                    <div>
                        <div style="font-size:9px;color:var(--ss-muted);text-transform:uppercase;letter-spacing:.3px;">Berlaku s/d</div>
                        <div style="font-size:12.5px;font-weight:600;"><?php echo $quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-'; ?></div>
                    </div>
                </div>

                <!-- Items table -->
                <div class="ss-table-wrap">
                    <table class="ss-table" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th style="font-size:10px;">#</th>
                                <th style="font-size:10px;">Keterangan</th>
                                <th style="font-size:10px;">Qty</th>
                                <th style="font-size:10px;">Satuan</th>
                                <th style="font-size:10px;">Harga</th>
                                <th style="font-size:10px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($qItems as $i => $item): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td><?php echo $item['qty'] == intval($item['qty']) ? (int)$item['qty'] : $item['qty']; ?></td>
                                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                    <td style="white-space:nowrap;"><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
                                    <td style="font-weight:600;white-space:nowrap;"><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div>
            <div class="ss-card" style="margin-bottom:14px;">
                <div class="ss-card-title" style="margin-bottom:12px;font-size:13px;">Ringkasan Harga</div>
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--ss-gray-2);font-size:12.5px;">
                    <span style="color:var(--ss-muted);">Subtotal</span>
                    <span style="font-weight:600;"><?php echo sunseaRupiah((float)$quotation['subtotal']); ?></span>
                </div>
                <?php if ($quotation['discount_amount'] > 0): ?>
                    <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--ss-gray-2);font-size:12.5px;">
                        <span style="color:var(--ss-muted);">Diskon</span>
                        <span style="color:var(--ss-success);font-weight:600;">- <?php echo sunseaRupiah((float)$quotation['discount_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--ss-gray-2);font-size:12.5px;">
                    <span style="color:var(--ss-muted);">PPN <?php echo $quotation['tax_pct']; ?>%</span>
                    <span style="font-weight:600;"><?php echo sunseaRupiah((float)$quotation['tax_amount']); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:baseline;padding:10px 0 0;">
                    <span style="font-size:12px;font-weight:700;color:var(--ss-ocean);text-transform:uppercase;letter-spacing:.3px;">Total</span>
                    <span style="font-size:19px;font-weight:800;color:var(--ss-ocean);"><?php echo sunseaRupiah((float)$quotation['total_amount']); ?></span>
                </div>
            </div>

            <?php if ($waPhone): ?>
                <div class="ss-card" style="margin-bottom:14px;">
                    <div class="ss-card-title" style="margin-bottom:8px;font-size:13px;">Kontak Customer</div>
                    <div style="font-size:12.5px;font-weight:600;"><?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                    <div style="font-size:12px;color:var(--ss-muted);margin-bottom:10px;"><?php echo htmlspecialchars($waPhone); ?></div>
                    <?php if ($waLink): ?>
                        <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank"
                            class="ss-btn ss-btn-sm" style="width:100%;justify-content:center;background:#25D366;color:#fff;border-color:#25D366;">
                            <i data-feather="message-circle"></i> Kirim via WhatsApp
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($quotation['notes']): ?>
                <div class="ss-card">
                    <div class="ss-card-title" style="margin-bottom:6px;font-size:13px;">Catatan</div>
                    <div style="font-size:12px;color:var(--ss-muted);"><?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif (in_array($action, ['add', 'edit'])): ?>
    <!-- ============ ADD/EDIT FORM ============ -->
    <div>
        <div style="margin-bottom:20px;">
            <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        </div>

        <form method="POST" id="quotationForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $quotation['id'] ?? 0; ?>">

            <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;">
                <div>
                    <!-- Header -->
                    <div class="ss-card" style="margin-bottom:16px;">
                        <div class="ss-card-title" style="margin-bottom:16px;">Informasi Penawaran</div>
                        <div class="ss-form-grid cols-2">
                            <div class="ss-form-group" style="grid-column:1/-1;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <label class="ss-label" style="margin:0;">Customer *</label>
                                    <a href="javascript:void(0)" onclick="toggleNewCustomer()" id="newCustomerToggleLink" style="font-size:11.5px;color:var(--ss-ocean);font-weight:600;text-decoration:none;">+ Tambah Customer Baru</a>
                                </div>
                                <select name="customer_id" id="customerSelect" class="ss-select" required>
                                    <option value="">-- Pilih Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo ($quotation['customer_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']); ?><?php echo $c['phone'] ? ' - ' . htmlspecialchars($c['phone']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="newCustomerBox" style="display:none;margin-top:8px;padding:10px 12px;background:var(--ss-sky);border:1px solid var(--ss-gray-2);border-radius:8px;">
                                    <div class="ss-form-grid cols-2">
                                        <div class="ss-form-group" style="grid-column:1/-1;margin:0;">
                                            <label class="ss-label">Nama Customer *</label>
                                            <input type="text" name="new_customer_name" id="newCustomerName" class="ss-input" placeholder="Nama lengkap customer">
                                        </div>
                                        <div class="ss-form-group" style="margin:0;">
                                            <label class="ss-label">No. HP / WA</label>
                                            <input type="text" name="new_customer_phone" class="ss-input" placeholder="08xxxxxxxxxx">
                                        </div>
                                        <div class="ss-form-group" style="margin:0;">
                                            <label class="ss-label">Email (opsional)</label>
                                            <input type="email" name="new_customer_email" class="ss-input" placeholder="email@contoh.com">
                                        </div>
                                    </div>
                                    <div style="font-size:10.5px;color:var(--ss-muted);margin-top:6px;">* Otomatis tersimpan ke database Pelanggan saat penawaran disimpan.</div>
                                </div>
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Paket (opsional)</label>
                                <select name="package_id" class="ss-select" id="pkgSelect" onchange="fillItineraryFromPackage(); applyPackageMode(); autoFillTripEndDate();">
                                    <option value="">-- Custom / Tidak pakai paket --</option>
                                    <?php foreach ($packages as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                                            data-price="<?php echo $p['base_price']; ?>"
                                            data-days="<?php echo $p['duration_days']; ?>"
                                            data-itinerary="<?php echo htmlspecialchars($p['itinerary'] ?? ''); ?>"
                                            <?php echo ($quotation['package_id'] ?? 0) == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?> — <?php echo sunseaRupiah((float)$p['base_price']); ?>/org
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Jumlah Peserta</label>
                                <input type="number" name="pax_count" class="ss-input" min="1"
                                    value="<?php echo $quotation['pax_count'] ?? 1; ?>" id="paxInput" oninput="syncPackageQtyFromPax()">
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Tanggal Trip</label>
                                <input type="date" name="trip_date" id="tripDateInput" class="ss-input"
                                    value="<?php echo $quotation['trip_date'] ?? ''; ?>" onchange="autoFillTripEndDate()">
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Tanggal Selesai</label>
                                <input type="date" name="trip_end_date" id="tripEndInput" class="ss-input"
                                    value="<?php echo $quotation['trip_end_date'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Info: paket dipilih, item otomatis dari harga paket x jumlah peserta -->
                    <div class="ss-card" id="packageModeNote" style="margin-bottom:16px;display:none;background:var(--ss-gray-1);">
                        <div style="font-size:13px;color:var(--ss-text);">
                            <strong>Mode Paket aktif.</strong> Tidak perlu input item satu-satu — subtotal otomatis dihitung dari harga paket × jumlah peserta. Ubah "Jumlah Peserta" di atas untuk update subtotal, atau pilih "Custom / Tidak pakai paket" untuk input item manual.
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="ss-card" id="itemsCard" style="margin-bottom:16px;">
                        <div class="ss-card-header">
                            <div class="ss-card-title">Item Penawaran</div>
                            <button type="button" onclick="addItem()" class="ss-btn ss-btn-outline ss-btn-sm">
                                <i data-feather="plus"></i> Tambah Baris Manual
                            </button>
                        </div>

                        <!-- Quick-add dari Database (urutan sesuai alur trip) -->
                        <div style="background:var(--ss-gray-1);border:1px solid var(--ss-gray-2);border-radius:8px;padding:12px;margin-bottom:14px;">
                            <div style="font-size:12px;font-weight:700;color:var(--ss-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.03em;">Tambah dari Database</div>

                            <?php
                            $quickAddGroups = [
                                ['id' => 'qaTicket', 'type' => 'other', 'label' => '1. Tiket &amp; Retribusi (kapal/ferry/tiket masuk destinasi/BTN)', 'options' => $mdTickets, 'nameField' => 'ticket_name', 'extra' => 'ticket_type', 'unitField' => 'unit'],
                                ['id' => 'qaTransport', 'type' => 'transport', 'label' => '2. Transportasi Karimunjawa (jemput/antar pelabuhan, trip darat/laut)', 'options' => $mdTransport, 'nameField' => 'name', 'extra' => 'transport_type', 'unitField' => 'unit'],
                                ['id' => 'qaRoom', 'type' => 'accommodation', 'label' => '3. Penginapan', 'options' => $mdRooms, 'nameField' => 'room_type', 'extra' => 'partner_name', 'unitField' => null],
                                ['id' => 'qaCatering', 'type' => 'meal', 'label' => '4. Makanan / Catering', 'options' => $mdCaterings, 'nameField' => 'menu_name', 'extra' => 'vendor_name', 'unitField' => 'portion_unit'],
                                ['id' => 'qaFacility', 'type' => 'equipment', 'label' => '5. Fasilitas Tambahan (open trip/private trip/dll)', 'options' => $mdFacilities, 'nameField' => 'name', 'extra' => null, 'unitField' => 'unit'],
                            ];
                            foreach ($quickAddGroups as $g):
                            ?>
                                <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:8px;flex-wrap:wrap;">
                                    <div style="flex:1;min-width:220px;">
                                        <label class="ss-label" style="font-size:11px;margin-bottom:3px;"><?php echo $g['label']; ?></label>
                                        <select class="ss-select" id="<?php echo $g['id']; ?>" style="font-size:12px;padding:6px 8px;">
                                            <option value="">-- Pilih dari database --</option>
                                            <?php foreach ($g['options'] as $o):
                                                $name  = $o[$g['nameField']];
                                                $extra = $g['extra'] ? ' — ' . $o[$g['extra']] : '';
                                                $unit  = $g['unitField'] ? ($o[$g['unitField']] ?: 'pax') : 'pax';
                                            ?>
                                                <option value="<?php echo $o['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($name . $extra); ?>"
                                                    data-price="<?php echo (float)$o['price_sell']; ?>"
                                                    data-unit="<?php echo htmlspecialchars($unit); ?>">
                                                    <?php echo htmlspecialchars($name . $extra); ?> (<?php echo sunseaRupiah((float)$o['price_sell']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div style="width:70px;">
                                        <label class="ss-label" style="font-size:11px;margin-bottom:3px;">Qty</label>
                                        <input type="number" class="ss-input" id="<?php echo $g['id']; ?>Qty" value="1" min="0" step="0.5" style="font-size:12px;padding:6px 8px;">
                                    </div>
                                    <button type="button" class="ss-btn ss-btn-primary ss-btn-sm" onclick="quickAddItem('<?php echo $g['id']; ?>','<?php echo $g['type']; ?>')">
                                        <i data-feather="plus"></i> Tambah
                                    </button>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($mdTickets) && empty($mdTransport) && empty($mdRooms) && empty($mdCaterings) && empty($mdFacilities)): ?>
                                <div style="font-size:12px;color:var(--ss-muted);">Belum ada data master. Isi dulu di menu <a href="database.php" style="color:var(--ss-ocean);">Database</a> (Tiket, Transportasi, Hotel/Homestay, Catering, Fasilitas).</div>
                            <?php endif; ?>
                        </div>

                        <div class="ss-table-wrap">
                            <table class="ss-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th style="width:130px;">Kategori</th>
                                        <th>Keterangan</th>
                                        <th style="width:60px;">Qty</th>
                                        <th style="width:60px;">Sat.</th>
                                        <th style="width:130px;">Harga</th>
                                        <th style="width:130px;">Subtotal</th>
                                        <th style="width:40px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <?php if (!empty($qItems)): ?>
                                        <?php foreach ($qItems as $item): ?>
                                            <tr><?php echo itemRowHtml($item['item_type'], $item['description'], $item['qty'], $item['unit'], $item['unit_price']); ?></tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><?php echo itemRowHtml(); ?></tr>
                                        <tr><?php echo itemRowHtml(); ?></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Sidebar: totals, notes -->
                <div>
                    <div class="ss-card" style="margin-bottom:16px;">
                        <div class="ss-card-title" style="margin-bottom:14px;">Kalkulasi</div>
                        <div class="ss-form-group">
                            <label class="ss-label">Diskon (Rp)</label>
                            <input type="text" name="discount_amount" class="ss-input" id="discountInput"
                                value="<?php echo number_format($quotation['discount_amount'] ?? 0, 0, ',', '.'); ?>"
                                placeholder="0">
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">PPN (%)</label>
                            <input type="number" name="tax_pct" class="ss-input" step="0.1"
                                value="<?php echo $quotation['tax_pct'] ?? 11; ?>" id="taxInput">
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">Berlaku (hari)</label>
                            <input type="number" name="valid_days" class="ss-input" min="1"
                                value="7" id="validDaysInput">
                        </div>
                        <hr style="border:none;border-top:1px solid var(--ss-gray-2);margin:12px 0;">
                        <div style="font-size:12px;color:var(--ss-muted);">Subtotal: <span id="calcSubtotal" style="float:right;font-weight:600;color:var(--ss-text);">Rp 0</span></div>
                        <div style="font-size:12px;color:var(--ss-muted);margin-top:4px;">Diskon: <span id="calcDiscount" style="float:right;color:var(--ss-success);font-weight:600;">-Rp 0</span></div>
                        <div style="font-size:12px;color:var(--ss-muted);margin-top:4px;">PPN: <span id="calcTax" style="float:right;font-weight:600;color:var(--ss-text);">Rp 0</span></div>
                        <div style="margin-top:10px;padding-top:10px;border-top:2px solid var(--ss-ocean);display:flex;justify-content:space-between;font-size:16px;font-weight:800;color:var(--ss-ocean);">
                            <span>TOTAL</span><span id="calcTotal">Rp 0</span>
                        </div>
                    </div>

                    <div class="ss-card" style="margin-bottom:16px;">
                        <div class="ss-card-header">
                            <label class="ss-label" style="margin:0;">Itinerary (Jadwal Perjalanan)</label>
                            <button type="button" onclick="fillItineraryFromPackage(true)" class="ss-btn ss-btn-outline ss-btn-sm">
                                <i data-feather="refresh-cw"></i> Isi dari Paket
                            </button>
                        </div>
                        <textarea name="itinerary" id="itineraryInput" class="ss-textarea" rows="6" placeholder="Hari 1: ...&#10;Hari 2: ..."><?php echo htmlspecialchars($quotation['itinerary'] ?? ''); ?></textarea>
                        <div style="font-size:11px;color:var(--ss-muted);margin-top:4px;">* Otomatis terisi dari itinerary paket yang dipilih (bisa diedit manual, atau ketik sendiri jika custom).</div>
                    </div>
                </div>
            </div>

            <div class="ss-card" style="margin-bottom:16px;">
                <div class="ss-card-title" style="margin-bottom:14px;">Catatan</div>
                <div class="ss-form-grid cols-2">
                    <div class="ss-form-group">
                        <label class="ss-label">Catatan (tampil di dokumen)</label>
                        <textarea name="notes" class="ss-textarea" rows="4"><?php echo htmlspecialchars($quotation['notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="ss-form-group">
                        <label class="ss-label">Catatan Internal</label>
                        <textarea name="internal_notes" class="ss-textarea" rows="4"><?php echo htmlspecialchars($quotation['internal_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" class="ss-btn ss-btn-primary" style="width:100%;">
                <i data-feather="save"></i> Simpan Penawaran
            </button>
        </form>
    </div>

<?php else: ?>
    <!-- ============ LIST ============ -->
    <div class="ss-card">
        <div class="ss-card-header">
            <div>
                <div class="ss-card-title">Daftar Penawaran</div>
                <div class="ss-card-sub"><?php echo count($quotations); ?> penawaran</div>
            </div>
            <a href="quotations.php?action=add" class="ss-btn ss-btn-primary">
                <i data-feather="plus"></i> Buat Penawaran
            </a>
        </div>

        <!-- Filter status -->
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'sent' => 'Terkirim', 'approved' => 'Approved', 'rejected' => 'Ditolak', 'converted' => 'Converted'] as $st => $lbl): ?>
                <a href="quotations.php?status=<?php echo $st; ?>"
                    class="ss-btn ss-btn-sm <?php echo $filter === $st ? 'ss-btn-primary' : 'ss-btn-outline'; ?>">
                    <?php echo $lbl; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($quotations)): ?>
            <div class="ss-empty">
                <div class="ss-empty-icon">📋</div>
                <h3>Belum ada penawaran</h3>
                <p>Buat penawaran untuk customer Anda</p>
            </div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>No. Penawaran</th>
                            <th>Customer</th>
                            <th>Sumber</th>
                            <th>Tgl Trip</th>
                            <th>Pax</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotations as $q): ?>
                            <tr>
                                <td><a href="quotations.php?action=view&id=<?php echo $q['id']; ?>"
                                        style="color:var(--ss-ocean);font-weight:600;text-decoration:none;">
                                        <?php echo htmlspecialchars($q['quotation_no']); ?>
                                    </a></td>
                                <td><?php echo htmlspecialchars($q['customer_name']); ?></td>
                                <td>
                                    <?php if (($q['created_by'] ?? '') === 'website'): ?>
                                        <span class="ss-status" style="background:#e0e7ff;color:#3730a3;">🌐 Web</span>
                                    <?php else: ?>
                                        <span class="ss-status" style="background:#f1f5f9;color:#475569;">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $q['trip_date'] ? date('d M Y', strtotime($q['trip_date'])) : '-'; ?></td>
                                <td><?php echo $q['pax_count']; ?></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$q['total_amount']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span></td>
                                <td>
                                    <a href="quotations.php?action=view&id=<?php echo $q['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm">
                                        <i data-feather="eye"></i>
                                    </a>
                                    <a href="quotations.php?action=edit&id=<?php echo $q['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm">
                                        <i data-feather="edit-2"></i>
                                    </a>
                                    <a href="quotations.php?action=print&id=<?php echo $q['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm">
                                        <i data-feather="printer"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// Helper: render a single item row HTML
function itemRowHtml($type = '', $desc = '', $qty = 1, $unit = 'pax', $price = 0): string
{
    $typeOpts = ['accommodation', 'transport', 'meal', 'activity', 'guide', 'equipment', 'other'];
    $typeLabels = ['Penginapan', 'Transport', 'Makan', 'Aktivitas', 'Guide', 'Perlengkapan', 'Lainnya'];
    $sel = '';
    foreach ($typeOpts as $i => $t) {
        $s = $type === $t ? ' selected' : '';
        $sel .= "<option value=\"$t\"$s>{$typeLabels[$i]}</option>";
    }
    $priceNum = number_format((float)$price, 0, ',', '.');
    return <<<HTML
        <td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">$sel</select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="$desc" placeholder="Keterangan..."></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty" style="font-size:12px;padding:6px 8px;" value="$qty" min="0" step="0.5"></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="$unit"></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price" style="font-size:12px;padding:6px 8px;" value="$priceNum" placeholder="0"></td>
        <td><input type="text" class="ss-input item-sub" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td><button type="button" onclick="removeRow(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>
HTML;
}
?>

<script>
    function toggleNewCustomer() {
        var box = document.getElementById('newCustomerBox');
        var select = document.getElementById('customerSelect');
        var link = document.getElementById('newCustomerToggleLink');
        var showing = box.style.display !== 'none';
        if (showing) {
            box.style.display = 'none';
            select.required = true;
            select.disabled = false;
            link.textContent = '+ Tambah Customer Baru';
        } else {
            box.style.display = 'block';
            select.value = '';
            select.required = false;
            select.disabled = true;
            link.textContent = '← Pilih dari Daftar Customer';
        }
    }

    function fillItineraryFromPackage(forceOverwrite) {
        var sel = document.getElementById('pkgSelect');
        var box = document.getElementById('itineraryInput');
        if (!sel || !box) return;
        var opt = sel.options[sel.selectedIndex];
        var itinerary = opt ? (opt.getAttribute('data-itinerary') || '') : '';
        if (!itinerary) return;
        // Auto-fill on package change only if the field is still empty,
        // so it never silently overwrites text the user already typed.
        if (forceOverwrite || !box.value.trim()) {
            box.value = itinerary;
        }
    }

    // Package duration (e.g. "3H2M" -> data-days=3) implies the trip end date, so
    // fill/recompute it whenever the package or start date changes.
    function autoFillTripEndDate() {
        var sel = document.getElementById('pkgSelect');
        var startInput = document.getElementById('tripDateInput');
        var endInput = document.getElementById('tripEndInput');
        if (!sel || !startInput || !endInput || !startInput.value) return;
        var opt = sel.options[sel.selectedIndex];
        var days = opt ? parseInt(opt.getAttribute('data-days') || '0', 10) : 0;
        if (!days || days < 1) return;
        var start = new Date(startInput.value + 'T00:00:00');
        start.setDate(start.getDate() + (days - 1));
        endInput.value = start.toISOString().slice(0, 10);
    }

    function packageRowHtml(name, qty, price) {
        return `<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;"><option value="other" selected>Paket</option></select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="${name.replace(/"/g, '&quot;')}" readonly></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty" style="font-size:12px;padding:6px 8px;" value="${qty}" min="1" step="1" readonly></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="org" readonly></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price" style="font-size:12px;padding:6px 8px;" value="${Math.round(price).toLocaleString('id-ID')}" readonly></td>
        <td><input type="text" class="ss-input item-sub" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td></td>`;
    }

    // Called when the package dropdown changes: switches between "package mode"
    // (single auto row, no manual input needed) and "custom mode" (manual items).
    function applyPackageMode() {
        var sel = document.getElementById('pkgSelect');
        var itemsCard = document.getElementById('itemsCard');
        var note = document.getElementById('packageModeNote');
        var tbody = document.getElementById('itemsBody');
        if (!sel || !itemsCard || !tbody) return;
        var opt = sel.options[sel.selectedIndex];

        if (sel.value) {
            itemsCard.style.display = 'none';
            if (note) note.style.display = '';
            var price = parseFloat(opt.getAttribute('data-price')) || 0;
            var name = opt.getAttribute('data-name') || '';
            var qty = parseFloat(document.getElementById('paxInput')?.value) || 1;
            tbody.innerHTML = '';
            var tr = document.createElement('tr');
            tr.setAttribute('data-pkg-row', '1');
            tr.innerHTML = packageRowHtml(name, qty, price);
            tbody.appendChild(tr);
            setupRowListeners(tr);
        } else {
            itemsCard.style.display = '';
            if (note) note.style.display = 'none';
            tbody.querySelectorAll('tr[data-pkg-row]').forEach(function(r) {
                r.remove();
            });
            if (!tbody.querySelector('tr')) {
                addItem();
                addItem();
            }
        }
        calcTotals();
    }

    // Keeps the auto package row's qty synced with "Jumlah Peserta" without
    // touching anything else (so it never disturbs manual custom items).
    function syncPackageQtyFromPax() {
        var tbody = document.getElementById('itemsBody');
        var pkgRow = tbody ? tbody.querySelector('tr[data-pkg-row]') : null;
        if (!pkgRow) return;
        var qty = parseFloat(document.getElementById('paxInput')?.value) || 1;
        var qtyField = pkgRow.querySelector('.item-qty');
        if (qtyField) qtyField.value = qty;
        calcTotals();
    }

    function fmt(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function unFmt(s) {
        return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
    }

    function quickAddItem(selectId, itemType) {
        var sel = document.getElementById(selectId);
        var opt = sel.options[sel.selectedIndex];
        if (!sel.value) {
            alert('Pilih item dari database terlebih dahulu.');
            return;
        }
        var qtyInput = document.getElementById(selectId + 'Qty');
        var qty = parseFloat(qtyInput?.value) || 1;
        var name = opt.getAttribute('data-name') || '';
        var price = parseFloat(opt.getAttribute('data-price')) || 0;
        var unit = opt.getAttribute('data-unit') || 'pax';

        var typeOpts = [
            ['accommodation', 'Penginapan'],
            ['transport', 'Transport'],
            ['meal', 'Makan'],
            ['activity', 'Aktivitas'],
            ['guide', 'Guide'],
            ['equipment', 'Perlengkapan'],
            ['other', 'Lainnya']
        ];
        var selHtml = typeOpts.map(function(t) {
            return '<option value="' + t[0] + '"' + (t[0] === itemType ? ' selected' : '') + '>' + t[1] + '</option>';
        }).join('');

        var tbody = document.getElementById('itemsBody');
        var tr = document.createElement('tr');
        tr.innerHTML = `<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">${selHtml}</select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="${name.replace(/"/g, '&quot;')}"></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty" style="font-size:12px;padding:6px 8px;" value="${qty}" min="0" step="0.5"></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="${unit}"></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price" style="font-size:12px;padding:6px 8px;" value="${Math.round(price).toLocaleString('id-ID')}"></td>
        <td><input type="text" class="ss-input item-sub" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td><button type="button" onclick="removeRow(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>`;
        tbody.appendChild(tr);
        feather.replace();
        setupRowListeners(tr);
        calcTotals();

        sel.value = '';
        if (qtyInput) qtyInput.value = 1;
    }

    function calcTotals() {
        var sub = 0;
        document.querySelectorAll('#itemsBody tr').forEach(function(row) {
            var q = parseFloat(row.querySelector('.item-qty')?.value) || 0;
            var p = unFmt(row.querySelector('.item-price')?.value || '0');
            var s = q * p;
            var sf = row.querySelector('.item-sub');
            if (sf) sf.value = s ? Math.round(s).toLocaleString('id-ID') : '';
            sub += s;
        });
        var disc = unFmt(document.getElementById('discountInput')?.value || '0');
        var taxP = parseFloat(document.getElementById('taxInput')?.value) || 0;
        var tax = (sub - disc) * taxP / 100;
        var tot = sub + tax - disc;
        document.getElementById('calcSubtotal').textContent = fmt(sub);
        document.getElementById('calcDiscount').textContent = '-' + fmt(disc);
        document.getElementById('calcTax').textContent = fmt(tax);
        document.getElementById('calcTotal').textContent = fmt(tot);
    }

    function addItem() {
        var tbody = document.getElementById('itemsBody');
        var tr = document.createElement('tr');
        tr.innerHTML = `<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">
        <option value="accommodation">Penginapan</option><option value="transport">Transport</option>
        <option value="meal">Makan</option><option value="activity">Aktivitas</option>
        <option value="guide">Guide</option><option value="equipment">Perlengkapan</option>
        <option value="other" selected>Lainnya</option></select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" placeholder="Keterangan..."></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty" style="font-size:12px;padding:6px 8px;" value="1" min="0" step="0.5"></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="pax"></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price" style="font-size:12px;padding:6px 8px;" placeholder="0"></td>
        <td><input type="text" class="ss-input item-sub" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td><button type="button" onclick="removeRow(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>`;
        tbody.appendChild(tr);
        feather.replace();
        setupRowListeners(tr);
    }

    function removeRow(btn) {
        btn.closest('tr').remove();
        calcTotals();
    }

    function setupRowListeners(row) {
        row.querySelectorAll('.item-qty, .item-price').forEach(function(inp) {
            inp.addEventListener('input', calcTotals);
        });
    }

    document.querySelectorAll('#itemsBody tr').forEach(setupRowListeners);
    document.getElementById('discountInput')?.addEventListener('input', calcTotals);
    document.getElementById('taxInput')?.addEventListener('input', calcTotals);

    // Only auto-switch to package mode on page load when there are no
    // already-saved items, so editing an existing custom quotation is untouched.
    var hasSavedItems = <?php echo (!empty($qItems)) ? 'true' : 'false'; ?>;
    if (!hasSavedItems && document.getElementById('pkgSelect')?.value) {
        applyPackageMode();
    } else {
        calcTotals();
    }
</script>

<?php include 'layout-footer.php'; ?>