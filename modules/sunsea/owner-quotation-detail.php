<?php

/**
 * Sunsea - Owner mobile view: Detail Penawaran (read-only) + kirim WA
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
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT q.*, c.name AS customer_name, c.phone AS customer_phone, c.whatsapp AS customer_whatsapp,
           p.name AS package_name
    FROM quotations q
    JOIN customers c ON c.id = q.customer_id
    LEFT JOIN trip_packages p ON p.id = q.package_id
    WHERE q.id = ?
");
$stmt->execute([$id]);
$quotation = $stmt->fetch();

if (!$quotation) {
    header('Location: owner-dashboard.php');
    exit;
}

$qItems = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id");
$qItems->execute([$id]);
$qItems = $qItems->fetchAll();

$statusBadge = [
    'draft'     => ['ob-badge-draft', 'Draft'],
    'sent'      => ['ob-badge-partial', 'Terkirim'],
    'approved'  => ['ob-badge-confirmed', 'Approved'],
    'rejected'  => ['ob-badge-issued', 'Ditolak'],
    'expired'   => ['ob-badge-issued', 'Kadaluarsa'],
    'converted' => ['ob-badge-paid', 'Converted'],
];
$badge = $statusBadge[$quotation['status']] ?? ['ob-badge-draft', $quotation['status']];

$waPhone = $quotation['customer_whatsapp'] ?: $quotation['customer_phone'];
$waShareUrl = rtrim(BASE_URL, '/') . '/modules/sunsea/quotations.php?action=print&id=' . $quotation['id'] . '&share=' . sunseaShareToken('quotation', (int)$quotation['id']);
$waMessage = "Halo {$quotation['customer_name']}, berikut penawaran perjalanan dari " . sunseaSetting($pdo, 'company_name', 'Explore Karimunjawa') . " nomor {$quotation['quotation_no']} sebesar " . sunseaRupiah((float)$quotation['total_amount']) . ". Berlaku sampai " . ($quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-') . ". Lihat/download PDF penawaran di sini: {$waShareUrl}\nTerima kasih.";
$waLink = $waPhone ? sunseaWaLink($waPhone, $waMessage) : '';

$pageTitle = 'Detail Penawaran';
include 'owner-mobile-header.php';
?>

<div class="ob-section">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
        <div>
            <div style="font-size:11px;color:var(--muted);font-weight:600;"><?php echo htmlspecialchars($quotation['quotation_no']); ?></div>
            <div style="font-size:17px;font-weight:800;">untuk <?php echo htmlspecialchars($quotation['customer_name']); ?></div>
        </div>
        <span class="ob-badge <?php echo $badge[0]; ?>"><?php echo htmlspecialchars($badge[1]); ?></span>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
        <a href="quotations.php?action=edit&id=<?php echo (int)$quotation['id']; ?>" class="ob-qbtn" style="flex:1;">
            <i data-feather="edit-2"></i> Edit
        </a>
        <?php if ($waLink): ?>
            <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" rel="noopener" class="ob-qbtn" style="flex:1;background:#25D366;border-color:#25D366;color:#fff;">
                <i data-feather="message-circle"></i> Kirim Penawaran
            </a>
        <?php endif; ?>
    </div>

    <div class="ob-detail-label">Tanggal Trip</div>
    <div class="ob-detail-value"><?php echo $quotation['trip_date'] ? date('d M Y', strtotime($quotation['trip_date'])) : '-'; ?></div>

    <div class="ob-detail-label">Peserta</div>
    <div class="ob-detail-value"><?php echo (int)$quotation['pax_count']; ?> orang</div>

    <?php if ($quotation['package_name']): ?>
        <div class="ob-detail-label">Paket</div>
        <div class="ob-detail-value"><?php echo htmlspecialchars($quotation['package_name']); ?></div>
    <?php endif; ?>

    <div class="ob-detail-label">Berlaku s/d</div>
    <div class="ob-detail-value"><?php echo $quotation['valid_until'] ? date('d M Y', strtotime($quotation['valid_until'])) : '-'; ?></div>

    <div class="ob-detail-label">Total Penawaran</div>
    <div class="ob-detail-value" style="color:var(--ocean);font-size:16px;"><?php echo sunseaRupiah((float)$quotation['total_amount']); ?></div>
</div>

<div class="ob-section">
    <div class="ob-section-head">
        <div class="ob-section-title">Rincian Item</div>
    </div>
    <?php if (empty($qItems)): ?>
        <div class="ob-empty">Belum ada item.</div>
    <?php else: ?>
        <?php foreach ($qItems as $it): ?>
            <div class="ob-item-row">
                <div>
                    <?php echo htmlspecialchars($it['description']); ?>
                    <div style="color:var(--muted);font-size:10.5px;"><?php echo (float)$it['qty']; ?> <?php echo htmlspecialchars($it['unit']); ?> × <?php echo sunseaRupiah((float)$it['unit_price']); ?></div>
                </div>
                <div style="font-weight:700;"><?php echo sunseaRupiah((float)$it['subtotal']); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!empty($quotation['notes'])): ?>
    <div class="ob-section">
        <div class="ob-section-head">
            <div class="ob-section-title">Catatan</div>
        </div>
        <div style="font-size:12px;color:var(--text);white-space:pre-wrap;"><?php echo htmlspecialchars($quotation['notes']); ?></div>
    </div>
<?php endif; ?>

<?php include 'owner-mobile-footer.php'; ?>
