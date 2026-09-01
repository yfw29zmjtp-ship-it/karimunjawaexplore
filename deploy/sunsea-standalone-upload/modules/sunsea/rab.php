<?php

/**
 * Sunsea - Cetak RAB Internal
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

$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    header('Location: bookings.php');
    exit;
}

$s = $pdo->prepare("SELECT b.*, c.name as customer_name
    FROM booking_orders b
    JOIN customers c ON c.id=b.customer_id
    WHERE b.id=?");
$s->execute([$bookingId]);
$booking = $s->fetch();
if (!$booking) {
    header('Location: bookings.php');
    exit;
}

$itemsStmt = $pdo->prepare("SELECT * FROM booking_order_items WHERE booking_id=? ORDER BY sort_order");
$itemsStmt->execute([$bookingId]);
$items = $itemsStmt->fetchAll();

$print = isset($_GET['print']) ? 1 : 0;

if ($print):
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <title>RAB <?php echo htmlspecialchars($booking['booking_no']); ?></title>
        <style>
            body {
                font-family: 'Segoe UI', sans-serif;
                font-size: 12px;
                padding: 24px;
                color: #0f172a
            }

            h1 {
                font-size: 22px;
                margin: 0;
                color: #7C2D12
            }

            h2 {
                font-size: 14px;
                margin: 2px 0 16px;
                color: #64748B
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 12px
            }

            th,
            td {
                border: 1px solid #E2E8F0;
                padding: 8px;
                text-align: left
            }

            th {
                background: #FFF7ED;
                font-size: 11px;
                color: #64748B
            }

            .total {
                margin-top: 16px;
                max-width: 360px;
                float: right
            }

            .row {
                display: flex;
                justify-content: space-between;
                padding: 4px 0
            }

            .final {
                font-size: 16px;
                font-weight: 700;
                border-top: 2px solid #C2410C;
                padding-top: 8px;
                color: #C2410C
            }

            @media print {
                body {
                    padding: 8px
                }
            }
        </style>
    </head>

    <body onload="window.print()">
        <h1>RAB Internal</h1>
        <h2><?php echo htmlspecialchars($booking['booking_no']); ?> · <?php echo htmlspecialchars($booking['customer_name']); ?></h2>
        <div>Periode: <?php echo date('d M Y', strtotime($booking['start_date'])); ?> - <?php echo date('d M Y', strtotime($booking['end_date'])); ?> | Pax: <?php echo (int)$booking['pax_count']; ?></div>
        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th>Qty</th>
                    <th>Harga Modal</th>
                    <th>Total Modal</th>
                    <th>Harga Jual</th>
                    <th>Total Jual</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['component_name']); ?></td>
                        <td><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.') . ' ' . htmlspecialchars($it['unit']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['price_cost']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['total_cost']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['price_sell']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['total_sell']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="total">
            <div class="row"><span>Total Modal</span><strong><?php echo sunseaRupiah((float)$booking['cost_total']); ?></strong></div>
            <div class="row"><span>Total Jual</span><strong><?php echo sunseaRupiah((float)$booking['sell_total']); ?></strong></div>
            <div class="row final"><span>Margin</span><strong><?php echo sunseaRupiah((float)$booking['margin_amount']); ?></strong></div>
        </div>
    </body>

    </html>
<?php
    exit;
endif;

$pageTitle = 'Cetak RAB';
$activePage = 'rab';
include 'layout-header.php';
?>

<div class="ss-card" style="max-width:900px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
        <div>
            <div class="ss-card-title">RAB Internal - <?php echo htmlspecialchars($booking['booking_no']); ?></div>
            <div class="ss-card-sub"><?php echo htmlspecialchars($booking['customer_name']); ?> · <?php echo date('d M Y', strtotime($booking['start_date'])); ?> - <?php echo date('d M Y', strtotime($booking['end_date'])); ?></div>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="bookings.php?view=<?php echo $booking['id']; ?>" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="arrow-left"></i> Kembali</a>
            <a href="rab.php?booking_id=<?php echo $booking['id']; ?>&print=1" target="_blank" class="ss-btn ss-btn-primary ss-btn-sm"><i data-feather="printer"></i> Cetak RAB</a>
        </div>
    </div>

    <div class="ss-table-wrap">
        <table class="ss-table">
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th>Qty</th>
                    <th>Total Modal</th>
                    <th>Total Jual</th>
                    <th>Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it):
                    $margin = (float)$it['total_sell'] - (float)$it['total_cost'];
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($it['component_name']); ?></td>
                        <td><?php echo rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.') . ' ' . htmlspecialchars($it['unit']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['total_cost']); ?></td>
                        <td><?php echo sunseaRupiah((float)$it['total_sell']); ?></td>
                        <td style="font-weight:600;color:var(--ss-success)"><?php echo sunseaRupiah($margin); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:14px;">
        <div style="width:320px;">
            <div style="display:flex;justify-content:space-between;padding:6px 0;"><span style="color:var(--ss-muted)">Total Modal</span><strong><?php echo sunseaRupiah((float)$booking['cost_total']); ?></strong></div>
            <div style="display:flex;justify-content:space-between;padding:6px 0;"><span style="color:var(--ss-muted)">Total Jual</span><strong><?php echo sunseaRupiah((float)$booking['sell_total']); ?></strong></div>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-top:2px solid var(--ss-ocean);font-size:16px;"><span>Margin</span><strong style="color:var(--ss-success)"><?php echo sunseaRupiah((float)$booking['margin_amount']); ?></strong></div>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
