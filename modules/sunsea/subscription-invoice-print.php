<?php

/** Printable receipt for a paid subscription invoice (system billing from ADF System). */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner', 'manager'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);

$period = trim($_GET['period'] ?? '');
$stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? AND status = 'paid' LIMIT 1");
$stmt->execute([$period]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header('Location: dashboard.php');
    exit;
}

$companyName = sunseaSetting($pdo, 'company_name', 'Karimunjawa Explore');
$companyAddress = sunseaSetting($pdo, 'company_address', '');
$logoPath = sunseaSetting($pdo, 'company_logo', '');
$logoSrc = $logoPath ? sunseaAssetUrl($logoPath) : '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Invoice Langganan <?php echo htmlspecialchars($invoice['period']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        body {
            background: #f1f5f9;
            padding: 24px;
            color: #1e293b;
        }

        .page {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            padding: 36px 40px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, .08);
        }

        .head {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #0369A1;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }

        .head img {
            max-width: 56px;
            max-height: 56px;
            object-fit: contain;
        }

        .head .name {
            font-size: 18px;
            font-weight: 800;
            color: #0369A1;
        }

        .head .addr {
            font-size: 11.5px;
            color: #64748B;
        }

        h1 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .sub {
            font-size: 12px;
            color: #64748B;
            margin-bottom: 20px;
        }

        .paid-badge {
            display: inline-block;
            background: #DCFCE7;
            color: #166534;
            font-weight: 700;
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table td {
            padding: 8px 0;
        }

        table tr.muted td {
            color: #64748B;
        }

        table tr.total td {
            border-top: 2px solid #0f172a;
            font-weight: 800;
            font-size: 16px;
            padding-top: 12px;
        }

        .meta {
            margin-top: 24px;
            font-size: 11.5px;
            color: #64748B;
            line-height: 1.7;
        }

        .thanks {
            margin-top: 26px;
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            color: #0369A1;
        }

        .print-bar {
            max-width: 640px;
            margin: 0 auto 14px;
            text-align: right;
        }

        .print-bar button {
            background: #0369A1;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .print-bar {
                display: none;
            }

            .page {
                box-shadow: none;
                border-radius: 0;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="print-bar">
        <button onclick="window.print()">Cetak / Simpan PDF</button>
    </div>
    <div class="page">
        <div class="head">
            <?php if ($logoSrc): ?><img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Logo"><?php endif; ?>
            <div>
                <div class="name"><?php echo htmlspecialchars($companyName); ?></div>
                <?php if ($companyAddress): ?><div class="addr"><?php echo htmlspecialchars($companyAddress); ?></div><?php endif; ?>
            </div>
        </div>

        <h1>Invoice Pembayaran Langganan System</h1>
        <div class="sub">Periode <?php echo htmlspecialchars($invoice['period']); ?></div>
        <span class="paid-badge">✓ LUNAS</span>

        <table>
            <tr class="muted">
                <td>Biaya Dasar Bulanan</td>
                <td style="text-align:right;"><?php echo sunseaRupiah((float) $invoice['base_fee']); ?></td>
            </tr>
            <tr class="muted">
                <td>Tamu Confirmed (<?php echo (int) $invoice['guest_count']; ?> &times; <?php echo sunseaRupiah((float) $invoice['per_guest_fee']); ?>)</td>
                <td style="text-align:right;"><?php echo sunseaRupiah((float) $invoice['guest_total']); ?></td>
            </tr>
            <tr class="total">
                <td>Total Dibayar</td>
                <td style="text-align:right;"><?php echo sunseaRupiah((float) $invoice['total_amount']); ?></td>
            </tr>
        </table>

        <div class="meta">
            <?php if (!empty($invoice['due_date'])): ?>Jatuh Tempo: <?php echo htmlspecialchars(date('d M Y', strtotime($invoice['due_date']))); ?><br><?php endif; ?>
            <?php if (!empty($invoice['paid_at'])): ?>Tanggal Bayar: <?php echo htmlspecialchars(date('d M Y H:i', strtotime($invoice['paid_at']))); ?> WIB<br><?php endif; ?>
            <?php if (!empty($invoice['order_id'])): ?>No. Order: <?php echo htmlspecialchars($invoice['order_id']); ?><br><?php endif; ?>
            <?php if (!empty($invoice['txn_id'])): ?>No. Transaksi Pakasir: <?php echo htmlspecialchars($invoice['txn_id']); ?><?php endif; ?>
        </div>

        <div class="thanks">Terima kasih atas pembayaran tepat waktu.</div>
    </div>
</body>

</html>
