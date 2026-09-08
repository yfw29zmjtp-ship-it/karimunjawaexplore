<?php

/**
 * Sunsea - Invoice Management
 * List, View, Add payment, Print invoice
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
$action = $_GET['action'] ?? 'list';
$invId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ---- HANDLE POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // Add manual payment
    if ($postAction === 'add_payment') {
        $iId    = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $method = $_POST['method'] ?? 'transfer';
        $date   = $_POST['payment_date'] ?: date('Y-m-d');
        $ref    = trim($_POST['reference'] ?? '');
        $notes  = trim($_POST['notes'] ?? '');
        $user   = $auth->getCurrentUser()['username'] ?? 'system';

        if ($iId > 0 && $amount > 0) {
            $pdo->prepare("
                INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$iId, $date, $amount, $method, $ref, $notes, $user]);

            // Recalculate paid & remaining
            $totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?");
            $totalPaid->execute([$iId]);
            $paid = (float)$totalPaid->fetchColumn();

            $inv = $pdo->prepare("SELECT total_amount FROM invoices WHERE id=?");
            $inv->execute([$iId]);
            $total = (float)$inv->fetchColumn();
            $remaining = max(0, $total - $paid);

            $newStatus = $remaining <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'issued');
            $paidAt = $remaining <= 0 ? ', paid_at=NOW()' : '';
            $pdo->prepare("UPDATE invoices SET paid_amount=?, remaining_amount=?, status=? $paidAt WHERE id=?")
                ->execute([$paid, $remaining, $newStatus, $iId]);

            // Add to cashbook automatically
            $custRow = $pdo->prepare("SELECT c.name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?");
            $custRow->execute([$iId]);
            $custName = $custRow->fetchColumn();
            $invRow = $pdo->prepare("SELECT invoice_no FROM invoices WHERE id=?");
            $invRow->execute([$iId]);
            $invNo = $invRow->fetchColumn();
            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, invoice_id, created_by)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $date,
                'income',
                'Penerimaan Trip',
                "Pembayaran Invoice $invNo — $custName",
                $amount,
                $ref ?: $invNo,
                $iId,
                $user
            ]);

            $_SESSION['flash_message'] = 'Pembayaran berhasil dicatat.';
            $_SESSION['flash_type']    = 'success';
        }
        header('Location: invoices.php?action=view&id=' . $iId);
        exit;

        // Create direct invoice (without quotation)
    } elseif ($postAction === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $newCustomerName = trim($_POST['new_customer_name'] ?? '');
        $taxPct     = (float)($_POST['tax_pct'] ?? 11);
        $discount   = (float)str_replace(['.', ','], ['', '.'], $_POST['discount_amount'] ?? '0');
        $tripDate   = $_POST['trip_date']     ?: null;
        $tripEnd    = $_POST['trip_end_date'] ?: null;
        $paxCount   = max(1, (int)($_POST['pax_count'] ?? 1));
        $dueDate    = $_POST['due_date']      ?: date('Y-m-d', strtotime('+14 days'));
        $invoiceDate = $_POST['invoice_date']  ?: date('Y-m-d');
        $notes      = trim($_POST['notes'] ?? '');
        $user       = $auth->getCurrentUser()['username'] ?? 'system';

        // Buat customer baru inline jika panel "Tambah Customer Baru" dipakai.
        if ($customerId <= 0 && $newCustomerName !== '') {
            $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
            $nextNum = 1;
            if ($lastCode && preg_match('/(\d+)$/', $lastCode, $mCode)) $nextNum = (int)$mCode[1] + 1;
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

        if ($customerId <= 0) {
            $_SESSION['flash_message'] = 'Customer wajib dipilih atau diisi datanya.';
            $_SESSION['flash_type']    = 'error';
            header('Location: invoices.php?action=' . ($id > 0 ? 'edit&id=' . $id : 'add'));
            exit;
        }

        $descriptions = $_POST['item_description'] ?? [];
        $itemTypes    = $_POST['item_type']         ?? [];
        $qtys         = $_POST['item_qty']          ?? [];
        $units        = $_POST['item_unit']         ?? [];
        $prices       = $_POST['item_price']        ?? [];

        $subtotal = 0;
        $items    = [];
        foreach ($descriptions as $i => $desc) {
            $desc = trim($desc);
            if (!$desc) continue;
            $qty  = max(0, (float)$qtys[$i]);
            $price = (float)str_replace(['.', ','], ['', '.'], $prices[$i] ?? '0');
            $sub  = $qty * $price;
            $subtotal += $sub;
            $items[] = [
                'item_type' => $itemTypes[$i] ?? 'other',
                'description' => $desc,
                'qty' => $qty,
                'unit' => trim($units[$i] ?? 'pax'),
                'unit_price' => $price,
                'subtotal' => $sub,
                'sort_order' => $i
            ];
        }
        $tax       = round($subtotal * $taxPct / 100, 2);
        $total     = $subtotal + $tax - $discount;
        $remaining = $total;

        if ($id > 0) {
            $pdo->prepare("
                UPDATE invoices SET customer_id=?, trip_date=?, trip_end_date=?, pax_count=?,
                subtotal=?, tax_pct=?, tax_amount=?, discount_amount=?, total_amount=?,
                remaining_amount=?, due_date=?, notes=?, issued_at=?, updated_at=NOW() WHERE id=?
            ")->execute([
                $customerId,
                $tripDate,
                $tripEnd,
                $paxCount,
                $subtotal,
                $taxPct,
                $tax,
                $discount,
                $total,
                $remaining,
                $dueDate,
                $notes,
                $invoiceDate,
                $id
            ]);
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        } else {
            $invNo = sunseaNextNumber($pdo, 'invoice');
            $pdo->prepare("
                INSERT INTO invoices 
                (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
                 subtotal, tax_pct, tax_amount, discount_amount, total_amount,
                 remaining_amount, due_date, notes, status, issued_at, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'issued',?,?)
            ")->execute([
                $invNo,
                $customerId,
                $tripDate,
                $tripEnd,
                $paxCount,
                $subtotal,
                $taxPct,
                $tax,
                $discount,
                $total,
                $remaining,
                $dueDate,
                $notes,
                $invoiceDate,
                $user
            ]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,qty,unit,unit_price,subtotal,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $item) {
            $ins->execute([$id, $item['item_type'], $item['description'], $item['qty'], $item['unit'], $item['unit_price'], $item['subtotal'], $item['sort_order']]);
        }

        $_SESSION['flash_message'] = 'Invoice berhasil disimpan.';
        $_SESSION['flash_type']    = 'success';
        header('Location: invoices.php?action=view&id=' . $id);
        exit;

        // Konfirmasi invoice manual jadi Booking, supaya operasional & keuangan ikut tercatat
    } elseif ($postAction === 'convert_to_booking') {
        $iId = (int)($_POST['invoice_id'] ?? 0);
        $user = $auth->getCurrentUser()['username'] ?? 'system';
        $invRow = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
        $invRow->execute([$iId]);
        $inv = $invRow->fetch(PDO::FETCH_ASSOC);

        if (!$inv) {
            $_SESSION['flash_message'] = 'Invoice tidak ditemukan.';
            $_SESSION['flash_type']    = 'error';
        } elseif (preg_match('/Generated from Reservasi:/', (string)($inv['internal_notes'] ?? ''))) {
            $_SESSION['flash_message'] = 'Invoice ini sudah terhubung ke booking.';
            $_SESSION['flash_type']    = 'error';
        } elseif (empty($inv['trip_date']) || empty($inv['trip_end_date'])) {
            $_SESSION['flash_message'] = 'Isi Tanggal Trip & Tanggal Selesai di invoice ini dulu sebelum dikonfirmasi jadi booking.';
            $_SESSION['flash_type']    = 'error';
        } else {
            sunseaEnsureBookingSchema($pdo);
            $itemsStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
            $itemsStmt->execute([$iId]);
            $iiRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                $bookingNo = sunseaNextNumber($pdo, 'booking');
                $pdo->prepare("INSERT INTO booking_orders
                    (booking_no, customer_id, booking_mode, start_date, end_date, pax_count, status, cost_total, sell_total, margin_amount, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $bookingNo,
                        (int)$inv['customer_id'],
                        'ecer',
                        $inv['trip_date'],
                        $inv['trip_end_date'],
                        (int)$inv['pax_count'],
                        'confirmed',
                        0,
                        (float)$inv['total_amount'],
                        (float)$inv['total_amount'],
                        'Dibuat dari Invoice ' . $inv['invoice_no'],
                        $user,
                    ]);
                $bookingId = (int)$pdo->lastInsertId();

                $insItem = $pdo->prepare("INSERT INTO booking_order_items
                    (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?,?)");
                foreach ($iiRows as $idx => $ii) {
                    $insItem->execute([
                        $bookingId,
                        'manual',
                        (string)$ii['description'],
                        (float)$ii['qty'],
                        (string)$ii['unit'],
                        0,
                        (float)$ii['unit_price'],
                        0,
                        (float)$ii['subtotal'],
                        $idx,
                    ]);
                }

                $pdo->prepare("UPDATE invoices SET internal_notes=? WHERE id=?")
                    ->execute(['Generated from Reservasi: ' . $bookingNo, $iId]);

                $pdo->commit();
                $_SESSION['flash_message'] = "Booking $bookingNo berhasil dibuat dari invoice ini.";
                $_SESSION['flash_type']    = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_message'] = 'Gagal membuat booking: ' . $e->getMessage();
                $_SESSION['flash_type']    = 'error';
            }
        }
        header('Location: invoices.php?action=view&id=' . $iId);
        exit;
    }
}

// ---- LOAD DATA ----
$invoice = null;
$invItems = [];
$payments = [];
if (in_array($action, ['view', 'print']) && $invId > 0) {
    $s = $pdo->prepare("
        SELECT i.*, c.name as customer_name, c.phone as customer_phone,
               c.email as customer_email, c.address as customer_address, c.city as customer_city
        FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?
    ");
    $s->execute([$invId]);
    $invoice = $s->fetch();
    if (!$invoice) {
        header('Location: invoices.php');
        exit;
    }

    $si = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
    $si->execute([$invId]);
    $invItems = $si->fetchAll();

    // Invoice dari booking paket: sembunyikan rincian modal internal (harga Rp 0) yang sudah terlanjur
    // tersimpan dari sebelum fix, cukup tampilkan baris "Paket: ..." + fasilitas/manual bernilai > 0.
    $linkedBooking = null;
    if (preg_match('/Generated from Reservasi:\s*(\S+)/', (string)($invoice['internal_notes'] ?? ''), $m)) {
        $boStmt = $pdo->prepare("SELECT id, booking_mode FROM booking_orders WHERE booking_no=?");
        $boStmt->execute([$m[1]]);
        $bo = $boStmt->fetch(PDO::FETCH_ASSOC);
        if ($bo) {
            $linkedBooking = ['id' => (int)$bo['id'], 'booking_no' => $m[1]];
            if ($bo['booking_mode'] === 'paket') {
                $invItems = array_values(array_filter($invItems, function ($it) {
                    $isZero = (float)$it['unit_price'] === 0.0 && (float)$it['subtotal'] === 0.0;
                    $isPaketLine = stripos((string)$it['description'], 'Paket:') === 0;
                    return !$isZero || $isPaketLine;
                }));
            }
        }
    }

    $sp = $pdo->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY payment_date");
    $sp->execute([$invId]);
    $payments = $sp->fetchAll();

    // Hitung ulang sisa tagihan dari total-terbayar, jangan percaya kolom remaining_amount yang bisa basi.
    $invoice['remaining_amount'] = max(0, (float)$invoice['total_amount'] - (float)$invoice['paid_amount']);
}

$editInvoice = null;
if ($action === 'edit' && $invId > 0) {
    $s2 = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
    $s2->execute([$invId]);
    $editInvoice = $s2->fetch();
    $si2 = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
    $si2->execute([$invId]);
    $invItems = $si2->fetchAll();
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

// List
$statusFilter = $_GET['status'] ?? '';
$wh = $statusFilter ? "WHERE i.status=?" : "";
$lp = $statusFilter ? [$statusFilter] : [];
$invoiceList = $pdo->prepare("
    SELECT i.id, i.invoice_no, i.status, i.total_amount, i.paid_amount, i.remaining_amount, i.due_date, i.created_at,
           c.name as customer_name, i.pax_count
    FROM invoices i JOIN customers c ON c.id=i.customer_id $wh
    ORDER BY i.created_at DESC LIMIT 100
");
$invoiceList->execute($lp);
$invoiceList = $invoiceList->fetchAll();
foreach ($invoiceList as &$_invRow) {
    // Hitung ulang sisa tagihan dari total-terbayar, jangan percaya kolom remaining_amount yang bisa basi.
    $_invRow['remaining_amount'] = max(0, (float)$_invRow['total_amount'] - (float)$_invRow['paid_amount']);
}
unset($_invRow);

$invoiceLogoPath = sunseaSetting($pdo, 'invoice_logo', '') ?: sunseaSetting($pdo, 'company_logo', '');
$invoiceLogoSrc = sunseaAssetUrl($invoiceLogoPath);

$pageTitle  = match ($action) {
    'add'   => 'Buat Invoice Baru',
    'edit'  => 'Edit Invoice',
    'view'  => 'Detail Invoice',
    'print' => 'Cetak Invoice',
    default => 'Daftar Invoice'
};
$activePage = 'invoices';

// ---- PRINT ----
if ($action === 'print' && $invoice):
    $companyName    = sunseaSetting($pdo, 'company_name', 'Explore Karimunjawa');
    $companyAddress = sunseaSetting($pdo, 'company_address', '');
    $companyPhone   = sunseaSetting($pdo, 'company_phone', '');
    $companyEmail   = sunseaSetting($pdo, 'company_email', '');
    $printLogoPath  = sunseaSetting($pdo, 'invoice_logo', '') ?: sunseaSetting($pdo, 'company_logo', '');
    $printLogoSrc   = sunseaAssetUrl($printLogoPath);
    $stampPath      = sunseaSetting($pdo, 'invoice_stamp', '');
    $stampSrc       = sunseaAssetUrl($stampPath);
    $bankName       = sunseaSetting($pdo, 'bank_name', '');
    $bankAccount    = sunseaSetting($pdo, 'bank_account', '');
    $bankHolder     = sunseaSetting($pdo, 'bank_holder', '');
    $bankName2      = sunseaSetting($pdo, 'bank_name2', '');
    $bankAccount2   = sunseaSetting($pdo, 'bank_account2', '');
    $bankHolder2    = sunseaSetting($pdo, 'bank_holder2', '');
    $invoiceNotes   = sunseaSetting($pdo, 'invoice_notes', '');
    $footer         = sunseaSetting($pdo, 'invoice_footer', '');

    // Sisa tagihan sudah dihitung ulang dari total-terbayar di LOAD DATA (lihat $invoice['remaining_amount']).
    $computedRemaining = (float)$invoice['remaining_amount'];

    $statusLabel = 'BELUM LUNAS';
    $statusBg    = '#FEE2E2';
    $statusColor = '#B91C1C';
    $watermarkLabel = 'UNPAID';
    $watermarkColor = '#DC2626';
    if ($computedRemaining <= 0 || $invoice['status'] === 'paid') {
        $statusLabel = 'LUNAS';
        $statusBg    = '#DCFCE7';
        $statusColor = '#15803D';
        $watermarkLabel = 'PAID';
        $watermarkColor = '#16A34A';
    } elseif ((float)$invoice['paid_amount'] > 0 || $invoice['status'] === 'partial') {
        $statusLabel = 'DP / PARTIAL';
        $statusBg    = '#FEF3C7';
        $statusColor = '#B45309';
        $watermarkLabel = 'DOWN PAYMENT';
        $watermarkColor = '#D97706';
    }
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>Invoice <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
        <style>
            * {
                box-sizing: border-box;
            }

            @page {
                size: A4 portrait;
                margin: 14mm 12mm;
            }

            html,
            body {
                background: #E2E8F0;
            }

            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 12.5px;
                color: #1e293b;
            }

            .page {
                width: 210mm;
                min-height: 297mm;
                margin: 12px auto;
                background: #fff;
                padding: 16mm 14mm;
                box-shadow: 0 4px 18px rgba(15, 23, 42, .12);
                position: relative;
                overflow: hidden;
            }

            .watermark {
                position: absolute;
                top: 45%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-28deg);
                font-size: 70px;
                font-weight: 800;
                letter-spacing: 4px;
                text-transform: uppercase;
                opacity: .13;
                white-space: nowrap;
                pointer-events: none;
                z-index: 0;
            }

            .page>*:not(.watermark) {
                position: relative;
                z-index: 1;
            }

            .accent-bar {
                height: 6px;
                border-radius: 4px;
                background: linear-gradient(90deg, #7C2D12, #C2410C 55%, #EA580C);
                margin-bottom: 20px;
            }

            .head {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                padding-bottom: 16px;
                border-bottom: 2px solid #E2E8F0;
            }

            .brand-row {
                display: flex;
                align-items: center;
                gap: 14px;
            }

            .brand-logo-box {
                width: 66px;
                height: 66px;
                flex-shrink: 0;
                border: 1px solid #E2E8F0;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                background: #fff;
            }

            .brand-logo-box img {
                width: 100%;
                height: 100%;
                object-fit: contain;
            }

            .brand-name {
                font-size: 20px;
                font-weight: 800;
                margin: 0;
                color: #7C2D12;
                letter-spacing: .2px;
                line-height: 1.25;
            }

            .brand-meta {
                font-size: 10.5px;
                color: #64748B;
                margin-top: 4px;
                line-height: 1.6;
                max-width: 320px;
            }

            .invoice-tag {
                font-size: 25px;
                font-weight: 800;
                letter-spacing: 2.5px;
                color: #C2410C;
                margin: 0;
            }

            .invoice-no {
                font-size: 12px;
                color: #64748B;
                margin-top: 5px;
                font-weight: 700;
            }

            .status-badge {
                display: inline-block;
                font-size: 10.5px;
                font-weight: 700;
                padding: 4px 14px;
                border-radius: 20px;
                letter-spacing: .3px;
                margin-top: 9px;
            }

            .info-cols {
                display: flex;
                justify-content: space-between;
                gap: 20px;
                margin-top: 18px;
            }

            .info-box {
                flex: 1;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 12px 16px;
            }

            .info-box .info-title {
                font-size: 10px;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: .5px;
                font-weight: 700;
                margin-bottom: 6px;
            }

            .info-box .cust-name {
                font-size: 14.5px;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 3px;
            }

            .info-box .cust-detail {
                font-size: 11px;
                color: #475569;
                line-height: 1.6;
            }

            .meta-list .meta-row {
                display: flex;
                justify-content: space-between;
                font-size: 11.5px;
                padding: 3px 0;
                color: #475569;
            }

            .meta-list .meta-row b {
                color: #1e293b;
                font-weight: 700;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 18px;
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

            thead th:first-child {
                border-radius: 6px 0 0 0;
                width: 26px;
                text-align: center;
            }

            thead th:last-child {
                border-radius: 0 6px 0 0;
            }

            thead th:nth-child(3),
            thead th:nth-child(4),
            thead th:nth-child(5) {
                text-align: right;
            }

            tbody td {
                padding: 8px 10px;
                border-bottom: 1px solid #EEF2F7;
                font-size: 12px;
            }

            tbody td:first-child {
                text-align: center;
                color: #94a3b8;
            }

            tbody td:nth-child(3),
            tbody td:nth-child(4),
            tbody td:nth-child(5) {
                text-align: right;
                white-space: nowrap;
            }

            tbody tr:nth-child(even) {
                background: #FAFBFC;
            }

            tbody tr:last-child td {
                border-bottom: 2px solid #E2E8F0;
            }

            .bottom-flex {
                display: flex;
                justify-content: space-between;
                gap: 24px;
                margin-top: 18px;
            }

            .bank-box {
                flex: 1;
                font-size: 11px;
                color: #475569;
            }

            .bank-card {
                background: #F8FAFC;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 10px 14px;
                margin-bottom: 8px;
            }

            .bank-card b {
                color: #1e293b;
            }

            .total {
                width: 300px;
                flex-shrink: 0;
            }

            .row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                font-size: 12px;
            }

            .final {
                font-size: 17px;
                font-weight: 800;
                border-top: 2px solid #C2410C;
                margin-top: 4px;
                padding-top: 10px;
                color: #C2410C;
            }

            .terms-box {
                margin-top: 22px;
                background: #F8FAFC;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                padding: 12px 16px;
            }

            .terms-box .terms-title {
                font-size: 10.5px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: .4px;
                color: #7C2D12;
                margin-bottom: 6px;
            }

            .terms-box ol {
                margin: 0;
                padding-left: 16px;
                font-size: 10.5px;
                color: #475569;
                line-height: 1.7;
            }

            .signature-area {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                margin-top: 36px;
                gap: 24px;
            }

            .notes-col {
                flex: 1;
                font-size: 11px;
                color: #475569;
            }

            .sign-col {
                width: 220px;
                text-align: center;
                position: relative;
            }

            .sign-col .sign-place {
                font-size: 11px;
                color: #64748B;
                margin-bottom: 4px;
            }

            .stamp-img {
                max-width: 110px;
                max-height: 110px;
                object-fit: contain;
                opacity: .88;
                margin: 6px auto -18px;
                display: block;
                mix-blend-mode: multiply;
            }

            .sign-line {
                margin-top: 58px;
                border-top: 1px solid #94a3b8;
                padding-top: 6px;
                font-size: 11.5px;
                font-weight: 700;
                color: #1e293b;
            }

            .footer-note {
                clear: both;
                margin-top: 60px;
                padding-top: 12px;
                border-top: 1px dashed #E2E8F0;
                font-size: 10.5px;
                color: #94a3b8;
                text-align: left;
                font-style: italic;
            }

            .footer-contact {
                margin-top: 6px;
                font-style: normal;
                font-weight: 700;
                color: #475569;
                display: flex;
                flex-direction: column;
                gap: 3px;
            }

            .footer-contact-name {
                margin-bottom: 2px;
            }

            .footer-adf-system {
                position: absolute;
                left: 0;
                right: 0;
                bottom: 8mm;
                font-size: 8px;
                font-style: normal;
                color: #cbd5e1;
                text-align: center;
            }

            .thanks-note {
                text-align: center;
                margin-top: 10px;
                font-size: 12px;
                font-weight: 700;
                color: #7C2D12;
            }

            @media print {

                html,
                body {
                    background: #fff;
                }

                .page {
                    width: auto;
                    min-height: 297mm;
                    margin: 0;
                    padding: 0;
                    box-shadow: none;
                }
            }
        </style>
    </head>

    <body onload="window.print()">
        <div class="page">
            <div class="watermark" style="color:<?php echo $watermarkColor; ?>;<?php echo strlen($watermarkLabel) > 6 ? 'font-size:52px;letter-spacing:2px;' : ''; ?>"><?php echo htmlspecialchars($watermarkLabel); ?></div>
            <div class="accent-bar"></div>
            <div class="head">
                <div class="brand-row">
                    <?php if ($printLogoSrc): ?><div class="brand-logo-box"><img src="<?php echo htmlspecialchars($printLogoSrc); ?>" alt="Logo"></div><?php endif; ?>
                    <div>
                        <p class="brand-name"><?php echo htmlspecialchars($companyName); ?></p>
                        <div class="brand-meta">
                            <?php if ($companyAddress): ?><div><?php echo htmlspecialchars($companyAddress); ?></div><?php endif; ?>
                            <div>
                                <?php echo htmlspecialchars($companyPhone); ?><?php echo ($companyPhone && $companyEmail) ? ' &middot; ' : ''; ?><?php echo htmlspecialchars($companyEmail); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <p class="invoice-tag">INVOICE</p>
                    <div class="invoice-no">No. <?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                    <div><span class="status-badge" style="background:<?php echo $statusBg; ?>;color:<?php echo $statusColor; ?>;"><?php echo $statusLabel; ?></span></div>
                </div>
            </div>

            <div class="info-cols">
                <div class="info-box">
                    <div class="info-title">Ditagihkan Kepada</div>
                    <div class="cust-name"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                    <div class="cust-detail">
                        <?php if (!empty($invoice['customer_phone'])): ?><?php echo htmlspecialchars($invoice['customer_phone']); ?><br><?php endif; ?>
                    <?php if (!empty($invoice['customer_email'])): ?><?php echo htmlspecialchars($invoice['customer_email']); ?><br><?php endif; ?>
                <?php if (!empty($invoice['customer_address']) || !empty($invoice['customer_city'])): ?>
                    <?php echo htmlspecialchars(trim($invoice['customer_address'] . ' ' . $invoice['customer_city'])); ?>
                <?php endif; ?>
                    </div>
                </div>
                <div class="info-box meta-list">
                    <div class="info-title">Detail Invoice</div>
                    <div class="meta-row"><span>Tanggal Invoice</span><b><?php echo date('d M Y', strtotime($invoice['issued_at'] ?: $invoice['created_at'])); ?></b></div>
                    <div class="meta-row"><span>Jatuh Tempo</span><b><?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?></b></div>
                    <div class="meta-row"><span>Jumlah Pax</span><b><?php echo (int)$invoice['pax_count']; ?> orang</b></div>
                    <?php if ($invoice['trip_date']): ?>
                        <div class="meta-row"><span>Tanggal Trip</span><b><?php echo date('d M Y', strtotime($invoice['trip_date'])); ?><?php echo $invoice['trip_end_date'] ? ' - ' . date('d M Y', strtotime($invoice['trip_end_date'])) : ''; ?></b></div>
                    <?php endif; ?>
                    <?php if ($linkedBooking): ?>
                        <div class="meta-row"><span>No. Booking</span><b><?php echo htmlspecialchars($linkedBooking['booking_no']); ?></b></div>
                    <?php endif; ?>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Keterangan</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invItems as $idx => $item): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['qty'] == (int)$item['qty'] ? (int)$item['qty'] : (float)$item['qty']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
                            <td><?php echo sunseaRupiah((float)$item['unit_price']); ?></td>
                            <td><?php echo sunseaRupiah((float)$item['subtotal']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($invItems)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;color:#94a3b8;">Belum ada item.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="bottom-flex">
                <div class="bank-box">
                    <?php if ($bankName || $bankAccount): ?>
                        <div class="bank-card"><b>Transfer ke:</b> <?php echo htmlspecialchars($bankName ?: '-'); ?> &mdash; <?php echo htmlspecialchars($bankAccount ?: '-'); ?> a.n. <?php echo htmlspecialchars($bankHolder ?: '-'); ?></div>
                    <?php endif; ?>
                    <?php if ($bankName2 || $bankAccount2): ?>
                        <div class="bank-card"><b>Transfer ke:</b> <?php echo htmlspecialchars($bankName2 ?: '-'); ?> &mdash; <?php echo htmlspecialchars($bankAccount2 ?: '-'); ?> a.n. <?php echo htmlspecialchars($bankHolder2 ?: '-'); ?></div>
                    <?php endif; ?>
                    <?php if ($invoice['notes']): ?><div style="margin-top:6px;"><strong>Catatan:</strong> <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div><?php endif; ?>
                </div>
                <div class="total">
                    <div class="row"><span>Subtotal</span><strong><?php echo sunseaRupiah((float)$invoice['subtotal']); ?></strong></div>
                    <?php if ($invoice['discount_amount'] > 0): ?>
                        <div class="row"><span>Diskon</span><strong>-<?php echo sunseaRupiah((float)$invoice['discount_amount']); ?></strong></div>
                    <?php endif; ?>
                    <div class="row"><span>PPN <?php echo (float)$invoice['tax_pct']; ?>%</span><strong><?php echo sunseaRupiah((float)$invoice['tax_amount']); ?></strong></div>
                    <div class="row final"><span>TOTAL</span><strong><?php echo sunseaRupiah((float)$invoice['total_amount']); ?></strong></div>
                    <?php if ($invoice['paid_amount'] > 0): ?>
                        <div class="row" style="margin-top:8px;"><span>Terbayar</span><strong style="color:#15803D;"><?php echo sunseaRupiah((float)$invoice['paid_amount']); ?></strong></div>
                        <div class="row"><span><?php echo $computedRemaining > 0 ? 'Sisa Tagihan' : '&check; Lunas'; ?></span><strong style="color:<?php echo $computedRemaining > 0 ? '#B91C1C' : '#15803D'; ?>;"><?php echo sunseaRupiah($computedRemaining); ?></strong></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($invoiceNotes): ?>
                <div class="terms-box">
                    <div class="terms-title">Ketentuan &amp; Catatan</div>
                    <div style="font-size:10.5px;color:#475569;line-height:1.7;"><?php echo nl2br(htmlspecialchars($invoiceNotes)); ?></div>
                </div>
            <?php endif; ?>

            <div class="thanks-note">Terima kasih atas kepercayaan Anda memilih <?php echo htmlspecialchars($companyName); ?></div>

            <div class="footer-note">
                <div>Dokumen ini merupakan bukti pembayaran yang sah dan dicetak melalui sistem Karimunjawa Explore. Jika Anda mengalami kendala atau membutuhkan bantuan, silakan hubungi:</div>
                <div class="footer-contact">
                    <div class="footer-contact-name">Karimunjawa Explore</div>
                    <?php if ($companyPhone): ?><div>&#9742; <?php echo htmlspecialchars($companyPhone); ?></div><?php endif; ?>
                    <?php if ($companyEmail): ?><div>&#9993; <?php echo htmlspecialchars($companyEmail); ?></div><?php endif; ?>
                </div>
                <?php if ($footer): ?><div style="margin-top:6px;"><?php echo nl2br(htmlspecialchars($footer)); ?></div><?php endif; ?>
                <div class="footer-adf-system">Powered by &copy; AdFsystem.online 2026</div>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
endif;

include 'layout-header.php';
?>

<?php
$prefillTripDate = (string)($_GET['trip_date'] ?? '');
$prefillTripEndDate = (string)($_GET['trip_end_date'] ?? '');
$prefillPaxCount = max(1, (int)($_GET['pax_count'] ?? 1));
?>

<?php if ($action === 'view' && $invoice): ?>
    <?php
    $payMode = (($_GET['pay_mode'] ?? 'dp') === 'full') ? 'full' : 'dp';
    $openPaymentModal = (($_GET['open_payment'] ?? '0') === '1') && in_array($invoice['status'], ['issued', 'partial'], true);
    $remainingForPayment = max(0, (float)$invoice['remaining_amount']);
    $dpSuggested = $remainingForPayment > 0 ? min($remainingForPayment, max(100000, round(((float)$invoice['total_amount']) * 0.3))) : 0;
    $paymentPreset = $payMode === 'full' ? $remainingForPayment : $dpSuggested;
    $paymentPresetFmt = number_format($paymentPreset, 0, ',', '.');
    ?>
    <!-- ============ VIEW ============ -->
    <div style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
        <a href="invoices.php?action=edit&id=<?php echo $invoice['id']; ?>" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="edit-3"></i> Edit Invoice</a>
        <a href="invoices.php?action=print&id=<?php echo $invoice['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i> Cetak</a>
        <?php if (in_array($invoice['status'], ['issued', 'partial'])): ?>
            <button onclick="document.getElementById('paymentModal').style.display='flex'" class="ss-btn ss-btn-primary ss-btn-sm">
                <i data-feather="dollar-sign"></i> Catat Pembayaran
            </button>
        <?php endif; ?>
        <?php if ($linkedBooking): ?>
            <a href="bookings.php?view=<?php echo $linkedBooking['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#15803D;border-color:#15803D;"><i data-feather="check-circle"></i> Terhubung Booking <?php echo htmlspecialchars($linkedBooking['booking_no']); ?></a>
        <?php else: ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Konfirmasi invoice ini jadi Booking? Data akan masuk ke menu Booking agar operasional & keuangan tercatat.');">
                <input type="hidden" name="action" value="convert_to_booking">
                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#C2410C;border-color:#C2410C;"><i data-feather="check-square"></i> Konfirmasi jadi Booking</button>
            </form>
        <?php endif; ?>
    </div>


    <div class="ss-card" style="max-width:900px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
            <div>
                <div class="ss-card-title">Invoice - <?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                <div class="ss-card-sub">
                    <?php echo htmlspecialchars($invoice['customer_name']); ?>
                    &middot; Invoice <?php echo date('d M Y', strtotime($invoice['issued_at'] ?: $invoice['created_at'])); ?>
                    <?php if ($invoice['trip_date']): ?>
                        &middot; Trip <?php echo date('d M Y', strtotime($invoice['trip_date'])); ?><?php echo $invoice['trip_end_date'] ? ' - ' . date('d M Y', strtotime($invoice['trip_end_date'])) : ''; ?>
                    <?php endif; ?>
                    &middot; <?php echo (int)$invoice['pax_count']; ?> pax
                    &middot; Jatuh Tempo <?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?>
                </div>
            </div>
            <span class="ss-status ss-status-<?php echo $invoice['status']; ?>" style="font-size:13px;padding:5px 14px;"><?php echo ucfirst($invoice['status']); ?></span>
        </div>

        <div class="ss-table-wrap">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Keterangan</th>
                        <th>Qty</th>
                        <th>Sat.</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invItems as $i => $item): ?>
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

        <div style="display:flex;justify-content:flex-end;margin-top:14px;">
            <div style="width:320px;">
                <div style="display:flex;justify-content:space-between;padding:6px 0;"><span style="color:var(--ss-muted)">Subtotal</span><strong><?php echo sunseaRupiah((float)$invoice['subtotal']); ?></strong></div>
                <?php if ($invoice['discount_amount'] > 0): ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;"><span style="color:var(--ss-muted)">Diskon</span><strong style="color:var(--ss-success)">-<?php echo sunseaRupiah((float)$invoice['discount_amount']); ?></strong></div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;"><span style="color:var(--ss-muted)">PPN <?php echo (float)$invoice['tax_pct']; ?>%</span><strong><?php echo sunseaRupiah((float)$invoice['tax_amount']); ?></strong></div>
                <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:2px solid var(--ss-ocean);font-size:16px;"><span>TOTAL</span><strong style="color:var(--ss-ocean)"><?php echo sunseaRupiah((float)$invoice['total_amount']); ?></strong></div>
                <?php if ($invoice['paid_amount'] > 0): ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;"><span style="color:var(--ss-muted)">Terbayar</span><strong style="color:var(--ss-success)"><?php echo sunseaRupiah((float)$invoice['paid_amount']); ?></strong></div>
                    <div style="display:flex;justify-content:space-between;padding:8px 14px;margin-top:4px;background:<?php echo $invoice['remaining_amount'] > 0 ? '#FEE2E2' : '#D1FAE5'; ?>;border-radius:8px;font-weight:800;color:<?php echo $invoice['remaining_amount'] > 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;">
                        <span><?php echo $invoice['remaining_amount'] > 0 ? 'Sisa Tagihan' : '✓ Lunas'; ?></span>
                        <span><?php echo sunseaRupiah((float)$invoice['remaining_amount']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($payments)): ?>
        <div class="ss-card" style="max-width:900px;">
            <div class="ss-card-title" style="margin-bottom:14px;">Riwayat Pembayaran</div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Jumlah</th>
                            <th>Metode</th>
                            <th>Referensi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($p['payment_date'])); ?></td>
                                <td style="font-weight:600;color:var(--ss-success);"><?php echo sunseaRupiah((float)$p['amount']); ?></td>
                                <td><?php echo ucfirst($p['method']); ?></td>
                                <td><?php echo htmlspecialchars($p['reference'] ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Payment Modal -->
    <div id="paymentModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
        <div class="ss-card" style="width:420px;max-width:96vw;">
            <div class="ss-card-header">
                <div class="ss-card-title"><?php echo $payMode === 'full' ? 'Pelunasan Invoice' : 'Catat DP Invoice'; ?></div>
                <button onclick="document.getElementById('paymentModal').style.display='none'" style="background:none;border:none;cursor:pointer;"><i data-feather="x"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                <div class="ss-form-group">
                    <label class="ss-label">Jumlah (Rp) *</label>
                    <input type="text" name="amount" class="ss-input" required
                        placeholder="<?php echo $paymentPresetFmt; ?>"
                        value="<?php echo $paymentPresetFmt; ?>">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Tanggal Bayar</label>
                    <input type="date" name="payment_date" class="ss-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Metode</label>
                    <select name="method" class="ss-select">
                        <option value="transfer">Transfer Bank</option>
                        <option value="cash">Tunai</option>
                        <option value="qris">QRIS</option>
                        <option value="other">Lainnya</option>
                    </select>
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">No. Referensi / Bukti</label>
                    <input type="text" name="reference" class="ss-input" placeholder="Opsional">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label">Catatan</label>
                    <textarea name="notes" class="ss-textarea" rows="2"></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="document.getElementById('paymentModal').style.display='none'" class="ss-btn ss-btn-outline">Batal</button>
                    <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="check"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($openPaymentModal): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var modal = document.getElementById('paymentModal');
                if (modal) {
                    modal.style.display = 'flex';
                }
            });
        </script>
    <?php endif; ?>

<?php elseif (in_array($action, ['add', 'edit'])): ?>
    <!-- Add/Edit: same pattern as quotations form (simplified) -->
    <style>
        .inv-form .ss-card {
            padding: 14px 16px;
        }

        .inv-form .ss-card-header {
            margin-bottom: 12px;
        }

        .inv-form .ss-card-title {
            font-size: 13px;
            margin-bottom: 10px;
        }

        .inv-form .ss-form-grid {
            gap: 10px;
        }

        .inv-form .ss-form-group {
            margin-bottom: 0;
        }

        .inv-form .ss-label {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .inv-form .ss-input,
        .inv-form .ss-select,
        .inv-form .ss-textarea {
            padding: 6px 9px;
            font-size: 12.5px;
        }

        .inv-form .ss-textarea {
            min-height: 54px;
        }

        .inv-form .ss-table th {
            padding: 6px 8px;
            font-size: 10px;
        }

        .inv-form .ss-table td {
            padding: 5px 8px;
        }

        .inv-form .ss-btn {
            padding: 6px 13px;
            font-size: 12.5px;
        }
    </style>
    <div style="max-width:640px;" class="inv-form">
        <a href="invoices.php" class="ss-btn ss-btn-outline ss-btn-sm" style="margin-bottom:14px;display:inline-flex;"><i data-feather="arrow-left"></i> Kembali</a>
        <form method="POST" onsubmit="return prepareInvoiceSubmit()">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $editInvoice['id'] ?? 0; ?>">
            <div class="ss-card" style="margin-bottom:12px;">
                <div class="ss-card-title">Informasi Invoice</div>
                <div class="ss-form-grid cols-2">
                    <div class="ss-form-group" style="grid-column:1/-1;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <label class="ss-label">Customer *</label>
                            <a href="javascript:void(0)" onclick="toggleNewInvoiceCustomer()" id="newInvCustomerToggleLink" style="font-size:11.5px;color:#C2410C;font-weight:600;text-decoration:none;">+ Tambah Customer Baru</a>
                        </div>
                        <select name="customer_id" id="invCustomerSelect" class="ss-select" required>
                            <option value="">-- Pilih Customer --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($editInvoice['customer_id'] ?? $_GET['customer_id'] ?? 0) == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="newInvCustomerBox" style="display:none;margin-top:8px;padding:10px;background:#FFF7ED;border:1px solid #FDE4CC;border-radius:6px;">
                            <div class="ss-form-grid cols-2" style="gap:8px;">
                                <div class="ss-form-group" style="grid-column:1/-1;margin:0;">
                                    <label class="ss-label">Nama Customer *</label>
                                    <input type="text" name="new_customer_name" id="newInvCustomerName" class="ss-input" placeholder="Nama lengkap tamu">
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
                            <div style="font-size:10.5px;color:#888;margin-top:5px;">* Otomatis tersimpan ke database Pelanggan saat invoice disimpan.</div>
                        </div>
                    </div>
                    <div class="ss-form-group"><label class="ss-label">Tanggal Invoice *</label><input type="date" name="invoice_date" class="ss-input" value="<?php echo htmlspecialchars(substr($editInvoice['issued_at'] ?? $editInvoice['created_at'] ?? '', 0, 10) ?: date('Y-m-d')); ?>" required></div>
                    <div class="ss-form-group"><label class="ss-label">Jatuh Tempo</label><input type="date" name="due_date" class="ss-input" value="<?php echo $editInvoice['due_date'] ?? date('Y-m-d', strtotime('+14 days')); ?>"></div>
                    <div class="ss-form-group"><label class="ss-label">Tanggal Trip</label><input type="date" name="trip_date" class="ss-input" value="<?php echo htmlspecialchars($editInvoice['trip_date'] ?? $prefillTripDate); ?>"></div>
                    <div class="ss-form-group"><label class="ss-label">Tanggal Selesai</label><input type="date" name="trip_end_date" class="ss-input" value="<?php echo htmlspecialchars($editInvoice['trip_end_date'] ?? $prefillTripEndDate); ?>"></div>
                    <div class="ss-form-group"><label class="ss-label">Peserta</label><input type="number" name="pax_count" class="ss-input" min="1" value="<?php echo (int)($editInvoice['pax_count'] ?? $prefillPaxCount); ?>"></div>
                    <div class="ss-form-group"><label class="ss-label">PPN (%)</label><input type="number" name="tax_pct" class="ss-input" step="0.1" value="<?php echo $editInvoice['tax_pct'] ?? 11; ?>" id="taxInput2"></div>
                    <div class="ss-form-group"><label class="ss-label">Diskon (Rp)</label><input type="text" name="discount_amount" class="ss-input" value="<?php echo number_format($editInvoice['discount_amount'] ?? 0, 0, ',', '.'); ?>" id="discountInput2"></div>
                    <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Catatan</label><textarea name="notes" class="ss-textarea"><?php echo htmlspecialchars($editInvoice['notes'] ?? ''); ?></textarea></div>
                </div>
            </div>

            <div class="ss-card" style="margin-bottom:12px;">
                <div class="ss-card-title" style="margin-bottom:10px;">Item Invoice</div>
                <div style="display:flex;gap:14px;margin-bottom:10px;">
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;">
                        <input type="radio" name="invoice_mode" value="items" id="modeItems" onchange="switchInvoiceMode('items')" <?php echo (empty($invItems) || count($invItems) !== 1) ? 'checked' : ''; ?>>
                        Rincian per Item
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;">
                        <input type="radio" name="invoice_mode" value="simple" id="modeSimple" onchange="switchInvoiceMode('simple')" <?php echo (!empty($invItems) && count($invItems) === 1) ? 'checked' : ''; ?>>
                        Nominal Langsung (1 Total)
                    </label>
                </div>

                <div id="itemsModeBlock">
                    <div style="display:flex;justify-content:flex-end;margin-bottom:6px;">
                        <button type="button" onclick="addItem2()" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="plus"></i> Tambah Baris</button>
                    </div>
                    <div class="ss-table-wrap">
                        <table class="ss-table">
                            <thead>
                                <tr>
                                    <th style="width:120px;">Kategori</th>
                                    <th>Keterangan</th>
                                    <th style="width:60px;">Qty</th>
                                    <th style="width:60px;">Sat.</th>
                                    <th style="width:130px;">Harga</th>
                                    <th style="width:130px;">Subtotal</th>
                                    <th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody2">
                                <?php if (!empty($invItems)): ?>
                                    <?php foreach ($invItems as $item): echo '<tr>' . invItemRow($item['item_type'], $item['description'], $item['qty'], $item['unit'], $item['unit_price']) . '</tr>';
                                    endforeach; ?>
                                <?php else: echo '<tr>' . invItemRow() . '</tr><tr>' . invItemRow() . '</tr>';
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="simpleModeBlock" style="display:none;">
                    <div class="ss-form-grid cols-2" style="gap:8px;">
                        <div class="ss-form-group" style="grid-column:1/-1;">
                            <label class="ss-label">Keterangan</label>
                            <input type="text" id="simpleDesc" class="ss-input" placeholder="Contoh: Paket Trip Karimunjawa 3D2N" value="<?php echo (!empty($invItems) && count($invItems) === 1) ? htmlspecialchars($invItems[0]['description']) : ''; ?>">
                        </div>
                        <div class="ss-form-group" style="grid-column:1/-1;">
                            <label class="ss-label">Nominal (Rp) *</label>
                            <input type="text" id="simpleNominal" class="ss-input" style="font-size:15px;font-weight:700;" placeholder="0" value="<?php echo (!empty($invItems) && count($invItems) === 1) ? number_format((float)$invItems[0]['unit_price'], 0, ',', '.') : ''; ?>">
                        </div>
                    </div>
                </div>

                <div style="text-align:right;margin-top:10px;font-size:13.5px;font-weight:800;color:var(--ss-ocean);">
                    TOTAL: <span id="calcTotal2">Rp 0</span>
                </div>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <a href="invoices.php" class="ss-btn ss-btn-outline">Batal</a>
                <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="save"></i> Simpan Invoice</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <!-- ============ LIST ============ -->
    <div class="invoice-page-shell">
        <div class="invoice-page-heading">
            <div class="invoice-brand-lockup">
                <?php if ($invoiceLogoSrc): ?><img src="<?php echo htmlspecialchars($invoiceLogoSrc); ?>" alt="Logo Explore Karimunjawa"><?php endif; ?>
                <div>
                    <div class="invoice-eyebrow">FINANCE &amp; BILLING</div>
                    <h1>Invoice</h1>
                    <p>Kelola tagihan perjalanan dengan cepat dan rapi.</p>
                </div>
            </div>
            <a href="invoices.php?action=add" class="ss-btn ss-btn-primary"><i data-feather="plus"></i> Buat Invoice</a>
        </div>
        <div class="ss-card invoice-list-card">
            <div class="ss-card-header">
                <div>
                    <div class="ss-card-title">Semua Invoice</div>
                    <div class="ss-card-sub"><?php echo count($invoiceList); ?> invoice</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
                <?php foreach (['' => 'Semua', 'issued' => 'Issued', 'partial' => 'Partial', 'paid' => 'Lunas', 'overdue' => 'Overdue'] as $st => $lbl): ?>
                    <a href="invoices.php?status=<?php echo $st; ?>" class="ss-btn ss-btn-sm <?php echo $statusFilter === $st ? 'ss-btn-primary' : 'ss-btn-outline'; ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (empty($invoiceList)): ?>
                <div class="ss-empty">
                    <div class="ss-empty-icon">🧾</div>
                    <h3>Belum ada invoice</h3>
                </div>
            <?php else: ?>
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th>No. Invoice</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Terbayar</th>
                                <th>Sisa</th>
                                <th>Status</th>
                                <th>Jatuh Tempo</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoiceList as $inv): ?>
                                <tr>
                                    <td><a href="invoices.php?action=view&id=<?php echo $inv['id']; ?>" style="color:var(--ss-ocean);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($inv['invoice_no']); ?></a></td>
                                    <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                                    <td style="font-weight:600;"><?php echo sunseaRupiah((float)$inv['total_amount']); ?></td>
                                    <td style="color:var(--ss-success);font-weight:600;"><?php echo sunseaRupiah((float)$inv['paid_amount']); ?></td>
                                    <td style="color:<?php echo $inv['remaining_amount'] > 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;font-weight:700;"><?php echo sunseaRupiah((float)$inv['remaining_amount']); ?></td>
                                    <td><span class="ss-status ss-status-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                    <td><?php echo $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '-'; ?></td>
                                    <td>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <a href="invoices.php?action=view&id=<?php echo $inv['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm" title="Lihat invoice"><i data-feather="eye"></i></a>
                                            <a href="invoices.php?action=edit&id=<?php echo $inv['id']; ?>" class="ss-btn ss-btn-primary ss-btn-sm" title="Edit invoice"><i data-feather="edit-3"></i></a>
                                            <a href="invoices.php?action=print&id=<?php echo $inv['id']; ?>" target="_blank" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="printer"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<style>
    .invoice-page-shell {
        max-width: 1180px;
    }

    .invoice-page-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 18px;
    }

    .invoice-brand-lockup {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .invoice-brand-lockup img {
        width: 52px;
        height: 52px;
        object-fit: contain;
        border-radius: 14px;
        background: #fff;
        padding: 5px;
        box-shadow: 0 8px 22px rgba(124, 45, 18, .12);
    }

    .invoice-eyebrow {
        color: #C2410C;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .12em;
    }

    .invoice-brand-lockup h1 {
        margin: 3px 0 2px;
        color: #431407;
        font-size: 25px;
        letter-spacing: -.02em;
    }

    .invoice-brand-lockup p {
        margin: 0;
        color: #7C6F68;
        font-size: 12px;
    }

    .invoice-list-card {
        box-shadow: 0 12px 35px rgba(124, 45, 18, .08);
        border: 1px solid rgba(194, 65, 12, .08);
    }

    .invoice-list-card .ss-table th {
        font-size: 10px;
        letter-spacing: .06em;
    }

    .invoice-list-card .ss-table td {
        padding-top: 13px;
        padding-bottom: 13px;
    }

    @media (max-width:700px) {
        .invoice-page-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .invoice-page-heading .ss-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<?php
function invItemRow($type = '', $desc = '', $qty = 1, $unit = 'pax', $price = 0): string
{
    $typeOpts = ['accommodation', 'transport', 'meal', 'activity', 'guide', 'equipment', 'other'];
    $typeLabels = ['Penginapan', 'Transport', 'Makan', 'Aktivitas', 'Guide', 'Perlengkapan', 'Lainnya'];
    $sel = '';
    foreach ($typeOpts as $i => $t) {
        $s = $type === $t ? ' selected' : '';
        $sel .= "<option value=\"$t\"$s>{$typeLabels[$i]}</option>";
    }
    $pFmt = number_format((float)$price, 0, ',', '.');
    return "<td><select name=\"item_type[]\" class=\"ss-select\" style=\"font-size:12px;padding:6px 8px;\">$sel</select></td>
        <td><input type=\"text\" name=\"item_description[]\" class=\"ss-input\" style=\"font-size:12px;padding:6px 8px;\" value=\"$desc\" placeholder=\"Keterangan...\"></td>
        <td><input type=\"number\" name=\"item_qty[]\" class=\"ss-input item-qty2\" style=\"font-size:12px;padding:6px 8px;\" value=\"$qty\" min=\"0\" step=\"0.5\"></td>
        <td><input type=\"text\" name=\"item_unit[]\" class=\"ss-input\" style=\"font-size:12px;padding:6px 8px;\" value=\"$unit\"></td>
        <td><input type=\"text\" name=\"item_price[]\" class=\"ss-input item-price2\" style=\"font-size:12px;padding:6px 8px;\" value=\"$pFmt\" placeholder=\"0\"></td>
        <td><input type=\"text\" class=\"ss-input item-sub2\" style=\"font-size:12px;padding:6px 8px;font-weight:600;\" readonly placeholder=\"0\"></td>
        <td><button type=\"button\" onclick=\"removeRow2(this)\" style=\"background:none;border:none;cursor:pointer;color:var(--ss-danger);\"><i data-feather=\"x\" style=\"width:14px;height:14px;\"></i></button></td>";
}
?>
<script>
    function unFmt(s) {
        return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
    }

    function fmt(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function calcTotals2() {
        var sub = 0;
        var simpleMode = document.getElementById('modeSimple') && document.getElementById('modeSimple').checked;
        if (simpleMode) {
            sub = unFmt(document.getElementById('simpleNominal')?.value || '0');
        } else {
            document.querySelectorAll('#itemsBody2 tr').forEach(function(row) {
                var q = parseFloat(row.querySelector('.item-qty2')?.value) || 0;
                var p = unFmt(row.querySelector('.item-price2')?.value || '0');
                var s = q * p;
                var sf = row.querySelector('.item-sub2');
                if (sf) sf.value = s ? Math.round(s).toLocaleString('id-ID') : '';
                sub += s;
            });
        }
        var disc = unFmt(document.getElementById('discountInput2')?.value || '0');
        var taxP = parseFloat(document.getElementById('taxInput2')?.value) || 0;
        var tax = (sub - disc) * taxP / 100;
        var tot = sub + tax - disc;
        document.getElementById('calcTotal2').textContent = fmt(tot);
    }

    function switchInvoiceMode(mode) {
        var itemsBlock = document.getElementById('itemsModeBlock');
        var simpleBlock = document.getElementById('simpleModeBlock');
        if (itemsBlock) itemsBlock.style.display = mode === 'simple' ? 'none' : '';
        if (simpleBlock) simpleBlock.style.display = mode === 'simple' ? '' : 'none';
        calcTotals2();
    }

    function toggleNewInvoiceCustomer() {
        var box = document.getElementById('newInvCustomerBox');
        var select = document.getElementById('invCustomerSelect');
        var link = document.getElementById('newInvCustomerToggleLink');
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

    function prepareInvoiceSubmit() {
        if (document.getElementById('modeSimple') && document.getElementById('modeSimple').checked) {
            var desc = (document.getElementById('simpleDesc')?.value || '').trim() || 'Invoice';
            var nominal = unFmt(document.getElementById('simpleNominal')?.value || '0');
            var tbody = document.getElementById('itemsBody2');
            tbody.innerHTML = '';
            var tr = document.createElement('tr');
            var escDesc = desc.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            tr.innerHTML = '<td><input type="hidden" name="item_type[]" value="other"></td>' +
                '<td><input type="hidden" name="item_description[]" value="' + escDesc + '"></td>' +
                '<td><input type="hidden" name="item_qty[]" value="1"></td>' +
                '<td><input type="hidden" name="item_unit[]" value="paket"></td>' +
                '<td><input type="hidden" name="item_price[]" value="' + nominal + '"></td>';
            tbody.appendChild(tr);
        }
        return true;
    }

    function addItem2() {
        var tr = document.createElement('tr');
        tr.innerHTML = `<td><select name="item_type[]" class="ss-select" style="font-size:12px;padding:6px 8px;">
        <option value="accommodation">Penginapan</option><option value="transport">Transport</option>
        <option value="meal">Makan</option><option value="activity">Aktivitas</option>
        <option value="guide">Guide</option><option value="equipment">Perlengkapan</option>
        <option value="other" selected>Lainnya</option></select></td>
        <td><input type="text" name="item_description[]" class="ss-input" style="font-size:12px;padding:6px 8px;" placeholder="Keterangan..."></td>
        <td><input type="number" name="item_qty[]" class="ss-input item-qty2" style="font-size:12px;padding:6px 8px;" value="1" min="0" step="0.5"></td>
        <td><input type="text" name="item_unit[]" class="ss-input" style="font-size:12px;padding:6px 8px;" value="pax"></td>
        <td><input type="text" name="item_price[]" class="ss-input item-price2" style="font-size:12px;padding:6px 8px;" placeholder="0"></td>
        <td><input type="text" class="ss-input item-sub2" style="font-size:12px;padding:6px 8px;font-weight:600;" readonly placeholder="0"></td>
        <td><button type="button" onclick="removeRow2(this)" style="background:none;border:none;cursor:pointer;color:var(--ss-danger);"><i data-feather="x" style="width:14px;height:14px;"></i></button></td>`;
        document.getElementById('itemsBody2').appendChild(tr);
        feather.replace();
        setupRowListeners2(tr);
    }

    function removeRow2(btn) {
        btn.closest('tr').remove();
        calcTotals2();
    }

    function setupRowListeners2(row) {
        row.querySelectorAll('.item-qty2,.item-price2').forEach(function(i) {
            i.addEventListener('input', calcTotals2);
        });
    }
    document.querySelectorAll('#itemsBody2 tr').forEach(setupRowListeners2);
    ['discountInput2', 'taxInput2', 'simpleNominal'].forEach(function(id) {
        document.getElementById(id)?.addEventListener('input', calcTotals2);
    });
    if (document.getElementById('modeSimple')) {
        switchInvoiceMode(document.getElementById('modeSimple').checked ? 'simple' : 'items');
    }
    calcTotals2();
</script>

<?php include 'layout-footer.php'; ?>