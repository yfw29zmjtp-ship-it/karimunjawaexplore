                    <?php if ($fpEnabled && $fpCloudId && $fpCloudStatus): ?>
                        <div style="margin-top:4px; font-size:11px;">
                            <strong>Status Cloud:</strong>
                            <?php if ($fpCloudStatus['success']): ?>
                                <span style="color:#059669; font-weight:700;">✅ <?php echo htmlspecialchars($fpCloudStatus['message'] ?? 'Aktif'); ?></span>
                            <?php else: ?>
                                <span style="color:#dc2626; font-weight:700;">⚠️ <?php echo htmlspecialchars($fpCloudStatus['message'] ?? 'Tidak aktif'); ?></span>
                            <?php endif; ?>
                        </div>
                        <details style="margin-top:6px;">
                            <summary style="font-size:10px; color:#64748b; cursor:pointer;">Debug: Lihat response API</summary>
                            <pre style="font-size:10px; background:#f3f4f6; color:#334155; border-radius:6px; padding:8px; border:1px solid #e2e8f0; max-width:420px; overflow-x:auto; margin-top:4px;"><?php echo htmlspecialchars(json_encode($fpCloudStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                        </details>
                    <?php endif; ?>
                    <?php
                    /**
                     * Payroll Attendance Dashboard - Redesigned
                     * Tabs: Dashboard Harian | Absen GPS | Fingerprint | Manual | Reset
                     */
                    define('APP_ACCESS', true);
                    require_once '../../config/config.php';
                    require_once '../../config/database.php';
                    require_once '../../includes/auth.php';
                    require_once '../../includes/functions.php';
                    require_once '../../includes/CloudinaryHelper.php';

                    $auth = new Auth();
                    $auth->requireLogin();

                    if (!isModuleEnabled('payroll')) {
                        header('Location: ' . BASE_URL . '/index.php');
                        exit;
                    }

                    $db = Database::getInstance();
                    $_pdo = $db->getConnection();
                    $currentUser = $auth->getCurrentUser();
                    $pageTitle = 'Absensi Karyawan';
                    $baseUrl = defined('BASE_URL') ? BASE_URL : '';

                    // Ensure 'uniform' column exists on schedule tables (Jadwal Seragam feature) — self-healing
                    foreach (['payroll_work_schedules', 'payroll_schedule_overrides'] as $__schedTbl) {
                        try {
                            $_pdo->query("SELECT uniform FROM `$__schedTbl` LIMIT 0");
                        } catch (PDOException $e) {
                            try {
                                $_pdo->exec("ALTER TABLE `$__schedTbl` ADD COLUMN `uniform` VARCHAR(100) DEFAULT NULL");
                            } catch (Throwable $e2) {
                            }
                        }
                    }

                    function isSplitShiftCheckInTime($timeStr)
                    {
                        $t = trim((string)$timeStr);
                        return $t !== '' && substr($t, 0, 8) >= '12:00:00';
                    }

                    function normalizeLateStatusBySplitShift($status, $checkInTime)
                    {
                        if ($status === 'late' && isSplitShiftCheckInTime($checkInTime)) {
                            return 'present';
                        }
                        return $status;
                    }

                    // ═══ AJAX: Get employee schedule ═══
                    if (isset($_GET['ajax_schedule']) && isset($_GET['emp_id'])) {
                        header('Content-Type: application/json');
                        try {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `day_of_week` TINYINT NOT NULL DEFAULT 0,
            `start_time` TIME NOT NULL DEFAULT '09:00:00', `end_time` TIME NOT NULL DEFAULT '17:00:00',
            `break_minutes` INT DEFAULT 60, `is_off` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_emp_day (employee_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $stmt = $_pdo->prepare("SELECT day_of_week, start_time, end_time, break_minutes, is_off, uniform FROM payroll_work_schedules WHERE employee_id = ?");
                            if ($stmt === false) {
                                throw new Exception('Prepare gagal: ' . implode(' ', $_pdo->errorInfo()));
                            }
                            $stmt->execute([(int)$_GET['emp_id']]);
                            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
                        } catch (Throwable $e) {
                            echo json_encode([]);
                        }
                        exit;
                    }

                    // ═══ AJAX: Get employee schedule OVERRIDES (specific-date exceptions, e.g. libur custom di luar pola mingguan) ═══
                    if (isset($_GET['ajax_overrides']) && isset($_GET['emp_id'])) {
                        header('Content-Type: application/json');
                        try {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_schedule_overrides` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `override_date` DATE NOT NULL,
            `is_off` TINYINT(1) NOT NULL DEFAULT 1, `start_time` TIME DEFAULT NULL, `end_time` TIME DEFAULT NULL,
            `break_minutes` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emp_date (employee_id, override_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $stmt = $_pdo->prepare("SELECT override_date, is_off, start_time, end_time, break_minutes, uniform FROM payroll_schedule_overrides WHERE employee_id = ?");
                            if ($stmt === false) {
                                throw new Exception('Prepare gagal: ' . implode(' ', $_pdo->errorInfo()));
                            }
                            $stmt->execute([(int)$_GET['emp_id']]);
                            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
                        } catch (Throwable $e) {
                            echo json_encode([]);
                        }
                        exit;
                    }

                    // ═══ AJAX: Get weekly patterns + overrides for ALL active employees (combined calendar view) ═══
                    if (isset($_GET['ajax_all_schedules'])) {
                        header('Content-Type: application/json');
                        try {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `day_of_week` TINYINT NOT NULL DEFAULT 0,
            `start_time` TIME NOT NULL DEFAULT '09:00:00', `end_time` TIME NOT NULL DEFAULT '17:00:00',
            `break_minutes` INT DEFAULT 60, `is_off` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_emp_day (employee_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_schedule_overrides` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `override_date` DATE NOT NULL,
            `is_off` TINYINT(1) NOT NULL DEFAULT 1, `start_time` TIME DEFAULT NULL, `end_time` TIME DEFAULT NULL,
            `break_minutes` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emp_date (employee_id, override_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $allEmps = $_pdo->query("SELECT id, employee_code, full_name FROM payroll_employees WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
                            $allWeekly = $_pdo->query("SELECT employee_id, day_of_week, start_time, end_time, break_minutes, is_off, uniform FROM payroll_work_schedules")->fetchAll(PDO::FETCH_ASSOC);
                            $allOverrides = $_pdo->query("SELECT employee_id, override_date, is_off, start_time, end_time, break_minutes, uniform FROM payroll_schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);
                            echo json_encode(['employees' => $allEmps, 'weekly' => $allWeekly, 'overrides' => $allOverrides]);
                        } catch (Throwable $e) {
                            echo json_encode(['employees' => [], 'weekly' => [], 'overrides' => []]);
                        }
                        exit;
                    }

                    // ═══ AJAX: Save a specific-date schedule override ═══
                    if (isset($_POST['ajax_save_override'])) {
                        header('Content-Type: application/json');
                        try {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_schedule_overrides` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `override_date` DATE NOT NULL,
            `is_off` TINYINT(1) NOT NULL DEFAULT 1, `start_time` TIME DEFAULT NULL, `end_time` TIME DEFAULT NULL,
            `break_minutes` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emp_date (employee_id, override_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $oEmpId = (int)($_POST['employee_id'] ?? 0);
                            $oDate = $_POST['override_date'] ?? '';
                            if ($oEmpId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $oDate)) {
                                throw new Exception('Data tidak valid.');
                            }
                            $oIsOff = (($_POST['is_off'] ?? '0') === '1') ? 1 : 0;
                            $oStart = $_POST['start_time'] ?? '09:00';
                            $oEnd = $_POST['end_time'] ?? '17:00';
                            $oBreak = (int)($_POST['break_minutes'] ?? 60);
                            $oUniform = trim($_POST['uniform'] ?? '') ?: null;
                            $ovStmt = $_pdo->prepare("INSERT INTO payroll_schedule_overrides (employee_id, override_date, is_off, start_time, end_time, break_minutes, uniform) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_off=VALUES(is_off), start_time=VALUES(start_time), end_time=VALUES(end_time), break_minutes=VALUES(break_minutes), uniform=VALUES(uniform)");
                            if ($ovStmt === false) {
                                throw new Exception('Prepare gagal: ' . implode(' ', $_pdo->errorInfo()));
                            }
                            $ovStmt->execute([$oEmpId, $oDate, $oIsOff, $oStart, $oEnd, $oBreak, $oUniform]);
                            echo json_encode(['success' => true]);
                        } catch (Throwable $e) {
                            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        }
                        exit;
                    }

                    // ═══ AJAX: Delete a specific-date schedule override (reverts to the weekly pattern) ═══
                    if (isset($_POST['ajax_delete_override'])) {
                        header('Content-Type: application/json');
                        try {
                            $oEmpId = (int)($_POST['employee_id'] ?? 0);
                            $oDate = $_POST['override_date'] ?? '';
                            if ($oEmpId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oDate)) {
                                $delStmt = $_pdo->prepare("DELETE FROM payroll_schedule_overrides WHERE employee_id = ? AND override_date = ?");
                                if ($delStmt === false) {
                                    throw new Exception('Prepare gagal: ' . implode(' ', $_pdo->errorInfo()));
                                }
                                $delStmt->execute([$oEmpId, $oDate]);
                            }
                            echo json_encode(['success' => true]);
                        } catch (Throwable $e) {
                            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        }
                        exit;
                    }

                    // ═══ AJAX: Save uniform schedule in bulk for ALL staff at once (dedicated Jadwal Seragam tab) ═══
                    if (isset($_POST['ajax_save_uniform_all'])) {
                        header('Content-Type: application/json');
                        try {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `day_of_week` TINYINT NOT NULL DEFAULT 0,
            `start_time` TIME NOT NULL DEFAULT '09:00:00', `end_time` TIME NOT NULL DEFAULT '17:00:00',
            `break_minutes` INT DEFAULT 60, `is_off` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_emp_day (employee_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                            $rows = json_decode($_POST['rows'] ?? '[]', true);
                            if (!is_array($rows)) {
                                throw new Exception('Data tidak valid.');
                            }
                            $uStmt = $_pdo->prepare("INSERT INTO payroll_work_schedules (employee_id, day_of_week, start_time, end_time, break_minutes, is_off, uniform) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE uniform=VALUES(uniform)");
                            if ($uStmt === false) {
                                throw new Exception('Prepare gagal: ' . implode(' ', $_pdo->errorInfo()));
                            }
                            $saved = 0;
                            foreach ($rows as $row) {
                                $rEmpId = (int)($row['employee_id'] ?? 0);
                                $rDay = (int)($row['day_of_week'] ?? -1);
                                if ($rEmpId <= 0 || $rDay < 0 || $rDay > 6) {
                                    continue;
                                }
                                $rVal = trim((string)($row['uniform'] ?? '')) ?: null;
                                $uStmt->execute([$rEmpId, $rDay, '09:00', '17:00', 60, $rDay === 0 ? 1 : 0, $rVal]);
                                $saved++;
                            }
                            echo json_encode(['success' => true, 'saved' => $saved]);
                        } catch (Throwable $e) {
                            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                        }
                        exit;
                    }

                    // ══════════════════════════════════════════════
                    // AUTO-CREATE TABLES & COLUMNS (idempotent)
                    // ══════════════════════════════════════════════

                    $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `office_lat` DECIMAL(10,7) NOT NULL DEFAULT -6.2000000,
    `office_lng` DECIMAL(10,7) NOT NULL DEFAULT 106.8166700,
    `allowed_radius_m` INT NOT NULL DEFAULT 200,
    `office_name` VARCHAR(100) DEFAULT 'Kantor',
    `checkin_start` TIME DEFAULT '07:00:00',
    `checkin_end` TIME DEFAULT '10:00:00',
    `checkout_start` TIME DEFAULT '16:00:00',
    `allow_outside` TINYINT(1) DEFAULT 0,
    `app_logo` VARCHAR(255) DEFAULT NULL,
    `fingerspot_cloud_id` VARCHAR(50) DEFAULT NULL,
    `fingerspot_enabled` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $_pdo->exec("INSERT IGNORE INTO `payroll_attendance_config` (`id`) VALUES (1)");

                    // Ensure columns exist
                    $autoColumns = [
                        ['payroll_attendance_config', 'fingerspot_cloud_id', "VARCHAR(50) DEFAULT NULL"],
                        ['payroll_attendance_config', 'fingerspot_enabled', "TINYINT(1) DEFAULT 0"],
                        ['payroll_attendance_config', 'app_logo', "VARCHAR(255) DEFAULT NULL"],
                        ['payroll_attendance_config', 'monthly_target_hours', "INT NOT NULL DEFAULT 208"],
                        ['payroll_attendance_config', 'ot_round_minutes', "INT NOT NULL DEFAULT 45"],
                        ['payroll_employees', 'attendance_pin', "VARCHAR(6) DEFAULT NULL"],
                        ['payroll_employees', 'finger_id', "VARCHAR(20) DEFAULT NULL"],
                        ['payroll_employees', 'monthly_target_hours', "INT DEFAULT 208"],
                        ['payroll_employees', 'face_descriptor', "TEXT DEFAULT NULL"],
                    ];
                    foreach ($autoColumns as [$tbl, $col, $def]) {
                        try {
                            $_pdo->query("SELECT `$col` FROM `$tbl` LIMIT 0");
                        } catch (PDOException $e) {
                            $_pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
                        }
                    }

                    // Attendance table
                    try {
                        $_pdo->query("SELECT 1 FROM payroll_attendance LIMIT 0");
                    } catch (PDOException $e) {
                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `employee_id` INT NOT NULL,
        `attendance_date` DATE NOT NULL,
        `check_in_time` TIME DEFAULT NULL,
        `check_in_lat` DECIMAL(10,7) DEFAULT NULL, `check_in_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_in_distance_m` INT DEFAULT NULL, `check_in_address` VARCHAR(255) DEFAULT NULL, `check_in_device` VARCHAR(200) DEFAULT NULL,
        `check_out_time` TIME DEFAULT NULL,
        `check_out_lat` DECIMAL(10,7) DEFAULT NULL, `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
        `check_out_distance_m` INT DEFAULT NULL, `check_out_device` VARCHAR(200) DEFAULT NULL,
        `scan_3` TIME DEFAULT NULL, `scan_4` TIME DEFAULT NULL,
        `work_hours` DECIMAL(5,2) DEFAULT NULL,
        `overtime_hours` DECIMAL(5,2) DEFAULT NULL,
        `shift_1_hours` DECIMAL(5,2) DEFAULT NULL, `shift_2_hours` DECIMAL(5,2) DEFAULT NULL,
        `status` ENUM('present','late','absent','leave','holiday','half_day') NOT NULL DEFAULT 'present',
        `is_outside_radius` TINYINT(1) DEFAULT 0,
        `notes` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_attendance` (`employee_id`, `attendance_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    }

                    // Split shift columns
                    $shiftCols = ['scan_3' => 'TIME DEFAULT NULL', 'scan_4' => 'TIME DEFAULT NULL', 'shift_1_hours' => 'DECIMAL(5,2) DEFAULT NULL', 'shift_2_hours' => 'DECIMAL(5,2) DEFAULT NULL', 'overtime_hours' => 'DECIMAL(5,2) DEFAULT NULL'];
                    foreach ($shiftCols as $col => $def) {
                        try {
                            $_pdo->query("SELECT `$col` FROM payroll_attendance LIMIT 0");
                        } catch (PDOException $e) {
                            $_pdo->exec("ALTER TABLE payroll_attendance ADD COLUMN `$col` $def");
                        }
                    }

                    // Locations table
                    try {
                        $_pdo->query("SELECT 1 FROM payroll_attendance_locations LIMIT 0");
                    } catch (PDOException $e) {
                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_attendance_locations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `location_name` VARCHAR(100) NOT NULL, `address` VARCHAR(255) DEFAULT NULL,
        `lat` DECIMAL(10,7) NOT NULL DEFAULT 0, `lng` DECIMAL(10,7) NOT NULL DEFAULT 0,
        `radius_m` INT NOT NULL DEFAULT 200, `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    }

                    // Fingerprint log table
                    try {
                        $_pdo->query("SELECT 1 FROM fingerprint_log LIMIT 0");
                    } catch (PDOException $e) {
                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `fingerprint_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `cloud_id` VARCHAR(50) NOT NULL, `type` VARCHAR(32) DEFAULT 'attlog',
        `pin` VARCHAR(20) DEFAULT NULL, `scan_time` DATETIME DEFAULT NULL,
        `verify_method` VARCHAR(30) DEFAULT NULL, `status_scan` VARCHAR(30) DEFAULT NULL,
        `employee_id` INT DEFAULT NULL, `processed` TINYINT(1) DEFAULT 0,
        `process_result` VARCHAR(255) DEFAULT NULL, `raw_data` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cloud (cloud_id), INDEX idx_pin (pin), INDEX idx_scan (scan_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    }

                    // Ensure fingerspot_token column exists
                    try {
                        $_pdo->query("SELECT fingerspot_token FROM payroll_attendance_config LIMIT 0");
                    } catch (PDOException $e) {
                        $_pdo->exec("ALTER TABLE payroll_attendance_config ADD COLUMN `fingerspot_token` VARCHAR(100) DEFAULT NULL AFTER fingerspot_enabled");
                    }

                    // ══════════════════════════════════════════════
                    // POST ACTIONS
                    // ══════════════════════════════════════════════
                    $msg = '';
                    $msgType = '';

                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                        $action = $_POST['action'] ?? '';

                        // ── Save GPS/Time settings ──
                        if ($action === 'save_config') {
                            $ciStart = $_POST['checkin_start'] ?? '07:00';
                            $ciEnd   = $_POST['checkin_end'] ?? '10:00';
                            $coStart = $_POST['checkout_start'] ?? '16:00';
                            $allowOut = isset($_POST['allow_outside']) ? 1 : 0;
                            $monthlyTargetHours = max(1, (int)($_POST['monthly_target_hours'] ?? 208));
                            $otRoundMinutes = max(1, (int)($_POST['ot_round_minutes'] ?? 45));
                            $db->query(
                                "UPDATE payroll_attendance_config SET checkin_start=?, checkin_end=?, checkout_start=?, allow_outside=?, monthly_target_hours=?, ot_round_minutes=?, updated_by=? WHERE id=1",
                                [$ciStart, $ciEnd, $coStart, $allowOut, $monthlyTargetHours, $otRoundMinutes, $currentUser['id']]
                            );
                            $msg = 'Pengaturan waktu berhasil disimpan.';
                            $msgType = 'success';
                        }

                        // ── Save logo ──
                        if ($action === 'save_logo') {
                            if (!empty($_FILES['logo_file']['tmp_name'])) {
                                $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                                if (in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'])) {
                                    $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower(defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'biz'));
                                    $filename = 'logo_' . $slug . '.' . $ext;
                                    $cloudinary = CloudinaryHelper::getInstance();
                                    $uploadResult = $cloudinary->smartUpload($_FILES['logo_file'], 'uploads/attendance_logos', $filename, 'attendance', 'attendance_logo_' . $slug);
                                    if ($uploadResult['success']) {
                                        $_pdo->prepare("UPDATE payroll_attendance_config SET app_logo=? WHERE id=1")->execute([$uploadResult['path']]);
                                        $msg = '✅ Logo berhasil disimpan.';
                                        $msgType = 'success';
                                    }
                                } else {
                                    $msg = '❌ Format tidak didukung.';
                                    $msgType = 'error';
                                }
                            } elseif (!empty($_POST['remove_logo'])) {
                                $_pdo->prepare("UPDATE payroll_attendance_config SET app_logo=NULL WHERE id=1")->execute();
                                $msg = 'Logo dihapus.';
                                $msgType = 'success';
                            }
                        }

                        // ── Location CRUD ──
                        if ($action === 'add_location' || $action === 'edit_location') {
                            $locName   = trim(htmlspecialchars($_POST['loc_name'] ?? 'Lokasi'));
                            $locAddr   = trim(htmlspecialchars($_POST['loc_address'] ?? ''));
                            $locLat    = (float)($_POST['loc_lat'] ?? 0);
                            $locLng    = (float)($_POST['loc_lng'] ?? 0);
                            $locRad    = max(10, min(10000, (int)($_POST['loc_radius'] ?? 200)));
                            $locActive = isset($_POST['loc_active']) ? 1 : 0;
                            try {
                                if ($action === 'add_location') {
                                    $_pdo->prepare("INSERT INTO payroll_attendance_locations (location_name, address, lat, lng, radius_m, is_active) VALUES (?,?,?,?,?,1)")
                                        ->execute([$locName, $locAddr, $locLat, $locLng, $locRad]);
                                    $msg = "✅ Lokasi '{$locName}' ditambahkan.";
                                    $msgType = 'success';
                                } else {
                                    $locId = (int)($_POST['loc_id'] ?? 0);
                                    $_pdo->prepare("UPDATE payroll_attendance_locations SET location_name=?, address=?, lat=?, lng=?, radius_m=?, is_active=? WHERE id=?")
                                        ->execute([$locName, $locAddr, $locLat, $locLng, $locRad, $locActive, $locId]);
                                    $msg = "✅ Lokasi diperbarui.";
                                    $msgType = 'success';
                                }
                            } catch (Exception $e) {
                                $msg = '❌ Error: ' . $e->getMessage();
                                $msgType = 'error';
                            }
                        }
                        if ($action === 'delete_location') {
                            $_pdo->prepare("DELETE FROM payroll_attendance_locations WHERE id=?")->execute([(int)($_POST['loc_id'] ?? 0)]);
                            $msg = '✅ Lokasi dihapus.';
                            $msgType = 'success';
                        }

                        // ── Fingerspot config ──
                        if ($action === 'save_fingerspot') {
                            $fpCloudId = trim($_POST['fingerspot_cloud_id'] ?? '');
                            $fpToken = trim($_POST['fingerspot_token'] ?? '');
                            $fpEnabled = isset($_POST['fingerspot_enabled']) ? 1 : 0;
                            $_pdo->prepare("UPDATE payroll_attendance_config SET fingerspot_cloud_id=?, fingerspot_token=?, fingerspot_enabled=?, updated_by=? WHERE id=1")
                                ->execute([$fpCloudId ?: null, $fpToken ?: null, $fpEnabled, $currentUser['id']]);
                            $msg = '✅ Pengaturan Fingerspot disimpan.';
                            $msgType = 'success';
                        }

                        // ── Sync Fingerspot Data ──
                        if ($action === 'sync_fingerspot') {
                            $syncFrom = $_POST['sync_from'] ?? date('Y-m-01');
                            $syncTo = $_POST['sync_to'] ?? date('Y-m-d');

                            // Get config
                            $fpConfig = $db->fetchOne("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1");
                            $cloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
                            $apiToken = $fpConfig['fingerspot_token'] ?? '';
                            $fpEnabled = (int)($fpConfig['fingerspot_enabled'] ?? 0);

                            if (!$fpEnabled || !$cloudId || !$apiToken) {
                                $msg = '❌ Fingerspot belum dikonfigurasi. Isi Cloud ID dan API Token terlebih dahulu.';
                                $msgType = 'error';
                            } else {
                                // IMPORTANT: Fingerspot API has 2-day limit, so we need to chunk the date range
                                $apiUrl = "https://developer.fingerspot.io/api/get_attlog";
                                $allLogs = [];
                                $apiErrors = [];

                                $startDate = new DateTime($syncFrom);
                                $endDate = new DateTime($syncTo);
                                $endDate->modify('+1 day'); // Include end date

                                $interval = new DateInterval('P2D'); // 2 days interval (API limit)
                                $period = new DatePeriod($startDate, $interval, $endDate);

                                $totalApiCalls = 0;
                                foreach ($period as $chunkStart) {
                                    $chunkEnd = clone $chunkStart;
                                    $chunkEnd->modify('+1 day'); // 2-day range

                                    // Don't exceed syncTo
                                    if ($chunkEnd > new DateTime($syncTo)) {
                                        $chunkEnd = new DateTime($syncTo);
                                    }

                                    $postData = [
                                        'trans_id' => uniqid('sync_'),
                                        'cloud_id' => $cloudId,
                                        'start_date' => $chunkStart->format('Y-m-d'),
                                        'end_date' => $chunkEnd->format('Y-m-d')
                                    ];

                                    $ch = curl_init();
                                    curl_setopt_array($ch, [
                                        CURLOPT_URL => $apiUrl,
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => json_encode($postData),
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 30,
                                        CURLOPT_SSL_VERIFYPEER => false,
                                        CURLOPT_HTTPHEADER => [
                                            'Content-Type: application/json',
                                            'Authorization: Bearer ' . $apiToken
                                        ]
                                    ]);

                                    $response = curl_exec($ch);
                                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                    $curlError = curl_error($ch);
                                    curl_close($ch);

                                    $totalApiCalls++;

                                    if ($curlError) {
                                        $apiErrors[] = "Chunk {$chunkStart->format('Y-m-d')}: {$curlError}";
                                        continue;
                                    }

                                    $data = json_decode($response, true);

                                    if ($data && $data['success'] === true && isset($data['data']) && is_array($data['data'])) {
                                        $allLogs = array_merge($allLogs, $data['data']);
                                    } elseif ($data && $data['success'] === false) {
                                        $apiErrors[] = "Chunk {$chunkStart->format('Y-m-d')}: " . ($data['message'] ?? 'Unknown error');
                                    }

                                    // Small delay to avoid rate limiting
                                    usleep(200000); // 0.2 second
                                }

                                if (!empty($allLogs)) {
                                    $processed = 0;
                                    $skipped = 0;
                                    $errors = 0;
                                    $newRecords = 0;

                                    foreach ($allLogs as $log) {
                                        $pin = trim($log['pin'] ?? $log['user_id'] ?? '');
                                        $scanTime = $log['scan_date'] ?? $log['datetime'] ?? $log['scan'] ?? '';
                                        $verify = $log['verify'] ?? $log['verify_type'] ?? 'finger';
                                        $statusScan = $log['status_scan'] ?? $log['status'] ?? '';

                                        if (!$pin || !$scanTime) {
                                            $skipped++;
                                            continue;
                                        }

                                        // Find employee
                                        $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE TRIM(finger_id) = ? AND is_active = 1", [$pin]);
                                        if (!$emp && is_numeric($pin)) {
                                            $emp = $db->fetchOne("SELECT id, full_name FROM payroll_employees WHERE CAST(TRIM(finger_id) AS UNSIGNED) = CAST(? AS UNSIGNED) AND is_active = 1", [$pin]);
                                        }

                                        if (!$emp) {
                                            $skipped++;
                                            continue;
                                        }

                                        $empId = $emp['id'];
                                        $scanDate = date('Y-m-d', strtotime($scanTime));
                                        $scanTimeOnly = date('H:i:s', strtotime($scanTime));

                                        // Check existing attendance
                                        $existing = $db->fetchOne("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?", [$empId, $scanDate]);

                                        // Get checkin_end config for late detection
                                        $checkinEnd = $config['checkin_end'] ?? '10:00:00';
                                        $isSplitShift = isSplitShiftCheckInTime($scanTimeOnly);
                                        $isLate = !$isSplitShift && ($scanTimeOnly > $checkinEnd);

                                        try {
                                            if (!$existing) {
                                                // First scan of the day = check in
                                                $status = $isLate ? 'late' : 'present';
                                                $db->query(
                                                    "INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, status, notes) VALUES (?, ?, ?, ?, ?)",
                                                    [$empId, $scanDate, $scanTimeOnly, $status, "Sync: {$verify}"]
                                                );
                                                $processed++;
                                                $newRecords++;
                                            } else {
                                                // Update: determine which scan slot
                                                $s1 = $existing['check_in_time'];
                                                $s2 = $existing['check_out_time'];
                                                $s3 = $existing['scan_3'];
                                                $s4 = $existing['scan_4'];

                                                // Don't duplicate same scan time (within 5 minutes)
                                                $existingTimes = array_filter([$s1, $s2, $s3, $s4]);
                                                $isDuplicate = false;
                                                foreach ($existingTimes as $et) {
                                                    if (abs(strtotime($et) - strtotime($scanTimeOnly)) < 300) {
                                                        $isDuplicate = true;
                                                        break;
                                                    }
                                                }

                                                if ($isDuplicate) {
                                                    $skipped++;
                                                    continue;
                                                }

                                                // Assign to next empty slot
                                                $updated = false;
                                                if (!$s2) {
                                                    $db->query("UPDATE payroll_attendance SET check_out_time = ? WHERE id = ?", [$scanTimeOnly, $existing['id']]);
                                                    $updated = true;
                                                } elseif (!$s3) {
                                                    $db->query("UPDATE payroll_attendance SET scan_3 = ? WHERE id = ?", [$scanTimeOnly, $existing['id']]);
                                                    $updated = true;
                                                } elseif (!$s4) {
                                                    $db->query("UPDATE payroll_attendance SET scan_4 = ? WHERE id = ?", [$scanTimeOnly, $existing['id']]);
                                                    $updated = true;
                                                }

                                                if ($updated) {
                                                    // Recalculate work hours
                                                    $att = $db->fetchOne("SELECT * FROM payroll_attendance WHERE id = ?", [$existing['id']]);
                                                    $sh1 = null;
                                                    $sh2 = null;
                                                    if ($att['check_in_time'] && $att['check_out_time']) {
                                                        $t1 = strtotime($scanDate . ' ' . $att['check_in_time']);
                                                        $t2 = strtotime($scanDate . ' ' . $att['check_out_time']);
                                                        if ($t2 > $t1) $sh1 = round(($t2 - $t1) / 3600, 2);
                                                    }
                                                    if ($att['scan_3'] && $att['scan_4']) {
                                                        $t3 = strtotime($scanDate . ' ' . $att['scan_3']);
                                                        $t4 = strtotime($scanDate . ' ' . $att['scan_4']);
                                                        if ($t4 > $t3) $sh2 = round(($t4 - $t3) / 3600, 2);
                                                    }
                                                    $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2);
                                                    $db->query(
                                                        "UPDATE payroll_attendance SET work_hours = ?, shift_1_hours = ?, shift_2_hours = ? WHERE id = ?",
                                                        [$wh, $sh1, $sh2, $existing['id']]
                                                    );

                                                    $processed++;
                                                }
                                            }

                                            // Log sync
                                            $_pdo->prepare("INSERT INTO fingerprint_log (cloud_id, type, pin, scan_time, verify_method, status_scan, employee_id, processed, process_result) VALUES (?,?,?,?,?,?,?,1,'synced')")
                                                ->execute([$cloudId, 'sync', $pin, $scanTime, $verify, $statusScan, $empId]);
                                        } catch (Exception $e) {
                                            $errors++;
                                            error_log("Sync error: " . $e->getMessage());
                                        }
                                    }

                                    // Build result message
                                    $apiCallInfo = "({$totalApiCalls} API calls)";
                                    if (!empty($apiErrors)) {
                                        $apiCallInfo .= " - Beberapa chunk error";
                                    }

                                    $msg = "<div style='line-height:1.8'>"
                                        . "<div style='font-size:14px;font-weight:800;margin-bottom:8px;'>✅ Tarik Data Fingerspot Selesai</div>"
                                        . "<div style='display:flex;flex-wrap:wrap;gap:16px;margin-bottom:6px;'>"
                                        . "<span>📥 <strong>Total scan:</strong> " . count($allLogs) . "</span>"
                                        . "<span>✅ <strong>Masuk absensi:</strong> {$newRecords} baru + " . ($processed - $newRecords) . " update</span>"
                                        . "<span>⏭️ <strong>Diskip:</strong> {$skipped}</span>"
                                        . ($errors > 0 ? "<span>❌ <strong>Error:</strong> {$errors}</span>" : "")
                                        . "</div>"
                                        . "<div style='font-size:11px;color:#166534;'>Periode: {$syncFrom} s/d {$syncTo} · {$apiCallInfo}</div>"
                                        . "<div style='margin-top:10px;'><a href='process.php?month=" . date('n') . "&year=" . date('Y') . "' style='background:#0d1f3c;color:#fff;padding:7px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;'>💰 Lanjut Proses Gaji &rarr;</a></div>"
                                        . "</div>";
                                    $msgType = 'success';
                                    $_SESSION['last_payroll_tab'] = 'fingerprint';
                                } else {
                                    if (!empty($apiErrors)) {
                                        $msg = "<strong>❌ API Error:</strong><br><span style='font-size:11px;'>" . htmlspecialchars(implode("<br>", array_slice($apiErrors, 0, 5))) . "</span>"
                                            . "<br><span style='font-size:10px;color:#64748b;margin-top:6px;display:block;'>Pastikan Cloud ID, API Token, dan koneksi internet aktif.</span>";
                                    } else {
                                        $msg = "ℹ️ Tidak ada data absensi dari Fingerspot untuk periode <strong>{$syncFrom} s/d {$syncTo}</strong>.<br><span style='font-size:10px;color:#64748b;'>Coba perluas rentang tanggal atau cek apakah mesin sudah melakukan scan.</span>";
                                    }
                                    $msgType = empty($apiErrors) ? 'info' : 'error';
                                    $_SESSION['last_payroll_tab'] = 'fingerprint';
                                }
                            }
                        }

                        // ── Request Get Userinfo from Fingerspot (async via webhook) ──
                        if ($action === 'request_userinfo') {
                            try {
                                $fpConfig = $db->fetchOne("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1");
                                $cloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
                                $apiToken = $fpConfig['fingerspot_token'] ?? '';
                                $fpEn = (int)($fpConfig['fingerspot_enabled'] ?? 0);

                                if (!$fpEn || !$cloudId || !$apiToken) {
                                    $msg = '❌ Fingerspot belum dikonfigurasi.';
                                    $msgType = 'error';
                                } else {
                                    // Ensure fingerspot_userinfo table exists
                                    try {
                                        $_pdo->query("SELECT 1 FROM fingerspot_userinfo LIMIT 0");
                                    } catch (PDOException $e) {
                                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `fingerspot_userinfo` (
                                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                                            `cloud_id` VARCHAR(50) NOT NULL,
                                            `pin` VARCHAR(20) NOT NULL,
                                            `name` VARCHAR(100) DEFAULT '',
                                            `privilege` VARCHAR(10) DEFAULT '0',
                                            `finger` VARCHAR(10) DEFAULT '0',
                                            `face` VARCHAR(10) DEFAULT '0',
                                            `password` VARCHAR(50) DEFAULT '',
                                            `rfid` VARCHAR(50) DEFAULT '',
                                            `vein` VARCHAR(10) DEFAULT '0',
                                            `employee_id` INT DEFAULT NULL,
                                            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                            UNIQUE KEY uk_cloud_pin (cloud_id, pin),
                                            INDEX idx_pin (pin)
                                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                    }

                                    // Get list of PINs to query
                                    $pinList = [];
                                    $emps = $db->fetchAll("SELECT finger_id FROM payroll_employees WHERE finger_id IS NOT NULL AND finger_id != '' AND is_active = 1");
                                    foreach ($emps as $e) {
                                        $p = trim($e['finger_id']);
                                        if ($p !== '' && !in_array($p, $pinList)) $pinList[] = $p;
                                    }

                                    // Also add PINs from input field
                                    $extraPins = trim($_POST['extra_pins'] ?? '');
                                    if ($extraPins) {
                                        foreach (preg_split('/[\s,;]+/', $extraPins) as $ep) {
                                            $ep = trim($ep);
                                            if ($ep !== '' && !in_array($ep, $pinList)) $pinList[] = $ep;
                                        }
                                    }

                                    if (empty($pinList)) {
                                        $msg = '⚠️ Tidak ada PIN yang bisa di-query. Isi Finger ID di Data Karyawan dulu, atau masukkan PIN di kolom tambahan.';
                                        $msgType = 'info';
                                    } else {
                                        $sent = 0;
                                        $errors = 0;
                                        $errMsgs = [];
                                        $apiUrl = "https://developer.fingerspot.io/api/get_userinfo";
                                        foreach ($pinList as $pin) {
                                            $postData = [
                                                'trans_id' => uniqid('ui_'),
                                                'cloud_id' => $cloudId,
                                                'pin' => (string)$pin
                                            ];
                                            $ch = curl_init();
                                            curl_setopt_array($ch, [
                                                CURLOPT_URL => $apiUrl,
                                                CURLOPT_POST => true,
                                                CURLOPT_POSTFIELDS => json_encode($postData),
                                                CURLOPT_RETURNTRANSFER => true,
                                                CURLOPT_TIMEOUT => 10,
                                                CURLOPT_SSL_VERIFYPEER => false,
                                                CURLOPT_SSL_VERIFYHOST => 0,
                                                CURLOPT_FOLLOWLOCATION => true,
                                                CURLOPT_HTTPHEADER => [
                                                    'Content-Type: application/json',
                                                    'Authorization: Bearer ' . $apiToken
                                                ]
                                            ]);
                                            $response = curl_exec($ch);
                                            $curlErr = curl_error($ch);
                                            curl_close($ch);

                                            if ($curlErr) {
                                                $errors++;
                                                $errMsgs[] = "PIN {$pin}: " . $curlErr;
                                            } else {
                                                $res = json_decode($response, true);
                                                if ($res && isset($res['success']) && $res['success'] === true) {
                                                    $sent++;
                                                } else {
                                                    $errors++;
                                                    $errMsgs[] = "PIN {$pin}: " . ($res['message'] ?? $response);
                                                }
                                            }
                                            usleep(300000);
                                        }

                                        $msg = "<div style='line-height:1.8'>"
                                            . "<div style='font-size:14px;font-weight:800;margin-bottom:8px;'>📤 Request Get Userinfo " . ($sent > 0 ? 'Terkirim' : 'Gagal') . "</div>"
                                            . "<div style='display:flex;flex-wrap:wrap;gap:16px;margin-bottom:6px;'>"
                                            . "<span>📤 <strong>Terkirim:</strong> {$sent}/{" . count($pinList) . "} PIN</span>"
                                            . ($errors > 0 ? "<span>❌ <strong>Gagal:</strong> {$errors}</span>" : "")
                                            . "</div>";
                                        if ($sent > 0) {
                                            $msg .= "<div style='font-size:11px;color:#7c3aed;margin-bottom:6px;'>⏳ Mesin akan kirim data via webhook. Refresh halaman setelah ~10-30 detik.</div>";
                                        }
                                        if (!empty($errMsgs)) {
                                            $msg .= "<details style='margin-top:4px;'><summary style='font-size:10px;color:#dc2626;cursor:pointer;'>Lihat error detail</summary>"
                                                . "<pre style='font-size:9px;color:#64748b;background:#f8fafc;padding:8px;border-radius:6px;margin-top:4px;white-space:pre-wrap;'>"
                                                . htmlspecialchars(implode("\n", array_slice($errMsgs, 0, 10))) . "</pre></details>";
                                        }
                                        $msg .= "</div>";
                                        $msgType = $sent > 0 ? 'success' : 'error';
                                    }
                                }
                            } catch (Exception $ex) {
                                $msg = "❌ Error: " . htmlspecialchars($ex->getMessage());
                                $msgType = 'error';
                            }
                            $_SESSION['last_payroll_tab'] = 'fingerprint';
                        }

                        // ── Get All PIN from Fingerspot ──
                        if ($action === 'get_fingerspot_pins') {
                            $fpConfig = $db->fetchOne("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1");
                            $cloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
                            $apiToken = $fpConfig['fingerspot_token'] ?? '';
                            $fpEn = (int)($fpConfig['fingerspot_enabled'] ?? 0);

                            if (!$fpEn || !$cloudId || !$apiToken) {
                                $msg = '❌ Fingerspot belum dikonfigurasi. Isi Cloud ID dan API Token terlebih dahulu.';
                                $msgType = 'error';
                            } else {
                                // Use get_attlog to discover PINs from recent scan data (last 30 days)
                                $apiUrl = "https://developer.fingerspot.io/api/get_attlog";
                                $scanFrom = $_POST['scan_from'] ?? date('Y-m-d', strtotime('-30 days'));
                                $scanTo = $_POST['scan_to'] ?? date('Y-m-d');
                                $allLogs = [];
                                $apiErrors = [];

                                $startDate = new DateTime($scanFrom);
                                $endDate = new DateTime($scanTo);
                                $endDate->modify('+1 day');
                                $interval = new DateInterval('P2D');
                                $period = new DatePeriod($startDate, $interval, $endDate);

                                foreach ($period as $chunkStart) {
                                    $chunkEnd = clone $chunkStart;
                                    $chunkEnd->modify('+1 day');
                                    if ($chunkEnd > new DateTime($scanTo)) {
                                        $chunkEnd = new DateTime($scanTo);
                                    }

                                    $postData = [
                                        'trans_id' => uniqid('pin_'),
                                        'cloud_id' => $cloudId,
                                        'start_date' => $chunkStart->format('Y-m-d'),
                                        'end_date' => $chunkEnd->format('Y-m-d')
                                    ];

                                    $ch = curl_init();
                                    curl_setopt_array($ch, [
                                        CURLOPT_URL => $apiUrl,
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => json_encode($postData),
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT => 30,
                                        CURLOPT_SSL_VERIFYPEER => false,
                                        CURLOPT_HTTPHEADER => [
                                            'Content-Type: application/json',
                                            'Authorization: Bearer ' . $apiToken
                                        ]
                                    ]);
                                    $response = curl_exec($ch);
                                    $curlError = curl_error($ch);
                                    curl_close($ch);

                                    if ($curlError) {
                                        $apiErrors[] = $curlError;
                                        continue;
                                    }

                                    $data = json_decode($response, true);
                                    if ($data && $data['success'] === true && isset($data['data']) && is_array($data['data'])) {
                                        $allLogs = array_merge($allLogs, $data['data']);
                                    }
                                    usleep(200000);
                                }

                                // Extract unique PINs from scan data
                                $devicePinArr = [];
                                $pinLastScan = [];
                                $pinScanCount = [];
                                foreach ($allLogs as $log) {
                                    $pin = trim($log['pin'] ?? $log['user_id'] ?? '');
                                    if ($pin === '') continue;
                                    if (!in_array($pin, $devicePinArr)) {
                                        $devicePinArr[] = $pin;
                                    }
                                    $scanTime = $log['scan_date'] ?? $log['datetime'] ?? '';
                                    if ($scanTime && (!isset($pinLastScan[$pin]) || $scanTime > $pinLastScan[$pin])) {
                                        $pinLastScan[$pin] = $scanTime;
                                    }
                                    $pinScanCount[$pin] = ($pinScanCount[$pin] ?? 0) + 1;
                                }
                                sort($devicePinArr, SORT_NUMERIC);

                                if (!empty($devicePinArr)) {
                                    $totalDevice = count($devicePinArr);

                                    // Cross-reference with employees
                                    $devicePinResults = [];
                                    foreach ($devicePinArr as $dPin) {
                                        $dPin = trim($dPin);
                                        $matchedEmp = $db->fetchOne("SELECT id, employee_code, full_name, position FROM payroll_employees WHERE TRIM(finger_id) = ? AND is_active = 1", [$dPin]);
                                        if (!$matchedEmp && is_numeric($dPin)) {
                                            $matchedEmp = $db->fetchOne("SELECT id, employee_code, full_name, position FROM payroll_employees WHERE CAST(TRIM(finger_id) AS UNSIGNED) = CAST(? AS UNSIGNED) AND is_active = 1", [$dPin]);
                                        }
                                        $devicePinResults[] = [
                                            'pin' => $dPin,
                                            'matched' => $matchedEmp ? true : false,
                                            'employee' => $matchedEmp
                                        ];
                                    }

                                    // Find employees with finger_id NOT in device
                                    $empWithFinger = $db->fetchAll("SELECT id, employee_code, full_name, position, finger_id FROM payroll_employees WHERE finger_id IS NOT NULL AND finger_id != '' AND is_active = 1");
                                    $missingFromDevice = [];
                                    foreach ($empWithFinger as $ewf) {
                                        $found = false;
                                        foreach ($devicePinArr as $dPin) {
                                            if (trim($ewf['finger_id']) === trim($dPin) || (is_numeric($ewf['finger_id']) && is_numeric($dPin) && (int)$ewf['finger_id'] === (int)$dPin)) {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $missingFromDevice[] = $ewf;
                                        }
                                    }

                                    $_SESSION['fingerspot_device_pins'] = $devicePinResults;
                                    $_SESSION['fingerspot_missing_from_device'] = $missingFromDevice;
                                    $_SESSION['fingerspot_total_device'] = $totalDevice;
                                    $_SESSION['fingerspot_pin_last_scan'] = $pinLastScan;
                                    $_SESSION['fingerspot_pin_scan_count'] = $pinScanCount;
                                    $_SESSION['fingerspot_scan_period'] = $scanFrom . ' s/d ' . $scanTo;

                                    $matched = count(array_filter($devicePinResults, fn($r) => $r['matched']));
                                    $unmatched = count($devicePinResults) - $matched;

                                    $msg = "<div style='line-height:1.8'>"
                                        . "<div style='font-size:14px;font-weight:800;margin-bottom:8px;'>✅ Deteksi PIN Berhasil</div>"
                                        . "<div style='display:flex;flex-wrap:wrap;gap:16px;margin-bottom:6px;'>"
                                        . "<span>📟 <strong>PIN aktif:</strong> {$totalDevice}</span>"
                                        . "<span>📊 <strong>Total scan:</strong> " . count($allLogs) . "</span>"
                                        . "<span>✅ <strong>Cocok:</strong> {$matched}</span>"
                                        . ($unmatched > 0 ? "<span>⚠️ <strong>Tidak dikenal:</strong> {$unmatched}</span>" : "")
                                        . (count($missingFromDevice) > 0 ? "<span>❌ <strong>Belum scan:</strong> " . count($missingFromDevice) . "</span>" : "")
                                        . "</div>"
                                        . "<div style='font-size:11px;color:#166534;'>Periode: {$scanFrom} s/d {$scanTo} · Lihat detail di bawah</div>"
                                        . "</div>";
                                    $msgType = 'success';
                                } else {
                                    if (!empty($apiErrors)) {
                                        $msg = "❌ API Error: " . htmlspecialchars(implode(", ", array_slice($apiErrors, 0, 3)));
                                    } else {
                                        $msg = "ℹ️ Tidak ada data scan dari mesin untuk periode <strong>{$scanFrom} s/d {$scanTo}</strong>.<br><span style='font-size:10px;color:#64748b;'>Coba perluas rentang tanggal atau pastikan mesin sudah melakukan scan.</span>";
                                    }
                                    $msgType = empty($apiErrors) ? 'info' : 'error';
                                }
                            }
                            $_SESSION['last_payroll_tab'] = 'fingerprint';
                        }

                        // ── Edit attendance ──
                        if ($action === 'edit_att') {
                            $attId = (int)$_POST['att_id'];
                            $status = $_POST['status'] ?? 'present';
                            $s1 = !empty($_POST['scan_1']) ? $_POST['scan_1'] . ':00' : null;
                            $s2 = !empty($_POST['scan_2']) ? $_POST['scan_2'] . ':00' : null;
                            $s3 = !empty($_POST['scan_3']) ? $_POST['scan_3'] . ':00' : null;
                            $s4 = !empty($_POST['scan_4']) ? $_POST['scan_4'] . ':00' : null;
                            $notes = trim($_POST['notes'] ?? '');
                            $overtimeHours = trim($_POST['overtime_hours'] ?? '');
                            // Rule: OT dibulatkan ke kelipatan 45 menit (di bawah 45 menit tidak terhitung).
                            $otHours = $overtimeHours === '' ? null : roundOT45($overtimeHours);
                            $status = normalizeLateStatusBySplitShift($status, $s1);

                            $sh1 = null;
                            $sh2 = null;
                            if ($s1 && $s2) {
                                $t1 = strtotime("2000-01-01 $s1");
                                $t2 = strtotime("2000-01-01 $s2");
                                $sh1 = ($t2 > $t1) ? round(($t2 - $t1) / 3600, 2) : null;
                            }
                            if ($s3 && $s4) {
                                $t3 = strtotime("2000-01-01 $s3");
                                $t4 = strtotime("2000-01-01 $s4");
                                $sh2 = ($t4 > $t3) ? round(($t4 - $t3) / 3600, 2) : null;
                            }
                            $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2) ?: null;
                            $db->query(
                                "UPDATE payroll_attendance SET status=?, check_in_time=?, check_out_time=?, scan_3=?, scan_4=?, work_hours=?, overtime_hours=?, shift_1_hours=?, shift_2_hours=?, notes=? WHERE id=?",
                                [$status, $s1, $s2, $s3, $s4, $wh, $otHours, $sh1, $sh2, $notes, $attId]
                            );
                            $msg = 'Data absen diperbarui.';
                            $msgType = 'success';
                        }

                        // ── Delete attendance ──
                        if ($action === 'delete_att') {
                            $attId = (int)$_POST['att_id'];
                            if ($attId > 0) {
                                $db->query("DELETE FROM payroll_attendance WHERE id = ?", [$attId]);
                                $msg = 'Record dihapus.';
                                $msgType = 'success';
                            }
                        }

                        // ── Manual attendance ──
                        if ($action === 'manual_att') {
                            $empId = (int)$_POST['employee_id'];
                            $date = $_POST['attendance_date'];
                            $status = $_POST['status'] ?? 'present';
                            $s1 = !empty($_POST['scan_1']) ? $_POST['scan_1'] . ':00' : null;
                            $s2 = !empty($_POST['scan_2']) ? $_POST['scan_2'] . ':00' : null;
                            $s3 = !empty($_POST['scan_3']) ? $_POST['scan_3'] . ':00' : null;
                            $s4 = !empty($_POST['scan_4']) ? $_POST['scan_4'] . ':00' : null;
                            $notes = trim($_POST['notes'] ?? '');
                            $status = normalizeLateStatusBySplitShift($status, $s1);

                            $sh1 = null;
                            $sh2 = null;
                            if ($s1 && $s2) {
                                $t1 = strtotime("$date $s1");
                                $t2 = strtotime("$date $s2");
                                $sh1 = ($t2 > $t1) ? round(($t2 - $t1) / 3600, 2) : null;
                            }
                            if ($s3 && $s4) {
                                $t3 = strtotime("$date $s3");
                                $t4 = strtotime("$date $s4");
                                $sh2 = ($t4 > $t3) ? round(($t4 - $t3) / 3600, 2) : null;
                            }
                            $wh = round(($sh1 ?? 0) + ($sh2 ?? 0), 2) ?: null;
                            try {
                                $db->query(
                                    "INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, check_out_time, scan_3, scan_4, work_hours, shift_1_hours, shift_2_hours, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in_time=VALUES(check_in_time), check_out_time=VALUES(check_out_time), scan_3=VALUES(scan_3), scan_4=VALUES(scan_4), work_hours=VALUES(work_hours), shift_1_hours=VALUES(shift_1_hours), shift_2_hours=VALUES(shift_2_hours), status=VALUES(status), notes=VALUES(notes)",
                                    [$empId, $date, $s1, $s2, $s3, $s4, $wh, $sh1, $sh2, $status, $notes]
                                );
                                $msg = 'Absen manual berhasil.';
                                $msgType = 'success';
                            } catch (Exception $e) {
                                $msg = 'Error: ' . $e->getMessage();
                                $msgType = 'error';
                            }
                        }

                        // ── Reset: Face ──
                        if ($action === 'reset_face') {
                            $empId = (int)$_POST['employee_id'];
                            $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE id = ?", [$empId]);
                            $msg = '✅ Data wajah direset. Karyawan perlu selfie ulang.';
                            $msgType = 'success';
                        }

                        // ── Reset: Attendance by date range ──
                        if ($action === 'reset_attendance_range') {
                            $fromDate = $_POST['reset_from'] ?? '';
                            $toDate = $_POST['reset_to'] ?? '';
                            $resetEmpId = (int)($_POST['reset_employee_id'] ?? 0);
                            if ($fromDate && $toDate) {
                                if ($resetEmpId > 0) {
                                    $db->query("DELETE FROM payroll_attendance WHERE attendance_date BETWEEN ? AND ? AND employee_id = ?", [$fromDate, $toDate, $resetEmpId]);
                                } else {
                                    $db->query("DELETE FROM payroll_attendance WHERE attendance_date BETWEEN ? AND ?", [$fromDate, $toDate]);
                                }
                                $msg = '✅ Data absen periode ' . $fromDate . ' s/d ' . $toDate . ' berhasil dihapus.';
                                $msgType = 'success';
                            }
                        }

                        // ── Reset: All face data ──
                        if ($action === 'reset_all_faces') {
                            $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE is_active = 1");
                            $msg = '✅ Semua data wajah direset.';
                            $msgType = 'success';
                        }

                        // ── Reset: Fingerprint log ──
                        if ($action === 'reset_fingerprint_log') {
                            $_pdo->exec("TRUNCATE TABLE fingerprint_log");
                            $msg = '✅ Log fingerprint dihapus.';
                            $msgType = 'success';
                        }

                        // ── Reset: All attendance data ──
                        if ($action === 'reset_all_attendance') {
                            $confirmCode = trim($_POST['confirm_code'] ?? '');
                            if ($confirmCode === 'HAPUS-SEMUA') {
                                $_pdo->exec("TRUNCATE TABLE payroll_attendance");
                                $msg = '✅ Semua data absensi berhasil dihapus.';
                                $msgType = 'success';
                            } else {
                                $msg = '❌ Kode konfirmasi salah. Ketik HAPUS-SEMUA untuk mengkonfirmasi.';
                                $msgType = 'error';
                            }
                        }

                        // ── Reset: Specific employee data ──
                        if ($action === 'reset_employee_data') {
                            $resetEmpId = (int)($_POST['employee_id'] ?? 0);
                            $resetWhat = $_POST['reset_type'] ?? '';
                            if ($resetEmpId > 0) {
                                if ($resetWhat === 'face') {
                                    $db->query("UPDATE payroll_employees SET face_descriptor = NULL WHERE id = ?", [$resetEmpId]);
                                    $msg = '✅ Data wajah karyawan direset.';
                                    $msgType = 'success';
                                } elseif ($resetWhat === 'finger') {
                                    $db->query("UPDATE payroll_employees SET finger_id = NULL WHERE id = ?", [$resetEmpId]);
                                    $msg = '✅ Finger ID karyawan direset.';
                                    $msgType = 'success';
                                } elseif ($resetWhat === 'attendance') {
                                    $db->query("DELETE FROM payroll_attendance WHERE employee_id = ?", [$resetEmpId]);
                                    $msg = '✅ Semua data absen karyawan dihapus.';
                                    $msgType = 'success';
                                } elseif ($resetWhat === 'all') {
                                    $db->query("UPDATE payroll_employees SET face_descriptor = NULL, finger_id = NULL WHERE id = ?", [$resetEmpId]);
                                    $db->query("DELETE FROM payroll_attendance WHERE employee_id = ?", [$resetEmpId]);
                                    $msg = '✅ Semua data karyawan direset (wajah, finger, absen).';
                                    $msgType = 'success';
                                }
                            }
                        }

                        // ── Save Work Schedules ──
                        if ($action === 'save_work_schedule') {
                            $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT NOT NULL,
            `day_of_week` TINYINT NOT NULL DEFAULT 0,
            `start_time` TIME NOT NULL DEFAULT '09:00:00',
            `end_time` TIME NOT NULL DEFAULT '17:00:00',
            `break_minutes` INT DEFAULT 60,
            `is_off` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_emp_day (employee_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                            $schedEmpId = (int)($_POST['schedule_employee_id'] ?? 0);
                            $schedMode = $_POST['schedule_mode'] ?? 'individual'; // individual or bulk
                            $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

                            if ($schedMode === 'bulk') {
                                // Apply same schedule to all active employees
                                $bulkStart = $_POST['bulk_start_time'] ?? '09:00';
                                $bulkEnd = $_POST['bulk_end_time'] ?? '17:00';
                                $bulkBreak = (int)($_POST['bulk_break'] ?? 60);
                                $bulkOffDays = $_POST['off_days'] ?? [];

                                $allEmps = $db->fetchAll("SELECT id FROM payroll_employees WHERE is_active = 1") ?: [];
                                $saved = 0;
                                foreach ($allEmps as $emp) {
                                    for ($d = 0; $d <= 6; $d++) {
                                        $isOff = in_array((string)$d, $bulkOffDays) ? 1 : 0;
                                        $_pdo->prepare("INSERT INTO payroll_work_schedules (employee_id, day_of_week, start_time, end_time, break_minutes, is_off) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), break_minutes=VALUES(break_minutes), is_off=VALUES(is_off)")
                                            ->execute([$emp['id'], $d, $bulkStart, $bulkEnd, $bulkBreak, $isOff]);
                                    }
                                    $saved++;
                                }
                                $msg = "✅ Jadwal berhasil diterapkan ke {$saved} karyawan.";
                                $msgType = 'success';
                            } elseif ($schedEmpId > 0) {
                                // Individual schedule
                                for ($d = 0; $d <= 6; $d++) {
                                    $st = $_POST["start_$d"] ?? '09:00';
                                    $et = $_POST["end_$d"] ?? '17:00';
                                    $brk = (int)($_POST["break_$d"] ?? 60);
                                    $off = (($_POST["off_$d"] ?? '0') === '1') ? 1 : 0;
                                    $uni = trim($_POST["uniform_$d"] ?? '') ?: null;

                                    $_pdo->prepare("INSERT INTO payroll_work_schedules (employee_id, day_of_week, start_time, end_time, break_minutes, is_off, uniform) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), break_minutes=VALUES(break_minutes), is_off=VALUES(is_off), uniform=VALUES(uniform)")
                                        ->execute([$schedEmpId, $d, $st, $et, $brk, $off, $uni]);
                                }
                                $empName = $db->fetchOne("SELECT full_name FROM payroll_employees WHERE id = ?", [$schedEmpId])['full_name'] ?? '';
                                $msg = "✅ Jadwal kerja {$empName} berhasil disimpan.";
                                $msgType = 'success';
                            }
                        }

                        // ── Leave / Cuti approval ──
                        if ($action === 'approve_leave' || $action === 'reject_leave') {
                            $leaveId = (int)($_POST['leave_id'] ?? 0);
                            $adminNotes = trim($_POST['admin_notes'] ?? '');
                            $newStatus = ($action === 'approve_leave') ? 'approved' : 'rejected';
                            $approver = $_SESSION['full_name'] ?? 'Admin';
                            if ($leaveId > 0) {
                                try {
                                    $_pdo->exec("CREATE TABLE IF NOT EXISTS `leave_requests` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `leave_type` VARCHAR(50) DEFAULT 'cuti',
                    `start_date` DATE NOT NULL, `end_date` DATE NOT NULL, `reason` TEXT, `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
                    `approved_by` VARCHAR(100), `approved_at` DATETIME, `admin_notes` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_emp (employee_id), INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                    $db->query(
                                        "UPDATE leave_requests SET status = ?, approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?",
                                        [$newStatus, $approver, $adminNotes, $leaveId]
                                    );
                                    // Create notification for staff
                                    $leaveReq = $db->fetchOne("SELECT employee_id, leave_type, start_date, end_date FROM leave_requests WHERE id = ?", [$leaveId]);
                                    if ($leaveReq) {
                                        $typeLabels = ['cuti' => 'Cuti', 'sakit' => 'Sakit', 'izin' => 'Izin', 'cuti_khusus' => 'Cuti Khusus'];
                                        $tl = $typeLabels[$leaveReq['leave_type']] ?? $leaveReq['leave_type'];
                                        $statusLabel = $newStatus === 'approved' ? 'Disetujui' : 'Ditolak';
                                        $db->query("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, 'leave_response', ?, ?, ?, NOW())", [
                                            $leaveReq['employee_id'],
                                            $tl . ' ' . $statusLabel,
                                            $tl . ' (' . $leaveReq['start_date'] . ' s/d ' . $leaveReq['end_date'] . ') ' . strtolower($statusLabel) . ($adminNotes ? '. Catatan: ' . $adminNotes : ''),
                                            json_encode(['leave_id' => $leaveId, 'status' => $newStatus, 'leave_type' => $leaveReq['leave_type']])
                                        ]);
                                    }
                                    $msg = $newStatus === 'approved' ? '✅ Cuti disetujui.' : '❌ Cuti ditolak.';
                                    $msgType = 'success';
                                } catch (Exception $e) {
                                    $msg = 'Error: ' . $e->getMessage();
                                    $msgType = 'error';
                                }
                            }
                        }

                        // ── Leave / Cuti delete ──
                        if ($action === 'delete_leave') {
                            $leaveId = (int)($_POST['leave_id'] ?? 0);
                            if ($leaveId > 0) {
                                try {
                                    $db->query("DELETE FROM leave_requests WHERE id = ?", [$leaveId]);
                                    $msg = '🗑️ Pengajuan cuti berhasil dihapus.';
                                    $msgType = 'success';
                                } catch (Exception $e) {
                                    $msg = 'Error: ' . $e->getMessage();
                                    $msgType = 'error';
                                }
                            }
                        }

                        // ── Overtime / Lembur approval ──
                        if ($action === 'approve_overtime' || $action === 'reject_overtime') {
                            $otId = (int)($_POST['overtime_id'] ?? 0);
                            $adminNotes = trim($_POST['admin_notes'] ?? '');
                            $newStatus = ($action === 'approve_overtime') ? 'approved' : 'rejected';
                            $approver = $_SESSION['full_name'] ?? 'Admin';
                            if ($otId > 0) {
                                try {
                                    $_pdo->exec("CREATE TABLE IF NOT EXISTS `overtime_requests` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `overtime_date` DATE NOT NULL,
                    `reason` TEXT NOT NULL, `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
                    `approved_by` VARCHAR(100), `approved_at` DATETIME, `admin_notes` TEXT,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_emp (employee_id), INDEX idx_status (status), INDEX idx_date (overtime_date),
                    UNIQUE KEY uk_emp_date (employee_id, overtime_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                    $db->query(
                                        "UPDATE overtime_requests SET status = ?, approved_by = ?, approved_at = NOW(), admin_notes = ? WHERE id = ?",
                                        [$newStatus, $approver, $adminNotes, $otId]
                                    );
                                    // Create notification for staff
                                    $otReq = $db->fetchOne("SELECT employee_id, overtime_date FROM overtime_requests WHERE id = ?", [$otId]);
                                    if ($otReq) {
                                        $statusLabel = $newStatus === 'approved' ? 'Disetujui' : 'Ditolak';
                                        $db->query("INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, 'overtime_response', ?, ?, ?, NOW())", [
                                            $otReq['employee_id'],
                                            'Lembur ' . $statusLabel,
                                            'Pengajuan lembur tanggal ' . $otReq['overtime_date'] . ' ' . strtolower($statusLabel) . ($adminNotes ? '. Catatan: ' . $adminNotes : ''),
                                            json_encode(['overtime_id' => $otId, 'status' => $newStatus, 'overtime_date' => $otReq['overtime_date']])
                                        ]);
                                    }
                                    $msg = $newStatus === 'approved' ? '✅ Lembur disetujui.' : '❌ Lembur ditolak.';
                                    $msgType = 'success';
                                } catch (Exception $e) {
                                    $msg = 'Error: ' . $e->getMessage();
                                    $msgType = 'error';
                                }
                            }
                        }

                        // ── Batch: Proses Log Fingerprint → payroll_attendance ──
                        if ($action === 'process_finger_batch') {
                            $fpFrom = $_POST['fp_from'] ?? date('Y-m-01');
                            $fpTo   = $_POST['fp_to']   ?? date('Y-m-d');
                            // Validate dates
                            if (!strtotime($fpFrom) || !strtotime($fpTo)) {
                                $msg = '❌ Format tanggal tidak valid.';
                                $msgType = 'error';
                            } else {
                                // Get checkin_end config for late detection
                                $cfgTime = $_pdo->query("SELECT checkin_end FROM payroll_attendance_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                                $checkinEnd = $cfgTime['checkin_end'] ?? '10:00:00';

                                // Get all unprocessed logs in date range, match to employees
                                $unprStmt = $_pdo->prepare("
                SELECT fl.id, fl.pin, fl.scan_time, fl.verify_method,
                       pe.id AS emp_id, pe.full_name
                FROM fingerprint_log fl
                LEFT JOIN payroll_employees pe ON (
                    pe.is_active = 1 AND (
                        TRIM(pe.finger_id) = TRIM(fl.pin)
                        OR (fl.pin REGEXP '^[0-9]+$' AND CAST(TRIM(pe.finger_id) AS UNSIGNED) = CAST(TRIM(fl.pin) AS UNSIGNED))
                    )
                )
                WHERE fl.processed = 0 AND fl.scan_time IS NOT NULL
                  AND DATE(fl.scan_time) BETWEEN ? AND ?
                ORDER BY fl.scan_time ASC
            ");
                                $unprStmt->execute([$fpFrom, $fpTo]);
                                $unprLogs = $unprStmt->fetchAll(PDO::FETCH_ASSOC);

                                $processed = 0;
                                $skipped = 0;
                                $errors = 0;

                                foreach ($unprLogs as $log) {
                                    $logId = (int)$log['id'];

                                    if (empty($log['emp_id'])) {
                                        $_pdo->prepare("UPDATE fingerprint_log SET process_result = CONCAT('Tidak cocok — PIN: ', pin) WHERE id = ?")
                                            ->execute([$logId]);
                                        $skipped++;
                                        continue;
                                    }

                                    try {
                                        $empId      = (int)$log['emp_id'];
                                        $scanDate   = date('Y-m-d', strtotime($log['scan_time']));
                                        $scanTime   = date('H:i:s', strtotime($log['scan_time']));

                                        // Get existing attendance for this employee + date
                                        $attStmt = $_pdo->prepare("SELECT * FROM payroll_attendance WHERE employee_id = ? AND attendance_date = ?");
                                        $attStmt->execute([$empId, $scanDate]);
                                        $att = $attStmt->fetch(PDO::FETCH_ASSOC);

                                        $scan1 = $att['check_in_time']  ?? null;
                                        $scan2 = $att['check_out_time'] ?? null;
                                        $scan3 = $att['scan_3']         ?? null;
                                        $scan4 = $att['scan_4']         ?? null;
                                        $filled = array_filter([$scan1, $scan2, $scan3, $scan4], fn($s) => !empty($s));

                                        if (count($filled) >= 4) {
                                            $_pdo->prepare("UPDATE fingerprint_log SET processed=1, process_result='Max 4 scan tercapai' WHERE id=?")->execute([$logId]);
                                            $skipped++;
                                            continue;
                                        }

                                        // Double-scan guard: ignore if last scan < 5 min ago
                                        if (!empty($filled)) {
                                            $lastScan = end($filled);
                                            $diffMin = abs(strtotime("2000-01-01 $scanTime") - strtotime("2000-01-01 $lastScan")) / 60;
                                            if ($diffMin < 5) {
                                                $_pdo->prepare("UPDATE fingerprint_log SET processed=1, process_result='Double scan diabaikan (<5 mnt)' WHERE id=?")->execute([$logId]);
                                                $skipped++;
                                                continue;
                                            }
                                        }

                                        if (!$att) {
                                            // Scan 1 — create new record
                                            $isSplitShift = isSplitShiftCheckInTime($scanTime);
                                            $status = $isSplitShift ? 'present' : (($scanTime > $checkinEnd) ? 'late' : 'present');
                                            $_pdo->prepare("INSERT INTO payroll_attendance (employee_id, attendance_date, check_in_time, status, check_in_device, notes) VALUES (?,?,?,?,?,?)")
                                                ->execute([$empId, $scanDate, $scanTime, $status, 'fingerprint:batch', 'Batch proses fingerprint']);
                                            $scanLabel = 'Scan 1 Masuk';
                                        } else {
                                            // Determine next slot
                                            if (empty($scan1)) {
                                                $updateCol = 'check_in_time';
                                                $scanNum = 1;
                                            } elseif (empty($scan2)) {
                                                $updateCol = 'check_out_time';
                                                $scanNum = 2;
                                            } elseif (empty($scan3)) {
                                                $updateCol = 'scan_3';
                                                $scanNum = 3;
                                            } else {
                                                $updateCol = 'scan_4';
                                                $scanNum = 4;
                                            }

                                            $sh1 = null;
                                            $sh2 = null;
                                            if ($scanNum === 2 && $scan1) {
                                                $t1 = strtotime("2000-01-01 $scan1");
                                                $t2 = strtotime("2000-01-01 $scanTime");
                                                $sh1 = ($t2 > $t1) ? round(($t2 - $t1) / 3600, 2) : null;
                                            }
                                            if ($scanNum === 4 && $scan3) {
                                                $t3 = strtotime("2000-01-01 $scan3");
                                                $t4 = strtotime("2000-01-01 $scanTime");
                                                $sh2 = ($t4 > $t3) ? round(($t4 - $t3) / 3600, 2) : null;
                                            }

                                            $updates = ["{$updateCol} = ?"];
                                            $params  = [$scanTime];
                                            if ($sh1 !== null) {
                                                $updates[] = 'shift_1_hours = ?';
                                                $params[] = $sh1;
                                            }
                                            if ($sh2 !== null) {
                                                $updates[] = 'shift_2_hours = ?';
                                                $params[] = $sh2;
                                            }

                                            $curSh1 = ($sh1 !== null) ? $sh1 : (float)($att['shift_1_hours'] ?? 0);
                                            $curSh2 = ($sh2 !== null) ? $sh2 : (float)($att['shift_2_hours'] ?? 0);
                                            $totalH = round($curSh1 + $curSh2, 2);
                                            $updates[] = 'work_hours = ?';
                                            $params[] = $totalH;

                                            $params[] = $att['id'];
                                            $_pdo->prepare("UPDATE payroll_attendance SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                                            $scanLabel = "Scan {$scanNum}";
                                        }

                                        $_pdo->prepare("UPDATE fingerprint_log SET processed=1, process_result=? WHERE id=?")
                                            ->execute(["✅ Batch → absensi ({$scanLabel})", $logId]);
                                        $processed++;
                                    } catch (Exception $e) {
                                        $errors++;
                                        $_pdo->prepare("UPDATE fingerprint_log SET process_result=? WHERE id=?")
                                            ->execute([substr('Error: ' . $e->getMessage(), 0, 255), $logId]);
                                    }
                                }

                                $totalLogs = count($unprLogs);
                                $msg = "<div style='line-height:1.8'>"
                                    . "<div style='font-size:14px;font-weight:800;margin-bottom:8px;'>✅ Proses Fingerprint Selesai</div>"
                                    . "<div style='display:flex;flex-wrap:wrap;gap:16px;margin-bottom:6px;'>"
                                    . "<span>📥 <strong>Total log:</strong> {$totalLogs}</span>"
                                    . "<span>✅ <strong>Berhasil:</strong> {$processed} scan ke absensi</span>"
                                    . "<span>⏭️ <strong>Diskip:</strong> {$skipped}</span>"
                                    . ($errors > 0 ? "<span>❌ <strong>Error:</strong> {$errors}</span>" : "")
                                    . "</div>"
                                    . "<div style='font-size:11px;color:#166534;'>Periode: {$fpFrom} s/d {$fpTo} &nbsp;·&nbsp; Data absensi & jam kerja sudah diperbarui</div>"
                                    . "<div style='margin-top:10px;'><a href='process.php?month=" . date('n') . "&year=" . date('Y') . "' style='background:#0d1f3c;color:#fff;padding:7px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;'>💰 Lanjut Proses Gaji &rarr;</a></div>"
                                    . "</div>";
                                $msgType = 'success';
                                $_SESSION['last_payroll_tab'] = 'fingerprint';
                            }
                        }
                    }

                    // ══════════════════════════════════════════════
                    // FETCH DATA
                    // ══════════════════════════════════════════════
                    $config = $db->fetchOne("SELECT * FROM payroll_attendance_config WHERE id = 1") ?: [];
                    $locations = $db->fetchAll("SELECT * FROM payroll_attendance_locations ORDER BY id") ?: [];
                    $employees = $db->fetchAll("SELECT id, employee_code, full_name, position, face_descriptor, finger_id FROM payroll_employees WHERE is_active = 1 ORDER BY full_name") ?: [];

                    $viewDate = $_GET['date'] ?? date('Y-m-d');

                    // Daily attendance
                    $dailyAtt = $db->fetchAll("
    SELECT a.*, e.full_name, e.employee_code, e.position
    FROM payroll_attendance a
    JOIN payroll_employees e ON e.id = a.employee_id
    WHERE a.attendance_date = ?
    ORDER BY e.full_name
", [$viewDate]) ?: [];

                    // Fetch approved overtime employee IDs for this date
                    $approvedOTEmployees = [];
                    try {
                        $approvedOTRows = $db->fetchAll("SELECT employee_id FROM overtime_requests WHERE overtime_date = ? AND status = 'approved'", [$viewDate]) ?: [];
                        foreach ($approvedOTRows as $otRow) $approvedOTEmployees[(int)$otRow['employee_id']] = true;
                    } catch (Exception $e) {
                    }

                    // Today stats
                    $todayStats = ['total' => count($employees), 'present' => 0, 'late' => 0, 'total_hours' => 0, 'regular_hours' => 0, 'overtime_hours' => 0, 'ot_count' => 0];
                    foreach ($dailyAtt as $a) {
                        if ($a['check_in_time']) $todayStats['present']++;
                        if (($a['status'] ?? '') === 'late' && !isSplitShiftCheckInTime($a['check_in_time'] ?? null)) $todayStats['late']++;
                        $wh = (float)($a['work_hours'] ?? 0);
                        $manualOT = (float)($a['overtime_hours'] ?? 0);
                        $todayStats['total_hours'] += $wh;
                        $todayStats['regular_hours'] += min($wh, 8);
                        // Manual OT from admin edit takes precedence; otherwise approved request uses actual time above 8 hours.
                        // Rule: OT dibulatkan ke kelipatan 45 menit (di bawah 45 menit tidak terhitung).
                        if ($manualOT > 0) {
                            $otAdd = roundOT45($manualOT);
                            if ($otAdd > 0) {
                                $todayStats['overtime_hours'] += $otAdd;
                                $todayStats['ot_count']++;
                            }
                        } elseif (isset($approvedOTEmployees[(int)$a['employee_id']])) {
                            $otAdd = roundOT45(max(0, $wh - 8));
                            if ($otAdd > 0) {
                                $todayStats['overtime_hours'] += $otAdd;
                                $todayStats['ot_count']++;
                            }
                        }
                    }

                    $bizSlug = defined('ACTIVE_BUSINESS_ID') ? strtolower(str_replace('_', '-', ACTIVE_BUSINESS_ID)) : 'narayana-hotel';
                    $staffPortalUrl = $baseUrl . '/modules/payroll/staff-portal.php?b=' . $bizSlug;

                    // Leave requests
                    $leaveRequests = [];
                    try {
                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `leave_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `leave_type` VARCHAR(50) DEFAULT 'cuti',
        `start_date` DATE NOT NULL, `end_date` DATE NOT NULL, `reason` TEXT, `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
        `approved_by` VARCHAR(100), `approved_at` DATETIME, `admin_notes` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp (employee_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        $leaveRequests = $db->fetchAll("SELECT lr.*, pe.full_name, pe.employee_code FROM leave_requests lr LEFT JOIN payroll_employees pe ON pe.id = lr.employee_id ORDER BY FIELD(lr.status,'pending','approved','rejected'), lr.created_at DESC LIMIT 100") ?: [];
                    } catch (Exception $e) {
                    }
                    $pendingLeaves = count(array_filter($leaveRequests, fn($l) => $l['status'] === 'pending'));

                    // Overtime requests
                    $overtimeRequests = [];
                    try {
                        $_pdo->exec("CREATE TABLE IF NOT EXISTS `overtime_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `overtime_date` DATE NOT NULL,
        `reason` TEXT NOT NULL, `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
        `approved_by` VARCHAR(100), `approved_at` DATETIME, `admin_notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emp (employee_id), INDEX idx_status (status), INDEX idx_date (overtime_date),
        UNIQUE KEY uk_emp_date (employee_id, overtime_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        $overtimeRequests = $db->fetchAll("SELECT ot.*, pe.full_name, pe.employee_code FROM overtime_requests ot LEFT JOIN payroll_employees pe ON pe.id = ot.employee_id ORDER BY FIELD(ot.status,'pending','approved','rejected'), ot.overtime_date DESC LIMIT 100") ?: [];
                    } catch (Exception $e) {
                    }
                    $pendingOT = count(array_filter($overtimeRequests, fn($o) => $o['status'] === 'pending'));

                    // Fingerspot data
                    $fpConfig = $db->fetchOne("SELECT fingerspot_cloud_id, fingerspot_token, fingerspot_enabled FROM payroll_attendance_config WHERE id = 1") ?: [];
                    $fpEnabled = (int)($fpConfig['fingerspot_enabled'] ?? 0);
                    $fpCloudId = $fpConfig['fingerspot_cloud_id'] ?? '';
                    $fpToken = $fpConfig['fingerspot_token'] ?? '';
                    $fpCloudStatus = null;

                    // Fungsi cek status cloud_id ke API Fingerspot
                    function checkFingerspotCloudStatus($cloudId)
                    {
                        if (!$cloudId) return ['success' => false, 'message' => 'Cloud ID kosong'];
                        $apiUrl = 'https://cloud.fingerspot.io/api/device/status?cloud_id=' . urlencode($cloudId);
                        $opts = [
                            'http' => [
                                'method' => 'GET',
                                'timeout' => 8,
                                'header' => [
                                    'Accept: application/json',
                                ]
                            ]
                        ];
                        $context = stream_context_create($opts);
                        $result = @file_get_contents($apiUrl, false, $context);
                        if ($result === false) return ['success' => false, 'message' => 'Tidak bisa menghubungi server Fingerspot'];
                        $data = json_decode($result, true);
                        if (!$data || !isset($data['success'])) return ['success' => false, 'message' => 'Respon tidak valid dari server'];
                        return $data;
                    }

                    // Cek status cloud_id jika diaktifkan
                    if ($fpEnabled && $fpCloudId) {
                        $fpCloudStatus = checkFingerspotCloudStatus($fpCloudId);
                    }
                    $webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'adfsystem.online') . str_replace('/modules/payroll/attendance.php', '', $_SERVER['SCRIPT_NAME']) . '/api/fingerprint-webhook.php?b=' . urlencode($bizSlug);
                    $webhookUrlMulti = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'adfsystem.online') . str_replace('/modules/payroll/attendance.php', '', $_SERVER['SCRIPT_NAME']) . '/api/fingerprint-webhook.php';

                    // Webhook logs
                    $fpLogs = [];
                    try {
                        $fpLogs = $db->fetchAll("SELECT fl.*, pe.full_name as emp_name FROM fingerprint_log fl LEFT JOIN payroll_employees pe ON fl.employee_id = pe.id ORDER BY fl.created_at DESC LIMIT 20") ?: [];
                    } catch (Exception $e) {
                    }

                    // Unprocessed fingerprint log stats
                    $fpUnprocessed = 0;
                    $fpThisMonth   = 0;
                    try {
                        $r1 = $_pdo->query("SELECT COUNT(*) as c FROM fingerprint_log WHERE processed = 0 AND scan_time IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
                        $fpUnprocessed = (int)($r1['c'] ?? 0);
                        $thisMonthStr = date('Y-m');
                        $r2 = $_pdo->prepare("SELECT COUNT(*) as c FROM fingerprint_log WHERE DATE_FORMAT(scan_time,'%Y-%m') = ?");
                        $r2->execute([$thisMonthStr]);
                        $fpThisMonth = (int)($r2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
                    } catch (Exception $e) {
                    }

                    // Reset stats
                    $resetStats = [
                        'total_records' => 0,
                        'face_registered' => 0,
                        'finger_registered' => 0,
                        'log_count' => 0
                    ];
                    try {
                        $r = $_pdo->query("SELECT COUNT(*) as c FROM payroll_attendance")->fetch(PDO::FETCH_ASSOC);
                        $resetStats['total_records'] = (int)($r['c'] ?? 0);
                        $resetStats['face_registered'] = count(array_filter($employees, fn($e) => !empty($e['face_descriptor'])));
                        $resetStats['finger_registered'] = count(array_filter($employees, fn($e) => !empty($e['finger_id'])));
                        $r2 = $_pdo->query("SELECT COUNT(*) as c FROM fingerprint_log")->fetch(PDO::FETCH_ASSOC);
                        $resetStats['log_count'] = (int)($r2['c'] ?? 0);
                    } catch (Exception $e) {
                    }

                    include '../../includes/header.php';
                    ?>

                    <style>
                        :root {
                            --navy: #0d1f3c;
                            --navy-light: #1a3a5c;
                            --gold: #f0b429;
                            --green: #059669;
                            --orange: #ea580c;
                            --red: #dc2626;
                            --blue: #2563eb;
                            --purple: #7c3aed;
                            --bg: #f8fafc;
                            --card: #fff;
                            --border: #e2e8f0;
                            --muted: #64748b;
                            /* Luxe accents (visual redesign only) */
                            --grad-gold: linear-gradient(135deg, #f0b429, #f7c948);
                            --grad-navy: linear-gradient(135deg, #0d1f3c, #1a3a5c);
                            --grad-green: linear-gradient(135deg, #059669, #34d399);
                            --grad-orange: linear-gradient(135deg, #ea580c, #fb923c);
                            --grad-purple: linear-gradient(135deg, #7c3aed, #a78bfa);
                            --grad-gray: linear-gradient(135deg, #94a3b8, #cbd5e1);
                            --pr-radius: 12px;
                            --pr-shadow: 0 1px 4px rgba(13, 31, 60, .06);
                        }

                        .att-wrap {
                            max-width: 100%;
                            font-family: 'Inter', sans-serif;
                        }

                        /* Header */
                        .att-head {
                            background: #fff;
                            padding: 13px 16px;
                            border-radius: var(--pr-radius);
                            margin-bottom: 12px;
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            gap: 12px;
                            flex-wrap: wrap;
                            border: 1px solid var(--border);
                            box-shadow: var(--pr-shadow);
                            position: relative;
                            overflow: hidden;
                        }

                        .att-head::before {
                            content: '';
                            position: absolute;
                            top: 0;
                            left: 0;
                            right: 0;
                            height: 3px;
                            background: var(--navy);
                        }

                        .att-head-title {
                            display: flex;
                            align-items: center;
                            gap: 12px;
                        }

                        .att-head-icon {
                            width: 44px;
                            height: 44px;
                            border-radius: 14px;
                            background: var(--grad-navy);
                            color: #fff;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            flex-shrink: 0;
                        }

                        .att-head-icon svg {
                            width: 22px;
                            height: 22px;
                        }

                        .att-head h1 {
                            font-size: 18px;
                            font-weight: 700;
                            color: var(--navy);
                            margin: 0 0 2px;
                            letter-spacing: -.2px;
                        }

                        .att-head p {
                            font-size: 12px;
                            margin: 0;
                            color: var(--muted);
                        }

                        .att-head .btn svg {
                            width: 14px;
                            height: 14px;
                        }

                        /* Stats Row */
                        .st-row {
                            display: grid;
                            grid-template-columns: repeat(5, 1fr);
                            gap: 10px;
                            margin-bottom: 12px;
                        }

                        .st-card {
                            background: #fff;
                            padding: 13px;
                            border-radius: var(--pr-radius);
                            border: 1px solid var(--border);
                            position: relative;
                            overflow: hidden;
                            transition: transform .2s ease, box-shadow .2s ease;
                        }

                        .st-card::before {
                            content: '';
                            position: absolute;
                            top: 0;
                            left: 0;
                            right: 0;
                            height: 3px;
                            background: var(--navy);
                        }

                        .st-card:hover {
                            transform: translateY(-2px);
                            box-shadow: var(--pr-shadow);
                        }

                        .st-icon {
                            width: 28px;
                            height: 28px;
                            border-radius: 8px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            margin-bottom: 7px;
                            background: rgba(13, 31, 60, .08);
                            color: var(--navy);
                        }

                        .st-icon svg {
                            width: 16px;
                            height: 16px;
                        }

                        .st-card .lb {
                            font-size: 10px;
                            color: var(--muted);
                            font-weight: 700;
                            text-transform: uppercase;
                            letter-spacing: .4px;
                        }

                        .st-card .vl {
                            font-size: 21px;
                            font-weight: 800;
                            color: var(--navy);
                            margin-top: 3px;
                            line-height: 1;
                        }

                        .st-card .sb {
                            font-size: 10px;
                            color: var(--muted);
                            margin-top: 3px;
                        }

                        /* Tabs */
                        .att-tabs {
                            display: flex;
                            gap: 6px;
                            background: var(--bg);
                            padding: 6px;
                            border-radius: 16px;
                            margin-bottom: 14px;
                            border: 1px solid var(--border);
                            overflow-x: auto;
                        }

                        .att-tab {
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            padding: 8px 14px;
                            border: none;
                            background: transparent;
                            border-radius: 11px;
                            cursor: pointer;
                            font-weight: 600;
                            font-size: 12px;
                            color: var(--muted);
                            transition: all .18s ease;
                            white-space: nowrap;
                        }

                        .att-tab:hover {
                            background: rgba(13, 31, 60, .05);
                            color: var(--navy);
                        }

                        .att-tab-icon {
                            display: inline-flex;
                            align-items: center;
                            justify-content: center;
                            width: 18px;
                            height: 18px;
                        }

                        .att-tab-icon svg {
                            width: 15px;
                            height: 15px;
                        }

                        .att-tab.active {
                            background: var(--navy);
                            color: #fff;
                            font-weight: 800;
                            box-shadow: 0 4px 12px rgba(13, 31, 60, .25);
                        }

                        /* Table */
                        .tbl-wrap {
                            background: #fff;
                            border-radius: 12px;
                            overflow: hidden;
                            box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
                            border: 1px solid var(--border);
                            margin-bottom: 14px;
                        }

                        .tbl {
                            width: 100%;
                            border-collapse: collapse;
                            font-size: 14px;
                            line-height: 1.5;
                        }

                        .tbl th {
                            background: #f8fafc;
                            color: #475569;
                            padding: 10px 14px;
                            text-align: left;
                            font-weight: 700;
                            font-size: 12px;
                            text-transform: uppercase;
                            letter-spacing: .5px;
                            border-bottom: 2px solid var(--navy);
                            white-space: nowrap;
                        }

                        .tbl td {
                            padding: 12px 14px;
                            border-bottom: 1px solid #f1f5f9;
                            font-size: 14px;
                            color: #1e293b;
                            vertical-align: middle;
                        }

                        .tbl tr:hover td {
                            background: #fafbfd;
                        }

                        /* Badges */
                        .badge {
                            padding: 3px 8px;
                            border-radius: 5px;
                            font-size: 10px;
                            font-weight: 700;
                            text-transform: uppercase;
                            display: inline-flex;
                            align-items: center;
                            gap: 3px;
                        }

                        .b-present {
                            background: #dcfce7;
                            color: #166534;
                        }

                        .b-late {
                            background: #ffedd5;
                            color: #9a3412;
                        }

                        .b-absent {
                            background: #fee2e2;
                            color: #991b1b;
                        }

                        .b-leave {
                            background: #e0e7ff;
                            color: #3730a3;
                        }

                        .b-notyet {
                            background: #f1f5f9;
                            color: #94a3b8;
                        }

                        .b-holiday,
                        .b-half_day {
                            background: #f3f4f6;
                            color: #374151;
                        }

                        /* Buttons */
                        .btn {
                            padding: 6px 12px;
                            border-radius: 7px;
                            font-size: 11px;
                            font-weight: 600;
                            border: none;
                            cursor: pointer;
                            transition: all .15s;
                            display: inline-flex;
                            align-items: center;
                            gap: 4px;
                            text-decoration: none;
                        }

                        .btn svg {
                            width: 14px;
                            height: 14px;
                        }

                        .btn:hover {
                            opacity: .85;
                        }

                        .btn-primary {
                            background: var(--navy);
                            color: #fff;
                        }

                        .btn-gold {
                            background: var(--navy);
                            color: #fff;
                        }

                        .btn-edit {
                            background: #eff6ff;
                            color: var(--blue);
                        }

                        .btn-del {
                            background: #fef2f2;
                            color: var(--red);
                            border: 1px solid #fca5a5;
                        }

                        .btn-green {
                            background: #d1fae5;
                            color: #065f46;
                        }

                        .btn-purple {
                            background: #ede9fe;
                            color: var(--purple);
                        }

                        .btn-danger {
                            background: var(--red);
                            color: #fff;
                        }

                        .btn-sm {
                            padding: 4px 8px;
                            font-size: 10px;
                        }

                        /* URL bar */
                        .url-bar {
                            background: #fff;
                            border: 1px solid var(--border);
                            border-radius: 14px;
                            padding: 10px 14px;
                            margin-bottom: 14px;
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        }

                        .url-bar-label {
                            display: inline-flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 10px;
                            font-weight: 700;
                            color: var(--muted);
                            text-transform: uppercase;
                        }

                        .url-bar-label svg {
                            width: 12px;
                            height: 12px;
                        }

                        .url-bar input {
                            flex: 1;
                            border: 1.5px solid var(--border);
                            border-radius: 6px;
                            padding: 6px 8px;
                            font-size: 11px;
                            font-family: monospace;
                            background: #f8fafc;
                        }

                        /* Forms */
                        .fg {
                            margin-bottom: 10px;
                        }

                        .fl {
                            font-size: 10px;
                            font-weight: 700;
                            color: var(--muted);
                            text-transform: uppercase;
                            margin-bottom: 3px;
                            display: block;
                            letter-spacing: .3px;
                        }

                        .fi {
                            width: 100%;
                            padding: 7px 9px;
                            border: 1.5px solid var(--border);
                            border-radius: 6px;
                            font-size: 12px;
                            color: var(--navy);
                            box-sizing: border-box;
                        }

                        .fi:focus {
                            border-color: var(--navy);
                            outline: none;
                        }

                        .fgrid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 8px;
                        }

                        /* Cards */
                        .card {
                            background: #fff;
                            border: 1px solid var(--border);
                            border-radius: 12px;
                            padding: 16px;
                            margin-bottom: 14px;
                        }

                        .card-title {
                            font-size: 14px;
                            font-weight: 700;
                            color: var(--navy);
                            margin: 0 0 12px;
                        }

                        /* Reset section */
                        .reset-card {
                            background: #fff;
                            border: 1px solid var(--border);
                            border-radius: 12px;
                            padding: 18px;
                            margin-bottom: 12px;
                        }

                        .reset-card.danger {
                            border-color: #fca5a5;
                            background: #fffbfb;
                        }

                        .reset-icon {
                            width: 36px;
                            height: 36px;
                            border-radius: 10px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 18px;
                            flex-shrink: 0;
                        }

                        /* ═══ Elegant Schedule Calendar (shared: Jadwal Kerja per-karyawan & kalender gabungan) ═══ */
                        .sc-cal-nav {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            background: var(--bg);
                            border: 1px solid var(--border);
                            border-radius: 10px;
                            padding: 8px 10px;
                            margin-bottom: 10px;
                        }

                        .sc-cal-nav-btn {
                            border: none;
                            border-radius: 8px;
                            padding: 7px 14px;
                            font-size: 11px;
                            font-weight: 700;
                            cursor: pointer;
                            background: var(--navy);
                            color: #fff;
                            transition: background .15s ease, transform .1s ease;
                        }

                        .sc-cal-nav-btn:hover {
                            background: var(--blue);
                        }

                        .sc-cal-nav-btn:active {
                            transform: scale(0.96);
                        }

                        .sc-cal-title {
                            font-weight: 800;
                            font-size: 13px;
                            color: var(--navy);
                            letter-spacing: .2px;
                        }

                        .sc-cal-dow {
                            text-align: center;
                            font-size: 10px;
                            font-weight: 800;
                            color: var(--muted);
                            text-transform: uppercase;
                            letter-spacing: .4px;
                            padding: 4px 0 8px;
                        }

                        .sc-cal-cell {
                            border: 1px solid var(--border);
                            border-radius: 10px;
                            padding: 7px 5px;
                            min-height: 58px;
                            cursor: pointer;
                            background: #fff;
                            display: flex;
                            flex-direction: column;
                            gap: 3px;
                            transition: box-shadow .15s ease, transform .1s ease, border-color .15s ease;
                        }

                        .sc-cal-cell:hover {
                            box-shadow: 0 4px 14px rgba(13, 31, 60, .12);
                            border-color: #93c5fd;
                            transform: translateY(-1px);
                        }

                        .sc-cal-cell.today {
                            border-color: var(--blue);
                            background: #eff6ff;
                            box-shadow: 0 0 0 1px var(--blue) inset;
                        }

                        .sc-cal-cell.is-off {
                            background: #fef7f7;
                        }

                        .sc-cal-cell.override {
                            border-style: dashed;
                            border-color: var(--purple);
                            border-width: 2px;
                        }

                        .sc-cal-daynum {
                            font-weight: 800;
                            font-size: 13px;
                            color: var(--navy);
                            line-height: 1;
                            display: flex;
                            align-items: center;
                            gap: 3px;
                        }

                        .sc-cal-cell.today .sc-cal-daynum {
                            color: var(--blue);
                        }

                        .sc-cal-badge {
                            display: inline-flex;
                            align-items: center;
                            gap: 3px;
                            font-size: 9px;
                            font-weight: 700;
                            padding: 2px 6px;
                            border-radius: 20px;
                            width: fit-content;
                            max-width: 100%;
                            overflow: hidden;
                            text-overflow: ellipsis;
                            white-space: nowrap;
                        }

                        .sc-cal-badge-in {
                            background: #d1fae5;
                            color: #065f46;
                        }

                        .sc-cal-badge-off {
                            background: #fee2e2;
                            color: #991b1b;
                        }

                        .sc-cal-badge-uniform {
                            background: #dbeafe;
                            color: #1e40af;
                        }

                        .sc-cal-legend {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 14px;
                            margin-top: 10px;
                            font-size: 10px;
                            color: var(--muted);
                            align-items: center;
                        }

                        /* Alert */
                        .att-alert {
                            padding: 10px 14px;
                            border-radius: 8px;
                            font-size: 12px;
                            font-weight: 600;
                            margin-bottom: 12px;
                        }

                        .att-alert-success {
                            background: #f0fdf4;
                            color: #166534;
                            border: 1px solid #bbf7d0;
                        }

                        .att-alert-error {
                            background: #fee2e2;
                            color: #991b1b;
                            border: 1px solid #fca5a5;
                        }

                        /* Modal */
                        .modal-overlay {
                            position: fixed;
                            inset: 0;
                            background: rgba(0, 0, 0, .5);
                            z-index: 9990;
                            display: none;
                            align-items: center;
                            justify-content: center;
                        }

                        .modal-overlay.open {
                            display: flex;
                        }

                        .modal-box {
                            background: #fff;
                            border-radius: 12px;
                            padding: 20px;
                            max-width: 440px;
                            width: 92%;
                            box-shadow: 0 10px 30px rgba(0, 0, 0, .18);
                            border-top: 3px solid var(--navy);
                            max-height: 90vh;
                            overflow-y: auto;
                        }

                        .modal-title {
                            font-size: 14px;
                            font-weight: 700;
                            color: var(--navy);
                            margin-bottom: 14px;
                        }

                        .modal-actions {
                            display: flex;
                            gap: 8px;
                            margin-top: 14px;
                            justify-content: flex-end;
                        }

                        /* Dash = empty cell */
                        .dash {
                            color: #d1d5db;
                        }

                        @media(max-width:768px) {
                            .st-row {
                                grid-template-columns: repeat(2, 1fr);
                            }

                            .fgrid {
                                grid-template-columns: 1fr;
                            }

                            .att-tabs {
                                flex-wrap: nowrap;
                                overflow-x: auto;
                            }
                        }
                    </style>

                    <?php if ($msg): ?>
                        <div class="att-alert att-alert-<?php echo $msgType; ?>" id="mainAlert"><?php echo $msgType === 'success' ? '' : ''; ?> <?php echo $msg; ?></div>
                    <?php endif; ?>

                    <div class="att-wrap">

                        <!-- Header -->
                        <div class="att-head">
                            <div class="att-head-title">
                                <div class="att-head-icon"><i data-feather="clipboard"></i></div>
                                <div>
                                    <h1>Absensi Karyawan</h1>
                                    <p>Dashboard harian, GPS, Fingerprint, Manual & Reset</p>
                                </div>
                            </div>
                            <div style="display:flex; gap:6px;">
                                <a href="<?php echo htmlspecialchars($staffPortalUrl); ?>" target="_blank" class="btn btn-primary"><i data-feather="smartphone"></i> Staff Portal</a>
                                <button onclick="openManualModal()" class="btn btn-gold"><i data-feather="plus-circle"></i> Input Manual</button>
                            </div>
                        </div>

                        <!-- URL bar -->
                        <div class="url-bar">
                            <span class="url-bar-label"><i data-feather="link"></i> Staff Portal</span>
                            <input type="text" value="<?php echo htmlspecialchars($staffPortalUrl); ?>" readonly id="portalUrlInput">
                            <button onclick="copyUrl('portalUrlInput')" class="btn btn-primary btn-sm"><i data-feather="copy"></i> Salin</button>
                        </div>

                        <!-- Stats -->
                        <div class="st-row">
                            <div class="st-card">
                                <div class="st-icon"><i data-feather="user-check"></i></div>
                                <div class="lb">Hadir</div>
                                <div class="vl"><?php echo $todayStats['present']; ?>/<?php echo $todayStats['total']; ?></div>
                                <div class="sb"><?php echo $todayStats['total'] > 0 ? round($todayStats['present'] / $todayStats['total'] * 100) : 0; ?>% kehadiran</div>
                            </div>
                            <div class="st-card">
                                <div class="st-icon"><i data-feather="clock"></i></div>
                                <div class="lb">Terlambat</div>
                                <div class="vl"><?php echo $todayStats['late']; ?></div>
                                <div class="sb">dari yang hadir</div>
                            </div>
                            <div class="st-card">
                                <div class="st-icon"><i data-feather="bar-chart-2"></i></div>
                                <div class="lb">Total Jam</div>
                                <div class="vl"><?php echo number_format($todayStats['total_hours'], 1); ?></div>
                                <div class="sb"><?php echo number_format($todayStats['regular_hours'], 1); ?>j reguler</div>
                            </div>
                            <div class="st-card">
                                <div class="st-icon"><i data-feather="zap"></i></div>
                                <div class="lb">Lembur</div>
                                <div class="vl"><?php echo number_format($todayStats['overtime_hours'], 1); ?>j</div>
                                <div class="sb"><?php echo $todayStats['ot_count']; ?> staff lembur</div>
                            </div>
                            <div class="st-card">
                                <div class="st-icon"><i data-feather="user-x"></i></div>
                                <div class="lb">Belum Absen</div>
                                <div class="vl"><?php echo max(0, $todayStats['total'] - $todayStats['present']); ?></div>
                                <div class="sb">perlu perhatian</div>
                            </div>
                        </div>

                        <!-- ═══ TABS ═══ -->
                        <div class="att-tabs">
                            <button class="att-tab active" data-tab="dashboard"><span class="att-tab-icon"><i data-feather="grid"></i></span> Dashboard Harian</button>
                            <button class="att-tab" data-tab="gps"><span class="att-tab-icon"><i data-feather="map-pin"></i></span> Absen GPS</button>
                            <button class="att-tab" data-tab="fingerprint"><span class="att-tab-icon"><i data-feather="shield"></i></span> Fingerprint</button>
                            <button class="att-tab" data-tab="cuti"><span class="att-tab-icon"><i data-feather="sun"></i></span> Cuti<?php if ($pendingLeaves > 0): ?> <span style="background:var(--red);color:#fff;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;"><?php echo $pendingLeaves; ?></span><?php endif; ?></button>
                            <button class="att-tab" data-tab="lembur"><span class="att-tab-icon"><i data-feather="zap"></i></span> Lembur<?php if ($pendingOT > 0): ?> <span style="background:var(--red);color:#fff;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;"><?php echo $pendingOT; ?></span><?php endif; ?></button>
                            <button class="att-tab" data-tab="manual"><span class="att-tab-icon"><i data-feather="edit-3"></i></span> Manual</button>
                            <button class="att-tab" data-tab="schedule"><span class="att-tab-icon"><i data-feather="calendar"></i></span> Jadwal Kerja</button>
                            <button class="att-tab" data-tab="uniform"><span class="att-tab-icon"><i data-feather="shopping-bag"></i></span> Jadwal Seragam</button>
                            <button class="att-tab" data-tab="reset"><span class="att-tab-icon"><i data-feather="refresh-cw"></i></span> Reset</button>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: DASHBOARD HARIAN                  -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-dashboard">
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                                <form method="GET" style="display:flex; gap:6px; align-items:center;">
                                    <input type="date" name="date" value="<?php echo $viewDate; ?>" class="fi" style="width:150px;">
                                    <button type="submit" class="btn btn-gold">Tampilkan</button>
                                </form>
                                <span style="font-size:12px; color:var(--muted);"><?php echo date('l, d F Y', strtotime($viewDate)); ?></span>
                            </div>

                            <div class="tbl-wrap">
                                <table class="tbl">
                                    <thead>
                                        <tr>
                                            <th>Karyawan</th>
                                            <th style="text-align:center;">Scan 1<br><span style="font-weight:400;font-size:11px;text-transform:none;">Masuk</span></th>
                                            <th style="text-align:center;">Scan 2<br><span style="font-weight:400;font-size:11px;text-transform:none;">Pulang</span></th>
                                            <th style="text-align:center;">Scan 3<br><span style="font-weight:400;font-size:11px;text-transform:none;">Masuk Shift 2</span></th>
                                            <th style="text-align:center;">Scan 4<br><span style="font-weight:400;font-size:11px;text-transform:none;">Pulang Shift 2</span></th>
                                            <th style="text-align:center;">Total<br><span style="font-weight:400;font-size:11px;text-transform:none;">Jam</span></th>
                                            <th style="text-align:center;">Lembur<br><span style="font-weight:400;font-size:11px;text-transform:none;">>45 menit</span></th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $attById = [];
                                        foreach ($dailyAtt as $a) $attById[$a['employee_id']] = $a;
                                        $dash = '<span class="dash">—</span>';

                                        foreach ($employees as $emp):
                                            $a = $attById[$emp['id']] ?? null;
                                            $rawStatus = $a ? $a['status'] : 'notyet';
                                            $status = ($a && isSplitShiftCheckInTime($a['check_in_time'] ?? null) && $rawStatus === 'late') ? 'split_shift' : $rawStatus;
                                            $statusLabels = ['present' => 'Hadir', 'late' => 'Terlambat', 'split_shift' => 'Split Shift', 'absent' => 'Absen', 'leave' => 'Izin', 'holiday' => 'Libur', 'half_day' => '½ Hari', 'notyet' => 'Belum'];
                                            $statusClass = ($status === 'split_shift') ? 'present' : $status;
                                            $s1 = $a && $a['check_in_time'] ? substr($a['check_in_time'], 0, 5) : null;
                                            $s2 = $a && $a['check_out_time'] ? substr($a['check_out_time'], 0, 5) : null;
                                            $s3 = $a && !empty($a['scan_3']) ? substr($a['scan_3'], 0, 5) : null;
                                            $s4 = $a && !empty($a['scan_4']) ? substr($a['scan_4'], 0, 5) : null;
                                            $wh = (float)($a['work_hours'] ?? 0);
                                            $manualOT = (float)($a['overtime_hours'] ?? 0);
                                            // Manual OT takes precedence; otherwise approved request shows actual overtime above 8 hours.
                                            $hasApprovedOT = isset($approvedOTEmployees[(int)$emp['id']]);
                                            $otCounted = $manualOT > 0 ? $manualOT : ($hasApprovedOT ? max(0, round($wh - 8, 2)) : 0);
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong style="font-size:12px;"><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                                                    <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($emp['employee_code']); ?> · <?php echo htmlspecialchars($emp['position']); ?></div>
                                                </td>
                                                <td style="text-align:center; font-weight:700; color:var(--green);"><?php echo $s1 ?: $dash; ?></td>
                                                <td style="text-align:center; font-weight:700; color:var(--navy);"><?php echo $s2 ?: $dash; ?></td>
                                                <td style="text-align:center; font-weight:700; color:var(--green);"><?php echo $s3 ?: $dash; ?></td>
                                                <td style="text-align:center; font-weight:700; color:var(--navy);"><?php echo $s4 ?: $dash; ?></td>
                                                <td style="text-align:center;">
                                                    <?php if ($wh > 0): ?>
                                                        <strong style="font-size:14px;"><?php echo number_format($wh, 1); ?></strong><span style="font-size:10px;color:var(--muted);"> jam</span>
                                                    <?php else: echo $dash;
                                                    endif; ?>
                                                </td>
                                                <td style="text-align:center;">
                                                    <?php if ($otCounted > 0): ?>
                                                        <span style="background:#ede9fe; color:var(--purple); padding:2px 8px; border-radius:4px; font-weight:700; font-size:12px;">+<?php echo number_format($otCounted, 1); ?>j</span>
                                                        <div style="font-size:9px; color:var(--muted);"><?php echo $otUnits; ?>×45m</div>
                                                    <?php elseif ($wh > 0): ?>
                                                        <span style="color:#d1d5db; font-size:11px;">0</span>
                                                    <?php else: echo $dash;
                                                    endif; ?>
                                                </td>
                                                <td><span class="badge b-<?php echo $statusClass; ?>"><?php echo $statusLabels[$status] ?? $status; ?></span></td>
                                                <td style="white-space:nowrap;">
                                                    <?php if ($a): ?>
                                                        <button class="btn btn-edit btn-sm" onclick='openEditModal(<?php echo json_encode($a); ?>)'>✏️</button>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus absen <?php echo htmlspecialchars($emp['full_name']); ?>?')">
                                                            <input type="hidden" name="action" value="delete_att">
                                                            <input type="hidden" name="att_id" value="<?php echo $a['id']; ?>">
                                                            <button type="submit" class="btn btn-del btn-sm">🗑</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button class="btn btn-green btn-sm" onclick="quickManualAdd(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>', '<?php echo $viewDate; ?>')">➕</button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Legend -->
                            <div style="padding:10px 14px; background:#f8fafc; border-radius:8px; border:1px solid var(--border); display:flex; gap:16px; flex-wrap:wrap; font-size:11px; color:var(--muted);">
                                <span>📌 <strong>Reguler:</strong> max 8 jam/hari</span>
                                <span>🔥 <strong>Lembur:</strong> hanya jika diajukan & disetujui, per kelipatan 45 menit</span>
                                <span>🕐 <strong>Scan:</strong> 1=Masuk, 2=Pulang, 3=Masuk Shift2, 4=Pulang Shift2</span>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: ABSEN GPS (Settings & Locations)  -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-gps" style="display:none;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start;">
                                <div>
                                    <!-- Logo -->
                                    <div class="card">
                                        <div class="card-title">🖼️ Logo Aplikasi Absen</div>
                                        <?php if (!empty($config['app_logo'])):
                                            $logoUrl = (strpos($config['app_logo'], 'http') === 0) ? $config['app_logo'] : $baseUrl . '/' . htmlspecialchars($config['app_logo']);
                                        ?>
                                            <div style="margin-bottom:10px; display:flex; align-items:center; gap:10px;">
                                                <img src="<?php echo $logoUrl; ?>" style="height:50px; max-width:140px; object-fit:contain; border-radius:6px; border:1px solid #eee; padding:3px;">
                                                <form method="POST" action="?tab=gps" style="margin:0;">
                                                    <input type="hidden" name="action" value="save_logo"><input type="hidden" name="remove_logo" value="1">
                                                    <button type="submit" class="btn btn-del btn-sm">🗑️ Hapus</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <p style="font-size:11px; color:var(--muted); margin:0 0 8px;">Belum ada logo. Upload untuk ditampilkan di halaman absen.</p>
                                        <?php endif; ?>
                                        <form method="POST" action="?tab=gps" enctype="multipart/form-data">
                                            <input type="hidden" name="action" value="save_logo">
                                            <input type="file" name="logo_file" accept=".png,.jpg,.jpeg,.svg,.webp" class="fi" style="padding:5px; font-size:11px;">
                                            <div style="font-size:9px; color:var(--muted); margin:3px 0 6px;">PNG, JPG, SVG, WebP · Rekomendasi: 200×60 px</div>
                                            <button type="submit" class="btn btn-primary" style="width:100%;">📤 Upload Logo</button>
                                        </form>
                                    </div>

                                    <!-- Time Settings -->
                                    <div class="card">
                                        <div class="card-title">🕐 Pengaturan Waktu</div>
                                        <form method="POST" action="?tab=gps">
                                            <input type="hidden" name="action" value="save_config">
                                            <div class="fgrid">
                                                <div class="fg"><label class="fl">Jam Masuk (Awal)</label><input type="time" name="checkin_start" class="fi" value="<?php echo $config['checkin_start'] ?? '07:00'; ?>"></div>
                                                <div class="fg"><label class="fl">Batas Terlambat</label><input type="time" name="checkin_end" class="fi" value="<?php echo $config['checkin_end'] ?? '10:00'; ?>">
                                                    <div style="font-size:9px;color:var(--muted);margin-top:2px;">Setelah jam ini = Terlambat</div>
                                                </div>
                                            </div>
                                            <div class="fg"><label class="fl">Checkout Mulai Jam</label><input type="time" name="checkout_start" class="fi" value="<?php echo $config['checkout_start'] ?? '16:00'; ?>"></div>
                                            <div class="fgrid">
                                                <div class="fg"><label class="fl">Target Jam Kerja / Bulan</label><input type="number" name="monthly_target_hours" class="fi" min="1" step="1" value="<?php echo (int)($config['monthly_target_hours'] ?? 208); ?>">
                                                    <div style="font-size:9px;color:var(--muted);margin-top:2px;">Dipakai sbg pembagi gaji pokok (Base ÷ Target)</div>
                                                </div>
                                                <div class="fg"><label class="fl">Threshold Lembur (menit)</label><input type="number" name="ot_round_minutes" class="fi" min="1" step="1" value="<?php echo (int)($config['ot_round_minutes'] ?? 45); ?>">
                                                    <div style="font-size:9px;color:var(--muted);margin-top:2px;">Menit minimum sebelum dibulatkan ke 1 jam lembur</div>
                                                </div>
                                            </div>
                                            <div class="fg" style="display:flex; align-items:center; gap:6px;">
                                                <input type="checkbox" name="allow_outside" id="allowOut" <?php echo ($config['allow_outside'] ?? 0) ? 'checked' : ''; ?>>
                                                <label for="allowOut" style="font-size:11px;">Izinkan absen di luar radius</label>
                                            </div>
                                            <button type="submit" class="btn btn-primary" style="width:100%;">💾 Simpan Waktu</button>
                                        </form>
                                    </div>

                                    <!-- Locations -->
                                    <div class="card">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                            <div class="card-title" style="margin:0;">📍 Lokasi Proyek</div>
                                            <button onclick="openLocModal()" class="btn btn-gold btn-sm">➕ Tambah</button>
                                        </div>
                                        <?php if (empty($locations)): ?>
                                            <div style="text-align:center; padding:20px; color:var(--muted); font-size:12px;">Belum ada lokasi.</div>
                                        <?php else: ?>
                                            <?php foreach ($locations as $loc): ?>
                                                <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px 12px; margin-bottom:6px; <?php echo $loc['is_active'] ? '' : 'opacity:.5;'; ?>">
                                                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                                        <div>
                                                            <div style="font-size:12px; font-weight:700; color:var(--navy);"><?php echo $loc['is_active'] ? '🟢' : '⚫'; ?> <?php echo htmlspecialchars($loc['location_name']); ?></div>
                                                            <?php if ($loc['address']): ?><div style="font-size:10px; color:var(--muted); margin-top:1px;"><?php echo htmlspecialchars($loc['address']); ?></div><?php endif; ?>
                                                            <div style="font-size:10px; color:var(--muted); margin-top:2px; font-family:monospace;"><?php echo number_format((float)$loc['lat'], 7); ?>, <?php echo number_format((float)$loc['lng'], 7); ?> · <?php echo $loc['radius_m']; ?>m</div>
                                                        </div>
                                                        <div style="display:flex; gap:3px;">
                                                            <button class="btn btn-edit btn-sm" onclick='openLocModal(<?php echo json_encode($loc); ?>)'>✏️</button>
                                                            <form method="POST" action="?tab=gps" style="display:inline;" onsubmit="return confirm('Hapus lokasi?')">
                                                                <input type="hidden" name="action" value="delete_location">
                                                                <input type="hidden" name="loc_id" value="<?php echo $loc['id']; ?>">
                                                                <button type="submit" class="btn btn-del btn-sm">🗑</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Map -->
                                <div class="card" style="position:sticky; top:10px;">
                                    <div class="card-title">🗺️ Peta Lokasi</div>
                                    <div id="adminMap" style="height:350px; border-radius:8px; border:1px solid var(--border);"></div>
                                    <div style="font-size:10px; color:var(--muted); margin-top:4px;">Semua lokasi aktif ditampilkan di peta.</div>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: FINGERPRINT                       -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-fingerprint" style="display:none;">

                            <!-- Fingerspot Settings -->
                            <div class="card">
                                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                                    <div class="reset-icon" style="background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;">🔐</div>
                                    <div style="flex:1;">
                                        <div class="card-title" style="margin:0;">Fingerspot.io Integration</div>
                                        <div style="font-size:10px; color:var(--muted);">Revo N830 via Fingerspot.io cloud</div>
                                        <?php if ($fpEnabled && $fpCloudId && $fpCloudStatus): ?>
                                            <div style="margin-top:4px; font-size:11px;">
                                                <strong>Status Cloud:</strong>
                                                <?php if ($fpCloudStatus['success']): ?>
                                                    <span style="color:#059669; font-weight:700;">✅ <?php echo htmlspecialchars($fpCloudStatus['message'] ?? 'Aktif'); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#dc2626; font-weight:700;">⚠️ <?php echo htmlspecialchars($fpCloudStatus['message'] ?? 'Tidak aktif'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($fpEnabled): ?>
                                        <span style="background:#d1fae5; color:#065f46; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700;">✅ Aktif</span>
                                    <?php else: ?>
                                        <span style="background:#fee2e2; color:#991b1b; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700;">⏸ Non-aktif</span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" action="?tab=fingerprint">
                                    <input type="hidden" name="action" value="save_fingerspot">
                                    <div class="fg">
                                        <label class="fl">Cloud ID Mesin</label>
                                        <input type="text" name="fingerspot_cloud_id" class="fi" value="<?php echo htmlspecialchars($fpCloudId); ?>" placeholder="Cloud ID dari Fingerspot.io">
                                        <div style="font-size:9px; color:var(--muted); margin-top:2px;">Lihat di dashboard Fingerspot.io → Device → Cloud ID</div>
                                    </div>
                                    <div class="fg">
                                        <label class="fl">API Token</label>
                                        <input type="password" name="fingerspot_token" class="fi" value="<?php echo htmlspecialchars($fpToken); ?>" placeholder="API Token dari Fingerspot.io">
                                        <div style="font-size:9px; color:var(--muted); margin-top:2px;">Diperlukan untuk sync data. Lihat di Settings → API</div>
                                    </div>
                                    <div class="fg" style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
                                        <input type="checkbox" name="fingerspot_enabled" id="fpOn" <?php echo $fpEnabled ? 'checked' : ''; ?>>
                                        <label for="fpOn" style="font-size:11px; font-weight:600;">Aktifkan integrasi Fingerspot</label>
                                    </div>
                                    <button type="submit" class="btn btn-primary" style="width:100%;">💾 Simpan Fingerspot</button>
                                </form>

                                <!-- Sync Data Section -->
                                <?php if ($fpEnabled && $fpCloudId && $fpToken): ?>
                                    <div style="margin-top:14px; padding-top:14px; border-top:1px dashed var(--border);">
                                        <div style="font-size:11px; font-weight:700; color:#7c3aed; margin-bottom:8px;">📥 Tarik Data Absensi dari Fingerspot API</div>
                                        <form method="POST" action="?tab=fingerprint" id="fpSyncForm" onsubmit="return startFpSync(this)">
                                            <input type="hidden" name="action" value="sync_fingerspot">
                                            <div style="display:flex; gap:8px; margin-bottom:8px;">
                                                <div style="flex:1;">
                                                    <label style="font-size:9px; font-weight:600; color:var(--muted);">Dari Tanggal</label>
                                                    <input type="date" name="sync_from" id="syncFrom" class="fi" value="<?php echo date('Y-m-01'); ?>" style="font-size:11px;">
                                                </div>
                                                <div style="flex:1;">
                                                    <label style="font-size:9px; font-weight:600; color:var(--muted);">Sampai Tanggal</label>
                                                    <input type="date" name="sync_to" id="syncTo" class="fi" value="<?php echo date('Y-m-d'); ?>" style="font-size:11px;">
                                                </div>
                                            </div>
                                            <button type="submit" id="fpSyncBtn" class="btn" style="width:100%; background:linear-gradient(135deg,#7c3aed,#a855f7); color:#fff; border:none; font-size:13px; padding:11px; font-weight:800; justify-content:center;">
                                                🔄 Tarik & Proses Data Fingerspot
                                            </button>
                                            <div style="font-size:9px; color:var(--muted); margin-top:4px; text-align:center;">
                                                Tarik data dari Fingerspot API → langsung masuk ke absensi & jam kerja
                                            </div>
                                        </form>
                                        <!-- Loading Overlay Sync -->
                                        <div id="fpSyncOverlay" style="display:none; position:fixed; inset:0; background:rgba(13,31,60,.75); z-index:99999; align-items:center; justify-content:center;">
                                            <div style="background:#fff; border-radius:18px; padding:32px 36px; max-width:400px; width:92%; box-shadow:0 24px 80px rgba(0,0,0,.35); text-align:center; border-top:5px solid #7c3aed;">
                                                <div style="margin:0 auto 18px; width:56px; height:56px; border-radius:50%; border:5px solid #f1f5f9; border-top-color:#7c3aed; animation:fpSpin 0.9s linear infinite;"></div>
                                                <div style="font-size:16px; font-weight:800; color:#0d1f3c; margin-bottom:4px;">Menarik Data dari Fingerspot</div>
                                                <div id="fpSyncPeriod" style="font-size:11px; color:#64748b; margin-bottom:20px;"></div>
                                                <div style="text-align:left; background:#f8fafc; border-radius:10px; padding:14px 16px; border:1px solid #e2e8f0;">
                                                    <div id="syncStep1" class="fp-step fp-step-wait"><span class="fp-step-icon">⏳</span><span>Menghubungi server Fingerspot API</span></div>
                                                    <div id="syncStep2" class="fp-step fp-step-wait"><span class="fp-step-icon">⏳</span><span>Mengambil data scan per periode (2 hari/chunk)</span></div>
                                                    <div id="syncStep3" class="fp-step fp-step-wait"><span class="fp-step-icon">⏳</span><span>Mencocokkan PIN ke data karyawan</span></div>
                                                    <div id="syncStep4" class="fp-step fp-step-wait"><span class="fp-step-icon">⏳</span><span>Menulis scan masuk/pulang ke absensi</span></div>
                                                    <div id="syncStep5" class="fp-step fp-step-wait"><span class="fp-step-icon">⏳</span><span>Menghitung jam kerja & total lembur</span></div>
                                                </div>
                                                <div style="font-size:10px; color:#94a3b8; margin-top:14px;">Harap tunggu — proses ini bisa memakan waktu 10–30 detik</div>
                                            </div>
                                        </div>
                                        <script>
                                            function startFpSync(form) {
                                                var from = document.getElementById('syncFrom').value;
                                                var to = document.getElementById('syncTo').value;
                                                if (!from || !to) return true;
                                                document.getElementById('fpSyncOverlay').style.display = 'flex';
                                                document.getElementById('fpSyncPeriod').textContent = 'Periode: ' + from + ' s/d ' + to;
                                                document.getElementById('fpSyncBtn').disabled = true;
                                                document.getElementById('fpSyncBtn').textContent = '⏳ Menarik data...';
                                                var steps = ['syncStep1', 'syncStep2', 'syncStep3', 'syncStep4', 'syncStep5'];
                                                var delays = [0, 1200, 2400, 4000, 6000];
                                                steps.forEach(function(id, i) {
                                                    setTimeout(function() {
                                                        if (i > 0) {
                                                            var prev = document.getElementById(steps[i - 1]);
                                                            prev.className = 'fp-step fp-step-done';
                                                            prev.querySelector('.fp-step-icon').textContent = '✅';
                                                        }
                                                        var cur = document.getElementById(id);
                                                        cur.className = 'fp-step fp-step-active';
                                                        cur.querySelector('.fp-step-icon').textContent = '🔄';
                                                    }, delays[i]);
                                                });
                                                setTimeout(function() {
                                                    form.submit();
                                                }, 200);
                                                return false;
                                            }
                                        </script>
                                    </div>
                                <?php elseif ($fpEnabled && $fpCloudId): ?>
                                    <div style="margin-top:14px; padding:12px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; font-size:11px; color:#92400e;">
                                        <strong>⚠️ API Token belum diisi</strong><br>
                                        <span style="font-size:10px;">Isi kolom <strong>API Token</strong> di atas untuk mengaktifkan Tarik Data dari Fingerspot. Token bisa ditemukan di dashboard Fingerspot.io → Settings → API.</span>
                                    </div>
                                <?php elseif ($fpEnabled): ?>
                                    <div style="margin-top:14px; padding:12px; background:#fee2e2; border:1px solid #fca5a5; border-radius:8px; font-size:11px; color:#991b1b;">
                                        <strong>⚠️ Cloud ID & API Token belum diisi</strong><br>
                                        <span style="font-size:10px;">Isi Cloud ID dan API Token di atas untuk menghubungkan ke mesin fingerprint.</span>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top:14px; padding:12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:11px; color:#475569;">
                                        ⓘ Aktifkan integrasi Fingerspot terlebih dahulu untuk menggunakan fitur tarik data.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Webhook Log -->
                            <div class="card">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <div class="card-title" style="margin:0;">📜 Webhook Log</div>
                                    <span style="font-size:10px; color:var(--muted);">20 terbaru</span>
                                </div>
                                <?php if (empty($fpLogs)): ?>
                                    <div style="text-align:center; padding:24px; color:var(--muted); font-size:12px;">📭 Belum ada log. Log muncul setelah mesin mengirim data.</div>
                                <?php else: ?>
                                    <div style="overflow-x:auto;">
                                        <table class="tbl" style="font-size:10px;">
                                            <thead>
                                                <tr>
                                                    <th>Waktu</th>
                                                    <th>Cloud ID</th>
                                                    <th>PIN</th>
                                                    <th>Karyawan</th>
                                                    <th>Scan</th>
                                                    <th>Status</th>
                                                    <th>Hasil</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($fpLogs as $log): ?>
                                                    <tr>
                                                        <td style="white-space:nowrap;"><?php echo date('d/m H:i:s', strtotime($log['created_at'])); ?></td>
                                                        <td><code style="font-size:9px;"><?php echo htmlspecialchars($log['cloud_id'] ?? '-'); ?></code></td>
                                                        <td><code><?php echo htmlspecialchars($log['pin'] ?? '-'); ?></code></td>
                                                        <td><?php echo htmlspecialchars($log['emp_name'] ?? '-'); ?></td>
                                                        <td style="white-space:nowrap;"><?php echo $log['scan_time'] ? date('d/m H:i', strtotime($log['scan_time'])) : '-'; ?></td>
                                                        <td><?php echo $log['processed'] ? '<span style="color:var(--green); font-weight:700;">✅</span>' : '<span style="color:var(--red); font-weight:700;">❌</span>'; ?></td>
                                                        <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($log['process_result'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($log['process_result'] ?? '-'); ?>
                                                            <?php if (!empty($log['raw_data'])): ?>
                                                                <button onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'" style="background:none;border:none;cursor:pointer;font-size:9px;padding:0 2px;">📋</button>
                                                                <pre style="display:none; font-size:8px; background:#f1f5f9; padding:4px; border-radius:3px; margin-top:3px; white-space:pre-wrap; word-break:break-all; max-width:250px;"><?php echo htmlspecialchars(substr($log['raw_data'], 0, 500)); ?></pre>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- ═══ PROSES LOG → ABSENSI & GAJI ═══ -->
                            <div class="card" style="border-left:4px solid var(--gold);">
                                <div style="display:flex; align-items:center; gap:12px; margin-bottom:14px;">
                                    <div class="reset-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; width:44px; height:44px; font-size:22px;">⚡</div>
                                    <div>
                                        <div class="card-title" style="margin:0; font-size:14px;">Proses Log → Absensi & Total Jam Kerja</div>
                                        <div style="font-size:10px; color:var(--muted);">Konversi log fingerprint ke data absensi, lalu lanjut hitung gaji otomatis</div>
                                    </div>
                                </div>

                                <!-- Status unprocessed -->
                                <?php if ($fpUnprocessed > 0): ?>
                                    <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:18px;">⚠️</span>
                                        <div>
                                            <div style="font-size:12px; font-weight:700; color:#92400e;"><?= $fpUnprocessed ?> log belum diproses ke absensi</div>
                                            <div style="font-size:10px; color:#a16207;">Total log bulan ini: <?= $fpThisMonth ?> scan</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:18px;">✅</span>
                                        <div style="font-size:12px; font-weight:700; color:#166534;">Semua log sudah diproses ke absensi · <?= $fpThisMonth ?> scan bulan ini</div>
                                    </div>
                                <?php endif; ?>

                                <!-- Form proses batch -->
                                <form method="POST" action="?tab=fingerprint" id="fpBatchForm" onsubmit="return startFpProcess(this)">
                                    <input type="hidden" name="action" value="process_finger_batch">
                                    <div class="fgrid" style="margin-bottom:10px;">
                                        <div class="fg">
                                            <label class="fl">Dari Tanggal</label>
                                            <input type="date" name="fp_from" id="fpFrom" class="fi" value="<?= date('Y-m-01') ?>" required>
                                        </div>
                                        <div class="fg">
                                            <label class="fl">Sampai Tanggal</label>
                                            <input type="date" name="fp_to" id="fpTo" class="fi" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                    </div>
                                    <button type="submit" id="fpBatchBtn" class="btn btn-gold" style="width:100%; font-size:13px; padding:11px; font-weight:800; justify-content:center;">
                                        ⚡ Proses Log Fingerprint ke Absensi
                                    </button>
                                    <div style="font-size:10px; color:var(--muted); margin-top:5px; text-align:center;">Scan 1=Masuk · Scan 2=Pulang · Scan 3=Masuk Shift2 · Scan 4=Pulang Shift2 · Duplikat &lt;5 menit diabaikan</div>
                                </form>

                                <!-- Loading Overlay -->
                                <div id="fpLoadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(13,31,60,.75); z-index:99999; align-items:center; justify-content:center;">
                                    <div style="background:#fff; border-radius:18px; padding:32px 36px; max-width:400px; width:92%; box-shadow:0 24px 80px rgba(0,0,0,.35); text-align:center; border-top:5px solid #f0b429;">
                                        <!-- Spinner -->
                                        <div style="margin:0 auto 18px; width:56px; height:56px; border-radius:50%; border:5px solid #f1f5f9; border-top-color:#f0b429; animation:fpSpin 0.9s linear infinite;"></div>
                                        <div style="font-size:16px; font-weight:800; color:#0d1f3c; margin-bottom:4px;">Memproses Data Fingerprint</div>
                                        <div id="fpLoadingPeriod" style="font-size:11px; color:#64748b; margin-bottom:20px;"></div>

                                        <!-- Step tracker -->
                                        <div style="text-align:left; background:#f8fafc; border-radius:10px; padding:14px 16px; border:1px solid #e2e8f0;">
                                            <div id="fpStep1" class="fp-step fp-step-wait">
                                                <span class="fp-step-icon">⏳</span>
                                                <span>Membaca log fingerprint dari database</span>
                                            </div>
                                            <div id="fpStep2" class="fp-step fp-step-wait">
                                                <span class="fp-step-icon">⏳</span>
                                                <span>Mencocokkan PIN karyawan</span>
                                            </div>
                                            <div id="fpStep3" class="fp-step fp-step-wait">
                                                <span class="fp-step-icon">⏳</span>
                                                <span>Menulis data ke tabel absensi</span>
                                            </div>
                                            <div id="fpStep4" class="fp-step fp-step-wait">
                                                <span class="fp-step-icon">⏳</span>
                                                <span>Menghitung total jam kerja & lembur</span>
                                            </div>
                                            <div id="fpStep5" class="fp-step fp-step-wait">
                                                <span class="fp-step-icon">⏳</span>
                                                <span>Menyimpan hasil ke payroll...</span>
                                            </div>
                                        </div>
                                        <div style="font-size:10px; color:#94a3b8; margin-top:14px;">Harap tunggu, jangan tutup halaman ini</div>
                                    </div>
                                </div>

                                <style>
                                    @keyframes fpSpin {
                                        to {
                                            transform: rotate(360deg);
                                        }
                                    }

                                    .fp-step {
                                        display: flex;
                                        align-items: center;
                                        gap: 8px;
                                        padding: 5px 0;
                                        font-size: 11px;
                                        font-weight: 600;
                                        color: #94a3b8;
                                        transition: all .3s;
                                    }

                                    .fp-step-active {
                                        color: #0d1f3c;
                                    }

                                    .fp-step-done {
                                        color: #059669;
                                    }

                                    .fp-step-icon {
                                        font-size: 14px;
                                        width: 20px;
                                        text-align: center;
                                    }
                                </style>

                                <script>
                                    function startFpProcess(form) {
                                        var from = document.getElementById('fpFrom').value;
                                        var to = document.getElementById('fpTo').value;
                                        if (!from || !to) return true;
                                        if (!confirm('Proses semua log fingerprint periode ' + from + ' s/d ' + to + ' ke data absensi?\n\nLog yang belum diproses akan dikonversi ke scan masuk/pulang karyawan.')) return false;

                                        // Show overlay
                                        var overlay = document.getElementById('fpLoadingOverlay');
                                        overlay.style.display = 'flex';
                                        document.getElementById('fpLoadingPeriod').textContent = 'Periode: ' + from + ' s/d ' + to;

                                        // Disable button
                                        var btn = document.getElementById('fpBatchBtn');
                                        btn.disabled = true;
                                        btn.textContent = '⏳ Sedang memproses...';

                                        // Animate steps sequentially
                                        var steps = ['fpStep1', 'fpStep2', 'fpStep3', 'fpStep4', 'fpStep5'];
                                        var delays = [0, 800, 1600, 2500, 3400];
                                        steps.forEach(function(id, i) {
                                            setTimeout(function() {
                                                // Mark previous as done
                                                if (i > 0) {
                                                    var prev = document.getElementById(steps[i - 1]);
                                                    prev.className = 'fp-step fp-step-done';
                                                    prev.querySelector('.fp-step-icon').textContent = '✅';
                                                }
                                                // Mark current as active
                                                var cur = document.getElementById(id);
                                                cur.className = 'fp-step fp-step-active';
                                                cur.querySelector('.fp-step-icon').textContent = '🔄';
                                            }, delays[i]);
                                        });

                                        // Submit form after brief delay so overlay renders
                                        setTimeout(function() {
                                            form.submit();
                                        }, 200);
                                        return false;
                                    }
                                </script>

                                <!-- Link ke proses gaji -->
                                <div style="border-top:1px solid var(--border); margin-top:16px; padding-top:14px;">
                                    <div style="font-size:11px; font-weight:700; color:var(--navy); margin-bottom:8px;">Setelah absensi diproses, lanjut hitung gaji:</div>
                                    <div style="background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:10px 12px; margin-bottom:10px; font-size:11px; color:var(--muted);">
                                        💡 <strong>Total jam kerja</strong> dihitung otomatis dari scan masuk–pulang fingerprint.<br>
                                        Gaji proporsional: <em>jam_kerja ÷ 208 × gaji_pokok</em> · Lembur dihitung per 45 menit
                                    </div>
                                    <div style="display:flex; gap:8px;">
                                        <a href="process.php?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-primary" style="flex:1; justify-content:center; font-size:12px; padding:10px;">
                                            💰 Proses Gaji <?= date('F Y') ?>
                                        </a>
                                        <a href="process.php" class="btn" style="background:#ede9fe; color:var(--purple); font-size:12px; padding:10px;">
                                            📋 Pilih Bulan Lain
                                        </a>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: CUTI (Leave Requests Approval)    -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-cuti" style="display:none;">
                            <?php if ($pendingLeaves > 0): ?>
                                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#92400e; display:flex; align-items:center; gap:8px;">
                                    <span style="font-size:20px;">⏳</span>
                                    <div><strong><?php echo $pendingLeaves; ?> pengajuan</strong> menunggu persetujuan</div>
                                </div>
                            <?php endif; ?>

                            <?php if (empty($leaveRequests)): ?>
                                <div style="text-align:center; padding:40px; color:var(--muted);">
                                    <div style="font-size:40px; margin-bottom:8px;">🏖️</div>
                                    <div style="font-size:14px; font-weight:600;">Belum ada pengajuan cuti</div>
                                    <div style="font-size:11px; margin-top:4px;">Staff mengajukan cuti via Staff Portal</div>
                                </div>
                            <?php else: ?>
                                <div class="tbl-wrap">
                                    <table class="tbl">
                                        <thead>
                                            <tr>
                                                <th>Karyawan</th>
                                                <th>Jenis</th>
                                                <th>Tanggal</th>
                                                <th>Durasi</th>
                                                <th>Alasan</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $typeLabels = ['cuti' => '🏖️ Cuti', 'sakit' => '🩺 Sakit', 'izin' => '📋 Izin', 'cuti_khusus' => '⭐ Khusus'];
                                            foreach ($leaveRequests as $lr):
                                                $days = (int)((strtotime($lr['end_date']) - strtotime($lr['start_date'])) / 86400) + 1;
                                                $statusCls = ['pending' => 'b-late', 'approved' => 'b-present', 'rejected' => 'b-absent'];
                                                $statusLbl = ['pending' => '⏳ Pending', 'approved' => '✅ Disetujui', 'rejected' => '❌ Ditolak'];
                                            ?>
                                                <tr style="<?php echo $lr['status'] === 'pending' ? 'background:#fffbeb;' : ''; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($lr['full_name'] ?? 'Unknown'); ?></strong>
                                                        <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($lr['employee_code'] ?? ''); ?></div>
                                                    </td>
                                                    <td style="font-size:11px; white-space:nowrap;"><?php echo $typeLabels[$lr['leave_type']] ?? $lr['leave_type']; ?></td>
                                                    <td style="font-size:11px; white-space:nowrap;">
                                                        <?php echo date('d M', strtotime($lr['start_date'])); ?> — <?php echo date('d M Y', strtotime($lr['end_date'])); ?>
                                                    </td>
                                                    <td style="text-align:center; font-weight:700;"><?php echo $days; ?> hari</td>
                                                    <td style="font-size:11px; max-width:200px;">
                                                        <?php echo htmlspecialchars($lr['reason'] ?? '-'); ?>
                                                        <?php if ($lr['admin_notes']): ?>
                                                            <div style="font-size:10px; color:var(--blue); margin-top:2px;">💬 <?php echo htmlspecialchars($lr['admin_notes']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge <?php echo $statusCls[$lr['status']] ?? ''; ?>"><?php echo $statusLbl[$lr['status']] ?? $lr['status']; ?></span>
                                                        <?php if ($lr['approved_by']): ?>
                                                            <div style="font-size:9px; color:var(--muted); margin-top:2px;">oleh <?php echo htmlspecialchars($lr['approved_by']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="white-space:nowrap;">
                                                        <?php $lrName = htmlspecialchars(addslashes($lr['full_name'] ?? '')); ?>
                                                        <?php $lrNotes = htmlspecialchars(addslashes($lr['admin_notes'] ?? '')); ?>
                                                        <?php if ($lr['status'] === 'pending'): ?>
                                                            <button class="btn btn-green btn-sm" onclick="openLeaveAction(<?php echo $lr['id']; ?>, 'approve', '<?php echo $lrName; ?>', '<?php echo $lrNotes; ?>')">✅</button>
                                                            <button class="btn btn-del btn-sm" onclick="openLeaveAction(<?php echo $lr['id']; ?>, 'reject', '<?php echo $lrName; ?>', '<?php echo $lrNotes; ?>')">❌</button>
                                                        <?php else: ?>
                                                            <button class="btn btn-gold btn-sm" onclick="openLeaveEdit(<?php echo $lr['id']; ?>, '<?php echo $lrName; ?>', '<?php echo $lr['status']; ?>', '<?php echo $lrNotes; ?>')">✏️ Edit</button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-del btn-sm" onclick="deleteLeaveRequest(<?php echo $lr['id']; ?>, '<?php echo $lrName; ?>')" title="Hapus pengajuan">🗑️</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Leave Action Modal (also used to EDIT an already approved/rejected decision) -->
                        <div class="modal-overlay" id="leaveModal">
                            <div class="modal-box">
                                <div class="modal-title" id="leaveModalTitle">Cuti</div>
                                <form method="POST" action="?tab=cuti" id="leaveActionForm">
                                    <input type="hidden" name="action" id="leaveAction" value="approve_leave">
                                    <input type="hidden" name="leave_id" id="leaveId">
                                    <div class="fg">
                                        <label class="fl">Catatan (opsional)</label>
                                        <textarea class="fi" name="admin_notes" id="leaveAdminNotes" rows="2" placeholder="Catatan admin..."></textarea>
                                    </div>
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('leaveModal').classList.remove('open')">Batal</button>
                                        <button type="button" class="btn btn-danger" onclick="submitLeaveDecision('reject_leave')">❌ Tolak</button>
                                        <button type="button" class="btn btn-green" onclick="submitLeaveDecision('approve_leave')">✅ Setujui</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Leave Delete Confirm Modal -->
                        <div class="modal-overlay" id="leaveDeleteModal">
                            <div class="modal-box">
                                <div class="modal-title">🗑️ Hapus Pengajuan Cuti</div>
                                <p style="font-size:13px; color:var(--muted); margin:0 0 16px;">Yakin ingin menghapus pengajuan cuti <strong id="leaveDeleteName"></strong>? Tindakan ini tidak bisa dibatalkan.</p>
                                <form method="POST" action="?tab=cuti">
                                    <input type="hidden" name="action" value="delete_leave">
                                    <input type="hidden" name="leave_id" id="leaveDeleteId">
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('leaveDeleteModal').classList.remove('open')">Batal</button>
                                        <button type="submit" class="btn btn-danger">🗑️ Hapus</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: LEMBUR (Overtime Requests Approval) -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-lembur" style="display:none;">
                            <?php if ($pendingOT > 0): ?>
                                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:12px; color:#92400e; display:flex; align-items:center; gap:8px;">
                                    <span style="font-size:20px;">⏳</span>
                                    <div><strong><?php echo $pendingOT; ?> pengajuan lembur</strong> menunggu persetujuan</div>
                                </div>
                            <?php endif; ?>

                            <div style="background:#f0fdf4; border:1px solid #86efac; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:11px; color:#166534;">
                                ⏰ Staff mengajukan lembur via <strong>Staff Portal</strong>. Overtime hanya akan dihitung di payroll jika pengajuan lembur <strong>disetujui</strong>. Jika ditolak/tidak ada pengajuan, overtime = 0.
                            </div>

                            <?php if (empty($overtimeRequests)): ?>
                                <div style="text-align:center; padding:40px; color:var(--muted);">
                                    <div style="font-size:40px; margin-bottom:8px;">⏰</div>
                                    <div style="font-size:14px; font-weight:600;">Belum ada pengajuan lembur</div>
                                    <div style="font-size:11px; margin-top:4px;">Staff mengajukan lembur via Staff Portal</div>
                                </div>
                            <?php else: ?>
                                <div class="tbl-wrap">
                                    <table class="tbl">
                                        <thead>
                                            <tr>
                                                <th>Karyawan</th>
                                                <th>Tanggal</th>
                                                <th>Alasan Lembur</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $statusCls = ['pending' => 'b-late', 'approved' => 'b-present', 'rejected' => 'b-absent'];
                                            $statusLbl = ['pending' => '⏳ Pending', 'approved' => '✅ Disetujui', 'rejected' => '❌ Ditolak'];
                                            foreach ($overtimeRequests as $ot):
                                            ?>
                                                <tr style="<?php echo $ot['status'] === 'pending' ? 'background:#fffbeb;' : ''; ?>">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($ot['full_name'] ?? 'Unknown'); ?></strong>
                                                        <div style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($ot['employee_code'] ?? ''); ?></div>
                                                    </td>
                                                    <td style="font-size:11px; white-space:nowrap;">
                                                        <?php echo date('D, d M Y', strtotime($ot['overtime_date'])); ?>
                                                    </td>
                                                    <td style="font-size:11px; max-width:250px;">
                                                        <?php echo htmlspecialchars($ot['reason'] ?? '-'); ?>
                                                        <?php if (!empty($ot['admin_notes'])): ?>
                                                            <div style="font-size:10px; color:var(--blue); margin-top:2px;">💬 <?php echo htmlspecialchars($ot['admin_notes']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="badge <?php echo $statusCls[$ot['status']] ?? ''; ?>"><?php echo $statusLbl[$ot['status']] ?? $ot['status']; ?></span>
                                                        <?php if (!empty($ot['approved_by'])): ?>
                                                            <div style="font-size:9px; color:var(--muted); margin-top:2px;">oleh <?php echo htmlspecialchars($ot['approved_by']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="white-space:nowrap;">
                                                        <?php if ($ot['status'] === 'pending'): ?>
                                                            <button class="btn btn-green btn-sm" onclick="openOTAction(<?php echo $ot['id']; ?>, 'approve', '<?php echo htmlspecialchars(addslashes($ot['full_name'] ?? '')); ?>')">✅</button>
                                                            <button class="btn btn-del btn-sm" onclick="openOTAction(<?php echo $ot['id']; ?>, 'reject', '<?php echo htmlspecialchars(addslashes($ot['full_name'] ?? '')); ?>')">❌</button>
                                                        <?php else: ?>
                                                            <span class="dash">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Overtime Action Modal -->
                        <div class="modal-overlay" id="otModal">
                            <div class="modal-box">
                                <div class="modal-title" id="otModalTitle">Lembur</div>
                                <form method="POST" action="?tab=lembur">
                                    <input type="hidden" name="action" id="otAction" value="approve_overtime">
                                    <input type="hidden" name="overtime_id" id="otId">
                                    <div class="fg">
                                        <label class="fl">Catatan (opsional)</label>
                                        <textarea class="fi" name="admin_notes" rows="2" placeholder="Catatan admin..."></textarea>
                                    </div>
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('otModal').classList.remove('open')">Batal</button>
                                        <button type="submit" class="btn btn-gold" id="otSubmitBtn">✅ Setujui</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: MANUAL (Face data + Manual input) -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-manual" style="display:none;">
                            <div style="background:#e0f2fe; border:1px solid #38bdf8; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:11px; color:#0c4a6e;">
                                👁️ Karyawan absen via <strong>scan wajah</strong> dari HP. Jika wajah bermasalah, reset di sini.<br>
                                ✋ Untuk input manual, klik tombol <strong>➕ Input Manual</strong> di header.
                            </div>

                            <div class="tbl-wrap">
                                <table class="tbl">
                                    <thead>
                                        <tr>
                                            <th>Kode</th>
                                            <th>Nama</th>
                                            <th>Jabatan</th>
                                            <th>Status Wajah</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $emp): ?>
                                            <tr>
                                                <td><code style="font-size:10px; background:rgba(240,180,41,.15); padding:2px 5px; border-radius:3px;"><?php echo htmlspecialchars($emp['employee_code']); ?></code></td>
                                                <td><strong><?php echo htmlspecialchars($emp['full_name']); ?></strong></td>
                                                <td style="font-size:10px; color:var(--muted);"><?php echo htmlspecialchars($emp['position']); ?></td>
                                                <td><?php echo !empty($emp['face_descriptor']) ? '<span style="color:var(--green); font-size:11px; font-weight:600;">✅ Terdaftar</span>' : '<span style="color:var(--orange); font-size:11px; font-weight:600;">⚠️ Belum (selfie saat absen pertama)</span>'; ?></td>
                                                <td>
                                                    <?php if (!empty($emp['face_descriptor'])): ?>
                                                        <button class="btn btn-del btn-sm" onclick="openFaceResetModal(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['full_name']); ?>')">🔄 Reset Wajah</button>
                                                    <?php else: ?><span class="dash">—</span><?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: JADWAL KERJA (Work Schedules)     -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-schedule" style="display:none;">

                            <!-- Individual Schedule per Employee (Calendar view) -->
                            <div class="reset-card">
                                <div style="display:flex;gap:12px;align-items:flex-start;">
                                    <div class="reset-icon" style="background:#fef3c7;color:var(--orange);">📅</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px;font-weight:700;color:var(--navy);margin:0 0 4px;">Jadwal Kerja per Karyawan</h3>
                                        <p style="font-size:10px;color:var(--muted);margin:0 0 10px;">Pilih karyawan, lalu klik tanggal di kalender untuk atur jam masuk/pulang & hari libur. Jadwal berlaku berulang tiap minggu sesuai hari yang diatur.</p>

                                        <div class="fg" style="margin-bottom:12px;">
                                            <label class="fl">Pilih Karyawan</label>
                                            <select id="schedEmpSelect" class="fi" onchange="loadEmpSchedule(this.value)">
                                                <option value="">— Pilih Karyawan —</option>
                                                <option value="all">👥 Semua Staff (Kalender Gabungan)</option>
                                                <?php foreach ($employees as $emp): ?>
                                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <form method="POST" action="?tab=schedule" id="schedForm" style="display:none;">
                                            <input type="hidden" name="action" value="save_work_schedule">
                                            <input type="hidden" name="schedule_mode" value="individual">
                                            <input type="hidden" name="schedule_employee_id" id="schedEmpId">
                                            <?php for ($di = 0; $di <= 6; $di++): ?>
                                                <input type="hidden" name="start_<?php echo $di; ?>" id="start_<?php echo $di; ?>" value="09:00">
                                                <input type="hidden" name="end_<?php echo $di; ?>" id="end_<?php echo $di; ?>" value="17:00">
                                                <input type="hidden" name="break_<?php echo $di; ?>" id="break_<?php echo $di; ?>" value="60">
                                                <input type="hidden" name="off_<?php echo $di; ?>" id="off_<?php echo $di; ?>" value="<?php echo $di === 0 ? '1' : '0'; ?>">
                                                <input type="hidden" name="uniform_<?php echo $di; ?>" id="uniform_<?php echo $di; ?>" value="">
                                            <?php endfor; ?>

                                            <!-- Quick Edit: check multiple weekdays, set one time range/off, apply to all at once -->
                                            <div id="schedQuickEdit" style="border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:12px;background:var(--bg);">
                                                <div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:8px;">⚡ Edit Cepat (banyak hari sekaligus)</div>
                                                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                                                    <?php $qDayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab']; ?>
                                                    <?php foreach ($qDayLabels as $qi => $ql): ?>
                                                        <label style="display:flex;align-items:center;gap:4px;padding:4px 10px;background:#fff;border:1px solid var(--border);border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;">
                                                            <input type="checkbox" class="sched-quick-day" value="<?php echo $qi; ?>"> <?php echo $ql; ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="fgrid" style="margin-bottom:8px;">
                                                    <div class="fg"><label class="fl">Jam Masuk</label><input type="time" class="fi" id="quickStart" value="09:00"></div>
                                                    <div class="fg"><label class="fl">Jam Pulang</label><input type="time" class="fi" id="quickEnd" value="17:00"></div>
                                                    <div class="fg"><label class="fl">Istirahat (menit)</label><input type="number" class="fi" id="quickBreak" value="60" min="0" max="120"></div>
                                                </div>
                                                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                                    <label style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;cursor:pointer;">
                                                        <input type="checkbox" id="quickOff" onchange="toggleQuickOffUI(this.checked)"> Tandai sebagai Libur (bukan jam kerja)
                                                    </label>
                                                    <button type="button" class="btn btn-primary btn-sm" onclick="applyQuickEdit()">⚡ Terapkan ke Hari Terpilih</button>
                                                </div>
                                            </div>

                                            <div class="sc-cal-nav">
                                                <button type="button" class="sc-cal-nav-btn" onclick="changeSchedMonth(-1)">◀ Bulan Lalu</button>
                                                <div id="schedCalTitle" class="sc-cal-title"></div>
                                                <button type="button" class="sc-cal-nav-btn" onclick="changeSchedMonth(1)">Bulan Depan ▶</button>
                                            </div>

                                            <div id="schedCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>

                                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px;">
                                                <div class="sc-cal-legend">
                                                    <span><span class="sc-cal-badge sc-cal-badge-in">🟢 Jam kerja</span></span>
                                                    <span><span class="sc-cal-badge sc-cal-badge-off">🔴 Libur</span></span>
                                                    <span>📌 Tanggal khusus &nbsp;—&nbsp; klik tanggal untuk mengubah</span>
                                                </div>
                                                <button type="submit" class="btn btn-primary">💾 Simpan Jadwal</button>
                                            </div>
                                        </form>

                                        <!-- All-Staff Combined Calendar view -->
                                        <div id="schedAllStaffView" style="display:none;">
                                            <div class="sc-cal-nav">
                                                <button type="button" class="sc-cal-nav-btn" onclick="changeSchedAllMonth(-1)">◀ Bulan Lalu</button>
                                                <div id="schedAllCalTitle" class="sc-cal-title"></div>
                                                <button type="button" class="sc-cal-nav-btn" onclick="changeSchedAllMonth(1)">Bulan Depan ▶</button>
                                            </div>
                                            <div id="schedAllCalGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                                            <div class="sc-cal-legend">
                                                <span><span class="sc-cal-badge sc-cal-badge-in">🟢 Jumlah staf masuk kerja</span></span>
                                                <span><span class="sc-cal-badge sc-cal-badge-off">🔴 Jumlah staf libur</span></span>
                                                <span>hover untuk lihat nama &nbsp;—&nbsp; klik tanggal untuk detail</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- All-Staff Day Detail Modal -->
                            <div class="modal-overlay" id="schedAllDayModal">
                                <div class="modal-box">
                                    <div class="modal-title" id="schedAllDayModalTitle">Staf Bertugas</div>
                                    <div id="schedAllDayModalBody" style="font-size:12px;max-height:360px;overflow-y:auto;"></div>
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('schedAllDayModal').classList.remove('open')">Tutup</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Day Editor Modal (edits the recurring weekly schedule for the clicked date's weekday) -->
                            <div class="modal-overlay" id="schedDayModal">
                                <div class="modal-box">
                                    <div class="modal-title" id="schedDayModalTitle">Atur Hari</div>
                                    <div id="schedDayDateLabel" style="font-size:11px;color:var(--muted);margin:-8px 0 12px;"></div>

                                    <div style="display:flex;gap:6px;margin-bottom:6px;">
                                        <button type="button" class="btn btn-sm" id="schedModeWeeklyBtn" style="flex:1;" onclick="setSchedDayMode('weekly')">🔁 Pola Mingguan</button>
                                        <button type="button" class="btn btn-sm" id="schedModeCustomBtn" style="flex:1;" onclick="setSchedDayMode('custom')">📌 Tanggal Ini Saja</button>
                                    </div>
                                    <div id="schedModeHint" style="font-size:10px;color:var(--muted);margin:0 0 12px;"></div>

                                    <div class="fg">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;cursor:pointer;">
                                            <input type="checkbox" id="schedDayOff" onchange="toggleSchedDayOffUI(this.checked)"> Hari Libur
                                        </label>
                                    </div>
                                    <div class="fgrid" id="schedDayTimeFields">
                                        <div class="fg"><label class="fl">Jam Masuk</label><input type="time" class="fi" id="schedDayStart" value="09:00"></div>
                                        <div class="fg"><label class="fl">Jam Pulang</label><input type="time" class="fi" id="schedDayEnd" value="17:00"></div>
                                        <div class="fg"><label class="fl">Istirahat (menit)</label><input type="number" class="fi" id="schedDayBreak" value="60" min="0" max="120"></div>
                                    </div>
                                    <div class="fg">
                                        <label class="fl">👔 Seragam</label>
                                        <input type="text" class="fi" id="schedDayUniform" placeholder="Contoh: Batik, Kemeja Putih, Bebas Rapi" maxlength="100">
                                    </div>
                                    <div class="modal-actions">
                                        <button type="button" class="btn btn-del btn-sm" id="schedDayRemoveOverrideBtn" style="display:none;" onclick="removeSchedDateOverride()">🗑️ Hapus Override</button>
                                        <button type="button" class="btn btn-primary" onclick="document.getElementById('schedDayModal').classList.remove('open')">Batal</button>
                                        <button type="button" class="btn btn-gold" onclick="applySchedDayEditor()">✅ Terapkan</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Current Schedules Overview -->
                            <div class="reset-card" style="margin-top:16px;">
                                <h3 style="font-size:13px;font-weight:700;color:var(--navy);margin:0 0 10px;">📋 Ringkasan Jadwal Terkini</h3>
                                <?php
                                // Load existing schedules
                                $_pdo->exec("CREATE TABLE IF NOT EXISTS `payroll_work_schedules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY, `employee_id` INT NOT NULL, `day_of_week` TINYINT NOT NULL DEFAULT 0,
                `start_time` TIME NOT NULL DEFAULT '09:00:00', `end_time` TIME NOT NULL DEFAULT '17:00:00',
                `break_minutes` INT DEFAULT 60, `is_off` TINYINT(1) DEFAULT 0,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_emp_day (employee_id, day_of_week)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                                $schedSummary = $_pdo->query("
                SELECT pe.id, pe.employee_code, pe.full_name, pe.position,
                    COUNT(ws.id) as sched_count,
                    GROUP_CONCAT(CASE WHEN ws.is_off = 1 THEN ws.day_of_week END) as off_days,
                    MIN(CASE WHEN ws.is_off = 0 THEN ws.start_time END) as earliest_start,
                    MAX(CASE WHEN ws.is_off = 0 THEN ws.end_time END) as latest_end
                FROM payroll_employees pe
                LEFT JOIN payroll_work_schedules ws ON ws.employee_id = pe.id
                WHERE pe.is_active = 1
                GROUP BY pe.id
                ORDER BY pe.full_name
            ")->fetchAll(PDO::FETCH_ASSOC);
                                $dShort = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                                ?>
                                <?php if (empty($schedSummary)): ?>
                                    <div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada karyawan aktif.</div>
                                <?php else: ?>
                                    <div style="overflow-x:auto;">
                                        <table class="tbl">
                                            <thead>
                                                <tr>
                                                    <th>Karyawan</th>
                                                    <th>Jabatan</th>
                                                    <th>Jam Kerja</th>
                                                    <th>Hari Libur</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($schedSummary as $ss): ?>
                                                    <tr>
                                                        <td style="font-weight:600;"><?php echo htmlspecialchars($ss['employee_code'] . ' — ' . $ss['full_name']); ?></td>
                                                        <td style="color:var(--muted);font-size:10px;"><?php echo htmlspecialchars($ss['position'] ?? '—'); ?></td>
                                                        <td>
                                                            <?php if ($ss['sched_count'] > 0): ?>
                                                                <span style="font-weight:600;"><?php echo substr($ss['earliest_start'] ?? '09:00', 0, 5); ?> — <?php echo substr($ss['latest_end'] ?? '17:00', 0, 5); ?></span>
                                                            <?php else: ?>
                                                                <span style="color:var(--muted);">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            if ($ss['off_days']) {
                                                                $offArr = explode(',', $ss['off_days']);
                                                                $offLabels = array_map(fn($d) => $dShort[(int)$d] ?? '?', $offArr);
                                                                echo '<span style="color:var(--red);font-size:10px;font-weight:600;">' . implode(', ', $offLabels) . '</span>';
                                                            } else {
                                                                echo '<span style="color:var(--muted);font-size:10px;">—</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($ss['sched_count'] > 0): ?>
                                                                <span class="badge b-hadir">✅ Sudah diatur</span>
                                                            <?php else: ?>
                                                                <span class="badge b-absent">⚠️ Belum</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: JADWAL SERAGAM (Uniform Schedule)  -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-uniform" style="display:none;">
                            <div class="reset-card">
                                <div style="display:flex;gap:12px;align-items:flex-start;">
                                    <div class="reset-icon" style="background:#d1fae5;color:#059669;">👔</div>
                                    <div style="flex:1;min-width:0;">
                                        <h3 style="font-size:13px;font-weight:700;color:var(--navy);margin:0 0 4px;">Setup Jadwal Seragam Semua Staff</h3>
                                        <p style="font-size:10px;color:var(--muted);margin:0 0 10px;">Atur seragam untuk 1 minggu SEKALI SAJA di bawah ini, lalu klik "Terapkan ke Semua Staff" — otomatis dipakai untuk semua karyawan aktif (tidak perlu isi satu-satu). Hari yang dikosongkan tidak akan mengubah data yang sudah ada. Untuk pengecualian per orang/tanggal, edit langsung di tabel bawah atau lewat kalender di tab Jadwal Kerja.</p>

                                        <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:14px;">
                                            <div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:8px;">⚡ Setup Cepat 1 Minggu (Semua Staff)</div>
                                            <div id="uniQuickRow" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;"></div>
                                            <button type="button" class="btn btn-primary" onclick="applyQuickToAll()">🚀 Terapkan ke Semua Staff Aktif</button>
                                        </div>

                                        <div style="margin-bottom:10px;">
                                            <button type="button" class="btn btn-primary" onclick="saveUniformAll()">💾 Simpan Semua Jadwal Seragam</button>
                                            <span id="uniSaveMsg" style="margin-left:10px;font-size:11px;"></span>
                                        </div>

                                        <div id="uniAllTableWrap" style="overflow-x:auto;border:1px solid var(--border);border-radius:8px;">
                                            <div class="loading" style="padding:20px;"><span class="spin"></span> Memuat...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ═══════════════════════════════════════ -->
                        <!-- TAB: RESET                              -->
                        <!-- ═══════════════════════════════════════ -->
                        <div class="tab-panel" id="panel-reset" style="display:none;">

                            <!-- Stats overview -->
                            <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px;">
                                <div class="st-card" style="border-top-color:var(--blue);">
                                    <div class="lb">Total Record Absen</div>
                                    <div class="vl" style="color:var(--blue); font-size:22px;"><?php echo number_format($resetStats['total_records']); ?></div>
                                </div>
                                <div class="st-card" style="border-top-color:var(--green);">
                                    <div class="lb">Wajah Terdaftar</div>
                                    <div class="vl" style="color:var(--green); font-size:22px;"><?php echo $resetStats['face_registered']; ?>/<?php echo count($employees); ?></div>
                                </div>
                                <div class="st-card" style="border-top-color:var(--purple);">
                                    <div class="lb">Finger ID Terdaftar</div>
                                    <div class="vl" style="color:var(--purple); font-size:22px;"><?php echo $resetStats['finger_registered']; ?>/<?php echo count($employees); ?></div>
                                </div>
                                <div class="st-card" style="border-top-color:var(--orange);">
                                    <div class="lb">Log Webhook</div>
                                    <div class="vl" style="color:var(--orange); font-size:22px;"><?php echo number_format($resetStats['log_count']); ?></div>
                                </div>
                            </div>

                            <!-- 1) Reset by Date Range -->
                            <div class="reset-card">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <div class="reset-icon" style="background:#eff6ff; color:var(--blue);">📅</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Absen per Periode</h3>
                                        <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Hapus data absensi pada rentang tanggal tertentu. Bisa pilih per karyawan atau semua.</p>
                                        <form method="POST" action="?tab=reset" onsubmit="return confirm('Yakin hapus data absen periode ini?')">
                                            <input type="hidden" name="action" value="reset_attendance_range">
                                            <div class="fgrid" style="margin-bottom:8px;">
                                                <div class="fg"><label class="fl">Dari Tanggal</label><input type="date" name="reset_from" class="fi" required></div>
                                                <div class="fg"><label class="fl">Sampai Tanggal</label><input type="date" name="reset_to" class="fi" required></div>
                                            </div>
                                            <div class="fg">
                                                <label class="fl">Karyawan (kosong = semua)</label>
                                                <select name="reset_employee_id" class="fi">
                                                    <option value="0">— Semua Karyawan —</option>
                                                    <?php foreach ($employees as $emp): ?>
                                                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-primary">🗑 Hapus Data Periode</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 2) Reset per Employee -->
                            <div class="reset-card">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <div class="reset-icon" style="background:#fefce8; color:var(--orange);">👤</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Data Staff</h3>
                                        <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Pilih karyawan dan jenis data yang ingin direset.</p>
                                        <form method="POST" action="?tab=reset" onsubmit="return confirm('Yakin reset data ini?')">
                                            <input type="hidden" name="action" value="reset_employee_data">
                                            <div class="fgrid" style="margin-bottom:8px;">
                                                <div class="fg">
                                                    <label class="fl">Karyawan</label>
                                                    <select name="employee_id" class="fi" required>
                                                        <option value="">— Pilih —</option>
                                                        <?php foreach ($employees as $emp): ?>
                                                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="fg">
                                                    <label class="fl">Jenis Reset</label>
                                                    <select name="reset_type" class="fi" required>
                                                        <option value="face">🔄 Reset Wajah Saja</option>
                                                        <option value="finger">🔄 Reset Finger ID Saja</option>
                                                        <option value="attendance">🗑 Hapus Semua Absen</option>
                                                        <option value="all">⚠️ Reset Semua (Wajah + Finger + Absen)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-primary">🔄 Reset Data Staff</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 3) Reset All Faces -->
                            <div class="reset-card">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <div class="reset-icon" style="background:#fef2f2; color:var(--red);">👁️</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Reset Semua Data Wajah</h3>
                                        <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Reset data wajah seluruh karyawan. Mereka perlu selfie ulang saat absen berikutnya.</p>
                                        <form method="POST" action="?tab=reset" onsubmit="return confirm('Reset SEMUA data wajah karyawan?')">
                                            <input type="hidden" name="action" value="reset_all_faces">
                                            <button type="submit" class="btn" style="background:#fef2f2; color:var(--red); border:1px solid #fca5a5;">👁️ Reset Semua Wajah (<?php echo $resetStats['face_registered']; ?> terdaftar)</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 4) Reset Fingerprint Log -->
                            <div class="reset-card">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <div class="reset-icon" style="background:#ede9fe; color:var(--purple);">📜</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px; font-weight:700; color:var(--navy); margin:0 0 4px;">Hapus Log Fingerprint</h3>
                                        <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Bersihkan tabel log webhook fingerprint. Data absen tetap aman.</p>
                                        <form method="POST" action="?tab=reset" onsubmit="return confirm('Hapus semua log fingerprint?')">
                                            <input type="hidden" name="action" value="reset_fingerprint_log">
                                            <button type="submit" class="btn btn-purple">📜 Hapus Log (<?php echo number_format($resetStats['log_count']); ?> records)</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 5) DANGER: Reset ALL Attendance -->
                            <div class="reset-card danger">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <div class="reset-icon" style="background:var(--red); color:#fff;">⚠️</div>
                                    <div style="flex:1;">
                                        <h3 style="font-size:13px; font-weight:700; color:var(--red); margin:0 0 4px;">⚠️ Reset Semua Data Absensi</h3>
                                        <p style="font-size:10px; color:var(--muted); margin:0 0 10px;">Hapus SELURUH data absensi. Tindakan ini <strong>tidak bisa dibatalkan</strong>. Ketik <code>HAPUS-SEMUA</code> untuk mengkonfirmasi.</p>
                                        <form method="POST" action="?tab=reset" onsubmit="return this.confirm_code.value==='HAPUS-SEMUA' || (alert('Ketik HAPUS-SEMUA untuk konfirmasi'), false)">
                                            <input type="hidden" name="action" value="reset_all_attendance">
                                            <div class="fg">
                                                <label class="fl">Kode Konfirmasi</label>
                                                <input type="text" name="confirm_code" class="fi" placeholder="Ketik: HAPUS-SEMUA" autocomplete="off" style="border-color:#fca5a5;">
                                            </div>
                                            <button type="submit" class="btn btn-danger">🗑 Hapus Semua Absensi (<?php echo number_format($resetStats['total_records']); ?> records)</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div><!-- /att-wrap -->

                    <!-- ═══ MODALS ═══ -->

                    <!-- Edit Attendance -->
                    <div class="modal-overlay" id="editModal">
                        <div class="modal-box">
                            <div class="modal-title">✏️ Edit Data Absen</div>
                            <form method="POST">
                                <input type="hidden" name="action" value="edit_att">
                                <input type="hidden" name="att_id" id="editAttId">
                                <div style="font-size:12px; font-weight:600; color:var(--navy); margin-bottom:10px;" id="editEmpName"></div>
                                <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#f0f9ff; border-radius:5px; border-left:3px solid var(--blue);">🔄 Shift 1</div>
                                <div class="fgrid">
                                    <div class="fg"><label class="fl">Scan 1 (Masuk)</label><input type="time" name="scan_1" id="editScan1" class="fi"></div>
                                    <div class="fg"><label class="fl">Scan 2 (Pulang)</label><input type="time" name="scan_2" id="editScan2" class="fi"></div>
                                </div>
                                <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#fefce8; border-radius:5px; border-left:3px solid var(--orange);">🌙 Shift 2</div>
                                <div class="fgrid">
                                    <div class="fg"><label class="fl">Scan 3 (Masuk)</label><input type="time" name="scan_3" id="editScan3" class="fi"></div>
                                    <div class="fg"><label class="fl">Scan 4 (Pulang)</label><input type="time" name="scan_4" id="editScan4" class="fi"></div>
                                </div>
                                <div class="fg"><label class="fl">Jam Lembur</label><input type="number" name="overtime_hours" id="editOvertimeHours" class="fi" min="0" step="0.25" placeholder="0.00">
                                    <div style="font-size:10px;color:var(--muted);margin-top:2px;">Isi jika jam lembur ingin diubah manual.</div>
                                </div>
                                <div class="fg"><label class="fl">Status</label>
                                    <select name="status" id="editStatus" class="fi">
                                        <option value="present">Hadir</option>
                                        <option value="late">Terlambat</option>
                                        <option value="absent">Absen</option>
                                        <option value="leave">Izin</option>
                                        <option value="holiday">Libur</option>
                                        <option value="half_day">½ Hari</option>
                                    </select>
                                </div>
                                <div class="fg"><label class="fl">Catatan</label><input type="text" name="notes" id="editNotes" class="fi" placeholder="Opsional"></div>
                                <div class="modal-actions">
                                    <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('editModal')">Batal</button>
                                    <button type="submit" class="btn btn-primary">💾 Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Manual Attendance -->
                    <div class="modal-overlay" id="manualModal">
                        <div class="modal-box">
                            <div class="modal-title">➕ Input Absen Manual</div>
                            <form method="POST">
                                <input type="hidden" name="action" value="manual_att">
                                <div class="fg"><label class="fl">Karyawan</label>
                                    <select name="employee_id" id="manualEmpId" class="fi" required>
                                        <option value="">— Pilih —</option>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['employee_code'] . ' — ' . $emp['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fg"><label class="fl">Tanggal</label><input type="date" name="attendance_date" id="manualDate" class="fi" value="<?php echo $viewDate; ?>" required></div>
                                <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#f0f9ff; border-radius:5px; border-left:3px solid var(--blue);">🔄 Shift 1</div>
                                <div class="fgrid">
                                    <div class="fg"><label class="fl">Scan 1 (Masuk)</label><input type="time" name="scan_1" class="fi" value="07:00"></div>
                                    <div class="fg"><label class="fl">Scan 2 (Pulang)</label><input type="time" name="scan_2" class="fi" value="11:00"></div>
                                </div>
                                <div style="font-size:10px; color:var(--muted); margin-bottom:6px; padding:5px 8px; background:#fefce8; border-radius:5px; border-left:3px solid var(--orange);">🌙 Shift 2</div>
                                <div class="fgrid">
                                    <div class="fg"><label class="fl">Scan 3 (Masuk)</label><input type="time" name="scan_3" class="fi"></div>
                                    <div class="fg"><label class="fl">Scan 4 (Pulang)</label><input type="time" name="scan_4" class="fi"></div>
                                </div>
                                <div class="fg"><label class="fl">Status</label>
                                    <select name="status" class="fi">
                                        <option value="present">Hadir</option>
                                        <option value="late">Terlambat</option>
                                        <option value="absent">Absen</option>
                                        <option value="leave">Izin</option>
                                        <option value="holiday">Libur</option>
                                    </select>
                                </div>
                                <div class="fg"><label class="fl">Catatan</label><input type="text" name="notes" class="fi" placeholder="Opsional"></div>
                                <div class="modal-actions">
                                    <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('manualModal')">Batal</button>
                                    <button type="submit" class="btn btn-primary">💾 Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Reset Face Modal -->
                    <div class="modal-overlay" id="faceModal">
                        <div class="modal-box">
                            <div class="modal-title">🔄 Reset Data Wajah</div>
                            <form method="POST">
                                <input type="hidden" name="action" value="reset_face">
                                <input type="hidden" name="employee_id" id="faceEmpId">
                                <div style="font-size:12px; color:var(--muted); margin-bottom:10px;" id="faceEmpName"></div>
                                <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:10px; font-size:11px; color:#991b1b; margin-bottom:10px;">
                                    ⚠️ Karyawan harus <strong>selfie ulang</strong> saat absen berikutnya.
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('faceModal')">Batal</button>
                                    <button type="submit" class="btn btn-danger">🔄 Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Location Modal -->
                    <div class="modal-overlay" id="locModal">
                        <div class="modal-box" style="max-width:520px;">
                            <div class="modal-title" id="locModalTitle">📍 Tambah Lokasi</div>
                            <form method="POST" action="?tab=gps" id="locForm" onsubmit="return validateLocForm()">
                                <input type="hidden" name="action" id="locFormAction" value="add_location">
                                <input type="hidden" name="loc_id" id="locFormId" value="">
                                <div class="fg"><label class="fl">Nama Lokasi</label><input type="text" name="loc_name" id="locName" class="fi" placeholder="mis: Proyek PLN Semarang" required></div>
                                <div class="fg"><label class="fl">Alamat (opsional)</label><input type="text" name="loc_address" id="locAddress" class="fi" placeholder="Alamat lengkap"></div>
                                <div class="fgrid">
                                    <div class="fg"><label class="fl">Latitude</label><input type="text" name="loc_lat" id="locLat" class="fi" placeholder="-6.2" required readonly style="background:#f8fafc;"></div>
                                    <div class="fg"><label class="fl">Longitude</label><input type="text" name="loc_lng" id="locLng" class="fi" placeholder="106.8" required readonly style="background:#f8fafc;"></div>
                                </div>
                                <div style="font-size:10px; color:var(--blue); background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:6px 8px; margin-bottom:8px;">
                                    📌 Klik peta untuk menentukan titik lokasi.
                                </div>
                                <div id="locPickerMap" style="height:180px; border-radius:6px; border:1px solid var(--border); margin-bottom:8px;"></div>
                                <div style="display:flex; gap:6px; margin-bottom:8px;">
                                    <button type="button" onclick="useMyGPS()" class="btn btn-edit btn-sm">📍 Lokasi Saya</button>
                                    <span id="locGpsStatus" style="font-size:10px; color:var(--muted); line-height:2.2;"></span>
                                </div>
                                <div class="fg"><label class="fl">Radius (meter)</label><input type="number" name="loc_radius" id="locRadius" class="fi" value="200" min="10" max="10000"></div>
                                <div class="fg" id="locActiveGroup" style="display:none;">
                                    <label style="display:flex; align-items:center; gap:6px; font-size:11px;">
                                        <input type="checkbox" name="loc_active" id="locActive"> Lokasi aktif
                                    </label>
                                </div>
                                <div class="modal-actions">
                                    <button type="button" class="btn" style="background:#f1f5f9; color:var(--muted); border:1px solid var(--border);" onclick="closeModal('locModal')">Batal</button>
                                    <button type="submit" class="btn btn-primary">💾 Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                    <script>
                        // ─ TABS ─
                        document.querySelectorAll('.att-tab').forEach(btn => {
                            btn.addEventListener('click', () => {
                                document.querySelectorAll('.att-tab').forEach(b => b.classList.remove('active'));
                                document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
                                btn.classList.add('active');
                                const tab = btn.dataset.tab;
                                document.getElementById('panel-' + tab).style.display = 'block';
                                if (tab === 'gps') setTimeout(initAdminMap, 100);
                                if (tab === 'uniform' && !uniAllLoaded) loadUniformAllTable();
                                const url = new URL(window.location);
                                url.searchParams.set('tab', tab);
                                window.history.replaceState({}, '', url);
                            });
                        });

                        // Restore tab from URL
                        const urlTab = new URLSearchParams(window.location.search).get('tab');
                        if (urlTab) {
                            document.querySelectorAll('.att-tab').forEach(b => {
                                b.classList.remove('active');
                                if (b.dataset.tab === urlTab) b.classList.add('active');
                            });
                            document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
                            const panel = document.getElementById('panel-' + urlTab);
                            if (panel) panel.style.display = 'block';
                            if (urlTab === 'gps') setTimeout(initAdminMap, 200);
                            if (urlTab === 'uniform') loadUniformAllTable();
                        }

                        // Auto-open fingerprint tab + scroll to result after batch process
                        <?php if (!empty($_SESSION['last_payroll_tab'])): ?>
                                (function() {
                                    var autoTab = '<?= htmlspecialchars($_SESSION['last_payroll_tab']) ?>';
                                    <?php unset($_SESSION['last_payroll_tab']); ?>
                                    document.querySelectorAll('.att-tab').forEach(b => {
                                        b.classList.remove('active');
                                        if (b.dataset.tab === autoTab) b.classList.add('active');
                                    });
                                    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
                                    var autoPanel = document.getElementById('panel-' + autoTab);
                                    if (autoPanel) autoPanel.style.display = 'block';
                                    var alert = document.getElementById('mainAlert');
                                    if (alert) setTimeout(function() {
                                        alert.scrollIntoView({
                                            behavior: 'smooth',
                                            block: 'center'
                                        });
                                    }, 150);
                                })();
                        <?php endif; ?>

                        // ─ COPY URL ─
                        function copyUrl(inputId) {
                            const el = document.getElementById(inputId || 'absenUrlInput');
                            el.select();
                            navigator.clipboard.writeText(el.value).then(() => {
                                event.target.textContent = '✅ OK!';
                                setTimeout(() => event.target.textContent = '📋 Salin', 1500);
                            });
                        }

                        function copyWebhookUrl(el) {
                            const text = el.innerText.trim();
                            navigator.clipboard.writeText(text).then(() => {
                                el.innerHTML = '✅ Copied!';
                                setTimeout(() => {
                                    el.innerHTML = text;
                                }, 1500);
                            });
                        }

                        // ─ LEAVE ACTION ─
                        function openLeaveAction(id, action, name, notes) {
                            document.getElementById('leaveId').value = id;
                            document.getElementById('leaveAction').value = action === 'approve' ? 'approve_leave' : 'reject_leave';
                            document.getElementById('leaveModalTitle').textContent = action === 'approve' ? '✅ Setujui Cuti — ' + name : '❌ Tolak Cuti — ' + name;
                            document.getElementById('leaveAdminNotes').value = notes || '';
                            document.getElementById('leaveModal').classList.add('open');
                        }

                        // ─ EDIT an already approved/rejected leave decision ─
                        function openLeaveEdit(id, name, currentStatus, notes) {
                            document.getElementById('leaveId').value = id;
                            document.getElementById('leaveAction').value = currentStatus === 'approved' ? 'approve_leave' : 'reject_leave';
                            document.getElementById('leaveModalTitle').textContent = '✏️ Edit Persetujuan Cuti — ' + name;
                            document.getElementById('leaveAdminNotes').value = notes || '';
                            document.getElementById('leaveModal').classList.add('open');
                        }

                        function submitLeaveDecision(actionValue) {
                            document.getElementById('leaveAction').value = actionValue;
                            document.getElementById('leaveActionForm').submit();
                        }

                        function deleteLeaveRequest(id, name) {
                            document.getElementById('leaveDeleteId').value = id;
                            document.getElementById('leaveDeleteName').textContent = name;
                            document.getElementById('leaveDeleteModal').classList.add('open');
                        }

                        // ─ OVERTIME ACTION ─
                        function openOTAction(id, action, name) {
                            document.getElementById('otId').value = id;
                            document.getElementById('otAction').value = action === 'approve' ? 'approve_overtime' : 'reject_overtime';
                            document.getElementById('otModalTitle').textContent = action === 'approve' ? '✅ Setujui Lembur — ' + name : '❌ Tolak Lembur — ' + name;
                            document.getElementById('otSubmitBtn').textContent = action === 'approve' ? '✅ Setujui' : '❌ Tolak';
                            document.getElementById('otSubmitBtn').className = action === 'approve' ? 'btn btn-green' : 'btn btn-danger';
                            document.getElementById('otModal').classList.add('open');
                        }

                        // ─ ADMIN MAP ─
                        let adminMap = null;
                        const allLocations = <?php echo json_encode(array_values($locations)); ?>;

                        function initAdminMap() {
                            if (adminMap) {
                                adminMap.invalidateSize();
                                return;
                            }
                            const center = allLocations.length ? [allLocations[0].lat, allLocations[0].lng] : [-5.8, 110.4];
                            adminMap = L.map('adminMap').setView(center, 14);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                maxZoom: 19,
                                attribution: '© OSM'
                            }).addTo(adminMap);
                            const bounds = [];
                            allLocations.forEach(loc => {
                                const ll = [parseFloat(loc.lat), parseFloat(loc.lng)];
                                bounds.push(ll);
                                L.circle(ll, {
                                    radius: parseInt(loc.radius_m),
                                    color: loc.is_active ? '#f0b429' : '#94a3b8',
                                    fillOpacity: .15,
                                    weight: 2
                                }).addTo(adminMap).bindTooltip(loc.location_name);
                                L.marker(ll).addTo(adminMap).bindPopup('<b>' + loc.location_name + '</b><br>Radius: ' + loc.radius_m + 'm');
                            });
                            if (bounds.length > 1) adminMap.fitBounds(bounds, {
                                padding: [30, 30]
                            });
                        }

                        // ─ LOCATION PICKER ─
                        let locPickerMap = null,
                            locPickerMarker = null,
                            locPickerCircle = null;

                        function initLocPickerMap(lat, lng, radius) {
                            lat = lat || -5.8;
                            lng = lng || 110.4;
                            radius = radius || 200;
                            if (!locPickerMap) {
                                locPickerMap = L.map('locPickerMap').setView([lat, lng], 16);
                                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                    maxZoom: 19,
                                    attribution: '© OSM'
                                }).addTo(locPickerMap);
                                locPickerMarker = L.marker([lat, lng], {
                                    draggable: true
                                }).addTo(locPickerMap);
                                locPickerCircle = L.circle([lat, lng], {
                                    radius,
                                    color: '#f0b429',
                                    fillOpacity: .15
                                }).addTo(locPickerMap);
                                locPickerMap.on('click', e => {
                                    locPickerMarker.setLatLng(e.latlng);
                                    locPickerCircle.setLatLng(e.latlng);
                                    document.getElementById('locLat').value = e.latlng.lat.toFixed(7);
                                    document.getElementById('locLng').value = e.latlng.lng.toFixed(7);
                                });
                                locPickerMarker.on('dragend', () => {
                                    const pos = locPickerMarker.getLatLng();
                                    locPickerCircle.setLatLng(pos);
                                    document.getElementById('locLat').value = pos.lat.toFixed(7);
                                    document.getElementById('locLng').value = pos.lng.toFixed(7);
                                });
                                document.getElementById('locRadius').addEventListener('input', function() {
                                    locPickerCircle.setRadius(parseInt(this.value) || 200);
                                });
                            } else {
                                locPickerMap.setView([lat, lng], 16);
                                locPickerMarker.setLatLng([lat, lng]);
                                locPickerCircle.setLatLng([lat, lng]).setRadius(radius);
                                locPickerMap.invalidateSize();
                            }
                            document.getElementById('locLat').value = lat;
                            document.getElementById('locLng').value = lng;
                        }

                        function openLocModal(loc) {
                            if (loc) {
                                document.getElementById('locModalTitle').textContent = '✏️ Edit Lokasi';
                                document.getElementById('locFormAction').value = 'edit_location';
                                document.getElementById('locFormId').value = loc.id;
                                document.getElementById('locName').value = loc.location_name;
                                document.getElementById('locAddress').value = loc.address || '';
                                document.getElementById('locRadius').value = loc.radius_m;
                                document.getElementById('locActive').checked = loc.is_active == 1;
                                document.getElementById('locActiveGroup').style.display = 'block';
                                document.getElementById('locModal').classList.add('open');
                                setTimeout(() => initLocPickerMap(parseFloat(loc.lat), parseFloat(loc.lng), parseInt(loc.radius_m)), 100);
                            } else {
                                document.getElementById('locModalTitle').textContent = '📍 Tambah Lokasi';
                                document.getElementById('locFormAction').value = 'add_location';
                                document.getElementById('locFormId').value = '';
                                document.getElementById('locForm').reset();
                                document.getElementById('locRadius').value = 200;
                                document.getElementById('locActiveGroup').style.display = 'none';
                                document.getElementById('locModal').classList.add('open');
                                const fl = allLocations[0];
                                setTimeout(() => initLocPickerMap(fl ? parseFloat(fl.lat) : -5.8, fl ? parseFloat(fl.lng) : 110.4, 200), 100);
                            }
                        }

                        function validateLocForm() {
                            const lat = document.getElementById('locLat').value.trim();
                            const lng = document.getElementById('locLng').value.trim();
                            if (!lat || !lng || (parseFloat(lat) === 0 && parseFloat(lng) === 0)) {
                                document.getElementById('locGpsStatus').textContent = '❌ Tentukan titik dulu — klik peta atau GPS.';
                                return false;
                            }
                            return true;
                        }

                        function useMyGPS() {
                            const s = document.getElementById('locGpsStatus');
                            s.textContent = '📡 Mengambil GPS...';
                            navigator.geolocation.getCurrentPosition(pos => {
                                const lat = pos.coords.latitude,
                                    lng = pos.coords.longitude;
                                document.getElementById('locLat').value = lat.toFixed(7);
                                document.getElementById('locLng').value = lng.toFixed(7);
                                if (locPickerMarker) {
                                    locPickerMarker.setLatLng([lat, lng]);
                                    locPickerCircle.setLatLng([lat, lng]);
                                    locPickerMap.setView([lat, lng], 17);
                                }
                                s.textContent = '✅ ±' + Math.round(pos.coords.accuracy) + 'm';
                            }, err => {
                                s.textContent = '❌ ' + err.message;
                            }, {
                                enableHighAccuracy: true
                            });
                        }

                        // ─ MODALS ─
                        function openEditModal(att) {
                            document.getElementById('editAttId').value = att.id;
                            document.getElementById('editEmpName').textContent = att.full_name + ' — ' + att.attendance_date;
                            document.getElementById('editScan1').value = att.check_in_time ? att.check_in_time.substring(0, 5) : '';
                            document.getElementById('editScan2').value = att.check_out_time ? att.check_out_time.substring(0, 5) : '';
                            document.getElementById('editScan3').value = att.scan_3 ? att.scan_3.substring(0, 5) : '';
                            document.getElementById('editScan4').value = att.scan_4 ? att.scan_4.substring(0, 5) : '';
                            document.getElementById('editOvertimeHours').value = att.overtime_hours ? parseFloat(att.overtime_hours).toFixed(2) : '';
                            document.getElementById('editStatus').value = att.status || 'present';
                            document.getElementById('editNotes').value = att.notes || '';
                            document.getElementById('editModal').classList.add('open');
                        }

                        function openFaceResetModal(id, name) {
                            document.getElementById('faceEmpId').value = id;
                            document.getElementById('faceEmpName').textContent = 'Karyawan: ' + name;
                            document.getElementById('faceModal').classList.add('open');
                        }

                        function openManualModal() {
                            document.getElementById('manualModal').classList.add('open');
                        }

                        function quickManualAdd(id, name, date) {
                            document.getElementById('manualEmpId').value = id;
                            document.getElementById('manualDate').value = date;
                            document.getElementById('manualModal').classList.add('open');
                        }

                        function closeModal(id) {
                            document.getElementById(id).classList.remove('open');
                        }
                        document.querySelectorAll('.modal-overlay').forEach(m => {
                            m.addEventListener('click', e => {
                                if (e.target === m) m.classList.remove('open');
                            });
                        });

                        // ─ SCHEDULE MANAGEMENT ─
                        const SCHED_MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        const SCHED_DAY_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
                        const SCHED_DAY_FULL = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                        let schedCalYear = new Date().getFullYear();
                        let schedCalMonth = new Date().getMonth();
                        let schedCurrentEmpId = null;
                        let schedEditingDay = null;
                        let schedEditingDate = null;
                        let schedDayMode = 'weekly';
                        let schedOverrides = {}; // { 'YYYY-MM-DD': {is_off, start_time, end_time, break_minutes} }
                        let schedAllCalYear = new Date().getFullYear();
                        let schedAllCalMonth = new Date().getMonth();
                        let schedAllData = null; // { employees:[], weeklyByEmp:{empId:{dow:row}}, overridesByEmp:{empId:{dateStr:row}} }

                        function schedPad(n) {
                            return n < 10 ? '0' + n : '' + n;
                        }

                        function schedDateStr(y, m, d) {
                            return y + '-' + schedPad(m + 1) + '-' + schedPad(d);
                        }

                        function renderScheduleCalendar() {
                            const grid = document.getElementById('schedCalGrid');
                            const title = document.getElementById('schedCalTitle');
                            if (!grid || !title) return;
                            title.textContent = SCHED_MONTH_NAMES[schedCalMonth] + ' ' + schedCalYear;
                            grid.innerHTML = '';
                            const today = new Date();
                            const todayStr = schedDateStr(today.getFullYear(), today.getMonth(), today.getDate());
                            SCHED_DAY_SHORT.forEach(d => {
                                const h = document.createElement('div');
                                h.textContent = d;
                                h.className = 'sc-cal-dow';
                                grid.appendChild(h);
                            });
                            const firstDay = new Date(schedCalYear, schedCalMonth, 1).getDay();
                            const daysInMonth = new Date(schedCalYear, schedCalMonth + 1, 0).getDate();
                            for (let i = 0; i < firstDay; i++) {
                                const empty = document.createElement('div');
                                empty.className = 'sc-cal-cell';
                                empty.style.visibility = 'hidden';
                                grid.appendChild(empty);
                            }
                            for (let day = 1; day <= daysInMonth; day++) {
                                const dow = new Date(schedCalYear, schedCalMonth, day).getDay();
                                const dateStr = schedDateStr(schedCalYear, schedCalMonth, day);
                                const override = schedOverrides[dateStr];
                                let isOff, start, end, uniform;
                                if (override) {
                                    isOff = parseInt(override.is_off) === 1;
                                    start = (override.start_time || '09:00').substring(0, 5);
                                    end = (override.end_time || '17:00').substring(0, 5);
                                    uniform = override.uniform || '';
                                } else {
                                    const offEl = document.getElementById('off_' + dow);
                                    isOff = offEl && offEl.value === '1';
                                    start = document.getElementById('start_' + dow).value;
                                    end = document.getElementById('end_' + dow).value;
                                    const uniEl = document.getElementById('uniform_' + dow);
                                    uniform = uniEl ? uniEl.value : '';
                                }
                                const cell = document.createElement('div');
                                cell.onclick = () => openSchedDayEditor(dateStr);
                                cell.className = 'sc-cal-cell' + (isOff ? ' is-off' : '') + (override ? ' override' : '') + (dateStr === todayStr ? ' today' : '');
                                const uniformBadge = (!isOff && uniform) ? '<span class="sc-cal-badge sc-cal-badge-uniform" title="' + uniform.replace(/"/g, '&quot;') + '">👔 ' + uniform + '</span>' : '';
                                const statusBadge = isOff ? '<span class="sc-cal-badge sc-cal-badge-off">🔴 Libur</span>' : '<span class="sc-cal-badge sc-cal-badge-in">🟢 ' + start + '-' + end + '</span>';
                                cell.innerHTML = '<div class="sc-cal-daynum">' + day + (override ? ' <span title="Tanggal khusus">📌</span>' : '') + '</div>' + statusBadge + uniformBadge;
                                grid.appendChild(cell);
                            }
                        }

                        function changeSchedMonth(delta) {
                            schedCalMonth += delta;
                            if (schedCalMonth < 0) {
                                schedCalMonth = 11;
                                schedCalYear--;
                            } else if (schedCalMonth > 11) {
                                schedCalMonth = 0;
                                schedCalYear++;
                            }
                            renderScheduleCalendar();
                        }

                        function setSchedDayMode(mode) {
                            schedDayMode = mode;
                            const weeklyBtn = document.getElementById('schedModeWeeklyBtn');
                            const customBtn = document.getElementById('schedModeCustomBtn');
                            const hint = document.getElementById('schedModeHint');
                            const removeBtn = document.getElementById('schedDayRemoveOverrideBtn');
                            if (mode === 'custom') {
                                weeklyBtn.className = 'btn btn-sm';
                                weeklyBtn.style.cssText = 'flex:1;background:var(--bg);color:var(--navy);';
                                customBtn.className = 'btn btn-sm';
                                customBtn.style.cssText = 'flex:1;background:var(--purple);color:#fff;';
                                hint.textContent = 'Pengaturan ini hanya berlaku untuk tanggal ini saja (tersimpan otomatis, tidak perlu klik "Simpan Jadwal").';
                                removeBtn.style.display = schedOverrides[schedEditingDate] ? 'inline-flex' : 'none';
                            } else {
                                weeklyBtn.className = 'btn btn-sm';
                                weeklyBtn.style.cssText = 'flex:1;background:var(--navy);color:#fff;';
                                customBtn.className = 'btn btn-sm';
                                customBtn.style.cssText = 'flex:1;background:var(--bg);color:var(--navy);';
                                hint.textContent = 'Berlaku untuk hari ' + SCHED_DAY_FULL[schedEditingDay] + ' setiap minggu (perlu klik "Simpan Jadwal").';
                                removeBtn.style.display = 'none';
                            }
                        }

                        function openSchedDayEditor(dateStr) {
                            if (!schedCurrentEmpId) return;
                            const parts = dateStr.split('-').map(Number);
                            const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
                            const dow = dateObj.getDay();
                            schedEditingDay = dow;
                            schedEditingDate = dateStr;
                            document.getElementById('schedDayModalTitle').textContent = '🗓️ Atur Jadwal — ' + SCHED_DAY_FULL[dow];
                            document.getElementById('schedDayDateLabel').textContent = dateObj.toLocaleDateString('id-ID', {
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric'
                            });

                            const override = schedOverrides[dateStr];
                            if (override) {
                                document.getElementById('schedDayOff').checked = parseInt(override.is_off) === 1;
                                document.getElementById('schedDayStart').value = (override.start_time || '09:00').substring(0, 5);
                                document.getElementById('schedDayEnd').value = (override.end_time || '17:00').substring(0, 5);
                                document.getElementById('schedDayBreak').value = override.break_minutes || 60;
                                document.getElementById('schedDayUniform').value = override.uniform || '';
                                setSchedDayMode('custom');
                            } else {
                                const isOff = document.getElementById('off_' + dow).value === '1';
                                document.getElementById('schedDayOff').checked = isOff;
                                document.getElementById('schedDayStart').value = document.getElementById('start_' + dow).value;
                                document.getElementById('schedDayEnd').value = document.getElementById('end_' + dow).value;
                                document.getElementById('schedDayBreak').value = document.getElementById('break_' + dow).value;
                                document.getElementById('schedDayUniform').value = document.getElementById('uniform_' + dow).value || '';
                                setSchedDayMode('weekly');
                            }
                            toggleSchedDayOffUI(document.getElementById('schedDayOff').checked);
                            document.getElementById('schedDayModal').classList.add('open');
                        }

                        function toggleSchedDayOffUI(checked) {
                            document.getElementById('schedDayTimeFields').style.opacity = checked ? '0.4' : '1';
                            ['schedDayStart', 'schedDayEnd', 'schedDayBreak'].forEach(id => {
                                document.getElementById(id).disabled = checked;
                            });
                        }

                        function applySchedDayEditor() {
                            if (schedEditingDay === null) return;
                            const isOff = document.getElementById('schedDayOff').checked;
                            const start = document.getElementById('schedDayStart').value || '09:00';
                            const end = document.getElementById('schedDayEnd').value || '17:00';
                            const brk = document.getElementById('schedDayBreak').value || '60';
                            const uniform = document.getElementById('schedDayUniform').value.trim();

                            if (schedDayMode === 'custom') {
                                const fd = new FormData();
                                fd.append('ajax_save_override', '1');
                                fd.append('employee_id', schedCurrentEmpId);
                                fd.append('override_date', schedEditingDate);
                                fd.append('is_off', isOff ? '1' : '0');
                                fd.append('start_time', start);
                                fd.append('end_time', end);
                                fd.append('break_minutes', brk);
                                fd.append('uniform', uniform);
                                fetch(window.location.pathname + '?tab=schedule', {
                                        method: 'POST',
                                        body: fd
                                    })
                                    .then(r => r.text().then(txt => {
                                        let res;
                                        try {
                                            res = JSON.parse(txt);
                                        } catch (e) {
                                            throw new Error('Respon server tidak valid (HTTP ' + r.status + '): ' + txt.substring(0, 200));
                                        }
                                        return res;
                                    }))
                                    .then(res => {
                                        if (res && res.success) {
                                            schedOverrides[schedEditingDate] = {
                                                is_off: isOff ? 1 : 0,
                                                start_time: start,
                                                end_time: end,
                                                break_minutes: brk,
                                                uniform: uniform
                                            };
                                            document.getElementById('schedDayModal').classList.remove('open');
                                            renderScheduleCalendar();
                                        } else {
                                            alert('Gagal menyimpan tanggal khusus: ' + (res && res.message ? res.message : 'unknown error'));
                                        }
                                    })
                                    .catch(err => alert('Gagal menyimpan tanggal khusus: ' + err.message));
                                return;
                            }

                            const d = schedEditingDay;
                            document.getElementById('off_' + d).value = isOff ? '1' : '0';
                            document.getElementById('start_' + d).value = start;
                            document.getElementById('end_' + d).value = end;
                            document.getElementById('break_' + d).value = brk;
                            document.getElementById('uniform_' + d).value = uniform;
                            document.getElementById('schedDayModal').classList.remove('open');
                            renderScheduleCalendar();
                        }

                        function removeSchedDateOverride() {
                            if (!schedEditingDate || !schedCurrentEmpId) return;
                            const fd = new FormData();
                            fd.append('ajax_delete_override', '1');
                            fd.append('employee_id', schedCurrentEmpId);
                            fd.append('override_date', schedEditingDate);
                            fetch(window.location.pathname + '?tab=schedule', {
                                    method: 'POST',
                                    body: fd
                                })
                                .then(r => r.text().then(txt => {
                                    let res;
                                    try {
                                        res = JSON.parse(txt);
                                    } catch (e) {
                                        throw new Error('Respon server tidak valid (HTTP ' + r.status + '): ' + txt.substring(0, 200));
                                    }
                                    return res;
                                }))
                                .then(res => {
                                    if (res && res.success) {
                                        delete schedOverrides[schedEditingDate];
                                        document.getElementById('schedDayModal').classList.remove('open');
                                        renderScheduleCalendar();
                                    } else {
                                        alert('Gagal menghapus override: ' + (res && res.message ? res.message : 'unknown error'));
                                    }
                                })
                                .catch(err => alert('Gagal menghapus override: ' + err.message));
                        }

                        function toggleQuickOffUI(checked) {
                            document.getElementById('quickStart').disabled = checked;
                            document.getElementById('quickEnd').disabled = checked;
                            document.getElementById('quickBreak').disabled = checked;
                        }

                        function applyQuickEdit() {
                            if (!schedCurrentEmpId) {
                                alert('Pilih karyawan terlebih dahulu.');
                                return;
                            }
                            const checkedDays = Array.from(document.querySelectorAll('.sched-quick-day:checked')).map(el => parseInt(el.value));
                            if (!checkedDays.length) {
                                alert('Pilih minimal satu hari.');
                                return;
                            }
                            const isOff = document.getElementById('quickOff').checked;
                            const start = document.getElementById('quickStart').value || '09:00';
                            const end = document.getElementById('quickEnd').value || '17:00';
                            const brk = document.getElementById('quickBreak').value || '60';
                            checkedDays.forEach(d => {
                                document.getElementById('off_' + d).value = isOff ? '1' : '0';
                                document.getElementById('start_' + d).value = start;
                                document.getElementById('end_' + d).value = end;
                                document.getElementById('break_' + d).value = brk;
                            });
                            renderScheduleCalendar();
                        }

                        function loadEmpSchedule(empId) {
                            const form = document.getElementById('schedForm');
                            const allView = document.getElementById('schedAllStaffView');
                            if (empId === 'all') {
                                form.style.display = 'none';
                                schedCurrentEmpId = null;
                                allView.style.display = 'block';
                                loadAllStaffSchedules();
                                return;
                            }
                            allView.style.display = 'none';
                            if (!empId) {
                                form.style.display = 'none';
                                schedCurrentEmpId = null;
                                return;
                            }
                            schedCurrentEmpId = empId;
                            document.getElementById('schedEmpId').value = empId;
                            // Reset to defaults first
                            for (let d = 0; d < 7; d++) {
                                document.getElementById('start_' + d).value = '09:00';
                                document.getElementById('end_' + d).value = '17:00';
                                document.getElementById('break_' + d).value = '60';
                                document.getElementById('off_' + d).value = (d === 0) ? '1' : '0';
                                document.getElementById('uniform_' + d).value = '';
                            }
                            // Reset quick-edit controls
                            document.querySelectorAll('.sched-quick-day').forEach(el => el.checked = false);
                            document.getElementById('quickOff').checked = false;
                            toggleQuickOffUI(false);
                            schedOverrides = {};
                            form.style.display = 'block';
                            renderScheduleCalendar();
                            // Fetch existing weekly schedule via AJAX
                            fetch('<?php echo "?tab=schedule&ajax_schedule=1&emp_id="; ?>' + encodeURIComponent(empId))
                                .then(r => r.json())
                                .then(data => {
                                    if (data && data.length) {
                                        data.forEach(row => {
                                            const d = parseInt(row.day_of_week);
                                            if (row.start_time) document.getElementById('start_' + d).value = row.start_time.substring(0, 5);
                                            if (row.end_time) document.getElementById('end_' + d).value = row.end_time.substring(0, 5);
                                            document.getElementById('break_' + d).value = row.break_minutes || 60;
                                            document.getElementById('off_' + d).value = parseInt(row.is_off) === 1 ? '1' : '0';
                                            document.getElementById('uniform_' + d).value = row.uniform || '';
                                        });
                                    }
                                    renderScheduleCalendar();
                                })
                                .catch(() => {}); // Use defaults on error
                            // Fetch specific-date overrides via AJAX
                            fetch('<?php echo "?tab=schedule&ajax_overrides=1&emp_id="; ?>' + encodeURIComponent(empId))
                                .then(r => r.json())
                                .then(data => {
                                    schedOverrides = {};
                                    if (data && data.length) {
                                        data.forEach(row => {
                                            schedOverrides[row.override_date] = row;
                                        });
                                    }
                                    renderScheduleCalendar();
                                })
                                .catch(() => {});
                        }

                        // ─ JADWAL SERAGAM (bulk setup for all staff at once) ─
                        const UNI_DAY_FULL = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                        let uniAllLoaded = false;
                        let uniAllCache = {
                            employees: [],
                            byEmpDay: {}
                        };

                        (function renderUniQuickRow() {
                            const wrap = document.getElementById('uniQuickRow');
                            if (!wrap) return;
                            let html = '';
                            for (let d = 0; d <= 6; d++) {
                                html += '<div style="min-width:140px;">' +
                                    '<label style="display:block;font-size:10px;font-weight:700;color:var(--navy);margin-bottom:3px;">' + UNI_DAY_FULL[d] + '</label>' +
                                    '<input type="text" class="fi uni-quick-input" data-day="' + d + '" placeholder="Batik / Bebas Rapi" maxlength="100" style="width:100%;">' +
                                    '</div>';
                            }
                            wrap.innerHTML = html;
                        })();

                        function applyQuickToAll() {
                            if (!uniAllCache.employees.length) {
                                alert('Data karyawan belum siap dimuat, tunggu sebentar lalu coba lagi.');
                                return;
                            }
                            const quickVals = {};
                            let anyFilled = false;
                            document.querySelectorAll('.uni-quick-input').forEach(inp => {
                                const v = inp.value.trim();
                                if (v) anyFilled = true;
                                quickVals[inp.dataset.day] = v;
                            });
                            if (!anyFilled) {
                                alert('Isi minimal 1 hari di Setup Cepat sebelum menerapkan.');
                                return;
                            }
                            if (!confirm('Terapkan seragam ini ke SEMUA staff aktif untuk hari yang diisi? Nilai lama pada hari tsb akan tertimpa.')) {
                                return;
                            }
                            uniAllCache.employees.forEach(emp => {
                                for (let d = 0; d <= 6; d++) {
                                    const qv = quickVals[d];
                                    if (!qv) continue;
                                    const row = uniAllCache.byEmpDay[emp.id + '_' + d];
                                    const isOff = row && parseInt(row.is_off) === 1;
                                    if (isOff) continue;
                                    const cell = document.querySelector('.uni-cell[data-emp="' + emp.id + '"][data-day="' + d + '"]');
                                    if (cell) cell.value = qv;
                                }
                            });
                            saveUniformAll();
                        }

                        function loadUniformAllTable() {
                            const wrap = document.getElementById('uniAllTableWrap');
                            wrap.innerHTML = '<div class="loading" style="padding:20px;"><span class="spin"></span> Memuat...</div>';
                            fetch(window.location.pathname + '?tab=uniform&ajax_all_schedules=1')
                                .then(r => r.json())
                                .then(data => {
                                    const emps = data.employees || [];
                                    const byEmpDay = {};
                                    (data.weekly || []).forEach(row => {
                                        byEmpDay[row.employee_id + '_' + parseInt(row.day_of_week)] = row;
                                    });
                                    uniAllCache = {
                                        employees: emps,
                                        byEmpDay
                                    };
                                    if (!emps.length) {
                                        wrap.innerHTML = '<div style="padding:20px;color:var(--muted);font-size:11px;">Belum ada karyawan aktif.</div>';
                                        return;
                                    }
                                    let html = '<table style="width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap;">';
                                    html += '<thead><tr style="background:var(--bg);">' +
                                        '<th style="position:sticky;left:0;background:var(--bg);text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);z-index:1;">Karyawan</th>';
                                    for (let d = 0; d <= 6; d++) {
                                        html += '<th style="text-align:left;padding:8px 10px;border-bottom:1px solid var(--border);min-width:150px;">' + UNI_DAY_FULL[d] + '</th>';
                                    }
                                    html += '</tr></thead><tbody>';
                                    emps.forEach(emp => {
                                        html += '<tr>' +
                                            '<td style="position:sticky;left:0;background:#fff;font-weight:600;padding:6px 10px;border-bottom:1px solid var(--border);">' +
                                            (emp.employee_code ? esc(emp.employee_code) + ' — ' : '') + esc(emp.full_name) + '</td>';
                                        for (let d = 0; d <= 6; d++) {
                                            const row = byEmpDay[emp.id + '_' + d];
                                            const isOff = row && parseInt(row.is_off) === 1;
                                            html += '<td style="padding:6px 10px;border-bottom:1px solid var(--border);">';
                                            if (isOff) {
                                                html += '<span style="color:var(--red);font-weight:600;">🔴 Libur</span>';
                                            } else {
                                                const uniformVal = (row && row.uniform) ? row.uniform : '';
                                                html += '<input type="text" class="fi uni-cell" data-emp="' + emp.id + '" data-day="' + d + '" value="' + esc(uniformVal) + '" placeholder="Batik / Kemeja Putih / Bebas Rapi" maxlength="100" style="width:100%;min-width:150px;">';
                                            }
                                            html += '</td>';
                                        }
                                        html += '</tr>';
                                    });
                                    html += '</tbody></table>';
                                    wrap.innerHTML = html;
                                    uniAllLoaded = true;
                                })
                                .catch(() => {
                                    wrap.innerHTML = '<div style="padding:20px;color:var(--red);font-size:11px;">Gagal memuat data.</div>';
                                });
                        }

                        function esc(s) {
                            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        }

                        function saveUniformAll() {
                            const msg = document.getElementById('uniSaveMsg');
                            const rows = [];
                            document.querySelectorAll('.uni-cell').forEach(inp => {
                                rows.push({
                                    employee_id: parseInt(inp.dataset.emp),
                                    day_of_week: parseInt(inp.dataset.day),
                                    uniform: inp.value.trim()
                                });
                            });
                            if (!rows.length) {
                                msg.style.color = 'var(--red)';
                                msg.textContent = 'Tidak ada data untuk disimpan.';
                                return;
                            }
                            msg.style.color = 'var(--muted)';
                            msg.textContent = 'Menyimpan...';
                            const fd = new FormData();
                            fd.append('ajax_save_uniform_all', '1');
                            fd.append('rows', JSON.stringify(rows));
                            fetch(window.location.pathname + '?tab=uniform', {
                                    method: 'POST',
                                    body: fd
                                })
                                .then(r => r.text().then(txt => {
                                    let res;
                                    try {
                                        res = JSON.parse(txt);
                                    } catch (e) {
                                        throw new Error('Respon server tidak valid (HTTP ' + r.status + '): ' + txt.substring(0, 200));
                                    }
                                    return res;
                                }))
                                .then(res => {
                                    if (res && res.success) {
                                        msg.style.color = 'var(--green)';
                                        msg.textContent = '✅ Jadwal seragam semua staff berhasil disimpan (' + res.saved + ' entri).';
                                    } else {
                                        msg.style.color = 'var(--red)';
                                        msg.textContent = '❌ Gagal: ' + (res && res.message ? res.message : 'unknown error');
                                    }
                                })
                                .catch(err => {
                                    msg.style.color = 'var(--red)';
                                    msg.textContent = '❌ Gagal: ' + err.message;
                                });
                        }

                        // ─ ALL-STAFF COMBINED CALENDAR ─
                        function schedAllComputeDay(emp, dateStr, dow) {
                            const ov = (schedAllData.overridesByEmp[emp.id] || {})[dateStr];
                            if (ov) {
                                return {
                                    isOff: parseInt(ov.is_off) === 1,
                                    start: (ov.start_time || '09:00').substring(0, 5),
                                    end: (ov.end_time || '17:00').substring(0, 5)
                                };
                            }
                            const w = (schedAllData.weeklyByEmp[emp.id] || {})[dow];
                            if (w) {
                                return {
                                    isOff: parseInt(w.is_off) === 1,
                                    start: (w.start_time || '09:00').substring(0, 5),
                                    end: (w.end_time || '17:00').substring(0, 5)
                                };
                            }
                            return {
                                isOff: dow === 0,
                                start: '09:00',
                                end: '17:00'
                            };
                        }

                        function loadAllStaffSchedules() {
                            fetch(window.location.pathname + '?tab=schedule&ajax_all_schedules=1')
                                .then(r => r.json())
                                .then(data => {
                                    const weeklyByEmp = {};
                                    (data.weekly || []).forEach(row => {
                                        const eid = row.employee_id;
                                        if (!weeklyByEmp[eid]) weeklyByEmp[eid] = {};
                                        weeklyByEmp[eid][parseInt(row.day_of_week)] = row;
                                    });
                                    const overridesByEmp = {};
                                    (data.overrides || []).forEach(row => {
                                        const eid = row.employee_id;
                                        if (!overridesByEmp[eid]) overridesByEmp[eid] = {};
                                        overridesByEmp[eid][row.override_date] = row;
                                    });
                                    schedAllData = {
                                        employees: data.employees || [],
                                        weeklyByEmp,
                                        overridesByEmp
                                    };
                                    renderAllStaffCalendar();
                                })
                                .catch(() => {
                                    schedAllData = {
                                        employees: [],
                                        weeklyByEmp: {},
                                        overridesByEmp: {}
                                    };
                                    renderAllStaffCalendar();
                                });
                        }

                        function renderAllStaffCalendar() {
                            const grid = document.getElementById('schedAllCalGrid');
                            const title = document.getElementById('schedAllCalTitle');
                            if (!grid || !title) return;
                            title.textContent = SCHED_MONTH_NAMES[schedAllCalMonth] + ' ' + schedAllCalYear;
                            grid.innerHTML = '';
                            const today = new Date();
                            const todayStr = schedDateStr(today.getFullYear(), today.getMonth(), today.getDate());
                            SCHED_DAY_SHORT.forEach(d => {
                                const h = document.createElement('div');
                                h.textContent = d;
                                h.className = 'sc-cal-dow';
                                grid.appendChild(h);
                            });
                            if (!schedAllData) return;
                            const firstDay = new Date(schedAllCalYear, schedAllCalMonth, 1).getDay();
                            const daysInMonth = new Date(schedAllCalYear, schedAllCalMonth + 1, 0).getDate();
                            for (let i = 0; i < firstDay; i++) {
                                const empty = document.createElement('div');
                                empty.className = 'sc-cal-cell';
                                empty.style.visibility = 'hidden';
                                grid.appendChild(empty);
                            }
                            for (let day = 1; day <= daysInMonth; day++) {
                                const dow = new Date(schedAllCalYear, schedAllCalMonth, day).getDay();
                                const dateStr = schedDateStr(schedAllCalYear, schedAllCalMonth, day);
                                let workingCount = 0;
                                const offNames = [];
                                schedAllData.employees.forEach(emp => {
                                    if (schedAllComputeDay(emp, dateStr, dow).isOff) {
                                        offNames.push(emp.full_name);
                                    } else {
                                        workingCount++;
                                    }
                                });
                                const cell = document.createElement('div');
                                cell.onclick = () => openAllStaffDayModal(dateStr);
                                cell.className = 'sc-cal-cell' + (dateStr === todayStr ? ' today' : '');
                                let offHtml = '';
                                if (offNames.length) {
                                    const offTitle = offNames.join(', ').replace(/"/g, '&quot;');
                                    offHtml = '<span class="sc-cal-badge sc-cal-badge-off" title="' + offTitle + '">🔴 ' + offNames.length + ' libur</span>';
                                }
                                cell.innerHTML = '<div class="sc-cal-daynum">' + day + '</div>' +
                                    '<span class="sc-cal-badge sc-cal-badge-in">🟢 ' + workingCount + ' masuk</span>' + offHtml;
                                grid.appendChild(cell);
                            }
                        }

                        function changeSchedAllMonth(delta) {
                            schedAllCalMonth += delta;
                            if (schedAllCalMonth < 0) {
                                schedAllCalMonth = 11;
                                schedAllCalYear--;
                            } else if (schedAllCalMonth > 11) {
                                schedAllCalMonth = 0;
                                schedAllCalYear++;
                            }
                            renderAllStaffCalendar();
                        }

                        function openAllStaffDayModal(dateStr) {
                            if (!schedAllData) return;
                            const parts = dateStr.split('-').map(Number);
                            const dateObj = new Date(parts[0], parts[1] - 1, parts[2]);
                            const dow = dateObj.getDay();
                            document.getElementById('schedAllDayModalTitle').textContent = '\uD83D\uDDD3\uFE0F ' + dateObj.toLocaleDateString('id-ID', {
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric'
                            });
                            const working = [];
                            const off = [];
                            schedAllData.employees.forEach(emp => {
                                const info = schedAllComputeDay(emp, dateStr, dow);
                                if (info.isOff) off.push(emp);
                                else working.push({
                                    emp,
                                    info
                                });
                            });
                            let html = '<div style="font-weight:700;color:var(--green);margin-bottom:6px;">\uD83D\uDFE2 Masuk Kerja (' + working.length + ')</div>';
                            if (working.length) {
                                html += '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:12px;">';
                                working.forEach(w => {
                                    html += '<div style="display:flex;justify-content:space-between;gap:8px;border:1px solid var(--border);border-radius:6px;padding:6px 8px;">' +
                                        '<span style="font-weight:600;">' + (w.emp.employee_code ? w.emp.employee_code + ' \u2014 ' : '') + w.emp.full_name + '</span>' +
                                        '<span style="color:var(--muted);white-space:nowrap;">' + w.info.start + '-' + w.info.end + '</span></div>';
                                });
                                html += '</div>';
                            } else {
                                html += '<div style="color:var(--muted);margin-bottom:12px;">Tidak ada staf yang masuk.</div>';
                            }
                            html += '<div style="font-weight:700;color:var(--red);margin-bottom:6px;">\uD83D\uDD34 Libur (' + off.length + ')</div>';
                            if (off.length) {
                                html += '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
                                off.forEach(emp => {
                                    html += '<span style="background:#fef2f2;color:#991b1b;font-size:10px;font-weight:600;padding:3px 8px;border-radius:4px;">' + emp.full_name + '</span>';
                                });
                                html += '</div>';
                            } else {
                                html += '<div style="color:var(--muted);">Tidak ada.</div>';
                            }
                            document.getElementById('schedAllDayModalBody').innerHTML = html;
                            document.getElementById('schedAllDayModal').classList.add('open');
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            renderScheduleCalendar();
                        });
                    </script>

                    <?php include '../../includes/footer.php'; ?>