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
$action = $_GET['action'] ?? 'list';
$qId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- HANDLE POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Save quotation (header + items)
    if ($postAction === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        $customerId = (int)($_POST['customer_id'] ?? 0);
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
if (in_array($action, ['view', 'edit', 'print']) && $qId > 0) {
    $s = $pdo->prepare("
        SELECT q.*, c.name as customer_name, c.phone as customer_phone,
               c.email as customer_email, c.address as customer_address, c.city as customer_city,
               p.name as package_name
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
           c.name as customer_name, q.pax_count
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
    $bankName       = sunseaSetting($pdo, 'bank_name', '');
    $bankAccount    = sunseaSetting($pdo, 'bank_account', '');
    $bankHolder     = sunseaSetting($pdo, 'bank_holder', '');
    $footer         = sunseaSetting($pdo, 'invoice_footer', '');
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>Penawaran <?php echo htmlspecialchars($quotation['quotation_no']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', sans-serif;
                font-size: 12px;
                color: #0F172A;
                padding: 30px 40px;
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 28px;
                border-bottom: 2px solid #C2410C;
                padding-bottom: 16px;
            }

            .brand {
                font-size: 24px;
                font-weight: 800;
                color: #7C2D12;
            }

            .brand-sub {
                font-size: 11px;
                color: #64748B;
            }

            .doc-info {
                text-align: right;
            }

            .doc-no {
                font-size: 18px;
                font-weight: 800;
                color: #C2410C;
            }

            .section-title {
                font-size: 10px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #64748B;
                margin-bottom: 6px;
            }

            .to-from {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 24px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 16px;
            }

            th {
                background: #FFF7ED;
                padding: 8px 10px;
                text-align: left;
                font-size: 11px;
                color: #64748B;
                font-weight: 700;
            }

            td {
                padding: 8px 10px;
                border-bottom: 1px solid #E2E8F0;
            }

            .total-box {
                float: right;
                width: 280px;
            }

            .total-row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0;
                font-size: 12px;
            }

            .total-row.final {
                font-size: 14px;
                font-weight: 800;
                color: #C2410C;
                border-top: 2px solid #C2410C;
                padding-top: 8px;
                margin-top: 4px;
            }

            .footer {
                margin-top: 32px;
                padding-top: 12px;
                border-top: 1px solid #E2E8F0;
                font-size: 11px;
                color: #64748B;
                text-align: center;
            }

            .bank-info {
                background: #FFF7ED;
                padding: 12px;
                border-radius: 8px;
                margin-top: 20px;
            }

            @media print {
                body {
                    padding: 15px;
                }
            }
        </style>
    </head>

    <body onload="window.print()">
        <div class="header">
            <div>
                <div class="brand">🌊 <?php echo htmlspecialchars($companyName); ?></div>
                <div class="brand-sub">Travel Bureau</div>
                <?php if ($companyAddress): ?><div style="margin-top:4px;color:#64748B;font-size:11px;"><?php echo nl2br(htmlspecialchars($companyAddress)); ?></div><?php endif; ?>
                <?php if ($companyPhone): ?><div style="color:#64748B;font-size:11px;">📞 <?php echo htmlspecialchars($companyPhone); ?></div><?php endif; ?>
            </div>
            <div class="doc-info">
                <div class="doc-no"><?php echo htmlspecialchars($quotation['quotation_no']); ?></div>
                <div>SURAT PENAWARAN HARGA</div>
                <div style="color:#64748B;">Tanggal: <?php echo date('d/m/Y', strtotime($quotation['created_at'])); ?></div>
                <div style="color:#64748B;">Berlaku s/d: <?php echo $quotation['valid_until'] ? date('d/m/Y', strtotime($quotation['valid_until'])) : '-'; ?></div>
            </div>
        </div>

        <div class="to-from">
            <div>
                <div class="section-title">Kepada Yth.</div>
                <strong><?php echo htmlspecialchars($quotation['customer_name']); ?></strong>
                <?php if ($quotation['customer_address']): ?><div><?php echo htmlspecialchars($quotation['customer_address']); ?></div><?php endif; ?>
                <?php if ($quotation['customer_city']): ?><div><?php echo htmlspecialchars($quotation['customer_city']); ?></div><?php endif; ?>
                <?php if ($quotation['customer_phone']): ?><div>📞 <?php echo htmlspecialchars($quotation['customer_phone']); ?></div><?php endif; ?>
            </div>
            <div>
                <div class="section-title">Info Perjalanan</div>
                <?php if ($quotation['trip_date']): ?><div>Tanggal: <strong><?php echo date('d/m/Y', strtotime($quotation['trip_date'])); ?><?php echo $quotation['trip_end_date'] ? ' - ' . date('d/m/Y', strtotime($quotation['trip_end_date'])) : ''; ?></strong></div><?php endif; ?>
                <div>Jumlah Peserta: <strong><?php echo $quotation['pax_count']; ?> orang</strong></div>
                <?php if ($quotation['package_name']): ?><div>Paket: <strong><?php echo htmlspecialchars($quotation['package_name']); ?></strong></div><?php endif; ?>
            </div>
        </div>

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
                        <td style="text-align:right;"><?php echo $item['qty'] == intval($item['qty']) ? (int)$item['qty'] : $item['qty']; ?></td>
                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                        <td style="text-align:right;"><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
                        <td style="text-align:right;font-weight:600;"><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total-box">
            <div class="total-row"><span>Subtotal</span><span><?php echo sunseaRupiah((float)$quotation['subtotal']); ?></span></div>
            <?php if ($quotation['discount_amount'] > 0): ?>
                <div class="total-row"><span>Diskon</span><span>- <?php echo sunseaRupiah((float)$quotation['discount_amount']); ?></span></div>
            <?php endif; ?>
            <div class="total-row"><span>PPN <?php echo $quotation['tax_pct']; ?>%</span><span><?php echo sunseaRupiah((float)$quotation['tax_amount']); ?></span></div>
            <div class="total-row final"><span>TOTAL</span><span><?php echo sunseaRupiah((float)$quotation['total_amount']); ?></span></div>
        </div>
        <div style="clear:both;"></div>

        <?php if ($bankName || $bankAccount): ?>
            <div class="bank-info">
                <strong>Informasi Pembayaran</strong><br>
                Bank: <?php echo htmlspecialchars($bankName); ?><br>
                No. Rekening: <?php echo htmlspecialchars($bankAccount); ?><br>
                Atas Nama: <?php echo htmlspecialchars($bankHolder); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($quotation['itinerary'])): ?>
            <div style="margin-top:16px;"><strong>Itinerary (Jadwal Perjalanan):</strong><br><?php echo nl2br(htmlspecialchars($quotation['itinerary'])); ?></div>
        <?php endif; ?>

        <?php if ($quotation['notes']): ?>
            <div style="margin-top:16px;"><strong>Catatan:</strong><br><?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></div>
        <?php endif; ?>

        <div class="footer"><?php echo htmlspecialchars($footer); ?></div>
    </body>

    </html>
<?php exit;
endif;

include 'layout-header.php';
?>

<?php if ($action === 'view' && $quotation): ?>
    <!-- ============ VIEW DETAIL ============ -->
    <div style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        <a href="quotations.php?action=print&id=<?php echo $quotation['id']; ?>" target="_blank"
            class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i> Cetak / PDF</a>
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

    <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;">
        <div>
            <div class="ss-card" style="margin-bottom:20px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
                    <div>
                        <div style="font-size:22px;font-weight:800;color:var(--ss-ocean);"><?php echo htmlspecialchars($quotation['quotation_no']); ?></div>
                        <div style="font-size:13px;color:var(--ss-muted);">untuk <?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                    </div>
                    <span class="ss-status ss-status-<?php echo $quotation['status']; ?>" style="font-size:13px;padding:5px 12px;">
                        <?php echo ucfirst($quotation['status']); ?>
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px;padding:16px;background:var(--ss-sky);border-radius:8px;">
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Tanggal Trip</div>
                        <div style="font-weight:600;"><?php echo $quotation['trip_date'] ? date('d M Y', strtotime($quotation['trip_date'])) : '-'; ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Peserta</div>
                        <div style="font-weight:600;"><?php echo $quotation['pax_count']; ?> orang</div>
                    </div>
                    <div>
                        <div style="font-size:10px;color:var(--ss-muted);text-transform:uppercase;">Berlaku s/d</div>
                        <div style="font-weight:600;"><?php echo $quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-'; ?></div>
                    </div>
                </div>

                <!-- Items table -->
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Keterangan</th>
                                <th>Qty</th>
                                <th>Satuan</th>
                                <th>Harga</th>
                                <th>Subtotal</th>
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
                </div>
            </div>
        </div>

        <div>
            <div class="ss-card" style="margin-bottom:16px;">
                <div class="ss-card-title" style="margin-bottom:14px;">Ringkasan Harga</div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ss-gray-2);">
                    <span style="color:var(--ss-muted);">Subtotal</span>
                    <span style="font-weight:600;"><?php echo sunseaRupiah((float)$quotation['subtotal']); ?></span>
                </div>
                <?php if ($quotation['discount_amount'] > 0): ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ss-gray-2);">
                        <span style="color:var(--ss-muted);">Diskon</span>
                        <span style="color:var(--ss-success);font-weight:600;">- <?php echo sunseaRupiah((float)$quotation['discount_amount']); ?></span>
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--ss-gray-2);">
                    <span style="color:var(--ss-muted);">PPN <?php echo $quotation['tax_pct']; ?>%</span>
                    <span style="font-weight:600;"><?php echo sunseaRupiah((float)$quotation['tax_amount']); ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:12px 0 0;font-size:18px;font-weight:800;color:var(--ss-ocean);">
                    <span>TOTAL</span>
                    <span><?php echo sunseaRupiah((float)$quotation['total_amount']); ?></span>
                </div>
            </div>

            <?php if ($quotation['notes']): ?>
                <div class="ss-card">
                    <div class="ss-card-title" style="margin-bottom:8px;">Catatan</div>
                    <div style="font-size:13px;color:var(--ss-muted);"><?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif (in_array($action, ['add', 'edit'])): ?>
    <!-- ============ ADD/EDIT FORM ============ -->
    <div style="max-width:900px;">
        <div style="margin-bottom:20px;">
            <a href="quotations.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        </div>

        <form method="POST" id="quotationForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $quotation['id'] ?? 0; ?>">

            <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;">
                <div>
                    <!-- Header -->
                    <div class="ss-card" style="margin-bottom:16px;">
                        <div class="ss-card-title" style="margin-bottom:16px;">Informasi Penawaran</div>
                        <div class="ss-form-grid cols-2">
                            <div class="ss-form-group" style="grid-column:1/-1;">
                                <label class="ss-label">Customer *</label>
                                <select name="customer_id" class="ss-select" required>
                                    <option value="">-- Pilih Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo ($quotation['customer_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['name']); ?><?php echo $c['phone'] ? ' - ' . htmlspecialchars($c['phone']) : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Paket (opsional)</label>
                                <select name="package_id" class="ss-select" id="pkgSelect" onchange="fillItineraryFromPackage()">
                                    <option value="">-- Custom / Tidak pakai paket --</option>
                                    <?php foreach ($packages as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"
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
                                    value="<?php echo $quotation['pax_count'] ?? 1; ?>" id="paxInput">
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Tanggal Trip</label>
                                <input type="date" name="trip_date" class="ss-input"
                                    value="<?php echo $quotation['trip_date'] ?? ''; ?>">
                            </div>
                            <div class="ss-form-group">
                                <label class="ss-label">Tanggal Selesai</label>
                                <input type="date" name="trip_end_date" class="ss-input"
                                    value="<?php echo $quotation['trip_end_date'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="ss-card" style="margin-bottom:16px;">
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

                    <div class="ss-card" style="margin-bottom:16px;">
                        <div class="ss-form-group">
                            <label class="ss-label">Catatan (tampil di dokumen)</label>
                            <textarea name="notes" class="ss-textarea" rows="4"><?php echo htmlspecialchars($quotation['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="ss-form-group">
                            <label class="ss-label">Catatan Internal</label>
                            <textarea name="internal_notes" class="ss-textarea" rows="3"><?php echo htmlspecialchars($quotation['internal_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="ss-btn ss-btn-primary" style="width:100%;">
                        <i data-feather="save"></i> Simpan Penawaran
                    </button>
                </div>
            </div>
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
                                <td><?php echo $q['trip_date'] ? date('d M Y', strtotime($q['trip_date'])) : '-'; ?></td>
                                <td><?php echo $q['pax_count']; ?></td>
                                <td style="font-weight:600;"><?php echo sunseaRupiah((float)$q['total_amount']); ?></td>
                                <td><span class="ss-status ss-status-<?php echo $q['status']; ?>"><?php echo ucfirst($q['status']); ?></span></td>
                                <td>
                                    <a href="quotations.php?action=view&id=<?php echo $q['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm">
                                        <i data-feather="eye"></i>
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
    calcTotals();
</script>

<?php include 'layout-footer.php'; ?>