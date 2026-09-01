<?php

/**
 * HK ROOM ALLOCATION - SHARED HELPER
 * ------------------------------------------------------------------
 * Core logic used by BOTH:
 *   - modules/frontdesk/hk-allocation.php (the admin UI)
 *   - api/hk-allocation-cron.php (the daily auto-sync cron, no UI needed)
 *
 * Kept in one place so the admin page and the cron always behave
 * identically (same priority rules, same fair-distribution algorithm,
 * same attendance-cutoff rule).
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

if (!function_exists('ensureHkTables')) {
    function ensureHkTables($db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS frontdesk_hk_staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_staff_name (staff_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS frontdesk_hk_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_date DATE NOT NULL,
            room_id INT NOT NULL,
            room_number VARCHAR(30) NOT NULL,
            task_code ENUM('B2B','OD','VD','VC') NOT NULL,
            priority_order TINYINT NOT NULL,
            assigned_staff VARCHAR(100) NOT NULL,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_daily_room_task (assignment_date, room_id, task_code),
            KEY idx_daily (assignment_date),
            KEY idx_staff (assigned_staff)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('buildHkTasks')) {
    function buildHkTasks($db, $workDate)
    {
        $prevDate = date('Y-m-d', strtotime($workDate . ' -1 day'));

        $rows = $db->fetchAll("SELECT
                r.id,
                r.room_number,
                r.status,
                COALESCE(rt.type_name, 'Standard') as room_type,
                (
                                    SELECT g1.guest_name
                                    FROM bookings b1
                                    LEFT JOIN guests g1 ON b1.guest_id = g1.id
                                    WHERE b1.room_id = r.id
                                        AND b1.status = 'checked_in'
                                    ORDER BY b1.id DESC
                    LIMIT 1
                            ) as inhouse_guest,
                            (
                                    SELECT DATE(b1.check_in_date)
                                    FROM bookings b1
                                    WHERE b1.room_id = r.id
                                        AND b1.status = 'checked_in'
                                    ORDER BY b1.id DESC
                                    LIMIT 1
                            ) as checked_in_date,
                            (
                                    SELECT g2.guest_name
                                    FROM bookings b2
                                    LEFT JOIN guests g2 ON b2.guest_id = g2.id
                                    WHERE b2.room_id = r.id
                                        AND DATE(b2.check_out_date) = ?
                                        AND b2.status IN ('checked_in','checked_out')
                                    ORDER BY b2.id DESC
                                    LIMIT 1
                            ) as departure_guest,
                            (
                                    SELECT g3.guest_name
                                    FROM bookings b3
                                    LEFT JOIN guests g3 ON b3.guest_id = g3.id
                                    WHERE b3.room_id = r.id
                                        AND DATE(b3.check_in_date) = ?
                                        AND b3.status IN ('pending','confirmed','checked_in')
                                    ORDER BY b3.id DESC
                                    LIMIT 1
                            ) as arrival_guest,
                            (
                                    SELECT COUNT(*)
                                    FROM bookings bd
                                    WHERE bd.room_id = r.id
                                        AND DATE(bd.check_out_date) = ?
                                        AND bd.status IN ('checked_in','checked_out')
                            ) as departures_today,
                            (
                                    SELECT COUNT(*)
                                    FROM bookings ba
                                    WHERE ba.room_id = r.id
                                        AND DATE(ba.check_in_date) = ?
                                        AND ba.status IN ('pending','confirmed','checked_in')
                            ) as arrivals_today,
                            (
                                    SELECT COUNT(*)
                                    FROM bookings bp
                                    WHERE bp.room_id = r.id
                                        AND bp.status IN ('pending','confirmed','checked_in','checked_out')
                                        AND DATE(bp.check_in_date) <= ?
                                        AND DATE(bp.check_out_date) > ?
                            ) as occupied_prev_night
            FROM rooms r
            LEFT JOIN room_types rt ON r.room_type_id = rt.id
                    ORDER BY r.room_number ASC", [$workDate, $workDate, $workDate, $workDate, $prevDate, $prevDate]) ?: [];

        $tasks = [];
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $arrivalsToday = (int)($r['arrivals_today'] ?? 0) > 0;
            $departuresToday = (int)($r['departures_today'] ?? 0) > 0;
            $occupiedPrevNight = (int)($r['occupied_prev_night'] ?? 0) > 0;

            $checkedInDate = (string)($r['checked_in_date'] ?? '');
            $isCheckedInNow = !empty($r['inhouse_guest']) || $status === 'occupied';
            $isCheckInTodayNow = $isCheckedInNow && $checkedInDate === $workDate;
            // Jika kamar checkout hari ini, prioritas status HK bukan OD lagi.
            // Setelah lewat 00:00 kamar tersebut harus terbaca sebagai pekerjaan departure (VD/B2B).
            $isOngoingInHouse = $isCheckedInNow && !$isCheckInTodayNow && !$departuresToday;

            if ($status === 'maintenance' || $status === 'blocked') {
                continue;
            }

            $taskCode = null;
            $priority = 99;
            $label = '';

            if ($departuresToday && $arrivalsToday) {
                $taskCode = 'B2B';
                $priority = 1;
                $label = 'Back to Back (CO + CI hari ini)';
            } elseif ($arrivalsToday && !$occupiedPrevNight) {
                $taskCode = 'VC';
                $priority = 4;
                $label = 'Vacant Clean (CI hari ini, kemarin kosong)';
            } elseif ($departuresToday && !$arrivalsToday) {
                $taskCode = 'VD';
                $priority = 3;
                $label = 'Vacant Dirty (CO hari ini)';
            } elseif ($isOngoingInHouse) {
                $taskCode = 'OD';
                $priority = 2;
                $label = 'Occupied / In-House';
            } elseif ($status === 'cleaning') {
                $taskCode = 'VD';
                $priority = 3;
                $label = 'Vacant Dirty';
            } elseif ($status === 'available') {
                $taskCode = 'VC';
                $priority = 4;
                $label = 'Vacant Clean';
            }

            if ($taskCode === null) {
                continue;
            }

            $inhouseContextGuest = '';
            if (!empty($r['departure_guest'])) {
                $inhouseContextGuest = (string)$r['departure_guest'];
            } elseif (!empty($r['inhouse_guest'])) {
                $inhouseContextGuest = (string)$r['inhouse_guest'];
            }

            $tasks[] = [
                'key' => (int)$r['id'] . '|' . $taskCode,
                'room_id' => (int)$r['id'],
                'room_number' => (string)$r['room_number'],
                'room_type' => (string)$r['room_type'],
                'task_code' => $taskCode,
                'task_label' => $label,
                'priority_order' => $priority,
                'inhouse_guest' => $inhouseContextGuest,
                'next_guest' => (string)($r['arrival_guest'] ?? ''),
                'room_status' => $status
            ];
        }

        usort($tasks, function ($a, $b) {
            if ($a['priority_order'] !== $b['priority_order']) {
                return $a['priority_order'] <=> $b['priority_order'];
            }
            return strnatcmp($a['room_number'], $b['room_number']);
        });

        return $tasks;
    }
}

if (!function_exists('autoAssignFair')) {
    function autoAssignFair($tasks, $staffNames, $seedCounts = [], $maxPerStaff = null, $overflowAssignee = '')
    {
        $result = [];
        $counts = [];

        foreach ($staffNames as $name) {
            $counts[$name] = (int)($seedCounts[$name] ?? 0);
        }

        if ($overflowAssignee !== '' && !isset($counts[$overflowAssignee])) {
            $counts[$overflowAssignee] = (int)($seedCounts[$overflowAssignee] ?? 0);
        }

        if (empty($staffNames)) {
            if ($overflowAssignee !== '') {
                foreach ($tasks as $task) {
                    $result[$task['key']] = $overflowAssignee;
                    if (!isset($counts[$overflowAssignee])) {
                        $counts[$overflowAssignee] = 0;
                    }
                    $counts[$overflowAssignee]++;
                }
            }
            return ['assignments' => $result, 'counts' => $counts];
        }

        $staffIndex = array_values($staffNames);
        $cursor = 0;

        foreach ($tasks as $task) {
            $eligibleCounts = [];
            foreach ($staffIndex as $name) {
                if ($maxPerStaff === null || $counts[$name] < $maxPerStaff) {
                    $eligibleCounts[$name] = $counts[$name];
                }
            }

            if (empty($eligibleCounts)) {
                if ($overflowAssignee !== '') {
                    $result[$task['key']] = $overflowAssignee;
                    if (!isset($counts[$overflowAssignee])) {
                        $counts[$overflowAssignee] = 0;
                    }
                    $counts[$overflowAssignee]++;
                }
                continue;
            }

            $minCount = min($eligibleCounts);
            $candidateIndexes = [];
            foreach ($staffIndex as $idx => $name) {
                if (isset($eligibleCounts[$name]) && $counts[$name] === $minCount) {
                    $candidateIndexes[] = $idx;
                }
            }

            $pickIdx = $candidateIndexes[0];
            foreach ($candidateIndexes as $ci) {
                if ($ci >= $cursor) {
                    $pickIdx = $ci;
                    break;
                }
            }

            $pickedStaff = $staffIndex[$pickIdx];
            $result[$task['key']] = $pickedStaff;
            $counts[$pickedStaff]++;
            $cursor = ($pickIdx + 1) % count($staffIndex);
        }

        return ['assignments' => $result, 'counts' => $counts];
    }
}

if (!function_exists('syncHkStaffFromPayroll')) {
    function syncHkStaffFromPayroll($db)
    {
        $rows = $db->fetchAll(
            "SELECT full_name
             FROM payroll_employees
             WHERE is_active = 1
               AND (
                    LOWER(COALESCE(department, '')) LIKE '%housekeeping%'
                    OR LOWER(COALESCE(department, '')) = 'hk'
                    OR LOWER(COALESCE(position, '')) LIKE '%housekeeping%'
                    OR LOWER(COALESCE(position, '')) LIKE 'hk%'
               )
             ORDER BY full_name ASC"
        ) ?: [];

        $names = [];
        foreach ($rows as $r) {
            $n = trim((string)($r['full_name'] ?? ''));
            if ($n !== '') {
                $names[$n] = true;
            }
        }
        $names = array_keys($names);

        $db->beginTransaction();
        $db->query("UPDATE frontdesk_hk_staff SET is_active = 0");
        foreach ($names as $name) {
            $db->query(
                "INSERT INTO frontdesk_hk_staff (staff_name, is_active) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()",
                [$name]
            );
        }
        $db->commit();

        return $names;
    }
}

if (!function_exists('getAttendanceEligibleHkStaff')) {
    /**
     * Determine which HK staff should actually receive rooms today.
     *
     * Two independent exclusion rules are applied:
     *   1. Approved leave/day-off ("libur/cuti") covering $workDate - applies
     *      IMMEDIATELY regardless of time of day, since a scheduled day off
     *      is known in advance and isn't the same as "hasn't checked in yet".
     *   2. Attendance cutoff - once $cutoffTime has passed today, anyone who
     *      hasn't checked in (and isn't already excluded by rule 1) is also
     *      treated as absent, so their rooms can be redistributed.
     */
    function getAttendanceEligibleHkStaff($db, $staffNames, $workDate, $cutoffTime = '09:00:00')
    {
        $result = [
            'enforced' => false,
            'eligible_staff' => $staffNames,
            'absent_staff' => [],
            'cutoff_time' => $cutoffTime,
            'reason' => ''
        ];

        if (empty($staffNames)) {
            return $result;
        }

        $normalizeName = function ($name) {
            $s = trim((string)$name);
            $s = preg_replace('/\s+/', ' ', $s);
            return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
        };

        $wantedByNorm = [];
        foreach ($staffNames as $sn) {
            $wantedByNorm[$normalizeName($sn)] = $sn;
        }

        // Rule 1: approved leave/day-off covering $workDate - known in advance,
        // so exclude immediately (no need to wait for the attendance cutoff).
        $onLeaveNorm = [];
        try {
            $leaveRows = $db->fetchAll(
                "SELECT pe.full_name
                 FROM leave_requests lr
                 JOIN payroll_employees pe ON pe.id = lr.employee_id
                 WHERE lr.status = 'approved' AND ? BETWEEN lr.start_date AND lr.end_date",
                [$workDate]
            ) ?: [];
            foreach ($leaveRows as $lr) {
                $norm = $normalizeName($lr['full_name'] ?? '');
                if (isset($wantedByNorm[$norm])) {
                    $onLeaveNorm[$norm] = true;
                }
            }
        } catch (Exception $e) {
            // leave_requests table may not exist yet in this business's DB - ignore.
            $onLeaveNorm = [];
        }

        $today = date('Y-m-d');
        $nowTime = date('H:i:s');
        $cutoffPassed = ($workDate === $today && $nowTime >= $cutoffTime);

        if (!$cutoffPassed && empty($onLeaveNorm)) {
            return $result;
        }

        $result['enforced'] = true;

        if (!$cutoffPassed) {
            // Before cutoff: only exclude staff on approved leave. Everyone
            // else's attendance isn't known/fair to judge yet.
            $eligible = [];
            $absent = [];
            foreach ($staffNames as $name) {
                if (isset($onLeaveNorm[$normalizeName($name)])) {
                    $absent[] = $name;
                } else {
                    $eligible[] = $name;
                }
            }
            $result['eligible_staff'] = $eligible;
            $result['absent_staff'] = $absent;
            $result['reason'] = 'Libur/cuti disetujui';
            return $result;
        }

        // Past cutoff: also check attendance, on top of the leave exclusions above.
        try {
            $rows = $db->fetchAll(
                "SELECT e.full_name, a.check_in_time
                 FROM payroll_employees e
                 LEFT JOIN payroll_attendance a
                    ON a.employee_id = e.id
                   AND a.attendance_date = ?
                 WHERE e.is_active = 1",
                [$workDate]
            ) ?: [];
        } catch (Exception $e) {
            // Fail-safe: jika tabel absensi belum tersedia, tetap terapkan
            // pengecualian libur/cuti (jika ada), tapi jangan blokir sisanya.
            if (empty($onLeaveNorm)) {
                $result['enforced'] = false;
                $result['reason'] = 'Data absensi belum tersedia';
                return $result;
            }
            $eligible = [];
            $absent = [];
            foreach ($staffNames as $name) {
                if (isset($onLeaveNorm[$normalizeName($name)])) {
                    $absent[] = $name;
                } else {
                    $eligible[] = $name;
                }
            }
            $result['eligible_staff'] = $eligible;
            $result['absent_staff'] = $absent;
            $result['reason'] = 'Libur/cuti disetujui (data absensi belum tersedia)';
            return $result;
        }

        $checkInByName = [];
        foreach ($rows as $r) {
            $nm = trim((string)($r['full_name'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $norm = $normalizeName($nm);
            if (!isset($wantedByNorm[$norm])) {
                continue;
            }
            // Keep first non-null check-in if duplicates exist.
            if (!array_key_exists($norm, $checkInByName) || $checkInByName[$norm] === null) {
                $checkInByName[$norm] = $r['check_in_time'] ?? null;
            }
        }

        $eligible = [];
        $absent = [];
        foreach ($staffNames as $name) {
            $normName = $normalizeName($name);
            if (isset($onLeaveNorm[$normName])) {
                $absent[] = $name;
                continue;
            }
            $checkIn = $checkInByName[$normName] ?? null;
            $time = $checkIn ? date('H:i:s', strtotime((string)$checkIn)) : null;
            if ($time !== null && $time <= $cutoffTime) {
                $eligible[] = $name;
            } else {
                $absent[] = $name;
            }
        }

        $result['eligible_staff'] = $eligible;
        $result['absent_staff'] = $absent;
        $result['reason'] = 'Cutoff absensi ' . $cutoffTime . (!empty($onLeaveNorm) ? ' + libur/cuti disetujui' : '');
        return $result;
    }
}

if (!function_exists('hkGenerateAssignments')) {
    /**
     * Full (re)generate + persist: wipes today's assignments and re-creates
     * them from scratch, fairly distributed among the given staff list.
     * Used for the "Generate Ulang Otomatis" button AND for self-healing
     * when nothing has been generated yet for the day.
     */
    function hkGenerateAssignments($db, $workDate, array $staffNames, $createdBy = null)
    {
        $tasks = buildHkTasks($db, $workDate);

        $maxPerStaff = null;
        if (count($staffNames) > 0 && count($tasks) >= count($staffNames)) {
            $maxPerStaff = intdiv(count($tasks), count($staffNames));
        }

        $auto = autoAssignFair($tasks, $staffNames, [], $maxPerStaff, 'TEAM');
        $assignMap = $auto['assignments'];

        $db->beginTransaction();
        $db->query("DELETE FROM frontdesk_hk_assignments WHERE assignment_date = ?", [$workDate]);

        $count = 0;
        foreach ($tasks as $task) {
            $assigned = $assignMap[$task['key']] ?? '';
            if ($assigned === '') {
                continue;
            }
            $db->query(
                "INSERT INTO frontdesk_hk_assignments
                (assignment_date, room_id, room_number, task_code, priority_order, assigned_staff, is_manual, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
                [
                    $workDate,
                    $task['room_id'],
                    $task['room_number'],
                    $task['task_code'],
                    $task['priority_order'],
                    $assigned,
                    $createdBy
                ]
            );
            $count++;
        }

        $db->commit();
        return $count;
    }
}

if (!function_exists('hkSyncDailyState')) {
    /**
     * Idempotent daily "keep it correct" entry point. Safe to call as often
     * as needed (page load or cron) - it only changes the database when
     * something actually needs it:
     *
     *   1. Nothing generated yet for $workDate -> generate now. Before the
     *      09:00 attendance cutoff this uses ALL active HK staff (nobody's
     *      attendance matters yet). After the cutoff it uses only staff who
     *      have already checked in (self-heal if the midnight run was missed).
     *   2. Already generated, and it's past the 09:00 cutoff, and some staff
     *      still haven't checked in -> redistribute ONLY the rooms auto-assigned
     *      to those absent staff to staff who did check in. Rooms already
     *      assigned to present staff (auto or manual) are never touched.
     */
    function hkSyncDailyState($db, $workDate, $createdBy = null)
    {
        $result = [
            'generated' => false,
            'redistributed' => 0,
            'absent_staff' => [],
            'enforced' => false,
            'reason' => ''
        ];

        syncHkStaffFromPayroll($db);

        $staffRows = $db->fetchAll(
            "SELECT staff_name FROM frontdesk_hk_staff WHERE is_active = 1 ORDER BY staff_name ASC"
        ) ?: [];
        $staffNames = array_map(fn($r) => $r['staff_name'], $staffRows);

        if (empty($staffNames)) {
            return $result;
        }

        $attendanceGate = getAttendanceEligibleHkStaff($db, $staffNames, $workDate, '09:00:00');
        $result['enforced'] = $attendanceGate['enforced'];
        $result['absent_staff'] = $attendanceGate['absent_staff'];
        $result['reason'] = $attendanceGate['reason'];

        $existingCount = (int)($db->fetchOne(
            "SELECT COUNT(*) as c FROM frontdesk_hk_assignments WHERE assignment_date = ?",
            [$workDate]
        )['c'] ?? 0);

        if ($existingCount === 0) {
            $staffForGeneration = ($attendanceGate['enforced'] && !empty($attendanceGate['eligible_staff']))
                ? $attendanceGate['eligible_staff']
                : $staffNames;
            hkGenerateAssignments($db, $workDate, $staffForGeneration, $createdBy);
            $result['generated'] = true;
            return $result;
        }

        if (!$attendanceGate['enforced'] || empty($attendanceGate['absent_staff'])) {
            return $result;
        }

        // Past cutoff, some staff absent, and a plan already exists: redistribute
        // ONLY their rooms, leaving everyone else's assignment exactly as-is.
        $savedRows = $db->fetchAll(
            "SELECT id, room_id, task_code, assigned_staff, is_manual
             FROM frontdesk_hk_assignments
             WHERE assignment_date = ?",
            [$workDate]
        ) ?: [];

        $savedByKey = [];
        foreach ($savedRows as $row) {
            $savedByKey[(int)$row['room_id'] . '|' . $row['task_code']] = $row;
        }

        $tasks = buildHkTasks($db, $workDate);
        $tasksByKey = [];
        foreach ($tasks as $t) {
            $tasksByKey[$t['key']] = $t;
        }

        $absentSet = array_fill_keys($attendanceGate['absent_staff'], true);

        $seedCounts = [];
        $freedTasks = [];
        $freedRowIds = [];

        foreach ($tasks as $task) {
            $key = $task['key'];
            $row = $savedByKey[$key] ?? null;

            if ($row === null) {
                // New task with no assignment yet (e.g. a room added after generation).
                $freedTasks[] = $task;
                continue;
            }

            $assignedStaff = (string)$row['assigned_staff'];
            $isManual = (int)$row['is_manual'] === 1;

            if (!$isManual && isset($absentSet[$assignedStaff])) {
                // Auto assignment belonging to a staff who hasn't checked in - redistribute it.
                $freedTasks[] = $task;
                $freedRowIds[$key] = (int)$row['id'];
                continue;
            }

            // Present staff (or a manual override) - room number stays untouched.
            $seedCounts[$assignedStaff] = ($seedCounts[$assignedStaff] ?? 0) + 1;
        }

        if (empty($freedTasks)) {
            return $result;
        }

        $effectiveStaff = !empty($attendanceGate['eligible_staff']) ? $attendanceGate['eligible_staff'] : $staffNames;
        $maxPerStaff = null;
        if (count($effectiveStaff) > 0 && count($tasks) >= count($effectiveStaff)) {
            $maxPerStaff = intdiv(count($tasks), count($effectiveStaff));
        }

        $auto = autoAssignFair($freedTasks, $effectiveStaff, $seedCounts, $maxPerStaff, 'TEAM');

        $db->beginTransaction();
        $redistributed = 0;
        foreach ($auto['assignments'] as $key => $newStaff) {
            if (isset($freedRowIds[$key])) {
                $db->query(
                    "UPDATE frontdesk_hk_assignments SET assigned_staff = ?, is_manual = 0, updated_at = NOW() WHERE id = ?",
                    [$newStaff, $freedRowIds[$key]]
                );
                $redistributed++;
            } elseif (isset($tasksByKey[$key])) {
                $task = $tasksByKey[$key];
                $db->query(
                    "INSERT INTO frontdesk_hk_assignments
                    (assignment_date, room_id, room_number, task_code, priority_order, assigned_staff, is_manual, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
                    [
                        $workDate,
                        $task['room_id'],
                        $task['room_number'],
                        $task['task_code'],
                        $task['priority_order'],
                        $newStaff,
                        $createdBy
                    ]
                );
                $redistributed++;
            }
        }
        $db->commit();

        $result['redistributed'] = $redistributed;
        return $result;
    }
}
