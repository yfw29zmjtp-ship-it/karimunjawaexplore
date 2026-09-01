<?php

/**
 * Gudang Nasita — Dashboard
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/procurement_functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!($auth->hasPermission('gudang_nasita') || $auth->hasPermission('warehouse') || $auth->hasPermission('gudang_view'))) {
    http_response_code(403);
    echo 'Akses ditolak.';
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Dashboard Gudang';

// ── Histori PO ke Supplier (PO gudang sendiri ke supplier) ────────────────────
$poSupplierHistory = $db->fetchAll(
    "SELECT poh.id, poh.po_number, poh.po_date, poh.status, poh.created_at,
            COUNT(pod.id) AS items_count
     FROM purchase_orders_header poh
     LEFT JOIN purchase_orders_detail pod ON pod.po_header_id = poh.id
     WHERE poh.business_id IS NULL AND poh.po_number LIKE 'GDN-%'
     GROUP BY poh.id
     ORDER BY COALESCE(poh.po_date, poh.created_at) DESC LIMIT 8"
) ?: [];

// ── Histori Barang Datang (penerimaan dari PO supplier) ───────────────────────
$barangDatangHistory = $db->fetchAll(
    "SELECT gm.quantity, gm.reference_number AS po_number, gm.notes,
            COALESCE(gm.movement_date, gm.created_at) AS tgl,
            gs.item_name, gs.unit
     FROM gudang_nasita_movements gm
     JOIN gudang_nasita_stock gs ON gm.stock_id = gs.id
     WHERE gm.movement_type = 'in_supplier'
     ORDER BY COALESCE(gm.movement_date, gm.created_at) DESC LIMIT 8"
) ?: [];

// ── Histori terkirim per bisnis (ringkasan) ───────────────────────────────────
$terkirimPerBisnis = $db->fetchAll(
    "SELECT COALESCE(target_business_name, bisnis_tujuan, 'Lainnya') AS bisnis,
            COUNT(*) AS total_transfer,
            COALESCE(SUM(total_qty),0) AS total_qty,
            MAX(COALESCE(tanggal_transfer, transfer_date, created_at)) AS terakhir
     FROM gudang_nasita_transfers
     GROUP BY COALESCE(target_business_name, bisnis_tujuan, 'Lainnya')
     ORDER BY total_qty DESC"
) ?: [];

// ── Lonceng PO: PO masuk dari bisnis yang belum diproses gudang ───────────────
$pendingBusinessPo = [];
try {
    $pendingBusinessPo = getGudangNasitaPendingBusinessPo();
} catch (Throwable $e) {
    error_log('Dashboard Gudang pending PO error: ' . $e->getMessage());
}
$pendingPoCount = count($pendingBusinessPo);

$forceTheme = 'light';
include __DIR__ . '/../../includes/header.php';
?>

<style>
    .gd-section-title {
        font-size: .95rem;
        font-weight: 700;
        color: var(--text-primary);
        margin: 0 0 .85rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .gd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .83rem;
    }

    .gd-table th {
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--text-muted);
        padding: .45rem .7rem;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .gd-table td {
        padding: .55rem .7rem;
        border-bottom: 1px solid var(--border);
    }

    .gd-table tr:last-child td {
        border-bottom: none;
    }

    .gd-badge {
        display: inline-block;
        padding: .2rem .6rem;
        border-radius: 999px;
        font-size: .7rem;
        font-weight: 700;
    }

    .gd-empty {
        text-align: center;
        padding: 2rem 1rem;
        color: var(--text-muted);
        font-size: .85rem;
    }

    .gd-card {
        border-radius: 1rem;
        padding: 1.25rem;
    }
</style>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
    <div>
        <h2 style="font-size:1.45rem;font-weight:800;margin:0;color:var(--text-primary);display:flex;align-items:center;gap:.6rem;">
            Dashboard Gudang Nasita
            <?php if ($pendingPoCount > 0): ?>
                <span style="background:#ef4444;color:#fff;border-radius:999px;padding:.2rem .55rem;font-size:.75rem;font-weight:800;">🔔 PO Masuk: <?php echo (int)$pendingPoCount; ?></span>
            <?php endif; ?>
        </h2>
        <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;">Ringkasan operasional gudang hari ini &mdash; <?php echo date('l, d F Y'); ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-nasita.php" class="btn btn-primary" style="font-size:.82rem;"><i data-feather="archive" style="width:14px;height:14px;"></i> Stock Gudang</a>
        <a href="<?php echo BASE_URL; ?>/modules/procurement/gudang-transfer.php" class="btn btn-success" style="font-size:.82rem;"><i data-feather="send" style="width:14px;height:14px;"></i> Transfer</a>
    </div>
</div>

<!-- PO Masuk dari Bisnis (lonceng) -->
<div class="card gd-card" style="margin-bottom:1.25rem;<?php echo $pendingPoCount > 0 ? 'border-left:3px solid #ef4444;' : ''; ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">
        <div class="gd-section-title" style="margin:0;color:#ef4444;">🔔 PO Masuk dari Bisnis</div>
        <?php if ($pendingPoCount > 0): ?><span class="gd-badge" style="background:#fee2e2;color:#991b1b;"><?php echo $pendingPoCount; ?> menunggu</span><?php endif; ?>
    </div>
    <?php if (empty($pendingBusinessPo)): ?>
        <div class="gd-empty">Tidak ada PO menunggu diproses</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>No PO</th>
                        <th>Bisnis</th>
                        <th>Tanggal</th>
                        <th>Item</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingBusinessPo as $bp):
                        $bpsc = match (strtolower($bp['status'] ?? '')) {
                            'approved', 'partially_received' => ['#dbeafe', '#1e40af'],
                            default => ['#fef3c7', '#92400e']
                        };
                        $bpDetail = [
                            'title' => (string)($bp['po_number'] ?? '-'),
                            'subtitle' => trim(($bp['source_business_name'] ?? '-') . ' • ' . ($bp['po_date'] ? date('d M Y', strtotime($bp['po_date'])) : '-') . ' • ' . strtoupper(str_replace('_', ' ', $bp['status'] ?? '-'))),
                            'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'Harga', 'right' => true], ['label' => 'Subtotal', 'right' => true]],
                            'rows' => array_map(function ($it) {
                                $qty = (float)($it['quantity'] ?? 0);
                                $price = (float)($it['unit_price'] ?? 0);
                                $subtotal = (float)($it['total_price'] ?? 0);
                                if ($subtotal <= 0) {
                                    $subtotal = $qty * $price;
                                }
                                return [
                                    (string)($it['item_name'] ?? '-'),
                                    number_format($qty, 2) . ' ' . (string)($it['unit'] ?? ''),
                                    'Rp ' . number_format($price, 0, ',', '.'),
                                    'Rp ' . number_format($subtotal, 0, ',', '.'),
                                ];
                            }, $bp['items'] ?? []),
                        ];
                    ?>
                        <tr class="gd-clickable-row" style="cursor:pointer;" data-detail="<?php echo htmlspecialchars(json_encode($bpDetail), ENT_QUOTES); ?>" onclick="openGudangDetailModal(this)">
                            <td style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($bp['po_number']); ?></td>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($bp['source_business_name'] ?? '-'); ?></td>
                            <td style="font-size:.76rem;color:var(--text-muted);"><?php echo $bp['po_date'] ? date('d M Y', strtotime($bp['po_date'])) : '-'; ?></td>
                            <td style="font-size:.78rem;"><?php echo (int)($bp['items_count'] ?? 0); ?> item</td>
                            <td><span class="gd-badge" style="background:<?php echo $bpsc[0]; ?>;color:<?php echo $bpsc[1]; ?>;white-space:nowrap;"><?php echo strtoupper(str_replace('_', ' ', $bp['status'] ?? '-')); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Histori PO ke Supplier / Histori Barang Datang -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem;">

    <!-- Histori PO ke Supplier -->
    <div class="card gd-card">
        <div class="gd-section-title" style="color:#7c3aed;">🧾 Histori PO ke Supplier</div>
        <?php if (empty($poSupplierHistory)): ?>
            <div class="gd-empty">Belum ada PO ke supplier</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>No PO</th>
                        <th>Item</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($poSupplierHistory as $po):
                        $psc = match (strtolower($po['status'] ?? '')) {
                            'completed', 'received' => ['#dcfce7', '#166534'],
                            'submitted', 'pending', 'waiting' => ['#fef3c7', '#92400e'],
                            'approved', 'partially_received' => ['#dbeafe', '#1e40af'],
                            'cancelled', 'rejected' => ['#fee2e2', '#991b1b'],
                            default => ['#e5e7eb', '#374151']
                        };
                        $poItems = $db->fetchAll("SELECT item_name, quantity, unit, unit_price, total_price FROM purchase_orders_detail WHERE po_header_id = ? ORDER BY id ASC", [(int)$po['id']]) ?: [];
                        $poDetail = [
                            'title' => (string)($po['po_number'] ?? '-'),
                            'subtitle' => trim(($po['po_date'] ? date('d M Y', strtotime($po['po_date'])) : '-') . ' • ' . strtoupper(str_replace('_', ' ', $po['status'] ?? '-'))),
                            'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'Harga', 'right' => true], ['label' => 'Subtotal', 'right' => true]],
                            'rows' => array_map(function ($it) {
                                $qty = (float)($it['quantity'] ?? 0);
                                $price = (float)($it['unit_price'] ?? 0);
                                $subtotal = (float)($it['total_price'] ?? 0);
                                if ($subtotal <= 0) {
                                    $subtotal = $qty * $price;
                                }
                                return [
                                    (string)($it['item_name'] ?? '-'),
                                    number_format($qty, 2) . ' ' . (string)($it['unit'] ?? ''),
                                    'Rp ' . number_format($price, 0, ',', '.'),
                                    'Rp ' . number_format($subtotal, 0, ',', '.'),
                                ];
                            }, $poItems),
                        ];
                    ?>
                        <tr class="gd-clickable-row" style="cursor:pointer;" data-detail="<?php echo htmlspecialchars(json_encode($poDetail), ENT_QUOTES); ?>" onclick="openGudangDetailModal(this)">
                            <td>
                                <div style="font-weight:700;font-size:.81rem;"><?php echo htmlspecialchars($po['po_number']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo $po['po_date'] ? date('d M Y', strtotime($po['po_date'])) : '-'; ?></div>
                            </td>
                            <td style="font-size:.78rem;"><?php echo (int)$po['items_count']; ?> item</td>
                            <td><span class="gd-badge" style="background:<?php echo $psc[0]; ?>;color:<?php echo $psc[1]; ?>;white-space:nowrap;"><?php echo strtoupper(str_replace('_', ' ', $po['status'] ?? '-')); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Histori Barang Datang -->
    <div class="card gd-card">
        <div class="gd-section-title" style="color:#16a34a;">📥 Histori Barang Datang</div>
        <?php if (empty($barangDatangHistory)): ?>
            <div class="gd-empty">Belum ada barang datang dari supplier</div>
        <?php else: ?>
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>No PO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barangDatangHistory as $bd):
                        $bdDetail = [
                            'title' => (string)($bd['item_name'] ?? '-'),
                            'subtitle' => $bd['tgl'] ? date('d M Y H:i', strtotime($bd['tgl'])) : '-',
                            'columns' => [['label' => 'Keterangan'], ['label' => 'Nilai']],
                            'rows' => [
                                ['Qty Masuk', '+' . number_format((float)($bd['quantity'] ?? 0), 2) . ' ' . (string)($bd['unit'] ?? '')],
                                ['No PO', (string)($bd['po_number'] ?? '-')],
                                ['Tanggal', $bd['tgl'] ? date('d M Y H:i', strtotime($bd['tgl'])) : '-'],
                                ['Catatan', $bd['notes'] !== null && $bd['notes'] !== '' ? (string)$bd['notes'] : '-'],
                            ],
                        ];
                    ?>
                        <tr class="gd-clickable-row" style="cursor:pointer;" data-detail="<?php echo htmlspecialchars(json_encode($bdDetail), ENT_QUOTES); ?>" onclick="openGudangDetailModal(this)">
                            <td>
                                <div style="font-weight:600;font-size:.81rem;"><?php echo htmlspecialchars($bd['item_name']); ?></div>
                                <div style="font-size:.7rem;color:var(--text-muted);"><?php echo $bd['tgl'] ? date('d M Y', strtotime($bd['tgl'])) : '-'; ?></div>
                            </td>
                            <td><span style="font-weight:700;color:#16a34a;">+<?php echo number_format($bd['quantity'], 0); ?></span> <span style="font-size:.7rem;color:var(--text-muted);"><?php echo $bd['unit']; ?></span></td>
                            <td style="font-size:.76rem;color:var(--text-muted);white-space:nowrap;"><?php echo htmlspecialchars($bd['po_number'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Histori terkirim per bisnis -->
<div class="card gd-card">
    <div class="gd-section-title">🚚 Histori Terkirim / Bisnis</div>
    <?php if (empty($terkirimPerBisnis)): ?>
        <div class="gd-empty">Belum ada data transfer</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="gd-table">
                <thead>
                    <tr>
                        <th>Bisnis</th>
                        <th class="text-right">Jumlah Transfer</th>
                        <th class="text-right">Total Qty</th>
                        <th>Terakhir Kirim</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terkirimPerBisnis as $tb):
                        $tbItems = $db->fetchAll(
                            "SELECT gt.transfer_number, gt.created_at, gti.item_name, gti.unit, gti.quantity
                             FROM gudang_nasita_transfers gt
                             JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
                             WHERE COALESCE(gt.target_business_name, gt.bisnis_tujuan, 'Lainnya') = ?
                             ORDER BY gt.created_at DESC, gti.id ASC LIMIT 50",
                            [$tb['bisnis']]
                        ) ?: [];
                        $tbDetail = [
                            'title' => (string)($tb['bisnis'] ?? '-'),
                            'subtitle' => (int)$tb['total_transfer'] . ' transfer • total qty ' . number_format((float)$tb['total_qty'], 0, ',', '.'),
                            'columns' => [['label' => 'Item'], ['label' => 'Qty', 'right' => true], ['label' => 'No Transfer'], ['label' => 'Tanggal']],
                            'rows' => array_map(function ($it) {
                                return [
                                    (string)($it['item_name'] ?? '-'),
                                    number_format((float)($it['quantity'] ?? 0), 2) . ' ' . (string)($it['unit'] ?? ''),
                                    (string)($it['transfer_number'] ?? '-'),
                                    $it['created_at'] ? date('d M Y', strtotime((string)$it['created_at'])) : '-',
                                ];
                            }, $tbItems),
                        ];
                    ?>
                        <tr class="gd-clickable-row" style="cursor:pointer;" data-detail="<?php echo htmlspecialchars(json_encode($tbDetail), ENT_QUOTES); ?>" onclick="openGudangDetailModal(this)">
                            <td style="font-weight:600;"><?php echo htmlspecialchars($tb['bisnis']); ?></td>
                            <td class="text-right"><?php echo (int)$tb['total_transfer']; ?></td>
                            <td class="text-right" style="font-weight:700;"><?php echo number_format((float)$tb['total_qty'], 0, ',', '.'); ?></td>
                            <td style="font-size:.76rem;color:var(--text-muted);"><?php echo $tb['terakhir'] ? date('d M Y H:i', strtotime($tb['terakhir'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Detail modal (shared by all clickable history sections) -->
<div id="gudangDetailModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0.9rem;width:92%;max-width:560px;max-height:82vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
            <div>
                <div style="font-weight:800;font-size:1rem;" id="gdmTitle">-</div>
                <div style="font-size:.8rem;color:var(--text-muted);" id="gdmSubtitle">-</div>
            </div>
            <button type="button" onclick="closeGudangDetailModal()" style="background:none;border:none;font-size:1.3rem;line-height:1;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <div style="padding:1rem 1.25rem;">
            <table class="gd-table" id="gdmTable" style="width:100%;">
                <thead id="gdmThead"></thead>
                <tbody id="gdmBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function gdmEscapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        } [c]));
    }

    function openGudangDetailModal(rowEl) {
        let detail;
        try {
            detail = JSON.parse(rowEl.getAttribute('data-detail') || '{}');
        } catch (e) {
            detail = {};
        }

        document.getElementById('gdmTitle').textContent = detail.title || '-';
        document.getElementById('gdmSubtitle').textContent = detail.subtitle || '';

        const cols = detail.columns || [];
        const thead = document.getElementById('gdmThead');
        thead.innerHTML = '<tr>' + cols.map(c => '<th' + (c.right ? ' class="text-right"' : '') + '>' + gdmEscapeHtml(c.label || '') + '</th>').join('') + '</tr>';

        const rows = detail.rows || [];
        const tbody = document.getElementById('gdmBody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="' + (cols.length || 1) + '" class="gd-empty">Tidak ada detail.</td></tr>';
        } else {
            tbody.innerHTML = rows.map(r => '<tr>' + r.map((v, i) => '<td' + (cols[i] && cols[i].right ? ' class="text-right"' : '') + '>' + gdmEscapeHtml(v) + '</td>').join('') + '</tr>').join('');
        }

        document.getElementById('gudangDetailModal').style.display = 'flex';
    }

    function closeGudangDetailModal() {
        document.getElementById('gudangDetailModal').style.display = 'none';
    }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>