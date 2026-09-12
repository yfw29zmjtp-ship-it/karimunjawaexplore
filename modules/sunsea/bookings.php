<?php

/**
 * Sunsea - Pemesanan (Paket / Ecer)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsurePackageItemsSchema($pdo);
sunseaEnsureBookingSchema($pdo);
sunseaEnsureAccommodationSchema($pdo);
sunseaEnsureMasterDataSchema($pdo);

function postNum(string $key): float
{
    return (float)str_replace(['.', ','], ['', '.'], $_POST[$key] ?? '0');
}

function safeFetchAll(PDO $pdo, string $sql, array $params = [], string $context = ''): array
{
    global $pageError;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        if ($pageError === '') {
            $pageError = 'Gagal memuat data ' . ($context ?: 'booking') . ': ' . $e->getMessage();
        }
        return [];
    }
}

function safeFetchOne(PDO $pdo, string $sql, array $params = [], string $context = '')
{
    global $pageError;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    } catch (Exception $e) {
        if ($pageError === '') {
            $pageError = 'Gagal memuat data ' . ($context ?: 'detail booking') . ': ' . $e->getMessage();
        }
        return false;
    }
}

/**
 * Ambil nama komponen (bisa lebih dari satu) berdasarkan component_code
 * dari daftar item booking, untuk ditampilkan di panel status operasional.
 */
function bookingItemsByCode(array $items, string $code): string
{
    $names = [];
    foreach ($items as $it) {
        if ($it['component_code'] === $code) {
            $names[] = $it['component_name'];
        }
    }
    return $names ? implode(', ', $names) : '-';
}

/**
 * Ensure invoice exists for a booking and return invoice id.
 * Creates invoice from booking items when not found.
 */
function ensureInvoiceFromBooking(PDO $pdo, Auth $auth, array $booking): int
{
    $invoiceId = 0;
    $internalRef = 'booking_id:' . (int)$booking['id'];

    try {
        $invStmt = $pdo->prepare("SELECT id FROM invoices WHERE internal_notes=? ORDER BY id DESC LIMIT 1");
        $invStmt->execute([$internalRef]);
        $invoiceId = (int)($invStmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        $invoiceId = 0;
    }

    if ($invoiceId > 0) {
        return $invoiceId;
    }

    // component_code 'pkg_detail' = rincian modal internal paket, tidak ditampilkan di invoice pelanggan
    $itemsStmt = $pdo->prepare("SELECT component_name, qty, unit, price_sell, total_sell FROM booking_order_items WHERE booking_id=? AND component_code != 'pkg_detail' ORDER BY sort_order");
    $itemsStmt->execute([(int)$booking['id']]);
    $bookingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($bookingItems)) {
        throw new RuntimeException('Item reservasi belum tersedia, invoice tidak dapat dibuat.');
    }

    $subtotal = 0.0;
    foreach ($bookingItems as $bi) {
        $subtotal += (float)$bi['total_sell'];
    }

    $taxPct = 0.0;
    $taxAmount = 0.0;
    $discountAmount = 0.0;
    $totalAmount = $subtotal;
    $remainingAmount = $totalAmount;
    $dueDate = date('Y-m-d', strtotime('+14 days'));
    $createdBy = $auth->getCurrentUser()['username'] ?? 'system';

    $pdo->beginTransaction();
    try {
        $invoiceNo = sunseaNextNumber($pdo, 'invoice');
        $insInv = $pdo->prepare("INSERT INTO invoices
            (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
             status, subtotal, tax_pct, tax_amount, discount_amount,
             total_amount, paid_amount, remaining_amount, due_date,
             notes, internal_notes, issued_at, created_by)
            VALUES (?,?,?,?,?,'issued',?,?,?,?,?,?,?,?,?, ?,NOW(),?)");

        $insInv->execute([
            $invoiceNo,
            (int)$booking['customer_id'],
            $booking['start_date'],
            $booking['end_date'],
            (int)$booking['pax_count'],
            $subtotal,
            $taxPct,
            $taxAmount,
            $discountAmount,
            $totalAmount,
            0,
            $remainingAmount,
            $dueDate,
            'Generated from Reservasi: ' . $booking['booking_no'],
            $internalRef,
            $createdBy,
        ]);

        $invoiceId = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare("INSERT INTO invoice_items
            (invoice_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
            VALUES (?,?,?,?,?,?,?,?)");

        foreach ($bookingItems as $idx => $bi) {
            $insItem->execute([
                $invoiceId,
                'other',
                (string)$bi['component_name'],
                (float)$bi['qty'],
                (string)$bi['unit'],
                (float)$bi['price_sell'],
                (float)$bi['total_sell'],
                $idx,
            ]);
        }

        $pdo->commit();
        return $invoiceId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function recalcBookingTotals(PDO $pdo, int $bookingId): void
{
    $sums = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) AS c, COALESCE(SUM(total_sell),0) AS s FROM booking_order_items WHERE booking_id=?");
    $sums->execute([$bookingId]);
    $row = $sums->fetch() ?: ['c' => 0, 's' => 0];
    $costTotal = (float)$row['c'];
    $sellTotal = (float)$row['s'];
    $pdo->prepare("UPDATE booking_orders SET cost_total=?, sell_total=?, margin_amount=?, updated_at=NOW() WHERE id=?")
        ->execute([$costTotal, $sellTotal, $sellTotal - $costTotal, $bookingId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_dates') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newStart = trim($_POST['start_date'] ?? '');
    $newEnd = trim($_POST['end_date'] ?? '');

    if ($bookingId > 0 && $newStart !== '' && $newEnd !== '' && strtotime($newEnd) >= strtotime($newStart)) {
        try {
            $pdo->prepare("UPDATE booking_orders SET start_date=?, end_date=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStart, $newEnd, $bookingId]);

            // Regenerate jadwal operasional sesuai rentang tanggal baru.
            $pdo->prepare("DELETE FROM booking_schedule WHERE booking_id=?")->execute([$bookingId]);
            $noStmt = $pdo->prepare("SELECT booking_no FROM booking_orders WHERE id=?");
            $noStmt->execute([$bookingId]);
            $bookingNo = (string)$noStmt->fetchColumn();
            $sched = $pdo->prepare("INSERT INTO booking_schedule (booking_id, activity_date, activity_type, title) VALUES (?,?,?,?)");
            $cur = strtotime($newStart);
            $end = strtotime($newEnd);
            while ($cur <= $end) {
                $sched->execute([$bookingId, date('Y-m-d', $cur), 'other', 'Operasional ' . $bookingNo]);
                $cur = strtotime('+1 day', $cur);
            }

            $_SESSION['flash_message'] = 'Tanggal trip berhasil diperbarui.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal update tanggal: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_message'] = 'Tanggal tidak valid. Pastikan tanggal selesai tidak sebelum tanggal mulai.';
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_item_prices') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $itemIds = $_POST['item_id'] ?? [];
    $priceSells = $_POST['price_sell'] ?? [];

    if ($bookingId > 0 && is_array($itemIds)) {
        try {
            $upd = $pdo->prepare("UPDATE booking_order_items SET price_sell=?, total_sell=(qty*?) WHERE id=? AND booking_id=?");
            foreach ($itemIds as $idx => $itemId) {
                $itemId = (int)$itemId;
                if ($itemId <= 0) continue;
                $newSell = (float)str_replace(['.', ','], ['', '.'], $priceSells[$idx] ?? '0');
                $upd->execute([$newSell, $newSell, $itemId, $bookingId]);
            }
            recalcBookingTotals($pdo, $bookingId);
            $_SESSION['flash_message'] = 'Harga jual / markup berhasil diperbarui.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal update harga jual: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $name = trim($_POST['component_name'] ?? '');
    $qty = (float)str_replace(['.', ','], ['', '.'], $_POST['qty'] ?? '1') ?: 1;
    $unit = trim($_POST['unit'] ?? 'unit') ?: 'unit';
    $priceCost = (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0');
    $priceSell = (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0');

    if ($bookingId > 0 && $name !== '') {
        try {
            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM booking_order_items WHERE booking_id=?");
            $sortStmt->execute([$bookingId]);
            $nextSort = (int)$sortStmt->fetchColumn();

            $pdo->prepare("INSERT INTO booking_order_items
                (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $bookingId,
                    'manual',
                    $name,
                    $qty,
                    $unit,
                    $priceCost,
                    $priceSell,
                    $qty * $priceCost,
                    $qty * $priceSell,
                    $nextSort,
                ]);
            recalcBookingTotals($pdo, $bookingId);
            $_SESSION['flash_message'] = 'Layanan tambahan berhasil ditambahkan.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal menambah layanan: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_message'] = 'Nama layanan wajib diisi.';
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);

    if ($bookingId > 0 && $itemId > 0) {
        try {
            $pdo->prepare("DELETE FROM booking_order_items WHERE id=? AND booking_id=?")->execute([$itemId, $bookingId]);
            recalcBookingTotals($pdo, $bookingId);
            $_SESSION['flash_message'] = 'Layanan berhasil dihapus.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal menghapus layanan: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'replace_room_transport') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $refType = $_POST['ref_type'] ?? '';
    $refId = (int)($_POST['ref_id'] ?? 0);
    $qty = (float)str_replace(['.', ','], ['', '.'], $_POST['qty'] ?? '1') ?: 1;

    if ($bookingId > 0 && $refId > 0 && in_array($refType, ['room', 'transport'], true)) {
        try {
            if ($refType === 'room') {
                $ref = $pdo->prepare("SELECT r.room_type, r.price_cost, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.id=?");
                $ref->execute([$refId]);
                $room = $ref->fetch(PDO::FETCH_ASSOC);
                if (!$room) throw new Exception('Kamar tidak ditemukan.');
                $componentCode = 'penginapan';
                $name = 'Penginapan: ' . $room['partner_name'] . ' - ' . $room['room_type'];
                $unit = 'room-night';
                $priceCost = (float)$room['price_cost'];
                $priceSell = (float)$room['price_sell'];
            } else {
                $ref = $pdo->prepare("SELECT name, unit, price_cost, price_sell FROM transport_items WHERE id=?");
                $ref->execute([$refId]);
                $trans = $ref->fetch(PDO::FETCH_ASSOC);
                if (!$trans) throw new Exception('Layanan transport tidak ditemukan.');
                $componentCode = 'transport';
                $name = 'Transportasi: ' . $trans['name'];
                $unit = $trans['unit'] ?: 'trip';
                $priceCost = (float)$trans['price_cost'];
                $priceSell = (float)$trans['price_sell'];
            }

            // "Ganti" = hapus item lama kategori yang sama. Cek 3 kemungkinan sekaligus supaya tidak dobel:
            // (1) mode ecer (component_code cocok langsung), (2) sudah ke-backfill item_type,
            // (3) belum ke-backfill tapi masih bisa dikenali live lewat trip_package_items milik paket booking ini.
            $delStmt = $pdo->prepare("DELETE boi FROM booking_order_items boi
                LEFT JOIN booking_orders bo ON bo.id = boi.booking_id
                LEFT JOIN trip_package_items tpi ON tpi.package_id = bo.package_id AND tpi.item_name = boi.component_name COLLATE utf8mb4_general_ci
                WHERE boi.booking_id = ?
                AND (boi.component_code = ? OR boi.item_type = ? OR (boi.component_code = 'pkg_detail' AND tpi.item_type = ?))");
            $delStmt->execute([$bookingId, $componentCode, $componentCode, $componentCode]);
            $deletedOldCount = $delStmt->rowCount();

            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM booking_order_items WHERE booking_id=?");
            $sortStmt->execute([$bookingId]);
            $nextSort = (int)$sortStmt->fetchColumn();

            $pdo->prepare("INSERT INTO booking_order_items
                (booking_id, component_code, item_type, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$bookingId, $componentCode, $componentCode, $name, $qty, $unit, $priceCost, $priceSell, $qty * $priceCost, $qty * $priceSell, $nextSort]);

            recalcBookingTotals($pdo, $bookingId);
            $_SESSION['flash_message'] = ($refType === 'room' ? 'Penginapan' : 'Layanan transport') . ' berhasil diganti, harga otomatis menyesuaikan. (item lama terhapus: ' . $deletedOldCount . ')';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal mengganti layanan: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    } else {
        $_SESSION['flash_message'] = 'Pilih penginapan/transport dari daftar terlebih dahulu.';
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_booking') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $bookingMode = $_POST['booking_mode'] ?? 'paket';
    $packageId = (int)($_POST['package_id'] ?? 0) ?: null;
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $pax = max(1, (int)($_POST['pax_count'] ?? 1));

    if ($customerId <= 0 || $startDate === '' || $endDate === '') {
        $_SESSION['flash_message'] = 'Customer, tanggal mulai, dan tanggal selesai wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php?action=add');
        exit;
    }

    $ticketKapalType = $_POST['ticket_kapal_type'] ?? 'none';
    $includeBtn = isset($_POST['include_btn_ticket']) ? 1 : 0;
    $transportNotes = trim($_POST['transport_notes'] ?? '');
    $mealNotes = trim($_POST['meal_notes'] ?? '');
    $islandTrip = isset($_POST['island_trip']) ? 1 : 0;
    $landTrip = isset($_POST['land_trip']) ? 1 : 0;
    $documentation = isset($_POST['documentation']) ? 1 : 0;

    $coordId = (int)($_POST['coordinator_id'] ?? 0) ?: null;
    $guideDaratId = (int)($_POST['guide_darat_id'] ?? 0) ?: null;
    $guideLautId = (int)($_POST['guide_laut_id'] ?? 0) ?: null;
    $accommodationManual = trim($_POST['accommodation_manual'] ?? '');

    $components = [];
    $costTotal = 0.0;
    $sellTotal = 0.0;

    // Komponen helper
    $pushComponent = function ($code, $name, $qty, $unit, $cost, $sell, $details = []) use (&$components, &$costTotal, &$sellTotal) {
        $totalCost = $qty * $cost;
        $totalSell = $qty * $sell;
        $costTotal += $totalCost;
        $sellTotal += $totalSell;
        $components[] = [
            'component_code' => $code,
            'component_name' => $name,
            'qty' => $qty,
            'unit' => $unit,
            'price_cost' => $cost,
            'price_sell' => $sell,
            'total_cost' => $totalCost,
            'total_sell' => $totalSell,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
        ];
    };

    // 1. Paket (jika mode paket dan dipilih)
    if ($bookingMode === 'paket' && $packageId) {
        $pkg = $pdo->prepare("SELECT name, base_price FROM trip_packages WHERE id=?");
        $pkg->execute([$packageId]);
        if ($row = $pkg->fetch()) {
            $baseSell = (float)$row['base_price'];
            $baseCost = $baseSell * 0.8; // estimasi modal awal, bisa disesuaikan nanti
            $pushComponent('paket', 'Paket: ' . $row['name'], $pax, 'pax', $baseCost, $baseSell, ['package_id' => $packageId]);
        }
    }

    // 2. Tiket kapal
    if ($ticketKapalType !== 'none') {
        $qty = postNum('ticket_kapal_qty') ?: $pax;
        $pushComponent('ticket_kapal', 'Tiket Kapal ' . strtoupper($ticketKapalType), $qty, 'pax', postNum('ticket_kapal_cost'), postNum('ticket_kapal_sell'), ['type' => $ticketKapalType]);
    }

    // 3. Tiket BTN
    if (isset($_POST['include_btn_ticket'])) {
        $qty = postNum('btn_ticket_qty') ?: $pax;
        $pushComponent('ticket_btn', 'Tiket BTN', $qty, 'pax', postNum('btn_ticket_cost'), postNum('btn_ticket_sell'));
    }

    // 4. Transport
    if ($transportNotes !== '' || postNum('transport_sell') > 0 || postNum('transport_cost') > 0) {
        $qty = postNum('transport_qty') ?: 1;
        $pushComponent('transport', 'Transportasi', $qty, 'trip', postNum('transport_cost'), postNum('transport_sell'), ['notes' => $transportNotes]);
    }

    // 5. Penginapan
    $roomId = (int)($_POST['room_id'] ?? 0);
    if ($roomId > 0) {
        $roomStmt = $pdo->prepare("SELECT r.room_type, r.price_cost, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.id=?");
        $roomStmt->execute([$roomId]);
        if ($room = $roomStmt->fetch()) {
            $nights = max(1, (int)($_POST['stay_nights'] ?? 1));
            $qty = max(1, (int)($_POST['stay_room_qty'] ?? 1));
            $unitQty = $nights * $qty;
            $pushComponent('penginapan', 'Penginapan: ' . $room['partner_name'] . ' - ' . $room['room_type'], $unitQty, 'room-night', (float)$room['price_cost'], (float)$room['price_sell']);
        }
    }

    // 6. Makan
    if ($mealNotes !== '' || postNum('meal_sell') > 0 || postNum('meal_cost') > 0) {
        $qty = postNum('meal_qty') ?: $pax;
        $pushComponent('makan', 'Makan', $qty, 'porsi', postNum('meal_cost'), postNum('meal_sell'), ['notes' => $mealNotes]);
    }

    // 7. Island hopping
    if ($islandTrip) {
        $qty = postNum('island_trip_qty') ?: 1;
        $pushComponent('island_trip', 'Trip Island Hopping', $qty, 'trip', postNum('island_trip_cost'), postNum('island_trip_sell'));
    }

    // 8. Trip darat
    if ($landTrip) {
        $qty = postNum('land_trip_qty') ?: 1;
        $pushComponent('land_trip', 'Trip Darat', $qty, 'trip', postNum('land_trip_cost'), postNum('land_trip_sell'));
    }

    // 9. Dokumentasi
    if ($documentation) {
        $qty = postNum('documentation_qty') ?: 1;
        $pushComponent('dokumentasi', 'Dokumentasi', $qty, 'paket', postNum('documentation_cost'), postNum('documentation_sell'));
    }

    // 10. Fasilitas tambahan
    if (!empty($_POST['facility_ids']) && is_array($_POST['facility_ids'])) {
        $facStmt = $pdo->prepare("SELECT id, name, unit, price_cost, price_sell FROM facilities WHERE id=?");
        foreach ($_POST['facility_ids'] as $fid) {
            $fid = (int)$fid;
            if ($fid <= 0) continue;
            $facStmt->execute([$fid]);
            if ($fac = $facStmt->fetch()) {
                $qtyMap = postNum('facility_qty_' . $fid);
                $qty = $qtyMap > 0 ? $qtyMap : 1;
                $pushComponent('fasilitas', 'Fasilitas: ' . $fac['name'], $qty, $fac['unit'] ?: 'unit', (float)$fac['price_cost'], (float)$fac['price_sell'], ['facility_id' => $fid]);
            }
        }
    }

    $margin = $sellTotal - $costTotal;
    $createdBy = $auth->getCurrentUser()['username'] ?? 'system';
    $bookingNo = sunseaNextNumber($pdo, 'booking');

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO booking_orders
            (booking_no, customer_id, booking_mode, package_id, start_date, end_date, pax_count,
             ticket_kapal_type, include_btn_ticket, transport_notes, meal_notes, island_trip, land_trip, documentation,
             coordinator_id, guide_darat_id, guide_laut_id, status, cost_total, sell_total, margin_amount, notes, accommodation_manual, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $bookingNo,
                $customerId,
                $bookingMode,
                $packageId,
                $startDate,
                $endDate,
                $pax,
                $ticketKapalType,
                $includeBtn,
                $transportNotes,
                $mealNotes,
                $islandTrip,
                $landTrip,
                $documentation,
                $coordId,
                $guideDaratId,
                $guideLautId,
                'draft',
                $costTotal,
                $sellTotal,
                $margin,
                trim($_POST['notes'] ?? ''),
                $accommodationManual,
                $createdBy
            ]);
        $bookingId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO booking_order_items
            (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, details_json, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");

        foreach ($components as $idx => $c) {
            $ins->execute([
                $bookingId,
                $c['component_code'],
                $c['component_name'],
                $c['qty'],
                $c['unit'],
                $c['price_cost'],
                $c['price_sell'],
                $c['total_cost'],
                $c['total_sell'],
                $c['details_json'],
                $idx,
            ]);
        }

        // Generate schedule rows from date range
        $cur = strtotime($startDate);
        $end = strtotime($endDate);
        $sched = $pdo->prepare("INSERT INTO booking_schedule (booking_id, activity_date, activity_type, title, notes) VALUES (?,?,?,?,?)");
        while ($cur <= $end) {
            $date = date('Y-m-d', $cur);
            $sched->execute([$bookingId, $date, 'other', 'Operasional ' . $bookingNo, 'Auto-generated schedule']);
            $cur = strtotime('+1 day', $cur);
        }

        $pdo->commit();
        $_SESSION['flash_message'] = 'Pemesanan berhasil disimpan: ' . $bookingNo;
        $_SESSION['flash_type'] = 'success';
        header('Location: bookings.php?view=' . $bookingId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = 'Gagal simpan pemesanan: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php?action=add');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');

    if ($newStatus === 'cancel') {
        $newStatus = 'cancelled';
    }

    $allowed = ['draft', 'confirmed', 'cancelled'];
    if ($bookingId > 0 && in_array($newStatus, $allowed, true)) {
        try {
            $pdo->prepare("UPDATE booking_orders SET status=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $bookingId]);
            $_SESSION['flash_message'] = 'Status reservasi berhasil diperbarui.';
            $_SESSION['flash_type'] = 'success';

            // Saat dikonfirmasi, otomatis siapkan invoice-nya juga (booking sudah otomatis tampil di kalender karena status confirmed).
            if ($newStatus === 'confirmed') {
                $bStmt = $pdo->prepare("SELECT id, booking_no, customer_id, start_date, end_date, pax_count FROM booking_orders WHERE id=?");
                $bStmt->execute([$bookingId]);
                $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
                if ($bRow) {
                    try {
                        ensureInvoiceFromBooking($pdo, $auth, $bRow);
                        $_SESSION['flash_message'] = 'Status dikonfirmasi, masuk kalender booking, dan invoice otomatis dibuat.';
                    } catch (Exception $e) {
                        $_SESSION['flash_message'] = 'Status dikonfirmasi, tapi invoice gagal dibuat otomatis: ' . $e->getMessage();
                        $_SESSION['flash_type'] = 'error';
                    }
                }
            }
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal update status: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }

    $redirectTo = 'bookings.php';
    if (!empty($_POST['return_view'])) {
        $redirectTo .= '?view=' . $bookingId;
    }
    header('Location: ' . $redirectTo);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_booking') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $stStmt = $pdo->prepare("SELECT status FROM booking_orders WHERE id=?");
        $stStmt->execute([$bookingId]);
        $bkStatus = $stStmt->fetchColumn();

        if ($bkStatus !== 'cancelled') {
            $_SESSION['flash_message'] = 'Hanya reservasi berstatus Cancel yang bisa dihapus.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $internalRef = 'booking_id:' . $bookingId;
            $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE internal_notes=?");
            $paidStmt->execute([$internalRef]);
            if ((float)$paidStmt->fetchColumn() > 0) {
                $_SESSION['flash_message'] = 'Reservasi tidak bisa dihapus karena sudah ada pembayaran invoice. Batalkan/refund invoice dulu.';
                $_SESSION['flash_type'] = 'error';
            } else {
                try {
                    $pdo->prepare("DELETE FROM cash_book WHERE booking_id=?")->execute([$bookingId]);
                    $pdo->prepare("DELETE FROM invoices WHERE internal_notes=?")->execute([$internalRef]);
                    $pdo->prepare("DELETE FROM booking_schedule WHERE booking_id=?")->execute([$bookingId]);
                    $pdo->prepare("DELETE FROM booking_order_items WHERE booking_id=?")->execute([$bookingId]);
                    $pdo->prepare("DELETE FROM booking_orders WHERE id=?")->execute([$bookingId]);
                    $_SESSION['flash_message'] = 'Reservasi berhasil dihapus.';
                    $_SESSION['flash_type'] = 'success';
                } catch (Exception $e) {
                    $_SESSION['flash_message'] = 'Gagal hapus reservasi: ' . $e->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
    }
    header('Location: bookings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_booking') {
    $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
    $deleted = 0;
    $skipped = 0;
    foreach ($ids as $bookingId) {
        $stStmt = $pdo->prepare("SELECT status FROM booking_orders WHERE id=?");
        $stStmt->execute([$bookingId]);
        $bkStatus = $stStmt->fetchColumn();

        if ($bkStatus !== 'cancelled') {
            $skipped++;
            continue;
        }

        $internalRef = 'booking_id:' . $bookingId;
        $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM invoices WHERE internal_notes=?");
        $paidStmt->execute([$internalRef]);
        if ((float)$paidStmt->fetchColumn() > 0) {
            $skipped++;
            continue;
        }

        try {
            $pdo->prepare("DELETE FROM cash_book WHERE booking_id=?")->execute([$bookingId]);
            $pdo->prepare("DELETE FROM invoices WHERE internal_notes=?")->execute([$internalRef]);
            $pdo->prepare("DELETE FROM booking_schedule WHERE booking_id=?")->execute([$bookingId]);
            $pdo->prepare("DELETE FROM booking_order_items WHERE booking_id=?")->execute([$bookingId]);
            $pdo->prepare("DELETE FROM booking_orders WHERE id=?")->execute([$bookingId]);
            $deleted++;
        } catch (Exception $e) {
            $skipped++;
        }
    }

    $msg = $deleted . ' reservasi berhasil dihapus.';
    if ($skipped > 0) {
        $msg .= ' ' . $skipped . ' dilewati (bukan status Cancel atau sudah ada pembayaran).';
    }
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_type'] = $deleted > 0 ? 'success' : 'error';
    header('Location: bookings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_checklist') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $doneIds = array_map('intval', $_POST['done_items'] ?? []);

    if ($bookingId > 0) {
        try {
            $itemsStmt = $pdo->prepare("SELECT id FROM booking_order_items WHERE booking_id=?");
            $itemsStmt->execute([$bookingId]);
            $allIds = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);
            $upd = $pdo->prepare("UPDATE booking_order_items SET is_done=? WHERE id=?");
            foreach ($allIds as $iid) {
                $upd->execute([in_array((int)$iid, $doneIds, true) ? 1 : 0, (int)$iid]);
            }
            $_SESSION['flash_message'] = 'Checklist koordinator berhasil disimpan.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal simpan checklist: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_mitra_paid') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $paidIds = array_map('intval', $_POST['paid_items'] ?? []);

    if ($bookingId > 0) {
        try {
            $bkStmt = $pdo->prepare("SELECT bo.booking_no, c.name AS customer_name FROM booking_orders bo LEFT JOIN customers c ON c.id = bo.customer_id WHERE bo.id=?");
            $bkStmt->execute([$bookingId]);
            $bk = $bkStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $bookingNo = $bk['booking_no'] ?? ('#' . $bookingId);
            $customerName = $bk['customer_name'] ?? '-';

            $itemsStmt = $pdo->prepare("SELECT id, component_name, total_cost, is_paid_mitra FROM booking_order_items WHERE booking_id=? AND component_code != 'paket'");
            $itemsStmt->execute([$bookingId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $upd = $pdo->prepare("UPDATE booking_order_items SET is_paid_mitra=? WHERE id=?");
            $user = $auth->getCurrentUser()['username'] ?? 'system';

            foreach ($items as $it) {
                $itemId = (int)$it['id'];
                $wasPaid = !empty($it['is_paid_mitra']);
                $nowPaid = in_array($itemId, $paidIds, true);
                $upd->execute([$nowPaid ? 1 : 0, $itemId]);

                if ($nowPaid && !$wasPaid) {
                    // Catat pengeluaran ke Finance hanya sekali per item mitra
                    $exists = $pdo->prepare("SELECT COUNT(*) FROM cash_book WHERE booking_item_id=?");
                    $exists->execute([$itemId]);
                    if ((int)$exists->fetchColumn() === 0) {
                        $pdo->prepare("
                            INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, booking_id, booking_item_id, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?)
                        ")->execute([
                            date('Y-m-d'),
                            'expense',
                            'Pembayaran Mitra',
                            "Pembayaran Mitra: {$it['component_name']} — $bookingNo ($customerName)",
                            (float)$it['total_cost'],
                            $bookingNo,
                            $bookingId,
                            $itemId,
                            $user,
                        ]);
                    }
                } elseif (!$nowPaid && $wasPaid) {
                    // Batal centang: hapus kembali catatan pengeluaran otomatis
                    $pdo->prepare("DELETE FROM cash_book WHERE booking_item_id=?")->execute([$itemId]);
                }
            }

            $_SESSION['flash_message'] = 'Checklist pembayaran mitra berhasil disimpan & pengeluaran tercatat di Finance.';
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['flash_message'] = 'Gagal simpan checklist mitra: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }
    }

    header('Location: bookings.php?view=' . $bookingId);
    exit;
}

$action = $_GET['action'] ?? 'list';
$viewId = (int)($_GET['view'] ?? 0);
$pageError = '';

if ($action === 'print_invoice' || $action === 'pay_invoice') {
    $bookingId = (int)($_GET['id'] ?? 0);
    if ($bookingId > 0) {
        $bookingStmt = $pdo->prepare("SELECT id, booking_no, customer_id, start_date, end_date, pax_count FROM booking_orders WHERE id=?");
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            try {
                $invoiceId = ensureInvoiceFromBooking($pdo, $auth, $booking);
                if ($action === 'print_invoice') {
                    header('Location: invoices.php?action=print&id=' . $invoiceId);
                    exit;
                }

                $payMode = ($_GET['pay_mode'] ?? 'dp') === 'full' ? 'full' : 'dp';
                header('Location: invoices.php?action=view&id=' . $invoiceId . '&open_payment=1&pay_mode=' . $payMode);
                exit;
            } catch (Exception $e) {
                $_SESSION['flash_message'] = 'Gagal menyiapkan invoice pembayaran: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
                header('Location: bookings.php?view=' . (int)$booking['id']);
                exit;
            }
        }
    }

    $_SESSION['flash_message'] = 'Data booking tidak ditemukan.';
    $_SESSION['flash_type'] = 'error';
    header('Location: bookings.php');
    exit;
}

if ($action === 'add') {
    // Use DB-driven reservation form so layanan always sync from master data.
    header('Location: bookings-new.php');
    exit;
}

$customers = safeFetchAll($pdo, "SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name", [], 'customer');
$packages = [];
$partnersRooms = [];
$guidesDarat = [];
$guidesLaut = [];
$coordinators = [];
$facilities = [];

$list = safeFetchAll(
    $pdo,
    "SELECT b.*, c.name as customer_name, c.phone as customer_phone, p.name as package_name,
        COALESCE((SELECT SUM(i.paid_amount) FROM invoices i WHERE i.internal_notes = CONCAT('booking_id:', b.id)), 0) AS paid_amount_total,
        (SELECT component_name FROM booking_order_items WHERE booking_id=b.id AND component_code IN ('penginapan','accommodation') ORDER BY sort_order LIMIT 1) AS accommodation_item
    FROM booking_orders b
    JOIN customers c ON c.id=b.customer_id
    LEFT JOIN trip_packages p ON p.id=b.package_id
    ORDER BY b.created_at DESC
    LIMIT 200",
    [],
    'list booking'
);

$detail = null;
$detailItems = [];
$roomMasterOptions = [];
$transportMasterOptions = [];
if ($viewId > 0) {
    $roomMasterOptions = safeFetchAll($pdo, "SELECT r.id, r.room_type, r.price_cost, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type", [], 'kamar penginapan');
    $transportMasterOptions = safeFetchAll($pdo, "SELECT id, name, unit, price_cost, price_sell FROM transport_items WHERE is_active=1 ORDER BY transport_type, name", [], 'transportasi');

    $detail = safeFetchOne($pdo, "SELECT b.*, c.name as customer_name, c.phone as customer_phone,
        p.name as package_name,
        cd.name as coordinator_name,
        gd.name as guide_darat_name,
        gl.name as guide_laut_name
        FROM booking_orders b
        JOIN customers c ON c.id=b.customer_id
        LEFT JOIN trip_packages p ON p.id=b.package_id
        LEFT JOIN coordinators cd ON cd.id=b.coordinator_id
        LEFT JOIN guides gd ON gd.id=b.guide_darat_id
        LEFT JOIN guides gl ON gl.id=b.guide_laut_id
        WHERE b.id=?", [$viewId], 'detail booking');

    $detailItems = safeFetchAll($pdo, "SELECT * FROM booking_order_items WHERE booking_id=? ORDER BY sort_order", [$viewId], 'item booking');
}

$pageTitle = 'Pemesanan';
$activePage = 'bookings';
include 'layout-header.php';
?>

<?php if ($pageError): ?>
    <div class="ss-alert ss-alert-error" style="margin-bottom:14px;">
        <i data-feather="alert-triangle"></i>
        <?php echo htmlspecialchars($pageError); ?>
    </div>
<?php endif; ?>

<?php if ($detail):
    $pendingCount = 0;
    foreach ($detailItems as $it) {
        if (empty($it['is_done'])) $pendingCount++;
    }
    $mitraItems = array_values(array_filter($detailItems, fn($it) => $it['component_code'] !== 'paket'));
    // 'pkg_detail' = rincian modal internal paket (sudah tampil di Rekap Pengeluaran Mitra di atas), jangan dobel di tabel Harga Jual.
    $sellItems = array_values(array_filter($detailItems, fn($it) => $it['component_code'] !== 'pkg_detail'));
    $mitraCostTotal = 0;
    foreach ($mitraItems as $it) $mitraCostTotal += (float)$it['total_cost'];
    $mitraUnpaidCount = 0;
    $mitraPaidTotal = 0;
    foreach ($mitraItems as $it) {
        if (empty($it['is_paid_mitra'])) {
            $mitraUnpaidCount++;
        } else {
            $mitraPaidTotal += (float)$it['total_cost'];
        }
    }

    // Samakan sumber angka finance dengan modal detail di Kalender: RAB dari item, Pengeluaran dari transaksi Finance asli (cash_book), bukan cost_total/margin_amount statis.
    $detailExpenses = safeFetchAll($pdo, "SELECT transaction_date, category, description, amount FROM cash_book WHERE booking_id=? AND type='expense' ORDER BY transaction_date, id", [$viewId], 'expense booking');
    $totalExpenseActual = 0;
    foreach ($detailExpenses as $ex) $totalExpenseActual += (float)$ex['amount'];
    $totalRabActual = 0;
    foreach ($detailItems as $it) $totalRabActual += (float)$it['total_sell'];
    $marginActual = $totalRabActual - $totalExpenseActual;
    $marginPct = $totalRabActual > 0 ? round($marginActual / $totalRabActual * 100) : 0;

    // Riwayat pembayaran/DP: invoice booking ini bisa dibayar bertahap (DP 1, DP 2, pelunasan, dst) di tabel payments.
    $detailInvStmt = $pdo->prepare("SELECT id, total_amount FROM invoices WHERE internal_notes = ? OR internal_notes = ?");
    $detailInvStmt->execute(['booking_id:' . $viewId, 'Generated from Reservasi: ' . $detail['booking_no']]);
    $detailBookingInvoices = $detailInvStmt->fetchAll();

    $detailPayments = [];
    $totalInvoiceAmountActual = 0;
    foreach ($detailBookingInvoices as $dbi) $totalInvoiceAmountActual += (float)$dbi['total_amount'];
    if ($detailBookingInvoices) {
        $detailInvIds = array_column($detailBookingInvoices, 'id');
        $detailPh = implode(',', array_fill(0, count($detailInvIds), '?'));
        $detailPayStmt = $pdo->prepare("SELECT payment_date, amount, method, reference FROM payments WHERE invoice_id IN ($detailPh) ORDER BY payment_date, id");
        $detailPayStmt->execute($detailInvIds);
        $detailPayments = $detailPayStmt->fetchAll();
    }
    $totalPaidActual = 0;
    foreach ($detailPayments as $dp) $totalPaidActual += (float)$dp['amount'];
    $remainingPaymentActual = max(0, $totalInvoiceAmountActual - $totalPaidActual);
?>
    <div style="margin-bottom:14px;"><a class="ss-btn ss-btn-outline ss-btn-sm" href="bookings.php"><i data-feather="arrow-left"></i> Kembali</a></div>

    <!-- 1. Identitas Booking: nama tamu, nomor, tanggal, status, tim lapangan -->
    <div class="ss-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:4px;">
            <div>
                <div style="font-size:20px;font-weight:800;color:var(--ss-text);"><?php echo htmlspecialchars($detail['customer_name']); ?></div>
                <div style="font-size:12px;color:var(--ss-muted);margin-top:2px;">
                    <?php echo htmlspecialchars($detail['booking_no']); ?> · <?php echo date('d M Y', strtotime($detail['start_date'])); ?> - <?php echo date('d M Y', strtotime($detail['end_date'])); ?> · <?php echo (int)$detail['pax_count']; ?> pax
                </div>
            </div>
            <span class="ss-status ss-status-<?php echo $detail['status'] === 'completed' ? 'approved' : ($detail['status'] === 'cancelled' ? 'rejected' : ($detail['status'] === 'draft' ? 'draft' : 'sent')); ?>" style="font-size:12px;"><?php echo $detail['status'] === 'draft' ? 'Pending' : ucfirst($detail['status']); ?></span>
        </div>
        <?php if (!empty($detail['notes'])): ?>
            <div style="font-size:12.5px;color:var(--ss-muted);margin-top:8px;padding-top:8px;border-top:1px solid var(--ss-gray-2);"><?php echo nl2br(htmlspecialchars($detail['notes'])); ?></div>
        <?php endif; ?>
        <div style="display:flex;gap:22px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--ss-gray-2);font-size:12.5px;">
            <div><span style="color:var(--ss-muted);">Koordinator: </span><strong><?php echo htmlspecialchars($detail['coordinator_name'] ?: '-'); ?></strong></div>
            <div><span style="color:var(--ss-muted);">Guide Darat: </span><strong><?php echo htmlspecialchars($detail['guide_darat_name'] ?: '-'); ?></strong></div>
            <div><span style="color:var(--ss-muted);">Guide Laut: </span><strong><?php echo htmlspecialchars($detail['guide_laut_name'] ?: '-'); ?></strong></div>
            <?php if (!empty($detail['accommodation_manual'])): ?>
                <div><span style="color:var(--ss-muted);">Penginapan: </span><strong><?php echo htmlspecialchars($detail['accommodation_manual']); ?></strong></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2. Ringkasan Keuangan: disamakan dengan modal detail di Kalender (RAB item vs pengeluaran nyata di Finance) -->
    <div class="ss-card" style="margin-bottom:14px;">
        <div class="ss-card-title" style="margin-bottom:12px;">💰 Ringkasan Keuangan</div>
        <div style="display:grid;grid-template-columns:150px 1fr;gap:22px;align-items:center;">
            <div style="position:relative;width:150px;height:150px;">
                <canvas id="bookingFinancePie" width="150" height="150"></canvas>
            </div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
                <div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--ss-muted);"><span style="width:9px;height:9px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Total Pengeluaran (Finance)</div>
                    <div style="font-size:17px;font-weight:800;margin-top:4px;"><?php echo sunseaRupiah($totalExpenseActual); ?></div>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--ss-muted);"><span style="width:9px;height:9px;border-radius:50%;background:var(--ss-success);display:inline-block;"></span> Margin (<?php echo $marginPct; ?>%)</div>
                    <div style="font-size:17px;font-weight:800;margin-top:4px;color:var(--ss-success);"><?php echo sunseaRupiah($marginActual); ?></div>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--ss-muted);"><span style="width:9px;height:9px;border-radius:50%;background:var(--ss-ocean);display:inline-block;"></span> Total RAB/Penawaran</div>
                    <div style="font-size:17px;font-weight:800;margin-top:4px;color:var(--ss-ocean);"><?php echo sunseaRupiah($totalRabActual); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2b. Pengeluaran Trip Ini (dari Finance): daftar transaksi cash_book nyata, sama seperti modal Kalender -->
    <div class="ss-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div class="ss-card-title" style="margin:0;">🧾 Pengeluaran Trip Ini (dari Finance)</div>
            <a href="finance.php?customer_id=<?php echo (int)$detail['customer_id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm">Lihat di Finance</a>
        </div>
        <?php if (empty($detailExpenses)): ?>
            <div style="font-size:12px;color:var(--ss-muted);">Belum ada pengeluaran dicatat di Finance untuk trip ini.</div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th style="white-space:nowrap;">Tanggal</th>
                            <th>Keterangan</th>
                            <th style="width:130px;white-space:nowrap;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailExpenses as $ex): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo htmlspecialchars($ex['transaction_date']); ?></td>
                                <td><?php echo htmlspecialchars($ex['description']); ?><?php if (!empty($ex['category'])): ?><br><small style="color:var(--ss-muted);"><?php echo htmlspecialchars($ex['category']); ?></small><?php endif; ?></td>
                                <td style="font-weight:600;color:var(--ss-danger);white-space:nowrap;"><?php echo sunseaRupiah((float)$ex['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--ss-gray-2);">
                            <td colspan="2" style="text-align:right;"><strong>Total Pengeluaran</strong></td>
                            <td style="font-weight:700;color:var(--ss-danger);white-space:nowrap;"><?php echo sunseaRupiah($totalExpenseActual); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- 2c. Riwayat Pembayaran (DP): sama seperti modal detail di Kalender, tampilkan semua tahap DP -->
    <div class="ss-card" style="margin-bottom:14px;">
        <div class="ss-card-title" style="margin-bottom:10px;">💳 Riwayat Pembayaran (DP)</div>
        <?php if (empty($detailPayments)): ?>
            <div style="font-size:12px;color:var(--ss-muted);">Belum ada pembayaran/DP tercatat untuk booking ini.</div>
        <?php else: ?>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th style="width:100px;">Tahap</th>
                            <th style="white-space:nowrap;">Tanggal</th>
                            <th>Metode</th>
                            <th style="width:130px;white-space:nowrap;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detailPayments as $dpIdx => $dp):
                            $dpIsLast = $dpIdx === count($detailPayments) - 1;
                            $dpStage = $dpIdx === 0 ? 'DP 1' : ($dpIsLast && $remainingPaymentActual <= 0 ? 'Pelunasan' : 'DP ' . ($dpIdx + 1));
                        ?>
                            <tr>
                                <td><strong><?php echo $dpStage; ?></strong></td>
                                <td style="white-space:nowrap;"><?php echo date('d M Y', strtotime($dp['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($dp['method'] ?: '-')); ?></td>
                                <td style="font-weight:600;color:var(--ss-success);white-space:nowrap;"><?php echo sunseaRupiah((float)$dp['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--ss-gray-2);">
                            <td colspan="3" style="text-align:right;"><strong>Total Dibayar</strong></td>
                            <td style="font-weight:700;color:var(--ss-success);white-space:nowrap;"><?php echo sunseaRupiah($totalPaidActual); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right;"><strong>Sisa Tagihan</strong></td>
                            <td style="font-weight:700;white-space:nowrap;color:<?php echo $remainingPaymentActual > 0 ? 'var(--ss-danger)' : 'var(--ss-success)'; ?>;"><?php echo $remainingPaymentActual > 0 ? sunseaRupiah($remainingPaymentActual) : '✓ Lunas'; ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. Rekap Pengeluaran: khusus biaya ke mitra, terpisah dari ringkasan keuangan -->
    <div class="ss-card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div class="ss-card-title" style="margin:0;">📋 Rekap Pengeluaran (Pembayaran ke Mitra)</div>
            <?php if (!empty($mitraItems)): ?>
                <?php if ($mitraUnpaidCount > 0): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#dc2626;font-weight:700;"><span style="width:8px;height:8px;border-radius:50%;background:#dc2626;display:inline-block;"></span> <?php echo $mitraUnpaidCount; ?> belum dibayar</span>
                <?php else: ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#16a34a;font-weight:700;"><span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;"></span> Semua lunas</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (empty($mitraItems)): ?>
            <div style="font-size:12px;color:var(--ss-muted);">Belum ada detail layanan mitra. Isi "Detail Layanan dalam Paket" di menu Paket Wisata agar tagihan mitra (tiket kapal, penginapan, rental, dll) tampil di sini.</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="update_mitra_paid">
                <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <thead>
                            <tr>
                                <th>Komponen</th>
                                <th>Pengeluaran</th>
                                <th>Status Bayar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mitraItems as $it): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($it['component_name']); ?></td>
                                    <td><strong><?php echo sunseaRupiah((float)$it['total_cost']); ?></strong></td>
                                    <td>
                                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;">
                                            <input type="checkbox" name="paid_items[]" value="<?php echo (int)$it['id']; ?>" <?php echo !empty($it['is_paid_mitra']) ? 'checked' : ''; ?>>
                                            <span style="<?php echo !empty($it['is_paid_mitra']) ? 'color:#16a34a;font-weight:700;' : 'color:var(--ss-muted);'; ?>"><?php echo !empty($it['is_paid_mitra']) ? 'Lunas' : 'Belum bayar'; ?></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="border-top:2px solid var(--ss-gray-2);">
                                <td><strong>Total Pengeluaran Mitra</strong></td>
                                <td colspan="2"><strong><?php echo sunseaRupiah((float)$detail['cost_total']); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="margin-top:10px;"><i data-feather="save"></i> Simpan Pembayaran Mitra</button>
            </form>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;">
        <div class="ss-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div class="ss-card-title" style="margin:0;">Rincian Layanan (Harga Jual)</div>
                <button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="var p=document.getElementById('editItemsPanel');p.style.display=(p.style.display==='none'?'block':'none');this.querySelector('span').textContent=(p.style.display==='none'?'Edit':'Tutup Edit');"><i data-feather="edit-2"></i> <span>Edit</span></button>
            </div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Komponen</th>
                            <th>Qty</th>
                            <th>Modal</th>
                            <th>Jual</th>
                            <th>Total Jual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sellItems as $it): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($it['component_name']); ?></td>
                                <td><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($it['unit']); ?></td>
                                <td><?php echo sunseaRupiah($it['component_code'] === 'paket' ? $mitraCostTotal : (float)$it['total_cost']); ?></td>
                                <td><?php echo sunseaRupiah((float)$it['price_sell']); ?></td>
                                <td><strong><?php echo sunseaRupiah((float)$it['total_sell']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sellItems)): ?>
                            <tr>
                                <td colspan="5" style="color:var(--ss-muted);">Belum ada layanan yang dijual pada pesanan ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="font-size:11px;color:var(--ss-muted);margin-top:6px;">* Rincian modal per komponen paket (tiket kapal, penginapan, transport, dll) ada di kartu "Rekap Pengeluaran (Pembayaran ke Mitra)" di atas.</div>

            <div id="editItemsPanel" style="display:none;margin-top:14px;padding-top:4px;">
                <div style="background:#F8FAFC;border:1px solid var(--ss-gray-2);border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                    <div class="ss-card-title" style="margin:0 0 10px;font-size:13px;display:flex;align-items:center;gap:6px;"><i data-feather="calendar" style="width:15px;height:15px;"></i> Edit Tanggal Trip</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_dates">
                        <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                        <div class="ss-form-grid cols-2">
                            <div class="ss-form-group"><label class="ss-label">Tanggal Mulai</label><input type="date" class="ss-input" name="start_date" value="<?php echo htmlspecialchars($detail['start_date']); ?>" required></div>
                            <div class="ss-form-group"><label class="ss-label">Tanggal Selesai</label><input type="date" class="ss-input" name="end_date" value="<?php echo htmlspecialchars($detail['end_date']); ?>" required></div>
                        </div>
                        <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="margin-top:6px;"><i data-feather="save"></i> Simpan Tanggal</button>
                    </form>
                </div>

                <div style="background:#F8FAFC;border:1px solid var(--ss-gray-2);border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                    <div class="ss-card-title" style="margin:0 0 10px;font-size:13px;display:flex;align-items:center;gap:6px;"><i data-feather="refresh-cw" style="width:15px;height:15px;"></i> Ganti Penginapan / Transport</div>
                    <div style="font-size:11.5px;color:var(--ss-muted);margin:-4px 0 10px;">Pilih dari database, harga modal &amp; jual otomatis menyesuaikan. Item lama dengan kategori yang sama akan diganti otomatis.</div>
                    <div class="ss-form-grid cols-2">
                        <form method="POST" style="display:contents;">
                            <input type="hidden" name="action" value="replace_room_transport">
                            <input type="hidden" name="ref_type" value="room">
                            <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                            <div class="ss-form-group" style="grid-column:1/-1;">
                                <label class="ss-label">Penginapan</label>
                                <select name="ref_id" class="ss-select" required>
                                    <option value="">-- pilih kamar/homestay --</option>
                                    <?php foreach ($roomMasterOptions as $rm): ?>
                                        <option value="<?php echo (int)$rm['id']; ?>">
                                            <?php echo htmlspecialchars($rm['partner_name'] . ' - ' . $rm['room_type']); ?>
                                            (Modal <?php echo sunseaRupiah((float)$rm['price_cost']); ?> / Jual <?php echo sunseaRupiah((float)$rm['price_sell']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ss-form-group"><label class="ss-label">Qty (kamar × malam)</label><input class="ss-input" name="qty" type="number" min="1" step="1" value="1"></div>
                            <div class="ss-form-group" style="display:flex;align-items:flex-end;">
                                <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="width:100%;"><i data-feather="check"></i> Ganti Penginapan</button>
                            </div>
                        </form>
                    </div>
                    <div class="ss-form-grid cols-2" style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--ss-gray-2);">
                        <form method="POST" style="display:contents;">
                            <input type="hidden" name="action" value="replace_room_transport">
                            <input type="hidden" name="ref_type" value="transport">
                            <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                            <div class="ss-form-group" style="grid-column:1/-1;">
                                <label class="ss-label">Layanan Transport</label>
                                <select name="ref_id" class="ss-select" required>
                                    <option value="">-- pilih layanan transport --</option>
                                    <?php foreach ($transportMasterOptions as $tr): ?>
                                        <option value="<?php echo (int)$tr['id']; ?>">
                                            <?php echo htmlspecialchars($tr['name']); ?>
                                            (Modal <?php echo sunseaRupiah((float)$tr['price_cost']); ?> / Jual <?php echo sunseaRupiah((float)$tr['price_sell']); ?> per <?php echo htmlspecialchars($tr['unit']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ss-form-group"><label class="ss-label">Qty</label><input class="ss-input" name="qty" type="number" min="1" step="1" value="1"></div>
                            <div class="ss-form-group" style="display:flex;align-items:flex-end;">
                                <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="width:100%;"><i data-feather="check"></i> Ganti Transport</button>
                            </div>
                        </form>
                    </div>
                    <?php if (empty($roomMasterOptions) && empty($transportMasterOptions)): ?>
                        <div style="font-size:11.5px;color:var(--ss-muted);margin-top:8px;">Belum ada data penginapan/transport di master data. Tambahkan dulu di menu Pengaturan.</div>
                    <?php endif; ?>
                </div>

                <div style="background:#F8FAFC;border:1px solid var(--ss-gray-2);border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                    <div class="ss-card-title" style="margin:0 0 10px;font-size:13px;display:flex;align-items:center;gap:6px;"><i data-feather="dollar-sign" style="width:15px;height:15px;"></i> Edit Harga Jual / Markup</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_item_prices">
                        <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                        <div class="ss-table-wrap">
                            <table class="ss-table">
                                <thead>
                                    <tr>
                                        <th>Komponen</th>
                                        <th>Qty</th>
                                        <th>Modal</th>
                                        <th>Harga Jual (per unit)</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($detailItems as $it): ?>
                                        <tr>
                                            <input type="hidden" name="item_id[]" value="<?php echo (int)$it['id']; ?>">
                                            <td><?php echo htmlspecialchars($it['component_name']); ?></td>
                                            <td><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.'); ?> <?php echo htmlspecialchars($it['unit']); ?></td>
                                            <td><?php echo sunseaRupiah((float)$it['price_cost']); ?></td>
                                            <td><input class="ss-input" name="price_sell[]" value="<?php echo (float)$it['price_sell']; ?>" style="max-width:150px;"></td>
                                            <td>
                                                <button type="button" class="ss-btn ss-btn-outline ss-btn-sm" style="color:#dc2626;border-color:#dc2626;" onclick="if(confirm('Hapus layanan ini dari pesanan?')){document.getElementById('deleteItemForm_<?php echo (int)$it['id']; ?>').submit();}"><i data-feather="trash-2"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($detailItems)): ?>
                                        <tr>
                                            <td colspan="5" style="color:var(--ss-muted);">Belum ada layanan pada pesanan ini.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="margin-top:10px;"><i data-feather="save"></i> Simpan Perubahan Harga</button>
                    </form>

                    <?php foreach ($detailItems as $it): ?>
                        <form method="POST" id="deleteItemForm_<?php echo (int)$it['id']; ?>" style="display:none;">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                            <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                        </form>
                    <?php endforeach; ?>
                </div>

                <div style="background:#F8FAFC;border:1px solid var(--ss-gray-2);border-radius:10px;padding:14px 16px;">
                    <div class="ss-card-title" style="margin:0 0 10px;font-size:13px;display:flex;align-items:center;gap:6px;"><i data-feather="plus-circle" style="width:15px;height:15px;"></i> Tambah Layanan Lain</div>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_item">
                        <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                        <div class="ss-form-grid cols-2">
                            <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Nama Layanan</label><input class="ss-input" name="component_name" placeholder="Contoh: Sewa Mobil" required></div>
                            <div class="ss-form-group"><label class="ss-label">Qty</label><input class="ss-input" name="qty" value="1"></div>
                            <div class="ss-form-group"><label class="ss-label">Satuan</label><input class="ss-input" name="unit" value="unit"></div>
                            <div class="ss-form-group"><label class="ss-label">Harga Modal (per unit)</label><input class="ss-input" name="price_cost" placeholder="0"></div>
                            <div class="ss-form-group"><label class="ss-label">Harga Jual (per unit)</label><input class="ss-input" name="price_sell" placeholder="0"></div>
                        </div>
                        <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="margin-top:6px;"><i data-feather="plus"></i> Tambah Layanan</button>
                    </form>
                </div>
            </div>
        </div>
        <div>
            <div class="ss-card" style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <div class="ss-card-title" style="margin:0;">Koordinator</div>
                    <?php if ($pendingCount > 0): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#dc2626;font-weight:700;"><span style="width:8px;height:8px;border-radius:50%;background:#dc2626;display:inline-block;"></span> <?php echo $pendingCount; ?> belum selesai</span>
                    <?php else: ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#16a34a;font-weight:700;"><span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;"></span> Semua selesai</span>
                    <?php endif; ?>
                </div>

                <?php if ($detail['status'] !== 'confirmed'): ?>
                    <div style="font-size:12px;color:var(--ss-muted);">Checklist koordinator tersedia setelah booking berstatus Confirmed.</div>
                <?php else: ?>
                    <button type="button" class="ss-btn ss-btn-outline ss-btn-sm" onclick="var p=document.getElementById('koordinatorPanel');p.style.display=(p.style.display==='none'?'block':'none');"><i data-feather="check-square"></i> Koordinator</button>
                    <div id="koordinatorPanel" style="display:none;margin-top:10px;">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_checklist">
                            <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                            <?php foreach ($detailItems as $it): ?>
                                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--ss-gray-2);">
                                    <input type="checkbox" name="done_items[]" value="<?php echo (int)$it['id']; ?>" <?php echo !empty($it['is_done']) ? 'checked' : ''; ?>>
                                    <span style="<?php echo !empty($it['is_done']) ? 'text-decoration:line-through;color:var(--ss-muted);' : ''; ?>"><?php echo htmlspecialchars($it['component_name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($detailItems)): ?>
                                <div style="font-size:12px;color:var(--ss-muted);padding:6px 0;">Belum ada item / layanan pada reservasi ini.</div>
                            <?php endif; ?>
                            <button class="ss-btn ss-btn-primary ss-btn-sm" type="submit" style="margin-top:10px;"><i data-feather="save"></i> Simpan Checklist</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ss-card">
                <div class="ss-card-title" style="margin-bottom:8px;">Status &amp; Aksi</div>
                <form method="POST" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="booking_id" value="<?php echo (int)$detail['id']; ?>">
                    <input type="hidden" name="return_view" value="1">
                    <select name="status" class="ss-select" style="flex:1;">
                        <option value="draft" <?php echo $detail['status'] === 'draft' ? 'selected' : ''; ?>>Pending</option>
                        <option value="confirmed" <?php echo $detail['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="cancel" <?php echo $detail['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancel</option>
                    </select>
                    <button class="ss-btn ss-btn-outline" type="submit"><i data-feather="save"></i></button>
                </form>
                <a href="bookings.php?action=print_invoice&id=<?php echo $detail['id']; ?>" class="ss-btn ss-btn-outline" style="margin-top:10px;"><i data-feather="file-text"></i> Cetak Invoice</a>
                <a href="bookings.php?action=pay_invoice&id=<?php echo $detail['id']; ?>&pay_mode=dp" class="ss-btn ss-btn-outline" style="margin-top:8px;"><i data-feather="dollar-sign"></i> Bayar DP</a>
                <a href="bookings.php?action=pay_invoice&id=<?php echo $detail['id']; ?>&pay_mode=full" class="ss-btn ss-btn-outline" style="margin-top:8px;"><i data-feather="check-circle"></i> Pelunasan</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        (function() {
            var ctx = document.getElementById('bookingFinancePie');
            if (!ctx || typeof Chart === 'undefined') return;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Pengeluaran', 'Margin'],
                    datasets: [{
                        data: [<?php echo (float)$totalExpenseActual; ?>, <?php echo (float)$marginActual; ?>],
                        backgroundColor: ['#dc2626', '#16a34a'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(c) {
                                    return c.label + ': Rp ' + c.raw.toLocaleString('id-ID');
                                }
                            }
                        }
                    }
                }
            });
        })();
    </script>

<?php elseif ($action === 'add'): ?>
    <div style="margin-bottom:14px;"><a class="ss-btn ss-btn-outline ss-btn-sm" href="bookings.php"><i data-feather="arrow-left"></i> Kembali</a></div>
    <form method="POST">
        <input type="hidden" name="action" value="save_booking">
        <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;">
            <div>
                <div class="ss-card" style="margin-bottom:16px;">
                    <div class="ss-card-title" style="margin-bottom:10px;">Input Customer & Range Waktu</div>
                    <div class="ss-form-grid cols-2">
                        <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Customer</label><select name="customer_id" class="ss-select" required>
                                <option value="">-- pilih customer --</option><?php foreach ($customers as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ($c['phone'] ? ' - ' . $c['phone'] : '')); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="ss-form-group"><label class="ss-label">Mode Pesanan</label><select name="booking_mode" id="modeSelect" class="ss-select">
                                <option value="paket">Paket</option>
                                <option value="ecer">Ecer</option>
                            </select></div>
                        <div class="ss-form-group"><label class="ss-label">Paket Wisata</label><select name="package_id" class="ss-select">
                                <option value="">-- custom/ecer --</option><?php foreach ($packages as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="ss-form-group"><label class="ss-label">Tanggal Mulai</label><input type="date" name="start_date" class="ss-input" required></div>
                        <div class="ss-form-group"><label class="ss-label">Tanggal Selesai</label><input type="date" name="end_date" class="ss-input" required></div>
                        <div class="ss-form-group"><label class="ss-label">Jumlah Pax</label><input type="number" name="pax_count" class="ss-input" value="1" min="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Catatan</label><input name="notes" class="ss-input" placeholder="Catatan tambahan"></div>
                    </div>
                </div>

                <div class="ss-card">
                    <div class="ss-card-title" style="margin-bottom:10px;">Komponen Pilihan Pesanan</div>
                    <div class="ss-form-grid cols-2">
                        <div class="ss-form-group"><label class="ss-label">Tiket Kapal</label><select name="ticket_kapal_type" class="ss-select">
                                <option value="none">Tidak</option>
                                <option value="pp">PP</option>
                                <option value="single">Satu Arah</option>
                            </select></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Tiket Kapal</label><input class="ss-input" name="ticket_kapal_qty" type="number" min="0" step="1" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Tiket Kapal</label><input class="ss-input" name="ticket_kapal_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Tiket Kapal</label><input class="ss-input" name="ticket_kapal_sell"></div>

                        <div class="ss-form-group" style="grid-column:1/-1;"><label><input type="checkbox" name="include_btn_ticket"> Tiket BTN (Retribusi)</label></div>
                        <div class="ss-form-group"><label class="ss-label">Qty BTN</label><input class="ss-input" name="btn_ticket_qty" type="number" min="0" step="1" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal BTN</label><input class="ss-input" name="btn_ticket_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual BTN</label><input class="ss-input" name="btn_ticket_sell"></div>

                        <div class="ss-form-group"><label class="ss-label">Transportasi</label><input class="ss-input" name="transport_notes" placeholder="Mobil jemput / local transport"></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Transport</label><input class="ss-input" name="transport_qty" type="number" min="0" step="1" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Transport</label><input class="ss-input" name="transport_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Transport</label><input class="ss-input" name="transport_sell"></div>

                        <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Penginapan (Hotel/Homestay)</label><select name="room_id" class="ss-select">
                                <option value="">-- tidak pilih --</option><?php foreach ($partnersRooms as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['partner_name'] . ' - ' . $r['room_type'] . ' (' . $r['partner_type'] . ')'); ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="ss-form-group"><label class="ss-label">Jumlah Kamar</label><input class="ss-input" name="stay_room_qty" type="number" min="1" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Jumlah Malam</label><input class="ss-input" name="stay_nights" type="number" min="1" value="1"></div>

                        <div class="ss-form-group" style="grid-column:1/-1;"><label class="ss-label">Penginapan (Manual)</label><input class="ss-input" name="accommodation_manual" placeholder="Isi hanya jika penginapan tidak dipilih dari daftar di atas, mis: Homestay Pak Budi"></div>

                        <div class="ss-form-group"><label class="ss-label">Makan</label><input class="ss-input" name="meal_notes" placeholder="Katering/resto"></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Makan</label><input class="ss-input" name="meal_qty" type="number" min="0" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Makan</label><input class="ss-input" name="meal_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Makan</label><input class="ss-input" name="meal_sell"></div>

                        <div class="ss-form-group"><label><input type="checkbox" name="island_trip"> Trip Island Hopping</label></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Island Trip</label><input class="ss-input" name="island_trip_qty" type="number" min="0" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Island</label><input class="ss-input" name="island_trip_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Island</label><input class="ss-input" name="island_trip_sell"></div>

                        <div class="ss-form-group"><label><input type="checkbox" name="land_trip"> Trip Darat</label></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Trip Darat</label><input class="ss-input" name="land_trip_qty" type="number" min="0" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Darat</label><input class="ss-input" name="land_trip_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Darat</label><input class="ss-input" name="land_trip_sell"></div>

                        <div class="ss-form-group"><label><input type="checkbox" name="documentation"> Dokumentasi</label></div>
                        <div class="ss-form-group"><label class="ss-label">Qty Dokumentasi</label><input class="ss-input" name="documentation_qty" type="number" min="0" value="1"></div>
                        <div class="ss-form-group"><label class="ss-label">Modal Dokumentasi</label><input class="ss-input" name="documentation_cost"></div>
                        <div class="ss-form-group"><label class="ss-label">Jual Dokumentasi</label><input class="ss-input" name="documentation_sell"></div>

                        <div class="ss-form-group" style="grid-column:1/-1;">
                            <label class="ss-label">Fasilitas Tambahan</label>
                            <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;">
                                <?php foreach ($facilities as $f): ?>
                                    <label style="display:flex;align-items:center;gap:8px;grid-column:1/2;">
                                        <input type="checkbox" name="facility_ids[]" value="<?php echo $f['id']; ?>"> <?php echo htmlspecialchars($f['name']); ?>
                                        <small style="color:var(--ss-muted)"><?php echo sunseaRupiah((float)$f['price_sell']) . '/' . htmlspecialchars($f['unit']); ?></small>
                                    </label>
                                    <input class="ss-input" type="number" min="0" step="0.01" name="facility_qty_<?php echo $f['id']; ?>" placeholder="Qty">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="ss-card" style="margin-bottom:16px;">
                    <div class="ss-card-title" style="margin-bottom:10px;">Penanggung Jawab</div>
                    <div class="ss-form-group"><label class="ss-label">Koordinator</label><select name="coordinator_id" class="ss-select">
                            <option value="">-- pilih --</option><?php foreach ($coordinators as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="ss-form-group"><label class="ss-label">Guide Darat</label><select name="guide_darat_id" class="ss-select">
                            <option value="">-- pilih --</option><?php foreach ($guidesDarat as $g): ?><option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="ss-form-group"><label class="ss-label">Guide Laut</label><select name="guide_laut_id" class="ss-select">
                            <option value="">-- pilih --</option><?php foreach ($guidesLaut as $g): ?><option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <div class="ss-card">
                    <div class="ss-card-title" style="margin-bottom:10px;">Aksi</div>
                    <button class="ss-btn ss-btn-primary" style="width:100%;" type="submit"><i data-feather="save"></i> Simpan Pemesanan</button>
                    <p style="margin-top:8px;color:var(--ss-muted);font-size:12px;">Setelah tersimpan, sistem akan otomatis membuat detail pesanan + jadwal blokir harian.</p>
                </div>
            </div>
        </div>
    </form>

<?php else: ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div>
            <h3 style="margin:0;font-size:18px;">Daftar Pemesanan</h3>
            <div style="color:var(--ss-muted);font-size:12px;">Paket / Ecer dengan rentang tanggal</div>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" id="bulkDeleteBookingBtn" class="ss-btn ss-btn-outline" style="display:none;color:#dc2626;border-color:#dc2626;" onclick="submitBulkDeleteBooking()">
                <i data-feather="trash-2"></i> Hapus Terpilih (<span id="bulkDeleteBookingCount">0</span>)
            </button>
            <a class="ss-btn ss-btn-primary" href="bookings.php?action=add"><i data-feather="plus"></i> Reservasi Baru</a>
        </div>
    </div>
    <style>
        .ss-actions-dropdown {
            position: relative;
            display: inline-block;
        }

        .ss-actions-dropdown summary {
            list-style: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .ss-actions-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .ss-actions-dropdown summary svg {
            width: 13px;
            height: 13px;
        }

        .ss-actions-dropdown .ss-actions-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            z-index: 20;
            background: #fff;
            border: 1px solid var(--ss-gray-1);
            border-radius: 8px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.12);
            min-width: 160px;
            padding: 6px;
            text-align: left;
        }

        .ss-actions-dropdown .ss-actions-menu a,
        .ss-actions-dropdown .ss-actions-menu form button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 7px 10px;
            font-size: 12.5px;
            color: var(--ss-text);
            text-decoration: none;
            border-radius: 6px;
            border: none;
            background: none;
            cursor: pointer;
        }

        .ss-actions-dropdown .ss-actions-menu a:hover,
        .ss-actions-dropdown .ss-actions-menu form button:hover {
            background: var(--ss-gray-1);
        }
    </style>
    <div class="ss-card">
        <form method="POST" id="bulkDeleteBookingForm" style="display:none;">
            <input type="hidden" name="action" value="bulk_delete_booking">
        </form>
        <div class="ss-table-wrap" style="overflow:visible;">
            <table class="ss-table">
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="checkAllBooking" onchange="toggleAllBookingRows(this)"></th>
                        <th>Customer</th>
                        <th>Mode</th>
                        <th>Tanggal</th>
                        <th>Paket</th>
                        <th>Penginapan</th>
                        <th>Status</th>
                        <th>Harga Deal</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                        <?php
                        $sellTotalRow = (float)$r['sell_total'];
                        $paidTotalRow = (float)$r['paid_amount_total'];
                        $isLunas = $sellTotalRow > 0 && $paidTotalRow >= $sellTotalRow - 0.01;
                        ?>
                        <tr>
                            <td>
                                <?php if ($r['status'] === 'cancelled'): ?>
                                    <input type="checkbox" class="booking-row-check" value="<?php echo $r['id']; ?>" onchange="updateBulkDeleteBookingBtn()">
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span style="font-weight:700;"><?php echo htmlspecialchars($r['customer_name']); ?></span>
                                    <?php if (!empty($r['customer_phone'])):
                                        $waNumberRow = preg_replace('/\D/', '', $r['customer_phone']);
                                        if (substr($waNumberRow, 0, 1) === '0') $waNumberRow = '62' . substr($waNumberRow, 1);
                                    ?>
                                        <a href="https://wa.me/<?php echo $waNumberRow; ?>" target="_blank" title="Chat WhatsApp" style="display:inline-flex;color:#25D366;"><i data-feather="message-circle" style="width:14px;height:14px;"></i></a>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:var(--ss-muted);margin-top:1px;"><?php echo htmlspecialchars($r['booking_no']); ?></div>
                            </td>
                            <td><?php echo strpos((string)$r['notes'], '✍️ Booking Manual') === 0 ? 'MANUAL' : strtoupper($r['booking_mode']); ?></td>
                            <td><?php echo date('d M Y', strtotime($r['start_date'])); ?> - <?php echo date('d M Y', strtotime($r['end_date'])); ?></td>
                            <td>
                                <?php
                                $nightsRow = max(0, (int)round((strtotime($r['end_date']) - strtotime($r['start_date'])) / 86400));
                                $durationLabelRow = ($nightsRow + 1) . 'H' . $nightsRow . 'M';
                                echo htmlspecialchars($durationLabelRow);
                                if ($r['package_name']) {
                                    echo '<div style="font-size:11px;color:var(--ss-muted);margin-top:1px;">' . htmlspecialchars($r['package_name']) . '</div>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $accomDisplay = $r['accommodation_item'] ? preg_replace('/^Penginapan:\s*/', '', $r['accommodation_item']) : ($r['accommodation_manual'] ?: '');
                                echo $accomDisplay ? htmlspecialchars($accomDisplay) : '<span style="color:var(--ss-muted);">-</span>';
                                ?>
                            </td>
                            <td>
                                <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:nowrap;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="booking_id" value="<?php echo (int)$r['id']; ?>">
                                    <select name="status" class="ss-select" onchange="this.form.submit()" style="min-width:130px;height:32px;padding:4px 8px;font-size:12px;">
                                        <option value="draft" <?php echo $r['status'] === 'draft' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo $r['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="cancel" <?php echo $r['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancel</option>
                                    </select>
                                    <?php if ($isLunas): ?>
                                        <span style="background:#F0FDF4;color:#15803d;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;">&#10003; Lunas</span>
                                    <?php elseif ($paidTotalRow > 0): ?>
                                        <span style="background:#FFF7ED;color:#C2410C;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap;">DP</span>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td><strong style="color:var(--ss-ocean)"><?php echo sunseaRupiah((float)$r['sell_total']); ?></strong></td>
                            <td>
                                <details class="ss-actions-dropdown">
                                    <summary class="ss-btn ss-btn-outline ss-btn-sm">Aksi <i data-feather="chevron-down"></i></summary>
                                    <div class="ss-actions-menu">
                                        <a href="bookings.php?view=<?php echo $r['id']; ?>">Lihat Detail</a>
                                        <a href="bookings.php?action=print_invoice&id=<?php echo $r['id']; ?>">Cetak Invoice</a>
                                        <a href="bookings.php?action=pay_invoice&id=<?php echo $r['id']; ?>&pay_mode=dp">Bayar DP</a>
                                        <a href="bookings.php?action=pay_invoice&id=<?php echo $r['id']; ?>&pay_mode=full">Pelunasan</a>
                                        <?php if ($r['status'] === 'cancelled'): ?>
                                            <form method="POST" onsubmit="return confirm('Hapus reservasi <?php echo htmlspecialchars(addslashes($r['booking_no'])); ?>? Tindakan ini tidak bisa dibatalkan.');">
                                                <input type="hidden" name="action" value="delete_booking">
                                                <input type="hidden" name="booking_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" style="color:#dc2626;">Hapus</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
    function toggleAllBookingRows(checkAllBox) {
        document.querySelectorAll('.booking-row-check').forEach(function(cb) {
            cb.checked = checkAllBox.checked;
        });
        updateBulkDeleteBookingBtn();
    }

    function updateBulkDeleteBookingBtn() {
        var checked = document.querySelectorAll('.booking-row-check:checked');
        var btn = document.getElementById('bulkDeleteBookingBtn');
        var countEl = document.getElementById('bulkDeleteBookingCount');
        if (!btn || !countEl) return;
        countEl.textContent = checked.length;
        btn.style.display = checked.length > 0 ? '' : 'none';
    }

    function submitBulkDeleteBooking() {
        var checked = document.querySelectorAll('.booking-row-check:checked');
        if (!checked.length) return;
        if (!confirm('Hapus ' + checked.length + ' reservasi terpilih? Tindakan ini tidak bisa dibatalkan.')) return;
        var form = document.getElementById('bulkDeleteBookingForm');
        form.querySelectorAll('input[name="ids[]"]').forEach(function(el) {
            el.remove();
        });
        checked.forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });
        form.submit();
    }
</script>

<?php include 'layout-footer.php';
