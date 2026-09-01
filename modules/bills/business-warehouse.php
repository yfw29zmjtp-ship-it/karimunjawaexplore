<?php

/**
 * TAGIHAN BISNIS & GUDANG
 *
 * Setiap kali satu bisnis mengirim barang ke bisnis lain (termasuk Gudang Nasita),
 * transaksi itu tercatat di tabel master `business_inter_stock_transfers`.
 * Logikanya:
 * - Bisnis PENGIRIM (source) berarti PIUTANG — bisnis lain berhutang ke kita.
 *   Contoh: Narayana kirim 1 botol Amer ke Bens Cafe -> Bens Cafe berhutang ke Narayana.
 * - Bisnis PENERIMA (target) berarti HUTANG — kita harus membayar ke pengirim.
 *   Contoh: Narayana kirim roti ke Gudang Nasita -> Gudang berhutang ke Narayana,
 *   begitu juga sebaliknya jika Gudang/bisnis lain yang mengirim ke kita.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('bills')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Tagihan Bisnis & Gudang';

$bizConfig = getActiveBusinessConfig();
$activeSlug = strtolower(trim((string)($bizConfig['business_id'] ?? '')));
$activeName = (string)($bizConfig['name'] ?? '');

// Banyak transfer lama tersimpan dengan unit_price/subtotal = 0 (barang belum
// pernah diberi harga saat dikirim). Izinkan siapa saja dari bisnis terkait
// (pengirim atau penerima) mengisi harga langsung dari halaman ini.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_transfer_price') {
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    $newUnitPrice = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['unit_price'] ?? '0'));
    if ($transferId > 0 && $newUnitPrice > 0) {
        try {
            $masterDsnSet = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
            $masterPdoSet = new PDO($masterDsnSet, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $rowToUpdate = $masterPdoSet->prepare(
                "SELECT * FROM business_inter_stock_transfers WHERE id = ? AND (source_business_slug = ? OR target_business_slug = ?) LIMIT 1"
            );
            $rowToUpdate->execute([$transferId, $activeSlug, $activeSlug]);
            $row = $rowToUpdate->fetch();
            if ($row) {
                $newSubtotal = $newUnitPrice * (float)($row['quantity'] ?? 0);
                $upd = $masterPdoSet->prepare("UPDATE business_inter_stock_transfers SET unit_price = ?, subtotal = ? WHERE id = ?");
                $upd->execute([$newUnitPrice, $newSubtotal, $transferId]);
            }
        } catch (Throwable $e) {
            error_log('business-warehouse set_transfer_price error: ' . $e->getMessage());
        }
    }
    header('Location: business-warehouse.php');
    exit;
}

// Friendly names for every known business, used as fallback when a transfer's
// stored name is missing/blank.
$knownBusinessNames = [];
foreach (glob(__DIR__ . '/../../config/businesses/*.php') as $cfgFile) {
    $cfg = require $cfgFile;
    if (!empty($cfg['business_id'])) {
        $knownBusinessNames[strtolower($cfg['business_id'])] = $cfg['name'] ?? $cfg['business_id'];
    }
}

$transfers = [];
try {
    $masterDsn = 'mysql:host=' . DB_HOST . ';dbname=' . (defined('MASTER_DB_NAME') ? MASTER_DB_NAME : DB_NAME) . ';charset=' . DB_CHARSET;
    $masterPdo = new PDO($masterDsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $masterPdo->prepare(
        "SELECT * FROM business_inter_stock_transfers
         WHERE source_business_slug = ? OR target_business_slug = ?
         ORDER BY created_at DESC
         LIMIT 300"
    );
    $stmt->execute([$activeSlug, $activeSlug]);
    $transfers = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('business-warehouse tagihan error: ' . $e->getMessage());
    $transfers = [];
}

// Split into piutang (kita pengirim -> orang lain berhutang ke kita)
// dan hutang (kita penerima -> kita berhutang ke pengirim), dikelompokkan per mitra bisnis.
$piutangByPartner = [];
$hutangByPartner = [];
$piutangTotal = 0.0;
$hutangTotal = 0.0;

// Nama database Gudang Nasita, dipakai untuk menebak harga item yang belum
// punya unit_price/subtotal (mis. barang baru seperti "roti" yang belum
// pernah diberi harga saat dikirim). Tanpa ini, transfer dengan harga 0
// disembunyikan total dari daftar tagihan padahal transfernya nyata terjadi.
$gudangDbNameForPricing = '';
try {
    $gudangCfgPathForPricing = __DIR__ . '/../../config/businesses/gudang-nasita.php';
    if (file_exists($gudangCfgPathForPricing)) {
        $gudangCfgForPricing = include $gudangCfgPathForPricing;
        $gudangDbNameForPricing = (string)($gudangCfgForPricing['database'] ?? '');
    }
} catch (Throwable $e) {
    $gudangDbNameForPricing = '';
}

foreach ($transfers as $t) {
    $qty = (float)($t['quantity'] ?? 0);
    $unitPrice = (float)($t['unit_price'] ?? 0);
    $value = isset($t['subtotal']) && $t['subtotal'] !== null ? (float)$t['subtotal'] : ($qty * $unitPrice);
    $isEstimated = false;

    if ($value <= 0 && $gudangDbNameForPricing !== '') {
        try {
            $originDbForPricing = Database::getCurrentDatabase();
            $gudangDbForPricing = Database::switchDatabase($gudangDbNameForPricing);

            // Cari dulu di katalog master "Database Produk" (gudang_nasita_barang) berdasarkan
            // NAMA barang langsung — jangan mengandalkan barang_id di gudang_nasita_stock, karena
            // link itu bisa kosong/salah meski harga aslinya sudah ada di katalog.
            $estimatedPrice = 0.0;
            $hasBarangTable = (bool)$gudangDbForPricing->fetchOne("SHOW TABLES LIKE 'gudang_nasita_barang'");
            if ($hasBarangTable) {
                $barangPriceRow = $gudangDbForPricing->fetchOne(
                    "SELECT harga_beli FROM gudang_nasita_barang WHERE LOWER(TRIM(nama_barang)) = LOWER(TRIM(?)) LIMIT 1",
                    [(string)($t['item_name'] ?? '')]
                );
                $estimatedPrice = (float)($barangPriceRow['harga_beli'] ?? 0);
            }

            // Fallback: harga_beli yang tersimpan di stok gudang saat ini.
            if ($estimatedPrice <= 0) {
                $stockPriceRow = $gudangDbForPricing->fetchOne(
                    "SELECT harga_beli FROM gudang_nasita_stock
                     WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(unit)) = LOWER(TRIM(?))
                     LIMIT 1",
                    [(string)($t['item_name'] ?? ''), (string)($t['unit'] ?? '')]
                );
                $estimatedPrice = (float)($stockPriceRow['harga_beli'] ?? 0);
            }

            if ($estimatedPrice > 0) {
                $value = $estimatedPrice * $qty;
                $isEstimated = true;
            }
            if ($originDbForPricing !== '') {
                Database::switchDatabase($originDbForPricing);
            }
        } catch (Throwable $e) {
            error_log('business-warehouse estimasi harga error: ' . $e->getMessage());
        }
    }

    $t['_bw_value'] = $value;
    $t['_bw_estimated'] = $isEstimated;

    $sourceSlug = strtolower((string)($t['source_business_slug'] ?? ''));
    $targetSlug = strtolower((string)($t['target_business_slug'] ?? ''));

    if ($sourceSlug === $activeSlug) {
        $partnerSlug = $targetSlug;
        $partnerName = $t['target_business_name'] ?: ($knownBusinessNames[$partnerSlug] ?? $partnerSlug);
        if (!isset($piutangByPartner[$partnerSlug])) {
            $piutangByPartner[$partnerSlug] = ['name' => $partnerName, 'total' => 0.0, 'items' => []];
        }
        $piutangByPartner[$partnerSlug]['total'] += $value;
        $piutangByPartner[$partnerSlug]['items'][] = $t;
        $piutangTotal += $value;
    } elseif ($targetSlug === $activeSlug) {
        $partnerSlug = $sourceSlug;
        $partnerName = $t['source_business_name'] ?: ($knownBusinessNames[$partnerSlug] ?? $partnerSlug);
        if (!isset($hutangByPartner[$partnerSlug])) {
            $hutangByPartner[$partnerSlug] = ['name' => $partnerName, 'total' => 0.0, 'items' => []];
        }
        $hutangByPartner[$partnerSlug]['total'] += $value;
        $hutangByPartner[$partnerSlug]['items'][] = $t;
        $hutangTotal += $value;
    }
}

uasort($piutangByPartner, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
uasort($hutangByPartner, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});

$netPosition = $piutangTotal - $hutangTotal;

include '../../includes/header.php';
?>

<style>
    .bw-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 1.25rem 1rem 2rem;
        font-size: 0.875rem;
    }

    .bw-header {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
        margin-bottom: 1.5rem;
    }

    .bw-header .bw-header-icon {
        flex-shrink: 0;
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--primary-color), var(--primary-dark, var(--primary-color)));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }

    .bw-header h1 {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--text-primary);
        margin: 0 0 0.2rem;
        letter-spacing: -0.01em;
    }

    .bw-header p {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin: 0;
        max-width: 620px;
        line-height: 1.5;
    }

    .bw-summary {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 760px) {
        .bw-summary {
            grid-template-columns: 1fr;
        }
    }

    .bw-summary-card {
        position: relative;
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.1rem 1.25rem;
        overflow: hidden;
    }

    .bw-summary-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }

    .bw-summary-card.piutang::before {
        background: #059669;
    }

    .bw-summary-card.hutang::before {
        background: #dc2626;
    }

    .bw-summary-card.net::before {
        background: <?php echo $netPosition >= 0 ? '#059669' : '#dc2626'; ?>;
    }

    .bw-summary-card .label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.875rem;
        font-weight: 700;
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
    }

    .bw-summary-card .value {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.01em;
    }

    .bw-summary-card .sub {
        font-size: 0.875rem;
        color: var(--text-secondary);
        margin-top: 0.25rem;
    }

    .bw-summary-card.piutang .value {
        color: #059669;
    }

    .bw-summary-card.hutang .value {
        color: #dc2626;
    }

    .bw-summary-card.net .value {
        color: <?php echo $netPosition >= 0 ? '#059669' : '#dc2626'; ?>;
    }

    .bw-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        align-items: start;
    }

    @media (max-width: 900px) {
        .bw-columns {
            grid-template-columns: 1fr;
        }
    }

    .bw-section-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        font-weight: 800;
        margin: 0 0 0.9rem;
        padding-bottom: 0.6rem;
        border-bottom: 2px solid var(--border-color);
    }

    .bw-section-title .count {
        font-weight: 600;
        font-size: 0.875rem;
        color: var(--text-secondary);
        background: var(--card-bg, #f3f4f6);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        padding: 0.1rem 0.55rem;
        margin-left: auto;
    }

    .bw-section-title.piutang {
        color: #059669;
    }

    .bw-section-title.hutang {
        color: #dc2626;
    }

    .bw-partner-card {
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        margin-bottom: 0.75rem;
        overflow: hidden;
        transition: box-shadow 0.15s ease, transform 0.15s ease;
    }

    .bw-partner-card.open {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    }

    .bw-partner-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.85rem 1rem;
        cursor: pointer;
        gap: 0.75rem;
    }

    .bw-partner-name-wrap {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        min-width: 0;
    }

    .bw-partner-avatar {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.875rem;
        color: #fff;
    }

    .bw-partner-card.piutang .bw-partner-avatar {
        background: #059669;
    }

    .bw-partner-card.hutang .bw-partner-avatar {
        background: #dc2626;
    }

    .bw-partner-name {
        font-weight: 700;
        font-size: 0.875rem;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .bw-partner-name small {
        display: block;
        font-weight: 500;
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .bw-partner-total {
        font-weight: 800;
        font-size: 0.875rem;
        white-space: nowrap;
    }

    .bw-partner-card.piutang .bw-partner-total {
        color: #059669;
    }

    .bw-partner-card.hutang .bw-partner-total {
        color: #dc2626;
    }

    .bw-partner-items {
        display: none;
        border-top: 1px solid var(--border-color);
        background: rgba(0, 0, 0, 0.012);
    }

    .bw-partner-card.open .bw-partner-items {
        display: block;
    }

    .bw-item-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.65rem 1rem;
        font-size: 0.875rem;
        border-bottom: 1px solid var(--border-color);
        gap: 0.75rem;
    }

    .bw-item-row:last-child {
        border-bottom: none;
    }

    .bw-item-name {
        color: var(--text-primary);
        font-weight: 600;
    }

    .bw-item-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem;
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-top: 0.15rem;
    }

    .bw-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        font-size: 0.875rem;
        font-weight: 700;
        padding: 0.1rem 0.55rem;
        border-radius: 999px;
        white-space: nowrap;
    }

    .bw-badge.warn {
        background: #fef3c7;
        color: #b45309;
    }

    .bw-badge.danger {
        background: #fee2e2;
        color: #b91c1c;
    }

    .bw-item-value {
        font-weight: 700;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .bw-price-form {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.5rem;
    }

    .bw-price-form input[type="text"] {
        width: 120px;
        padding: 0.3rem 0.55rem;
        font-size: 0.875rem;
        border: 1px solid var(--border-color);
        border-radius: 8px;
    }

    .bw-price-form button {
        font-size: 0.875rem;
        font-weight: 700;
        padding: 0.3rem 0.75rem;
        border-radius: 8px;
        border: 1px solid var(--primary-color);
        background: var(--primary-color);
        color: #fff;
        cursor: pointer;
        transition: opacity 0.15s ease;
    }

    .bw-price-form button:hover {
        opacity: 0.88;
    }

    .bw-empty {
        color: var(--text-secondary);
        font-size: 0.875rem;
        padding: 1.5rem 1rem;
        background: var(--card-bg, #fff);
        border: 1px dashed var(--border-color);
        border-radius: 14px;
        text-align: center;
    }

    .bw-chevron {
        transition: transform 0.15s ease;
        color: var(--text-secondary);
        flex-shrink: 0;
    }

    .bw-partner-card.open .bw-chevron {
        transform: rotate(180deg);
    }
</style>

<div class="bw-container">
    <div class="bw-header">
        <div class="bw-header-icon">📊</div>
        <div>
            <h1>Tagihan Bisnis &amp; Gudang</h1>
            <p>Rekap piutang &amp; hutang antar bisnis dari transfer barang <strong><?php echo htmlspecialchars($activeName); ?></strong> ke/dari bisnis lain (termasuk Gudang Nasita).</p>
        </div>
    </div>

    <div class="bw-summary">
        <div class="bw-summary-card piutang">
            <div class="label">💰 Total Piutang (Ditagih)</div>
            <div class="value">Rp <?php echo number_format($piutangTotal, 0, ',', '.'); ?></div>
            <div class="sub">Bisnis lain berhutang ke kami</div>
        </div>
        <div class="bw-summary-card hutang">
            <div class="label">📥 Total Hutang (Harus Dibayar)</div>
            <div class="value">Rp <?php echo number_format($hutangTotal, 0, ',', '.'); ?></div>
            <div class="sub">Kami berhutang ke bisnis lain</div>
        </div>
        <div class="bw-summary-card net">
            <div class="label">⚖️ Posisi Bersih</div>
            <div class="value">Rp <?php echo number_format(abs($netPosition), 0, ',', '.'); ?></div>
            <div class="sub"><?php echo $netPosition >= 0 ? 'Bersih menerima (Piutang)' : 'Bersih membayar (Hutang)'; ?></div>
        </div>
    </div>

    <div class="bw-columns">
        <div>
            <div class="bw-section-title piutang">💰 Piutang — Berhutang ke Kami <span class="count"><?php echo count($piutangByPartner); ?> mitra</span></div>
            <?php if (empty($piutangByPartner)): ?>
                <div class="bw-empty">Belum ada barang yang kami kirim ke bisnis lain.</div>
            <?php else: ?>
                <?php foreach ($piutangByPartner as $partner): ?>
                    <div class="bw-partner-card piutang">
                        <div class="bw-partner-header" onclick="this.closest('.bw-partner-card').classList.toggle('open')">
                            <div class="bw-partner-name-wrap">
                                <div class="bw-partner-avatar"><?php echo htmlspecialchars(strtoupper(substr($partner['name'], 0, 1))); ?></div>
                                <div class="bw-partner-name">
                                    <?php echo htmlspecialchars($partner['name']); ?>
                                    <small><?php echo count($partner['items']); ?> item transfer</small>
                                </div>
                            </div>
                            <span style="display:flex; align-items:center; gap:0.5rem;">
                                <span class="bw-partner-total">Rp <?php echo number_format($partner['total'], 0, ',', '.'); ?></span>
                                <span class="bw-chevron">&#9662;</span>
                            </span>
                        </div>
                        <div class="bw-partner-items">
                            <?php foreach ($partner['items'] as $item): ?>
                                <?php $itemValue = (float)($item['_bw_value'] ?? 0); ?>
                                <div class="bw-item-row">
                                    <div style="min-width:0;">
                                        <div class="bw-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="bw-item-meta">
                                            <span><?php echo number_format((float)$item['quantity'], 0, ',', '.'); ?> <?php echo htmlspecialchars($item['unit']); ?></span>
                                            <span>&middot;</span>
                                            <span><?php echo date('d M Y', strtotime($item['created_at'])); ?></span>
                                            <?php if (!empty($item['_bw_estimated'])): ?><span class="bw-badge warn">Harga diperkirakan</span><?php endif; ?>
                                            <?php if ($itemValue <= 0): ?><span class="bw-badge danger">Belum ada harga</span><?php endif; ?>
                                        </div>
                                        <?php if ($itemValue <= 0): ?>
                                        <form method="post" class="bw-price-form">
                                            <input type="hidden" name="action" value="set_transfer_price">
                                            <input type="hidden" name="transfer_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="text" name="unit_price" placeholder="Harga per unit" required>
                                            <button type="submit">Simpan</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="bw-item-value">Rp <?php echo number_format($itemValue, 0, ',', '.'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="bw-section-title hutang">📥 Hutang — Kami Berhutang <span class="count"><?php echo count($hutangByPartner); ?> mitra</span></div>
            <?php if (empty($hutangByPartner)): ?>
                <div class="bw-empty">Belum ada barang yang kami terima dari bisnis lain.</div>
            <?php else: ?>
                <?php foreach ($hutangByPartner as $partner): ?>
                    <div class="bw-partner-card hutang">
                        <div class="bw-partner-header" onclick="this.closest('.bw-partner-card').classList.toggle('open')">
                            <div class="bw-partner-name-wrap">
                                <div class="bw-partner-avatar"><?php echo htmlspecialchars(strtoupper(substr($partner['name'], 0, 1))); ?></div>
                                <div class="bw-partner-name">
                                    <?php echo htmlspecialchars($partner['name']); ?>
                                    <small><?php echo count($partner['items']); ?> item transfer</small>
                                </div>
                            </div>
                            <span style="display:flex; align-items:center; gap:0.5rem;">
                                <span class="bw-partner-total">Rp <?php echo number_format($partner['total'], 0, ',', '.'); ?></span>
                                <span class="bw-chevron">&#9662;</span>
                            </span>
                        </div>
                        <div class="bw-partner-items">
                            <?php foreach ($partner['items'] as $item): ?>
                                <?php $itemValue = (float)($item['_bw_value'] ?? 0); ?>
                                <div class="bw-item-row">
                                    <div style="min-width:0;">
                                        <div class="bw-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="bw-item-meta">
                                            <span><?php echo number_format((float)$item['quantity'], 0, ',', '.'); ?> <?php echo htmlspecialchars($item['unit']); ?></span>
                                            <span>&middot;</span>
                                            <span><?php echo date('d M Y', strtotime($item['created_at'])); ?></span>
                                            <?php if (!empty($item['_bw_estimated'])): ?><span class="bw-badge warn">Harga diperkirakan</span><?php endif; ?>
                                            <?php if ($itemValue <= 0): ?><span class="bw-badge danger">Belum ada harga</span><?php endif; ?>
                                        </div>
                                        <?php if ($itemValue <= 0): ?>
                                        <form method="post" class="bw-price-form">
                                            <input type="hidden" name="action" value="set_transfer_price">
                                            <input type="hidden" name="transfer_id" value="<?php echo (int)$item['id']; ?>">
                                            <input type="text" name="unit_price" placeholder="Harga per unit" required>
                                            <button type="submit">Simpan</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="bw-item-value">Rp <?php echo number_format($itemValue, 0, ',', '.'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
