<?php

/**
 * Developer Panel - Staff Accounts Management
 * View, manage, delete staff portal accounts across all businesses
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = 'Staff Portal Accounts';

$error = '';
$success = '';

if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get all businesses with payroll module
$businesses = [];
try {
    $businesses = $pdo->query("SELECT id, business_id, name, database_name FROM businesses WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Also load from config files as fallback
$configDir = dirname(dirname(__FILE__)) . '/config/businesses/';
$configBusinesses = [];
if (is_dir($configDir)) {
    foreach (glob($configDir . '*.php') as $file) {
        $slug = basename($file, '.php');
        $cfg = require $file;
        if (isset($cfg['database']) && in_array('payroll', $cfg['enabled_modules'] ?? [])) {
            $configBusinesses[$slug] = $cfg;
        }
    }
}

// Selected business
$selectedBiz = $_GET['business'] ?? $_POST['business'] ?? '';
$bizDb = null;
$bizName = '';
$bizSlug = '';
$staffAccounts = [];
$employees = [];

if ($selectedBiz) {
    // Try config file first
    if (isset($configBusinesses[$selectedBiz])) {
        $cfg = $configBusinesses[$selectedBiz];
        $bizName = $cfg['name'] ?? $selectedBiz;
        $bizSlug = $selectedBiz;
        try {
            require_once dirname(dirname(__FILE__)) . '/config/database.php';
            $bizDb = Database::switchDatabase($cfg['database']);
        } catch (Exception $e) {
            $error = 'Gagal konek ke database: ' . $e->getMessage();
        }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bizDb) {
    $formAction = $_POST['form_action'] ?? '';
    $bizPdo = $bizDb->getConnection();

    // Ensure table exists
    $bizPdo->exec("SET time_zone = '+07:00'");
    $bizPdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `plain_password` VARCHAR(255) DEFAULT NULL,
        `last_login` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp (employee_id),
        UNIQUE KEY uk_emp (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $bizPdo->exec("ALTER TABLE staff_accounts ADD COLUMN plain_password VARCHAR(255) DEFAULT NULL AFTER password_hash");
    } catch (Exception $e) {
    }

    if ($formAction === 'delete' && !empty($_POST['account_id'])) {
        $accId = (int)$_POST['account_id'];
        $bizPdo->prepare("DELETE FROM staff_accounts WHERE id = ?")->execute([$accId]);
        $auth->logAction('delete_staff_account', 'staff_accounts', $accId, null, ['business' => $bizSlug]);
        $_SESSION['success_message'] = 'Akun staff berhasil dihapus.';
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }

    if ($formAction === 'reset_password' && !empty($_POST['account_id'])) {
        $accId = (int)$_POST['account_id'];
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $bizPdo->prepare("UPDATE staff_accounts SET password_hash = ?, plain_password = ? WHERE id = ?")->execute([$hash, $newPass, $accId]);
            $auth->logAction('reset_staff_password', 'staff_accounts', $accId, null, ['business' => $bizSlug]);
            $_SESSION['success_message'] = 'Password berhasil direset.';
        } else {
            $_SESSION['success_message'] = 'Password minimal 6 karakter.';
        }
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }

    if ($formAction === 'create_account') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($empId && $username && strlen($password) >= 6) {
            $exists = $bizDb->fetchOne("SELECT id FROM staff_accounts WHERE employee_id = ?", [$empId]);
            if ($exists) {
                $error = 'Karyawan sudah punya akun.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $bizPdo->prepare("INSERT INTO staff_accounts (employee_id, email, password_hash, plain_password) VALUES (?, ?, ?, ?)")
                    ->execute([$empId, $username, $hash, $password]);
                $auth->logAction('create_staff_account', 'staff_accounts', $bizPdo->lastInsertId(), null, ['business' => $bizSlug, 'employee_id' => $empId]);
                $_SESSION['success_message'] = 'Akun staff berhasil dibuat.';
                header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
                exit;
            }
        } else {
            $error = 'Lengkapi semua field. Password minimal 6 karakter.';
        }
    }

    if ($formAction === 'delete_all') {
        $bizPdo->exec("TRUNCATE TABLE staff_accounts");
        $auth->logAction('delete_all_staff_accounts', 'staff_accounts', null, null, ['business' => $bizSlug]);
        $_SESSION['success_message'] = 'Semua akun staff berhasil dihapus.';
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz));
        exit;
    }

    // Export accounts — regenerate passwords so they can be shared
    if ($formAction === 'export_accounts') {
        $exportMode = $_POST['export_mode'] ?? 'reset'; // 'reset' = generate new passwords
        $exportData = [];

        // Get all staff accounts with employee info
        $rows = $bizPdo->query("
            SELECT sa.id, sa.email, pe.full_name, pe.employee_code, pe.position, pe.department
            FROM staff_accounts sa
            LEFT JOIN payroll_employees pe ON pe.id = sa.employee_id
            ORDER BY pe.full_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Generate a simple readable password
            $newPass = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', $row['full_name'] ?? 'staff'), 0, 4))
                . rand(100, 999);
            // Update the password in DB
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $bizPdo->prepare("UPDATE staff_accounts SET password_hash = ?, plain_password = ? WHERE id = ?")->execute([$hash, $newPass, $row['id']]);

            $exportData[] = [
                'name'     => $row['full_name'] ?? 'Unknown',
                'code'     => $row['employee_code'] ?? '',
                'position' => $row['position'] ?? '',
                'username' => $row['email'],
                'password' => $newPass,
            ];
        }

        $auth->logAction('export_staff_accounts', 'staff_accounts', null, null, [
            'business' => $bizSlug,
            'count' => count($exportData)
        ]);

        // Store in session for display
        $_SESSION['export_data'] = $exportData;
        $_SESSION['export_biz'] = $bizName;
        $_SESSION['export_url'] = $portalUrl ?? '';
        header('Location: staff-accounts.php?business=' . urlencode($selectedBiz) . '&show_export=1');
        exit;
    }
}

// Fetch data if business selected
if ($bizDb) {
    $bizPdo = $bizDb->getConnection();
    try {
        // Ensure table
        $bizPdo->exec("SET time_zone = '+07:00'");
        $bizPdo->exec("CREATE TABLE IF NOT EXISTS `staff_accounts` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `plain_password` VARCHAR(255) DEFAULT NULL,
            `last_login` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_emp (employee_id),
            UNIQUE KEY uk_emp (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $bizPdo->exec("ALTER TABLE staff_accounts ADD COLUMN plain_password VARCHAR(255) DEFAULT NULL AFTER password_hash");
        } catch (Exception $e) {
        }

        $staffAccounts = $bizPdo->query("
            SELECT sa.*, pe.full_name, pe.employee_code, pe.position, pe.department 
            FROM staff_accounts sa 
            LEFT JOIN payroll_employees pe ON pe.id = sa.employee_id 
            ORDER BY sa.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $staffAccounts = [];
    }

    try {
        $employees = $bizPdo->query("
            SELECT pe.id, pe.employee_code, pe.full_name, pe.position, pe.department 
            FROM payroll_employees pe 
            WHERE pe.is_active = 1 
            AND pe.id NOT IN (SELECT employee_id FROM staff_accounts)
            ORDER BY pe.full_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $employees = [];
    }
}

// Staff portal URL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$portalUrl = $baseUrl . dirname(dirname($_SERVER['SCRIPT_NAME'])) . '/modules/payroll/staff-portal.php?b=' . urlencode($bizSlug);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Business Selector -->
    <div class="content-card mb-4">
        <div class="card-header-custom d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Staff Portal Accounts</h5>
                <small class="text-muted">Kelola akun staff portal per bisnis</small>
            </div>
        </div>
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-bold">Pilih Bisnis</label>
                    <select name="business" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Pilih Bisnis --</option>
                        <?php foreach ($configBusinesses as $slug => $cfg): ?>
                            <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $selectedBiz === $slug ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cfg['name'] ?? $slug); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selectedBiz && $bizDb): ?>
                    <div class="col-md-7">
                        <label class="form-label fw-bold">🔗 Link Staff Portal</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars($portalUrl); ?>" readonly id="portalLink">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('portalLink').value).then(()=>{this.textContent='✅ Copied!';setTimeout(()=>this.textContent='📋 Copy',1500)})">📋 Copy</button>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($selectedBiz && $bizDb): ?>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="content-card text-center p-3">
                    <div class="fs-2 fw-bold text-primary"><?php echo count($staffAccounts); ?></div>
                    <div class="text-muted small">Akun Terdaftar</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="content-card text-center p-3">
                    <div class="fs-2 fw-bold text-success"><?php echo count(array_filter($staffAccounts, fn($a) => $a['last_login'])); ?></div>
                    <div class="text-muted small">Pernah Login</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="content-card text-center p-3">
                    <div class="fs-2 fw-bold text-warning"><?php echo count($employees); ?></div>
                    <div class="text-muted small">Belum Punya Akun</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="content-card text-center p-3">
                    <div class="fs-2 fw-bold text-info"><?php echo count($staffAccounts) + count($employees); ?></div>
                    <div class="text-muted small">Total Karyawan Aktif</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Accounts Table -->
            <div class="col-lg-8">
                <div class="content-card">
                    <div class="card-header-custom d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">📋 Daftar Akun Staff (<?php echo count($staffAccounts); ?>)</h6>
                        <?php if (count($staffAccounts) > 0): ?>
                            <div class="d-flex gap-2">
                                <form method="POST" onsubmit="return confirm('Export akan RESET semua password staff dan generate password baru.\nPassword lama tidak bisa digunakan lagi.\n\nLanjutkan?')">
                                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                                    <input type="hidden" name="form_action" value="export_accounts">
                                    <input type="hidden" name="export_mode" value="reset">
                                    <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export Akun</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('HAPUS SEMUA akun staff? Tindakan ini tidak bisa dibatalkan!')">
                                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                                    <input type="hidden" name="form_action" value="delete_all">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Hapus Semua</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Karyawan</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>Last Login</th>
                                    <th>Dibuat</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($staffAccounts)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">Belum ada akun staff terdaftar</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($staffAccounts as $i => $acc): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($acc['full_name'] ?? 'Unknown'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($acc['employee_code'] ?? ''); ?> · <?php echo htmlspecialchars($acc['position'] ?? ''); ?></small>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($acc['email']); ?></code></td>
                                            <td>
                                                <?php if (!empty($acc['plain_password'])): ?>
                                                    <code class="text-danger fw-bold"><?php echo htmlspecialchars($acc['plain_password']); ?></code>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark" style="cursor:pointer" onclick="resetPassword(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['full_name'] ?? '')); ?>')" title="Klik untuk set password">⚠️ Reset</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($acc['last_login']): ?>
                                                    <span class="badge bg-success"><?php echo date('d M H:i', strtotime($acc['last_login'])); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Belum pernah</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small><?php echo date('d M Y', strtotime($acc['created_at'])); ?></small></td>
                                            <td class="text-center">
                                                <button class="btn btn-sm btn-outline-warning" onclick="resetPassword(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['full_name'] ?? '')); ?>')" title="Reset Password">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus akun <?php echo htmlspecialchars(addslashes($acc['full_name'] ?? '')); ?>?')">
                                                    <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                                                    <input type="hidden" name="form_action" value="delete">
                                                    <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Create Account Card -->
            <div class="col-lg-4">
                <div class="content-card">
                    <div class="card-header-custom">
                        <h6 class="mb-0">➕ Buat Akun Baru</h6>
                    </div>
                    <div class="card-body p-4">
                        <?php if (empty($employees)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-check-circle fs-1 text-success"></i>
                                <p class="mt-2 mb-0">Semua karyawan sudah punya akun!</p>
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                                <input type="hidden" name="form_action" value="create_account">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Karyawan</label>
                                    <select name="employee_id" class="form-select" required>
                                        <option value="">-- Pilih --</option>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>">
                                                <?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Username</label>
                                    <input type="text" name="username" class="form-control" placeholder="nama / email" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Password</label>
                                    <input type="text" name="password" class="form-control" placeholder="Min 6 karakter" required minlength="6">
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-person-plus me-1"></i>Buat Akun
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="business" value="<?php echo htmlspecialchars($selectedBiz); ?>">
                <input type="hidden" name="form_action" value="reset_password">
                <input type="hidden" name="account_id" id="resetAccountId">
                <div class="modal-header">
                    <h6 class="modal-title">🔑 Reset Password</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Reset password untuk: <strong id="resetName"></strong></p>
                    <input type="text" name="new_password" class="form-control" placeholder="Password baru (min 6)" required minlength="6">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning btn-sm">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Show export result overlay
$showExport = isset($_GET['show_export']) && !empty($_SESSION['export_data']);
$exportData = $_SESSION['export_data'] ?? [];
$exportBiz = $_SESSION['export_biz'] ?? '';
$exportUrl = $_SESSION['export_url'] ?? '';
if ($showExport) {
    unset($_SESSION['export_data'], $_SESSION['export_biz'], $_SESSION['export_url']);
}
?>

<?php if ($showExport && !empty($exportData)): ?>
    <!-- Export Result Modal (auto-show) -->
    <div class="modal fade" id="exportModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Akun Staff — <?php echo htmlspecialchars($exportBiz); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="p-3 bg-warning bg-opacity-10 border-bottom">
                        <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>
                            Semua password sudah direset. Bagikan data ini ke masing-masing staff.</small>
                    </div>

                    <!-- Printable content -->
                    <div id="exportContent" class="p-4">
                        <div style="text-align:center;margin-bottom:20px;">
                            <h4 style="margin:0;font-weight:800;"><?php echo htmlspecialchars($exportBiz); ?></h4>
                            <p style="margin:4px 0 0;color:#666;font-size:13px;">Daftar Akun Staff Portal</p>
                            <p style="margin:2px 0 0;color:#999;font-size:11px;">Generated: <?php echo date('d M Y H:i'); ?></p>
                        </div>

                        <?php if ($exportUrl): ?>
                            <div style="background:#f0f7ff;border:1px solid #c5ddf5;border-radius:8px;padding:10px 14px;margin-bottom:16px;text-align:center;">
                                <small style="color:#666;">Link Staff Portal:</small><br>
                                <strong style="font-size:12px;word-break:break-all;"><?php echo htmlspecialchars($exportUrl); ?></strong>
                            </div>
                        <?php endif; ?>

                        <table style="width:100%;border-collapse:collapse;font-size:13px;" id="exportTable">
                            <thead>
                                <tr style="background:#0d1f3c;color:#fff;">
                                    <th style="padding:8px 10px;text-align:left;">No</th>
                                    <th style="padding:8px 10px;text-align:left;">Nama</th>
                                    <th style="padding:8px 10px;text-align:left;">Jabatan</th>
                                    <th style="padding:8px 10px;text-align:left;">Username</th>
                                    <th style="padding:8px 10px;text-align:left;">Password</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exportData as $i => $d): ?>
                                    <tr style="border-bottom:1px solid #eee;<?php echo $i % 2 ? 'background:#f9f9f9;' : ''; ?>">
                                        <td style="padding:8px 10px;"><?php echo $i + 1; ?></td>
                                        <td style="padding:8px 10px;font-weight:600;">
                                            <?php echo htmlspecialchars($d['name']); ?>
                                            <?php if ($d['code']): ?><br><small style="color:#999;"><?php echo htmlspecialchars($d['code']); ?></small><?php endif; ?>
                                        </td>
                                        <td style="padding:8px 10px;color:#666;"><?php echo htmlspecialchars($d['position']); ?></td>
                                        <td style="padding:8px 10px;"><code style="background:#f0f0f0;padding:2px 6px;border-radius:4px;"><?php echo htmlspecialchars($d['username']); ?></code></td>
                                        <td style="padding:8px 10px;"><code style="background:#fef3c7;padding:2px 6px;border-radius:4px;font-weight:700;"><?php echo htmlspecialchars($d['password']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="margin-top:16px;text-align:center;color:#999;font-size:10px;">
                            ⚠️ Jaga kerahasiaan data ini. Setiap staff hanya perlu tahu akun miliknya sendiri.
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyExportText()">
                            <i class="bi bi-clipboard me-1"></i>Copy Text
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="copyExportWA()">
                            <i class="bi bi-whatsapp me-1"></i>Copy untuk WA
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="printExport()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    function resetPassword(id, name) {
        document.getElementById('resetAccountId').value = id;
        document.getElementById('resetName').textContent = name;
        new bootstrap.Modal(document.getElementById('resetModal')).show();
    }

    <?php if ($showExport && !empty($exportData)): ?>
        // Auto-show export modal
        document.addEventListener('DOMContentLoaded', () => {
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        });

        const exportAccounts = <?php echo json_encode($exportData, JSON_UNESCAPED_UNICODE); ?>;
        const exportBiz = <?php echo json_encode($exportBiz); ?>;
        const exportUrl = <?php echo json_encode($exportUrl); ?>;

        function copyExportText() {
            let txt = `📋 DAFTAR AKUN STAFF PORTAL\n${exportBiz}\n${'═'.repeat(35)}\n\n`;
            if (exportUrl) txt += `🔗 Link: ${exportUrl}\n\n`;
            exportAccounts.forEach((a, i) => {
                txt += `${i+1}. ${a.name}${a.position ? ' ('+a.position+')' : ''}\n`;
                txt += `   Username: ${a.username}\n`;
                txt += `   Password: ${a.password}\n\n`;
            });
            txt += `⚠️ Jaga kerahasiaan password masing-masing.`;
            navigator.clipboard.writeText(txt).then(() => {
                showCopyFeedback('Text berhasil di-copy!');
            });
        }

        function copyExportWA() {
            let txt = `📋 *DAFTAR AKUN STAFF PORTAL*\n*${exportBiz}*\n\n`;
            if (exportUrl) txt += `🔗 Link:\n${exportUrl}\n\n`;
            txt += `${'─'.repeat(25)}\n`;
            exportAccounts.forEach((a, i) => {
                txt += `\n*${i+1}. ${a.name}*${a.position ? '\n    _'+a.position+'_' : ''}\n`;
                txt += `    👤 \`${a.username}\`\n`;
                txt += `    🔑 \`${a.password}\`\n`;
            });
            txt += `\n${'─'.repeat(25)}\n⚠️ _Jaga kerahasiaan password._`;
            navigator.clipboard.writeText(txt).then(() => {
                showCopyFeedback('Format WA berhasil di-copy!');
            });
        }

        function printExport() {
            const content = document.getElementById('exportContent').innerHTML;
            const win = window.open('', '_blank');
            win.document.write(`<!DOCTYPE html><html><head><title>Export Akun Staff</title>
    <style>body{font-family:Arial,sans-serif;padding:20px;} table{width:100%;border-collapse:collapse;}
    th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #ddd;}
    th{background:#0d1f3c;color:#fff;} code{background:#f0f0f0;padding:2px 6px;border-radius:4px;}
    @media print{body{padding:10px;}}</style></head><body>${content}</body></html>`);
            win.document.close();
            win.focus();
            win.print();
        }

        function showCopyFeedback(msg) {
            const toast = document.createElement('div');
            toast.className = 'position-fixed bottom-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `<div class="toast show bg-success text-white" role="alert">
        <div class="toast-body"><i class="bi bi-check-circle me-2"></i>${msg}</div></div>`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2500);
        }
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>