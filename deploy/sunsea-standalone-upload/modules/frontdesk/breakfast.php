<?php

/**
 * BREAKFAST ORDER - Rewritten clean version
 * Flow: Pick guest (not yet ordered today) → Pick menu → Submit → Pick next guest
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
// Breakfast hotel date rolls over at 10:00, so last night's picks stay visible until 10 AM.
$today = ((int)date('H') < 10) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

$guestLinkMessageTemplate = '';
try {
    $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'breakfast_guest_link_template' LIMIT 1");
    $guestLinkMessageTemplate = trim((string)($row['setting_value'] ?? ''));
} catch (Exception $e) {
}

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_menus (
        id INT PRIMARY KEY AUTO_INCREMENT, menu_name VARCHAR(100) NOT NULL,
        description TEXT, category ENUM('western','indonesian','asian','drinks','beverages','extras') DEFAULT 'western',
        price DECIMAL(10,2) DEFAULT 0.00, is_free BOOLEAN DEFAULT TRUE, is_available BOOLEAN DEFAULT TRUE,
        image_url VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS breakfast_orders (
        id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT NULL, guest_name VARCHAR(500) NOT NULL,
        room_number TEXT, total_pax INT DEFAULT 1, breakfast_time TIME, breakfast_date DATE,
        location VARCHAR(20) DEFAULT 'restaurant', menu_items TEXT, special_requests TEXT,
        total_price DECIMAL(10,2) DEFAULT 0.00, order_status VARCHAR(20) DEFAULT 'pending',
        created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

// Widen guest_name column and drop old unique constraint (combined multi-guest names can be long)
try {
    $pdo->exec("ALTER TABLE breakfast_orders MODIFY guest_name VARCHAR(500) NOT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE breakfast_orders DROP INDEX uk_guest_date");
} catch (Exception $e) {
}

// Get menus
$freeMenus = $paidMenus = [];
try {
    $freeMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=1 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
    $paidMenus = $pdo->query("SELECT * FROM breakfast_menus WHERE is_available=1 AND is_free=0 ORDER BY category,menu_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Default child menu IDs for guest portal quota (example: pancake + waffle)
$defaultChildMenuIds = [];
foreach (array_merge($freeMenus, $paidMenus) as $mx) {
    $nameLower = strtolower(trim($mx['menu_name'] ?? ''));
    if (in_array($nameLower, ['pancake', 'waffle'], true)) {
        $defaultChildMenuIds[] = (int)$mx['id'];
    }
}

// Get in-house guests WHO HAVE NOT ORDERED TODAY
// Group by guest_id: one guest may have multiple bookings/rooms
$inHouseGuests = [];
$guestQuotaMap = [];
try {
    $quotaRows = $pdo->query("SELECT booking_id, adult_count, child_young_count, child_old_count, total_pax, max_main, max_drink, max_child, child_menu_ids, extra_main_price, extra_drink_price, extra_child_price, breakfast_date FROM breakfast_guest_quota")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($quotaRows as $qr) {
        $guestQuotaMap[(int)$qr['booking_id']] = $qr;
    }

    $stmt = $pdo->prepare("
        SELECT g.id as guest_id, g.guest_name, COALESCE(g.phone,'') as guest_phone,
               GROUP_CONCAT(DISTINCT r.room_number ORDER BY r.room_number SEPARATOR ',') as rooms,
               GROUP_CONCAT(DISTINCT b.id ORDER BY b.id SEPARATOR ',') as booking_ids
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND NOT EXISTS (
            SELECT 1 FROM breakfast_orders bo 
            WHERE bo.breakfast_date = ? 
            AND bo.room_number LIKE CONCAT('%\"', r.room_number, '\"%')
        )
        GROUP BY g.id, g.guest_name
        ORDER BY MIN(r.room_number) ASC
    ");
    $stmt->execute([$today]);
    $inHouseGuests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Map Extra Breakfast (over-quota) per room number for today — used to flag orders
$extraByRoom = [];
try {
    $exStmt = $pdo->prepare("
        SELECT be.booking_id, r.room_number,
               COALESCE(SUM(be.total_price), 0) as extra_total
        FROM booking_extras be
        LEFT JOIN bookings b ON be.booking_id = b.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE be.item_name = 'Extra Breakfast'
          AND (be.notes LIKE ? OR DATE(be.created_at) = ?)
        GROUP BY be.booking_id, r.room_number
    ");
    $exStmt->execute(['%date=' . $today . '%', $today]);
    foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $ex) {
        $rn = (string)($ex['room_number'] ?? '');
        if ($rn !== '') {
            $extraByRoom[$rn] = ($extraByRoom[$rn] ?? 0) + (float)$ex['extra_total'];
        }
    }
} catch (Exception $e) {
}


// Today's orders for sidebar
$todayOrders = [];
try {
    $stmt = $pdo->prepare("SELECT bo.* FROM breakfast_orders bo
        WHERE bo.breakfast_date = ?
        AND bo.id = (
            SELECT MAX(bo2.id) FROM breakfast_orders bo2
            WHERE bo2.guest_name = bo.guest_name
              AND bo2.breakfast_date = bo.breakfast_date
              AND bo2.room_number = bo.room_number
        )
        ORDER BY bo.breakfast_time ASC, bo.id ASC");
    $stmt->execute([$today]);
    $todayOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todayOrders as &$o) {
        $o['menu_items'] = json_decode($o['menu_items'], true) ?: [];
    }
    unset($o);
} catch (Exception $e) {
}

// Rekap total pesanan per menu (untuk kitchen prep) — jumlahkan quantity semua
// order hari ini per nama menu, diurutkan dari yang paling banyak dipesan.
$menuRecap = [];
foreach ($todayOrders as $order) {
    foreach ($order['menu_items'] as $item) {
        $menuName = trim($item['menu_name'] ?? '');
        if ($menuName === '') continue;
        $qty = (int)($item['quantity'] ?? 1);
        if (!isset($menuRecap[$menuName])) $menuRecap[$menuName] = 0;
        $menuRecap[$menuName] += $qty;
    }
}
arsort($menuRecap);


// Edit mode
$editOrder = null;
$editMenuIds = [];
$editMenuQty = [];
$editMenuNotes = [];
$editCustomExtras = [];
if (!empty($_GET['edit'])) {
    $editOrder = $db->fetchOne("SELECT * FROM breakfast_orders WHERE id = ?", [(int)$_GET['edit']]);
    if ($editOrder) {
        foreach (json_decode($editOrder['menu_items'], true) ?: [] as $item) {
            if (!empty($item['is_custom'])) {
                $editCustomExtras[] = $item;
            } else {
                $editMenuIds[] = $item['menu_id'];
                $editMenuQty[$item['menu_id']] = $item['quantity'];
                if (!empty($item['note'])) $editMenuNotes[$item['menu_id']] = $item['note'];
            }
        }
    }
}

$pageTitle = 'Breakfast Order';
include '../../includes/header.php';
?>

<style>
    .bf-wrap {
        max-width: 1300px;
        margin: 0 auto
    }

    .bf-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
        gap: .75rem
    }

    .bf-head h1 {
        font-size: 1.5rem;
        font-weight: 800;
        background: linear-gradient(135deg, #f59e0b, #f97316);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin: 0;
        display: flex;
        align-items: center;
        gap: .5rem
    }

    .bf-head-actions {
        display: flex;
        gap: .5rem
    }

    .bf-head-btn {
        padding: .5rem .875rem;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        border-radius: 8px;
        font-size: .75rem;
        font-weight: 600;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: .35rem;
        transition: all .2s
    }

    .bf-head-btn:hover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, .1)
    }

    .bf-grid {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 1.25rem
    }

    .bf-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        padding: 1rem
    }

    .bf-section {
        margin-bottom: 1rem
    }

    .bf-title {
        font-size: .85rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: .65rem;
        padding-bottom: .4rem;
        border-bottom: 2px solid var(--bg-tertiary);
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: .75rem;
        margin-bottom: .75rem
    }

    .bf-group {
        display: flex;
        flex-direction: column
    }

    .bf-label {
        font-size: .68rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .3px;
        margin-bottom: .3rem
    }

    .bf-input,
    .bf-select {
        padding: .55rem .65rem;
        border-radius: 6px;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .85rem;
        width: 100%
    }

    .bf-input:focus,
    .bf-select:focus {
        outline: none;
        border-color: var(--primary-color)
    }

    .bf-radio-group {
        display: flex;
        gap: .5rem
    }

    .bf-radio-label {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .35rem;
        padding: .5rem;
        background: var(--bg-primary);
        border: 2px solid var(--bg-tertiary);
        border-radius: 8px;
        cursor: pointer;
        font-size: .78rem;
        font-weight: 600;
        transition: all .2s
    }

    .bf-radio-label:hover {
        border-color: var(--primary-color)
    }

    .bf-radio-label:has(input:checked) {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, .15)
    }

    .bf-radio-label input {
        display: none
    }

    .bf-menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: .5rem
    }

    .bf-menu-item {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .65rem;
        transition: all .2s;
        cursor: pointer
    }

    .bf-menu-item:hover {
        border-color: var(--primary-color)
    }

    .bf-menu-item:has(input:checked) {
        border-color: #10b981;
        background: rgba(16, 185, 129, .1)
    }

    .bf-menu-cb {
        display: flex;
        align-items: flex-start;
        gap: .5rem
    }

    .bf-menu-cb input[type="checkbox"] {
        margin-top: .15rem;
        width: 16px;
        height: 16px;
        cursor: pointer
    }

    .bf-menu-name {
        font-size: .8rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: .2rem
    }

    .bf-menu-price {
        font-size: .72rem;
        font-weight: 700;
        color: #10b981
    }

    .bf-menu-cat {
        display: inline-block;
        padding: .15rem .4rem;
        background: rgba(99, 102, 241, .15);
        border-radius: 4px;
        font-size: .6rem;
        font-weight: 600;
        text-transform: uppercase;
        color: var(--primary-color)
    }

    .bf-menu-qty {
        display: none;
        align-items: center;
        gap: .35rem;
        margin-top: .5rem;
        padding-top: .5rem;
        border-top: 1px dashed var(--bg-tertiary)
    }

    .bf-menu-item:has(input:checked) .bf-menu-qty {
        display: flex
    }

    .bf-qty-input {
        width: 50px;
        padding: .3rem;
        border-radius: 4px;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .8rem;
        text-align: center
    }

    .bf-menu-note {
        display: none;
        margin-top: .35rem
    }

    .bf-menu-item:has(input:checked) .bf-menu-note {
        display: block
    }

    .bf-note-input {
        width: 100%;
        padding: .3rem .5rem;
        border-radius: 4px;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .72rem;
        font-family: inherit
    }

    .bf-textarea {
        width: 100%;
        padding: .55rem .65rem;
        border-radius: 6px;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        color: var(--text-primary);
        font-size: .85rem;
        font-family: inherit;
        resize: vertical;
        min-height: 50px
    }

    .bf-actions {
        display: flex;
        gap: .5rem;
        margin-top: 1rem
    }

    .bf-btn-submit {
        flex: 1;
        padding: .75rem 1rem;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: .85rem;
        font-weight: 700;
        cursor: pointer;
        transition: all .2s
    }

    .bf-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, .3)
    }

    .bf-btn-submit:disabled {
        opacity: .5;
        cursor: not-allowed;
        transform: none
    }

    .bf-btn-reset {
        padding: .75rem 1rem;
        background: var(--bg-primary);
        color: var(--text-muted);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        font-size: .85rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        text-align: center
    }

    /* Sidebar */
    .bf-side {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        overflow: hidden;
        height: fit-content;
        position: sticky;
        top: 1rem
    }

    .bf-side-title {
        padding: .85rem 1rem;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: #fff;
        font-size: .9rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-side-count {
        background: rgba(255, 255, 255, .25);
        padding: .15rem .5rem;
        border-radius: 10px;
        font-size: .7rem;
        margin-left: auto
    }

    .bf-recap-card {
        margin: .75rem;
        border: 1px solid #fde68a;
        background: #fffbeb;
        border-radius: 10px;
        overflow: hidden
    }

    [data-theme="dark"] .bf-recap-card {
        background: #292015;
        border-color: #78350f
    }

    .bf-recap-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        padding: .55rem .75rem;
        font-size: .78rem;
        font-weight: 700;
        color: #92400e;
        border-bottom: 1px solid #fde68a
    }

    [data-theme="dark"] .bf-recap-head {
        color: #fbbf24;
        border-bottom-color: #78350f
    }

    .bf-recap-print {
        border: none;
        background: #f59e0b;
        color: #fff;
        font-size: .7rem;
        font-weight: 700;
        padding: .25rem .55rem;
        border-radius: 6px;
        cursor: pointer;
        white-space: nowrap
    }

    .bf-recap-print:hover {
        background: #d97706
    }

    .bf-recap-table {
        width: 100%;
        border-collapse: collapse
    }

    .bf-recap-table tr:not(:last-child) td {
        border-bottom: 1px dashed #fde68a
    }

    [data-theme="dark"] .bf-recap-table tr:not(:last-child) td {
        border-bottom-color: #78350f
    }

    .bf-recap-name {
        padding: .4rem .75rem;
        font-size: .8rem;
        color: var(--text-primary)
    }

    .bf-recap-qty {
        padding: .4rem .75rem;
        font-size: .85rem;
        font-weight: 800;
        color: #d97706;
        text-align: right
    }

    .bf-order {
        padding: .75rem 1rem;
        border-bottom: 1px solid var(--bg-tertiary);
        transition: background .2s
    }

    .bf-order:last-child {
        border-bottom: none
    }

    .bf-order:hover {
        background: var(--bg-primary)
    }

    .bf-order-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: .35rem
    }

    .bf-order-time {
        font-size: .75rem;
        font-weight: 700;
        color: var(--primary-color)
    }

    .bf-order-pax {
        font-size: .65rem;
        padding: .2rem .4rem;
        background: var(--bg-tertiary);
        border-radius: 4px;
        color: var(--text-muted)
    }

    .bf-order-guest {
        font-size: .8rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: .25rem
    }

    .bf-order-room {
        font-size: .7rem;
        color: var(--text-muted);
        margin-bottom: .35rem
    }

    .bf-order-extra-badge {
        font-size: .68rem;
        font-weight: 700;
        color: #b45309;
        background: rgba(245, 158, 11, .14);
        border: 1px solid rgba(245, 158, 11, .35);
        border-radius: 6px;
        padding: .25rem .45rem;
        margin-bottom: .4rem;
        line-height: 1.3
    }

    .bf-order-extra-badge span {
        font-weight: 500;
        opacity: .8
    }


    display: flex;
    flex-wrap: wrap;
    gap: .25rem
    }

    .bf-order-tag {
        font-size: .62rem;
        padding: .15rem .35rem;
        background: rgba(139, 92, 246, .15);
        color: #a78bfa;
        border-radius: 3px
    }

    .bf-order-foot {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: .4rem;
        padding-top: .35rem;
        border-top: 1px dashed var(--bg-tertiary)
    }

    .bf-order-price {
        font-size: .72rem;
        font-weight: 700;
        color: #10b981
    }

    .bf-order-status {
        font-size: .6rem;
        padding: .2rem .4rem;
        border-radius: 4px;
        font-weight: 700;
        text-transform: uppercase
    }

    .bf-order-status.pending {
        background: rgba(245, 158, 11, .2);
        color: #f59e0b
    }

    .bf-order-status.preparing {
        background: rgba(99, 102, 241, .2);
        color: #6366f1
    }

    .bf-order-status.served {
        background: rgba(16, 185, 129, .2);
        color: #10b981
    }

    .bf-order-status.completed {
        background: rgba(107, 114, 128, .2);
        color: #9ca3af
    }

    .bf-order-btns {
        display: flex;
        gap: .35rem;
        margin-top: .4rem
    }

    .bf-order-btn {
        padding: .25rem .5rem;
        border-radius: 4px;
        font-size: .65rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .2s
    }

    .bf-order-btn.edit {
        background: rgba(99, 102, 241, .15);
        color: #6366f1
    }

    .bf-order-btn.del {
        background: rgba(239, 68, 68, .15);
        color: #ef4444
    }

    .bf-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: var(--text-muted)
    }

    .bf-empty-icon {
        font-size: 2rem;
        margin-bottom: .5rem
    }

    .bf-alert {
        padding: .75rem 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        font-size: .85rem;
        font-weight: 600
    }

    .bf-alert.ok {
        background: rgba(16, 185, 129, .15);
        border: 1px solid rgba(16, 185, 129, .3);
        color: #10b981
    }

    .bf-alert.err {
        background: rgba(239, 68, 68, .15);
        border: 1px solid rgba(239, 68, 68, .3);
        color: #ef4444
    }

    .bf-no-guest {
        padding: 1rem;
        text-align: center;
        font-size: .8rem;
        color: var(--text-muted);
        background: rgba(245, 158, 11, .08);
        border-radius: 8px
    }

    /* Multi-guest selection */
    .bf-guest-list {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .5rem
    }

    .bf-guest-item {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .45rem .55rem;
        border-radius: 6px;
        transition: background .15s;
        cursor: pointer
    }

    .bf-guest-item:hover {
        background: var(--bg-primary)
    }

    .bf-guest-item:has(input:checked) {
        background: rgba(16, 185, 129, .1)
    }

    .bf-guest-item input[type="checkbox"] {
        width: 16px;
        height: 16px;
        cursor: pointer
    }

    .bf-guest-item .guest-info {
        flex: 1
    }

    .bf-guest-item .guest-name {
        font-size: .8rem;
        font-weight: 600;
        color: var(--text-primary)
    }

    .bf-guest-item .guest-room {
        font-size: .68rem;
        color: var(--text-muted)
    }

    .bf-guest-count {
        font-size: .72rem;
        color: var(--primary-color);
        font-weight: 600;
        margin-top: .4rem
    }

    .bf-guest-tools {
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .bf-link-guest-btn {
        padding: .25rem .5rem;
        border: none;
        border-radius: 6px;
        font-size: .66rem;
        font-weight: 700;
        cursor: pointer;
        background: rgba(14, 165, 233, .14);
        color: #0284c7
    }

    .bf-setup-guest-btn {
        padding: .25rem .5rem;
        border: none;
        border-radius: 6px;
        font-size: .66rem;
        font-weight: 700;
        cursor: pointer;
        background: rgba(99, 102, 241, .14);
        color: #4f46e5
    }

    .bf-wa-phone {
        font-size: .62rem;
        color: #64748b;
        max-width: 120px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .bf-wa-panel {
        margin-top: .6rem;
        padding: .65rem;
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        background: var(--bg-primary)
    }

    .bf-wa-panel-title {
        font-size: .72rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-bottom: .45rem
    }

    .bf-wa-row {
        display: flex;
        gap: .45rem;
        align-items: center;
        flex-wrap: wrap;
        margin-top: .45rem
    }

    .bf-link-send {
        padding: .35rem .65rem;
        border: none;
        border-radius: 6px;
        background: linear-gradient(135deg, #0ea5e9, #0284c7);
        color: #fff;
        font-size: .68rem;
        font-weight: 700;
        cursor: pointer
    }

    .bf-link-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(120px, 1fr));
        gap: .45rem
    }

    .bf-link-group {
        display: flex;
        flex-direction: column;
        gap: .25rem
    }

    .bf-link-group label {
        font-size: .64rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .25px
    }

    .bf-link-group input {
        padding: .35rem .45rem;
        border-radius: 6px;
        border: 1px solid var(--bg-tertiary);
        background: var(--bg-secondary);
        color: var(--text-primary);
        font-size: .72rem
    }

    .bf-child-menu-list {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .4rem
    }

    .bf-child-menu-item {
        font-size: .66rem;
        padding: .2rem .4rem;
        border-radius: 999px;
        background: rgba(99, 102, 241, .12);
        color: #4f46e5;
        display: flex;
        align-items: center;
        gap: .25rem
    }

    .bf-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, .45);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px
    }

    .bf-modal {
        width: min(700px, 100%);
        max-height: 85vh;
        overflow: auto;
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 12px;
        padding: 12px
    }

    .bf-modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: .6rem
    }

    .bf-modal-title {
        font-size: .9rem;
        font-weight: 800;
        color: var(--text-primary)
    }

    .bf-modal-close {
        border: none;
        background: rgba(239, 68, 68, .12);
        color: #ef4444;
        border-radius: 6px;
        padding: .3rem .55rem;
        font-weight: 700;
        cursor: pointer
    }

    .bf-modal-backdrop.show {
        display: flex
    }

    /* Notes in sidebar */
    .bf-order-note {
        font-size: .62rem;
        color: #f59e0b;
        font-style: italic;
        margin-left: .2rem
    }

    .bf-order-special {
        font-size: .68rem;
        color: var(--text-muted);
        background: rgba(245, 158, 11, .08);
        padding: .3rem .5rem;
        border-radius: 4px;
        margin-top: .35rem;
        font-style: italic;
        border-left: 2px solid #f59e0b
    }

    .bf-order-btn.print {
        background: rgba(16, 185, 129, .15);
        color: #10b981
    }

    .bf-custom-extra {
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: 8px;
        padding: .65rem;
        margin-bottom: .5rem;
        transition: all .2s
    }

    .bf-custom-extra:hover {
        border-color: #f59e0b
    }

    @media(max-width:900px) {
        .bf-grid {
            grid-template-columns: 1fr
        }

        .bf-side {
            position: static
        }

        .bf-menu-grid {
            grid-template-columns: 1fr 1fr
        }
    }

    @media(max-width:600px) {
        .bf-row {
            grid-template-columns: 1fr
        }

        .bf-menu-grid {
            grid-template-columns: 1fr
        }

        .bf-radio-group {
            flex-direction: column
        }
    }
</style>

<div class="bf-wrap">
    <div class="bf-head">
        <h1>🍳 Breakfast Order</h1>
        <div class="bf-head-actions">
            <a href="breakfast.php" class="bf-head-btn">📋 Orders</a>
            <a href="in-house.php" class="bf-head-btn">👥 In House</a>
            <a href="dashboard.php" class="bf-head-btn">🏠 Dashboard</a>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?>
        <div class="bf-alert ok">✅ <?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>

    <div class="bf-grid">
        <!-- FORM -->
        <div class="bf-card">
            <form id="bfForm" autocomplete="off">
                <?php if ($editOrder): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $editOrder['id']; ?>">
                <?php endif; ?>

                <!-- Guest Selection -->
                <div class="bf-section">
                    <div class="bf-title">👤 Pilih Tamu In-House <span style="font-size:.68rem;font-weight:400;color:var(--text-muted);margin-left:.5rem">(bisa pilih beberapa)</span></div>
                    <?php if (count($inHouseGuests) > 0 || $editOrder): ?>
                        <div class="bf-group">
                            <?php if ($editOrder): ?>
                                <?php
                                $editRooms = json_decode($editOrder['room_number'], true);
                                $editRoomStr = is_array($editRooms) ? implode(', ', $editRooms) : $editOrder['room_number'];
                                ?>
                                <label class="bf-label">Editing: <?php echo htmlspecialchars($editOrder['guest_name']); ?></label>
                                <input type="hidden" id="editGuestData"
                                    data-id="edit_<?php echo $editOrder['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($editOrder['guest_name']); ?>"
                                    data-rooms="<?php echo htmlspecialchars($editRoomStr); ?>"
                                    data-booking="<?php echo $editOrder['booking_id']; ?>">
                            <?php else: ?>
                                <label class="bf-label">Pilih Tamu (centang 1 atau lebih) *</label>
                                <div class="bf-guest-list" id="guestList">
                                    <?php foreach ($inHouseGuests as $g):
                                        $roomList = $g['rooms'];
                                        $bookingIdFirst = explode(',', $g['booking_ids'])[0];
                                        $savedQuota = $guestQuotaMap[(int)$bookingIdFirst] ?? [];
                                        $savedChildIds = json_decode($savedQuota['child_menu_ids'] ?? '[]', true);
                                        if (!is_array($savedChildIds)) $savedChildIds = [];
                                        $savedChildIds = array_values(array_unique(array_map('intval', $savedChildIds)));
                                    ?>
                                        <label class="bf-guest-item">
                                            <input type="checkbox" name="guest_checks[]" value="<?php echo $g['guest_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($g['guest_name']); ?>"
                                                data-rooms="<?php echo htmlspecialchars($roomList); ?>"
                                                data-booking="<?php echo $bookingIdFirst; ?>"
                                                data-phone="<?php echo htmlspecialchars($g['guest_phone'] ?? ''); ?>"
                                                data-adults="<?php echo (int)($savedQuota['adult_count'] ?? 1); ?>"
                                                data-child-young="<?php echo (int)($savedQuota['child_young_count'] ?? 0); ?>"
                                                data-child-old="0"
                                                data-total-pax="<?php echo (int)($savedQuota['total_pax'] ?? 0); ?>"
                                                data-max-main="<?php echo (int)($savedQuota['max_main'] ?? 2); ?>"
                                                data-max-drink="<?php echo (int)($savedQuota['max_drink'] ?? 2); ?>"
                                                data-max-child="<?php echo (int)($savedQuota['max_child'] ?? 2); ?>"
                                                data-extra-main-price="<?php echo (int)($savedQuota['extra_main_price'] ?? 75000); ?>"
                                                data-extra-drink-price="<?php echo (int)((isset($savedQuota['extra_drink_price']) && (int)$savedQuota['extra_drink_price'] === 75000) ? 20000 : ($savedQuota['extra_drink_price'] ?? 20000)); ?>"
                                                data-extra-child-price="<?php echo (int)($savedQuota['extra_child_price'] ?? 75000); ?>"
                                                data-child-menu-ids="<?php echo htmlspecialchars(json_encode($savedChildIds)); ?>">
                                            <div class="guest-info">
                                                <div class="guest-name"><?php echo htmlspecialchars($g['guest_name']); ?></div>
                                                <div class="guest-room">🛏️ Room <?php echo $roomList; ?></div>
                                            </div>
                                            <div class="bf-guest-tools">
                                                <?php if (!empty($g['guest_phone'])): ?>
                                                    <span class="bf-wa-phone" title="<?php echo htmlspecialchars($g['guest_phone']); ?>"><?php echo htmlspecialchars($g['guest_phone']); ?></span>
                                                <?php else: ?>
                                                    <span class="bf-wa-phone">No phone</span>
                                                <?php endif; ?>
                                                <button type="button" class="bf-setup-guest-btn" onclick="openGuestSetup(event,this)">Setup</button>
                                                <button type="button" class="bf-link-guest-btn" onclick="sendGuestSelectionLink(event,this)">Link</button>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="bf-guest-count" id="guestCount">0 tamu dipilih</div>
                                <div class="bf-wa-row" style="margin-top:.55rem">
                                    <button type="button" class="bf-link-send" onclick="sendSelectedGuestsPortalLinks()">🔗+📲 Buat Link & Kirim WA (terpilih)</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="bf-no-guest">🎉 Semua tamu in-house sudah order sarapan hari ini!</div>
                    <?php endif; ?>
                </div>

                <!-- Time & Details -->
                <div class="bf-section">
                    <div class="bf-title">⏰ Waktu & Detail</div>
                    <div class="bf-row">
                        <div class="bf-group">
                            <label class="bf-label">Jumlah Pax *</label>
                            <input type="number" name="total_pax" id="totalPax" class="bf-input" min="1" max="20" required value="<?php echo $editOrder ? (int)$editOrder['total_pax'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Jam *</label>
                            <input type="time" name="breakfast_time" id="bfTime" class="bf-input" required value="<?php echo $editOrder ? $editOrder['breakfast_time'] : ''; ?>">
                        </div>
                        <div class="bf-group">
                            <label class="bf-label">Tanggal</label>
                            <input type="date" name="breakfast_date" class="bf-input" value="<?php echo $editOrder ? $editOrder['breakfast_date'] : $today; ?>" readonly>
                        </div>
                    </div>
                    <div class="bf-row">
                        <div class="bf-group" style="grid-column:span 2">
                            <label class="bf-label">Lokasi *</label>
                            <div class="bf-radio-group">
                                <label class="bf-radio-label"><input type="radio" name="location" value="restaurant" <?php echo (!$editOrder || ($editOrder['location'] ?? '') === 'restaurant') ? 'checked' : ''; ?>> 🍽️ Restaurant</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="room_service" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'room_service') ? 'checked' : ''; ?>> 🛏️ Room Service</label>
                                <label class="bf-radio-label"><input type="radio" name="location" value="take_away" <?php echo ($editOrder && ($editOrder['location'] ?? '') === 'take_away') ? 'checked' : ''; ?>> 🥡 Take Away</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Menu -->
                <div class="bf-section">
                    <div class="bf-title">🍽️ Pilih Menu</div>

                    <?php if (count($freeMenus) > 0): ?>
                        <div style="margin-bottom:1rem">
                            <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">✨ Free Breakfast</div>
                            <div class="bf-menu-grid">
                                <?php foreach ($freeMenus as $m): ?>
                                    <div class="bf-menu-item">
                                        <label class="bf-menu-cb">
                                            <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                            <div>
                                                <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                                <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                            </div>
                                        </label>
                                        <div class="bf-menu-qty">
                                            <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                            <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                        </div>
                                        <div class="bf-menu-note">
                                            <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (count($paidMenus) > 0): ?>
                        <div>
                            <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem">💰 Extra (Berbayar)</div>
                            <div class="bf-menu-grid">
                                <?php foreach ($paidMenus as $m): ?>
                                    <div class="bf-menu-item">
                                        <label class="bf-menu-cb">
                                            <input type="checkbox" name="menu_items[]" value="<?php echo $m['id']; ?>" <?php echo in_array($m['id'], $editMenuIds) ? 'checked' : ''; ?>>
                                            <div>
                                                <div class="bf-menu-name"><?php echo htmlspecialchars($m['menu_name']); ?></div>
                                                <div class="bf-menu-price">Rp <?php echo number_format($m['price'], 0, ',', '.'); ?></div>
                                                <span class="bf-menu-cat"><?php echo $m['category']; ?></span>
                                            </div>
                                        </label>
                                        <div class="bf-menu-qty">
                                            <span style="font-size:.7rem;color:var(--text-muted)">Qty:</span>
                                            <input type="number" name="menu_qty[<?php echo $m['id']; ?>]" min="1" max="20" value="<?php echo $editMenuQty[$m['id']] ?? 1; ?>" class="bf-qty-input">
                                        </div>
                                        <div class="bf-menu-note">
                                            <input type="text" name="menu_note[<?php echo $m['id']; ?>]" class="bf-note-input" placeholder="Catatan: pedas/tidak, dll" value="<?php echo htmlspecialchars($editMenuNotes[$m['id']] ?? ''); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Custom Extra Breakfast (Manual) -->
                    <div style="margin-top:1rem">
                        <div style="font-size:.8rem;font-weight:700;margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center">
                            <span>🛒 Extra Breakfast (Manual)</span>
                            <button type="button" onclick="addCustomExtra()" style="padding:.3rem .65rem;background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;border:none;border-radius:6px;font-size:.72rem;font-weight:700;cursor:pointer">+ Tambah</button>
                        </div>
                        <div id="customExtrasContainer">
                            <?php if (!empty($editCustomExtras)): ?>
                                <?php foreach ($editCustomExtras as $idx => $ce): ?>
                                    <div class="bf-custom-extra" data-index="<?php echo $idx; ?>">
                                        <div style="display:flex;gap:.5rem;align-items:center">
                                            <input type="text" class="bf-input custom-extra-name" placeholder="Nama item, cth: Extra Nasi" value="<?php echo htmlspecialchars($ce['menu_name']); ?>" style="flex:1;font-size:.8rem;padding:.45rem .55rem" required>
                                            <input type="number" class="bf-input custom-extra-price" placeholder="Harga" value="<?php echo (int)$ce['price']; ?>" min="0" step="1000" style="width:110px;font-size:.8rem;padding:.45rem .55rem" required>
                                            <input type="number" class="bf-input custom-extra-qty" placeholder="Qty" value="<?php echo $ce['quantity'] ?? 1; ?>" min="1" max="20" style="width:55px;font-size:.8rem;padding:.45rem .55rem;text-align:center">
                                            <button type="button" onclick="this.closest('.bf-custom-extra').remove()" style="padding:.4rem .55rem;background:rgba(239,68,68,.15);color:#ef4444;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700">✕</button>
                                        </div>
                                        <input type="text" class="bf-note-input custom-extra-note" placeholder="Catatan (opsional)" value="<?php echo htmlspecialchars($ce['note'] ?? ''); ?>" style="margin-top:.35rem;width:100%">
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <p style="font-size:.65rem;color:var(--text-muted);margin-top:.4rem">Tambahkan item extra breakfast yang tidak ada di daftar menu. Isi nama dan harga manual.</p>
                    </div>
                </div>

                <!-- Notes -->
                <div class="bf-section">
                    <div class="bf-title">📝 Catatan</div>
                    <textarea name="special_requests" class="bf-textarea" placeholder="Alergi, permintaan khusus, dll"><?php echo $editOrder ? htmlspecialchars($editOrder['special_requests'] ?? '') : ''; ?></textarea>
                </div>

                <div class="bf-actions">
                    <button type="submit" class="bf-btn-submit" id="btnSubmit"><?php echo $editOrder ? '✓ Update Order' : '✓ Simpan Order'; ?></button>
                    <?php if ($editOrder): ?>
                        <a href="breakfast.php" class="bf-btn-reset">✕ Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- SIDEBAR: Today's Orders -->
        <div class="bf-side">
            <?php if (!empty($menuRecap)): ?>
            <div class="bf-recap-card">
                <div class="bf-recap-head">
                    <span>📋 Rekap Total per Menu <span style="font-weight:400;color:var(--text-muted);font-size:.7rem">(untuk kitchen)</span></span>
                    <button type="button" class="bf-recap-print" onclick="cetakRekap()" title="Cetak Rekap PDF">🖨️ PDF</button>
                </div>
                <table class="bf-recap-table">
                    <?php foreach ($menuRecap as $menuName => $qty): ?>
                        <tr>
                            <td class="bf-recap-name"><?php echo htmlspecialchars($menuName); ?></td>
                            <td class="bf-recap-qty">×<?php echo $qty; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <div class="bf-side-title">
                📊 Today's Orders
                <span class="bf-side-count"><?php echo count($todayOrders); ?></span>
            </div>

            <?php if (count($todayOrders) > 0): ?>
                <?php foreach ($todayOrders as $order): ?>
                    <div class="bf-order">
                        <div class="bf-order-head">
                            <span class="bf-order-time">🕐 <?php echo $order['breakfast_time'] ? date('H:i', strtotime($order['breakfast_time'])) : '-'; ?></span>
                            <span class="bf-order-pax"><?php echo $order['total_pax']; ?> pax</span>
                        </div>
                        <div class="bf-order-guest"><?php echo htmlspecialchars($order['guest_name']); ?></div>
                        <?php
                        $rooms = json_decode($order['room_number'], true);
                        $roomStr = is_array($rooms) ? implode(', ', $rooms) : ($order['room_number'] ?: '-');
                        // Detect Extra Breakfast (over-quota) for this order's room(s)
                        $orderRooms = is_array($rooms) ? $rooms : array_filter(array_map('trim', explode(',', (string)$order['room_number'])));
                        $extraAmt = 0;
                        foreach ($orderRooms as $rm) {
                            if (isset($extraByRoom[(string)$rm])) $extraAmt += (float)$extraByRoom[(string)$rm];
                        }
                        ?>
                        <div class="bf-order-room">🛏️ Room <?php echo htmlspecialchars($roomStr); ?></div>
                        <?php if ($extraAmt > 0): ?>
                            <div class="bf-order-extra-badge">⚠️ Extra Breakfast: Rp <?php echo number_format($extraAmt, 0, ',', '.'); ?> <span>(tagih saat check-out)</span></div>
                        <?php endif; ?>
                        <div class="bf-order-room"><?php echo ($order['location'] ?? 'restaurant') === 'restaurant' ? '🍽️ Restaurant' : (($order['location'] ?? '') === 'take_away' ? '🥡 Take Away' : '🚪 Room Service'); ?></div>
                        <div class="bf-order-menus">
                            <?php foreach ($order['menu_items'] as $item): ?>
                                <span class="bf-order-tag">
                                    <?php echo htmlspecialchars($item['menu_name'] ?? '?'); ?>
                                    <?php if (($item['quantity'] ?? 1) > 1): ?>×<?php echo $item['quantity']; ?><?php endif; ?>
                                    <?php if (!empty($item['note'])): ?><span class="bf-order-note">(<?php echo htmlspecialchars($item['note']); ?>)</span><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($order['special_requests'])): ?>
                            <div class="bf-order-special">📝 <?php echo htmlspecialchars($order['special_requests']); ?></div>
                        <?php endif; ?>
                        <div class="bf-order-foot">
                            <span class="bf-order-price"><?php echo $order['total_price'] > 0 ? 'Rp ' . number_format($order['total_price'], 0, ',', '.') : 'Free'; ?></span>
                            <span class="bf-order-status <?php echo $order['order_status']; ?>"><?php echo ucfirst($order['order_status']); ?></span>
                        </div>
                        <div class="bf-order-btns">
                            <a href="?edit=<?php echo $order['id']; ?>" class="bf-order-btn edit">✏️ Edit</a>
                            <button class="bf-order-btn print" onclick='cetakOrder(<?php echo json_encode($order, JSON_HEX_APOS | JSON_HEX_TAG); ?>)'>🖨️ PDF</button>
                            <button class="bf-order-btn del" onclick="hapusOrder(<?php echo $order['id']; ?>,'<?php echo htmlspecialchars(addslashes($order['guest_name'])); ?>')">🗑️ Hapus</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="bf-empty">
                    <div class="bf-empty-icon">📭</div>
                    <p style="font-size:.8rem">Belum ada order hari ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="bf-modal-backdrop" id="guestSetupModal">
    <div class="bf-modal">
        <div class="bf-modal-head">
            <div class="bf-modal-title" id="guestSetupTitle">Guest Setup</div>
            <button type="button" class="bf-modal-close" onclick="closeGuestSetup()">✕</button>
        </div>
        <div class="bf-link-grid">
            <div class="bf-link-group"><label>Jatah Pax (Dewasa)</label><input type="number" id="setupPax" min="0" max="20" oninput="updateSetupPax()"></div>
            <div class="bf-link-group"><label>Jatah Kids (Anak)</label><input type="number" id="setupKids" min="0" max="20" oninput="updateSetupPax()"></div>
            <div class="bf-link-group"><label>Total Pax</label><input type="number" id="setupTotalPax" min="0" max="40" readonly></div>
        </div>
        <div style="margin-top:.6rem;font-size:.72rem;color:var(--text-muted);line-height:1.5">
            <b>Jatah Pax</b> = jatah sarapan dewasa (makanan + minuman). <b>Jatah Kids</b> = jatah menu anak.<br>
            Jika tamu memilih lebih dari jatah, sistem otomatis mendeteksi kelebihannya dan tercatat sebagai <b>Extra Breakfast</b> untuk ditagih saat check-out.
        </div>
        <div class="bf-wa-row" style="justify-content:flex-end;margin-top:.8rem">
            <button type="button" class="bf-btn-reset" onclick="closeGuestSetup()">Cancel</button>
            <button type="button" class="bf-link-send" onclick="saveGuestSetup()">Save Setup</button>
        </div>
    </div>
</div>

<script>
    // Guest checkbox counter
    var guestChecks = document.querySelectorAll('input[name="guest_checks[]"]');
    var guestCountEl = document.getElementById('guestCount');
    var activeSetupCheckbox = null;
    if (guestChecks.length > 0) {
        guestChecks.forEach(function(cb) {
            cb.addEventListener('change', function() {
                var checked = document.querySelectorAll('input[name="guest_checks[]"]:checked');
                var count = checked.length;
                guestCountEl.textContent = count + ' tamu dipilih';
                if (count === 1) {
                    applyGuestSettingsFromCheckbox(checked[0]);
                }
            });
        });
    }

    function applyGuestSettingsFromCheckbox(cb) {
        if (!cb) return;
        var adults = parseInt(cb.dataset.adults || '1', 10) || 1;
        var childYoung = parseInt(cb.dataset.childYoung || '0', 10) || 0;
        var childOld = parseInt(cb.dataset.childOld || '0', 10) || 0;
        var maxMain = parseInt(cb.dataset.maxMain || '2', 10) || 2;
        var maxDrink = parseInt(cb.dataset.maxDrink || '2', 10) || 2;
        var maxChild = parseInt(cb.dataset.maxChild || '0', 10) || 0;
        var extraMainPrice = parseFloat(cb.dataset.extraMainPrice || '75000') || 75000;
        var extraDrinkPrice = parseFloat(cb.dataset.extraDrinkPrice || '20000') || 20000;
        if (Math.round(extraDrinkPrice) === 75000) extraDrinkPrice = 20000;
        var extraChildPrice = parseFloat(cb.dataset.extraChildPrice || '75000') || 75000;
        var childMenuIds = [];
        try {
            childMenuIds = JSON.parse(cb.dataset.childMenuIds || '[]');
        } catch (e) {
            childMenuIds = [];
        }

        cb.dataset.adults = String(adults);
        cb.dataset.childYoung = String(childYoung);
        cb.dataset.childOld = String(childOld);
        cb.dataset.maxMain = String(maxMain);
        cb.dataset.maxDrink = String(maxDrink);
        cb.dataset.maxChild = String(maxChild);
        cb.dataset.extraMainPrice = String(extraMainPrice);
        cb.dataset.extraDrinkPrice = String(extraDrinkPrice);
        cb.dataset.extraChildPrice = String(extraChildPrice);
        cb.dataset.childMenuIds = JSON.stringify(childMenuIds);
    }

    function openGuestSetup(evt, btn) {
        evt.preventDefault();
        evt.stopPropagation();
        var row = btn.closest('.bf-guest-item');
        var cb = row ? row.querySelector('input[name="guest_checks[]"]') : null;
        if (!cb) return;
        activeSetupCheckbox = cb;

        document.getElementById('guestSetupTitle').textContent = 'Setup: ' + (cb.dataset.name || 'Guest');
        var curPax = parseInt(cb.dataset.maxMain || cb.dataset.adults || '2', 10) || 2;
        var curKids = parseInt(cb.dataset.maxChild || cb.dataset.childYoung || '0', 10) || 0;
        document.getElementById('setupPax').value = curPax;
        document.getElementById('setupKids').value = curKids;
        document.getElementById('setupTotalPax').value = curPax + curKids;

        document.getElementById('guestSetupModal').classList.add('show');
    }

    function closeGuestSetup() {
        document.getElementById('guestSetupModal').classList.remove('show');
        activeSetupCheckbox = null;
    }

    function updateSetupPax() {
        var pax = Math.max(0, parseInt(document.getElementById('setupPax').value || '0', 10) || 0);
        var kids = Math.max(0, parseInt(document.getElementById('setupKids').value || '0', 10) || 0);
        document.getElementById('setupTotalPax').value = pax + kids;
    }

    function saveGuestSetup() {
        if (!activeSetupCheckbox) return;

        var pax = Math.max(0, parseInt(document.getElementById('setupPax').value || '2', 10) || 2);
        var kids = Math.max(0, parseInt(document.getElementById('setupKids').value || '0', 10) || 0);
        // Jatah Pax dewasa => kuota makanan & minuman. Jatah Kids => kuota menu anak.
        var adults = pax;
        var kidYoung = kids;
        var kidOld = 0;
        var food = pax;
        var drink = pax;
        var fruit = kids;
        var extraFood = 75000;
        var extraDrink = 20000;
        var extraFruit = 0; // Kids / fruit portion is always free.

        activeSetupCheckbox.dataset.adults = String(adults);
        activeSetupCheckbox.dataset.childYoung = String(kidYoung);
        activeSetupCheckbox.dataset.childOld = String(kidOld);
        activeSetupCheckbox.dataset.totalPax = String(adults + kidYoung + kidOld);
        activeSetupCheckbox.dataset.maxMain = String(food);
        activeSetupCheckbox.dataset.maxDrink = String(drink);
        activeSetupCheckbox.dataset.maxChild = String(fruit);
        activeSetupCheckbox.dataset.extraMainPrice = String(extraFood);
        activeSetupCheckbox.dataset.extraDrinkPrice = String(extraDrink);
        activeSetupCheckbox.dataset.extraChildPrice = String(extraFruit);

        if (activeSetupCheckbox.checked) {
            applyGuestSettingsFromCheckbox(activeSetupCheckbox);
        }

        closeGuestSetup();
    }

    // Collect common form data (menu, time, pax, etc)
    function collectFormData() {
        var pax = document.getElementById('totalPax').value;
        var time = document.getElementById('bfTime').value;
        if (!pax || parseInt(pax) < 1) {
            alert('Isi jumlah pax!');
            return null;
        }
        if (!time) {
            alert('Isi jam sarapan!');
            return null;
        }
        var menus = document.querySelectorAll('input[name="menu_items[]"]:checked');

        // Collect custom extras
        var customExtras = [];
        document.querySelectorAll('.bf-custom-extra').forEach(function(el) {
            var name = el.querySelector('.custom-extra-name').value.trim();
            var price = parseFloat(el.querySelector('.custom-extra-price').value) || 0;
            var qty = parseInt(el.querySelector('.custom-extra-qty').value) || 1;
            var note = el.querySelector('.custom-extra-note').value.trim();
            if (name && price >= 0) {
                customExtras.push({
                    name: name,
                    price: price,
                    quantity: qty,
                    note: note
                });
            }
        });

        if (menus.length === 0 && customExtras.length === 0) {
            alert('Pilih minimal 1 menu atau tambahkan extra manual!');
            return null;
        }
        var menuItems = [],
            menuQty = {},
            menuNote = {};
        menus.forEach(function(cb) {
            var id = cb.value;
            menuItems.push(id);
            var q = document.querySelector('input[name="menu_qty[' + id + ']"]');
            menuQty[id] = q ? parseInt(q.value) || 1 : 1;
            var n = document.querySelector('input[name="menu_note[' + id + ']"]');
            menuNote[id] = n ? n.value.trim() : '';
        });
        return {
            total_pax: parseInt(pax),
            breakfast_time: time,
            breakfast_date: document.querySelector('input[name="breakfast_date"]').value,
            location: (document.querySelector('input[name="location"]:checked') || {
                value: 'restaurant'
            }).value,
            special_requests: document.querySelector('textarea[name="special_requests"]').value.trim(),
            menu_items: menuItems,
            menu_qty: menuQty,
            menu_note: menuNote,
            custom_extras: customExtras
        };
    }

    // Form submit via AJAX
    var submitting = false;

    // Build an over-quota notification message from the save API response.
    function bfExtraMessage(res) {
        var charge = parseFloat(res && res.extra_charge) || 0;
        if (charge <= 0) return '';
        var parts = [];
        var em = parseInt(res.extra_main) || 0;
        var ed = parseInt(res.extra_drink) || 0;
        if (em > 0) parts.push(em + ' menu utama');
        if (ed > 0) parts.push(ed + ' minuman');
        var rp = 'Rp ' + charge.toLocaleString('id-ID');
        return '⚠️ Melebihi jatah sarapan: ' + parts.join(' + ') +
            '.\nTagihan Extra Breakfast ' + rp +
            ' otomatis ditambahkan ke Hotel Service Invoice.';
    }

    document.getElementById('bfForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (submitting) return;

        var common = collectFormData();
        if (!common) return;

        var editData = document.getElementById('editGuestData');
        var btn = document.getElementById('btnSubmit');

        if (editData) {
            // EDIT MODE: single guest update
            var roomsStr = editData.dataset.rooms || '';
            var roomArr = roomsStr ? roomsStr.split(',').map(function(r) {
                return r.trim();
            }) : [];
            var data = Object.assign({}, common, {
                action: 'update_order',
                edit_id: parseInt(document.querySelector('input[name="edit_id"]').value),
                booking_id: parseInt(editData.dataset.booking) || null,
                guest_name: editData.dataset.name || '',
                room_number: roomArr
            });
            submitting = true;
            btn.disabled = true;
            btn.textContent = '⏳ Menyimpan...';
            fetch('../../api/breakfast-save.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(res) {
                    if (res.success) {
                        var em = bfExtraMessage(res);
                        if (em) alert(em);
                        window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
                    } else {
                        alert('❌ ' + (res.message || 'Gagal'));
                        submitting = false;
                        btn.disabled = false;
                        btn.textContent = '✓ Update Order';
                    }
                }).catch(function(err) {
                    alert('❌ Error: ' + err.message);
                    submitting = false;
                    btn.disabled = false;
                    btn.textContent = '✓ Update Order';
                });
            return;
        }

        // CREATE MODE: multi-guest
        var checked = document.querySelectorAll('input[name="guest_checks[]"]:checked');
        if (checked.length === 0) {
            alert('Pilih minimal 1 tamu!');
            return;
        }

        var guests = [];
        checked.forEach(function(cb) {
            var roomsStr = cb.dataset.rooms || '';
            guests.push({
                guest_id: parseInt(cb.value) || null,
                guest_name: cb.dataset.name || '',
                room_number: roomsStr ? roomsStr.split(',').map(function(r) {
                    return r.trim();
                }) : [],
                booking_id: parseInt(cb.dataset.booking) || null
            });
        });

        submitting = true;
        btn.disabled = true;
        btn.textContent = '⏳ Menyimpan order...';

        var payload = Object.assign({}, common, {
            action: 'create_bulk',
            guests: guests
        });

        fetch('../../api/breakfast-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(res) {
                if (res.success) {
                    var em = bfExtraMessage(res);
                    if (em) alert(em);
                    window.location.href = 'breakfast.php?success=' + encodeURIComponent(res.message);
                } else {
                    alert('❌ ' + (res.message || 'Gagal menyimpan'));
                    submitting = false;
                    btn.disabled = false;
                    btn.textContent = '✓ Simpan Order';
                }
            })
            .catch(function(err) {
                alert('❌ Error koneksi: ' + err.message);
                submitting = false;
                btn.disabled = false;
                btn.textContent = '✓ Simpan Order';
            });
    });

    // Delete order
    function hapusOrder(id, name) {
        if (!confirm('Hapus order sarapan "' + name + '"?')) return;
        fetch('<?php echo BASE_URL; ?>/api/breakfast-order-action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: id
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(d) {
                if (d.success) location.reload();
                else alert('Gagal: ' + (d.message || '?'));
            })
            .catch(function() {
                alert('Error koneksi');
            });
    }

    // PDF Print — A4 format
    function cetakOrder(order) {
        var rooms = order.room_number;
        if (typeof rooms === 'string') {
            try {
                rooms = JSON.parse(rooms);
            } catch (e) {
                rooms = [rooms];
            }
        }
        var roomStr = Array.isArray(rooms) ? rooms.join(', ') : (rooms || '-');
        var items = order.menu_items;
        if (typeof items === 'string') {
            try {
                items = JSON.parse(items);
            } catch (e) {
                items = [];
            }
        }
        var locMap = {
            restaurant: 'Restaurant',
            room_service: 'Room Service',
            take_away: 'Take Away'
        };
        var locLabel = locMap[order.location] || order.location;
        var timeStr = order.breakfast_time ? order.breakfast_time.substring(0, 5) : '-';
        var dateStr = order.breakfast_date || '<?php echo $today; ?>';

        var html = '<div style="font-family:Arial,sans-serif;width:100%;max-width:700px;margin:0 auto;padding:30px 40px;color:#1a1a2e">';

        // Header
        html += '<div style="text-align:center;border-bottom:3px solid #f59e0b;padding-bottom:15px;margin-bottom:25px">';
        html += '<div style="font-size:28px;font-weight:800;color:#f59e0b;letter-spacing:1px">BREAKFAST ORDER</div>';
        html += '<div style="font-size:14px;color:#374151;margin-top:6px;font-weight:600"><?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Karimunjawa"); ?></div>';
        html += '<div style="font-size:11px;color:#9ca3af;margin-top:4px">Order #' + (order.id || '-') + ' | ' + dateStr + '</div>';
        html += '</div>';

        // Guest info
        html += '<table style="width:100%;font-size:13px;margin-bottom:25px;border-collapse:collapse">';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;width:130px;border-bottom:1px solid #f3f4f6;vertical-align:top">Tamu</td><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #f3f4f6">' + escHtml(order.guest_name) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Room</td><td style="padding:8px 12px;font-weight:600;color:#6366f1;border-bottom:1px solid #f3f4f6">' + escHtml(roomStr) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Tanggal</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + dateStr + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Jam</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + timeStr + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Jumlah Pax</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + (order.total_pax || 1) + '</td></tr>';
        html += '<tr><td style="padding:8px 12px;color:#6b7280;border-bottom:1px solid #f3f4f6">Lokasi</td><td style="padding:8px 12px;border-bottom:1px solid #f3f4f6">' + locLabel + '</td></tr>';
        html += '</table>';

        // Menu header
        html += '<div style="font-size:15px;font-weight:700;margin-bottom:12px;padding:10px 12px;background:#fef3c7;border-radius:6px">Menu Items</div>';

        // Menu table
        html += '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:20px">';
        html += '<thead><tr style="background:#f9fafb">';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280;text-transform:uppercase">Menu</th>';
        html += '<th style="padding:10px 12px;text-align:center;width:50px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Qty</th>';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Catatan</th>';
        html += '<th style="padding:10px 12px;text-align:right;width:110px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280">Harga</th>';
        html += '</tr></thead><tbody>';

        var totalPrice = 0;
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var price = parseFloat(it.price) || 0;
            var qty = parseInt(it.quantity) || 1;
            var lineTotal = it.is_free ? 0 : price * qty;
            totalPrice += lineTotal;
            html += '<tr style="border-bottom:1px solid #f3f4f6">';
            html += '<td style="padding:10px 12px;font-weight:600">' + escHtml(it.menu_name || '?');
            if (it.is_free) html += ' <span style="color:#10b981;font-size:10px;font-weight:400">(Free)</span>';
            html += '</td>';
            html += '<td style="padding:10px 12px;text-align:center">' + qty + '</td>';
            html += '<td style="padding:10px 12px;color:#92400e;font-style:italic">' + escHtml(it.note || '-') + '</td>';
            html += '<td style="padding:10px 12px;text-align:right">' + (lineTotal > 0 ? 'Rp ' + numberFmt(lineTotal) : '-') + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';

        // Special requests
        if (order.special_requests) {
            html += '<div style="margin-bottom:20px;padding:12px 14px;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:4px;font-size:12px">';
            html += '<strong>Catatan Khusus:</strong> ' + escHtml(order.special_requests);
            html += '</div>';
        }

        // Total
        html += '<div style="text-align:right;padding:14px 12px;border-top:2px solid #e5e7eb;margin-bottom:30px">';
        if (totalPrice > 0) {
            html += '<span style="font-size:18px;font-weight:800;color:#10b981">Total: Rp ' + numberFmt(totalPrice) + '</span>';
        } else {
            html += '<span style="font-size:15px;font-weight:700;color:#6b7280">Free Breakfast</span>';
        }
        html += '</div>';

        // Footer — no absolute positioning, just at the end with spacing
        html += '<div style="text-align:center;font-size:9px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:40px">';
        html += 'Printed from ADF System — <?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Hotel"); ?> &copy; <?php echo date("Y"); ?>';
        html += '<br>Printed: ' + new Date().toLocaleString('id-ID');
        html += '</div>';

        html += '</div>';

        var container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        html2pdf().set({
            margin: [10, 15, 15, 15],
            filename: 'breakfast-' + escHtml(order.guest_name).replace(/[\s,]+/g, '-') + '-' + dateStr + '.pdf',
            html2canvas: {
                scale: 2,
                useCORS: true
            },
            jsPDF: {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait'
            },
            pagebreak: {
                mode: ['avoid-all']
            }
        }).from(container).save().then(function() {
            document.body.removeChild(container);
        });
    }

    // Rekap Total Pesanan per Menu — PDF untuk kitchen prep
    var rekapMenuData = <?php echo json_encode($menuRecap, JSON_HEX_APOS | JSON_HEX_TAG); ?>;
    var rekapTotalPax = <?php echo (int) array_sum(array_column($todayOrders, 'total_pax')); ?>;

    function cetakRekap() {
        var entries = Object.keys(rekapMenuData).map(function(name) {
            return {
                name: name,
                qty: rekapMenuData[name]
            };
        });

        var html = '<div style="font-family:Arial,sans-serif;width:100%;max-width:700px;margin:0 auto;padding:30px 40px;color:#1a1a2e">';

        html += '<div style="text-align:center;border-bottom:3px solid #f59e0b;padding-bottom:15px;margin-bottom:25px">';
        html += '<div style="font-size:26px;font-weight:800;color:#f59e0b;letter-spacing:1px">REKAP PESANAN SARAPAN</div>';
        html += '<div style="font-size:14px;color:#374151;margin-top:6px;font-weight:600"><?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Karimunjawa"); ?></div>';
        html += '<div style="font-size:11px;color:#9ca3af;margin-top:4px"><?php echo $today; ?> | Total ' + <?php echo (int) count($todayOrders); ?> + ' order, ' + rekapTotalPax + ' pax</div>';
        html += '</div>';

        html += '<table style="width:100%;font-size:13px;border-collapse:collapse">';
        html += '<thead><tr style="background:#fef3c7">';
        html += '<th style="padding:10px 12px;text-align:left;border-bottom:2px solid #fde68a;font-size:11px;color:#92400e;text-transform:uppercase">Menu</th>';
        html += '<th style="padding:10px 12px;text-align:right;width:100px;border-bottom:2px solid #fde68a;font-size:11px;color:#92400e;text-transform:uppercase">Total Qty</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < entries.length; i++) {
            html += '<tr style="border-bottom:1px solid #f3f4f6">';
            html += '<td style="padding:10px 12px;font-weight:600">' + escHtml(entries[i].name) + '</td>';
            html += '<td style="padding:10px 12px;text-align:right;font-weight:800;color:#d97706;font-size:15px">×' + entries[i].qty + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';

        html += '<div style="text-align:center;font-size:9px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:12px;margin-top:30px">';
        html += 'Printed from ADF System — <?php echo htmlspecialchars($_SESSION["business_name"] ?? "Narayana Hotel"); ?> &copy; <?php echo date("Y"); ?>';
        html += '<br>Printed: ' + new Date().toLocaleString('id-ID');
        html += '</div>';

        html += '</div>';

        var container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        html2pdf().set({
            margin: [10, 15, 15, 15],
            filename: 'rekap-sarapan-<?php echo $today; ?>.pdf',
            html2canvas: {
                scale: 2,
                useCORS: true
            },
            jsPDF: {
                unit: 'mm',
                format: 'a4',
                orientation: 'portrait'
            },
            pagebreak: {
                mode: ['avoid-all']
            }
        }).from(container).save().then(function() {
            document.body.removeChild(container);
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function numberFmt(n) {
        return parseInt(n).toLocaleString('id-ID');
    }

    function addCustomExtra() {
        var container = document.getElementById('customExtrasContainer');
        var idx = container.querySelectorAll('.bf-custom-extra').length;
        var div = document.createElement('div');
        div.className = 'bf-custom-extra';
        div.dataset.index = idx;
        div.innerHTML = '<div style="display:flex;gap:.5rem;align-items:center">' +
            '<input type="text" class="bf-input custom-extra-name" placeholder="Nama item, cth: Extra Nasi" style="flex:1;font-size:.8rem;padding:.45rem .55rem" required>' +
            '<input type="number" class="bf-input custom-extra-price" placeholder="Harga" min="0" step="1000" style="width:110px;font-size:.8rem;padding:.45rem .55rem" required>' +
            '<input type="number" class="bf-input custom-extra-qty" placeholder="Qty" value="1" min="1" max="20" style="width:55px;font-size:.8rem;padding:.45rem .55rem;text-align:center">' +
            '<button type="button" onclick="this.closest(\'.bf-custom-extra\').remove()" style="padding:.4rem .55rem;background:rgba(239,68,68,.15);color:#ef4444;border:none;border-radius:6px;font-size:.8rem;cursor:pointer;font-weight:700">✕</button>' +
            '</div>' +
            '<input type="text" class="bf-note-input custom-extra-note" placeholder="Catatan (opsional)" style="margin-top:.35rem;width:100%">';
        container.appendChild(div);
        div.querySelector('.custom-extra-name').focus();
    }

    var linkContext = {
        createApi: <?php echo json_encode(BASE_URL . '/api/breakfast-guest-portal.php', JSON_UNESCAPED_UNICODE); ?>,
        childMenuDefaults: <?php echo json_encode($defaultChildMenuIds, JSON_UNESCAPED_UNICODE); ?>,
        portalLinkTemplate: <?php echo json_encode($guestLinkMessageTemplate, JSON_UNESCAPED_UNICODE); ?>
    };

    function renderChildMenuOptions() {
        return;
    }

    function getSelectedChildMenuIdsFromGuest(cb) {
        if (!cb) return [];
        var ids = [];
        try {
            ids = JSON.parse(cb.dataset.childMenuIds || '[]');
        } catch (e) {
            ids = [];
        }
        if (!Array.isArray(ids) || ids.length === 0) {
            ids = Array.isArray(linkContext.childMenuDefaults) ? linkContext.childMenuDefaults : [];
        }
        return ids.map(function(v) {
            return parseInt(v, 10);
        }).filter(function(v) {
            return Number.isFinite(v) && v > 0;
        });
    }

    async function createGuestPortalLinkFromCheckbox(cb) {
        var adultCount = parseInt(cb.dataset.adults || '1', 10);
        var childYoung = parseInt(cb.dataset.childYoung || '0', 10);
        var childOld = 0;
        var quotaMain = parseInt(cb.dataset.maxMain || '2', 10);
        var quotaDrink = parseInt(cb.dataset.maxDrink || '2', 10);
        var quotaChild = parseInt(cb.dataset.maxChild || '0', 10);
        var extraMainPrice = 75000;
        var extraDrinkPrice = parseFloat(cb.dataset.extraDrinkPrice || '20000');
        if (Math.round(extraDrinkPrice) === 75000) extraDrinkPrice = 20000;
        var extraChildPrice = 75000;
        var expireHours = 24;

        if (!Number.isFinite(adultCount) || adultCount < 0) adultCount = 0;
        if (!Number.isFinite(childYoung) || childYoung < 0) childYoung = 0;
        if (!Number.isFinite(childOld) || childOld < 0) childOld = 0;
        if (!Number.isFinite(quotaMain) || quotaMain < 0) quotaMain = 0;
        if (!Number.isFinite(quotaDrink) || quotaDrink < 0) quotaDrink = 0;
        if (!Number.isFinite(quotaChild) || quotaChild < 0) quotaChild = 0;
        if (!Number.isFinite(extraMainPrice) || extraMainPrice < 0) extraMainPrice = 75000;
        if (!Number.isFinite(extraDrinkPrice) || extraDrinkPrice < 0) extraDrinkPrice = 20000;
        if (!Number.isFinite(extraChildPrice) || extraChildPrice < 0) extraChildPrice = 75000;
        if (!Number.isFinite(expireHours) || expireHours < 1) expireHours = 24;

        var body = {
            action: 'create_link',
            guest_id: parseInt(cb.value, 10) || null,
            guest_name: cb.dataset.name || '',
            guest_phone: cb.dataset.phone || '',
            booking_id: parseInt(cb.dataset.booking, 10) || null,
            room_number: (cb.dataset.rooms || '').split(',').map(function(r) {
                return r.trim();
            }).filter(Boolean),
            breakfast_date: <?php echo json_encode($today); ?>,
            // New quota structure
            adult_count: adultCount,
            child_young_count: childYoung, // < 7 years old
            child_old_count: childOld, // >= 7 years old
            total_pax: adultCount + childYoung + childOld,
            max_main: quotaMain,
            max_drink: quotaDrink,
            max_child: quotaChild,
            extra_main_price: extraMainPrice,
            extra_drink_price: extraDrinkPrice,
            extra_child_price: extraChildPrice,
            child_menu_ids: getSelectedChildMenuIdsFromGuest(cb),
            expire_hours: expireHours
        };

        var res = await fetch(linkContext.createApi, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        });
        var data = await res.json();
        if (!data.success) {
            throw new Error(data.message || 'Gagal membuat link tamu');
        }
        return data.data || {};
    }

    function buildPortalLinkWaMessage(guestName, roomLabel, portalLink) {
        var template = (linkContext.portalLinkTemplate || '').trim();
        if (!template) {
            template = [
                'Hello {guest_name},',
                'Please select your breakfast menu using the link below:',
                '{portal_link}',
                '{room_line}',
                'The system will limit the selection based on your breakfast allowance.',
                'Thank you.'
            ].join('\n');
        }

        var message = template
            .replace(/\{guest_name\}/g, guestName || 'Guest')
            .replace(/\{room_label\}/g, roomLabel || '')
            .replace(/\{room_line\}/g, roomLabel ? 'Room: ' + roomLabel : '')
            .replace(/\{portal_link\}/g, portalLink || '');

        var cleaned = message
            .split('\n')
            .map(function(line) {
                return line.trim();
            })
            .filter(function(line, index, arr) {
                return line !== '' || (index > 0 && arr[index - 1] !== '');
            });

        var deduped = [];
        var lastLine = null;
        var seenPortalLink = false;
        for (var i = 0; i < cleaned.length; i++) {
            var line = cleaned[i];
            if (!line) {
                if (deduped.length && deduped[deduped.length - 1] !== '') deduped.push('');
                continue;
            }
            if (line === lastLine) continue;
            if (portalLink && line.indexOf(portalLink) !== -1) {
                if (seenPortalLink) continue;
                seenPortalLink = true;
            }
            deduped.push(line);
            lastLine = line;
        }

        return deduped.join('\n').trim();
    }

    async function sendGuestSelectionLink(evt, btn) {
        evt.preventDefault();
        evt.stopPropagation();
        var row = btn.closest('.bf-guest-item');
        var cb = row ? row.querySelector('input[name="guest_checks[]"]') : null;
        if (!cb) return;

        try {
            var linkData = await createGuestPortalLinkFromCheckbox(cb);
            var portalLink = linkData.short_link || linkData.link_url || '';
            var phone = normalizeWaPhone(cb.dataset.phone || '');
            if (!phone) {
                prompt('Link portal berhasil dibuat. Salin link berikut untuk dikirim manual:', portalLink);
                return;
            }
            var msg = buildPortalLinkWaMessage(cb.dataset.name || 'Tamu', cb.dataset.rooms || '-', portalLink);
            window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
        } catch (err) {
            alert('❌ ' + err.message);
        }
    }

    async function sendSelectedGuestsPortalLinks() {
        var selected = Array.from(document.querySelectorAll('input[name="guest_checks[]"]:checked'));
        if (!selected.length) {
            alert('Pilih minimal 1 tamu.');
            return;
        }

        var success = 0;
        var manualLinks = [];
        for (var i = 0; i < selected.length; i++) {
            var cb = selected[i];
            try {
                var linkData = await createGuestPortalLinkFromCheckbox(cb);
                var portalLink = linkData.short_link || linkData.link_url || '';
                var phone = normalizeWaPhone(cb.dataset.phone || '');
                if (phone) {
                    var msg = buildPortalLinkWaMessage(cb.dataset.name || 'Tamu', cb.dataset.rooms || '-', portalLink);
                    window.open('https://wa.me/' + phone + '?text=' + encodeURIComponent(msg), '_blank');
                    success++;
                } else {
                    manualLinks.push((cb.dataset.name || 'Tamu') + ': ' + portalLink);
                }
            } catch (err) {
                manualLinks.push((cb.dataset.name || 'Tamu') + ': ERROR - ' + err.message);
            }
        }

        if (manualLinks.length) {
            prompt('Sebagian link perlu kirim manual (copy text di bawah):', manualLinks.join('\n'));
        }
        alert('Selesai. Link WA otomatis dibuka untuk ' + success + ' tamu.');
    }

    function normalizeWaPhone(rawPhone) {
        var p = String(rawPhone || '').replace(/[^0-9]/g, '');
        if (!p) return '';
        if (p.indexOf('00') === 0) p = p.substring(2);
        if (p.indexOf('62') === 0) return p;
        if (p.charAt(0) === '0') return '62' + p.substring(1);
        if (p.charAt(0) === '8') return '62' + p;
        return p;
    }

    renderChildMenuOptions();
</script>

<?php include '../../includes/footer.php'; ?>