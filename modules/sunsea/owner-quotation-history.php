<?php

/**
 * Sunsea - Owner mobile view: Riwayat Penawaran Web
 * Penawaran dari website yang sudah dibuka (jadi sudah hilang dari widget "Booking dari Web"
 * di dashboard) tetap bisa dilihat/edit/dihapus/di-confirm dari sini kapan saja.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: owner-login.php');
    exit;
}
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
$pdo = getSunseaConnection();
if (!sunseaCanAccessMenu($pdo, $currentUser, 'owner_dashboard')) {
    header('Location: dashboard.php');
    exit;
}

sunseaEnsureBookingSchema($pdo);

$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $qId = (int)($_POST['quotation_id'] ?? 0);

    if ($postAction === 'delete' && $qId > 0) {
        $linkedCheck = $pdo->prepare("SELECT id FROM booking_orders WHERE quotation_id = ?");
        $linkedCheck->execute([$qId]);
        if ($linkedCheck->fetchColumn()) {
            $flashMessage = 'Tidak bisa dihapus, penawaran ini sudah dikonfirmasi jadi booking.';
            $flashType = 'error';
        } else {
            $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ?")->execute([$qId]);
            $pdo->prepare("DELETE FROM quotations WHERE id = ?")->execute([$qId]);
            $flashMessage = 'Penawaran berhasil dihapus dari riwayat.';
        }
    } elseif ($postAction === 'confirm' && $qId > 0) {
        // Sama seperti alur approve di system utama (quotations.php): buat booking otomatis masuk Kalender.
        $bookingIdStmt = $pdo->prepare("SELECT id FROM booking_orders WHERE quotation_id = ?");
        $bookingIdStmt->execute([$qId]);
        $newBookingId = (int)($bookingIdStmt->fetchColumn() ?: 0);

        if (!$newBookingId) {
            $q = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
            $q->execute([$qId]);
            $quote = $q->fetch();
            if ($quote) {
                $bookingNo = sunseaNextNumber($pdo, 'booking');
                $startDate = $quote['trip_date'] ?: date('Y-m-d');
                $endDate   = $quote['trip_end_date'] ?: $startDate;
                $pdo->prepare("
                    INSERT INTO booking_orders
                    (quotation_id, booking_no, customer_id, booking_mode, package_id, start_date, end_date,
                     pax_count, status, cost_total, sell_total, margin_amount, notes, accommodation_manual, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $qId,
                    $bookingNo,
                    $quote['customer_id'],
                    $quote['package_id'] ? 'paket' : 'ecer',
                    $quote['package_id'],
                    $startDate,
                    $endDate,
                    $quote['pax_count'],
                    'confirmed',
                    0,
                    $quote['total_amount'],
                    $quote['total_amount'],
                    "Dari Penawaran {$quote['quotation_no']}",
                    $quote['accommodation_manual'] ?? null,
                    'system',
                ]);
                $newBookingId = (int)$pdo->lastInsertId();

                $qItems = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ?");
                $qItems->execute([$qId]);
                $insBookingItem = $pdo->prepare("
                    INSERT INTO booking_order_items
                    (booking_id, component_code, component_name, qty, unit, price_cost, price_sell, total_cost, total_sell, sort_order)
                    VALUES (?,?,?,?,?,0,?,0,?,?)
                ");
                foreach ($qItems->fetchAll() as $idx => $qi) {
                    $insBookingItem->execute([
                        $newBookingId,
                        $qi['item_type'],
                        $qi['description'],
                        $qi['qty'],
                        $qi['unit'],
                        $qi['unit_price'],
                        $qi['subtotal'],
                        $idx,
                    ]);
                }
            }
        }
        $pdo->prepare("UPDATE quotations SET status='approved', approved_at=NOW() WHERE id=?")->execute([$qId]);

        if ($newBookingId) {
            header('Location: owner-booking-detail.php?id=' . $newBookingId);
            exit;
        }
        $flashMessage = 'Penawaran berhasil dikonfirmasi.';
    }
}

$webHistory = $pdo->query("
    SELECT q.id, q.quotation_no, q.status, q.total_amount, q.trip_date, q.pax_count, q.viewed_at,
           c.name AS customer_name, p.name AS package_name,
           b.id AS booking_id
    FROM quotations q
    JOIN customers c ON c.id = q.customer_id
    LEFT JOIN trip_packages p ON p.id = q.package_id
    LEFT JOIN booking_orders b ON b.quotation_id = q.id
    WHERE q.created_by = 'website' AND q.viewed_at IS NOT NULL
    ORDER BY q.viewed_at DESC
    LIMIT 200
")->fetchAll();

$statusBadge = [
    'draft'     => ['ob-badge-draft', 'Draft'],
    'sent'      => ['ob-badge-partial', 'Terkirim'],
    'approved'  => ['ob-badge-confirmed', 'Approved'],
    'rejected'  => ['ob-badge-issued', 'Ditolak'],
    'expired'   => ['ob-badge-issued', 'Kadaluarsa'],
    'converted' => ['ob-badge-paid', 'Converted'],
];

$pageTitle = 'Riwayat Penawaran Web';
$backUrl = 'owner-dashboard.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-status-btn {
        flex: 1;
        min-width: 60px;
        text-align: center;
        padding: 8px 6px;
        border-radius: 8px;
        border: none;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        text-decoration: none;
    }

    .ob-alert-success {
        background: #DCFCE7;
        color: #166534;
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }

    .ob-alert-error {
        background: #FEE2E2;
        color: var(--danger);
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }
</style>

<?php if ($flashMessage): ?>
    <div class="<?php echo $flashType === 'error' ? 'ob-alert-error' : 'ob-alert-success'; ?>">
        <?php echo htmlspecialchars($flashMessage); ?>
    </div>
<?php endif; ?>

<?php if (empty($webHistory)): ?>
    <div class="ob-section">
        <div class="ob-empty">Belum ada riwayat penawaran web yang sudah dibuka.</div>
    </div>
<?php else: ?>
    <?php foreach ($webHistory as $wq): ?>
        <?php $badge = $statusBadge[$wq['status']] ?? ['ob-badge-draft', $wq['status']]; ?>
        <div class="ob-bcard">
            <div class="ob-bcard-top">
                <div class="ob-bcard-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($wq['customer_name'], 0, 1))); ?></div>
                <div class="ob-bcard-info">
                    <div class="ob-bcard-name"><?php echo htmlspecialchars($wq['customer_name']); ?></div>
                    <div class="ob-bcard-no"><?php echo htmlspecialchars($wq['quotation_no']); ?><?php echo $wq['package_name'] ? ' · ' . htmlspecialchars($wq['package_name']) : ''; ?></div>
                </div>
                <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
            </div>
            <div class="ob-bcard-meta">
                <i data-feather="eye"></i> Dibuka <?php echo $wq['viewed_at'] ? date('d M Y H:i', strtotime($wq['viewed_at'])) : '-'; ?>
                &nbsp;·&nbsp; <strong><?php echo sunseaRupiah((float)$wq['total_amount']); ?></strong>
            </div>
            <div style="display:flex;gap:6px;margin-bottom:6px;">
                <a href="owner-quotation-detail.php?id=<?php echo (int)$wq['id']; ?>" class="ob-status-btn" style="background:var(--sky);color:var(--ocean);">
                    <i data-feather="eye"></i> Lihat
                </a>
                <a href="owner-quotation-edit.php?id=<?php echo (int)$wq['id']; ?>" class="ob-status-btn" style="background:#FEF3C7;color:#92400E;">
                    <i data-feather="edit-2"></i> Edit
                </a>
                <?php if (!$wq['booking_id']): ?>
                    <form method="POST" style="flex:1;margin:0;" onsubmit="return confirm('Konfirmasi penawaran ini? Booking akan otomatis dibuat dan masuk Kalender.');">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="quotation_id" value="<?php echo (int)$wq['id']; ?>">
                        <button type="submit" class="ob-status-btn" style="background:var(--success);color:#fff;width:100%;">
                            <i data-feather="check-circle"></i> Confirmed
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$wq['booking_id']): ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Hapus penawaran ini dari riwayat? Tindakan ini tidak bisa dibatalkan.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="quotation_id" value="<?php echo (int)$wq['id']; ?>">
                    <button type="submit" class="ob-status-btn" style="background:#FEE2E2;color:var(--danger);width:100%;">
                        <i data-feather="trash-2"></i> Hapus
                    </button>
                </form>
            <?php else: ?>
                <div style="font-size:10.5px;color:var(--muted);text-align:center;">Sudah dikonfirmasi jadi booking, tidak bisa dihapus.</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include 'owner-mobile-footer.php'; ?>