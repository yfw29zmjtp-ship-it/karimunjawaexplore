<?php

/** Sunsea - Tagihan Langganan ke ADF System (biaya dasar + biaya per tamu confirmed) */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);
sunseaEnsureBookingSchema($pdo);

// ---- Save connection to ADF System (client key/token only — pricing is NOT editable here) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_connection') {
    sunseaSetSetting($pdo, 'subscription_client_key', trim($_POST['client_key'] ?? ''));
    sunseaSetSetting($pdo, 'subscription_client_token', trim($_POST['client_token'] ?? ''));
    if (trim($_POST['sync_url'] ?? '') !== '') {
        sunseaSetSetting($pdo, 'subscription_sync_url', trim($_POST['sync_url']));
    }
    $result = sunseaSyncSubscriptionConfig($pdo);
    if ($result['last_sync_error'] !== '') {
        $_SESSION['flash_message'] = 'Koneksi tersimpan, tapi sinkronisasi gagal: ' . $result['last_sync_error'];
        $_SESSION['flash_type'] = 'error';
    } else {
        $_SESSION['flash_message'] = 'Koneksi tersimpan dan berhasil sinkron dengan ADF System.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: subscription-billing.php');
    exit;
}

// ---- Manually force a re-sync with ADF System ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_now') {
    $result = sunseaSyncSubscriptionConfig($pdo);
    if ($result['last_sync_error'] !== '') {
        $_SESSION['flash_message'] = 'Sinkronisasi gagal: ' . $result['last_sync_error'];
        $_SESSION['flash_type'] = 'error';
    } else {
        $_SESSION['flash_message'] = 'Berhasil sinkron dengan ADF System.';
        $_SESSION['flash_type'] = 'success';
    }
    header('Location: subscription-billing.php?period=' . urlencode($_POST['period'] ?? date('Y-m')));
    exit;
}

// ---- Create Pakasir payment link for an invoice ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $period = trim($_POST['period'] ?? '');
    $cfg = sunseaSubscriptionConfig($pdo);
    $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $stmt->execute([$period]);
    $invoice = $stmt->fetch();

    if (!$invoice || $invoice['status'] !== 'unpaid') {
        $_SESSION['flash_message'] = 'Tagihan tidak ditemukan atau sudah tidak berstatus unpaid.';
        $_SESSION['flash_type'] = 'error';
    } elseif (!sunseaSubscriptionIsConfigured($cfg)) {
        $_SESSION['flash_message'] = 'Pengaturan Pakasir belum diisi. Lengkapi dulu di bagian Pengaturan di bawah.';
        $_SESSION['flash_type'] = 'error';
    } else {
        $orderId = 'SUB' . str_replace('-', '', $period) . strtoupper(bin2hex(random_bytes(3)));
        $result = sunseaPakasirCreatePaymentLink($cfg, $orderId, (int) round((float) $invoice['total_amount']));
        if ($result === null) {
            $_SESSION['flash_message'] = 'Gagal membuat link pembayaran ke Pakasir. Cek slug/API Key.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $pdo->prepare("UPDATE subscription_invoices SET order_id=?, txn_id=?, payment_link=? WHERE period=?")
                ->execute([$orderId, $result['txn_id'], $result['payment_link'], $period]);
            header('Location: ' . $result['payment_link']);
            exit;
        }
    }
    header('Location: subscription-billing.php?period=' . urlencode($period));
    exit;
}

// ---- Manually re-check payment status from Pakasir ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    $period = trim($_POST['period'] ?? '');
    $cfg = sunseaSubscriptionConfig($pdo);
    $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $stmt->execute([$period]);
    $invoice = $stmt->fetch();

    if ($invoice && !empty($invoice['txn_id'])) {
        $status = sunseaPakasirTransactionStatus($cfg, $invoice['txn_id']);
        if ($status !== null && ($status['status'] ?? '') === 'completed') {
            $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=? WHERE period=?")
                ->execute([$status['completed_at'] ?? date('c'), $period]);
            $_SESSION['flash_message'] = 'Status diperbarui: pembayaran sudah lunas.';
            $_SESSION['flash_type'] = 'success';
        } else {
            $_SESSION['flash_message'] = 'Belum ada perubahan status (masih ' . htmlspecialchars($status['status'] ?? 'unknown') . ').';
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: subscription-billing.php?period=' . urlencode($period));
    exit;
}

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    $period = date('Y-m');
}

// Auto-refresh from ADF System at most once per hour so the page stays fast.
$lastSyncAt = sunseaSetting($pdo, 'subscription_last_sync_at', '');
if ($lastSyncAt === '' || (time() - strtotime($lastSyncAt)) > 3600) {
    sunseaSyncSubscriptionConfig($pdo);
}

$isCurrentPeriod = $period === date('Y-m');
if ($isCurrentPeriod) {
    $invoice = sunseaGetOrRefreshSubscriptionInvoice($pdo, $period);
} else {
    $stmt = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $stmt->execute([$period]);
    $invoice = $stmt->fetch() ?: null;
}

$cfg = sunseaSubscriptionConfig($pdo);
$configured = sunseaSubscriptionIsConfigured($cfg);
$history = $pdo->query("SELECT * FROM subscription_invoices ORDER BY period DESC LIMIT 24")->fetchAll();

$pageTitle = 'Tagihan Langganan';
$activePage = 'subscription_billing';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:18px;">
    <div>
        <div class="ss-card" style="margin-bottom:18px;">
            <div class="ss-card-title" style="margin-bottom:12px;">
                Tagihan Periode
                <form method="GET" style="display:inline-block;margin-left:10px;">
                    <input type="month" name="period" value="<?php echo htmlspecialchars($period); ?>" onchange="this.form.submit()" style="padding:4px 8px;border-radius:6px;border:1px solid var(--ss-border);">
                </form>
            </div>

            <?php if (!$invoice): ?>
                <p style="color:var(--ss-muted);">Belum ada tagihan untuk periode ini.</p>
            <?php else: ?>
                <table style="width:100%;font-size:14px;">
                    <tr>
                        <td style="padding:6px 0;color:var(--ss-muted);">Biaya Dasar Bulanan</td>
                        <td style="padding:6px 0;text-align:right;"><?php echo sunseaRupiah((float) $invoice['base_fee']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:6px 0;color:var(--ss-muted);">Tamu Confirmed (<?php echo (int) $invoice['guest_count']; ?> tamu &times; <?php echo sunseaRupiah((float) $invoice['per_guest_fee']); ?>)</td>
                        <td style="padding:6px 0;text-align:right;"><?php echo sunseaRupiah((float) $invoice['guest_total']); ?></td>
                    </tr>
                    <tr style="border-top:1px solid var(--ss-border);">
                        <td style="padding:10px 0;font-weight:700;">Total Tagihan</td>
                        <td style="padding:10px 0;text-align:right;font-weight:700;font-size:18px;"><?php echo sunseaRupiah((float) $invoice['total_amount']); ?></td>
                    </tr>
                </table>

                <div style="margin-top:8px;">
                    <?php if ($invoice['status'] === 'paid'): ?>
                        <span class="ss-status ss-status-approved">Lunas <?php echo $invoice['paid_at'] ? '(' . htmlspecialchars(date('d M Y', strtotime($invoice['paid_at']))) . ')' : ''; ?></span>
                    <?php elseif ($invoice['status'] === 'cancelled'): ?>
                        <span class="ss-status ss-status-draft">Dibatalkan</span>
                    <?php else: ?>
                        <span class="ss-status ss-status-partial">Belum Dibayar</span>
                    <?php endif; ?>
                </div>

                <?php if ($invoice['status'] === 'unpaid'): ?>
                    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if (!$configured): ?>
                            <p style="color:#dc2626;font-size:13px;">Pengaturan Pakasir belum lengkap — isi dulu di panel Pengaturan.</p>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="pay">
                                <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                                <button type="submit" class="ss-btn ss-btn-primary"><i data-feather="credit-card"></i> Bayar Sekarang via Pakasir</button>
                            </form>
                            <?php if (!empty($invoice['txn_id'])): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="check_status">
                                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                                    <button type="submit" class="ss-btn ss-btn-outline"><i data-feather="refresh-cw"></i> Cek Status</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="ss-card">
            <div class="ss-card-title" style="margin-bottom:10px;">Riwayat Tagihan</div>
            <div class="ss-table-wrap">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th>Tamu</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['period']); ?></td>
                            <td><?php echo (int) $row['guest_count']; ?></td>
                            <td><?php echo sunseaRupiah((float) $row['total_amount']); ?></td>
                            <td>
                                <?php if ($row['status'] === 'paid'): ?>
                                    <span class="ss-status ss-status-approved">Lunas</span>
                                <?php elseif ($row['status'] === 'cancelled'): ?>
                                    <span class="ss-status ss-status-draft">Dibatalkan</span>
                                <?php else: ?>
                                    <span class="ss-status ss-status-partial">Belum Dibayar</span>
                                <?php endif; ?>
                            </td>
                            <td><a href="subscription-billing.php?period=<?php echo urlencode($row['period']); ?>" class="ss-btn ss-btn-outline ss-btn-sm">Lihat</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($history)): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--ss-muted);">Belum ada riwayat.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="ss-card" style="margin-bottom:18px;">
        <div class="ss-card-title" style="margin-bottom:12px;">Biaya Langganan</div>
        <p style="font-size:12px;color:var(--ss-muted);margin-bottom:10px;">
            Ditentukan oleh ADF System, hanya bisa dilihat di sini.
        </p>
        <table style="width:100%;font-size:13px;">
            <tr>
                <td style="padding:4px 0;color:var(--ss-muted);">Biaya Dasar / Bulan</td>
                <td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo sunseaRupiah((float) $cfg['base_fee']); ?></td>
            </tr>
            <tr>
                <td style="padding:4px 0;color:var(--ss-muted);">Biaya per Tamu Confirmed</td>
                <td style="padding:4px 0;text-align:right;font-weight:600;"><?php echo sunseaRupiah((float) $cfg['per_guest_fee']); ?></td>
            </tr>
        </table>
        <p style="font-size:11px;color:var(--ss-muted);margin-top:10px;">
            Terakhir sinkron: <?php echo $cfg['last_sync_at'] ? htmlspecialchars(date('d M Y, H:i', strtotime($cfg['last_sync_at']))) : 'belum pernah'; ?>
            <?php if ($cfg['last_sync_error'] !== ''): ?>
                <br><span style="color:#dc2626;">Error: <?php echo htmlspecialchars($cfg['last_sync_error']); ?></span>
            <?php endif; ?>
        </p>
        <form method="POST" style="margin-top:8px;">
            <input type="hidden" name="action" value="sync_now">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
            <button type="submit" class="ss-btn ss-btn-outline ss-btn-sm"><i data-feather="refresh-cw"></i> Sync Sekarang</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Koneksi ke ADF System</div>
        <p style="font-size:12px;color:var(--ss-muted);margin-bottom:8px;">Isi sekali saja dengan Client Key &amp; Client Token yang diberikan admin ADF System.</p>
        <form method="POST">
            <input type="hidden" name="action" value="save_connection">
            <div class="ss-form-group">
                <label class="ss-label">Client Key</label>
                <input class="ss-input" name="client_key" value="<?php echo htmlspecialchars(sunseaSetting($pdo, 'subscription_client_key', '')); ?>">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">Client Token</label>
                <input class="ss-input" name="client_token" value="<?php echo htmlspecialchars(sunseaSetting($pdo, 'subscription_client_token', '')); ?>">
            </div>
            <div class="ss-form-group">
                <label class="ss-label">URL Sinkronisasi</label>
                <input class="ss-input" name="sync_url" value="<?php echo htmlspecialchars(sunseaSetting($pdo, 'subscription_sync_url', 'https://adfsystem.store/api/subscription-config.php')); ?>">
            </div>
            <p style="font-size:12px;color:var(--ss-muted);">Webhook pembayaran: <code><?php echo htmlspecialchars(BASE_URL . '/api/subscription-webhook.php'); ?></code></p>
            <button class="ss-btn ss-btn-primary" type="submit"><i data-feather="save"></i> Simpan &amp; Sync</button>
        </form>
    </div>
</div>

<?php include 'layout-footer.php';
