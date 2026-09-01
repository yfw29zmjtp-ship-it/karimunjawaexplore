<?php

/**
 * Buyer Portal - PWF Office
 * Buyer can monitor multiple customers and view each customer's orders.
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/db-helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$_isProduction = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') === false);
$_pwfDb = $_isProduction ? 'adfb2574_pwf' : 'adf_pwf';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_pwfDb . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if (function_exists('ensurePwfOfficeTables')) {
        ensurePwfOfficeTables($pdo);
    }
} catch (PDOException $e) {
    $_altDb = $_isProduction ? 'adfb2574_pwf' : 'adf_system_pwf';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $_altDb . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

$buyerQuery = trim((string)($_GET['buyer'] ?? $_GET['q'] ?? ''));
$buyerKey = trim((string)($_GET['buyer_key'] ?? ''));
$buyerKey = preg_replace('/[^a-zA-Z0-9]/', '', $buyerKey);
$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);
$buyerPortalName = '';
$buyerPortalError = '';
$isLockedBuyerPortal = false;

$companyName = 'Prapen Wood Furniture';
$logoUrl = '';
$faviconUrl = '';
$faviconType = 'image/x-icon';

try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('pwf_company_name','pwf_login_logo','pwf_favicon')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['pwf_company_name'])) {
        $companyName = $rows['pwf_company_name'];
    }
    if (!empty($rows['pwf_login_logo'])) {
        $logoUrl = (strpos($rows['pwf_login_logo'], 'http') === 0)
            ? $rows['pwf_login_logo']
            : $baseUrl . '/' . ltrim($rows['pwf_login_logo'], '/');
    }
    if (!empty($rows['pwf_favicon'])) {
        $faviconUrl = (strpos($rows['pwf_favicon'], 'http') === 0)
            ? $rows['pwf_favicon']
            : $baseUrl . '/' . ltrim($rows['pwf_favicon'], '/');
        $ext = strtolower(pathinfo(parse_url($faviconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $mimeMap = [
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];
        $faviconType = $mimeMap[$ext] ?? 'image/x-icon';
    }
} catch (Exception $e) {
}

$customerSql = "SELECT c.id, c.customer_code, c.customer_name, c.phone
                FROM pwf_customers c";
$customerParams = [];
$customerWhere = [];

if ($buyerKey !== '') {
    try {
        $stmtBuyer = $pdo->prepare("SELECT id, buyer_name FROM pwf_buyers WHERE access_key = ? AND is_active = 1 LIMIT 1");
        $stmtBuyer->execute([$buyerKey]);
        $buyerRow = $stmtBuyer->fetch(PDO::FETCH_ASSOC);
        if ($buyerRow) {
            $isLockedBuyerPortal = true;
            $buyerPortalName = (string)$buyerRow['buyer_name'];
            $customerSql .= " INNER JOIN pwf_buyer_customers bc ON bc.customer_id = c.id";
            $customerWhere[] = "bc.buyer_id = ?";
            $customerParams[] = (int)$buyerRow['id'];
        } else {
            $buyerPortalError = 'Buyer link is invalid or inactive.';
            $customerWhere[] = '1 = 0';
        }
    } catch (Exception $e) {
        $buyerPortalError = 'Unable to validate buyer access.';
        $customerWhere[] = '1 = 0';
    }
}

if ($buyerQuery !== '') {
    $customerWhere[] = "(UPPER(c.customer_code) LIKE ? OR UPPER(c.customer_name) LIKE ?)";
    $customerParams[] = '%' . strtoupper($buyerQuery) . '%';
    $customerParams[] = '%' . strtoupper($buyerQuery) . '%';
}

if (!empty($customerWhere)) {
    $customerSql .= ' WHERE ' . implode(' AND ', $customerWhere);
}

$customerSql .= " ORDER BY customer_name ASC LIMIT 300";
$stmtCustomers = $pdo->prepare($customerSql);
$stmtCustomers->execute($customerParams);
$customers = $stmtCustomers->fetchAll(PDO::FETCH_ASSOC);

if ($selectedCustomerId <= 0 && !empty($customers)) {
    $selectedCustomerId = (int)$customers[0]['id'];
}

$selectedCustomer = null;
foreach ($customers as $c) {
    if ((int)$c['id'] === $selectedCustomerId) {
        $selectedCustomer = $c;
        break;
    }
}

$dashboard = [
    'customer_count' => count($customers),
    'total_orders' => 0,
    'qty_done' => 0,
    'qty_shipped' => 0,
];

$monthlyRows = [];
$recentOrders = [];
$customerSummary = [
    'total_orders' => 0,
    'qty_ordered' => 0,
    'qty_done' => 0,
    'qty_shipped' => 0,
    'container_count' => 0,
];

if (!empty($customers)) {
    $ids = array_map(static fn($r) => (int)$r['id'], $customers);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $stmtDash = $pdo->prepare("SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.qty_done),0) AS qty_done,
            COALESCE(SUM(sh.qty_shipped),0) AS qty_shipped
        FROM pwf_orders o
        LEFT JOIN (
            SELECT order_id, SUM(qty_shipped) AS qty_shipped
            FROM pwf_container_items
            GROUP BY order_id
        ) sh ON sh.order_id = o.id
        WHERE o.customer_id IN ($ph)
          AND o.status <> 'cancelled'");
    $stmtDash->execute($ids);
    $d = $stmtDash->fetch(PDO::FETCH_ASSOC) ?: [];
    $dashboard['total_orders'] = (int)($d['total_orders'] ?? 0);
    $dashboard['qty_done'] = (float)($d['qty_done'] ?? 0);
    $dashboard['qty_shipped'] = (float)($d['qty_shipped'] ?? 0);
}

if ($selectedCustomer) {
    $cid = (int)$selectedCustomer['id'];

    $stmtSummary = $pdo->prepare("SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.quantity), 0) AS qty_ordered,
            COALESCE(SUM(o.qty_done), 0) AS qty_done,
            COALESCE(SUM(s.qty_shipped), 0) AS qty_shipped,
            COUNT(DISTINCT ci.container_id) AS container_count
        FROM pwf_orders o
        LEFT JOIN (
            SELECT order_id, SUM(qty_shipped) AS qty_shipped
            FROM pwf_container_items
            GROUP BY order_id
        ) s ON s.order_id = o.id
        LEFT JOIN pwf_container_items ci ON ci.order_id = o.id
        WHERE o.customer_id = ?
          AND o.status <> 'cancelled'");
    $stmtSummary->execute([$cid]);
    $sum = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];

    $customerSummary['total_orders'] = (int)($sum['total_orders'] ?? 0);
    $customerSummary['qty_ordered'] = (float)($sum['qty_ordered'] ?? 0);
    $customerSummary['qty_done'] = (float)($sum['qty_done'] ?? 0);
    $customerSummary['qty_shipped'] = (float)($sum['qty_shipped'] ?? 0);
    $customerSummary['container_count'] = (int)($sum['container_count'] ?? 0);

    $stmtMonthly = $pdo->prepare("SELECT
            DATE_FORMAT(o.order_date, '%Y-%m') AS period_key,
            DATE_FORMAT(o.order_date, '%b %Y') AS period_label,
            COUNT(*) AS total_orders,
            COALESCE(SUM(o.quantity), 0) AS qty_ordered,
            COALESCE(SUM(o.qty_done), 0) AS qty_done,
            COALESCE(SUM(s.qty_shipped), 0) AS qty_shipped
        FROM pwf_orders o
        LEFT JOIN (
            SELECT order_id, SUM(qty_shipped) AS qty_shipped
            FROM pwf_container_items
            GROUP BY order_id
        ) s ON s.order_id = o.id
        WHERE o.customer_id = ?
          AND o.status <> 'cancelled'
        GROUP BY period_key, period_label
        ORDER BY period_key DESC
        LIMIT 12");
    $stmtMonthly->execute([$cid]);
    $monthlyRows = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);

    $stmtRecent = $pdo->prepare("SELECT
            o.order_code,
            o.order_date,
            o.product_name,
            o.quantity,
            o.qty_done,
            o.finish,
            o.wood_color,
            o.image_path,
            o.status,
            COALESCE(s.qty_shipped, 0) AS qty_shipped,
            COALESCE(s.container_refs, '-') AS container_refs
        FROM pwf_orders o
        LEFT JOIN (
            SELECT
                ci.order_id,
                SUM(ci.qty_shipped) AS qty_shipped,
                GROUP_CONCAT(
                    DISTINCT TRIM(COALESCE(NULLIF(ct.container_no, ''), ct.container_code))
                    ORDER BY ct.id DESC SEPARATOR ', '
                ) AS container_refs
            FROM pwf_container_items ci
            LEFT JOIN pwf_containers ct ON ct.id = ci.container_id
            GROUP BY ci.order_id
        ) s ON s.order_id = o.id
        WHERE o.customer_id = ?
          AND o.status <> 'cancelled'
        ORDER BY o.order_date DESC, o.id DESC
        LIMIT 8");
    $stmtRecent->execute([$cid]);
    $recentOrders = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch all orders for all customers (for dashboard preview)
$allOrdersData = [];
if (!empty($customers)) {
    $ids = array_map(static fn($r) => (int)$r['id'], $customers);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $stmtAllOrders = $pdo->prepare("SELECT
            o.id,
            o.order_code,
            o.order_date,
            o.product_name,
            o.quantity,
            o.qty_done,
            o.finish,
            o.wood_color,
            o.status,
            COALESCE(s.qty_shipped, 0) AS qty_shipped,
            COALESCE(s.container_refs, '-') AS container_refs,
            c.id AS customer_id,
            c.customer_code,
            c.customer_name
        FROM pwf_orders o
        LEFT JOIN (
            SELECT
                ci.order_id,
                SUM(ci.qty_shipped) AS qty_shipped,
                GROUP_CONCAT(
                    DISTINCT TRIM(COALESCE(NULLIF(ct.container_no, ''), ct.container_code))
                    ORDER BY ct.id DESC SEPARATOR ', '
                ) AS container_refs
            FROM pwf_container_items ci
            LEFT JOIN pwf_containers ct ON ct.id = ci.container_id
            GROUP BY ci.order_id
        ) s ON s.order_id = o.id
        INNER JOIN pwf_customers c ON c.id = o.customer_id
        WHERE o.customer_id IN ($ph)
          AND o.status <> 'cancelled'
        ORDER BY c.customer_name ASC, o.order_date DESC, o.id DESC");
    $stmtAllOrders->execute($ids);
    $allOrdersData = $stmtAllOrders->fetchAll(PDO::FETCH_ASSOC);
}

function fmtQty(float $value): string
{
    return rtrim(rtrim(number_format($value, 2), '0'), '.');
}

$completionPct = 0;
if ($customerSummary['qty_ordered'] > 0) {
    $completionPct = min(100, max(0, (int)round(($customerSummary['qty_done'] / $customerSummary['qty_ordered']) * 100)));
}

$shippingPct = 0;
if ($customerSummary['qty_ordered'] > 0) {
    $shippingPct = min(100, max(0, (int)round(($customerSummary['qty_shipped'] / $customerSummary['qty_ordered']) * 100)));
}

$manifestHref = 'customer-manifest.php';
$_manifestParams = [];
if ($buyerQuery !== '') {
    $_manifestParams['q'] = $buyerQuery;
}
if ($buyerKey !== '') {
    $_manifestParams['buyer_key'] = $buyerKey;
}
if (!empty($_manifestParams)) {
    $manifestHref .= '?' . http_build_query($_manifestParams);
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0E223D">
    <title>Buyer Portal - <?= htmlspecialchars($companyName) ?></title>
    <link rel="manifest" href="<?= htmlspecialchars($manifestHref) ?>">
    <?php if ($faviconUrl !== ''): ?>
        <link rel="icon" type="<?= htmlspecialchars($faviconType) ?>" href="<?= htmlspecialchars($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --ink: #0E223D;
            --ink-soft: #1E3A5F;
            --card: #FFFFFF;
            --line: #D8E2EF;
            --text: #0F172A;
            --muted: #64748B;
            --fs-xs: 11px;
            --fs-sm: 12px;
            --fs-md: 13px;
            --fs-lg: 16px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            overflow-x: hidden;
            max-width: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text);
            background: linear-gradient(180deg, #0C1E37 0%, #0E223D 190px, #F0F4FA 190px, #F0F4FA 100%);
            min-height: 100vh;
        }

        .wrap {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 14px 32px;
            box-sizing: border-box;
        }

        .hero {
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .logo {
            width: 72px;
            height: 72px;
            min-width: 72px;
            border-radius: 16px;
            background: rgba(255, 255, 255, .15);
            border: 1px solid rgba(255, 255, 255, .25);
            display: grid;
            place-items: center;
            overflow: hidden;
        }

        .logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
            background: #fff;
        }

        .hero h1 {
            font-size: var(--fs-lg);
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .hero p {
            margin-top: 2px;
            font-size: var(--fs-xs);
            color: rgba(255, 255, 255, .72);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .install-btn {
            border: 1px solid rgba(255, 255, 255, .28);
            background: rgba(255, 255, 255, .14);
            color: #fff;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            display: none;
            white-space: nowrap;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(8, 22, 43, .07);
        }

        .customer-sidebar-panel {
            display: flex;
            flex-direction: column;
            min-height: 74vh;
        }

        .search-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: end;
        }

        .label {
            display: block;
            font-size: var(--fs-xs);
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 5px;
        }

        .input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 9px 11px;
            font-family: inherit;
            font-size: var(--fs-md);
            font-weight: 600;
            color: var(--text);
            outline: none;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, #1F4B7A, #2A6DA8);
            color: #fff;
            padding: 9px 14px;
            font-family: inherit;
            font-size: var(--fs-sm);
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .buyer-kpi {
            margin-top: 10px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .kpi-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
        }

        .kpi-title {
            font-size: var(--fs-xs);
            color: var(--muted);
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .kpi-value {
            font-size: 20px;
            font-weight: 800;
            line-height: 1;
            color: var(--ink);
        }

        .portal-grid {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 290px 1fr;
            gap: 10px;
        }

        .customer-list {
            flex: 1;
            min-height: 0;
            max-height: none;
            overflow: auto;
            display: grid;
            gap: 8px;
        }

        .sidebar-meta {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px dashed #D7E3F1;
            font-size: var(--fs-xs);
            color: var(--muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            position: sticky;
            bottom: 0;
            background: #fff;
        }

        .sidebar-meta strong {
            color: #1E3A5F;
            font-weight: 800;
        }

        .customer-item {
            border: 1px solid #DDE7F3;
            border-radius: 10px;
            padding: 9px;
            background: #FBFDFF;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .customer-item.active {
            background: #ECF4FF;
            border-color: #9FC3F4;
        }

        .customer-code {
            font-size: var(--fs-xs);
            color: #2563EB;
            font-weight: 700;
        }

        .customer-name {
            font-size: var(--fs-sm);
            color: #0F2948;
            font-weight: 800;
            margin-top: 2px;
        }

        .customer-phone {
            font-size: var(--fs-xs);
            color: var(--muted);
            margin-top: 2px;
        }

        .detail-grid {
            display: grid;
            gap: 10px;
        }

        .customer-head {
            background: linear-gradient(180deg, #F8FBFF 0%, #EEF5FF 100%);
            border: 1px solid #D9E5F5;
            border-radius: 12px;
            padding: 10px;
        }

        .customer-meta {
            margin-top: 5px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .chip {
            font-size: var(--fs-xs);
            background: #EEF4FF;
            color: #1E40AF;
            border: 1px solid #C7D7FE;
            border-radius: 99px;
            padding: 4px 8px;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }

        .two-col {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 10px;
        }

        .progress-head {
            display: flex;
            justify-content: space-between;
            font-size: var(--fs-xs);
            font-weight: 700;
            color: var(--ink-soft);
            margin-bottom: 5px;
        }

        .bar {
            width: 100%;
            height: 10px;
            background: #E8EFF8;
            border-radius: 999px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 999px;
        }

        .bar-done {
            background: linear-gradient(90deg, #0F9D74, #34D399);
        }

        .bar-ship {
            background: linear-gradient(90deg, #F97316, #FDBA74);
        }

        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--fs-sm);
        }

        th {
            text-align: left;
            font-size: var(--fs-xs);
            text-transform: uppercase;
            color: var(--muted);
            padding: 8px 7px;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        td {
            padding: 9px 7px;
            border-bottom: 1px solid #EEF3F9;
            vertical-align: middle;
        }

        .status {
            display: inline-flex;
            align-items: center;
            border-radius: 99px;
            padding: 3px 7px;
            font-size: var(--fs-xs);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
            background: #F1F5F9;
            color: #334155;
            white-space: nowrap;
        }

        .status.ready_ship,
        .status.completed {
            background: #ECFDF5;
            color: #047857;
        }

        .status.on_progress,
        .status.partial_ship,
        .status.qc {
            background: #FFF7ED;
            color: #C2410C;
        }

        .status.draft {
            background: #EFF6FF;
            color: #1D4ED8;
        }

        .status.shipped {
            background: #EEF2FF;
            color: #4338CA;
        }

        .container-ref {
            display: block;
            margin-top: 3px;
            font-size: var(--fs-xs);
            color: #2563EB;
        }

        .empty {
            padding: 18px;
            text-align: center;
            color: var(--muted);
            font-size: var(--fs-sm);
        }

        /* ---- Custom slide dropdown (mobile) ---- */
        .customer-select-mobile {
            display: none;
        }

        .cust-drop {
            position: relative;
        }

        .cust-drop-btn {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-family: inherit;
            font-size: var(--fs-md);
            font-weight: 700;
            color: var(--text);
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            cursor: pointer;
            text-align: left;
        }

        .cust-drop-btn .drop-label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .cust-drop-btn .drop-arrow {
            flex-shrink: 0;
            width: 16px;
            height: 16px;
            transition: transform 0.3s ease;
            color: var(--muted);
        }

        .cust-drop-btn.open .drop-arrow {
            transform: rotate(180deg);
        }

        .cust-drop-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            border: 0 solid var(--line);
            border-radius: 0 0 10px 10px;
            background: #fff;
            box-shadow: 0 8px 20px rgba(8, 22, 43, .10);
            margin-top: -2px;
        }

        .cust-drop-menu.open {
            max-height: 60vh;
            overflow-y: auto;
            border-width: 0 1px 1px 1px;
        }

        .cust-drop-item {
            display: block;
            padding: 10px 12px;
            font-size: var(--fs-sm);
            font-weight: 600;
            color: var(--text);
            text-decoration: none;
            border-bottom: 1px solid #F0F5FA;
        }

        .cust-drop-item:last-child {
            border-bottom: 0;
        }

        .cust-drop-item.active {
            background: #EBF3FF;
            color: #1D4ED8;
        }

        .cust-drop-item:hover {
            background: #F4F8FF;
        }

        @media (max-width: 980px) {
            .portal-grid {
                grid-template-columns: 1fr;
            }

            .customer-list {
                display: none;
            }

            .customer-sidebar-panel {
                min-height: auto;
            }

            .sidebar-meta {
                margin-top: 8px;
                position: static;
            }

            .customer-select-mobile {
                display: block;
                margin-bottom: 8px;
            }

            .buyer-kpi,
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .two-col {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 1024px) {
            .logo {
                width: 62px;
                height: 62px;
                border-radius: 16px;
            }

            .hero h1 {
                font-size: 24px;
            }
        }

        /* Print and Export Controls */
        .print-export-toolbar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .print-btn,
        .pdf-btn {
            border: 1px solid #D8E2EF;
            background: #F8FBFF;
            color: #0F172A;
            border-radius: 8px;
            padding: 6px 12px;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
        }

        .print-btn:hover,
        .pdf-btn:hover {
            background: #EEF4FF;
            border-color: #9FC3F4;
        }

        .print-btn:active,
        .pdf-btn:active {
            transform: scale(0.98);
        }

        /* Hide print controls when printing */
        @media print {

            .print-export-toolbar,
            .install-btn,
            .search-grid,
            .customer-list,
            .customer-sidebar-panel,
            .portal-grid {
                display: none !important;
            }

            body {
                background: white;
            }

            .wrap {
                padding: 0;
                max-width: 100%;
            }

            .panel {
                box-shadow: none;
                page-break-inside: avoid;
                border: none;
            }

            .detail-grid {
                display: block;
            }

            .detail-grid>* {
                page-break-inside: avoid;
                margin-bottom: 20px;
            }

            table {
                page-break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr {
                page-break-inside: avoid;
            }

            .two-col {
                grid-template-columns: 1fr 1fr !important;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="hero">
            <div class="brand">
                <div class="logo">
                    <?php if ($logoUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
                    <?php else: ?>
                        <span style="font-size:28px;color:#fff;">B</span>
                    <?php endif; ?>
                </div>
                <div>
                    <h1>Buyer Portal</h1>
                    <p>
                        <?= htmlspecialchars($companyName) ?> - Multi-customer order monitoring
                        <?php if ($isLockedBuyerPortal && $buyerPortalName !== ''): ?>
                            · Buyer: <?= htmlspecialchars($buyerPortalName) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <button id="installBtn" class="install-btn" type="button">Install</button>
        </div>

        <?php if ($buyerPortalError !== ''): ?>
            <div class="panel" style="margin-bottom:10px;border-color:#FECACA;background:#FEF2F2;color:#991B1B;font-weight:700">
                <?= htmlspecialchars($buyerPortalError) ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <form method="get" class="search-grid">
                <?php if ($buyerKey !== ''): ?>
                    <input type="hidden" name="buyer_key" value="<?= htmlspecialchars($buyerKey) ?>">
                <?php endif; ?>
                <div>
                    <label class="label"><?= $isLockedBuyerPortal ? 'Search Assigned Customer' : 'Buyer Search (Customer Code or Name)' ?></label>
                    <input class="input" type="text" name="buyer" value="<?= htmlspecialchars($buyerQuery) ?>" placeholder="<?= $isLockedBuyerPortal ? 'Search in assigned customer list' : 'Example: CUS-202606-001 or Hunger Resto' ?>">
                </div>
                <button class="btn" type="submit"><?= $isLockedBuyerPortal ? 'Search' : 'Load Buyer View' ?></button>
            </form>

            <div class="buyer-kpi">
                <div class="kpi-card">
                    <div class="kpi-title">Customers</div>
                    <div class="kpi-value"><?= (int)$dashboard['customer_count'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Total Orders</div>
                    <div class="kpi-value"><?= (int)$dashboard['total_orders'] ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Completed Qty</div>
                    <div class="kpi-value"><?= htmlspecialchars(fmtQty((float)$dashboard['qty_done'])) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Shipped Qty</div>
                    <div class="kpi-value"><?= htmlspecialchars(fmtQty((float)$dashboard['qty_shipped'])) ?></div>
                </div>
            </div>

            <div class="print-export-toolbar">
                <button class="print-btn" onclick="printDashboard()" title="Print dashboard data">
                    🖨️ Print Dashboard
                </button>
                <button class="pdf-btn" onclick="printDashboard()" title="Preview dashboard report">
                    📄 Preview & Export
                </button>
            </div>
        </div>

        <div class="portal-grid">
            <div class="panel customer-sidebar-panel">
                <div style="font-size:13px;font-weight:800;color:#0F2948;margin-bottom:8px;">Customers</div>
                <div class="customer-select-mobile">
                    <label class="label">Pilih Customer</label>
                    <div class="cust-drop" id="custDrop">
                        <button class="cust-drop-btn" id="custDropBtn" type="button">
                            <span class="drop-label" id="custDropLabel">
                                <?php
                                $activeLabel = '';
                                foreach ($customers as $cust) {
                                    if ((int)$cust['id'] === (int)$selectedCustomerId) {
                                        $activeLabel = $cust['customer_code'] . ' - ' . $cust['customer_name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($activeLabel ?: 'Pilih customer...');
                                ?>
                            </span>
                            <svg class="drop-arrow" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div class="cust-drop-menu" id="custDropMenu">
                            <?php foreach ($customers as $cust):
                                $isActive = ((int)$cust['id'] === (int)$selectedCustomerId);
                                $qsMob = [
                                    'buyer' => $buyerQuery,
                                    'customer_id' => (int)$cust['id']
                                ];
                                if ($buyerKey !== '') {
                                    $qsMob['buyer_key'] = $buyerKey;
                                }
                                $urlMob = 'customer-portal.php?' . http_build_query(array_filter($qsMob, static fn($v) => $v !== '' && $v !== null));
                            ?>
                                <a class="cust-drop-item<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($urlMob) ?>">
                                    <?= htmlspecialchars((string)$cust['customer_code']) ?> - <?= htmlspecialchars((string)$cust['customer_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="customer-list">
                    <?php if (empty($customers)): ?>
                        <div class="empty"><?= $isLockedBuyerPortal ? 'No assigned customers found for this buyer.' : 'No customers found for this buyer filter.' ?></div>
                    <?php else: ?>
                        <?php foreach ($customers as $cust):
                            $isActive = ((int)$cust['id'] === (int)$selectedCustomerId);
                            $qs = [
                                'buyer' => $buyerQuery,
                                'customer_id' => (int)$cust['id']
                            ];
                            if ($buyerKey !== '') {
                                $qs['buyer_key'] = $buyerKey;
                            }
                            $url = 'customer-portal.php?' . http_build_query(array_filter($qs, static fn($v) => $v !== '' && $v !== null));
                        ?>
                            <a class="customer-item<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($url) ?>">
                                <div class="customer-code"><?= htmlspecialchars((string)$cust['customer_code']) ?></div>
                                <div class="customer-name"><?= htmlspecialchars((string)$cust['customer_name']) ?></div>
                                <?php if (!empty($cust['phone'])): ?>
                                    <div class="customer-phone"><?= htmlspecialchars((string)$cust['phone']) ?></div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="sidebar-meta">
                    <span>Version: <strong>v1.0.0</strong></span>
                    <span>powerby <strong>AdFsystem</strong></span>
                </div>
            </div>

            <div class="detail-grid">
                <?php if (!$selectedCustomer): ?>
                    <div class="panel">
                        <div class="empty">Select a customer to view orders and recap.</div>
                    </div>
                <?php else: ?>
                    <div class="customer-head panel" style="box-shadow:none;">
                        <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;">
                            <div>
                                <div style="font-size:18px;font-weight:800;color:#102A4A;"><?= htmlspecialchars((string)$selectedCustomer['customer_name']) ?></div>
                                <div class="customer-meta">
                                    <span class="chip">Code: <?= htmlspecialchars((string)$selectedCustomer['customer_code']) ?></span>
                                    <?php if (!empty($selectedCustomer['phone'])): ?>
                                        <span class="chip">Phone: <?= htmlspecialchars((string)$selectedCustomer['phone']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="print-export-toolbar" style="margin-bottom:0;">
                                <button class="print-btn" onclick="printCustomerData('<?= htmlspecialchars((string)$selectedCustomer['customer_code']) ?>')" title="Preview customer data">
                                    🖨️ Preview & Print
                                </button>
                                <button class="pdf-btn" onclick="printCustomerData('<?= htmlspecialchars((string)$selectedCustomer['customer_code']) ?>')" title="Export customer data as PDF">
                                    📄 Export PDF
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="kpi-card">
                            <div class="kpi-title">Total Orders</div>
                            <div class="kpi-value"><?= (int)$customerSummary['total_orders'] ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Ordered Qty</div>
                            <div class="kpi-value"><?= htmlspecialchars(fmtQty((float)$customerSummary['qty_ordered'])) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">Completed Qty</div>
                            <div class="kpi-value" style="color:#0F766E"><?= htmlspecialchars(fmtQty((float)$customerSummary['qty_done'])) ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-title">In Container</div>
                            <div class="kpi-value" style="color:#C2410C"><?= htmlspecialchars(fmtQty((float)$customerSummary['qty_shipped'])) ?></div>
                        </div>
                    </div>

                    <div class="two-col">
                        <div class="panel">
                            <div style="font-size:13px;font-weight:800;color:#0F2948;margin-bottom:8px;">Progress Summary</div>
                            <div style="margin-bottom:10px;">
                                <div class="progress-head"><span>Production completed</span><span><?= (int)$completionPct ?>%</span></div>
                                <div class="bar">
                                    <div class="bar-fill bar-done" style="width: <?= (int)$completionPct ?>%;"></div>
                                </div>
                            </div>
                            <div>
                                <div class="progress-head"><span>Already in container</span><span><?= (int)$shippingPct ?>%</span></div>
                                <div class="bar">
                                    <div class="bar-fill bar-ship" style="width: <?= (int)$shippingPct ?>%;"></div>
                                </div>
                            </div>
                            <div style="margin-top:8px;font-size:11px;color:#64748B;">Related containers: <strong><?= (int)$customerSummary['container_count'] ?></strong></div>
                        </div>
                        <div class="panel">
                            <div style="font-size:13px;font-weight:800;color:#0F2948;margin-bottom:8px;">Current Status</div>
                            <div style="display:grid;gap:7px;font-size:11px;">
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Active orders</span><strong><?= (int)$customerSummary['total_orders'] ?></strong></div>
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Qty not completed</span><strong><?= htmlspecialchars(fmtQty(max(0, (float)$customerSummary['qty_ordered'] - (float)$customerSummary['qty_done']))) ?></strong></div>
                                <div style="display:flex;justify-content:space-between;"><span style="color:#64748B;">Qty ready to ship</span><strong><?= htmlspecialchars(fmtQty(max(0, (float)$customerSummary['qty_done'] - (float)$customerSummary['qty_shipped']))) ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div style="font-size:13px;font-weight:800;color:#0F2948;margin-bottom:8px;">Monthly Order Recap</div>
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Orders</th>
                                        <th>Ordered Qty</th>
                                        <th>Completed Qty</th>
                                        <th>In Container</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($monthlyRows)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align:center;color:#94A3B8;">No monthly data yet.</td>
                                        </tr>
                                        <?php else: foreach ($monthlyRows as $row): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars((string)$row['period_label']) ?></strong></td>
                                                <td><?= (int)$row['total_orders'] ?></td>
                                                <td><?= htmlspecialchars(fmtQty((float)$row['qty_ordered'])) ?></td>
                                                <td><?= htmlspecialchars(fmtQty((float)$row['qty_done'])) ?></td>
                                                <td><?= htmlspecialchars(fmtQty((float)$row['qty_shipped'])) ?></td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div style="font-size:13px;font-weight:800;color:#0F2948;margin-bottom:8px;">Orders for <?= htmlspecialchars((string)$selectedCustomer['customer_name']) ?></div>
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Photo</th>
                                        <th>Order Code</th>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>Finish Color</th>
                                        <th>Qty</th>
                                        <th>Done</th>
                                        <th>Container</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentOrders)): ?>
                                        <tr>
                                            <td colspan="9" style="text-align:center;color:#94A3B8;">No orders yet.</td>
                                        </tr>
                                        <?php else: foreach ($recentOrders as $ord):
                                            $ordImg = trim((string)($ord['image_path'] ?? ''));
                                            $ordImgSrc = $ordImg ? (preg_match('#^https?://#i', $ordImg) ? $ordImg : $baseUrl . '/' . ltrim($ordImg, '/')) : '';
                                            $ordData = json_encode([
                                                'order_code' => $ord['order_code'],
                                                'order_date' => $ord['order_date'],
                                                'product_name' => $ord['product_name'],
                                                'quantity' => $ord['quantity'],
                                                'qty_done' => $ord['qty_done'],
                                                'qty_shipped' => $ord['qty_shipped'],
                                                'finish' => $ord['finish'],
                                                'wood_color' => $ord['wood_color'],
                                                'status' => $ord['status'],
                                                'container_refs' => $ord['container_refs'],
                                                'image_path' => $ordImgSrc
                                            ]);
                                        ?>
                                            <tr class="order-row-clickable" data-order="<?= htmlspecialchars($ordData) ?>" style="cursor:pointer;transition:background-color 0.2s;" onmouseover="this.style.backgroundColor='var(--nav-hover)'" onmouseout="this.style.backgroundColor=''">
                                                <td>
                                                    <?php if ($ordImg !== ''): ?>
                                                        <img src="<?= htmlspecialchars($ordImgSrc) ?>" alt="<?= htmlspecialchars((string)$ord['product_name']) ?>" loading="lazy" style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #E2E8F0;display:block;">
                                                    <?php else: ?>
                                                        <span style="display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:8px;background:#F1F5F9;color:#94A3B8;">&#128247;</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?= htmlspecialchars((string)$ord['order_code']) ?></strong></td>
                                                <td><?= htmlspecialchars((string)$ord['order_date']) ?></td>
                                                <td><?= htmlspecialchars((string)$ord['product_name']) ?></td>
                                                <td>
                                                    <?php
                                                    $finishColor = trim((string)($ord['finish'] ?? ''));
                                                    if ($finishColor === '') {
                                                        $finishColor = trim((string)($ord['wood_color'] ?? ''));
                                                    }
                                                    ?>
                                                    <?= $finishColor !== '' ? htmlspecialchars($finishColor) : '—' ?>
                                                </td>
                                                <td><?= htmlspecialchars(fmtQty((float)$ord['quantity'])) ?></td>
                                                <td><?= htmlspecialchars(fmtQty((float)$ord['qty_done'])) ?></td>
                                                <td>
                                                    <?= htmlspecialchars(fmtQty((float)$ord['qty_shipped'])) ?> pcs
                                                    <?php if (!empty($ord['container_refs']) && $ord['container_refs'] !== '-'): ?>
                                                        <span class="container-ref">No: <?= htmlspecialchars((string)$ord['container_refs']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="status <?= htmlspecialchars((string)$ord['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', (string)$ord['status'])) ?></span></td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Order Detail Modal -->
    <div id="orderDetailModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:10000;align-items:center;justify-content:center;padding:20px;">
        <div style="background:var(--card);border-radius:14px;max-width:700px;width:100%;max-height:85vh;padding:0;box-shadow:0 25px 50px rgba(0,0,0,0.3);overflow:hidden;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;border-bottom:1px solid var(--border);">
                <h2 style="margin:0;font-size:18px;font-weight:700;color:var(--text);">Order Details</h2>
                <button id="orderDetailClose" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--muted);padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;transition:color 0.2s;">×</button>
            </div>
            <div id="orderDetailContent" style="padding:24px;flex:1;overflow-y:auto;"></div>
        </div>
    </div>

    <!-- Print Preview Modal -->
    <div id="printPreviewModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:20000;align-items:center;justify-content:center;padding:12px;">
        <div style="background:var(--card);border-radius:16px;max-width:900px;width:100%;max-height:95vh;padding:0;box-shadow:0 30px 60px rgba(0,0,0,0.4);overflow:hidden;display:flex;flex-direction:column;">
            <!-- Header -->
            <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:2px solid #F0F4FA;background:linear-gradient(135deg, #F8FBFF 0%, #EEF5FF 100%);">
                <div>
                    <h2 style="margin:0;font-size:20px;font-weight:800;color:#0E223D;">📄 Print Preview</h2>
                    <p style="margin:4px 0 0 0;font-size:12px;color:var(--muted);">Review your report before printing or exporting</p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <span id="previewStatus" style="font-size:11px;color:var(--muted);font-weight:600;"></span>
                    <button id="previewClose" style="background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted);padding:0;width:36px;height:36px;display:flex;align-items:center;justify-content:center;transition:all 0.2s;border-radius:8px;" onmouseover="this.style.background='#F0F4FA';this.style.color='#0E223D'" onmouseout="this.style.background='none';this.style.color='var(--muted)'">×</button>
                </div>
            </div>

            <!-- Preview Content -->
            <div id="printPreviewContent" style="flex:1;overflow-y:auto;padding:24px;background:white;"></div>

            <!-- Actions Footer -->
            <div style="display:flex;gap:12px;padding:20px 24px;border-top:2px solid #F0F4FA;background:#F8FBFF;justify-content:flex-end;flex-wrap:wrap;">
                <button id="previewCancelBtn" style="border:1px solid var(--line);background:#fff;color:var(--text);border-radius:10px;padding:10px 18px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.background='#F9F9F9'" onmouseout="this.style.background='#fff'">Cancel</button>
                <button id="previewPrintBtn" style="border:none;background:linear-gradient(135deg, #2563EB, #1D4ED8);color:white;border-radius:10px;padding:10px 18px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:6px;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'"><span>🖨️</span><span>Print / Export PDF</span></button>
            </div>
        </div>
    </div>

    <style>
        #orderDetailModal[style*="display: flex"] {
            display: flex !important;
        }

        #printPreviewModal[style*="display: flex"] {
            display: flex !important;
        }
    </style>

    <script>
        /**
         * Print Preview and Export Functions - Simplified Version
         */

        // All orders data from PHP backend (for dashboard preview)
        const allOrdersData = <?= json_encode($allOrdersData) ?>;

        let currentPreviewType = 'dashboard';
        let currentPreviewData = {};

        // Open Print Preview Modal
        function openPrintPreview(type, data) {
            currentPreviewType = type;
            currentPreviewData = data;

            const modal = document.getElementById('printPreviewModal');
            const contentDiv = document.getElementById('printPreviewContent');
            const statusSpan = document.getElementById('previewStatus');

            let previewHTML = '';
            if (type === 'dashboard') {
                previewHTML = generateDashboardPreviewHTML(data);
                statusSpan.textContent = '📊 Dashboard Report';
            } else if (type === 'customer') {
                previewHTML = generateCustomerPreviewHTML(data);
                statusSpan.textContent = `📋 ${data.customerCode || 'Customer'} Report`;
            }

            contentDiv.innerHTML = previewHTML;
            modal.style.display = 'flex';
            setTimeout(() => {
                contentDiv.scrollTop = 0;
            }, 100);
        }

        // Generate Dashboard Preview HTML - Simplified & Compact
        function generateDashboardPreviewHTML(data) {
            const now = new Date().toLocaleString('id-ID');
            let html = `
                <div style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 12px; color: #0F172A;">
                    <div style="margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #0E223D;">
                        <h1 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 800; color: #0E223D;">📊 Buyer Portal - All Orders Report</h1>
                        <p style="margin: 0; font-size: 11px; color: #94A3B8;">Generated: ${now}</p>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px;">
                        <div style="padding: 8px; background: #ECF4FF; border-radius: 8px; text-align: center;">
                            <div style="font-size: 10px; color: #1E40AF; font-weight: 700;">CUSTOMERS</div>
                            <div style="font-size: 16px; font-weight: 800; color: #0E223D;">${data.customerCount}</div>
                        </div>
                        <div style="padding: 8px; background: #FFF7ED; border-radius: 8px; text-align: center;">
                            <div style="font-size: 10px; color: #C2410C; font-weight: 700;">ORDERS</div>
                            <div style="font-size: 16px; font-weight: 800; color: #0E223D;">${data.totalOrders}</div>
                        </div>
                        <div style="padding: 8px; background: #ECFDF5; border-radius: 8px; text-align: center;">
                            <div style="font-size: 10px; color: #047857; font-weight: 700;">DONE</div>
                            <div style="font-size: 16px; font-weight: 800; color: #0E223D;">${formatQty(data.qtyDone)}</div>
                        </div>
                        <div style="padding: 8px; background: #EEF2FF; border-radius: 8px; text-align: center;">
                            <div style="font-size: 10px; color: #4338CA; font-weight: 700;">SHIPPED</div>
                            <div style="font-size: 16px; font-weight: 800; color: #0E223D;">${formatQty(data.qtyShipped)}</div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px;">
                        <thead>
                            <tr style="background: #ECF4FF; border-bottom: 1px solid #9FC3F4;">
                                <th style="padding: 6px; text-align: left; font-weight: 700; color: #1E40AF;">CUSTOMER</th>
                                <th style="padding: 6px; text-align: left; font-weight: 700; color: #1E40AF;">ORDER CODE</th>
                                <th style="padding: 6px; text-align: left; font-weight: 700; color: #1E40AF;">PRODUCT</th>
                                <th style="padding: 6px; text-align: center; font-weight: 700; color: #1E40AF;">ORD</th>
                                <th style="padding: 6px; text-align: center; font-weight: 700; color: #1E40AF;">DONE</th>
                                <th style="padding: 6px; text-align: center; font-weight: 700; color: #1E40AF;">SHIP</th>
                                <th style="padding: 6px; text-align: left; font-weight: 700; color: #1E40AF;">STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            if (data.orders && Array.isArray(data.orders) && data.orders.length > 0) {
                data.orders.forEach(order => {
                    const statusBg = getStatusBgColor(order.status);
                    html += `
                        <tr style="border-bottom: 1px solid #E5E7EB;">
                            <td style="padding: 5px; color: #0E223D; font-weight: 600;">${escapeHtml(order.customerName)}</td>
                            <td style="padding: 5px; color: #0E223D; font-weight: 700;">${escapeHtml(order.code)}</td>
                            <td style="padding: 5px; color: #0F172A;">${escapeHtml(order.product)}</td>
                            <td style="padding: 5px; text-align: center; color: #0F172A;">${formatQty(order.qtyOrder)}</td>
                            <td style="padding: 5px; text-align: center; color: #047857; font-weight: 600;">${formatQty(order.qtyDone)}</td>
                            <td style="padding: 5px; text-align: center; color: #4338CA; font-weight: 600;">${formatQty(order.qtyShipped)}</td>
                            <td style="padding: 5px;"><span style="display: inline-block; padding: 2px 6px; background: ${statusBg}; border-radius: 4px; font-weight: 700; font-size: 10px;">${escapeHtml(order.status.replace(/_/g, ' '))}</span></td>
                        </tr>
                    `;
                });
            }

            html += `
                        </tbody>
                    </table>
                    <div style="margin-top: 12px; padding-top: 8px; border-top: 1px dashed #D8E2EF; font-size: 10px; color: #94A3B8; text-align: center;">
                        ✓ Generated by PWF Buyer Portal
                    </div>
                </div>
            `;

            return html;
        }

        // Generate Customer Preview HTML - Simplified & Compact
        function generateCustomerPreviewHTML(data) {
            const now = new Date().toLocaleString('id-ID');
            let html = `
                <div style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 12px; color: #0F172A;">
                    <div style="margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #0E223D;">
                        <h1 style="margin: 0 0 3px 0; font-size: 16px; font-weight: 800; color: #0E223D;">📋 ${escapeHtml(data.customerName)}</h1>
                        <div style="font-size: 10px; color: #94A3B8;">Code: ${escapeHtml(data.customerCode)} | Generated: ${now}</div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 12px;">
                        <div style="padding: 6px; background: #ECF4FF; border-radius: 6px; text-align: center;">
                            <div style="font-size: 9px; color: #1E40AF; font-weight: 700;">ORDERS</div>
                            <div style="font-size: 14px; font-weight: 800; color: #0E223D;">${data.totalOrders}</div>
                        </div>
                        <div style="padding: 6px; background: #FFF7ED; border-radius: 6px; text-align: center;">
                            <div style="font-size: 9px; color: #C2410C; font-weight: 700;">ORD QTY</div>
                            <div style="font-size: 14px; font-weight: 800; color: #0E223D;">${formatQty(data.qtyOrdered)}</div>
                        </div>
                        <div style="padding: 6px; background: #ECFDF5; border-radius: 6px; text-align: center;">
                            <div style="font-size: 9px; color: #047857; font-weight: 700;">DONE</div>
                            <div style="font-size: 14px; font-weight: 800; color: #0E223D;">${formatQty(data.qtyDone)}</div>
                        </div>
                        <div style="padding: 6px; background: #EEF2FF; border-radius: 6px; text-align: center;">
                            <div style="font-size: 9px; color: #4338CA; font-weight: 700;">SHIP</div>
                            <div style="font-size: 14px; font-weight: 800; color: #0E223D;">${formatQty(data.qtyShipped)}</div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                        <thead>
                            <tr style="background: #ECF4FF; border-bottom: 1px solid #9FC3F4;">
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">ORDER CODE</th>
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">DATE</th>
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">PRODUCT</th>
                                <th style="padding: 5px; text-align: center; font-weight: 700; color: #1E40AF;">ORD</th>
                                <th style="padding: 5px; text-align: center; font-weight: 700; color: #1E40AF;">DONE</th>
                                <th style="padding: 5px; text-align: center; font-weight: 700; color: #1E40AF;">SHIP</th>
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">FINISH</th>
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">CONTAINER</th>
                                <th style="padding: 5px; text-align: left; font-weight: 700; color: #1E40AF;">STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            if (data.orders && Array.isArray(data.orders) && data.orders.length > 0) {
                data.orders.forEach(order => {
                    const statusBg = getStatusBgColor(order.status);
                    html += `
                        <tr style="border-bottom: 1px solid #E5E7EB;">
                            <td style="padding: 4px; font-weight: 700; color: #0E223D;">${escapeHtml(order.code)}</td>
                            <td style="padding: 4px; color: #0F172A; font-size: 9px;">${order.date}</td>
                            <td style="padding: 4px; color: #0F172A;">${escapeHtml(order.product)}</td>
                            <td style="padding: 4px; text-align: center; color: #0F172A;">${formatQty(order.qty)}</td>
                            <td style="padding: 4px; text-align: center; color: #047857; font-weight: 600;">${formatQty(order.qtyDone)}</td>
                            <td style="padding: 4px; text-align: center; color: #4338CA; font-weight: 600;">${formatQty(order.qtyShipped)}</td>
                            <td style="padding: 4px; font-size: 9px; color: #0F172A;">${escapeHtml(order.finishColor || '—')}</td>
                            <td style="padding: 4px; font-size: 9px; color: #2563EB;">${escapeHtml(order.containerRefs && order.containerRefs !== '-' ? order.containerRefs : '—')}</td>
                            <td style="padding: 4px;"><span style="display: inline-block; padding: 2px 6px; background: ${statusBg}; border-radius: 4px; font-weight: 700; font-size: 9px;">${escapeHtml(order.status.replace(/_/g, ' '))}</span></td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="9" style="padding: 8px; text-align: center; color: #94A3B8;">No orders found</td></tr>';
            }

            html += `
                        </tbody>
                    </table>
                    <div style="margin-top: 8px; padding-top: 6px; border-top: 1px dashed #D8E2EF; font-size: 9px; color: #94A3B8; text-align: center;">
                        ✓ Generated by PWF Buyer Portal
                    </div>
                </div>
            `;

            return html;
        }

        // Helper: Get status background color
        function getStatusBgColor(status) {
            const colorMap = {
                'draft': '#EFF6FF',
                'on_progress': '#FFF7ED',
                'qc': '#FFF7ED',
                'ready_ship': '#ECFDF5',
                'partial_ship': '#FFF7ED',
                'shipped': '#EEF2FF',
                'completed': '#ECFDF5',
            };
            return colorMap[status] || '#F1F5F9';
        }

        // Helper: Escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        // Helper: Format Qty
        function formatQty(val) {
            val = parseFloat(val) || 0;
            let str = val.toFixed(2);
            str = str.replace(/\.?0+$/, '');
            return str;
        }

        // Print from preview
        function doPrintFromPreview() {
            const previewContent = document.getElementById('printPreviewContent');
            if (!previewContent) return;

            const printWindow = window.open('', '', 'width=1200,height=800');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8">
                    <title>Print Report</title>
                    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
                    <style>
                        * { box-sizing: border-box; margin: 0; padding: 0; }
                        body { font-family: 'Plus Jakarta Sans', sans-serif; padding: 15px; background: white; }
                        @media print { body { padding: 0; } }
                    </style>
                </head>
                <body>
                    ${previewContent.innerHTML}
                </body>
                </html>
            `);
            printWindow.document.close();
            setTimeout(() => {
                printWindow.focus();
                printWindow.print();
            }, 250);
        }

        // Download PDF from preview
        function doDownloadPdfFromPreview() {
            const previewContent = document.getElementById('printPreviewContent');
            if (!previewContent) return;

            const btn = document.getElementById('previewDownloadBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span>⏳</span><span>Generating…</span>';
            }

            // Clone into a DOM-attached container (html2canvas requires element to be in DOM)
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = previewContent.innerHTML;
            tempDiv.style.cssText = 'position:absolute;left:-9999px;top:0;width:794px;background:#fff;font-family:Arial,sans-serif;font-size:11px;';
            document.body.appendChild(tempDiv);

            const filename = currentPreviewType === 'dashboard' ?
                `buyer-dashboard-${new Date().toISOString().split('T')[0]}.pdf` :
                `customer-report-${currentPreviewData.customerCode || 'report'}-${new Date().toISOString().split('T')[0]}.pdf`;

            const opt = {
                margin: 8,
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: false
                },
                jsPDF: {
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a4'
                }
            };

            html2pdf().set(opt).from(tempDiv).save().then(() => {
                document.body.removeChild(tempDiv);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<span>📥</span><span>Download PDF</span>';
                }
            }).catch(() => {
                document.body.removeChild(tempDiv);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<span>📥</span><span>Download PDF</span>';
                }
            });
        }

        // Dashboard Print
        function printDashboard() {
            // Use data from PHP backend instead of DOM extraction
            const orders = allOrdersData.map(order => ({
                code: order.order_code,
                customerName: order.customer_name,
                product: order.product_name,
                qtyOrder: parseFloat(order.quantity) || 0,
                qtyDone: parseFloat(order.qty_done) || 0,
                qtyShipped: parseFloat(order.qty_shipped) || 0,
                status: order.status,
                finishColor: order.finish || order.wood_color || ''
            }));

            // Get unique customers
            const uniqueCustomers = new Set(allOrdersData.map(o => o.customer_code));

            // Calculate totals
            let totalQtyOrder = 0,
                totalQtyDone = 0,
                totalQtyShipped = 0;
            allOrdersData.forEach(order => {
                totalQtyOrder += parseFloat(order.quantity) || 0;
                totalQtyDone += parseFloat(order.qty_done) || 0;
                totalQtyShipped += parseFloat(order.qty_shipped) || 0;
            });

            const dashboardData = {
                customerCount: uniqueCustomers.size,
                totalOrders: allOrdersData.length,
                qtyDone: totalQtyDone,
                qtyShipped: totalQtyShipped,
                orders: orders
            };

            openPrintPreview('dashboard', dashboardData);
        }

        // Customer Print
        function printCustomerData(customerCode) {
            const detailGrid = document.querySelector('.detail-grid');
            if (!detailGrid) return;

            const customerHead = detailGrid.querySelector('.customer-head');
            const customerName = customerHead?.querySelector('[style*="font-size:18px"]')?.textContent || 'Unknown';
            const customerPhone = customerHead?.querySelector('.customer-phone')?.textContent?.replace('Phone: ', '') || '';

            // Get KPI values
            const kpiCards = detailGrid.querySelectorAll('.summary-grid .kpi-card');
            let totalOrders = 0,
                qtyOrdered = 0,
                qtyDone = 0,
                qtyShipped = 0;

            if (kpiCards.length >= 4) {
                totalOrders = parseInt(kpiCards[0].querySelector('.kpi-value')?.textContent || '0');
                qtyOrdered = parseFloat(kpiCards[1].querySelector('.kpi-value')?.textContent || '0');
                qtyDone = parseFloat(kpiCards[2].querySelector('.kpi-value')?.textContent || '0');
                qtyShipped = parseFloat(kpiCards[3].querySelector('.kpi-value')?.textContent || '0');
            }

            // Extract orders from table
            const orders = [];
            const orderRows = detailGrid.querySelectorAll('.order-row-clickable');
            orderRows.forEach(row => {
                try {
                    const orderData = JSON.parse(row.getAttribute('data-order'));
                    orders.push({
                        code: orderData.order_code,
                        date: orderData.order_date,
                        product: orderData.product_name,
                        finishColor: orderData.finish || orderData.wood_color || '',
                        qty: parseFloat(orderData.quantity) || 0,
                        qtyDone: parseFloat(orderData.qty_done) || 0,
                        qtyShipped: parseFloat(orderData.qty_shipped) || 0,
                        status: orderData.status,
                        containerRefs: orderData.container_refs || '-'
                    });
                } catch (e) {}
            });

            const customerData = {
                customerCode: customerCode,
                customerName: customerName,
                customerPhone: customerPhone,
                totalOrders: totalOrders,
                qtyOrdered: qtyOrdered,
                qtyDone: qtyDone,
                qtyShipped: qtyShipped,
                orders: orders
            };

            openPrintPreview('customer', customerData);
        }

        (function() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('customer-sw.js').catch(function() {});
            }

            // Preview Modal Controls
            const previewModal = document.getElementById('printPreviewModal');
            const previewClose = document.getElementById('previewClose');
            const previewCancelBtn = document.getElementById('previewCancelBtn');
            const previewPrintBtn = document.getElementById('previewPrintBtn');

            if (previewClose) previewClose.addEventListener('click', () => previewModal.style.display = 'none');
            if (previewCancelBtn) previewCancelBtn.addEventListener('click', () => previewModal.style.display = 'none');
            if (previewPrintBtn) previewPrintBtn.addEventListener('click', doPrintFromPreview);

            previewModal.addEventListener('click', (e) => {
                if (e.target === previewModal) previewModal.style.display = 'none';
            });

            /* Custom slide dropdown */
            var dropBtn = document.getElementById('custDropBtn');
            var dropMenu = document.getElementById('custDropMenu');
            if (dropBtn && dropMenu) {
                dropBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var isOpen = dropMenu.classList.contains('open');
                    dropMenu.classList.toggle('open', !isOpen);
                    dropBtn.classList.toggle('open', !isOpen);
                });
                document.addEventListener('click', function() {
                    dropMenu.classList.remove('open');
                    dropBtn.classList.remove('open');
                });
                dropMenu.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }

            var ua = navigator.userAgent || '';
            var isAndroid = /Android/i.test(ua);
            var installBtn = document.getElementById('installBtn');
            var deferredPrompt = null;

            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                deferredPrompt = e;
                if (isAndroid && installBtn) installBtn.style.display = 'inline-flex';
            });

            if (installBtn) {
                installBtn.addEventListener('click', function() {
                    if (!deferredPrompt) {
                        alert('To install: open Chrome menu (⋮) -> Install app');
                        return;
                    }
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.finally(function() {
                        deferredPrompt = null;
                        installBtn.style.display = 'none';
                    });
                });
            }

            /* Order Detail Modal */
            var modal = document.getElementById('orderDetailModal');
            var closeBtn = document.getElementById('orderDetailClose');
            var contentDiv = document.getElementById('orderDetailContent');

            document.querySelectorAll('.order-row-clickable').forEach(function(row) {
                row.addEventListener('click', function(e) {
                    e.stopPropagation();
                    try {
                        var orderData = JSON.parse(this.getAttribute('data-order'));
                        var statusClass = orderData.status.toLowerCase();
                        var finishColor = orderData.finish || orderData.wood_color || '—';
                        var imageSrc = orderData.image_path;

                        var html = '';

                        // Calculate quantities upfront
                        var qtyOrdered = parseFloat(orderData.quantity) || 0;
                        var qtyDone = parseFloat(orderData.qty_done) || 0;
                        var qtyShipped = parseFloat(orderData.qty_shipped) || 0;
                        var completionPct = qtyOrdered > 0 ? Math.round((qtyDone / qtyOrdered) * 100) : 0;
                        var shippingPct = qtyOrdered > 0 ? Math.round((qtyShipped / qtyOrdered) * 100) : 0;

                        // Main layout: Image on left, all info on right
                        html += '<div style="display:grid;grid-template-columns:140px 1fr;gap:18px;margin-bottom:20px;">';

                        // Left: Image Frame
                        if (imageSrc) {
                            html += '<div style="width:140px;height:140px;padding:8px;background:var(--nav-hover);border-radius:8px;border:2px solid var(--border);box-sizing:border-box;"><img src="' + imageSrc.replace(/"/g, '&quot;') + '" alt="Order image" style="width:100%;height:100%;object-fit:cover;border-radius:4px;"></div>';
                        } else {
                            html += '<div style="width:140px;height:140px;display:flex;align-items:center;justify-content:center;background:var(--nav-hover);border-radius:8px;border:2px dashed var(--border);color:var(--muted);font-size:36px;box-sizing:border-box;">📷</div>';
                        }

                        // Right: All info stacked
                        html += '<div>';

                        // Order Header: Code, Status, Date (compact)
                        html += '<div style="margin-bottom:12px;">';
                        html += '<div style="margin-bottom:8px;"><div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;">Order Code</div><div style="font-size:14px;font-weight:700;color:var(--text);margin-top:2px;">' + escapeHtml(orderData.order_code) + '</div></div>';
                        html += '<div style="margin-bottom:8px;"><div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;">Status</div><div style="margin-top:2px;"><span class="status ' + statusClass + '" style="display:inline-block;padding:4px 8px;border-radius:4px;font-size:10px;font-weight:600;">' + escapeHtml(orderData.status.replace(/_/g, ' ')) + '</span></div></div>';
                        html += '<div><div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;">Date</div><div style="font-size:11px;color:var(--text);margin-top:2px;">' + escapeHtml(orderData.order_date) + '</div></div>';
                        html += '</div>';

                        // Product Details (compact)
                        html += '<div style="padding:12px;background:var(--nav-hover);border-radius:8px;margin-bottom:12px;">';
                        html += '<div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;margin-bottom:6px;">📦 Product</div>';
                        html += '<div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:8px;">' + escapeHtml(orderData.product_name) + '</div>';
                        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:10px;">';
                        html += '<div><div style="color:var(--muted);font-weight:600;">Finish</div><div style="color:var(--text);margin-top:1px;">' + escapeHtml(finishColor) + '</div></div>';
                        html += '<div><div style="color:var(--muted);font-weight:600;">Unit</div><div style="color:var(--text);margin-top:1px;">pcs</div></div>';
                        html += '</div>';
                        html += '</div>';

                        // Quantity Status (compact)
                        html += '<div style="padding:12px;background:var(--nav-hover);border-radius:8px;">';
                        html += '<div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;margin-bottom:8px;">📊 Quantity</div>';

                        // Progress bars (smaller)
                        html += '<div style="margin-bottom:10px;">';
                        html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:9px;">';
                        html += '<div style="color:var(--muted);font-weight:600;">Complete</div>';
                        html += '<div style="color:var(--text);font-weight:600;">' + completionPct + '%</div>';
                        html += '</div>';
                        html += '<div style="height:4px;background:rgba(0,0,0,0.1);border-radius:2px;overflow:hidden;">';
                        html += '<div style="height:100%;background:linear-gradient(90deg, #3b82f6, #1e40af);width:' + completionPct + '%;"></div>';
                        html += '</div>';
                        html += '</div>';

                        html += '<div style="margin-bottom:8px;">';
                        html += '<div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:9px;">';
                        html += '<div style="color:var(--muted);font-weight:600;">Shipped</div>';
                        html += '<div style="color:var(--text);font-weight:600;">' + shippingPct + '%</div>';
                        html += '</div>';
                        html += '<div style="height:4px;background:rgba(0,0,0,0.1);border-radius:2px;overflow:hidden;">';
                        html += '<div style="height:100%;background:linear-gradient(90deg, #10b981, #059669);width:' + shippingPct + '%;"></div>';
                        html += '</div>';
                        html += '</div>';

                        // Qty cards (smaller)
                        html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;font-size:9px;">';
                        html += '<div style="padding:8px;background:rgba(59,130,246,0.15);border-radius:6px;text-align:center;">';
                        html += '<div style="color:var(--muted);font-weight:600;margin-bottom:3px;">Order</div>';
                        html += '<div style="font-size:12px;font-weight:700;color:var(--text);">' + formatQty(qtyOrdered) + '</div>';
                        html += '</div>';

                        html += '<div style="padding:8px;background:rgba(59,130,246,0.15);border-radius:6px;text-align:center;">';
                        html += '<div style="color:var(--muted);font-weight:600;margin-bottom:3px;">Done</div>';
                        html += '<div style="font-size:12px;font-weight:700;color:var(--text);">' + formatQty(qtyDone) + '</div>';
                        html += '</div>';

                        html += '<div style="padding:8px;background:rgba(16,185,129,0.15);border-radius:6px;text-align:center;">';
                        html += '<div style="color:var(--muted);font-weight:600;margin-bottom:3px;">Ship</div>';
                        html += '<div style="font-size:12px;font-weight:700;color:var(--text);">' + formatQty(qtyShipped) + '</div>';
                        html += '</div>';
                        html += '</div>';

                        html += '</div>';
                        html += '</div>';
                        html += '</div>';

                        if (orderData.container_refs && orderData.container_refs !== '-') {
                            html += '<div style="padding:12px;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);border-radius:10px;">';
                            html += '<div style="font-size:9px;color:var(--muted);text-transform:uppercase;font-weight:600;letter-spacing:0.4px;margin-bottom:6px;">📦 Containers</div>';
                            html += '<div style="font-size:11px;color:var(--text);font-family:monospace;line-height:1.5;">' + escapeHtml(orderData.container_refs) + '</div>';
                            html += '</div>';
                        }

                        contentDiv.innerHTML = html;
                        modal.style.display = 'flex';
                    } catch (err) {
                        console.error('Error parsing order data:', err);
                    }
                });
            });

            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });

            closeBtn.addEventListener('mouseover', function() {
                this.style.color = 'var(--text)';
            });

            closeBtn.addEventListener('mouseout', function() {
                this.style.color = 'var(--muted)';
            });
        })();
    </script>
</body>

</html>