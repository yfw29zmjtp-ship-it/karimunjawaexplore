<?php

/**
 * PWF Management - User Menu Permissions
 * Atur akses menu sidebar untuk tiap user PWF
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../db-helper.php';

$isOwner = isset($_SESSION['role']) && in_array($_SESSION['role'], ['owner', 'developer']);
if (!$isOwner) {
    http_response_code(403);
    echo 'Access Denied';
    exit;
}

// ─── Menu PWF (sesuai sidebar layout.php) ───────────────────────────────────
// Prefix pwf_ supaya tidak konflik dengan menu_items sistem lain
$PWF_MENUS = [
    ['code' => 'pwf_dashboard',  'label' => 'Dashboard',         'icon' => 'bi-speedometer2'],
    ['code' => 'pwf_orders',     'label' => 'Orders',            'icon' => 'bi-clipboard2-check'],
    ['code' => 'pwf_progress',   'label' => 'Progress Tracking', 'icon' => 'bi-bar-chart-line'],
    ['code' => 'pwf_warehouse',  'label' => 'Warehouse / Stock', 'icon' => 'bi-building'],
    ['code' => 'pwf_shipping',   'label' => 'Shipping & Export', 'icon' => 'bi-box-seam'],
    ['code' => 'pwf_rekap',      'label' => 'Rekap Order',       'icon' => 'bi-box2-heart'],
    ['code' => 'pwf_customers',  'label' => 'Customers',         'icon' => 'bi-people'],
    ['code' => 'pwf_craftsmen',  'label' => 'Craftsmen',         'icon' => 'bi-hammer'],
    ['code' => 'pwf_containers', 'label' => 'Containers',        'icon' => 'bi-archive'],
    ['code' => 'pwf_settings',   'label' => 'Settings',          'icon' => 'bi-gear'],
];

$msg = '';
$error = null;
$pwfUsers = [];
$menus = [];
$userPermissions = [];
$selectedUserId = (int)($_GET['user'] ?? 0);

try {
    $masterPdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // 1) Cari PWF business
    $pwfBiz = $masterPdo->query(
        "SELECT id FROM businesses WHERE business_name LIKE '%PWF%' OR business_name LIKE '%Prapen%' LIMIT 1"
    )->fetch();
    $pwfBizId = $pwfBiz['id'] ?? null;
    if (!$pwfBizId) throw new RuntimeException('PWF business tidak ditemukan!');

    // 2) Auto-insert PWF menus ke menu_items jika belum ada
    $insertMenu = $masterPdo->prepare(
        "INSERT IGNORE INTO menu_items (menu_code, menu_name, menu_order, is_active) VALUES (?, ?, ?, 1)"
    );
    foreach ($PWF_MENUS as $i => $m) {
        $insertMenu->execute([$m['code'], $m['label'], ($i + 1) * 10]);
    }

    // 3) Ambil ID menu dari DB
    $codes = array_column($PWF_MENUS, 'code');
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $rows = $masterPdo->prepare("SELECT id, menu_code FROM menu_items WHERE menu_code IN ($ph)");
    $rows->execute($codes);
    $codeToId = [];
    foreach ($rows->fetchAll() as $r) $codeToId[$r['menu_code']] = (int)$r['id'];

    foreach ($PWF_MENUS as &$m) $m['id'] = $codeToId[$m['code']] ?? null;
    unset($m);
    $menus = array_values(array_filter($PWF_MENUS, fn($m) => $m['id'] !== null));

    // 4) Ambil user PWF
    $stmt = $masterPdo->prepare(
        "SELECT u.id, u.username, u.full_name FROM users u
         INNER JOIN user_business_assignment uba ON u.id = uba.user_id
         WHERE uba.business_id = ? ORDER BY u.full_name"
    );
    $stmt->execute([$pwfBizId]);
    $pwfUsers = $stmt->fetchAll();

    // 5) Load existing permissions
    if ($selectedUserId > 0) {
        $stmt = $masterPdo->prepare(
            "SELECT menu_id, can_view, can_create, can_edit, can_delete
             FROM user_menu_permissions WHERE user_id = ? AND business_id = ?"
        );
        $stmt->execute([$selectedUserId, $pwfBizId]);
        foreach ($stmt->fetchAll() as $p) $userPermissions[$p['menu_id']] = $p;
    }

    // 6) SAVE
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
        $saveId = (int)$_POST['user_id'];
        if ($saveId <= 0) throw new RuntimeException('User tidak valid!');

        $masterPdo->prepare(
            "DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?"
        )->execute([$saveId, $pwfBizId]);

        $ins = $masterPdo->prepare(
            "INSERT INTO user_menu_permissions
             (user_id, business_id, menu_id, can_view, can_create, can_edit, can_delete)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $count = 0;
        foreach ($menus as $menu) {
            $v = isset($_POST["m_{$menu['id']}_view"])   ? 1 : 0;
            $c = isset($_POST["m_{$menu['id']}_create"]) ? 1 : 0;
            $e = isset($_POST["m_{$menu['id']}_edit"])   ? 1 : 0;
            $d = isset($_POST["m_{$menu['id']}_delete"]) ? 1 : 0;
            if ($v || $c || $e || $d) {
                $ins->execute([$saveId, $pwfBizId, $menu['id'], $v, $c, $e, $d]);
                $count++;
            }
        }
        $msg = "✅ Permission berhasil disimpan ($count menu diizinkan)";
        $selectedUserId = $saveId;

        // Reload
        $userPermissions = [];
        $stmt = $masterPdo->prepare(
            "SELECT menu_id, can_view, can_create, can_edit, can_delete
             FROM user_menu_permissions WHERE user_id = ? AND business_id = ?"
        );
        $stmt->execute([$saveId, $pwfBizId]);
        foreach ($stmt->fetchAll() as $p) $userPermissions[$p['menu_id']] = $p;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Akses — PWF Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Inter, system-ui, sans-serif;
        }

        body {
            background: #F5F3F0;
            color: #1c1511;
        }

        .top-nav {
            background: #fff;
            border-bottom: 1px solid #E7E5E4;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 99;
            box-shadow: 0 1px 6px rgba(0, 0, 0, .06);
        }

        .nav-brand {
            font-weight: 700;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-brand i {
            color: #B8860B;
        }

        .nav-acts {
            display: flex;
            gap: 8px;
        }

        .btn-nav {
            border: 1px solid #ddd;
            background: #fff;
            color: #555;
            padding: 7px 16px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background .15s;
        }

        .btn-nav:hover {
            background: #F5F3F0;
            color: #1c1511;
        }

        .wrap {
            max-width: 900px;
            margin: 36px auto;
            padding: 0 20px 60px;
        }

        h1 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .subtitle {
            color: #999;
            font-size: 13px;
            margin-bottom: 28px;
        }

        .alert-ok {
            background: #F0FDF4;
            color: #166534;
            border: 1px solid #BBF7D0;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .alert-err {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .user-box {
            background: #fff;
            border: 1px solid #E7E5E4;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }

        .user-box label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #999;
            margin-bottom: 8px;
        }

        .user-box select {
            width: 100%;
            max-width: 420px;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
        }

        .user-box select:focus {
            outline: none;
            border-color: #B8860B;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, .12);
        }

        .perm-card {
            background: #fff;
            border: 1px solid #E7E5E4;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .04);
        }

        .perm-table {
            width: 100%;
            border-collapse: collapse;
        }

        .perm-table thead th {
            background: #FAFAF9;
            border-bottom: 1px solid #E7E5E4;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #888;
            text-align: center;
        }

        .perm-table thead th:first-child {
            text-align: left;
            padding-left: 20px;
        }

        .perm-table tbody tr {
            border-bottom: 1px solid #F0EEE8;
            transition: background .12s;
        }

        .perm-table tbody tr:last-child {
            border-bottom: none;
        }

        .perm-table tbody tr:hover {
            background: #FFFBF5;
        }

        .perm-table td {
            padding: 13px 16px;
            font-size: 13px;
            text-align: center;
            vertical-align: middle;
        }

        .perm-table td:first-child {
            text-align: left;
            padding-left: 20px;
        }

        .menu-label {
            font-weight: 600;
            color: #1c1511;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .menu-label i {
            color: #B8860B;
            font-size: 15px;
        }

        .perm-cb {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #B8860B;
        }

        .sel-all-row th {
            background: #FEF9EC !important;
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }

        .form-footer {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 24px;
            border-top: 1px solid #F0EEE8;
            background: #FAFAF9;
        }

        .btn-save {
            padding: 11px 28px;
            background: linear-gradient(135deg, #B8860B, #D4A017);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 2px 8px rgba(184, 134, 11, .25);
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(184, 134, 11, .35);
        }

        .btn-clear {
            padding: 11px 20px;
            background: #fff;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-clear:hover {
            background: #F5F3F0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #bbb;
        }

        .empty-state i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
        }
    </style>
</head>

<body>

    <div class="top-nav">
        <div class="nav-brand"><i class="bi bi-shield-lock-fill"></i> PWF Management — Manajemen Akses</div>
        <div class="nav-acts">
            <a href="index.php" class="btn-nav"><i class="bi bi-arrow-left"></i> Kembali</a>
            <a href="../dashboard.php" class="btn-nav"><i class="bi bi-house"></i> Dashboard</a>
        </div>
    </div>

    <div class="wrap">
        <h1>🔐 Manajemen Akses Menu</h1>
        <p class="subtitle">Atur menu sidebar yang boleh diakses oleh setiap user PWF</p>

        <?php if ($error): ?>
            <div class="alert-err"><i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($msg && !$error): ?>
            <div class="alert-ok"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if (!$error): ?>
            <div class="user-box">
                <label>👤 Pilih User</label>
                <select onchange="location.href='?user='+this.value">
                    <option value="">— Pilih user untuk set akses —</option>
                    <?php foreach ($pwfUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $selectedUserId === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?> (<?= htmlspecialchars($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selectedUserId > 0): ?>
                <form method="post">
                    <input type="hidden" name="user_id" value="<?= $selectedUserId ?>">
                    <div class="perm-card">
                        <table class="perm-table">
                            <thead>
                                <tr>
                                    <th style="width:42%">Menu</th>
                                    <th>👁️ Lihat</th>
                                    <th>➕ Buat</th>
                                    <th>✏️ Edit</th>
                                    <th>🗑️ Hapus</th>
                                </tr>
                                <tr class="sel-all-row">
                                    <th style="text-align:left;padding-left:20px;font-size:12px;color:#B8860B;">
                                        <label style="cursor:pointer;font-weight:600;">
                                            <input type="checkbox" id="selAll" class="perm-cb" onchange="selectAll(this)">
                                            &nbsp;Centang Semua
                                        </label>
                                    </th>
                                    <th></th>
                                    <th></th>
                                    <th></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($menus as $menu):
                                    $p  = $userPermissions[$menu['id']] ?? [];
                                    $cv = ($p['can_view']   ?? 0) ? 'checked' : '';
                                    $cc = ($p['can_create'] ?? 0) ? 'checked' : '';
                                    $ce = ($p['can_edit']   ?? 0) ? 'checked' : '';
                                    $cd = ($p['can_delete'] ?? 0) ? 'checked' : '';
                                ?>
                                    <tr>
                                        <td>
                                            <span class="menu-label">
                                                <i class="bi <?= htmlspecialchars($menu['icon']) ?>"></i>
                                                <?= htmlspecialchars($menu['label']) ?>
                                            </span>
                                        </td>
                                        <td><input class="perm-cb" type="checkbox" name="m_<?= $menu['id'] ?>_view" value="1" <?= $cv ?>></td>
                                        <td><input class="perm-cb" type="checkbox" name="m_<?= $menu['id'] ?>_create" value="1" <?= $cc ?>></td>
                                        <td><input class="perm-cb" type="checkbox" name="m_<?= $menu['id'] ?>_edit" value="1" <?= $ce ?>></td>
                                        <td><input class="perm-cb" type="checkbox" name="m_<?= $menu['id'] ?>_delete" value="1" <?= $cd ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="form-footer">
                            <button type="submit" class="btn-save">💾 Simpan Akses</button>
                            <button type="button" class="btn-clear" onclick="clearAll()">✕ Hapus Semua</button>
                        </div>
                    </div>
                </form>

            <?php else: ?>
                <div class="perm-card">
                    <div class="empty-state">
                        <i class="bi bi-person-circle"></i>
                        Pilih user di atas untuk mengatur akses menu mereka
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function selectAll(master) {
            document.querySelectorAll('.perm-cb:not(#selAll)').forEach(cb => cb.checked = master.checked);
        }

        function clearAll() {
            document.querySelectorAll('.perm-cb').forEach(cb => cb.checked = false);
        }
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('perm-cb') && e.target.id !== 'selAll') {
                const all = document.querySelectorAll('.perm-cb:not(#selAll)');
                document.getElementById('selAll').checked = [...all].every(cb => cb.checked);
            }
        });
    </script>
</body>

</html>