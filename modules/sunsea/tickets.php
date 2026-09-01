<?php

/**
 * Sunsea - Database Tiket (Kapal, BTN, dll)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();

// Auto-create tickets table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tickets` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_code`     VARCHAR(20) UNIQUE,
        `ticket_type`     VARCHAR(50) NOT NULL,
        `ticket_name`     VARCHAR(150) NOT NULL,
        `description`     TEXT,
        `unit`            VARCHAR(30) DEFAULT 'pax',
        `price_cost`      DECIMAL(15,2) DEFAULT 0.00,
        `price_sell`      DECIMAL(15,2) DEFAULT 0.00,
        `notes`           TEXT,
        `is_active`       TINYINT(1) DEFAULT 1,
        `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ticket_active (`is_active`),
        INDEX idx_ticket_type (`ticket_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // table may already exist or no permission — continue
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $payload = [
        'ticket_type' => $_POST['ticket_type'] ?? 'express_bahari',
        'ticket_name' => trim($_POST['ticket_name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'unit' => trim($_POST['unit'] ?? 'pax'),
        'price_cost' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_cost'] ?? '0'),
        'price_sell' => (float)str_replace(['.', ','], ['', '.'], $_POST['price_sell'] ?? '0'),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($payload['ticket_name'] === '') {
        $_SESSION['flash_message'] = 'Nama tiket wajib diisi.';
        $_SESSION['flash_type'] = 'error';
        header('Location: tickets.php');
        exit;
    }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE tickets
                SET ticket_type=?, ticket_name=?, description=?, unit=?, price_cost=?, price_sell=?, notes=?, is_active=?, updated_at=NOW()
                WHERE id=?")
                ->execute([
                    $payload['ticket_type'],
                    $payload['ticket_name'],
                    $payload['description'],
                    $payload['unit'],
                    $payload['price_cost'],
                    $payload['price_sell'],
                    $payload['notes'],
                    $payload['is_active'],
                    $id
                ]);
            $_SESSION['flash_message'] = 'Data tiket berhasil diperbarui.';
        } else {
            $lastCode = $pdo->query("SELECT ticket_code FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
            $next = 1;
            if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) {
                $next = (int)$m[1] + 1;
            }
            $code = 'SS-TKT-' . str_pad($next, 3, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO tickets
                (ticket_code, ticket_type, ticket_name, description, unit, price_cost, price_sell, notes, is_active)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $code,
                    $payload['ticket_type'],
                    $payload['ticket_name'],
                    $payload['description'],
                    $payload['unit'],
                    $payload['price_cost'],
                    $payload['price_sell'],
                    $payload['notes'],
                    $payload['is_active']
                ]);
            $_SESSION['flash_message'] = 'Database tiket berhasil ditambahkan.';
        }
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: tickets.php');
    exit;
}

$rows = [];
$dbError = '';
try {
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    $rows = $pdo->query("SELECT * FROM tickets ORDER BY is_active DESC, ticket_type, ticket_name")->fetchAll();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$pageTitle = 'Database Tiket';
$activePage = 'database';
include 'layout-header.php';
?>

<div style="display:grid;grid-template-columns:400px 1fr;gap:18px;padding:16px;">
    <?php if ($dbError): ?>
        <div style="grid-column:1/-1;padding:12px;background:#fee;border:1px solid #f88;border-radius:4px;color:#c33;margin-bottom:12px;">
            <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
        </div>
    <?php endif; ?>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:12px;">Input Tiket</div>
        <form method="POST" style="display:flex;flex-direction:column;gap:12px;">
            <input type="hidden" name="action" value="save">

            <div class="ss-form-group">
                <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Tipe Tiket</label>
                <select name="ticket_type" class="ss-select" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;">
                    <option value="express_bahari">Express Bahari</option>
                    <option value="ferry">Ferry Siginjal</option>
                    <option value="pesawat_susi">Pesawat Susi Air</option>
                </select>
            </div>

            <div class="ss-form-group">
                <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Nama Tiket *</label>
                <input type="text" class="ss-input" name="ticket_name" required placeholder="Kapal Reguler PP / BTN Anak" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
            </div>

            <div class="ss-form-group">
                <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Deskripsi</label>
                <input type="text" class="ss-input" name="description" placeholder="Jam berangkat, estimasi waktu, dll" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div class="ss-form-group">
                    <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Unit</label>
                    <input type="text" class="ss-input" name="unit" value="pax" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
                </div>
                <div class="ss-form-group">
                    <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Harga Modal</label>
                    <input type="number" class="ss-input" name="price_cost" placeholder="0" step="0.01" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
                </div>
            </div>

            <div class="ss-form-group">
                <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Harga Jual</label>
                <input type="number" class="ss-input" name="price_sell" placeholder="0" step="0.01" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;">
            </div>

            <div class="ss-form-group">
                <label class="ss-label" style="display:block;margin-bottom:6px;font-weight:500;">Catatan</label>
                <textarea class="ss-textarea" name="notes" placeholder="Catatan khusus" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:inherit;box-sizing:border-box;min-height:80px;"></textarea>
            </div>

            <div class="ss-form-group" style="margin-bottom:0;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
                    <input type="checkbox" name="is_active" checked style="width:16px;height:16px;cursor:pointer;"> Aktif
                </label>
            </div>

            <button class="ss-btn ss-btn-primary" type="submit" style="padding:10px 16px;background:#C2410C;color:white;border:none;border-radius:4px;font-weight:600;cursor:pointer;font-size:14px;">💾 Simpan Tiket</button>
        </form>
    </div>

    <div class="ss-card">
        <div class="ss-card-title" style="margin-bottom:10px;">Daftar Database Tiket</div>
        <div class="ss-table-wrap" style="overflow-x:auto;">
            <table class="ss-table" style="width:100%;border-collapse:collapse;border:1px solid #ddd;">
                <thead>
                    <tr style="background:#f5f5f5;border-bottom:2px solid #ddd;">
                        <th style="padding:10px;text-align:left;border:1px solid #ddd;font-weight:600;">Kode</th>
                        <th style="padding:10px;text-align:left;border:1px solid #ddd;font-weight:600;">Tipe</th>
                        <th style="padding:10px;text-align:left;border:1px solid #ddd;font-weight:600;">Nama Tiket</th>
                        <th style="padding:10px;text-align:left;border:1px solid #ddd;font-weight:600;">Deskripsi</th>
                        <th style="padding:10px;text-align:right;border:1px solid #ddd;font-weight:600;">Harga Modal</th>
                        <th style="padding:10px;text-align:right;border:1px solid #ddd;font-weight:600;">Harga Jual</th>
                        <th style="padding:10px;text-align:center;border:1px solid #ddd;font-weight:600;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center;color:#999;padding:20px;border:1px solid #ddd;">Belum ada data tiket.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:10px;border:1px solid #ddd;"><?php echo htmlspecialchars($r['ticket_code'] ?? '-'); ?></td>
                                <td style="padding:10px;border:1px solid #ddd;"><?php echo ucfirst(str_replace('_', ' ', $r['ticket_type'] ?? '')); ?></td>
                                <td style="padding:10px;border:1px solid #ddd;"><strong><?php echo htmlspecialchars($r['ticket_name']); ?></strong><br><small style="color:#999;"><?php echo htmlspecialchars($r['description'] ?: '-'); ?></small></td>
                                <td style="padding:10px;border:1px solid #ddd;font-size:12px;color:#666;"><?php echo htmlspecialchars($r['description'] ?: '-'); ?></td>
                                <td style="padding:10px;border:1px solid #ddd;text-align:right;">Rp <?php echo number_format((float)($r['price_cost'] ?? 0), 0, ',', '.'); ?></td>
                                <td style="padding:10px;border:1px solid #ddd;text-align:right;font-weight:600;color:#C2410C;">Rp <?php echo number_format((float)($r['price_sell'] ?? 0), 0, ',', '.'); ?></td>
                                <td style="padding:10px;border:1px solid #ddd;text-align:center;"><?php echo $r['is_active'] ? '<span style="background:#e6f7ff;color:#C2410C;padding:4px 8px;border-radius:3px;font-size:12px;font-weight:600;">Aktif</span>' : '<span style="background:#f5f5f5;color:#666;padding:4px 8px;border-radius:3px;font-size:12px;font-weight:600;">Nonaktif</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'layout-footer.php';
