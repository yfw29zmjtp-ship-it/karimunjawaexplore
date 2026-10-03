<?php

/**
 * One-off admin tool: find & remove spam/bot bookings created via the public
 * website booking API before the honeypot + guest_name validation fix.
 * Access: admin/owner/developer only. Safe by default (dry-run list, no action
 * taken unless explicitly confirmed via POST).
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'owner', 'developer'], true)) {
    http_response_code(403);
    die('Access denied.');
}

$dbHost = DB_HOST;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$hotelDbName = getDbName('adf_narayana_hotel');

$pdo = new PDO("mysql:host=$dbHost;dbname=$hotelDbName;charset=utf8mb4", $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Patterns commonly injected by booking-form spam bots
$spamSql = "
    SELECT b.id AS booking_id, b.booking_code, b.status, b.created_at,
           g.id AS guest_id, g.guest_name, g.email, g.phone
    FROM bookings b
    JOIN guests g ON g.id = b.guest_id
    WHERE b.booking_source = 'online'
      AND b.status != 'cancelled'
      AND (
        g.guest_name REGEXP 'https?://|www\\.|\\.(com|net|org|ru|xyz|top|info|online)\\b'
        OR g.guest_name REGEXP '[0-9]{3,}'
        OR CHAR_LENGTH(g.guest_name) > 80
      )
    ORDER BY b.created_at DESC
";
$spamRows = $pdo->query($spamSql)->fetchAll(PDO::FETCH_ASSOC);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids']) && ($_POST['confirm'] ?? '') === '1') {
    $ids = array_map('intval', (array)$_POST['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $action = $_POST['action'] ?? 'cancel';

    if ($action === 'cancel') {
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', notes = CONCAT(COALESCE(notes,''), ' [Cancelled: spam/bot submission]') WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $message = count($ids) . ' spam booking(s) marked as cancelled.';
    } elseif ($action === 'delete') {
        // Also remove the guest record if it has no other bookings left
        $stmt = $pdo->prepare("SELECT guest_id FROM bookings WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $guestIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'guest_id');

        $del = $pdo->prepare("DELETE FROM bookings WHERE id IN ($placeholders)");
        $del->execute($ids);

        foreach (array_unique($guestIds) as $gid) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE guest_id = ?");
            $cnt->execute([$gid]);
            if ((int)$cnt->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM guests WHERE id = ?")->execute([$gid]);
            }
        }
        $message = count($ids) . ' spam booking(s) deleted permanently.';
    }

    $spamRows = $pdo->query($spamSql)->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Spam Booking Cleanup</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th,
        td {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid #334155;
            text-align: left;
            font-size: 0.85rem;
        }

        th {
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 0.7rem;
        }

        .msg {
            background: #10b98122;
            border: 1px solid #10b981;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }

        .empty {
            color: #64748b;
            padding: 2rem;
            text-align: center;
        }

        .actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.75rem;
        }

        button {
            padding: 0.6rem 1.25rem;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: #f59e0b;
            color: #1e293b;
        }

        .btn-delete {
            background: #ef4444;
            color: #fff;
        }

        code {
            color: #f5d67d;
        }
    </style>
</head>

<body>
    <h2>Spam / Bot Booking Cleanup — Narayana Hotel</h2>
    <p style="color:#94a3b8;">Matches bookings from <code>booking_source = 'online'</code> whose guest name looks like a URL/promo-spam injection.</p>

    <?php if ($message): ?>
        <div class="msg"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (empty($spamRows)): ?>
        <p class="empty">No suspicious bookings found. 🎉</p>
    <?php else: ?>
        <form method="post" onsubmit="if(!confirm('Are you sure? This action cannot be undone easily.')){return false;} document.getElementById('confirmFlag').value='1'; return true;">
            <input type="hidden" name="confirm" id="confirmFlag" value="0">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)"></th>
                        <th>Booking Code</th>
                        <th>Guest Name</th>
                        <th>Email / Phone</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spamRows as $row): ?>
                        <tr>
                            <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int)$row['booking_id'] ?>"></td>
                            <td><?= htmlspecialchars($row['booking_code']) ?></td>
                            <td style="max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($row['guest_name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?><br><?= htmlspecialchars($row['phone']) ?></td>
                            <td><?= htmlspecialchars($row['status']) ?></td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="actions">
                <button type="submit" name="action" value="cancel" class="btn-cancel">Mark Selected as Cancelled</button>
                <button type="submit" name="action" value="delete" class="btn-delete">Delete Selected Permanently</button>
            </div>
        </form>
    <?php endif; ?>
</body>

</html>