<?php
// Read-only monthly Gudang Nasita bill recap for the CURRENT active business (Bens Cafe / Eat Meet).
// Mirrors the per-business calculation used in modules/procurement/gudang-tagihan.php's
// "Tagihan Bulanan per Bisnis" section, but scoped to a single business for the Tagihan menu.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_functions.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

$slug = (string)($_SESSION['active_business_id'] ?? '');
$allowedSlugs = ['bens-cafe', 'eaat-meet', 'narayana-hotel'];
if (!in_array($slug, $allowedSlugs, true)) {
    echo json_encode(['success' => false, 'message' => 'Tagihan Gudang tidak tersedia untuk bisnis ini']);
    exit;
}

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// Matches the business a gudang transfer/target_business_name row belongs to (lowercase FIRST,
// then strip non-alphanumeric — see repo memory note on the Aug 2026 slug-matching bug).
function gudangBillNameMatchesSlug(string $businessName, string $slug): bool
{
    $norm = preg_replace('/[^a-z0-9]/', '', strtolower($businessName));
    if ($slug === 'narayana-hotel') {
        return strpos($norm, 'narayana') !== false || strpos($norm, 'hotel') !== false;
    }
    if ($slug === 'bens-cafe') {
        return strpos($norm, 'bens') !== false || strpos($norm, 'cafe') !== false;
    }
    if ($slug === 'eaat-meet') {
        return strpos($norm, 'eat') !== false || strpos($norm, 'meet') !== false;
    }
    return false;
}

try {
    $gudangCfgPath = __DIR__ . '/../config/businesses/gudang-nasita.php';
    $gudangDbName = '';
    if (file_exists($gudangCfgPath)) {
        $gc = require $gudangCfgPath;
        $gudangDbName = (string)($gc['database'] ?? '');
    }
    $db = Database::getInstance();
    $originDb = Database::getCurrentDatabase();
    $gudangDb = ($gudangDbName && $gudangDbName !== $originDb) ? Database::switchDatabase($gudangDbName) : $db;

    $rows = $gudangDb->fetchAll(
        "SELECT gt.target_business_name,
                COUNT(DISTINCT gt.id) AS transfer_count,
                COALESCE(SUM(gti.quantity), 0) AS total_qty,
                COALESCE(SUM(COALESCE(gti.subtotal, gti.quantity * COALESCE(gti.unit_price, 0))), 0) AS total_nilai
         FROM gudang_nasita_transfers gt
         LEFT JOIN gudang_nasita_transfer_items gti ON gti.transfer_id = gt.id
         WHERE gt.status NOT IN ('cancelled') AND gt.created_at BETWEEN ? AND ?
         GROUP BY gt.target_business_name",
        [$monthStart . ' 00:00:00', $monthEnd . ' 23:59:59']
    ) ?: [];

    $transferCount = 0;
    $transferQty = 0.0;
    $transferNilai = 0.0;
    foreach ($rows as $r) {
        if (gudangBillNameMatchesSlug((string)($r['target_business_name'] ?? ''), $slug)) {
            $transferCount += (int)$r['transfer_count'];
            $transferQty += (float)$r['total_qty'];
            $transferNilai += (float)$r['total_nilai'];
        }
    }

    $tkbmRow = $gudangDb->fetchOne(
        'SELECT COALESCE(SUM(total_biaya), 0) AS t FROM gudang_nasita_tkbm WHERE tanggal BETWEEN ? AND ?',
        [$monthStart, $monthEnd]
    );
    $tkbmShare = (float)(is_array($tkbmRow) ? ($tkbmRow['t'] ?? 0) : 0) / 3;

    $interAdj = getBusinessInterStockTransferBillAdjustments([$slug], $monthStart . ' 00:00:00', $monthEnd . ' 23:59:59');
    $transferNilai = max(0, $transferNilai + (float)($interAdj[$slug] ?? 0));

    $total = $transferNilai + $tkbmShare;

    $paidRow = $gudangDb->fetchOne(
        'SELECT amount, paid_at FROM gudang_nasita_tagihan_payments WHERE business_slug = ? AND bill_month = ?',
        [$slug, $month]
    );
    $isPaid = is_array($paidRow);

    if ($gudangDbName && $gudangDbName !== $originDb) {
        Database::switchDatabase($originDb);
    }

    echo json_encode([
        'success' => true,
        'recap' => [
            'month'           => $month,
            'transfer_count'  => $transferCount,
            'transfer_qty'    => $transferQty,
            'transfer_nilai'  => $transferNilai,
            'tkbm_share'      => $tkbmShare,
            'total'           => $total,
            'is_paid'         => $isPaid,
            'paid_at'         => $isPaid ? date('d M Y H:i', strtotime((string)$paidRow['paid_at'])) : null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('get-gudang-bill-recap error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal memuat tagihan gudang']);
}
