<?php

/**
 * Sunsea - Owner mobile view: Tagihan Langganan (ADF System)
 * Tampilan HP khusus portal owner: tagihan aktif, tombol bayar (mulai H-3 jatuh tempo),
 * ringkasan tarif, dan riwayat tagihan dengan rincian per bulan.
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
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner', 'manager'], true)) {
    header('Location: owner-dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureSubscriptionBillingSchema($pdo);
sunseaEnsureBookingSchema($pdo);

// ---- Aksi: bayar via Pakasir (nominal terbaru dari ADF Store; hanya mulai H-3 jatuh tempo) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    sunseaSyncLedger($pdo, true);
    $ref = trim($_POST['period'] ?? '');
    $cfg = sunseaSubscriptionConfig($pdo);
    $q = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $q->execute([$ref]);
    $inv = $q->fetch(PDO::FETCH_ASSOC);

    $err = '';
    if (!$inv || $inv['status'] !== 'unpaid') {
        $err = 'Tagihan tidak ditemukan atau sudah tidak berstatus belum dibayar.';
    } elseif (!sunseaPayWindowOpen($inv)) {
        $err = 'Pembayaran dibuka mulai ' . date('d M Y', strtotime(sunseaPayOpenDate($inv))) . ' (H-' . SUNSEA_PAY_OPEN_DAYS_BEFORE . ' jatuh tempo).';
    } elseif (!sunseaSubscriptionIsConfigured($cfg)) {
        $err = 'Pengaturan pembayaran belum lengkap. Hubungi ADF System.';
    } else {
        $orderId = 'SUB' . str_replace('-', '', $ref) . strtoupper(bin2hex(random_bytes(3)));
        $result = sunseaPakasirCreatePaymentLink($cfg, $orderId, (int) round((float) $inv['total_amount']));
        if ($result === null) {
            $err = 'Gagal membuat link pembayaran. Coba lagi sebentar.';
        } else {
            $pdo->prepare("UPDATE subscription_invoices SET order_id=?, txn_id=?, payment_link=? WHERE period=?")
                ->execute([$orderId, $result['txn_id'], $result['payment_link'], $ref]);
            header('Location: ' . $result['payment_link']);
            exit;
        }
    }
    $_SESSION['os_flash'] = ['type' => 'error', 'msg' => $err];
    header('Location: owner-subscription.php');
    exit;
}

// ---- Aksi: cek status pembayaran ke Pakasir ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    $ref = trim($_POST['period'] ?? '');
    $cfg = sunseaSubscriptionConfig($pdo);
    $q = $pdo->prepare("SELECT * FROM subscription_invoices WHERE period = ? LIMIT 1");
    $q->execute([$ref]);
    $inv = $q->fetch(PDO::FETCH_ASSOC);
    $flash = ['type' => 'info', 'msg' => 'Belum ada perubahan status.'];
    if ($inv && !empty($inv['txn_id'])) {
        $status = sunseaPakasirTransactionStatus($cfg, $inv['txn_id']);
        if ($status !== null && ($status['status'] ?? '') === 'completed') {
            $paidAt = $status['completed_at'] ?? date('c');
            $pdo->prepare("UPDATE subscription_invoices SET status='paid', paid_at=? WHERE period=?")->execute([$paidAt, $ref]);
            $inv['status'] = 'paid';
            $inv['paid_at'] = $paidAt;
            sunseaNotifyAdfSystemPaymentSuccess($pdo, $inv);
            $flash = ['type' => 'success', 'msg' => 'Pembayaran sudah diterima. Terima kasih!'];
        } else {
            $flash = ['type' => 'info', 'msg' => 'Belum ada pembayaran masuk (status: ' . ($status['status'] ?? 'menunggu') . ').'];
        }
    }
    $_SESSION['os_flash'] = $flash;
    header('Location: owner-subscription.php');
    exit;
}

// ---- Sinkron (maks. tiap 5 menit) + data ----
$lastSyncAt = sunseaSetting($pdo, 'subscription_last_sync_at', '');
if ($lastSyncAt === '' || (time() - strtotime($lastSyncAt)) > 300) {
    sunseaSyncSubscriptionConfig($pdo);
    sunseaSyncManualInvoices($pdo);
}
sunseaGetOrRefreshSubscriptionInvoice($pdo, date('Y-m'));
$subscriptionReminder = sunseaGetSubscriptionReminder($pdo); // juga merekonsiliasi pembayaran yang tertunda

$cfg = sunseaSubscriptionConfig($pdo);
$configured = sunseaSubscriptionIsConfigured($cfg);
$invoices = $pdo->query("SELECT * FROM subscription_invoices WHERE status != 'cancelled' ORDER BY (status = 'unpaid') DESC, due_date DESC, period DESC LIMIT 24")->fetchAll(PDO::FETCH_ASSOC);

// Tagihan aktif = yang belum dibayar dengan jatuh tempo terdekat
$active = null;
foreach ($invoices as $row) {
    if ($row['status'] === 'unpaid' && (!$active || strtotime($row['due_date'] ?: '2999-01-01') < strtotime($active['due_date'] ?: '2999-01-01'))) {
        $active = $row;
    }
}
$history = array_values(array_filter($invoices, static fn($r) => !$active || $r['id'] !== $active['id']));

$paxThisMonth = (int) sunseaCalculateSubscriptionCharge($pdo, date('Y-m'))['guest_count'];
$flash = $_SESSION['os_flash'] ?? null;
unset($_SESSION['os_flash']);

$bulanId = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];
$periodLabel = static function (array $inv) use ($bulanId): string {
    $p = (string) $inv['period'];
    if (preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return ($bulanId[$m[2]] ?? $m[2]) . ' ' . $m[1];
    if (preg_match('/^TOPUP-(\d{4})-(\d{2})/', $p, $m)) return 'Susulan ' . ($bulanId[$m[2]] ?? $m[2]) . ' ' . $m[1];
    return $inv['description'] ?: 'Tagihan Manual';
};
$isManualOnly = static fn(array $inv): bool => ($inv['type'] ?? 'recurring') === 'manual' && strpos((string) $inv['period'], 'TOPUP-') !== 0;
$daysLeft = static function (array $inv): ?int {
    return empty($inv['due_date']) ? null : (int) ceil((strtotime($inv['due_date']) - strtotime(date('Y-m-d'))) / 86400);
};

/** Baris rincian: [label, nominal(+/-)]. */
$lines = static function (array $inv) use ($isManualOnly): array {
    if ($isManualOnly($inv)) {
        return [[$inv['description'] ?: 'Tagihan Manual', (float) $inv['total_amount']]];
    }
    $out = [];
    if ((float) $inv['base_fee'] > 0) $out[] = ['Biaya bulanan', (float) $inv['base_fee']];
    if ((int) $inv['guest_count'] > 0) $out[] = [(int) $inv['guest_count'] . ' tamu × ' . sunseaRupiah((float) $inv['per_guest_fee']), (float) $inv['guest_total']];
    $adj = (float) $inv['total_amount'] - (float) $inv['base_fee'] - (float) $inv['guest_total'];
    if (abs($adj) > 0.5) $out[] = ['Penyesuaian', $adj];
    return $out;
};

$pageTitle = 'Tagihan Langganan';
$backUrl = 'owner-dashboard.php';
include 'owner-mobile-header.php';
?>
<style>
    .os-flash { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; font-size: 13px; font-weight: 600; margin-bottom: 12px; }
    .os-flash-success { background: #DCFCE7; color: #166534; }
    .os-flash-error { background: #FEE2E2; color: #991B1B; }
    .os-flash-info { background: #E0F2FE; color: #075985; }
    .os-hero { background: linear-gradient(145deg, #0369A1 0%, #0EA5E9 100%); color: #fff; border-radius: 20px; padding: 18px; box-shadow: 0 12px 28px rgba(3, 105, 161, .25); margin-bottom: 14px; }
    .os-hero-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
    .os-eyebrow { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; opacity: .85; }
    .os-chip { font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 999px; background: rgba(255, 255, 255, .22); white-space: nowrap; }
    .os-chip.warn { background: #FEF3C7; color: #92400E; }
    .os-chip.late { background: #FEE2E2; color: #991B1B; }
    .os-period { font-size: 19px; font-weight: 800; margin: 8px 0 2px; }
    .os-total { font-size: 32px; font-weight: 900; letter-spacing: -.02em; line-height: 1.15; }
    .os-due { font-size: 12.5px; opacity: .9; margin-top: 2px; }
    .os-lines { margin: 14px 0 4px; padding: 10px 12px; background: rgba(255, 255, 255, .14); border-radius: 12px; }
    .os-line { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 4px 0; }
    .os-line span:first-child { opacity: .9; }
    .os-line b { white-space: nowrap; }
    .os-note { font-size: 11.5px; opacity: .85; margin-top: 8px; line-height: 1.45; }
    .os-actions { display: flex; gap: 8px; margin-top: 14px; }
    .os-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; border: 0; border-radius: 12px; padding: 13px 14px; font-size: 14px; font-weight: 800; cursor: pointer; text-decoration: none; font-family: inherit; }
    .os-btn-pay { background: #fff; color: #0369A1; }
    .os-btn-ghost { background: rgba(255, 255, 255, .2); color: #fff; flex: 0 0 auto; }
    .os-btn svg { width: 16px; height: 16px; }
    .os-wait { margin-top: 14px; padding: 11px 12px; border-radius: 12px; background: rgba(255, 255, 255, .16); font-size: 12.5px; line-height: 1.45; display: flex; gap: 8px; align-items: flex-start; }
    .os-wait svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
    .os-clear { background: #fff; border: 1px solid var(--border); border-radius: 18px; padding: 22px 16px; text-align: center; margin-bottom: 14px; }
    .os-clear-ico { width: 48px; height: 48px; margin: 0 auto 8px; border-radius: 50%; background: #DCFCE7; color: #059669; display: flex; align-items: center; justify-content: center; }
    .os-clear-ico svg { width: 24px; height: 24px; }
    .os-clear strong { display: block; font-size: 15px; }
    .os-clear span { font-size: 12.5px; color: var(--muted); }
    .os-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 18px; }
    .os-stat { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 11px 10px; }
    .os-stat span { display: block; font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; }
    .os-stat b { display: block; font-size: 13px; margin-top: 3px; white-space: nowrap; }
    .os-section-title { font-size: 13px; font-weight: 800; margin: 0 2px 8px; color: var(--text); }
    .os-item { background: #fff; border: 1px solid var(--border); border-radius: 16px; margin-bottom: 8px; overflow: hidden; }
    .os-item summary { list-style: none; display: flex; align-items: center; gap: 10px; padding: 13px 14px; cursor: pointer; }
    .os-item summary::-webkit-details-marker { display: none; }
    .os-item-main { flex: 1; min-width: 0; }
    .os-item-main strong { display: block; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .os-item-main small { font-size: 11.5px; color: var(--muted); }
    .os-item-amt { text-align: right; }
    .os-item-amt b { display: block; font-size: 14px; }
    .os-badge { display: inline-block; margin-top: 3px; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
    .os-badge-paid { background: #DCFCE7; color: #166534; }
    .os-badge-unpaid { background: #FEF3C7; color: #92400E; }
    .os-chev { width: 16px; height: 16px; color: var(--muted); flex-shrink: 0; transition: transform .15s; }
    .os-item[open] .os-chev { transform: rotate(180deg); }
    .os-detail { padding: 2px 14px 14px; border-top: 1px dashed var(--border); }
    .os-detail .os-line { color: var(--text); }
    .os-detail .os-line span:first-child { color: var(--muted); opacity: 1; }
    .os-detail .os-line.total { border-top: 1px solid var(--border); margin-top: 6px; padding-top: 9px; font-weight: 800; }
    .os-meta { font-size: 11.5px; color: var(--muted); line-height: 1.6; margin-top: 6px; }
    .os-link { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 9px 12px; border-radius: 10px; background: var(--sky); border: 1px solid #BAE6FD; color: var(--ocean); font-size: 12px; font-weight: 800; text-decoration: none; }
    .os-link svg { width: 13px; height: 13px; }
</style>

<div class="os-wrap">
    <?php if ($flash): ?>
        <div class="os-flash os-flash-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['msg']); ?></div>
    <?php endif; ?>

    <?php if ($active):
        $dl = $daysLeft($active);
        $overdue = $dl !== null && $dl < 0;
        $chipClass = $overdue ? 'late' : (($dl !== null && $dl <= SUNSEA_PAY_OPEN_DAYS_BEFORE) ? 'warn' : '');
        $chipText = $dl === null ? 'Belum dibayar' : ($overdue ? 'Terlambat ' . abs($dl) . ' hari' : ($dl === 0 ? 'Jatuh tempo hari ini' : 'H-' . $dl));
        $payOpen = sunseaPayWindowOpen($active);
    ?>
        <section class="os-hero">
            <div class="os-hero-top">
                <span class="os-eyebrow">Tagihan aktif</span>
                <span class="os-chip <?php echo $chipClass; ?>"><?php echo htmlspecialchars($chipText); ?></span>
            </div>
            <div class="os-period"><?php echo htmlspecialchars($periodLabel($active)); ?></div>
            <div class="os-total"><?php echo sunseaRupiah((float) $active['total_amount']); ?></div>
            <?php if (!empty($active['due_date'])): ?>
                <div class="os-due">Jatuh tempo <?php echo htmlspecialchars(date('d M Y', strtotime($active['due_date']))); ?></div>
            <?php endif; ?>

            <div class="os-lines">
                <?php foreach ($lines($active) as [$lbl, $amt]): ?>
                    <div class="os-line"><span><?php echo htmlspecialchars($lbl); ?></span><b><?php echo ($amt < 0 ? '-' : '') . sunseaRupiah(abs($amt)); ?></b></div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($active['description']) && !$isManualOnly($active)): ?>
                <div class="os-note"><?php echo htmlspecialchars($active['description']); ?></div>
            <?php endif; ?>

            <?php if (!$configured): ?>
                <div class="os-wait"><i data-feather="alert-circle"></i><span>Pengaturan pembayaran belum lengkap. Hubungi ADF System.</span></div>
            <?php elseif (!$payOpen): ?>
                <div class="os-wait"><i data-feather="clock"></i><span>Tombol bayar dibuka mulai <b><?php echo htmlspecialchars(date('d M Y', strtotime(sunseaPayOpenDate($active)))); ?></b> (H-<?php echo SUNSEA_PAY_OPEN_DAYS_BEFORE; ?> jatuh tempo). Tamu yang terdaftar setelah tagihan dibayar ditagihkan di bulan berikutnya.</span></div>
            <?php else: ?>
                <div class="os-actions">
                    <form method="POST" style="flex:1;display:flex;">
                        <input type="hidden" name="action" value="pay">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($active['period']); ?>">
                        <button type="submit" class="os-btn os-btn-pay"><i data-feather="credit-card"></i> Bayar Sekarang</button>
                    </form>
                    <?php if (!empty($active['txn_id'])): ?>
                        <form method="POST" style="display:flex;">
                            <input type="hidden" name="action" value="check_status">
                            <input type="hidden" name="period" value="<?php echo htmlspecialchars($active['period']); ?>">
                            <button type="submit" class="os-btn os-btn-ghost" title="Cek status pembayaran"><i data-feather="refresh-cw"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="os-clear">
            <div class="os-clear-ico"><i data-feather="check"></i></div>
            <strong>Semua tagihan sudah lunas</strong>
            <span>Tagihan berikutnya muncul otomatis setelah bulan berjalan.</span>
        </section>
    <?php endif; ?>

    <div class="os-stats">
        <div class="os-stat"><span>Bulanan</span><b><?php echo sunseaRupiah((float) $cfg['base_fee']); ?></b></div>
        <div class="os-stat"><span>Per tamu</span><b><?php echo sunseaRupiah((float) $cfg['per_guest_fee']); ?></b></div>
        <div class="os-stat"><span>Tamu bulan ini</span><b><?php echo $paxThisMonth; ?></b></div>
    </div>

    <div class="os-section-title">Riwayat tagihan</div>
    <?php foreach ($history as $inv):
        $isPaid = $inv['status'] === 'paid';
    ?>
        <details class="os-item">
            <summary>
                <div class="os-item-main">
                    <strong><?php echo htmlspecialchars($periodLabel($inv)); ?></strong>
                    <small><?php echo $isPaid && $inv['paid_at'] ? 'Dibayar ' . htmlspecialchars(date('d M Y', strtotime($inv['paid_at']))) : (!empty($inv['due_date']) ? 'Jatuh tempo ' . htmlspecialchars(date('d M Y', strtotime($inv['due_date']))) : '-'); ?></small>
                </div>
                <div class="os-item-amt">
                    <b><?php echo sunseaRupiah((float) $inv['total_amount']); ?></b>
                    <span class="os-badge <?php echo $isPaid ? 'os-badge-paid' : 'os-badge-unpaid'; ?>"><?php echo $isPaid ? 'Lunas' : 'Belum dibayar'; ?></span>
                </div>
                <i data-feather="chevron-down" class="os-chev"></i>
            </summary>
            <div class="os-detail">
                <?php foreach ($lines($inv) as [$lbl, $amt]): ?>
                    <div class="os-line"><span><?php echo htmlspecialchars($lbl); ?></span><b><?php echo ($amt < 0 ? '-' : '') . sunseaRupiah(abs($amt)); ?></b></div>
                <?php endforeach; ?>
                <div class="os-line total"><span>Total</span><b><?php echo sunseaRupiah((float) $inv['total_amount']); ?></b></div>
                <?php if (!empty($inv['description']) && !$isManualOnly($inv)): ?>
                    <div class="os-meta"><?php echo htmlspecialchars($inv['description']); ?></div>
                <?php endif; ?>
                <?php if ($isPaid): ?>
                    <a class="os-link" href="subscription-invoice-print.php?period=<?php echo urlencode($inv['period']); ?>" target="_blank"><i data-feather="file-text"></i> Lihat bukti pembayaran</a>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
    <?php if (!$history): ?>
        <div class="os-clear"><span>Belum ada riwayat tagihan.</span></div>
    <?php endif; ?>
</div>
<?php include 'owner-mobile-footer.php'; ?>
