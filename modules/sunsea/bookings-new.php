<?php

/**
 * Sunsea - Pemesanan Baru (Simplified dengan dropdown database & auto-calculate)
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
sunseaEnsureBookingSchema($pdo);
sunseaEnsureMasterDataSchema($pdo);
sunseaEnsureAccommodationSchema($pdo);

// Fail-safe query agar halaman tidak blank jika tabel layanan optional belum ada.
$pageWarnings = [];

function safeQueryAll(PDO $pdo, string $sql, string $label, array &$warnings): array
{
    try {
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    } catch (Exception $e) {
        $warnings[] = 'Data ' . $label . ' belum tersedia: ' . $e->getMessage();
        return [];
    }
}

function safeQueryPrice(PDO $pdo, string $sql, array $params): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

// Load data dari database
$customers = safeQueryAll($pdo, "SELECT id, name, phone FROM customers WHERE is_active=1 ORDER BY name", 'customer', $pageWarnings);
$packages = safeQueryAll($pdo, "SELECT id, name, base_price FROM trip_packages WHERE is_active=1 ORDER BY name", 'paket', $pageWarnings);
$tickets = safeQueryAll($pdo, "SELECT id, ticket_name, ticket_type, price_cost, price_sell FROM tickets WHERE is_active=1 ORDER BY ticket_name", 'tiket', $pageWarnings);
$rooms = safeQueryAll($pdo, "SELECT r.id, r.room_type, r.price_cost, r.price_sell, p.name as partner_name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type", 'penginapan', $pageWarnings);
$caterings = safeQueryAll($pdo, "SELECT id, menu_name, vendor_name, price_cost, price_sell, portion_unit FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name", 'catering', $pageWarnings);
$guides = safeQueryAll($pdo, "SELECT id, name, guide_type, daily_rate_cost, daily_rate_sell FROM guides WHERE is_active=1 ORDER BY guide_type, name", 'guide', $pageWarnings);
$facilities = safeQueryAll($pdo, "SELECT id, name, unit, price_cost, price_sell FROM facilities WHERE is_active=1 ORDER BY name", 'fasilitas', $pageWarnings);
$coordinators = safeQueryAll($pdo, "SELECT id, name FROM coordinators WHERE is_active=1 ORDER BY name", 'koordinator', $pageWarnings);
$transportItems = safeQueryAll($pdo, "SELECT id, name, transport_type, unit, price_cost, price_sell FROM transport_items WHERE is_active=1 ORDER BY transport_type, name", 'transportasi', $pageWarnings);

// Handle AJAX request untuk fetch price
if ($_GET['action'] ?? '' === 'get_price') {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? '';
    $id = (int)($_GET['id'] ?? 0);

    $price = ['cost' => 0, 'sell' => 0];

    if ($type === 'ticket' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT price_cost, price_sell FROM tickets WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'room' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT price_cost, price_sell FROM accommodation_rooms WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'catering' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT price_cost, price_sell FROM caterings WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'guide' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT daily_rate_cost, daily_rate_sell FROM guides WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['daily_rate_cost'], 'sell' => (float)$r['daily_rate_sell']];
    } elseif ($type === 'facility' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT price_cost, price_sell FROM facilities WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    } elseif ($type === 'transport' && $id > 0) {
        $r = safeQueryPrice($pdo, "SELECT price_cost, price_sell FROM transport_items WHERE id=?", [$id]);
        if ($r) $price = ['cost' => (float)$r['price_cost'], 'sell' => (float)$r['price_sell']];
    }

    echo json_encode($price);
    exit;
}

// Handle form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_booking') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $bookingMode = $_POST['booking_mode'] ?? 'paket';
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $pax = max(1, (int)($_POST['pax_count'] ?? 1));

    if ($customerId <= 0 || $startDate === '' || $endDate === '') {
        $_SESSION['flash_message'] = 'Customer, tanggal mulai, dan tanggal selesai wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }

    $components = [];
    $costTotal = 0.0;
    $sellTotal = 0.0;

    // Helper function untuk tambah komponen
    $addComponent = function ($code, $name, $qty, $unit, $costPrice, $sellPrice) use (&$components, &$costTotal, &$sellTotal) {
        $totalCost = $qty * $costPrice;
        $totalSell = $qty * $sellPrice;
        $costTotal += $totalCost;
        $sellTotal += $totalSell;
        $components[] = [
            'component_code' => $code,
            'component_name' => $name,
            'qty' => $qty,
            'unit' => $unit,
            'price_cost' => $costPrice,
            'price_sell' => $sellPrice,
            'total_cost' => $totalCost,
            'total_sell' => $totalSell,
        ];
    };

    // 0. Paket (jika mode = paket)
    if ($bookingMode === 'paket' && !empty($_POST['package_id'])) {
        $packageId = (int)$_POST['package_id'];
        $pkgStmt = $pdo->prepare("SELECT name, base_price FROM trip_packages WHERE id=?");
        $pkgStmt->execute([$packageId]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
        if ($pkg) {
            $addComponent('paket', 'Paket: ' . $pkg['name'], $pax, 'pax', 0, (float)$pkg['base_price']);
        }
    }

    // 1. Tiket (auto qty dari total pax, bisa PP / sekali jalan)
    if (!empty($_POST['ticket_id'])) {
        $ticketId = (int)$_POST['ticket_id'];
        $tripType = ($_POST['ticket_trip_type'] ?? 'sekali') === 'pp' ? 'pp' : 'sekali';
        $ticketQty = max(1, (int)($_POST['ticket_qty'] ?? $pax));
        if ($tripType === 'pp') {
            $ticketQty *= 2;
        }
        $tktStmt = $pdo->prepare("SELECT ticket_name, price_cost, price_sell FROM tickets WHERE id=?");
        $tktStmt->execute([$ticketId]);
        $tkt = $tktStmt->fetch(PDO::FETCH_ASSOC);
        if ($tkt) {
            $label = 'Tiket: ' . $tkt['ticket_name'] . ($tripType === 'pp' ? ' (PP)' : ' (Sekali Jalan)');
            $addComponent('ticket', $label, $ticketQty, 'pax', (float)$tkt['price_cost'], (float)$tkt['price_sell']);
        }
    }

    // 1b. Transportasi Karimunjawa (jemput/antar pelabuhan, trip darat/laut)
    if (!empty($_POST['transport_id'])) {
        $transportId = (int)$_POST['transport_id'];
        $transportQty = max(1, (float)($_POST['transport_qty'] ?? 1));
        $trStmt = $pdo->prepare("SELECT name, unit, price_cost, price_sell FROM transport_items WHERE id=?");
        $trStmt->execute([$transportId]);
        $tr = $trStmt->fetch(PDO::FETCH_ASSOC);
        if ($tr) {
            $addComponent('transport', 'Transportasi: ' . $tr['name'], $transportQty, $tr['unit'] ?: 'trip', (float)$tr['price_cost'], (float)$tr['price_sell']);
        }
    }

    // 2. Penginapan
    if (!empty($_POST['room_id'])) {
        $roomId = (int)$_POST['room_id'];
        $nights = max(1, (int)($_POST['stay_nights'] ?? 1));
        $roomQty = max(1, (int)($_POST['stay_room_qty'] ?? 1));
        $rmStmt = $pdo->prepare("SELECT r.room_type, r.price_cost, r.price_sell, p.name FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id WHERE r.id=?");
        $rmStmt->execute([$roomId]);
        $rm = $rmStmt->fetch(PDO::FETCH_ASSOC);
        if ($rm) {
            $unitQty = $nights * $roomQty;
            $addComponent('penginapan', 'Penginapan: ' . $rm['name'] . ' - ' . $rm['room_type'], $unitQty, 'room-night', (float)$rm['price_cost'], (float)$rm['price_sell']);
        }
    }

    // 3. Catering
    if (!empty($_POST['catering_id'])) {
        $cateringId = (int)$_POST['catering_id'];
        $cateringQty = max(1, (int)($_POST['catering_qty'] ?? $pax));
        $catStmt = $pdo->prepare("SELECT menu_name, vendor_name, portion_unit, price_cost, price_sell FROM caterings WHERE id=?");
        $catStmt->execute([$cateringId]);
        $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
        if ($cat) {
            $addComponent('catering', 'Catering: ' . $cat['vendor_name'] . ' - ' . $cat['menu_name'], $cateringQty, $cat['portion_unit'], (float)$cat['price_cost'], (float)$cat['price_sell']);
        }
    }

    // 4. Guide Darat
    if (!empty($_POST['guide_darat_id'])) {
        $guideId = (int)$_POST['guide_darat_id'];
        $days = max(1, (int)($_POST['guide_darat_days'] ?? 1));
        $tripType = ($_POST['guide_darat_trip_type'] ?? 'open') === 'private' ? ' (Private Trip)' : ' (Open Trip)';
        $gdStmt = $pdo->prepare("SELECT name, daily_rate_cost, daily_rate_sell FROM guides WHERE id=?");
        $gdStmt->execute([$guideId]);
        $gd = $gdStmt->fetch(PDO::FETCH_ASSOC);
        if ($gd) {
            $addComponent('guide_darat', 'Guide Darat: ' . $gd['name'] . $tripType, $days, 'hari', (float)$gd['daily_rate_cost'], (float)$gd['daily_rate_sell']);
        }
    }

    // 5. Guide Laut
    if (!empty($_POST['guide_laut_id'])) {
        $guideId = (int)$_POST['guide_laut_id'];
        $days = max(1, (int)($_POST['guide_laut_days'] ?? 1));
        $tripType = ($_POST['guide_laut_trip_type'] ?? 'open') === 'private' ? ' (Private Trip)' : ' (Open Trip)';
        $glStmt = $pdo->prepare("SELECT name, daily_rate_cost, daily_rate_sell FROM guides WHERE id=?");
        $glStmt->execute([$guideId]);
        $gl = $glStmt->fetch(PDO::FETCH_ASSOC);
        if ($gl) {
            $addComponent('guide_laut', 'Guide Laut: ' . $gl['name'] . $tripType, $days, 'hari', (float)$gl['daily_rate_cost'], (float)$gl['daily_rate_sell']);
        }
    }

    // 6. Fasilitas
    if (!empty($_POST['facility_ids']) && is_array($_POST['facility_ids'])) {
        $facStmt = $pdo->prepare("SELECT id, name, unit, price_cost, price_sell FROM facilities WHERE id=?");
        foreach ($_POST['facility_ids'] as $fid) {
            $fid = (int)$fid;
            if ($fid <= 0) continue;
            $facStmt->execute([$fid]);
            if ($fac = $facStmt->fetch()) {
                $qty = max(1, (float)($_POST['facility_qty_' . $fid] ?? 1));
                $addComponent('fasilitas', 'Fasilitas: ' . $fac['name'], $qty, $fac['unit'], (float)$fac['price_cost'], (float)$fac['price_sell']);
            }
        }
    }

    // 7. Item Tambahan (Manual / custom, tidak dari database)
    if (!empty($_POST['manual_name']) && is_array($_POST['manual_name'])) {
        foreach ($_POST['manual_name'] as $i => $mName) {
            $mName = trim($mName);
            if ($mName === '') continue;
            $mQty = max(0, (float)($_POST['manual_qty'][$i] ?? 1));
            if ($mQty <= 0) continue;
            $mUnit = trim($_POST['manual_unit'][$i] ?? 'pax') ?: 'pax';
            $mPrice = (float)str_replace(['.', ','], ['', '.'], $_POST['manual_price'][$i] ?? '0');
            $addComponent('manual', $mName, $mQty, $mUnit, 0, $mPrice);
        }
    }

    if (empty($components)) {
        $_SESSION['flash_message'] = 'Minimal pilih satu komponen (tiket, penginapan, catering, dll).';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }

    $margin = $sellTotal - $costTotal;
    $createdBy = $auth->getCurrentUser()['username'] ?? 'system';
    $bookingNo = sunseaNextNumber($pdo, 'booking');
    $coordId = (int)($_POST['coordinator_id'] ?? 0) ?: null;
    $packageId = ($bookingMode === 'paket') ? ((int)($_POST['package_id'] ?? 0) ?: null) : null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO booking_orders
            (booking_no, customer_id, booking_mode, package_id, start_date, end_date, pax_count,
             coordinator_id, status, cost_total, sell_total, margin_amount, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $bookingNo,
                $customerId,
                $bookingMode,
                $packageId,
                $startDate,
                $endDate,
                $pax,
                $coordId,
                'draft',
                $costTotal,
                $sellTotal,
                $margin,
                trim($_POST['notes'] ?? ''),
                $createdBy
            ]);
        $bookingId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO booking_order_items
            (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?)");

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
                $idx,
            ]);
        }

        // Generate schedule
        $cur = strtotime($startDate);
        $end = strtotime($endDate);
        $sched = $pdo->prepare("INSERT INTO booking_schedule (booking_id, activity_date, activity_type, title) VALUES (?,?,?,?)");
        while ($cur <= $end) {
            $date = date('Y-m-d', $cur);
            $sched->execute([$bookingId, $date, 'other', 'Operasional ' . $bookingNo]);
            $cur = strtotime('+1 day', $cur);
        }

        $pdo->commit();
        $_SESSION['flash_message'] = 'Pemesanan berhasil disimpan: ' . $bookingNo;
        $_SESSION['flash_type'] = 'success';
        header('Location: bookings.php?view=' . $bookingId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = 'Gagal simpan: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings-new.php');
        exit;
    }
}

$pageTitle = 'Input Pemesanan Baru';
$activePage = 'bookings';
include 'layout-header.php';
?>

<div style="max-width:880px;padding:12px;">
    <?php if (!empty($pageWarnings)): ?>
        <div style="margin-bottom:10px;padding:8px 10px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:6px;">
            <div style="font-weight:600;margin-bottom:3px;font-size:12.5px;">Sebagian data layanan belum tersedia</div>
            <div style="font-size:12px;line-height:1.5;">
                <?php echo htmlspecialchars($pageWarnings[0]); ?>
            </div>
        </div>
    <?php endif; ?>

    <div style="margin-bottom:10px;"><a href="bookings.php" style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#FFF7ED;color:#C2410C;border:1px solid #C2410C;border-radius:4px;text-decoration:none;font-weight:500;font-size:12.5px;cursor:pointer;">← Kembali ke Daftar</a></div>

    <form method="POST" id="bookingForm" style="display:flex;flex-direction:column;gap:8px;">
        <input type="hidden" name="action" value="save_booking">

        <div style="padding:8px 10px;background:#ffffff;border:1px solid #ddd;border-radius:6px;">
            <div style="margin-bottom:6px;font-size:13px;font-weight:600;color:#7C2D12;">👤 1. Data Tamu &amp; Jadwal</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;">
                <div style="grid-column:1/-1;">
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Customer *</label>
                    <select name="customer_id" required style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name'] . ' (' . $c['phone'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Jumlah Pax *</label>
                    <input type="number" name="pax_count" id="paxCount" value="1" min="1" required oninput="syncTicketQty(); syncCateringQty(); calculateTotal();" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Koordinator</label>
                    <select name="coordinator_id" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                        <option value="">-- Pilih Koordinator --</option>
                        <?php foreach ($coordinators as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Tanggal Mulai *</label>
                    <input type="date" name="start_date" id="startDate" required onchange="syncStayNights(); calculateTotal();" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Tanggal Selesai *</label>
                    <input type="date" name="end_date" id="endDate" required onchange="syncStayNights(); calculateTotal();" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="display:block;margin-bottom:3px;font-weight:500;font-size:12px;">Catatan</label>
                    <textarea name="notes" placeholder="Catatan khusus pesanan" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;min-height:40px;"></textarea>
                </div>
            </div>
        </div>

        <div style="padding:8px 10px;background:#ffffff;border:1px solid #ddd;border-radius:6px;">
            <div style="margin-bottom:6px;font-size:13px;font-weight:600;color:#7C2D12;">🧭 2. Pilih Tipe Layanan</div>
            <select name="booking_mode" id="bookingModeSelect" style="display:none;">
                <option value="paket">Paket</option>
                <option value="ecer" selected>Ecer</option>
            </select>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div class="mode-card" data-mode="paket" onclick="selectMode('paket')" style="flex:1;min-width:160px;padding:8px;text-align:center;border:2px solid #ddd;border-radius:8px;cursor:pointer;">
                    <div style="font-size:18px;">📦</div>
                    <div style="font-weight:700;margin-top:3px;color:#7C2D12;font-size:12px;">Paket (Sudah Jadi)</div>
                    <div style="font-size:10.5px;color:#777;margin-top:1px;">Pilih 1 paket trip yang sudah lengkap</div>
                </div>
                <div class="mode-card" data-mode="ecer" onclick="selectMode('ecer')" style="flex:1;min-width:160px;padding:8px;text-align:center;border:2px solid #C2410C;background:#FFF7ED;border-radius:8px;cursor:pointer;">
                    <div style="font-size:18px;">🧩</div>
                    <div style="font-weight:700;margin-top:3px;color:#7C2D12;font-size:12px;">Ecer (Custom)</div>
                    <div style="font-size:10.5px;color:#777;margin-top:1px;">Susun sendiri per komponen (tiket, transport, dll)</div>
                </div>
            </div>
        </div>

        <div id="pkgSection" style="display:none;padding:8px 10px;background:#ffffff;border:1px solid #ddd;border-radius:6px;">
            <div style="margin-bottom:6px;font-size:13px;font-weight:600;color:#7C2D12;">📦 3. Pilih Paket</div>
            <select name="package_id" onchange="calculateTotal()" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
                <option value="">-- Pilih Paket --</option>
                <?php foreach ($packages as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']) . ' - Rp ' . number_format((float)($p['base_price'] ?? 0), 0, ',', '.'); ?></option>
                <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:#888;margin-top:4px;">* Harga paket dikalikan jumlah pax. Detail komponen paket bisa diatur di menu Paket Trip.</div>
            <div style="text-align:right;margin-top:6px;font-size:11px;">Subtotal: <strong id="pkgSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
        </div>

        <div id="ecerSection" style="padding:10px 12px;background:#ffffff;border:1px solid #ddd;border-radius:6px;">
            <div style="margin-bottom:6px;font-size:13px;font-weight:600;color:#7C2D12;">🧩 3. Pilih Komponen dari Database</div>

            <!-- Tiket -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🎫 Tiket Kapal</label>
                <div style="display:grid;grid-template-columns:1fr 120px 90px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Tiket</small>
                        <select name="ticket_id" onchange="loadPrice('ticket', this.value, 'ticketPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($tickets as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['ticket_name'] . ' (' . $t['ticket_type'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Jenis Trip</small>
                        <select name="ticket_trip_type" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="sekali">Sekali Jalan</option>
                            <option value="pp">PP (Pulang-Pergi)</option>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Total Pax</small>
                        <input type="number" name="ticket_qty" id="ticketQty" value="1" min="1" readonly title="Otomatis mengikuti jumlah pax" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;background:#f3f4f6;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga Jual</small>
                        <strong id="ticketPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px;">
                    <div style="font-size:10.5px;color:#888;">* "Total Pax" otomatis = jumlah pax di atas. Pilih PP jika tiket pulang-pergi (harga dikali 2).</div>
                    <div style="font-size:11px;white-space:nowrap;">Subtotal: <strong id="ticketSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
                </div>
            </div>

            <!-- Transportasi -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🚐 Transportasi Karimunjawa</label>
                <div style="display:grid;grid-template-columns:1fr 90px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Transportasi</small>
                        <select name="transport_id" onchange="loadPrice('transport', this.value, 'transportPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($transportItems as $ti): ?>
                                <option value="<?php echo $ti['id']; ?>"><?php echo htmlspecialchars($ti['name'] . ' (' . ($ti['transport_type'] === 'laut' ? 'Laut' : 'Darat') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Qty/Trip</small>
                        <input type="number" name="transport_qty" value="1" min="1" step="0.01" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga Jual</small>
                        <strong id="transportPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px;">
                    <div style="font-size:10.5px;color:#888;">* Penjemputan/pengantaran pelabuhan, trip darat, trip laut. Kelola daftar di menu Database Transportasi.</div>
                    <div style="font-size:11px;white-space:nowrap;">Subtotal: <strong id="transportSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
                </div>
            </div>

            <!-- Penginapan -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🏨 Penginapan</label>
                <div style="display:grid;grid-template-columns:1fr 80px 80px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Kamar</small>
                        <select name="room_id" onchange="loadPrice('room', this.value, 'roomPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['partner_name'] . ' - ' . $r['room_type']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Jml Kamar</small>
                        <input type="number" name="stay_room_qty" value="1" min="1" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Jml Malam</small>
                        <input type="number" name="stay_nights" id="stayNights" value="1" min="1" title="Otomatis mengikuti tanggal mulai &amp; selesai, bisa diubah manual" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;background:#f3f4f6;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga/Malam</small>
                        <strong id="roomPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px;">
                    <div style="font-size:10.5px;color:#888;">* "Jml Malam" otomatis dihitung dari tanggal mulai &amp; selesai (bisa diubah manual jika beda).</div>
                    <div style="font-size:11px;white-space:nowrap;">Subtotal: <strong id="roomSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
                </div>
            </div>

            <!-- Catering -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🍽️ Makan (Catering)</label>
                <div style="display:grid;grid-template-columns:1fr 90px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Menu</small>
                        <select name="catering_id" onchange="loadPrice('catering', this.value, 'cateringPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($caterings as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['vendor_name'] . ' - ' . $c['menu_name'] . ' (' . $c['portion_unit'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Qty/Porsi</small>
                        <input type="number" name="catering_qty" id="cateringQty" value="1" min="1" title="Otomatis mengikuti Jumlah Pax, bisa diubah manual" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;background:#f3f4f6;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga Jual</small>
                        <strong id="cateringPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="text-align:right;margin-top:4px;font-size:11px;">Subtotal: <strong id="cateringSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
            </div>

            <!-- Guide Darat -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🧭 Guide Darat</label>
                <div style="display:grid;grid-template-columns:1fr 100px 70px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Guide</small>
                        <select name="guide_darat_id" onchange="loadPrice('guide', this.value, 'guideDaratPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($guides as $g): if ($g['guide_type'] === 'darat'): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                            <?php endif;
                            endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Tipe Trip</small>
                        <select name="guide_darat_trip_type" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="open">Open Trip</option>
                            <option value="private">Private Trip</option>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Jml Hari</small>
                        <input type="number" name="guide_darat_days" value="1" min="1" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga/Hari</small>
                        <strong id="guideDaratPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="text-align:right;margin-top:4px;font-size:11px;">Subtotal: <strong id="guideDaratSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
            </div>

            <!-- Guide Laut -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">⛵ Guide Laut</label>
                <div style="display:grid;grid-template-columns:1fr 100px 70px 100px;gap:5px;align-items:end;">
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Pilih Guide</small>
                        <select name="guide_laut_id" onchange="loadPrice('guide', this.value, 'guideLautPrice')" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="">-- Tidak pilih --</option>
                            <?php foreach ($guides as $g): if ($g['guide_type'] === 'laut'): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
                            <?php endif;
                            endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Tipe Trip</small>
                        <select name="guide_laut_trip_type" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                            <option value="open">Open Trip</option>
                            <option value="private">Private Trip</option>
                        </select>
                    </div>
                    <div>
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Jml Hari</small>
                        <input type="number" name="guide_laut_days" value="1" min="1" onchange="calculateTotal()" style="width:100%;padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12.5px;box-sizing:border-box;">
                    </div>
                    <div style="text-align:center;">
                        <small style="display:block;color:#888;font-size:10px;margin-bottom:1px;">Harga/Hari</small>
                        <strong id="guideLautPrice" style="color:#C2410C;font-size:13px;">-</strong>
                    </div>
                </div>
                <div style="text-align:right;margin-top:4px;font-size:11px;">Subtotal: <strong id="guideLautSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
            </div>

            <!-- Fasilitas -->
            <div style="margin-bottom:6px;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:4px;font-weight:600;color:#7C2D12;font-size:12.5px;">🎒 Fasilitas Tambahan</label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:6px;">
                    <?php foreach ($facilities as $f): ?>
                        <label style="display:flex;align-items:center;gap:6px;padding:6px 8px;background:#ffffff;border:1px solid #e0e0e0;border-radius:4px;cursor:pointer;">
                            <input type="checkbox" name="facility_ids[]" value="<?php echo $f['id']; ?>" onchange="calculateTotal()" style="width:14px;height:14px;cursor:pointer;">
                            <span style="flex:1;font-size:12px;"><?php echo htmlspecialchars($f['name'] . ' (' . $f['unit'] . ')'); ?></span>
                            <span class="fac-price" style="color:#C2410C;font-weight:600;min-width:90px;text-align:right;font-size:11px;">Rp <?php echo number_format((float)$f['price_sell'], 0, ',', '.'); ?></span>
                            <input type="number" name="facility_qty_<?php echo $f['id']; ?>" placeholder="Qty" value="1" min="0" step="0.01" onchange="calculateTotal()" style="width:50px;padding:3px;border:1px solid #ccc;border-radius:3px;font-family:inherit;font-size:11px;">
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="text-align:right;margin-top:4px;font-size:11px;">Subtotal: <strong id="facilitySubtotal" style="color:#7C2D12;">Rp 0</strong></div>
            </div>

            <!-- Item Manual -->
            <div style="margin-bottom:0;padding:7px 9px;background:#f8fbff;border:1px solid #d0e8ff;border-radius:6px;">
                <label style="display:block;margin-bottom:5px;font-weight:600;color:#7C2D12;font-size:12.5px;">➕ Item Tambahan (Manual)</label>
                <div id="manualItemsBody"></div>
                <button type="button" onclick="addManualItem()" style="margin-top:4px;padding:6px 10px;background:#FFF7ED;color:#C2410C;border:1px solid #C2410C;border-radius:4px;font-weight:600;cursor:pointer;font-size:12px;">+ Tambah Item Manual</button>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:4px;">
                    <div style="font-size:10.5px;color:#888;">* Untuk biaya lain di luar database (misal: tiket destinasi, tiket masuk kawasan BTN, biaya lain-lain).</div>
                    <div style="font-size:11px;white-space:nowrap;">Subtotal: <strong id="manualSubtotal" style="color:#7C2D12;">Rp 0</strong></div>
                </div>
            </div>
        </div>

        <div style="padding:8px 10px;background:#ffffff;border:1px solid #ddd;border-radius:6px;">
            <div style="margin-bottom:6px;font-size:13px;font-weight:600;color:#7C2D12;">💰 4. Estimasi Harga</div>
            <div style="max-width:260px;margin-left:auto;">
                <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee;font-size:12.5px;">
                    <span style="color:#666;">Total Modal</span>
                    <strong id="totalCost" style="color:#C2410C;">Rp 0</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee;font-size:12.5px;">
                    <span style="color:#666;">Total Jual</span>
                    <strong id="totalSell" style="color:#C2410C;">Rp 0</strong>
                </div>
                <div style="display:flex;justify-content:space-between;padding:5px 0;border-top:2px solid #C2410C;font-size:13px;">
                    <span style="font-weight:600;">Margin</span>
                    <strong style="color:#10b981;" id="totalMargin">Rp 0</strong>
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;">
                <a href="bookings.php" style="padding:6px 12px;background:#FFF7ED;color:#C2410C;border:1px solid #C2410C;border-radius:4px;text-decoration:none;font-weight:600;cursor:pointer;font-size:12.5px;">Batal</a>
                <button type="submit" style="padding:6px 12px;background:#C2410C;color:white;border:none;border-radius:4px;font-weight:600;cursor:pointer;font-size:12.5px;">💾 Simpan Pemesanan</button>
            </div>
        </div>
    </form>
</div>

<script>
    function selectMode(mode) {
        document.getElementById('bookingModeSelect').value = mode;
        document.querySelectorAll('.mode-card').forEach(card => {
            const active = card.dataset.mode === mode;
            card.style.border = active ? '2px solid #C2410C' : '2px solid #ddd';
            card.style.background = active ? '#FFF7ED' : '#ffffff';
        });
        document.getElementById('pkgSection').style.display = mode === 'paket' ? 'block' : 'none';
        document.getElementById('ecerSection').style.display = mode === 'ecer' ? 'block' : 'none';
        calculateTotal();
    }

    function syncTicketQty() {
        const pax = parseFloat(document.getElementById('paxCount').value) || 1;
        document.getElementById('ticketQty').value = pax;
        calculateTotal();
    }

    function syncCateringQty() {
        const pax = parseFloat(document.getElementById('paxCount').value) || 1;
        document.getElementById('cateringQty').value = pax;
        calculateTotal();
    }

    function syncStayNights() {
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        if (start && end) {
            const diffDays = Math.round((new Date(end) - new Date(start)) / (1000 * 60 * 60 * 24));
            document.getElementById('stayNights').value = diffDays > 0 ? diffDays : 1;
            calculateTotal();
        }
    }

    function addManualItem() {
        const row = document.createElement('div');
        row.style.cssText = 'display:grid;grid-template-columns:1fr 60px 80px 110px 26px;gap:6px;margin-bottom:5px;align-items:center;';
        row.innerHTML = '<input type="text" name="manual_name[]" placeholder="Nama item" onchange="calculateTotal()" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12px;box-sizing:border-box;">' +
            '<input type="number" name="manual_qty[]" placeholder="Qty" value="1" min="0" step="0.01" onchange="calculateTotal()" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12px;box-sizing:border-box;">' +
            '<input type="text" name="manual_unit[]" placeholder="Satuan" value="pax" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12px;box-sizing:border-box;">' +
            '<input type="text" name="manual_price[]" placeholder="Harga (Rp)" onchange="calculateTotal()" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:12px;box-sizing:border-box;">' +
            '<button type="button" onclick="this.parentElement.remove(); calculateTotal();" style="background:#fee;color:#c00;border:1px solid #fbb;border-radius:4px;cursor:pointer;padding:5px;font-size:12px;">✕</button>';
        document.getElementById('manualItemsBody').appendChild(row);
    }

    function rupiah(n) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(n));
    }

    function loadPrice(type, id, displayId) {
        if (!id) {
            document.getElementById(displayId).textContent = '-';
            calculateTotal();
            return;
        }
        fetch('bookings-new.php?action=get_price&type=' + type + '&id=' + id)
            .then(r => r.json())
            .then(data => {
                document.getElementById(displayId).textContent = rupiah(data.sell);
                calculateTotal();
            })
            .catch(e => console.error(e));
    }

    function setSubtotal(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = rupiah(value);
    }

    // Angka harga selalu ditulis dengan titik sebagai pemisah ribuan (format id-ID),
    // jadi ambil digitnya saja - JANGAN pakai parseFloat langsung karena titik akan
    // dibaca sebagai koma desimal (mis. "600.000" jadi 600, bukan 600000).
    function parseRupiah(text) {
        return parseFloat(String(text).replace(/[^0-9]/g, '')) || 0;
    }

    function calculateTotal() {
        let costTotal = 0,
            sellTotal = 0;
        const mode = document.getElementById('bookingModeSelect').value;

        // Paket
        let pkgSubtotal = 0;
        if (mode === 'paket') {
            const pkgSelect = document.querySelector('select[name="package_id"]');
            if (pkgSelect && pkgSelect.value) {
                const optText = pkgSelect.options[pkgSelect.selectedIndex].text;
                const priceMatch = optText.match(/Rp\s*([\d.]+)/);
                const price = priceMatch ? parseRupiah(priceMatch[1]) : 0;
                const pax = parseFloat(document.getElementById('paxCount').value) || 1;
                pkgSubtotal = price * pax;
                sellTotal += pkgSubtotal;
            }
        }
        setSubtotal('pkgSubtotal', pkgSubtotal);

        // Tiket (bisa PP / sekali jalan)
        let ticketSubtotal = 0;
        if (document.querySelector('select[name="ticket_id"]').value) {
            const price = parseRupiah(document.getElementById('ticketPrice').textContent);
            const qty = parseFloat(document.getElementById('ticketQty').value) || 0;
            const tripType = document.querySelector('select[name="ticket_trip_type"]').value;
            const multiplier = tripType === 'pp' ? 2 : 1;
            ticketSubtotal = price * qty * multiplier;
            sellTotal += ticketSubtotal;
        }
        setSubtotal('ticketSubtotal', ticketSubtotal);

        // Transportasi
        let transportSubtotal = 0;
        if (document.querySelector('select[name="transport_id"]').value) {
            const price = parseRupiah(document.getElementById('transportPrice').textContent);
            const qty = parseFloat(document.querySelector('input[name="transport_qty"]').value) || 0;
            transportSubtotal = price * qty;
            sellTotal += transportSubtotal;
        }
        setSubtotal('transportSubtotal', transportSubtotal);

        // Penginapan
        let roomSubtotal = 0;
        if (document.querySelector('select[name="room_id"]').value) {
            const price = parseRupiah(document.getElementById('roomPrice').textContent);
            const nights = parseFloat(document.querySelector('input[name="stay_nights"]').value) || 1;
            const qty = parseFloat(document.querySelector('input[name="stay_room_qty"]').value) || 1;
            roomSubtotal = price * nights * qty;
            sellTotal += roomSubtotal;
        }
        setSubtotal('roomSubtotal', roomSubtotal);

        // Catering
        let cateringSubtotal = 0;
        if (document.querySelector('select[name="catering_id"]').value) {
            const price = parseRupiah(document.getElementById('cateringPrice').textContent);
            const qty = parseFloat(document.querySelector('input[name="catering_qty"]').value) || 0;
            cateringSubtotal = price * qty;
            sellTotal += cateringSubtotal;
        }
        setSubtotal('cateringSubtotal', cateringSubtotal);

        // Guide Darat
        let guideDaratSubtotal = 0;
        if (document.querySelector('select[name="guide_darat_id"]').value) {
            const price = parseRupiah(document.getElementById('guideDaratPrice').textContent);
            const days = parseFloat(document.querySelector('input[name="guide_darat_days"]').value) || 1;
            guideDaratSubtotal = price * days;
            sellTotal += guideDaratSubtotal;
        }
        setSubtotal('guideDaratSubtotal', guideDaratSubtotal);

        // Guide Laut
        let guideLautSubtotal = 0;
        if (document.querySelector('select[name="guide_laut_id"]').value) {
            const price = parseRupiah(document.getElementById('guideLautPrice').textContent);
            const days = parseFloat(document.querySelector('input[name="guide_laut_days"]').value) || 1;
            guideLautSubtotal = price * days;
            sellTotal += guideLautSubtotal;
        }
        setSubtotal('guideLautSubtotal', guideLautSubtotal);

        // Fasilitas
        let facilitySubtotal = 0;
        document.querySelectorAll('input[name="facility_ids[]"]:checked').forEach(checkbox => {
            const facId = checkbox.value;
            const facPrice = parseRupiah(checkbox.parentElement.querySelector('.fac-price').textContent);
            const facQty = parseFloat(document.querySelector('input[name="facility_qty_' + facId + '"]').value) || 1;
            facilitySubtotal += facPrice * facQty;
        });
        sellTotal += facilitySubtotal;
        setSubtotal('facilitySubtotal', facilitySubtotal);

        // Item Manual
        let manualSubtotal = 0;
        const manualNames = document.querySelectorAll('input[name="manual_name[]"]');
        const manualQtys = document.querySelectorAll('input[name="manual_qty[]"]');
        const manualPrices = document.querySelectorAll('input[name="manual_price[]"]');
        manualNames.forEach((nameInput, idx) => {
            if (!nameInput.value.trim()) return;
            const qty = parseFloat(manualQtys[idx] ? manualQtys[idx].value : 0) || 0;
            const price = parseRupiah(manualPrices[idx] ? manualPrices[idx].value : '0');
            manualSubtotal += qty * price;
        });
        sellTotal += manualSubtotal;
        setSubtotal('manualSubtotal', manualSubtotal);

        const margin = sellTotal - costTotal;
        document.getElementById('totalCost').textContent = rupiah(costTotal);
        document.getElementById('totalSell').textContent = rupiah(sellTotal);
        document.getElementById('totalMargin').textContent = rupiah(margin);
    }

    document.addEventListener('DOMContentLoaded', function() {
        selectMode('ecer');
        syncTicketQty();
        syncStayNights();
        syncCateringQty();
    });
</script>

<?php include 'layout-footer.php';
