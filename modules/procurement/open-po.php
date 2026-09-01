<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

$poId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$poNumber = trim((string)($_GET['po_number'] ?? ''));
$poBizSlug = resolveBusinessConfigSlug(trim((string)($_GET['po_business'] ?? '')));

$allowedSlugs = ['narayana-hotel', 'bens-cafe', 'eaat-meet', 'eat-meet'];

// Build candidate order: incoming slug first, then remaining known slugs.
$candidateSlugs = [];
if ($poBizSlug !== '' && in_array($poBizSlug, $allowedSlugs, true)) {
    $candidateSlugs[] = $poBizSlug;
}
foreach ($allowedSlugs as $slug) {
    if (!in_array($slug, $candidateSlugs, true)) {
        $candidateSlugs[] = $slug;
    }
}

$foundId = 0;
$foundSlug = '';

foreach ($candidateSlugs as $slug) {
    $cfgPath = __DIR__ . '/../../config/businesses/' . $slug . '.php';
    if (!file_exists($cfgPath)) {
        continue;
    }

    $cfg = require $cfgPath;
    $dbName = (string)($cfg['database'] ?? '');
    if ($dbName === '') {
        continue;
    }

    try {
        Database::switchDatabase($dbName);
        $db = Database::getInstance();

        $row = null;
        if ($poNumber !== '') {
            $row = $db->fetchOne('SELECT id, po_number FROM purchase_orders_header WHERE po_number = ? LIMIT 1', [$poNumber]);
        }
        if (!$row && $poNumber === '' && $poId > 0) {
            $row = $db->fetchOne('SELECT id, po_number FROM purchase_orders_header WHERE id = ? LIMIT 1', [$poId]);
        }

        if ($row && !empty($row['id'])) {
            $foundId = (int)$row['id'];
            $foundSlug = $slug;
            if ($poNumber === '' && !empty($row['po_number'])) {
                $poNumber = (string)$row['po_number'];
            }
            break;
        }
    } catch (Throwable $e) {
        // continue scanning other DBs
    }
}

if ($foundId > 0 && $foundSlug !== '') {
    $target = 'view-po.php?id=' . $foundId . '&po_business=' . urlencode($foundSlug);
    if ($poNumber !== '') {
        $target .= '&po_number=' . urlencode($poNumber);
    }
    header('Location: ' . $target);
    exit;
}

$_SESSION['error'] = 'PO tidak ditemukan pada database bisnis manapun.';
header('Location: gudang-nasita.php');
exit;
