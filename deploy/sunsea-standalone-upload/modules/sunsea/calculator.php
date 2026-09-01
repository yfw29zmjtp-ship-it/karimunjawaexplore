<?php

/**
 * Sunsea - Kalkulator Harga Trip (Enhanced)
 * - Pilihan paket (3H2M, 4H3M, custom, dll)
 * - Input manual harga fleksibel
 * - Load komponen dari database (tiket, hotel, catering, guide, fasilitas)
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

// Ensure settings table exists (used for package preset prices)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    // Ignore: table may already exist or no privilege
}

// Save preset package prices for fast invoice creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_package_price_setup') {
    $priceKeys = [
        '2d1n' => 'calc_pkg_price_2d1n',
        '3d2n' => 'calc_pkg_price_3d2n',
        '4d3n' => 'calc_pkg_price_4d3n',
        '5d4n' => 'calc_pkg_price_5d4n',
    ];

    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
        foreach ($priceKeys as $postKey => $settingKey) {
            $raw = (string)($_POST['pkg_price_' . $postKey] ?? '0');
            $val = (float)str_replace(['.', ','], ['', '.'], $raw);
            if ($val < 0) {
                $val = 0;
            }
            $stmt->execute([$settingKey, (string)$val]);
        }
        $_SESSION['flash_message'] = 'Setup harga paket berhasil disimpan.';
        $_SESSION['flash_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['flash_message'] = 'Gagal simpan setup harga paket: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }

    header('Location: bookings.php');
    exit;
}

// Create invoice directly from calculator and open print view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_invoice_from_calc') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $tripName   = trim($_POST['trip_name'] ?? 'Trip Custom');
    $paxCount   = max(1, (int)($_POST['pax_count'] ?? 1));
    $taxPct     = max(0, (float)($_POST['tax_pct'] ?? 0));
    $dpAmount   = (float)str_replace(['.', ','], ['', '.'], (string)($_POST['dp_amount'] ?? '0'));
    $dpDateRaw  = trim((string)($_POST['dp_date'] ?? ''));
    $dpDate     = $dpDateRaw !== '' ? $dpDateRaw : date('Y-m-d');
    $payload    = $_POST['calc_payload'] ?? '[]';
    $itemsData  = json_decode($payload, true);
    $user       = $auth->getCurrentUser()['username'] ?? 'system';

    if ($customerId <= 0) {
        $_SESSION['flash_message'] = 'Pilih customer terlebih dahulu sebelum cetak invoice.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php');
        exit;
    }

    if (!is_array($itemsData) || empty($itemsData)) {
        $_SESSION['flash_message'] = 'Komponen kalkulasi kosong. Tambahkan item dulu.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php');
        exit;
    }

    $invoiceItems = [];
    $subtotal = 0.0;

    foreach ($itemsData as $idx => $row) {
        $desc   = trim((string)($row['desc'] ?? ''));
        $qty    = (float)($row['qty'] ?? 0);
        $unit   = trim((string)($row['unit'] ?? 'unit'));
        $price  = (float)($row['price'] ?? 0);
        $perPax = !empty($row['perPax']);
        $type   = trim((string)($row['type'] ?? 'other'));

        if ($desc === '' || $qty <= 0) {
            continue;
        }

        $effectiveQty = $perPax ? ($qty * $paxCount) : $qty;
        $lineSubtotal = $effectiveQty * $price;
        $subtotal += $lineSubtotal;

        $invoiceItems[] = [
            'type' => $type,
            'desc' => $desc,
            'qty' => $effectiveQty,
            'unit' => $unit,
            'price' => $price,
            'subtotal' => $lineSubtotal,
            'sort_order' => $idx,
        ];
    }

    if (empty($invoiceItems)) {
        $_SESSION['flash_message'] = 'Tidak ada item valid untuk dibuat invoice.';
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php');
        exit;
    }

    $taxAmount = round($subtotal * $taxPct / 100, 2);
    $totalAmount = $subtotal + $taxAmount;
    if ($dpAmount < 0) {
        $dpAmount = 0;
    }
    if ($dpAmount > $totalAmount) {
        $dpAmount = $totalAmount;
    }
    $remainingAmount = max(0, $totalAmount - $dpAmount);
    $invoiceStatus = $dpAmount <= 0 ? 'issued' : ($remainingAmount <= 0 ? 'paid' : 'partial');
    $dueDate = date('Y-m-d');
    $tripNotes = 'Generated from Kalkulator: ' . $tripName;
    if ($dpAmount > 0) {
        $tripNotes .= ' | DP: Rp ' . number_format($dpAmount, 0, ',', '.') . ' (' . $dpDate . ')';
    }

    try {
        $pdo->beginTransaction();

        $invoiceNo = sunseaNextNumber($pdo, 'invoice');
        $insInv = $pdo->prepare("INSERT INTO invoices
            (invoice_no, customer_id, trip_date, trip_end_date, pax_count,
             status, subtotal, tax_pct, tax_amount, discount_amount,
             total_amount, paid_amount, remaining_amount, due_date,
             notes, issued_at, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)");

        $insInv->execute([
            $invoiceNo,
            $customerId,
            date('Y-m-d'),
            date('Y-m-d'),
            $paxCount,
            $invoiceStatus,
            $subtotal,
            $taxPct,
            $taxAmount,
            0,
            $totalAmount,
            $dpAmount,
            $remainingAmount,
            $dueDate,
            $tripNotes,
            $user,
        ]);

        $invoiceId = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare("INSERT INTO invoice_items
            (invoice_id, item_type, description, qty, unit, unit_price, subtotal, sort_order)
            VALUES (?,?,?,?,?,?,?,?)");

        foreach ($invoiceItems as $it) {
            $insItem->execute([
                $invoiceId,
                $it['type'],
                $it['desc'],
                $it['qty'],
                $it['unit'],
                $it['price'],
                $it['subtotal'],
                $it['sort_order'],
            ]);
        }

        if ($dpAmount > 0) {
            $insPay = $pdo->prepare("INSERT INTO payments (invoice_id, payment_date, amount, method, reference, notes, created_by)
                VALUES (?,?,?,?,?,?,?)");
            $insPay->execute([
                $invoiceId,
                $dpDate,
                $dpAmount,
                'transfer',
                'DP-' . $invoiceNo,
                'DP dari Booking Kalkulator',
                $user,
            ]);
        }

        $pdo->commit();
        header('Location: invoices.php?action=print&id=' . $invoiceId);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_message'] = 'Gagal membuat invoice dari kalkulator: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
        header('Location: bookings.php');
        exit;
    }
}

// Load semua data master dari database
$dbPackages = $pdo->query("SELECT id, name, base_price, duration_days, duration_nights, min_pax, max_pax, includes FROM trip_packages WHERE is_active=1 ORDER BY name")->fetchAll();
$dbCustomers = $pdo->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

// Database items untuk picker
$dbTickets = [];
try {
    $dbTickets = $pdo->query("SELECT id, ticket_name, ticket_type, price_cost, price_sell, unit FROM tickets WHERE is_active=1 ORDER BY ticket_type, ticket_name")->fetchAll();
} catch (Exception $e) {
}

$dbRooms = [];
try {
    $dbRooms = $pdo->query("SELECT r.id, r.room_type, r.price_cost, r.price_sell, p.name as partner_name
        FROM accommodation_rooms r JOIN accommodation_partners p ON p.id=r.partner_id
        WHERE r.is_active=1 AND p.is_active=1 ORDER BY p.name, r.room_type")->fetchAll();
} catch (Exception $e) {
}

$dbCaterings = [];
try {
    $dbCaterings = $pdo->query("SELECT id, vendor_name, menu_name, portion_unit, price_cost, price_sell FROM caterings WHERE is_active=1 ORDER BY vendor_name, menu_name")->fetchAll();
} catch (Exception $e) {
}

$dbGuides = [];
try {
    $dbGuides = $pdo->query("SELECT id, name, guide_type, daily_rate_cost, daily_rate_sell FROM guides WHERE is_active=1 ORDER BY guide_type, name")->fetchAll();
} catch (Exception $e) {
}

$dbFacilities = [];
try {
    $dbFacilities = $pdo->query("SELECT id, name, unit, price_cost, price_sell, category FROM facilities WHERE is_active=1 ORDER BY category, name")->fetchAll();
} catch (Exception $e) {
}

$pkgPresetPrices = [
    '2d1n' => (float)sunseaSetting($pdo, 'calc_pkg_price_2d1n', '0'),
    '3d2n' => (float)sunseaSetting($pdo, 'calc_pkg_price_3d2n', '0'),
    '4d3n' => (float)sunseaSetting($pdo, 'calc_pkg_price_4d3n', '0'),
    '5d4n' => (float)sunseaSetting($pdo, 'calc_pkg_price_5d4n', '0'),
];

// Encode semua data ke JSON untuk JavaScript
$jsTickets    = json_encode($dbTickets);
$jsRooms      = json_encode($dbRooms);
$jsCaterings  = json_encode($dbCaterings);
$jsGuides     = json_encode($dbGuides);
$jsFacilities = json_encode($dbFacilities);
$jsPkgPreset  = json_encode($pkgPresetPrices);

$pageTitle  = 'Booking';
$activePage = 'bookings';
include 'layout-header.php';
?>

<style>
    .calc-section {
        background: #fff;
        border: 1px solid #dde5ef;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 16px;
    }

    .calc-section-title {
        font-size: 14px;
        font-weight: 700;
        color: #7C2D12;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .pkg-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 10px;
        margin-bottom: 12px;
    }

    .pkg-card {
        border: 2px solid #dde5ef;
        border-radius: 8px;
        padding: 12px;
        text-align: center;
        cursor: pointer;
        transition: all .15s;
    }

    .pkg-card:hover,
    .pkg-card.active {
        border-color: #C2410C;
        background: #FFF7ED;
    }

    .pkg-card .pkg-duration {
        font-size: 20px;
        font-weight: 800;
        color: #7C2D12;
    }

    .pkg-card .pkg-label {
        font-size: 11px;
        color: #666;
        margin-top: 2px;
    }

    .pkg-card .pkg-price {
        font-size: 11px;
        font-weight: 700;
        color: #C2410C;
        margin-top: 6px;
    }

    .calc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .calc-table th {
        padding: 8px 10px;
        text-align: left;
        font-size: 11px;
        color: #666;
        background: #f8fbff;
        font-weight: 700;
    }

    .calc-table td {
        padding: 5px 7px;
        border-bottom: 1px solid #f0f4f8;
        vertical-align: middle;
    }

    .calc-input {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #dde5ef;
        border-radius: 4px;
        font-size: 12px;
        font-family: inherit;
        box-sizing: border-box;
    }

    .calc-input:focus {
        outline: none;
        border-color: #C2410C;
    }

    .calc-select {
        width: 100%;
        padding: 6px 8px;
        border: 1px solid #dde5ef;
        border-radius: 4px;
        font-size: 12px;
        font-family: inherit;
        background: #fff;
        box-sizing: border-box;
    }

    .calc-btn-sm {
        padding: 5px 10px;
        border: 1px solid #dde5ef;
        border-radius: 4px;
        font-size: 12px;
        cursor: pointer;
        background: #fff;
        font-family: inherit;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 13px;
    }

    .summary-total {
        display: flex;
        justify-content: space-between;
        font-size: 18px;
        font-weight: 800;
        color: #C2410C;
        border-top: 2px solid #C2410C;
        padding-top: 10px;
        margin-top: 6px;
    }

    .add-db-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        background: #FFF7ED;
        border: 1px solid #bae6fd;
        border-radius: 5px;
        font-size: 12px;
        font-weight: 600;
        color: #0369a1;
        cursor: pointer;
    }

    .add-db-btn:hover {
        background: #e0f2fe;
    }
</style>

<?php if (!empty($_SESSION['flash_message'])): ?>
    <div style="padding:10px 12px;margin-bottom:14px;border-radius:6px;font-size:13px;font-weight:600;
    background:<?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? '#ecfdf5' : '#fef2f2'; ?>;
    border:1px solid <?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? '#86efac' : '#fecaca'; ?>;
    color:<?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? '#166534' : '#991b1b'; ?>;">
        <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
    </div>
<?php unset($_SESSION['flash_message'], $_SESSION['flash_type']);
endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">

    <!-- LEFT: Builder -->
    <div>

        <!-- 1. PILIH PAKET -->
        <div class="calc-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;">
                <div class="calc-section-title" style="margin-bottom:0;">📦 Pilihan Paket</div>
                <button type="button" onclick="togglePkgSetup()" class="calc-btn-sm" style="font-weight:700;color:#7C2D12;">⚙️ Setup Harga Paket</button>
            </div>

            <div id="pkgSetupPanel" style="display:none;padding:12px;background:#f8fbff;border:1px solid #dbeafe;border-radius:8px;margin-bottom:12px;">
                <form method="POST" style="display:grid;grid-template-columns:repeat(4,minmax(110px,1fr)) auto;gap:8px;align-items:end;">
                    <input type="hidden" name="action" value="save_package_price_setup">
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">2D1N</label>
                        <input type="text" name="pkg_price_2d1n" class="calc-input" value="<?php echo number_format((float)$pkgPresetPrices['2d1n'], 0, ',', '.'); ?>" placeholder="0">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">3D2N</label>
                        <input type="text" name="pkg_price_3d2n" class="calc-input" value="<?php echo number_format((float)$pkgPresetPrices['3d2n'], 0, ',', '.'); ?>" placeholder="0">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">4D3N</label>
                        <input type="text" name="pkg_price_4d3n" class="calc-input" value="<?php echo number_format((float)$pkgPresetPrices['4d3n'], 0, ',', '.'); ?>" placeholder="0">
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px;">5D4N</label>
                        <input type="text" name="pkg_price_5d4n" class="calc-input" value="<?php echo number_format((float)$pkgPresetPrices['5d4n'], 0, ',', '.'); ?>" placeholder="0">
                    </div>
                    <button type="submit" style="padding:8px 12px;background:#C2410C;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">💾 Simpan</button>
                </form>
                <div style="margin-top:8px;font-size:11px;color:#64748b;">Harga di atas dipakai otomatis saat paket dipilih, untuk mempercepat pembuatan invoice.</div>
            </div>

            <div class="pkg-grid" id="pkgGrid">
                <div class="pkg-card" onclick="selectPkg(this,'2D1N','2 Hari 1 Malam',2,1)">
                    <div class="pkg-duration">2D1N</div>
                    <div class="pkg-label">2 Hari 1 Malam</div>
                    <div class="pkg-price" id="pkgPrice2d1n"><?php echo $pkgPresetPrices['2d1n'] > 0 ? 'Rp ' . number_format($pkgPresetPrices['2d1n'], 0, ',', '.') : 'Belum set'; ?></div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'3D2N','3 Hari 2 Malam',3,2)">
                    <div class="pkg-duration">3D2N</div>
                    <div class="pkg-label">3 Hari 2 Malam</div>
                    <div class="pkg-price" id="pkgPrice3d2n"><?php echo $pkgPresetPrices['3d2n'] > 0 ? 'Rp ' . number_format($pkgPresetPrices['3d2n'], 0, ',', '.') : 'Belum set'; ?></div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'4D3N','4 Hari 3 Malam',4,3)">
                    <div class="pkg-duration">4D3N</div>
                    <div class="pkg-label">4 Hari 3 Malam</div>
                    <div class="pkg-price" id="pkgPrice4d3n"><?php echo $pkgPresetPrices['4d3n'] > 0 ? 'Rp ' . number_format($pkgPresetPrices['4d3n'], 0, ',', '.') : 'Belum set'; ?></div>
                </div>
                <div class="pkg-card" onclick="selectPkg(this,'5D4N','5 Hari 4 Malam',5,4)">
                    <div class="pkg-duration">5D4N</div>
                    <div class="pkg-label">5 Hari 4 Malam</div>
                    <div class="pkg-price" id="pkgPrice5d4n"><?php echo $pkgPresetPrices['5d4n'] > 0 ? 'Rp ' . number_format($pkgPresetPrices['5d4n'], 0, ',', '.') : 'Belum set'; ?></div>
                </div>
                <div class="pkg-card" onclick="selectPkgCustom(this)">
                    <div class="pkg-duration" style="font-size:16px;">✏️</div>
                    <div class="pkg-label">Custom</div>
                    <div class="pkg-price">Manual</div>
                </div>
            </div>

            <div id="customDurWrap" style="display:none;margin-bottom:10px;">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Nama Paket</label>
                        <input type="text" id="customPkgName" class="calc-input" placeholder="Custom Trip">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Hari</label>
                        <input type="number" id="customDays" class="calc-input" value="3" min="1">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Malam</label>
                        <input type="number" id="customNights" class="calc-input" value="2" min="0">
                    </div>
                </div>
                <button onclick="applyCustomPkg()" style="margin-top:8px;padding:6px 14px;background:#C2410C;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer;font-size:12px;">Terapkan</button>
            </div>

            <div style="border-top:1px solid #eef2f7;padding-top:12px;margin-top:4px;">
                <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:6px;">Atau Load dari Paket Wisata Tersimpan:</label>
                <select id="quickPkg" class="calc-select" style="max-width:360px;" onchange="loadFromSavedPackage(this.value)">
                    <option value="">-- Pilih paket tersimpan --</option>
                    <?php foreach ($dbPackages as $p): ?>
                        <option value="<?php echo $p['id']; ?>"
                            data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                            data-price="<?php echo $p['base_price']; ?>"
                            data-days="<?php echo $p['duration_days']; ?>"
                            data-nights="<?php echo $p['duration_nights']; ?>"
                            data-includes="<?php echo htmlspecialchars($p['includes'] ?? '', ENT_QUOTES); ?>">
                            <?php echo htmlspecialchars($p['name']); ?> — Rp <?php echo number_format((float)$p['base_price'], 0, ',', '.'); ?>/org
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- 2. INFO TRIP -->
        <div class="calc-section">
            <div class="calc-section-title">ℹ️ Info Trip</div>
            <div style="display:grid;grid-template-columns:1fr 140px 140px 140px;gap:10px;">
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Nama Trip / Paket</label>
                    <input type="text" id="tripName" class="calc-input" placeholder="Karimunjawa 3D2N">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Durasi</label>
                    <input type="text" id="tripDuration" class="calc-input" placeholder="3H 2M" readonly style="background:#f8fbff;">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Jumlah Pax</label>
                    <input type="number" id="paxCount" class="calc-input" value="1" min="1" oninput="recalc()">
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Margin (%)</label>
                    <input type="number" id="marginPct" class="calc-input" value="15" min="0" max="300" step="0.5" oninput="recalc()">
                </div>
            </div>
        </div>

        <!-- 3. KOMPONEN BIAYA -->
        <div class="calc-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div class="calc-section-title" style="margin-bottom:0;">🧾 Komponen Biaya</div>
                <button onclick="addManualRow()" style="padding:7px 12px;background:#C2410C;color:#fff;border:none;border-radius:5px;font-weight:600;font-size:12px;cursor:pointer;">+ Baris Manual</button>
            </div>

            <!-- Quick add dari database -->
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;padding:10px;background:#f8fbff;border:1px solid #dde5ef;border-radius:6px;">
                <span style="font-size:11px;font-weight:700;color:#7C2D12;align-self:center;">Tambah dari Database:</span>
                <button class="add-db-btn" onclick="openDbPicker('ticket')">🎫 Tiket</button>
                <button class="add-db-btn" onclick="openDbPicker('hotel')">🏨 Hotel/Penginapan</button>
                <button class="add-db-btn" onclick="openDbPicker('catering')">🍽️ Catering</button>
                <button class="add-db-btn" onclick="openDbPicker('guide')">👤 Guide</button>
                <button class="add-db-btn" onclick="openDbPicker('facility')">⚙️ Fasilitas</button>
            </div>

            <!-- DB Picker Panel -->
            <div id="dbPickerPanel" style="display:none;padding:12px;background:#fff8e1;border:1px solid #fde68a;border-radius:6px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <strong id="dbPickerTitle" style="font-size:13px;color:#92400e;min-width:80px;">Pilih Tiket:</strong>
                    <select id="dbPickerSelect" class="calc-select" style="flex:1;min-width:200px;max-width:360px;">
                        <option value="">-- Pilih item --</option>
                    </select>
                    <div style="display:flex;gap:4px;">
                        <input type="number" id="dbPickerQty" class="calc-input" value="1" min="0" step="0.5" style="width:70px;" placeholder="Qty">
                        <input type="text" id="dbPickerUnit" class="calc-input" value="unit" style="width:70px;" placeholder="Sat.">
                    </div>
                    <button onclick="addFromDb()" style="padding:7px 14px;background:#f59e0b;color:#fff;border:none;border-radius:4px;font-weight:700;font-size:12px;cursor:pointer;">+ Tambah</button>
                    <button onclick="closeDbPicker()" style="padding:7px 10px;background:#fff;border:1px solid #ddd;border-radius:4px;font-size:12px;cursor:pointer;">✕</button>
                </div>
                <div id="dbPickerPreview" style="margin-top:8px;font-size:12px;color:#666;"></div>
            </div>

            <!-- Tabel komponen -->
            <div style="overflow-x:auto;">
                <table class="calc-table" id="calcTable">
                    <thead>
                        <tr>
                            <th style="width:120px;">Kategori</th>
                            <th>Keterangan</th>
                            <th style="width:65px;">Qty</th>
                            <th style="width:65px;">Sat.</th>
                            <th style="width:120px;">Harga/Sat.</th>
                            <th style="width:40px;text-align:center;">/Pax</th>
                            <th style="width:110px;text-align:right;">Subtotal</th>
                            <th style="width:32px;"></th>
                        </tr>
                    </thead>
                    <tbody id="calcBody"></tbody>
                </table>
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">
                <button onclick="addManualRow('accommodation')" class="calc-btn-sm">+ Penginapan</button>
                <button onclick="addManualRow('transport')" class="calc-btn-sm">+ Transport</button>
                <button onclick="addManualRow('meal')" class="calc-btn-sm">+ Makan</button>
                <button onclick="addManualRow('activity')" class="calc-btn-sm">+ Aktivitas</button>
                <button onclick="addManualRow('guide')" class="calc-btn-sm">+ Guide</button>
                <button onclick="addManualRow('equipment')" class="calc-btn-sm">+ Perlengkapan</button>
                <button onclick="addManualRow('other')" class="calc-btn-sm">+ Lainnya</button>
            </div>
        </div>
    </div>

    <!-- RIGHT: Ringkasan -->
    <div>
        <div class="calc-section" style="position:sticky;top:72px;">
            <div class="calc-section-title" style="margin-bottom:14px;">📊 Ringkasan</div>

            <div style="background:#FFF7ED;border-radius:8px;padding:14px;margin-bottom:14px;">
                <div class="summary-row"><span style="color:#666;">Total Biaya</span><strong id="sumCost">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Biaya / Pax</span><strong id="sumCostPerPax">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Margin (<span id="marginLabel">15</span>%)</span><strong style="color:#f59e0b;" id="sumMargin">Rp 0</strong></div>
                <div class="summary-row"><span style="color:#666;">Harga Jual / Pax</span><strong style="font-size:15px;color:#C2410C;" id="sumSellPerPax">Rp 0</strong></div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">PPN (%)</label>
                <input type="number" id="taxCalc" class="calc-input" value="11" step="0.1" min="0" oninput="recalc()">
            </div>

            <div style="border-top:2px solid #C2410C;padding-top:12px;margin-bottom:14px;">
                <div class="summary-row"><span style="color:#666;">Subtotal</span><span id="sumSubtotal">Rp 0</span></div>
                <div class="summary-row"><span style="color:#666;">PPN</span><span id="sumTax">Rp 0</span></div>
                <div class="summary-total"><span>TOTAL</span><span id="sumTotal">Rp 0</span></div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">Customer (untuk penawaran)</label>
                <select id="toCustomer" class="calc-select">
                    <option value="">-- Pilih Customer --</option>
                    <?php foreach ($dbCustomers as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">Down Payment (DP)</label>
                    <input type="text" id="dpAmount" class="calc-input" placeholder="0" oninput="recalc()">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#666;display:block;margin-bottom:5px;">Tanggal DP</label>
                    <input type="date" id="dpDate" class="calc-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <button onclick="recalc()" style="width:100%;padding:9px;background:#fff;color:#C2410C;border:1px solid #C2410C;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;margin-bottom:8px;">
                🧮 Hitung Kalkulasi Ecer
            </button>
            <button onclick="saveTempBooking()" style="width:100%;padding:9px;background:#fff;color:#7C2D12;border:1px solid #93c5fd;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;margin-bottom:8px;">
                💾 Save Sementara
            </button>
            <button onclick="sendToQuotation()" style="width:100%;padding:11px;background:#C2410C;color:#fff;border:none;border-radius:6px;font-weight:700;font-size:14px;cursor:pointer;margin-bottom:8px;">
                📄 Buat Penawaran dari Booking
            </button>
            <button onclick="printInvoiceFromCalculator()" style="width:100%;padding:9px;background:#fff;color:#C2410C;border:1px solid #C2410C;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;margin-bottom:8px;">
                🖨️ Cetak Invoice
            </button>
            <button onclick="clearAll()" style="width:100%;padding:8px;background:#fff;color:#ef4444;border:1px solid #fca5a5;border-radius:6px;font-weight:600;font-size:12px;cursor:pointer;">
                🗑️ Bersihkan Semua
            </button>
        </div>
    </div>
</div>

<form id="toQuotationForm" method="GET" action="quotations.php" style="display:none;">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="customer_id" id="fCustomerId">
    <input type="hidden" name="from_calc" value="1">
</form>

<form id="printInvoiceForm" method="POST" action="calculator.php" style="display:none;">
    <input type="hidden" name="action" value="create_invoice_from_calc">
    <input type="hidden" name="customer_id" id="piCustomerId">
    <input type="hidden" name="trip_name" id="piTripName">
    <input type="hidden" name="pax_count" id="piPaxCount">
    <input type="hidden" name="tax_pct" id="piTaxPct">
    <input type="hidden" name="dp_amount" id="piDpAmount">
    <input type="hidden" name="dp_date" id="piDpDate">
    <input type="hidden" name="calc_payload" id="piPayload">
</form>

<script>
    var DB = {
        ticket: <?php echo $jsTickets; ?>,
        hotel: <?php echo $jsRooms; ?>,
        catering: <?php echo $jsCaterings; ?>,
        guide: <?php echo $jsGuides; ?>,
        facility: <?php echo $jsFacilities; ?>
    };
    var PACKAGE_PRESET_PRICES = <?php echo $jsPkgPreset; ?>;
    var activePkgDays = 0,
        activePkgNights = 0,
        currentDbType = '';

    function togglePkgSetup() {
        var p = document.getElementById('pkgSetupPanel');
        if (!p) return;
        p.style.display = p.style.display === 'none' ? '' : 'none';
    }

    function removePresetPackageRows() {
        document.querySelectorAll('#calcBody tr').forEach(function(row) {
            var descEl = row.querySelector('.ci-desc');
            if (!descEl) return;
            var v = (descEl.value || '').trim().toLowerCase();
            if (v.indexOf('harga paket preset') === 0) {
                row.remove();
            }
        });
    }

    function applyPresetPackagePrice(packageCode) {
        var key = String(packageCode || '').toLowerCase();
        var price = parseFloat(PACKAGE_PRESET_PRICES[key] || 0);
        removePresetPackageRows();
        if (price > 0) {
            addManualRow('other', 'Harga Paket Preset ' + String(packageCode).toUpperCase(), 1, 'pax', price, true);
        }
    }

    function selectPkg(el, code, label, days, nights) {
        document.querySelectorAll('.pkg-card').forEach(function(c) {
            c.classList.remove('active');
        });
        el.classList.add('active');
        activePkgDays = days;
        activePkgNights = nights;
        document.getElementById('tripName').value = 'Trip Karimunjawa ' + code;
        document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
        document.getElementById('customDurWrap').style.display = 'none';
        applyPresetPackagePrice(code);
        recalc();
    }

    function selectPkgCustom(el) {
        document.querySelectorAll('.pkg-card').forEach(function(c) {
            c.classList.remove('active');
        });
        el.classList.add('active');
        document.getElementById('customDurWrap').style.display = '';
        removePresetPackageRows();
        recalc();
    }

    function applyCustomPkg() {
        var name = document.getElementById('customPkgName').value || 'Custom Trip';
        var days = parseInt(document.getElementById('customDays').value) || 1;
        var nights = parseInt(document.getElementById('customNights').value) || 0;
        activePkgDays = days;
        activePkgNights = nights;
        document.getElementById('tripName').value = name;
        document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
        removePresetPackageRows();
        recalc();
    }

    function loadFromSavedPackage(pkgId) {
        if (!pkgId) return;
        var opt = document.querySelector('#quickPkg option[value="' + pkgId + '"]');
        if (!opt) return;
        var days = parseInt(opt.dataset.days) || 1;
        var nights = parseInt(opt.dataset.nights) || 0;
        activePkgDays = days;
        activePkgNights = nights;
        document.getElementById('tripName').value = opt.dataset.name;
        document.getElementById('tripDuration').value = days + ' Hari ' + nights + ' Malam';
        var includes = opt.dataset.includes || '';
        if (includes) {
            document.getElementById('calcBody').innerHTML = '';
            includes.split('\n').forEach(function(line) {
                line = line.replace(/^[-*•]\s*/, '').trim();
                if (line) addManualRow('other', line, 1, 'unit', 0, false);
            });
        }
        var basePrice = parseFloat(opt.dataset.price) || 0;
        if (basePrice > 0) addManualRow('other', 'Harga Dasar: ' + opt.dataset.name, 1, 'paket', basePrice, false);
        document.querySelectorAll('.pkg-card').forEach(function(c) {
            c.classList.remove('active');
        });
        recalc();
    }

    var DB_LABELS = {
        ticket: '🎫 Pilih Tiket',
        hotel: '🏨 Pilih Hotel/Penginapan',
        catering: '🍽️ Pilih Catering',
        guide: '👤 Pilih Guide',
        facility: '⚙️ Pilih Fasilitas'
    };

    function openDbPicker(type) {
        currentDbType = type;
        document.getElementById('dbPickerTitle').textContent = DB_LABELS[type] || 'Pilih Item';
        var sel = document.getElementById('dbPickerSelect');
        sel.innerHTML = '<option value="">-- Pilih item --</option>';
        var items = DB[type] || [];
        items.forEach(function(item, i) {
            var label = '',
                cost = 0,
                sell = 0,
                unit = 'unit';
            if (type === 'ticket') {
                label = '[' + item.ticket_type + '] ' + item.ticket_name + ' — Jual: Rp ' + fmt0(item.price_sell);
                cost = item.price_cost;
                sell = item.price_sell;
                unit = item.unit || 'pax';
            } else if (type === 'hotel') {
                label = item.partner_name + ' — ' + item.room_type + ' — Rp ' + fmt0(item.price_sell) + '/malam';
                cost = item.price_cost;
                sell = item.price_sell;
                unit = 'malam';
            } else if (type === 'catering') {
                label = item.vendor_name + ' — ' + item.menu_name + ' (' + item.portion_unit + ') — Rp ' + fmt0(item.price_sell);
                cost = item.price_cost;
                sell = item.price_sell;
                unit = item.portion_unit || 'porsi';
            } else if (type === 'guide') {
                label = '[' + item.guide_type + '] ' + item.name + ' — Rp ' + fmt0(item.daily_rate_sell) + '/hari';
                cost = item.daily_rate_cost;
                sell = item.daily_rate_sell;
                unit = 'hari';
            } else if (type === 'facility') {
                label = (item.category ? '[' + item.category + '] ' : '') + item.name + ' (' + item.unit + ') — Rp ' + fmt0(item.price_sell);
                cost = item.price_cost;
                sell = item.price_sell;
                unit = item.unit || 'unit';
            }
            var opt = document.createElement('option');
            opt.value = i;
            opt.textContent = label;
            opt.dataset.cost = cost;
            opt.dataset.sell = sell;
            opt.dataset.unit = unit;
            sel.appendChild(opt);
        });
        sel.onchange = function() {
            var o = sel.options[sel.selectedIndex];
            if (!o || !o.value) {
                document.getElementById('dbPickerPreview').textContent = '';
                return;
            }
            document.getElementById('dbPickerPreview').innerHTML = '<strong>Modal:</strong> Rp ' + fmt0(o.dataset.cost) + ' &nbsp;|&nbsp; <strong>Jual:</strong> Rp ' + fmt0(o.dataset.sell) + ' &nbsp;|&nbsp; Satuan: <strong>' + (o.dataset.unit || '-') + '</strong>';
            document.getElementById('dbPickerUnit').value = o.dataset.unit || 'unit';
            document.getElementById('dbPickerQty').value = (type === 'hotel' && activePkgNights > 0) ? activePkgNights : (type === 'guide' && activePkgDays > 0) ? activePkgDays : 1;
        };
        document.getElementById('dbPickerPanel').style.display = '';
        document.getElementById('dbPickerPreview').textContent = '';
        document.getElementById('dbPickerQty').value = 1;
    }

    function addFromDb() {
        var sel = document.getElementById('dbPickerSelect');
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) {
            alert('Pilih item terlebih dahulu.');
            return;
        }
        var items = DB[currentDbType] || [];
        var item = items[parseInt(opt.value)];
        if (!item) return;
        var qty = parseFloat(document.getElementById('dbPickerQty').value) || 1;
        var unit = document.getElementById('dbPickerUnit').value || opt.dataset.unit || 'unit';
        var sell = parseFloat(opt.dataset.sell) || 0;
        var catMap = {
            ticket: 'transport',
            hotel: 'accommodation',
            catering: 'meal',
            guide: 'guide',
            facility: 'equipment'
        };
        var desc = '';
        if (currentDbType === 'ticket') desc = item.ticket_name + ' (' + item.ticket_type + ')';
        else if (currentDbType === 'hotel') desc = item.partner_name + ' — ' + item.room_type;
        else if (currentDbType === 'catering') desc = item.vendor_name + ' — ' + item.menu_name;
        else if (currentDbType === 'guide') desc = 'Guide ' + item.guide_type + ': ' + item.name;
        else if (currentDbType === 'facility') desc = item.name;
        addManualRow(catMap[currentDbType] || 'other', desc, qty, unit, sell, false);
        sel.value = '';
        document.getElementById('dbPickerPreview').textContent = '';
        document.getElementById('dbPickerQty').value = 1;
    }

    function closeDbPicker() {
        document.getElementById('dbPickerPanel').style.display = 'none';
        currentDbType = '';
    }

    var categoryLabels = {
        accommodation: 'Penginapan',
        transport: 'Transport',
        meal: 'Makan',
        activity: 'Aktivitas',
        guide: 'Guide',
        equipment: 'Perlengkapan',
        other: 'Lainnya'
    };
    var categoryOptions = Object.keys(categoryLabels).map(function(k) {
        return '<option value="' + k + '">' + categoryLabels[k] + '</option>';
    }).join('');

    function addManualRow(type, desc, qty, unit, price, perPax) {
        type = type || 'other';
        desc = desc || '';
        qty = qty || 1;
        unit = unit || 'unit';
        price = price || 0;
        perPax = perPax !== undefined ? perPax : false;
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><select class="calc-select ci-type" onchange="recalc()">' + categoryOptions.replace('value="' + type + '"', 'value="' + type + '" selected') + '</select></td>' +
            '<td><input type="text" class="calc-input ci-desc" value="' + esc(desc) + '" placeholder="Keterangan..." oninput="recalc()"></td>' +
            '<td><input type="number" class="calc-input ci-qty" value="' + qty + '" min="0" step="0.5" oninput="recalc()" style="text-align:center;"></td>' +
            '<td><input type="text" class="calc-input ci-unit" value="' + unit + '" style="text-align:center;"></td>' +
            '<td><input type="text" class="calc-input ci-price" value="' + (price ? fmtNum(price) : '') + '" placeholder="0 (manual)" oninput="recalc()"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="ci-perpax"' + (perPax ? ' checked' : '') + ' onchange="recalc()" title="Per pax?" style="width:16px;height:16px;cursor:pointer;"></td>' +
            '<td style="text-align:right;font-weight:700;" class="ci-sub">Rp 0</td>' +
            '<td style="text-align:center;"><button type="button" onclick="this.closest(\'tr\').remove();recalc();" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:16px;line-height:1;">×</button></td>';
        document.getElementById('calcBody').appendChild(tr);
        recalc();
    }

    function recalc() {
        var pax = parseInt(document.getElementById('paxCount').value) || 1;
        var margin = parseFloat(document.getElementById('marginPct').value) || 0;
        var taxPct = parseFloat(document.getElementById('taxCalc').value) || 0;
        var dpAmt = unFmt(document.getElementById('dpAmount')?.value || '0');
        var totalCost = 0;
        document.getElementById('marginLabel').textContent = margin;
        document.querySelectorAll('#calcBody tr').forEach(function(row) {
            var qty = parseFloat(row.querySelector('.ci-qty')?.value) || 0;
            var price = unFmt(row.querySelector('.ci-price')?.value || '0');
            var perPax = row.querySelector('.ci-perpax')?.checked;
            var sub = qty * price * (perPax ? pax : 1);
            var cell = row.querySelector('.ci-sub');
            if (cell) cell.textContent = fmt(sub);
            totalCost += sub;
        });
        var costPerPax = pax > 0 ? totalCost / pax : 0;
        var marginAmt = costPerPax * margin / 100;
        var sellPerPax = costPerPax + marginAmt;
        var subtotal = sellPerPax * pax;
        var tax = subtotal * taxPct / 100;
        var total = subtotal + tax;
        var remain = Math.max(0, total - dpAmt);
        document.getElementById('sumCost').textContent = fmt(totalCost);
        document.getElementById('sumCostPerPax').textContent = fmt(costPerPax);
        document.getElementById('sumMargin').textContent = fmt(marginAmt * pax);
        document.getElementById('sumSellPerPax').textContent = fmt(sellPerPax);
        document.getElementById('sumSubtotal').textContent = fmt(subtotal);
        document.getElementById('sumTax').textContent = fmt(tax);
        document.getElementById('sumTotal').textContent = fmt(total);

        if (!document.getElementById('sumDpRow')) {
            var box = document.getElementById('sumTotal').closest('div').parentElement;
            var dpRow = document.createElement('div');
            dpRow.className = 'summary-row';
            dpRow.id = 'sumDpRow';
            dpRow.innerHTML = '<span style="color:#666;">DP</span><span id="sumDp">Rp 0</span>';
            var remRow = document.createElement('div');
            remRow.className = 'summary-row';
            remRow.id = 'sumRemainRow';
            remRow.innerHTML = '<span style="color:#666;font-weight:700;">Sisa Bayar</span><span id="sumRemain" style="font-weight:700;color:#ef4444;">Rp 0</span>';
            box.appendChild(dpRow);
            box.appendChild(remRow);
        }
        document.getElementById('sumDp').textContent = fmt(dpAmt);
        document.getElementById('sumRemain').textContent = fmt(remain);
    }

    function clearAll() {
        if (!confirm('Bersihkan semua?')) return;
        document.getElementById('calcBody').innerHTML = '';
        document.getElementById('tripName').value = '';
        document.getElementById('tripDuration').value = '';
        document.getElementById('quickPkg').value = '';
        var dpEl = document.getElementById('dpAmount');
        if (dpEl) dpEl.value = '';
        var dpDateEl = document.getElementById('dpDate');
        if (dpDateEl) dpDateEl.value = '<?php echo date('Y-m-d'); ?>';
        document.querySelectorAll('.pkg-card').forEach(function(c) {
            c.classList.remove('active');
        });
        closeDbPicker();
        recalc();
    }

    function sendToQuotation() {
        var custId = document.getElementById('toCustomer').value;
        if (!custId) {
            alert('Pilih customer terlebih dahulu.');
            return;
        }
        var items = [];
        document.querySelectorAll('#calcBody tr').forEach(function(row) {
            items.push({
                type: row.querySelector('.ci-type')?.value || 'other',
                desc: row.querySelector('.ci-desc')?.value || '',
                qty: parseFloat(row.querySelector('.ci-qty')?.value) || 1,
                unit: row.querySelector('.ci-unit')?.value || 'unit',
                price: unFmt(row.querySelector('.ci-price')?.value || '0'),
                perPax: row.querySelector('.ci-perpax')?.checked ? 1 : 0
            });
        });
        sessionStorage.setItem('sunsea_calc', JSON.stringify({
            items,
            pax: parseInt(document.getElementById('paxCount').value) || 1,
            margin: parseFloat(document.getElementById('marginPct').value) || 0,
            tripName: document.getElementById('tripName').value
        }));
        document.getElementById('fCustomerId').value = custId;
        document.getElementById('toQuotationForm').submit();
    }

    function printInvoiceFromCalculator() {
        var custId = document.getElementById('toCustomer').value;
        if (!custId) {
            alert('Pilih customer terlebih dahulu sebelum cetak invoice.');
            return;
        }

        var items = [];
        document.querySelectorAll('#calcBody tr').forEach(function(row) {
            var desc = row.querySelector('.ci-desc')?.value || '';
            var qty = parseFloat(row.querySelector('.ci-qty')?.value) || 0;
            var price = unFmt(row.querySelector('.ci-price')?.value || '0');
            if (!desc.trim() || qty <= 0) return;
            items.push({
                type: row.querySelector('.ci-type')?.value || 'other',
                desc: desc,
                qty: qty,
                unit: row.querySelector('.ci-unit')?.value || 'unit',
                price: price,
                perPax: row.querySelector('.ci-perpax')?.checked ? 1 : 0
            });
        });

        if (!items.length) {
            alert('Belum ada komponen biaya yang valid untuk dibuat invoice.');
            return;
        }

        document.getElementById('piCustomerId').value = custId;
        document.getElementById('piTripName').value = document.getElementById('tripName').value || 'Trip Custom';
        document.getElementById('piPaxCount').value = parseInt(document.getElementById('paxCount').value) || 1;
        document.getElementById('piTaxPct').value = parseFloat(document.getElementById('taxCalc').value) || 0;
        document.getElementById('piDpAmount').value = unFmt(document.getElementById('dpAmount')?.value || '0');
        document.getElementById('piDpDate').value = document.getElementById('dpDate')?.value || '';
        document.getElementById('piPayload').value = JSON.stringify(items);
        document.getElementById('printInvoiceForm').submit();
    }

    function saveTempBooking() {
        var draft = {
            tripName: document.getElementById('tripName')?.value || '',
            tripDuration: document.getElementById('tripDuration')?.value || '',
            pax: document.getElementById('paxCount')?.value || '1',
            margin: document.getElementById('marginPct')?.value || '15',
            tax: document.getElementById('taxCalc')?.value || '11',
            customerId: document.getElementById('toCustomer')?.value || '',
            dpAmount: document.getElementById('dpAmount')?.value || '',
            dpDate: document.getElementById('dpDate')?.value || '',
            items: []
        };
        document.querySelectorAll('#calcBody tr').forEach(function(row) {
            draft.items.push({
                type: row.querySelector('.ci-type')?.value || 'other',
                desc: row.querySelector('.ci-desc')?.value || '',
                qty: row.querySelector('.ci-qty')?.value || '1',
                unit: row.querySelector('.ci-unit')?.value || 'unit',
                price: row.querySelector('.ci-price')?.value || '',
                perPax: row.querySelector('.ci-perpax')?.checked ? 1 : 0
            });
        });
        localStorage.setItem('sunsea_booking_temp', JSON.stringify(draft));
        alert('Draft booking sementara berhasil disimpan.');
    }

    function loadTempBooking() {
        var raw = localStorage.getItem('sunsea_booking_temp');
        if (!raw) return;
        try {
            var d = JSON.parse(raw);
            if (!d || !Array.isArray(d.items) || d.items.length === 0) return;
            document.getElementById('tripName').value = d.tripName || '';
            document.getElementById('tripDuration').value = d.tripDuration || '';
            document.getElementById('paxCount').value = d.pax || '1';
            document.getElementById('marginPct').value = d.margin || '15';
            document.getElementById('taxCalc').value = d.tax || '11';
            document.getElementById('toCustomer').value = d.customerId || '';
            if (document.getElementById('dpAmount')) document.getElementById('dpAmount').value = d.dpAmount || '';
            if (document.getElementById('dpDate')) document.getElementById('dpDate').value = d.dpDate || '<?php echo date('Y-m-d'); ?>';
            document.getElementById('calcBody').innerHTML = '';
            d.items.forEach(function(it) {
                addManualRow(it.type, it.desc, parseFloat(it.qty) || 1, it.unit || 'unit', unFmt(it.price || '0'), !!it.perPax);
            });
            recalc();
        } catch (e) {}
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function fmtNum(n) {
        return n ? Math.round(n).toLocaleString('id-ID') : '';
    }

    function unFmt(s) {
        return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
    }

    function fmt(n) {
        return 'Rp ' + Math.round(n).toLocaleString('id-ID');
    }

    function fmt0(n) {
        return Math.round(n).toLocaleString('id-ID');
    }

    addManualRow('accommodation');
    addManualRow('transport');
    addManualRow('meal');
    loadTempBooking();
    recalc();
</script>

<?php include 'layout-footer.php'; ?>