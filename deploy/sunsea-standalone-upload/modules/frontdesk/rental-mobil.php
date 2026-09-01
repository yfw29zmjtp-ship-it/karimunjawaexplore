<?php

/**
 * Rental Mobil / Taxi — Hotel Services Sub-Module
 * Track car/taxi rentals with partner-owner commission tracking
 * Integrated with Hotel Service invoicing system
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/InvoiceHelper.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$currentUser = $auth->getCurrentUser();
$businessId  = $_SESSION['business_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (($_GET['view'] ?? '') !== 'manage')) {
    header('Location: ' . BASE_URL . '/modules/frontdesk/rental-mobil-dashboard.php');
    exit;
}

// ── Auto-create tables ─────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS rental_cars (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    business_id         INT NOT NULL DEFAULT 1,
    plate_number        VARCHAR(20) NOT NULL,
    car_name            VARCHAR(100) NOT NULL,
    car_type            ENUM('sedan','mpv','minibus','pickup','suv','van','other') NOT NULL DEFAULT 'mpv',
    color               VARCHAR(30) DEFAULT NULL,
    year                SMALLINT DEFAULT NULL,
    capacity            TINYINT DEFAULT NULL COMMENT 'jumlah penumpang',
    daily_rate          DECIMAL(15,2) NOT NULL DEFAULT 0,
    partner_owner       VARCHAR(120) DEFAULT NULL COMMENT 'nama pemilik/mitra',
    owner_phone         VARCHAR(30) DEFAULT NULL,
    owner_commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '% bagian pemilik dari total',
    status              ENUM('available','rented','maintenance') NOT NULL DEFAULT 'available',
    notes               TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_biz (business_id),
    KEY idx_status (business_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS rental_car_bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    business_id     INT NOT NULL DEFAULT 1,
    car_id          INT NOT NULL,
    invoice_id      INT DEFAULT NULL,
    guest_name      VARCHAR(120) NOT NULL,
    guest_phone     VARCHAR(30) DEFAULT NULL,
    room_number     VARCHAR(20) DEFAULT NULL,
    booking_id      INT DEFAULT NULL,
    start_datetime  DATETIME NOT NULL,
    end_datetime    DATETIME NOT NULL,
    actual_return   DATETIME DEFAULT NULL,
    daily_rate      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_price     DECIMAL(15,2) NOT NULL DEFAULT 0,
    owner_amount    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'bagian pemilik',
    hotel_commission DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'bagian hotel',
    deposit         DECIMAL(15,2) NOT NULL DEFAULT 0,
    trip_destination VARCHAR(200) DEFAULT NULL,
    status          ENUM('active','returned','overdue','cancelled') NOT NULL DEFAULT 'active',
    notes           TEXT DEFAULT NULL,
    created_by      INT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_biz (business_id),
    KEY idx_car (car_id),
    KEY idx_invoice (invoice_id),
    KEY idx_status (business_id, status),
    KEY idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Auto-update overdue ────────────────────────────────────────────────────────
$pdo->exec("UPDATE rental_car_bookings SET status='overdue'
    WHERE status='active' AND end_datetime < NOW() AND business_id={$businessId}");

// ── AJAX handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    ob_start();
    try {
        $action = $_POST['action'];

        // ── SAVE CAR (add / edit) ───────────────────────────────────────────
        if ($action === 'save_car') {
            $cid          = (int)($_POST['car_id'] ?? 0);
            $plate        = strtoupper(trim($_POST['plate_number'] ?? ''));
            $carName      = trim($_POST['car_name'] ?? '');
            $carType      = $_POST['car_type'] ?? 'mpv';
            $color        = trim($_POST['color'] ?? '');
            $year         = (int)($_POST['year'] ?? 0) ?: null;
            $capacity     = (int)($_POST['capacity'] ?? 0) ?: null;
            $dailyRate    = max(0, (float)($_POST['daily_rate'] ?? 0));
            $owner        = trim($_POST['partner_owner'] ?? '');
            $ownerPhone   = trim($_POST['owner_phone'] ?? '');
            $ownerCommPct = max(0, min(100, (float)($_POST['owner_commission_pct'] ?? 0)));
            $carStatus    = $_POST['car_status'] ?? 'available';
            $notes        = trim($_POST['notes'] ?? '');

            if (!$plate || !$carName) throw new Exception('Plat nomor dan nama kendaraan wajib diisi');
            $validTypes = ['sedan', 'mpv', 'minibus', 'pickup', 'suv', 'van', 'other'];
            if (!in_array($carType, $validTypes)) $carType = 'mpv';
            if (!in_array($carStatus, ['available', 'rented', 'maintenance'])) $carStatus = 'available';

            if ($cid) {
                $pdo->prepare("UPDATE rental_cars SET plate_number=?,car_name=?,car_type=?,color=?,year=?,
                    capacity=?,daily_rate=?,partner_owner=?,owner_phone=?,owner_commission_pct=?,
                    status=?,notes=?,updated_at=NOW() WHERE id=? AND business_id=?")
                    ->execute([
                        $plate,
                        $carName,
                        $carType,
                        $color ?: null,
                        $year,
                        $capacity,
                        $dailyRate,
                        $owner ?: null,
                        $ownerPhone ?: null,
                        $ownerCommPct,
                        $carStatus,
                        $notes ?: null,
                        $cid,
                        $businessId
                    ]);
            } else {
                $pdo->prepare("INSERT INTO rental_cars
                    (business_id,plate_number,car_name,car_type,color,year,capacity,daily_rate,
                     partner_owner,owner_phone,owner_commission_pct,status,notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $businessId,
                        $plate,
                        $carName,
                        $carType,
                        $color ?: null,
                        $year,
                        $capacity,
                        $dailyRate,
                        $owner ?: null,
                        $ownerPhone ?: null,
                        $ownerCommPct,
                        $carStatus,
                        $notes ?: null
                    ]);
                $cid = (int)$pdo->lastInsertId();
            }
            ob_clean();
            echo json_encode(['success' => true, 'id' => $cid]);
            exit;
        }

        // ── DELETE CAR ──────────────────────────────────────────────────────
        if ($action === 'delete_car') {
            $cid = (int)($_POST['car_id'] ?? 0);
            if (!$cid) throw new Exception('Invalid ID');
            $activeCheck = $pdo->prepare("SELECT COUNT(*) FROM rental_car_bookings WHERE car_id=? AND status IN ('active','overdue') AND business_id=?");
            $activeCheck->execute([$cid, $businessId]);
            if ((int)$activeCheck->fetchColumn() > 0) throw new Exception('Tidak bisa hapus: kendaraan sedang disewa');
            $pdo->prepare("DELETE FROM rental_cars WHERE id=? AND business_id=?")->execute([$cid, $businessId]);
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        // ── CREATE RENTAL ───────────────────────────────────────────────────
        if ($action === 'create_rental') {
            $guestName   = trim($_POST['guest_name'] ?? '');
            $guestPhone  = trim($_POST['guest_phone'] ?? '');
            $roomNumber  = trim($_POST['room_number'] ?? '');
            $bookingId2  = (int)($_POST['booking_id'] ?? 0) ?: null;
            $carId       = (int)($_POST['car_id'] ?? 0);
            $startDt     = trim($_POST['start_datetime'] ?? '');
            $endDt       = trim($_POST['end_datetime'] ?? '');
            $dailyRate2  = max(0, (float)($_POST['daily_rate'] ?? 0));
            $deposit     = max(0, (float)($_POST['deposit'] ?? 0));
            $destination = trim($_POST['trip_destination'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');
            $createInv   = !empty($_POST['create_invoice']);

            if (!$guestName || !$startDt || !$endDt || !$carId) throw new Exception('Data tidak lengkap');

            $car = $pdo->prepare("SELECT * FROM rental_cars WHERE id=? AND business_id=?");
            $car->execute([$carId, $businessId]);
            $carRow = $car->fetch(PDO::FETCH_ASSOC);
            if (!$carRow) throw new Exception('Kendaraan tidak ditemukan');
            if ($carRow['status'] === 'rented') throw new Exception("Kendaraan {$carRow['plate_number']} sedang disewa");
            if ($carRow['status'] === 'maintenance') throw new Exception("Kendaraan {$carRow['plate_number']} sedang maintenance");

            $start = new DateTime($startDt);
            $end   = new DateTime($endDt);
            if ($end <= $start) throw new Exception('Tanggal selesai harus setelah tanggal mulai');

            $plannedSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());
            $plannedDays    = max(1, (int)ceil($plannedSeconds / 86400));
            $plannedTotal   = max(0, round($plannedDays * $dailyRate2, 2));
            $ownerPct       = (float)($carRow['owner_commission_pct'] ?? 0);
            $plannedOwner   = round($plannedTotal * ($ownerPct / 100), 2);
            $plannedHotel   = $plannedTotal - $plannedOwner;

            $pdo->beginTransaction();

            $invoiceId = null;
            if ($createInv) {
                // Use consolidated invoice system - get or create single invoice for guest
                $invoiceId = getOrCreateGuestInvoice(
                    $pdo,
                    $businessId,
                    $bookingId2,
                    $guestName,
                    $guestPhone ?: null,
                    $roomNumber ?: null
                );

                // Add invoice item for this car rental
                addInvoiceItem(
                    $pdo,
                    $invoiceId,
                    'car_rental',
                    "{$carRow['car_name']} ({$carRow['plate_number']})" .
                        ($destination ? " — Tujuan: {$destination}" : ''),
                    $plannedDays,
                    $dailyRate2,  // unit_price (daily rate)
                    $startDt,
                    $endDt
                );
            }

            $pdo->prepare("INSERT INTO rental_car_bookings
                (business_id,car_id,invoice_id,guest_name,guest_phone,room_number,booking_id,
                 start_datetime,end_datetime,daily_rate,total_price,owner_amount,hotel_commission,
                 deposit,trip_destination,status,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $businessId,
                    $carId,
                    $invoiceId,
                    $guestName,
                    $guestPhone ?: null,
                    $roomNumber ?: null,
                    $bookingId2,
                    $startDt,
                    $endDt,
                    $dailyRate2,
                    $plannedTotal,
                    $plannedOwner,
                    $plannedHotel,
                    $deposit,
                    $destination ?: null,
                    'active',
                    $notes ?: null,
                    $currentUser['id'] ?? null
                ]);
            $rentalId = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE rental_cars SET status='rented',updated_at=NOW() WHERE id=?")->execute([$carId]);

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'rental_id' => $rentalId, 'invoice_id' => $invoiceId]);
            exit;
        }

        // ── RETURN CAR ──────────────────────────────────────────────────────
        if ($action === 'return_car') {
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            if (!$rentalId) throw new Exception('Invalid rental ID');

            $rental = $pdo->prepare("SELECT cb.*, rc.plate_number, rc.car_name, rc.owner_commission_pct
                FROM rental_car_bookings cb
                JOIN rental_cars rc ON cb.car_id = rc.id
                WHERE cb.id=? AND cb.business_id=?");
            $rental->execute([$rentalId, $businessId]);
            $rentalRow = $rental->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) throw new Exception('Rental tidak ditemukan');
            if ($rentalRow['status'] === 'returned') throw new Exception('Kendaraan sudah dikembalikan');

            $returnTime = date('Y-m-d H:i:s');
            $start      = new DateTime($rentalRow['start_datetime']);
            $actualEnd  = new DateTime($returnTime);
            $interval   = $start->diff($actualEnd);
            $totalHours = ($interval->d * 24) + $interval->h + ($interval->i > 0 ? 1 : 0) + ($interval->s > 0 ? 1 : 0);
            $actualDays = max(1, (int)ceil($totalHours / 24));

            $dailyRate   = (float)$rentalRow['daily_rate'];
            $billedTotal = (float)($rentalRow['total_price'] ?? 0);
            $ownerAmt    = (float)($rentalRow['owner_amount'] ?? 0);
            $hotelComm   = (float)($rentalRow['hotel_commission'] ?? 0);
            $plannedEnd  = new DateTime($rentalRow['end_datetime']);
            $plannedSeconds = max(0, $plannedEnd->getTimestamp() - $start->getTimestamp());
            $billedDays  = max(1, (int)ceil($plannedSeconds / 86400));

            $pdo->beginTransaction();

            $pdo->prepare("UPDATE rental_car_bookings
                SET status='returned',actual_return=?,updated_at=NOW()
                WHERE id=?")
                ->execute([$returnTime, $rentalId]);

            $pdo->prepare("UPDATE rental_cars SET status='available',updated_at=NOW() WHERE id=?")->execute([$rentalRow['car_id']]);

            $pdo->commit();
            ob_clean();
            echo json_encode([
                'success'      => true,
                'actual_days'  => $actualDays,
                'billed_days'  => $billedDays,
                'daily_rate'   => $dailyRate,
                'billed_total' => $billedTotal,
                'owner_amount' => $ownerAmt,
                'hotel_comm'   => $hotelComm,
                'invoice_locked' => !empty($rentalRow['invoice_id']),
            ]);
            exit;
        }

        // ── CANCEL RENTAL ───────────────────────────────────────────────────
        if ($action === 'cancel_rental') {
            $rentalId = (int)($_POST['rental_id'] ?? 0);
            if (!$rentalId) throw new Exception('Invalid ID');

            $rental = $pdo->prepare("SELECT * FROM rental_car_bookings WHERE id=? AND business_id=?");
            $rental->execute([$rentalId, $businessId]);
            $rentalRow = $rental->fetch(PDO::FETCH_ASSOC);
            if (!$rentalRow) throw new Exception('Rental tidak ditemukan');

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE rental_car_bookings SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$rentalId]);

            $otherActive = $pdo->prepare("SELECT COUNT(*) FROM rental_car_bookings WHERE car_id=? AND status IN ('active','overdue') AND id!=?");
            $otherActive->execute([$rentalRow['car_id'], $rentalId]);
            if ((int)$otherActive->fetchColumn() === 0) {
                $pdo->prepare("UPDATE rental_cars SET status='available',updated_at=NOW() WHERE id=?")->execute([$rentalRow['car_id']]);
            }

            if ($rentalRow['invoice_id']) {
                $pdo->prepare("UPDATE hotel_invoices SET status='cancelled',updated_at=NOW() WHERE id=? AND cashbook_synced=0")
                    ->execute([$rentalRow['invoice_id']]);
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        throw new Exception('Unknown action');
    } catch (Exception $e) {
        ob_clean();
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch Data ─────────────────────────────────────────────────────────────────
$cars = $pdo->prepare("SELECT * FROM rental_cars WHERE business_id=? ORDER BY status ASC, car_name ASC");
$cars->execute([$businessId]);
$carList = $cars->fetchAll(PDO::FETCH_ASSOC);

$filterStatus = $_GET['rs'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');

$rwhere  = ["cb.business_id = ?"];
$rparams = [$businessId];
if ($filterStatus) {
    $rwhere[] = "cb.status = ?";
    $rparams[] = $filterStatus;
}
if ($filterSearch) {
    $rwhere[] = "(cb.guest_name LIKE ? OR rc.plate_number LIKE ? OR rc.car_name LIKE ? OR rc.partner_owner LIKE ?)";
    $rparams[] = "%{$filterSearch}%";
    $rparams[] = "%{$filterSearch}%";
    $rparams[] = "%{$filterSearch}%";
    $rparams[] = "%{$filterSearch}%";
}

$rentalStmt = $pdo->prepare("SELECT cb.*, rc.plate_number, rc.car_name, rc.car_type, rc.color as car_color,
    rc.partner_owner, rc.owner_commission_pct,
    hi.invoice_number, hi.payment_status as inv_pay_status
    FROM rental_car_bookings cb
    JOIN rental_cars rc ON cb.car_id = rc.id
    LEFT JOIN hotel_invoices hi ON cb.invoice_id = hi.id
    WHERE " . implode(' AND ', $rwhere) . "
    ORDER BY FIELD(cb.status,'active','overdue','returned','cancelled'), cb.start_datetime DESC
    LIMIT 200");
$rentalStmt->execute($rparams);
$rentals = $rentalStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCars       = count($carList);
$availableCars   = count(array_filter($carList, fn($c) => $c['status'] === 'available'));
$rentedCars      = count(array_filter($carList, fn($c) => $c['status'] === 'rented'));
$maintenanceCars = count(array_filter($carList, fn($c) => $c['status'] === 'maintenance'));
$activeRentals   = count(array_filter($rentals, fn($r) => in_array($r['status'], ['active', 'overdue'])));

$revStmt = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) as revenue,
    COALESCE(SUM(owner_amount),0) as owner_total,
    COALESCE(SUM(hotel_commission),0) as hotel_total,
    COUNT(*) as total_rentals
    FROM rental_car_bookings WHERE business_id=? AND status IN ('active','returned','overdue')
    AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
$revStmt->execute([$businessId]);
$revStats = $revStmt->fetch(PDO::FETCH_ASSOC);

// In-house guests
try {
    $inHouseGuests = $pdo->query("SELECT b.id as booking_id, g.guest_name, r.room_number, g.phone
        FROM bookings b LEFT JOIN guests g ON b.guest_id=g.id LEFT JOIN rooms r ON b.room_id=r.id
        WHERE b.status='checked_in' ORDER BY r.room_number ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $inHouseGuests = [];
}

// Open invoices
try {
    $openInvStmt = $pdo->prepare("SELECT id,invoice_number,guest_name,room_number,total
        FROM hotel_invoices WHERE business_id=? AND cashbook_synced=0 AND status NOT IN ('cancelled')
        ORDER BY created_at DESC LIMIT 50");
    $openInvStmt->execute([$businessId]);
    $openInvoiceList = $openInvStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $openInvoiceList = [];
}

include '../../includes/header.php';
?>
<style>
    .rm-page {
        padding: 1.25rem;
    }

    .rm-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .rm-topbar h2 {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0;
    }

    .rm-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    .rm-stat {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        border-top: 3px solid var(--c);
    }

    .rm-stat .val {
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--c);
    }

    .rm-stat .lbl {
        font-size: 0.72rem;
        color: var(--text-secondary);
        margin-top: 0.15rem;
    }

    .rm-fleet {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.25rem;
    }

    .rm-car-card {
        background: white;
        border-radius: 10px;
        padding: 0.9rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        border-left: 4px solid var(--mc);
        position: relative;
        transition: transform 0.15s;
    }

    .rm-car-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .rm-car-card .mc-plate {
        font-size: 0.9rem;
        font-weight: 800;
        color: #1e293b;
    }

    .rm-car-card .mc-name {
        font-size: 0.78rem;
        color: var(--text-secondary);
        margin-top: 0.15rem;
    }

    .rm-car-card .mc-owner {
        font-size: 0.72rem;
        color: #6366f1;
        margin-top: 0.2rem;
        font-weight: 600;
    }

    .rm-car-card .mc-rate {
        font-size: 0.75rem;
        color: #6366f1;
        font-weight: 600;
        margin-top: 0.3rem;
    }

    .rm-car-card .mc-status {
        position: absolute;
        top: 0.65rem;
        right: 0.75rem;
        display: inline-block;
        padding: 0.15rem 0.5rem;
        border-radius: 20px;
        font-size: 0.68rem;
        font-weight: 600;
        color: white;
    }

    .rm-car-card .mc-actions {
        display: flex;
        gap: 0.35rem;
        margin-top: 0.6rem;
        flex-wrap: wrap;
    }

    .mc-btn {
        padding: 0.2rem 0.5rem;
        border: none;
        border-radius: 5px;
        font-size: 0.7rem;
        font-weight: 600;
        cursor: pointer;
        transition: opacity 0.2s;
    }

    .mc-btn:hover {
        opacity: 0.8;
    }

    .rm-filters {
        background: white;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        margin-bottom: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        align-items: center;
    }

    .rm-filters input,
    .rm-filters select {
        padding: 0.4rem 0.6rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.8rem;
        background: white;
        color: var(--text-primary);
    }

    .rm-table-wrap {
        background: white;
        border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.07);
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    .rm-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .rm-table th {
        background: #f8fafc;
        padding: 0.65rem 0.85rem;
        text-align: left;
        font-weight: 600;
        color: var(--text-secondary);
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        border-bottom: 1px solid #e2e8f0;
    }

    .rm-table td {
        padding: 0.65rem 0.85rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .rm-table tr:last-child td {
        border-bottom: none;
    }

    .rm-table tr:hover td {
        background: #fafbff;
    }

    .rm-badge {
        display: inline-block;
        padding: 0.2rem 0.55rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        color: white;
    }

    .rm-action-btn {
        padding: 0.35rem 0.7rem;
        border: none;
        border-radius: 7px;
        cursor: pointer;
        font-size: 0.76rem;
        font-weight: 700;
        transition: opacity 0.2s, transform 0.15s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
    }

    .rm-action-btn:hover {
        opacity: 0.8;
    }

    .rm-action-btn.return-btn {
        padding: 0.5rem 1rem;
        font-size: 0.82rem;
        border: 1px solid #86efac;
        box-shadow: 0 2px 8px rgba(22, 163, 74, 0.15);
    }

    .rm-action-btn.return-btn:hover {
        transform: translateY(-1px);
    }

    .rm-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .rm-modal-overlay.open {
        display: flex;
    }

    .rm-modal {
        background: white;
        border-radius: 14px;
        padding: 1.5rem;
        width: 100%;
        max-width: 560px;
        max-height: 92vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .rm-modal h3 {
        margin: 0 0 1rem;
        font-size: 1.05rem;
        font-weight: 700;
    }

    .rm-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .rm-form-row.full {
        grid-template-columns: 1fr;
    }

    .rm-field label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.3rem;
    }

    .rm-field input,
    .rm-field select,
    .rm-field textarea {
        width: 100%;
        padding: 0.5rem 0.65rem;
        border: 1px solid #e2e8f0;
        border-radius: 7px;
        font-size: 0.85rem;
        color: var(--text-primary);
        background: white;
        box-sizing: border-box;
    }

    .rm-field textarea {
        resize: vertical;
        min-height: 55px;
    }

    .rm-field input:focus,
    .rm-field select:focus,
    .rm-field textarea:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
    }

    .rm-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        margin-top: 1rem;
    }

    .btn-rm {
        padding: 0.5rem 1.25rem;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        font-size: 0.85rem;
    }

    .btn-rm-primary {
        background: var(--primary, #6366f1);
        color: white;
    }

    .btn-rm-secondary {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #e5e7eb;
    }

    .btn-rm-success {
        background: #10b981;
        color: white;
    }

    .btn-rm-danger {
        background: #ef4444;
        color: white;
    }

    .rm-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-secondary);
    }

    .rm-empty .em-icon {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
    }

    .rm-section {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin: 1.25rem 0 0.6rem;
    }

    .rm-tabs {
        display: flex;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1rem;
    }

    .rm-tab {
        padding: 0.5rem 1rem;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        color: #64748b;
        border-bottom: 2px solid transparent;
        margin-bottom: -2px;
        background: none;
        border-top: none;
        border-left: none;
        border-right: none;
    }

    .rm-tab.active {
        color: #4338ca;
        border-bottom-color: #6366f1;
    }

    .rm-tab-pane {
        display: none;
    }

    .rm-tab-pane.active {
        display: block;
    }

    .rm-total-preview {
        background: linear-gradient(135deg, #f0f4ff, #e8edff);
        border-radius: 8px;
        padding: 0.75rem 1rem;
        text-align: center;
        margin: 0.75rem 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: #4338ca;
    }

    .owner-info-box {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 8px;
        padding: 0.7rem 0.9rem;
        font-size: 0.8rem;
        margin-top: 0.5rem;
    }

    .owner-info-box strong {
        color: #065f46;
    }

    .rm-overdue-pulse {
        animation: overduePulse 2s ease-in-out infinite;
    }

    @keyframes overduePulse {

        0%,
        100% {
            opacity: 1
        }

        50% {
            opacity: 0.6
        }
    }

    .guest-toggle {
        display: flex;
        gap: 0.4rem;
        margin-bottom: 0.6rem;
    }

    .guest-toggle button {
        flex: 1;
        padding: 0.4rem 0.6rem;
        border: 2px solid #e2e8f0;
        border-radius: 7px;
        background: white;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        color: #374151;
    }

    .guest-toggle button.active {
        border-color: #6366f1;
        background: #ede9fe;
        color: #4c1d95;
    }

    @media(max-width:580px) {
        .rm-form-row {
            grid-template-columns: 1fr
        }

        .rm-stats {
            grid-template-columns: repeat(2, 1fr)
        }

        .rm-fleet {
            grid-template-columns: repeat(2, 1fr)
        }
    }
</style>

<div class="rm-page">
    <!-- Top Bar -->
    <div class="rm-topbar">
        <div>
            <h2>🚗 Monitoring Rental Mobil / Taxi</h2>
            <div style="font-size:0.75rem;color:var(--text-secondary)">Kelola armada & pantau penyewaan aktif · Rekap per pemilik</div>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
            <a href="rental-mobil-dashboard.php" class="btn-rm btn-rm-secondary" style="text-decoration:none;font-size:0.8rem;padding:0.4rem 0.8rem">📊 Dashboard</a>
            <a href="hotel-services.php" class="btn-rm btn-rm-secondary" style="text-decoration:none;font-size:0.8rem;padding:0.4rem 0.8rem">← Hotel Services</a>
            <button class="btn-rm btn-rm-secondary" onclick="openCarModal()" style="font-size:0.8rem;padding:0.4rem 0.8rem">+ Tambah Kendaraan</button>
            <button class="btn-rm btn-rm-primary" onclick="openRentalModal()" style="font-size:0.8rem;padding:0.4rem 0.8rem">+ Sewa Baru</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="rm-stats">
        <div class="rm-stat" style="--c:#6366f1">
            <div class="val"><?php echo $totalCars; ?></div>
            <div class="lbl">Total Kendaraan</div>
        </div>
        <div class="rm-stat" style="--c:#10b981">
            <div class="val"><?php echo $availableCars; ?></div>
            <div class="lbl">Tersedia</div>
        </div>
        <div class="rm-stat" style="--c:#f59e0b">
            <div class="val"><?php echo $rentedCars; ?></div>
            <div class="lbl">Disewa</div>
        </div>
        <div class="rm-stat" style="--c:#ef4444">
            <div class="val"><?php echo $activeRentals; ?></div>
            <div class="lbl">Rental Aktif</div>
        </div>
        <div class="rm-stat" style="--c:#8b5cf6">
            <div class="val">Rp <?php echo number_format($revStats['revenue'], 0, ',', '.'); ?></div>
            <div class="lbl">Revenue Bulan Ini</div>
        </div>
        <div class="rm-stat" style="--c:#06b6d4">
            <div class="val">Rp <?php echo number_format($revStats['hotel_total'], 0, ',', '.'); ?></div>
            <div class="lbl">Komisi Hotel Bln Ini</div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="rm-tabs">
        <button class="rm-tab active" id="tab-monitoring" onclick="switchTab('monitoring')">📊 Monitoring</button>
        <button class="rm-tab" id="tab-fleet" onclick="switchTab('fleet')">🚗 Armada</button>
        <button class="rm-tab" id="tab-history" onclick="switchTab('history')">📋 Riwayat</button>
    </div>

    <!-- TAB: Monitoring -->
    <div class="rm-tab-pane active" id="pane-monitoring">
        <?php
        $activeList = array_filter($rentals, fn($r) => in_array($r['status'], ['active', 'overdue']));
        if (empty($activeList)):
        ?>
            <div class="rm-empty">
                <div class="em-icon">🚗</div>
                <p>Tidak ada rental aktif saat ini</p>
            </div>
        <?php else: ?>
            <div class="rm-table-wrap">
                <table class="rm-table">
                    <thead>
                        <tr>
                            <th>Kendaraan</th>
                            <th>Tamu</th>
                            <th>Kamar</th>
                            <th>Tujuan</th>
                            <th>Mulai</th>
                            <th>Kembali</th>
                            <th>Sisa</th>
                            <th>Tarif/Hari</th>
                            <th>Pemilik</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeList as $r):
                            $now = new DateTime();
                            $endDt = new DateTime($r['end_datetime']);
                            $isOverdue = $r['status'] === 'overdue';
                            $diff = $now->diff($endDt);
                            $remaining = $isOverdue
                                ? "Terlambat {$diff->days}h {$diff->h}j"
                                : "{$diff->days}h {$diff->h}j {$diff->i}m";
                        ?>
                            <tr class="<?php echo $isOverdue ? 'rm-overdue-pulse' : ''; ?>">
                                <td>
                                    <div style="font-weight:700;font-size:0.82rem"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['car_name']); ?> <span style="background:#e0e7ff;color:#4338ca;border-radius:4px;padding:0 4px;font-size:0.65rem"><?php echo $r['car_type']; ?></span></div>
                                </td>
                                <td>
                                    <div style="font-weight:600"><?php echo htmlspecialchars($r['guest_name']); ?></div>
                                    <?php if ($r['guest_phone']): ?><div style="font-size:0.7rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['guest_phone']); ?></div><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($r['room_number'] ?? '-'); ?></td>
                                <td style="font-size:0.75rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['trip_destination'] ?? '-'); ?></td>
                                <td style="font-size:0.75rem"><?php echo date('d M H:i', strtotime($r['start_datetime'])); ?></td>
                                <td style="font-size:0.75rem"><?php echo date('d M H:i', strtotime($r['end_datetime'])); ?></td>
                                <td><span style="font-weight:700;color:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>;font-size:0.78rem"><?php echo $remaining; ?></span></td>
                                <td style="font-weight:600;font-size:0.82rem">Rp <?php echo number_format($r['daily_rate'], 0, ',', '.'); ?></td>
                                <td style="font-size:0.75rem"><?php echo htmlspecialchars($r['partner_owner'] ?? '—'); ?></td>
                                <td><span class="rm-badge" style="background:<?php echo $isOverdue ? '#ef4444' : '#10b981'; ?>"><?php echo $isOverdue ? '⚠ Overdue' : '✓ Aktif'; ?></span></td>
                                <td style="white-space:nowrap">
                                    <button class="rm-action-btn return-btn" style="background:#dcfce7;color:#15803d" onclick="returnCar(<?php echo $r['id']; ?>,'<?php echo htmlspecialchars(addslashes($r['car_name'])); ?>')">↩ Kembalikan</button>
                                    <button class="rm-action-btn" style="background:#fee2e2;color:#b91c1c" onclick="cancelRental(<?php echo $r['id']; ?>)">✕</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB: Fleet -->
    <div class="rm-tab-pane" id="pane-fleet">
        <?php if (empty($carList)): ?>
            <div class="rm-empty">
                <div class="em-icon">🚗</div>
                <p>Belum ada kendaraan terdaftar</p>
                <button class="btn-rm btn-rm-primary" onclick="openCarModal()" style="margin-top:0.5rem">+ Tambah Kendaraan</button>
            </div>
        <?php else: ?>
            <div class="rm-fleet">
                <?php
                $statusColors = ['available' => '#10b981', 'rented' => '#f59e0b', 'maintenance' => '#6b7280'];
                $statusLabels = ['available' => 'Tersedia', 'rented' => 'Disewa', 'maintenance' => 'Maint.'];
                $typeIcons    = ['sedan' => '🚘', 'mpv' => '🚙', 'minibus' => '🚌', 'pickup' => '🛻', 'suv' => '🚐', 'van' => '🚐', 'other' => '🚗'];
                foreach ($carList as $c):
                    $mc = $statusColors[$c['status']] ?? '#6b7280';
                    $icon = $typeIcons[$c['car_type']] ?? '🚗';
                ?>
                    <div class="rm-car-card" style="--mc:<?php echo $mc; ?>">
                        <span class="mc-status" style="background:<?php echo $mc; ?>"><?php echo $statusLabels[$c['status']] ?? $c['status']; ?></span>
                        <div style="font-size:1.4rem;margin-bottom:0.3rem"><?php echo $icon; ?></div>
                        <div class="mc-plate"><?php echo htmlspecialchars($c['plate_number']); ?></div>
                        <div class="mc-name">
                            <?php echo htmlspecialchars($c['car_name']); ?>
                            <?php if ($c['color']): ?><span style="color:var(--text-secondary)"> · <?php echo htmlspecialchars($c['color']); ?></span><?php endif; ?>
                            <?php if ($c['capacity']): ?><span style="color:var(--text-secondary)"> · <?php echo $c['capacity']; ?> pax</span><?php endif; ?>
                        </div>
                        <?php if ($c['partner_owner']): ?>
                            <div class="mc-owner">👤 <?php echo htmlspecialchars($c['partner_owner']); ?>
                                <?php if ((float)$c['owner_commission_pct'] > 0): ?>
                                    <span style="color:#059669"> (<?php echo $c['owner_commission_pct']; ?>%)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="mc-rate">Rp <?php echo number_format($c['daily_rate'], 0, ',', '.'); ?> / hari</div>
                        <div class="mc-actions">
                            <button class="mc-btn" style="background:#e0e7ff;color:#4338ca" onclick="editCar(<?php echo htmlspecialchars(json_encode($c)); ?>)">✏️ Edit</button>
                            <?php if ($c['status'] === 'available'): ?>
                                <button class="mc-btn" style="background:#dcfce7;color:#15803d" onclick="openRentalModal(<?php echo $c['id']; ?>)">🔑 Sewakan</button>
                            <?php endif; ?>
                            <?php if ($c['status'] !== 'rented'): ?>
                                <button class="mc-btn" style="background:#fee2e2;color:#b91c1c" onclick="deleteCar(<?php echo $c['id']; ?>,'<?php echo htmlspecialchars(addslashes($c['plate_number'])); ?>')">🗑️</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB: History -->
    <div class="rm-tab-pane" id="pane-history">
        <form method="GET" class="rm-filters">
            <input type="hidden" name="view" value="manage">
            <input type="text" name="q" placeholder="🔍 Cari tamu / plat / pemilik..." value="<?php echo htmlspecialchars($filterSearch); ?>">
            <select name="rs">
                <option value="">Semua Status</option>
                <?php foreach (['active' => 'Aktif', 'overdue' => 'Overdue', 'returned' => 'Dikembalikan', 'cancelled' => 'Dibatalkan'] as $sk => $sl): ?>
                    <option value="<?php echo $sk; ?>" <?php echo $filterStatus === $sk ? 'selected' : ''; ?>><?php echo $sl; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-rm btn-rm-primary" style="padding:0.4rem 0.9rem;font-size:0.8rem">Filter</button>
            <?php if ($filterStatus || $filterSearch): ?>
                <a href="rental-mobil.php?view=manage" class="btn-rm btn-rm-secondary" style="padding:0.4rem 0.9rem;font-size:0.8rem;text-decoration:none">Clear</a>
            <?php endif; ?>
        </form>
        <?php if (empty($rentals)): ?>
            <div class="rm-empty">
                <div class="em-icon">📋</div>
                <p>Belum ada data rental</p>
            </div>
        <?php else: ?>
            <div class="rm-table-wrap">
                <table class="rm-table">
                    <thead>
                        <tr>
                            <th>Kendaraan</th>
                            <th>Tamu</th>
                            <th>Kamar</th>
                            <th>Mulai</th>
                            <th>Selesai</th>
                            <th>Total</th>
                            <th>Pemilik</th>
                            <th>Komisi Hotel</th>
                            <th>Invoice</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rStatusColors = ['active' => '#10b981', 'overdue' => '#ef4444', 'returned' => '#6b7280', 'cancelled' => '#94a3b8'];
                        $rStatusLabels = ['active' => 'Aktif', 'overdue' => 'Overdue', 'returned' => 'Kembali', 'cancelled' => 'Batal'];
                        foreach ($rentals as $r):
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight:700;font-size:0.82rem"><?php echo htmlspecialchars($r['plate_number']); ?></div>
                                    <div style="font-size:0.72rem;color:var(--text-secondary)"><?php echo htmlspecialchars($r['car_name']); ?></div>
                                </td>
                                <td style="font-weight:600"><?php echo htmlspecialchars($r['guest_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['room_number'] ?? '-'); ?></td>
                                <td style="font-size:0.75rem"><?php echo date('d M Y H:i', strtotime($r['start_datetime'])); ?></td>
                                <td style="font-size:0.75rem">
                                    <?php echo date('d M Y H:i', strtotime($r['end_datetime'])); ?>
                                    <?php if ($r['actual_return']): ?><div style="font-size:0.68rem;color:#10b981">↩ <?php echo date('d M H:i', strtotime($r['actual_return'])); ?></div><?php endif; ?>
                                </td>
                                <td style="font-weight:600">Rp <?php echo number_format($r['total_price'], 0, ',', '.'); ?></td>
                                <td style="font-size:0.75rem">
                                    <?php if ($r['partner_owner']): ?>
                                        <div><?php echo htmlspecialchars($r['partner_owner']); ?></div>
                                        <div style="color:#059669;font-weight:600">Rp <?php echo number_format($r['owner_amount'], 0, ',', '.'); ?></div>
                                        <?php else: ?>—<?php endif; ?>
                                </td>
                                <td style="font-weight:600;color:#6366f1">Rp <?php echo number_format($r['hotel_commission'], 0, ',', '.'); ?></td>
                                <td>
                                    <?php if ($r['invoice_number']): ?>
                                        <a href="hotel-service-invoice.php?id=<?php echo $r['invoice_id']; ?>" target="_blank" style="color:#6366f1;font-weight:600;font-size:0.75rem;text-decoration:none"><?php echo htmlspecialchars($r['invoice_number']); ?></a>
                                        <?php if ($r['inv_pay_status']): ?>
                                            <span class="rm-badge" style="background:<?php echo ['unpaid' => '#ef4444', 'partial' => '#f59e0b', 'paid' => '#10b981'][$r['inv_pay_status']] ?? '#6b7280'; ?>;font-size:0.62rem"><?php echo $r['inv_pay_status']; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?><span style="color:var(--text-secondary);font-size:0.72rem">—</span><?php endif; ?>
                                </td>
                                <td><span class="rm-badge" style="background:<?php echo $rStatusColors[$r['status']] ?? '#6b7280'; ?>"><?php echo $rStatusLabels[$r['status']] ?? $r['status']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ MODAL: Add/Edit Car ═══ -->
<div class="rm-modal-overlay" id="carModal" onclick="if(event.target===this)closeCarModal()">
    <div class="rm-modal">
        <h3 id="carModalTitle">Tambah Kendaraan</h3>
        <input type="hidden" id="fc_id" value="0">
        <div class="rm-form-row">
            <div class="rm-field"><label>Plat Nomor *</label><input type="text" id="fc_plate" placeholder="B 1234 XY" style="text-transform:uppercase"></div>
            <div class="rm-field"><label>Nama Kendaraan *</label><input type="text" id="fc_name" placeholder="Toyota Avanza"></div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field">
                <label>Tipe</label>
                <select id="fc_type">
                    <option value="sedan">Sedan</option>
                    <option value="mpv" selected>MPV</option>
                    <option value="suv">SUV</option>
                    <option value="minibus">Minibus</option>
                    <option value="van">Van</option>
                    <option value="pickup">Pickup</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div class="rm-field"><label>Warna</label><input type="text" id="fc_color" placeholder="Hitam"></div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field"><label>Tahun</label><input type="number" id="fc_year" placeholder="2022" min="1990" max="2030"></div>
            <div class="rm-field"><label>Kapasitas (pax)</label><input type="number" id="fc_capacity" placeholder="7" min="1" max="50"></div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field"><label>Tarif / Hari (Rp) *</label><input type="number" id="fc_rate" placeholder="300000" min="0"></div>
            <div class="rm-field">
                <label>Status</label>
                <select id="fc_status">
                    <option value="available">Tersedia</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
        </div>
        <div class="rm-section">👤 Informasi Pemilik / Mitra</div>
        <div class="rm-form-row">
            <div class="rm-field"><label>Nama Pemilik</label><input type="text" id="fc_owner" placeholder="Pak Budi"></div>
            <div class="rm-field"><label>No. HP Pemilik</label><input type="text" id="fc_owner_phone" placeholder="08xxxxxxxxxx"></div>
        </div>
        <div class="rm-form-row">
            <div class="rm-field">
                <label>% Bagian Pemilik</label>
                <input type="number" id="fc_comm_pct" placeholder="80" min="0" max="100" step="1" oninput="updateOwnerPreview()">
            </div>
            <div class="rm-field" style="display:flex;align-items:flex-end">
                <div id="ownerPreview" class="owner-info-box" style="width:100%">
                    <strong>Pemilik: 0%</strong> · Hotel: 0%
                </div>
            </div>
        </div>
        <div class="rm-form-row full">
            <div class="rm-field"><label>Catatan</label><textarea id="fc_notes" placeholder="Catatan tambahan..."></textarea></div>
        </div>
        <div class="rm-modal-footer">
            <button class="btn-rm btn-rm-secondary" onclick="closeCarModal()">Batal</button>
            <button class="btn-rm btn-rm-primary" onclick="saveCar()">💾 Simpan</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: Create Rental ═══ -->
<div class="rm-modal-overlay" id="rentalModal" onclick="if(event.target===this)closeRentalModal()">
    <div class="rm-modal" style="max-width:580px">
        <h3>🔑 Sewa Kendaraan Baru</h3>

        <div class="rm-section">👥 Data Tamu</div>
        <div class="guest-toggle">
            <button class="active" onclick="toggleGuestMode('inhouse',this)" type="button">🏨 Tamu In-house</button>
            <button onclick="toggleGuestMode('manual',this)" type="button">✏️ Tamu Luar</button>
        </div>
        <div id="guestInhouse">
            <div class="rm-field" style="margin-bottom:0.75rem">
                <label>Pilih Tamu In-house</label>
                <select id="fr_guest_select" onchange="onGuestSelect()">
                    <option value="">-- Pilih Tamu --</option>
                    <?php foreach ($inHouseGuests as $ig): ?>
                        <option value="<?php echo $ig['booking_id']; ?>"
                            data-name="<?php echo htmlspecialchars($ig['guest_name']); ?>"
                            data-phone="<?php echo htmlspecialchars($ig['phone'] ?? ''); ?>"
                            data-room="<?php echo htmlspecialchars($ig['room_number'] ?? ''); ?>">
                            <?php echo htmlspecialchars(($ig['room_number'] ? "#{$ig['room_number']} - " : '') . $ig['guest_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="guestManual" style="display:none">
            <div class="rm-form-row">
                <div class="rm-field"><label>Nama Tamu *</label><input type="text" id="fr_guest_name" placeholder="Nama lengkap"></div>
                <div class="rm-field"><label>No. HP</label><input type="text" id="fr_guest_phone" placeholder="08xxxxxxxxxx"></div>
            </div>
            <div class="rm-form-row">
                <div class="rm-field"><label>No. Kamar</label><input type="text" id="fr_room" placeholder="101"></div>
                <div class="rm-field"></div>
            </div>
        </div>
        <input type="hidden" id="fr_booking_id" value="">

        <div class="rm-section">🚗 Kendaraan</div>
        <div class="rm-field" style="margin-bottom:0.75rem">
            <label>Pilih Kendaraan *</label>
            <select id="fr_car_id" onchange="onCarSelect()">
                <option value="">-- Pilih Kendaraan --</option>
                <?php foreach ($carList as $c): if ($c['status'] !== 'available') continue; ?>
                    <option value="<?php echo $c['id']; ?>"
                        data-rate="<?php echo $c['daily_rate']; ?>"
                        data-owner="<?php echo htmlspecialchars($c['partner_owner'] ?? ''); ?>"
                        data-comm="<?php echo $c['owner_commission_pct']; ?>">
                        <?php echo htmlspecialchars($c['plate_number'] . ' — ' . $c['car_name'] . ' (' . $c['car_type'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rm-form-row">
            <div class="rm-field"><label>Tarif / Hari (Rp)</label><input type="number" id="fr_rate" placeholder="0" min="0" onchange="calcRentalTotal()"></div>
            <div class="rm-field"><label>Tujuan / Destinasi</label><input type="text" id="fr_destination" placeholder="Pantai Baron, dll"></div>
        </div>

        <div id="carOwnerInfo" class="owner-info-box" style="display:none;margin-bottom:0.75rem"></div>

        <div class="rm-section">📅 Jadwal</div>
        <div class="rm-form-row">
            <div class="rm-field"><label>Tanggal Mulai *</label><input type="datetime-local" id="fr_start" onchange="calcRentalTotal()"></div>
            <div class="rm-field"><label>Tanggal Kembali *</label><input type="datetime-local" id="fr_end" onchange="calcRentalTotal()"></div>
        </div>

        <div class="rm-form-row">
            <div class="rm-field"><label>Deposit (Rp)</label><input type="number" id="fr_deposit" placeholder="0" min="0" value="0"></div>
            <div class="rm-field" style="display:flex;align-items:flex-end;padding-bottom:0.15rem">
                <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                    <input type="checkbox" id="fr_create_invoice" checked>
                    <span style="font-size:0.82rem;font-weight:600">Buat Invoice Otomatis</span>
                </label>
            </div>
        </div>

        <div class="rm-total-preview" id="rentalTotalPreview">Estimasi: Rp 0 (0 hari)</div>

        <div class="rm-form-row full">
            <div class="rm-field"><label>Catatan</label><textarea id="fr_notes" placeholder="Catatan tambahan..."></textarea></div>
        </div>
        <div class="rm-modal-footer">
            <button class="btn-rm btn-rm-secondary" onclick="closeRentalModal()">Batal</button>
            <button class="btn-rm btn-rm-success" onclick="createRental()">🔑 Proses Sewa</button>
        </div>
    </div>
</div>

<script>
    // ── Tab switching ───────────────────────────────────────────────────────────
    function switchTab(name) {
        document.querySelectorAll('.rm-tab-pane').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.rm-tab').forEach(t => t.classList.remove('active'));
        document.getElementById('pane-' + name).classList.add('active');
        document.getElementById('tab-' + name).classList.add('active');
    }

    // ── Owner preview in car form ───────────────────────────────────────────────
    function updateOwnerPreview() {
        const pct = parseFloat(document.getElementById('fc_comm_pct').value) || 0;
        document.getElementById('ownerPreview').innerHTML =
            `<strong>Pemilik: ${pct}%</strong> · Hotel: ${(100 - pct).toFixed(0)}%`;
    }

    // ── Car Modal ───────────────────────────────────────────────────────────────
    function openCarModal() {
        document.getElementById('fc_id').value = 0;
        ['fc_plate', 'fc_name', 'fc_color', 'fc_year', 'fc_capacity', 'fc_rate', 'fc_owner', 'fc_owner_phone', 'fc_notes'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('fc_type').value = 'mpv';
        document.getElementById('fc_status').value = 'available';
        document.getElementById('fc_comm_pct').value = 0;
        updateOwnerPreview();
        document.getElementById('carModalTitle').textContent = 'Tambah Kendaraan';
        document.getElementById('carModal').classList.add('open');
    }

    function editCar(c) {
        document.getElementById('fc_id').value = c.id;
        document.getElementById('fc_plate').value = c.plate_number;
        document.getElementById('fc_name').value = c.car_name;
        document.getElementById('fc_type').value = c.car_type;
        document.getElementById('fc_color').value = c.color || '';
        document.getElementById('fc_year').value = c.year || '';
        document.getElementById('fc_capacity').value = c.capacity || '';
        document.getElementById('fc_rate').value = c.daily_rate;
        document.getElementById('fc_owner').value = c.partner_owner || '';
        document.getElementById('fc_owner_phone').value = c.owner_phone || '';
        document.getElementById('fc_comm_pct').value = c.owner_commission_pct;
        document.getElementById('fc_status').value = c.status;
        document.getElementById('fc_notes').value = c.notes || '';
        updateOwnerPreview();
        document.getElementById('carModalTitle').textContent = 'Edit Kendaraan';
        document.getElementById('carModal').classList.add('open');
    }

    function closeCarModal() {
        document.getElementById('carModal').classList.remove('open');
    }

    function saveCar() {
        const fd = new FormData();
        fd.append('action', 'save_car');
        ['fc_id:car_id', 'fc_plate:plate_number', 'fc_name:car_name', 'fc_type:car_type',
            'fc_color:color', 'fc_year:year', 'fc_capacity:capacity', 'fc_rate:daily_rate',
            'fc_owner:partner_owner', 'fc_owner_phone:owner_phone', 'fc_comm_pct:owner_commission_pct',
            'fc_status:car_status', 'fc_notes:notes'
        ].forEach(pair => {
            const [id, key] = pair.split(':');
            fd.append(key, document.getElementById(id).value);
        });
        fetch('rental-mobil.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    closeCarModal();
                    location.reload();
                } else alert(d.message || 'Gagal menyimpan');
            })
            .catch(() => alert('Network error'));
    }

    function deleteCar(id, plate) {
        if (!confirm('Hapus kendaraan ' + plate + '?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_car');
        fd.append('car_id', id);
        fetch('rental-mobil.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json()).then(d => {
                if (d.success) location.reload();
                else alert(d.message);
            })
            .catch(() => alert('Network error'));
    }

    // ── Rental Modal ────────────────────────────────────────────────────────────
    function openRentalModal(preselectedCarId) {
        ['fr_guest_select', 'fr_guest_name', 'fr_guest_phone', 'fr_room', 'fr_notes', 'fr_destination'].forEach(id => document.getElementById(id).value = '');
        document.getElementById('fr_booking_id').value = '';
        document.getElementById('fr_deposit').value = '0';
        document.getElementById('fr_create_invoice').checked = true;
        document.getElementById('fr_car_id').value = preselectedCarId || '';
        if (preselectedCarId) onCarSelect();
        const now = new Date(),
            tmrw = new Date(now);
        tmrw.setDate(tmrw.getDate() + 1);
        document.getElementById('fr_start').value = fmtDTL(now);
        document.getElementById('fr_end').value = fmtDTL(tmrw);
        calcRentalTotal();
        document.getElementById('rentalModal').classList.add('open');
    }

    function closeRentalModal() {
        document.getElementById('rentalModal').classList.remove('open');
    }

    function fmtDTL(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') +
            'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    function toggleGuestMode(mode, btn) {
        document.querySelectorAll('.guest-toggle button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('guestInhouse').style.display = mode === 'inhouse' ? 'block' : 'none';
        document.getElementById('guestManual').style.display = mode === 'manual' ? 'block' : 'none';
    }

    function onGuestSelect() {
        const sel = document.getElementById('fr_guest_select');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            document.getElementById('fr_guest_name').value = opt.dataset.name || '';
            document.getElementById('fr_guest_phone').value = opt.dataset.phone || '';
            document.getElementById('fr_room').value = opt.dataset.room || '';
            document.getElementById('fr_booking_id').value = opt.value;
        }
    }

    function onCarSelect() {
        const sel = document.getElementById('fr_car_id');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            document.getElementById('fr_rate').value = opt.dataset.rate || 0;
            const owner = opt.dataset.owner || '';
            const comm = parseFloat(opt.dataset.comm || 0);
            const infoBox = document.getElementById('carOwnerInfo');
            if (owner) {
                infoBox.style.display = 'block';
                infoBox.innerHTML = `<strong>👤 Pemilik: ${owner}</strong> · Bagian pemilik: <strong>${comm}%</strong> · Hotel: <strong>${(100-comm).toFixed(0)}%</strong>`;
            } else {
                infoBox.style.display = 'none';
            }
        } else {
            document.getElementById('carOwnerInfo').style.display = 'none';
        }
        calcRentalTotal();
    }

    function calcRentalTotal() {
        const start = document.getElementById('fr_start').value;
        const end = document.getElementById('fr_end').value;
        const rate = parseFloat(document.getElementById('fr_rate').value) || 0;
        const el = document.getElementById('rentalTotalPreview');
        if (!start || !end) {
            el.textContent = 'Estimasi: Rp 0 (0 hari)';
            return;
        }
        const diff = (new Date(end) - new Date(start)) / (1000 * 3600);
        if (diff <= 0) {
            el.textContent = 'Tanggal tidak valid';
            return;
        }
        const days = Math.max(1, Math.ceil(diff / 24));
        const total = days * rate;
        el.textContent = 'Estimasi: Rp ' + total.toLocaleString('id-ID') + ' (' + days + ' hari)';
    }

    function createRental() {
        const guestName = document.getElementById('fr_guest_name').value.trim() ||
            (document.getElementById('fr_guest_select').options[document.getElementById('fr_guest_select').selectedIndex]?.dataset?.name || '');
        const carId = document.getElementById('fr_car_id').value;
        if (!guestName) {
            alert('Pilih atau isi nama tamu');
            return;
        }
        if (!carId) {
            alert('Pilih kendaraan');
            return;
        }
        if (!document.getElementById('fr_start').value || !document.getElementById('fr_end').value) {
            alert('Isi tanggal');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'create_rental');
        fd.append('guest_name', guestName);
        fd.append('guest_phone', document.getElementById('fr_guest_phone').value);
        fd.append('room_number', document.getElementById('fr_room').value);
        fd.append('booking_id', document.getElementById('fr_booking_id').value);
        fd.append('car_id', carId);
        fd.append('daily_rate', document.getElementById('fr_rate').value);
        fd.append('start_datetime', document.getElementById('fr_start').value);
        fd.append('end_datetime', document.getElementById('fr_end').value);
        fd.append('deposit', document.getElementById('fr_deposit').value);
        fd.append('trip_destination', document.getElementById('fr_destination').value);
        fd.append('notes', document.getElementById('fr_notes').value);
        if (document.getElementById('fr_create_invoice').checked) fd.append('create_invoice', '1');

        fetch('rental-mobil.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    closeRentalModal();
                    location.reload();
                } else alert(d.message || 'Gagal membuat rental');
            })
            .catch(() => alert('Network error'));
    }

    function returnCar(rentalId, carName) {
        if (!confirm('Konfirmasi pengembalian ' + carName + '?\n\nInvoice tidak diubah otomatis. Jika perlu koreksi (mis. pulang lebih cepat), edit invoice manual.')) return;
        const fd = new FormData();
        fd.append('action', 'return_car');
        fd.append('rental_id', rentalId);
        fetch('rental-mobil.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    const ownerFmt = 'Rp ' + Number(d.owner_amount).toLocaleString('id-ID');
                    const hotelFmt = 'Rp ' + Number(d.hotel_comm).toLocaleString('id-ID');
                    const totalFmt = 'Rp ' + Number(d.billed_total).toLocaleString('id-ID');
                    const billedMsg = `${d.billed_days} hari × Rp ${Number(d.daily_rate).toLocaleString('id-ID')}/hari = ${totalFmt}`;
                    const adjustMsg = d.invoice_locked ?
                        '\n\nCatatan: Bila durasi aktual berbeda (' + d.actual_days + ' hari), edit nominal di invoice manual.' :
                        '';
                    alert(`✅ Kendaraan dikembalikan!\n\nTagihan saat ini: ${billedMsg}\n👤 Bagian Pemilik: ${ownerFmt}\n🏨 Komisi Hotel: ${hotelFmt}${adjustMsg}`);
                    location.reload();
                } else alert(d.message || 'Gagal');
            }).catch(() => alert('Network error'));
    }

    function cancelRental(rentalId) {
        if (!confirm('Batalkan rental ini?')) return;
        const fd = new FormData();
        fd.append('action', 'cancel_rental');
        fd.append('rental_id', rentalId);
        fetch('rental-mobil.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) location.reload();
                else alert(d.message);
            })
            .catch(() => alert('Network error'));
    }
</script>

<?php include '../../includes/footer.php'; ?>