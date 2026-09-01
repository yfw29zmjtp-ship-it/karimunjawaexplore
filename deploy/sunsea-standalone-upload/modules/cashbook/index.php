<?php

/**
 * MULTI-BUSINESS MANAGEMENT SYSTEM
 * Buku Kas Besar - List & Overview
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/print-helper.php';

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();

// Auto-drop FK constraint on cash_book.created_by (references users in master DB, not business DB)
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` DROP FOREIGN KEY `cash_book_ibfk_3`");
} catch (Exception $e) { /* already dropped or doesn't exist */
}

// Auto-fix: allow NULL division_id/category_id - Setor Tunai (cash_transfer) rows
// have no division/category since they're an internal cash<->bank transfer, not a
// real income/expense transaction. Original schema had these NOT NULL.
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `division_id` INT NULL");
} catch (Exception $e) { /* ignore */
}
try {
    $db->getConnection()->exec("ALTER TABLE `cash_book` MODIFY COLUMN `category_id` INT NULL");
} catch (Exception $e) { /* ignore */
}

// ==========================================
// BACKFILL: Setor Tunai (cash_transfers, master DB) rows that are missing a
// linked cash_book entry (business DB) - happens for transfers made before
// this link existed, or if a transfer was made without visiting the
// Ringkasan Setor Tunai page (which also runs this same backfill). Doing it
// here too means simply opening Buku Kas is enough to sync them in.
// ==========================================
try {
    $btMasterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $btMasterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Make sure cash_transfers table + link column exist (no-op if already there)
    try {
        $btMasterDb->exec("ALTER TABLE cash_transfers ADD COLUMN cash_book_id INT NULL AFTER archived_by");
    } catch (Exception $e) { /* column already exists or table doesn't exist */
    }

    $btBusinessId = getMasterBusinessId();

    $missingStmt = $btMasterDb->prepare("
        SELECT ct.*, ca_bank.account_name as bank_name
        FROM cash_transfers ct
        LEFT JOIN cash_accounts ca_bank ON ct.bank_account_id = ca_bank.id
        WHERE ct.business_id = ? AND (ct.cash_book_id IS NULL OR ct.cash_book_id = 0)
    ");
    $missingStmt->execute([$btBusinessId]);
    $btMissingTransfers = $missingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($btMissingTransfers as $mt) {
        $rawDesc = $mt['description'] ?? '';
        $penyetor = '';
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $rawDesc, $m)) {
            $penyetor = $m[1];
            $rawDesc = $m[2];
        }
        $cbData = [
            'transaction_date' => $mt['transfer_date'],
            'transaction_time' => $mt['transfer_time'],
            'division_id' => null,
            'category_id' => null,
            'transaction_type' => 'expense',
            'amount' => $mt['amount'],
            'description' => "Pemindahan Uang / Setoran Harian ke " . ($mt['bank_name'] ?: 'rekening bank')
                . ($penyetor ? " - Penyetor: {$penyetor}" : '') . ($rawDesc ? " - {$rawDesc}" : ''),
            'payment_method' => 'cash',
            'cash_account_id' => $mt['cash_account_id'],
            'created_by' => $mt['created_by'],
            'source_type' => 'cash_transfer',
            'is_editable' => 0
        ];
        if ($db->insert('cash_book', $cbData)) {
            $btNewCashBookId = $db->getConnection()->lastInsertId();
            $btMasterDb->prepare("UPDATE cash_transfers SET cash_book_id = ? WHERE id = ?")
                ->execute([$btNewCashBookId, $mt['id']]);
        }
    }
} catch (Exception $e) {
    error_log("index.php: backfill cash_book for cash_transfers failed: " . $e->getMessage());
}

// ==========================================
// AUTO-SYNC UNSYNCED BOOKING PAYMENTS TO CASHBOOK
// Works on both local AND hosting (with/without synced_to_cashbook column)
// ==========================================
try {
    // Check if this is a hotel business with booking_payments table
    $hasBookingPayments = false;
    try {
        $db->getConnection()->query("SELECT 1 FROM booking_payments LIMIT 1");
        $hasBookingPayments = true;
    } catch (\Throwable $e) {
    }

    if ($hasBookingPayments) {
        // Ensure synced_to_cashbook column exists
        $hasSyncCol = false;
        try {
            $syncColChk = $db->getConnection()->query("SHOW COLUMNS FROM booking_payments LIKE 'synced_to_cashbook'");
            $hasSyncCol = $syncColChk && $syncColChk->rowCount() > 0;
        } catch (\Throwable $e) {
        }
        if (!$hasSyncCol) {
            try {
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN synced_to_cashbook TINYINT(1) NOT NULL DEFAULT 0");
                $db->getConnection()->exec("ALTER TABLE booking_payments ADD COLUMN cashbook_id INT(11) DEFAULT NULL");
                $hasSyncCol = true;
            } catch (\Throwable $e) {
                error_log("Cashbook page: Cannot add synced_to_cashbook column: " . $e->getMessage());
                $hasSyncCol = false;
            }
        }

        // Check if there are payments to sync
        $needsSync = false;
        if ($hasSyncCol) {
            $unsyncedCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM booking_payments WHERE synced_to_cashbook = 0");
            $needsSync = $unsyncedCount && (int)$unsyncedCount['cnt'] > 0;
        } else {
            // Fallback: always check recent payments
            $recentCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM booking_payments WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)");
            $needsSync = $recentCount && (int)$recentCount['cnt'] > 0;
        }

        if ($needsSync) {
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
            $masterDb = null;
            try {
                $masterDb = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                    DB_USER,
                    DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (\Throwable $masterErr) {
                // FALLBACK: Use current DB connection if Master DB fails
                // Critical for Single-DB Hosting environments
                if (defined('DB_NAME')) {
                    try {
                        $masterDb = new PDO(
                            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                            DB_USER,
                            DB_PASS,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );
                    } catch (\Throwable $e2) {
                        $masterDb = $db->getConnection();
                    }
                } else {
                    $masterDb = $db->getConnection();
                }
            }
            $businessId = $_SESSION['business_id'] ?? 1;

            $cbUserId = $currentUser['id'] ?? 1;
            $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
            if (!$userExists) {
                $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $cbUserId = $firstUser['id'] ?? 1;
            }

            $hasCashAccountId = false;
            try {
                $colChk = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
            } catch (\Throwable $e) {
            }

            // Detect payment_method ENUM
            $allowedPaymentMethods = null;
            try {
                $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                    preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                    $allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
                }
            } catch (\Throwable $e) {
            }

            $division = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%front%' OR LOWER(division_name) LIKE '%room%' OR LOWER(division_name) LIKE '%kamar%' ORDER BY id ASC LIMIT 1");
            if (!$division) $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
            $divisionId = $division['id'] ?? 1;

            $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id ASC LIMIT 1");
            if (!$category) $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
            $categoryId = $category['id'] ?? 1;

            // ============================================
            // IMPORTANT: Only sync payments where:
            // - Direct Booking: sync anytime (already paid)
            // - OTA: ONLY sync when booking status = checked_in or checked_out
            // This prevents OTA payments from entering kas before check-in!
            // ============================================
            // OTA detection using LIKE (substring match) - consistent with PHP-side detection
            // This prevents OTA variants like 'Booking Com', 'agoda_direct' etc from being treated as direct

            if ($hasSyncCol) {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, b.status as booking_status, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.synced_to_cashbook = 0 
                    AND (
                        -- Direct Booking: sync anytime (source does NOT contain any OTA keyword)
                        (
                            LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%agoda%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%booking%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%tiket%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%traveloka%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%airbnb%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%expedia%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%pegipegi%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%ota%'
                        )
                        OR
                        -- OTA: only sync if checked_in or checked_out
                        (b.status IN ('checked_in', 'checked_out'))
                    )
                    ORDER BY bp.id ASC
                ");
            } else {
                $unsyncedPayments = $db->fetchAll("
                    SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, bp.payment_date,
                           b.booking_code, b.booking_source, b.final_price, b.status as booking_status, g.guest_name, r.room_number
                    FROM booking_payments bp
                    JOIN bookings b ON bp.booking_id = b.id
                    LEFT JOIN guests g ON b.guest_id = g.id
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE bp.payment_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) 
                    AND (
                        -- Direct Booking: sync anytime (source does NOT contain any OTA keyword)
                        (
                            LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%agoda%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%booking%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%tiket%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%traveloka%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%airbnb%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%expedia%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%pegipegi%'
                            AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%ota%'
                        )
                        OR
                        -- OTA: only sync if checked_in or checked_out
                        (b.status IN ('checked_in', 'checked_out'))
                    )
                    ORDER BY bp.id ASC
                ");
            }

            $syncCount = 0;
            foreach ($unsyncedPayments as $payment) {
                try {
                    // ============================================
                    // DOUBLE-SAFETY: PHP-level OTA gate
                    // Even if SQL filter misses, block OTA before check-in
                    // ============================================
                    $srcCheck = strtolower(trim($payment['booking_source'] ?? ''));
                    $srcNorm = str_replace(['.com', '.co.id', '.id'], '', $srcCheck);
                    $srcNorm = preg_replace('/[^a-z0-9]/', '', $srcNorm);
                    $otaKeywords = ['agoda', 'booking', 'bookingcom', 'tiket', 'tiketcom', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'];
                    $isOtaPayment = false;
                    foreach ($otaKeywords as $kw) {
                        if (strpos($srcNorm, $kw) !== false) {
                            $isOtaPayment = true;
                            break;
                        }
                    }
                    if ($isOtaPayment && !in_array($payment['booking_status'], ['checked_in', 'checked_out'])) {
                        // OTA but not checked in yet — skip, do NOT sync
                        continue;
                    }

                    // ============================================
                    // FIX: Payment-level duplicate prevention
                    // Check by booking_code AND payment_id to allow multiple payments per booking
                    // ============================================
                    $dedupDesc = '%' . $payment['booking_code'] . '%';
                    $existingEntry = $db->fetchOne(
                        "SELECT id FROM cash_book WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income' LIMIT 1",
                        [$dedupDesc, $payment['amount']]
                    );
                    if ($existingEntry) {
                        // Mark as synced even if entry already exists (prevent retry loop)
                        if ($hasSyncCol) {
                            try {
                                $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$existingEntry['id'], $payment['payment_id']]);
                            } catch (\Throwable $e) {
                            }
                        }
                        continue;
                    }

                    // Fallback dedup if no sync column (legacy support)
                    if (!$hasSyncCol) {
                        $existingLegacy = $db->fetchOne(
                            "SELECT id FROM cash_book WHERE description LIKE ? AND ABS(amount - ?) < 1 AND transaction_type = 'income' LIMIT 1",
                            [$dedupDesc, $payment['amount']]
                        );
                        if ($existingLegacy) continue;
                    }

                    // Calculate OTA fee using booking_source with per-OTA fee rates
                    $netAmount = (float)$payment['amount'];
                    $bookingSource = strtolower(trim($payment['booking_source'] ?? ''));
                    $normalizedSrc = str_replace(['.com', '.co.id', '.id'], '', $bookingSource);
                    $normalizedSrc = preg_replace('/[^a-z0-9]/', '', $normalizedSrc);
                    $otaFeeMap = [
                        'agoda' => 'ota_fee_agoda',
                        'booking' => 'ota_fee_booking_com',
                        'bookingcom' => 'ota_fee_booking_com',
                        'tiket' => 'ota_fee_tiket_com',
                        'tiketcom' => 'ota_fee_tiket_com',
                        'airbnb' => 'ota_fee_airbnb',
                        'traveloka' => 'ota_fee_traveloka',
                        'expedia' => 'ota_fee_expedia',
                        'pegipegi' => 'ota_fee_other_ota',
                        'ota' => 'ota_fee_other_ota'
                    ];
                    $feeSettingKey = null;
                    foreach ($otaFeeMap as $otaKey => $settingKey) {
                        if (strpos($normalizedSrc, $otaKey) !== false || $normalizedSrc === $otaKey) {
                            $feeSettingKey = $settingKey;
                            break;
                        }
                    }
                    if ($feeSettingKey) {
                        $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                        $feeStmt->execute([$feeSettingKey]);
                        $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                        if ($feeQuery && (float)($feeQuery['setting_value'] ?? 0) > 0) {
                            $netAmount = $payment['amount'] - ($payment['amount'] * (float)$feeQuery['setting_value'] / 100);
                        }
                    }

                    $accountType = ($payment['payment_method'] === 'cash') ? 'cash' : 'bank';
                    $accountStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND account_type = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                    $accountStmt->execute([$businessId, $accountType]);
                    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

                    // FALLBACK: If no specific account type found, get ANY active account
                    if (!$account) {
                        $fallbackStmt = $masterDb->prepare("SELECT id, current_balance FROM cash_accounts WHERE business_id = ? AND is_active = 1 ORDER BY is_default_account DESC LIMIT 1");
                        $fallbackStmt->execute([$businessId]);
                        $account = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$account) continue;

                    $guestName = $payment['guest_name'] ?? 'Guest';
                    $roomNum = $payment['room_number'] ?? '';
                    $desc = "Pembayaran Reservasi - {$guestName}";
                    if ($roomNum) $desc .= " (Room {$roomNum})";
                    $desc .= " - {$payment['booking_code']}";
                    $totalPaid = $db->fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM booking_payments WHERE booking_id = ?", [$payment['booking_id']]);
                    $desc .= ((float)$totalPaid['total'] >= (float)$payment['final_price']) ? ' [LUNAS]' : ' [CICILAN]';

                    $pmMap = ['bank_transfer' => 'transfer', 'credit_card' => 'debit', 'credit' => 'debit'];
                    $cbMethod = strtolower($payment['payment_method'] ?? 'cash');
                    $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
                    if ($allowedPaymentMethods !== null) {
                        if (!in_array($cbMethod, $allowedPaymentMethods)) {
                            $cbMethod = in_array('other', $allowedPaymentMethods) ? 'other' : (in_array('cash', $allowedPaymentMethods) ? 'cash' : $allowedPaymentMethods[0]);
                        }
                    }

                    if ($hasCashAccountId) {
                        $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, cash_account_id, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())");
                        $cashBookInsert->execute([$payment['payment_date'], $payment['payment_date'], $divisionId, $categoryId, $desc, $netAmount, $cbMethod, $account['id'], $cbUserId]);
                    } else {
                        $cashBookInsert = $db->getConnection()->prepare("INSERT INTO cash_book (transaction_date, transaction_time, division_id, category_id, description, transaction_type, amount, payment_method, created_by, created_at) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, NOW())");
                        $cashBookInsert->execute([$payment['payment_date'], $payment['payment_date'], $divisionId, $categoryId, $desc, $netAmount, $cbMethod, $cbUserId]);
                    }

                    $transactionId = $db->getConnection()->lastInsertId();

                    if ($hasSyncCol) {
                        try {
                            $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$transactionId, $payment['payment_id']]);
                        } catch (\Throwable $e) {
                        }
                    }

                    try {
                        // SMART FIX: Check if transaction_id column exists
                        $hasTransIdCol = false;
                        try {
                            $chk = $masterDb->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'");
                            $hasTransIdCol = $chk && $chk->rowCount() > 0;
                        } catch (\Throwable $e) {
                        }

                        if ($hasTransIdCol) {
                            $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                                $account['id'],
                                $transactionId,
                                $payment['payment_date'],
                                $desc,
                                $netAmount,
                                $payment['booking_code'],
                                $cbUserId
                            ]);
                        } else {
                            $masterDb->prepare("INSERT INTO cash_account_transactions (cash_account_id, transaction_date, description, amount, transaction_type, reference_number, created_by, created_at) VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())")->execute([
                                $account['id'],
                                $payment['payment_date'],
                                $desc,
                                $netAmount,
                                $payment['booking_code'],
                                $cbUserId
                            ]);
                        }

                        $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$netAmount, $account['id']]);
                    } catch (\Throwable $masterErr) {
                        error_log("Cashbook page master sync error: " . $masterErr->getMessage());
                    }
                    $syncCount++;
                } catch (\Throwable $paymentError) {
                    error_log("Cashbook page sync error payment#{$payment['payment_id']}: " . $paymentError->getMessage());
                    continue;
                }
            }
            if ($syncCount > 0) {
                error_log("Cashbook page auto-sync: {$syncCount} payments synced");
            }
        }
    }
} catch (\Throwable $syncError) {
    error_log("Cashbook page sync setup error: " . $syncError->getMessage());
}

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// ============================================
// BUSINESS FEATURE DETECTION (CONFIG-BASED)
// Uses enabled_modules and business_type from config
// NOT hardcoded business ID - allows proper isolation
// ============================================
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';

// Legacy compatibility - use feature flags for conditional logic
$isCQC = $hasProjectModule; // Only true if business has cqc-projects module enabled

// Project module: Load project names for mapping (only if module enabled)
$cqcProjectMap = [];
if ($hasProjectModule) {
    try {
        require_once __DIR__ . '/../cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();
        $stmt = $cqcPdo->query("SELECT id, project_name, project_code, client_name, status, budget_idr, spent_idr FROM cqc_projects ORDER BY project_name");
        $cqcAllProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cqcAllProjects as $p) {
            $cqcProjectMap[$p['id']] = $p;
        }

        // Also load expense-to-project mapping
        $cqcExpenseProjectMap = [];
        $stmt2 = $cqcPdo->query("SELECT e.description, e.amount, e.expense_date, e.project_id, e.category_id, c.category_name, c.category_icon FROM cqc_project_expenses e LEFT JOIN cqc_expense_categories c ON e.category_id = c.id");
        $cqcExpenseRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cqcExpenseRows as $exp) {
            $key = $exp['description'] . '|' . number_format($exp['amount'], 2, '.', '') . '|' . $exp['expense_date'];
            $cqcExpenseProjectMap[$key] = $exp;
        }
    } catch (Exception $e) {
        error_log('CQC project map error: ' . $e->getMessage());
    }
}

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
    ? $companyNameSetting['setting_value']
    : BUSINESS_NAME;

$pageTitle = ($isCQC ? 'CQC' : $displayCompanyName) . ' - Buku Kas Besar';
$pageSubtitle = $isCQC ? 'Pencatatan Keuangan Proyek Solar Panel' : 'Pencatatan Transaksi Keuangan';

// Filtering - sanitize inputs (handle empty strings from form submission)
$rawFilterDate = trim(getGet('date', ''));
$rawFilterMonth = trim(getGet('month', ''));
$filterType = trim(getGet('type', 'all'));
$filterDivision = trim(getGet('division', 'all'));
$filterPayment = trim(getGet('payment', 'all'));
$filterUser = trim(getGet('user', 'all'));
$filterSearch = trim(getGet('search', ''));

$filterDate = '';
$filterMonth = '';
$activePeriodType = 'all';

if (!empty($rawFilterMonth) && preg_match('/^\d{4}-\d{2}$/', $rawFilterMonth)) {
    $filterMonth = $rawFilterMonth;
    $activePeriodType = 'month';
} elseif (!empty($rawFilterDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFilterDate)) {
    $filterDate = $rawFilterDate;
    $activePeriodType = 'date';
} elseif (!$isCQC && !isset($_GET['date']) && !isset($_GET['month']) && !isset($_GET['type'])) {
    $filterMonth = date('Y-m');
    $activePeriodType = 'month';
}

// If a month is selected, ignore any date value completely.
// This makes period filtering deterministic and prevents stale date inputs
// from narrowing the result set when the user meant to view a full month.
if ($activePeriodType === 'month') {
    $filterDate = '';
} elseif ($activePeriodType === 'date') {
    $filterMonth = '';
}

// Build query with filters
$whereClauses = [];
$params = [];

// Filter by the active period only
if ($activePeriodType === 'date' && !empty($filterDate)) {
    $whereClauses[] = "cb.transaction_date = :date";
    $params['date'] = $filterDate;
} elseif ($activePeriodType === 'month' && !empty($filterMonth)) {
    $whereClauses[] = "DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month";
    $params['month'] = $filterMonth;
}

if (!empty($filterType) && $filterType !== 'all') {
    $whereClauses[] = "cb.transaction_type = :type";
    $params['type'] = $filterType;
}

if (!empty($filterDivision) && $filterDivision !== 'all') {
    $whereClauses[] = "cb.division_id = :division";
    $params['division'] = $filterDivision;
}

if (!empty($filterPayment) && $filterPayment !== 'all') {
    if ($filterPayment === 'ota_all') {
        // Filter all OTA payments
        $whereClauses[] = "cb.payment_method LIKE 'OTA %'";
    } else {
        $whereClauses[] = "cb.payment_method = :payment";
        $params['payment'] = $filterPayment;
    }
}

if (!empty($filterUser) && $filterUser !== 'all') {
    $whereClauses[] = "cb.created_by = :user_id";
    $params['user_id'] = $filterUser;
}

if (!empty($filterSearch)) {
    $whereClauses[] = "(cb.description LIKE :search OR c.category_name LIKE :search2)";
    $params['search'] = '%' . $filterSearch . '%';
    $params['search2'] = '%' . $filterSearch . '%';
}

$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get transactions - Use LEFT JOIN to handle missing references
// Check if transaction_time column exists (might differ on hosting)
$hasTransactionTime = true;
try {
    $db->getConnection()->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTime = false;
}
$orderBy = $hasTransactionTime ? 'cb.transaction_date DESC, cb.transaction_time DESC' : 'cb.transaction_date DESC, cb.id DESC';

// Use cross-database join to get user names from master database
$masterDbName = DB_NAME;
$transactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(d.division_code, '-') as division_code,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
    {$whereSQL}
    ORDER BY {$orderBy}",
    $params
);

// Debug: if query returns empty but shouldn't, log it
if (empty($transactions) && empty($whereClauses)) {
    error_log('CASHBOOK DEBUG: No transactions found even without filter. DB=' . Database::getCurrentDatabase() . ' whereSQL=' . $whereSQL);
}

// Get divisions for filter
$divisions = $db->fetchAll("SELECT * FROM divisions WHERE is_active = 1 ORDER BY division_name");

// Get users for filter (from master database)
$usersForFilter = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $usersForFilter = $masterDb->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: empty array
    $usersForFilter = [];
}

// Calculate totals
$totalIncome = 0;
$totalExpense = 0;
$totalOwnerFund = 0; // ALL BUSINESSES: Owner top-up to Kas Operasional (NOT income)
$totalRealIncome = 0; // Real income from customers/invoices
$totalOfficeExpense = 0; // Office/operational expenses only (no project)
$totalProjectExpense = 0; // Project-linked expenses (for contractor businesses)
$totalPettyCashExpense = 0; // Expenses funded from Petty Cash (office + project)
$totalKasBesarExpense = 0; // Expenses funded from Kas Besar
$totalCashTransfer = 0; // Setor Tunai (internal cash->bank transfer) - reduces cash but NOT a business expense
foreach ($transactions as $trans) {
    if ($trans['transaction_type'] === 'income') {
        $totalIncome += $trans['amount'];
        // ALL BUSINESSES: Separate owner fund from real income
        if (isset($trans['source_type']) && $trans['source_type'] === 'owner_fund') {
            $totalOwnerFund += $trans['amount'];
        } else {
            $totalRealIncome += $trans['amount'];
        }
    } else {
        // Setor Tunai internal transfers reduce cash on hand but are NOT a real
        // business expense (money just moves to the bank account) - track them
        // separately and exclude from $totalExpense / P&L breakdowns below.
        if (isset($trans['source_type']) && $trans['source_type'] === 'cash_transfer') {
            $totalCashTransfer += $trans['amount'];
            continue;
        }
        $totalExpense += $trans['amount'];
        // Separate project expenses (not hotel operational)
        if (isset($trans['source_type']) && $trans['source_type'] === 'owner_project') {
            $totalProjectExpense += $trans['amount'];
        }
        // Contractor businesses: Separate office vs project expenses
        if ($isContractor) {
            $desc = $trans['description'] ?? '';
            if (preg_match('/\[CQC_PROJECT:\d+\]/', $desc)) {
                $totalProjectExpense += $trans['amount'];
                // Track fund source from description tag
                if (strpos($desc, '[Petty Cash]') !== false) {
                    $totalPettyCashExpense += $trans['amount'];
                } else {
                    $totalKasBesarExpense += $trans['amount'];
                }
            } else {
                $totalOfficeExpense += $trans['amount'];
                // Track fund source from description tag for office expenses too
                if (strpos($desc, '[Kas Besar]') !== false) {
                    $totalKasBesarExpense += $trans['amount'];
                } else {
                    // Default to Petty Cash if no tag (legacy data)
                    $totalPettyCashExpense += $trans['amount'];
                }
            }
        }
    }
}
$balance = $totalIncome - $totalExpense;

// Calculate CASH AVAILABLE — same logic as dashboard Daily Cash widget
$cashAvailable = 0;
$startKas = 0;
try {
    if ($activePeriodType === 'month' && !empty($filterMonth)) {
        $periodStart = $filterMonth . '-01';
        $periodEnd = (new DateTime($periodStart))->modify('last day of this month')->format('Y-m-d');
    } elseif ($activePeriodType === 'date' && !empty($filterDate)) {
        $periodStart = $filterDate;
        $periodEnd = $filterDate;
    } else {
        $periodStart = null;
        $periodEnd = null;
    }

    // Get cash_accounts from master DB
    $masterDbCash = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDbCash->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $businessId = getMasterBusinessId();

    $stmt = $masterDbCash->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $masterDbCash->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $allAccIds = array_merge($capitalAccIds, $pettyCashAccIds);

    // Check if cash_account_id column exists
    $hasCashAccCol = false;
    try {
        $db->getConnection()->query("SELECT cash_account_id FROM cash_book LIMIT 1");
        $hasCashAccCol = true;
    } catch (\Throwable $e) {
    }

    if ($hasCashAccCol && !empty($allAccIds)) {
        $ph = implode(',', array_fill(0, count($allAccIds), '?'));

        if ($periodStart !== null && $periodEnd !== null) {
            // Start Kas = balance before selected period
            $rStart = $db->fetchOne(
                "SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                 FROM cash_book WHERE cash_account_id IN ($ph) AND transaction_date < ?",
                array_merge($allAccIds, [$periodStart])
            );
            $startKas = (float)($rStart['bal'] ?? 0);

            // Balance inside the selected period
            $rPeriod = $db->fetchOne(
                "SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                 FROM cash_book WHERE cash_account_id IN ($ph) AND transaction_date BETWEEN ? AND ?",
                array_merge($allAccIds, [$periodStart, $periodEnd])
            );
            $periodBal = (float)($rPeriod['bal'] ?? 0);

            // Saldo cash pada akhir periode terpilih
            $cashAvailable = $startKas + $periodBal;
        } else {
            // No period filter: show all-time cash balance from operational accounts
            $rAll = $db->fetchOne(
                "SELECT COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
                        COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
                 FROM cash_book WHERE cash_account_id IN ($ph)",
                $allAccIds
            );
            $cashAvailable = (float)($rAll['bal'] ?? 0);
        }
    } else {
        // Fallback: simple all-time balance
        $cashAvailRow = $db->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) -
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as bal
             FROM cash_book"
        );
        $cashAvailable = (float)($cashAvailRow['bal'] ?? 0);
    }
} catch (Exception $e) {
    // Fallback
    $cashAvailRow = $db->fetchOne(
        "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as bal
         FROM cash_book"
    );
    $cashAvailable = (float)($cashAvailRow['bal'] ?? 0);
}

// CQC: Get actual Petty Cash balance from cash_accounts (master DB)
$actualPettyCashBalance = 0;
if ($isCQC) {
    try {
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $businessId = getMasterBusinessId();

        // Get Petty Cash account balance (account_type = 'cash')
        $stmtPetty = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPetty->execute([$businessId]);
        $pettyCashAccount = $stmtPetty->fetch(PDO::FETCH_ASSOC);
        $actualPettyCashBalance = (float)($pettyCashAccount['balance'] ?? 0);
    } catch (Exception $e) {
        // Fallback to calculated value
        $actualPettyCashBalance = $totalOwnerFund - $totalOfficeExpense;
    }
}

include '../../includes/header.php';
echo getPrintCSS();
?>

<?php if ($isCQC): ?>
    <style>
        /* ===== CQC BUKU KAS - CLEAN ELEGANT DESIGN ===== */
        :root,
        body,
        body[data-theme="light"],
        body[data-theme="dark"] {
            --primary-color: #f0b429 !important;
            --primary-dark: #d4960d !important;
            --secondary-color: #0d1f3c !important;
        }

        /* Filter Card */
        .cqc-filter-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #f0b429;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        .cqc-filter-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1rem;
        }

        .cqc-filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .cqc-filter-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #0d1f3c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cqc-filter-input {
            height: 40px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0 0.75rem;
            font-size: 0.813rem;
            background: #f9fafb;
            color: #0d1f3c;
            transition: all 0.2s;
        }

        .cqc-filter-input:focus {
            outline: none;
            border-color: #f0b429;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(240, 180, 41, 0.1);
        }

        .cqc-filter-actions {
            grid-column: span 6;
            display: flex;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        .cqc-btn-filter {
            flex: 1;
            height: 42px;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.22);
        }

        .cqc-btn-filter:hover {
            background: linear-gradient(135deg, #4338ca, #3730a3);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(67, 56, 202, 0.28);
        }

        .cqc-btn-reset {
            padding: 0 1.5rem;
            height: 42px;
            background: linear-gradient(135deg, #64748b, #475569);
            color: #ffffff;
            border: 1px solid rgba(71, 85, 105, 0.4);
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(71, 85, 105, 0.2);
        }

        .cqc-btn-reset:hover {
            background: linear-gradient(135deg, #475569, #334155);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(51, 65, 85, 0.24);
        }

        /* Table Header */
        .table-header-cqc {
            background: #fff !important;
            border-radius: 12px !important;
            padding: 1rem 1.25rem !important;
            margin-bottom: 1rem !important;
            border: 1px solid #e5e7eb !important;
            border-left: 4px solid #f0b429 !important;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04) !important;
        }

        .table-header-cqc h3 {
            color: #0d1f3c !important;
        }

        .table-header-cqc p {
            color: #6b7280 !important;
        }

        /* Buttons */
        .btn-primary {
            background: #f0b429 !important;
            color: #0d1f3c !important;
            border: none !important;
            font-weight: 700 !important;
        }

        .btn-primary:hover {
            background: #d4960d !important;
        }

        .btn-secondary {
            background: #f3f4f6 !important;
            color: #374151 !important;
            border: 1px solid #e5e7eb !important;
        }

        .btn-secondary:hover {
            background: #e5e7eb !important;
        }

        .btn-white-text,
        .btn-white-text * {
            color: #fff !important;
            stroke: #fff !important;
            fill: #fff !important;
            font-weight: 700 !important;
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.18);
        }

        .cashbook-action-btn {
            border-radius: 10px !important;
            letter-spacing: 0.01em;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.14);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            border: none !important;
            color: #fff !important;
            text-decoration: none !important;
            position: relative !important;
            isolation: isolate !important;
            overflow: hidden !important;
            opacity: 1 !important;
        }

        .cashbook-action-btn::before,
        .cashbook-action-btn::after {
            z-index: 1 !important;
            pointer-events: none !important;
        }

        a.cashbook-action-btn,
        a.cashbook-action-btn:link,
        a.cashbook-action-btn:visited,
        a.cashbook-action-btn:hover,
        a.cashbook-action-btn:active,
        a.cashbook-action-btn:focus {
            color: #fff !important;
            text-decoration: none !important;
        }

        .cashbook-action-btn:hover {
            transform: translateY(-1px);
            filter: saturate(1.05);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.2);
        }

        .cashbook-action-btn i,
        .cashbook-action-btn span {
            color: #fff !important;
            stroke: #fff !important;
            position: relative !important;
            z-index: 9 !important;
            opacity: 1 !important;
            mix-blend-mode: normal !important;
        }

        .cashbook-action-btn span {
            -webkit-text-fill-color: #fff !important;
            text-fill-color: #fff !important;
            text-shadow: 0 1px 1px rgba(0, 0, 0, 0.24) !important;
        }

        .cashbook-action-btn svg,
        .cashbook-action-btn svg * {
            color: #fff !important;
            stroke: #fff !important;
            fill: none !important;
            position: relative !important;
            z-index: 9 !important;
            opacity: 1 !important;
        }

        /* Cashbook action button color variants */
        .cashbook-btn-filter {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
        }

        .cashbook-btn-reset {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
        }

        .cashbook-btn-pdf {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
        }

        .cashbook-btn-excel {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
        }

        .cashbook-btn-wa {
            background: linear-gradient(135deg, #1e40af, #1d4ed8) !important;
        }

        /* Table Styling */
        .cb-table {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .cb-table th {
            background: #f9fafb !important;
            color: #0d1f3c !important;
            font-size: 0.7rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border-bottom: 2px solid #f0b429 !important;
            padding: 0.75rem 0.5rem !important;
        }

        .cb-table td {
            border-bottom: 1px solid #f3f4f6 !important;
        }

        .cb-table tbody tr:hover {
            background: rgba(240, 180, 41, 0.04) !important;
        }

        /* Date Header Row */
        .cb-table .date-header-row td {
            background: linear-gradient(90deg, rgba(240, 180, 41, 0.1), transparent) !important;
            font-weight: 700 !important;
            color: #0d1f3c !important;
            border-bottom: 1px solid rgba(240, 180, 41, 0.3) !important;
        }

        /* Tags */
        .cb-badge.income {
            background: rgba(16, 185, 129, 0.12);
            color: #059669;
            font-weight: 700;
        }

        .cb-badge.expense {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            font-weight: 700;
        }

        .cb-ref-tag {
            background: rgba(240, 180, 41, 0.15) !important;
            color: #92400e !important;
        }

        .cqc-project-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: linear-gradient(135deg, rgba(240, 180, 41, 0.15), rgba(240, 180, 41, 0.08));
            color: #0d1f3c;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            border-left: 3px solid #f0b429;
        }

        .cqc-office-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(59, 130, 246, 0.06));
            color: #1d4ed8;
            padding: 0.25rem 0.6rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            border-left: 3px solid #3b82f6;
        }

        /* Info Chips */
        .cqc-payment-info {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.4rem;
        }

        .cqc-info-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.2rem 0.5rem;
            border-radius: 5px;
            font-size: 0.68rem;
            font-weight: 600;
        }

        .cqc-info-chip.method {
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
        }

        .cqc-info-chip.account {
            background: rgba(139, 92, 246, 0.1);
            color: #7c3aed;
        }

        .cqc-info-chip.category {
            background: rgba(240, 180, 41, 0.12);
            color: #92400e;
        }

        .cqc-info-chip.user {
            background: rgba(107, 114, 128, 0.1);
            color: #4b5563;
        }

        /* Action Buttons */
        .cb-action-btn.edit {
            background: rgba(240, 180, 41, 0.15);
            color: #92400e;
        }

        .cb-action-btn.edit:hover {
            background: #f0b429;
            color: #0d1f3c;
        }

        .cb-action-btn.delete {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .cb-action-btn.delete:hover {
            background: #ef4444;
            color: #fff;
        }

        @media (max-width: 1024px) {
            .cqc-filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .cqc-filter-actions {
                grid-column: span 2;
            }
        }

        @media (max-width: 640px) {
            .cqc-filter-grid {
                grid-template-columns: 1fr;
            }

            .cqc-filter-actions {
                grid-column: span 1;
                flex-direction: column;
            }
        }

        /* ===== CQC DAILY EXPENSES CONTAINER ===== */
        .cqc-daily-expenses {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #f0b429;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }

        .cqc-daily-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .cqc-daily-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #f0b429, #d4960d);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .cqc-daily-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0d1f3c;
        }

        .cqc-daily-subtitle {
            font-size: 0.7rem;
            color: #6b7280;
        }

        .cqc-daily-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .cqc-daily-card {
            padding: 1rem;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s;
        }

        .cqc-daily-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .cqc-daily-card.owner {
            background: linear-gradient(135deg, rgba(240, 180, 41, 0.08), rgba(240, 180, 41, 0.03));
            border-left: 4px solid #f0b429;
        }

        .cqc-daily-card.expense {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.06), rgba(239, 68, 68, 0.02));
            border-left: 4px solid #ef4444;
        }

        .cqc-daily-card.balance {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.08), rgba(59, 130, 246, 0.03));
            border-left: 4px solid #3b82f6;
        }

        .cqc-daily-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .cqc-daily-card.owner .cqc-daily-label {
            color: #92400e;
        }

        .cqc-daily-card.expense .cqc-daily-label {
            color: #dc2626;
        }

        .cqc-daily-card.balance .cqc-daily-label {
            color: #2563eb;
        }

        .cqc-daily-value {
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .cqc-daily-card.owner .cqc-daily-value {
            color: #b45309;
        }

        .cqc-daily-card.expense .cqc-daily-value {
            color: #dc2626;
        }

        .cqc-daily-card.balance .cqc-daily-value {
            color: #2563eb;
        }

        .cqc-daily-desc {
            font-size: 0.65rem;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .cqc-daily-grid {
                grid-template-columns: 1fr;
            }

            .cqc-daily-value {
                font-size: 1.2rem;
            }
        }
    </style>
<?php endif; ?>

<style>
    /* Global override: keep all cashbook action buttons uniform blue (CQC & non-CQC) */
    .cashbook-action-btn,
    .cashbook-btn-filter,
    .cashbook-btn-reset,
    .cashbook-btn-pdf,
    .cashbook-btn-excel,
    .cashbook-btn-wa,
    .cqc-btn-filter,
    .cqc-btn-reset {
        background: #1e3a8a !important;
        border: 1px solid #1e40af !important;
        color: #ffffff !important;
        opacity: 1 !important;
        filter: none !important;
    }

    .cashbook-action-btn:hover,
    .cashbook-btn-filter:hover,
    .cashbook-btn-reset:hover,
    .cashbook-btn-pdf:hover,
    .cashbook-btn-excel:hover,
    .cashbook-btn-wa:hover,
    .cqc-btn-filter:hover,
    .cqc-btn-reset:hover {
        background: #1d4ed8 !important;
        border-color: #1e40af !important;
        color: #ffffff !important;
        opacity: 1 !important;
        filter: none !important;
    }

    .cashbook-action-btn,
    .cashbook-action-btn *,
    .cqc-btn-filter,
    .cqc-btn-filter *,
    .cqc-btn-reset,
    .cqc-btn-reset * {
        color: #ffffff !important;
        stroke: #ffffff !important;
        text-shadow: none !important;
        opacity: 1 !important;
    }

    .cashbook-action-btn:disabled,
    .cashbook-action-btn[disabled],
    button.cashbook-action-btn:disabled {
        background: #1e3a8a !important;
        border-color: #1e40af !important;
        color: #ffffff !important;
        opacity: 1 !important;
        filter: none !important;
    }

    /* ===== COMPACT CASHBOOK TABLE STYLES ===== */
    .cb-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .cb-table th {
        background: linear-gradient(135deg, var(--bg-secondary), var(--bg-tertiary));
        padding: 0.65rem 0.5rem;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: var(--text-muted);
        border-bottom: 2px solid var(--bg-tertiary);
        white-space: nowrap;
    }

    .cb-table td {
        padding: 0.5rem;
        border-bottom: 1px solid var(--bg-tertiary);
        vertical-align: middle;
    }

    .cb-table tbody tr:hover {
        background: rgba(99, 102, 241, 0.05);
    }

    .cb-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .cb-badge.income {
        background: rgba(16, 185, 129, 0.15);
        color: #059669;
    }

    .cb-badge.expense {
        background: rgba(239, 68, 68, 0.15);
        color: #dc2626;
    }

    .cb-method {
        display: inline-block;
        padding: 0.15rem 0.4rem;
        background: var(--bg-tertiary);
        border-radius: 4px;
        font-size: 0.68rem;
        font-weight: 600;
        color: var(--text-muted);
    }

    .cb-ref-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        background: rgba(99, 102, 241, 0.15);
        color: var(--primary-color);
        padding: 0.15rem 0.35rem;
        border-radius: 4px;
        font-size: 0.65rem;
        font-weight: 600;
        margin-right: 0.35rem;
    }

    .cb-actions {
        display: flex;
        gap: 0.25rem;
        justify-content: center;
    }

    .cb-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
    }

    .cb-action-btn svg {
        width: 14px;
        height: 14px;
    }

    .cb-action-btn.edit {
        background: var(--bg-tertiary);
        color: var(--text-muted);
    }

    .cb-action-btn.edit:hover {
        background: var(--primary-color);
        color: white;
    }

    .cb-action-btn.delete {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
    }

    .cb-action-btn.delete:hover {
        background: #ef4444;
        color: white;
    }

    .cb-action-btn.locked {
        background: var(--bg-tertiary);
        color: var(--text-muted);
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* ===== PAYMENT INFO CHIPS (Global) ===== */
    .cqc-payment-info {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-top: 0.4rem;
    }

    .cqc-info-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        padding: 0.2rem 0.5rem;
        border-radius: 5px;
        font-size: 0.68rem;
        font-weight: 600;
    }

    .cqc-info-chip.method {
        background: rgba(59, 130, 246, 0.1);
        color: #2563eb;
    }

    .cqc-info-chip.account {
        background: rgba(139, 92, 246, 0.1);
        color: #7c3aed;
    }

    .cqc-info-chip.category {
        background: rgba(240, 180, 41, 0.12);
        color: #92400e;
    }

    .cqc-info-chip.user {
        background: rgba(107, 114, 128, 0.1);
        color: #4b5563;
    }

    /* ===== INPUT BY USER BADGE ===== */
    .cb-user-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.5rem;
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(99, 102, 241, 0.05));
        color: #4f46e5;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
        border: 1px solid rgba(99, 102, 241, 0.15);
    }

    /* ===== ELEGANT PRINT STYLES ===== */
    @media print {
        * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            color-adjust: exact;
        }

        body {
            background: white;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        /* Hide non-print elements */
        .sidebar,
        .page-header,
        button,
        .btn,
        .table-actions,
        .table-header>div:last-child,
        form,
        a[href*="add"],
        a[href*="logs"],
        [onclick*="print"],
        .dashboard-grid,
        .table-container {
            display: none !important;
        }

        /* Main content */
        .main-content,
        .content-wrapper,
        .page-content {
            width: 100%;
            padding: 0;
            margin: 0;
            background: white;
        }

        /* Print header */
        .print-header {
            display: table;
            width: 100%;
            margin-bottom: 1rem;
            border-bottom: 2px solid #111827;
            padding-bottom: 1rem;
        }

        .print-header-left {
            display: table-cell;
            width: 12%;
            vertical-align: middle;
            text-align: center;
        }

        .print-header-center {
            display: table-cell;
            width: 76%;
            vertical-align: middle;
            text-align: center;
            padding: 0 1rem;
        }

        .print-header-right {
            display: table-cell;
            width: 12%;
            vertical-align: middle;
            text-align: right;
        }

        .print-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            margin: 0 auto;
        }

        .print-company-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: #111827;
            margin: 0 0 0.15rem 0;
            letter-spacing: -0.3px;
        }

        .print-company-type {
            font-size: 0.8rem;
            color: #6b7280;
            margin: 0;
            font-weight: 400;
        }

        .print-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #111827;
            margin: 0.75rem 0 0.3rem 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .print-period {
            font-size: 0.85rem;
            color: #6b7280;
            text-align: center;
            margin-bottom: 0;
        }

        /* Summary cards for print */
        .print-summary {
            display: flex;
            gap: 0;
            margin-bottom: 1rem;
            page-break-inside: avoid;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            overflow: hidden;
        }

        .print-summary-card {
            flex: 1;
            padding: 0.6rem 0.75rem;
            text-align: center;
            border-right: 1px solid #d1d5db;
        }

        .print-summary-card:last-child {
            border-right: none;
        }

        .print-summary-label {
            font-size: 0.7rem;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 0.2rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .print-summary-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: #111827;
        }

        .print-summary-value.income {
            color: #059669;
        }

        .print-summary-value.expense {
            color: #dc2626;
        }

        .print-summary-value.balance {
            color: #111827;
        }

        /* Table styling */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        thead {
            background: #111827;
            color: white;
        }

        th {
            padding: 0.5rem 0.5rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.7rem;
            border: 1px solid #111827;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        td {
            padding: 0.35rem 0.5rem;
            border: 1px solid #e5e7eb;
            font-size: 0.78rem;
            line-height: 1.3;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        tfoot td {
            border-color: #d1d5db;
        }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 700;
        }

        .badge.income {
            background: #d1fae5;
            color: #065f46;
        }

        .badge.expense {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Print footer */
        .print-footer {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #d1d5db;
            display: flex;
            justify-content: space-around;
            text-align: center;
            page-break-inside: avoid;
        }

        .print-footer-item {
            flex: 1;
        }

        .print-footer-label {
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 2.5rem;
        }

        .print-footer-line {
            border-top: 1px solid #111827;
            height: 1px;
            margin-bottom: 0.3rem;
            width: 70%;
            margin-left: auto;
            margin-right: auto;
        }

        .print-footer-text {
            font-size: 0.8rem;
            color: #111827;
            font-weight: 600;
        }

        /* Page break */
        @page {
            margin: 0.4in 0.5in;
            size: A4;
        }
    }
</style>

<!-- Print Version (Hidden in Screen) -->
<div style="display: none;" id="printSection" class="print-content">
    <?php
    // Build dynamic period text
    $periodText = $activePeriodType === 'month'
        ? date('F Y', strtotime($filterMonth . '-01'))
        : ($activePeriodType === 'date' ? formatDate($filterDate) : 'Semua Periode');

    // Build dynamic title based on active filters
    $printTitle = 'LAPORAN BUKU KAS BESAR';
    $filterTags = [];

    // Payment method filter label
    $paymentLabels = [
        'cash' => 'Transaksi Cash',
        'transfer' => 'Transaksi Transfer Bank',
        'debit' => 'Transaksi Debit/Kartu',
        'qr' => 'Transaksi QR Code',
        'edc' => 'Transaksi EDC',
        'other' => 'Transaksi Lainnya',
        'ota_all' => 'Transaksi OTA (Semua)',
        'OTA tiket.com' => 'Transaksi OTA tiket.com',
        'OTA Agoda' => 'Transaksi OTA Agoda',
        'OTA Booking.com' => 'Transaksi OTA Booking.com',
        'OTA Traveloka' => 'Transaksi OTA Traveloka',
        'OTA Airbnb' => 'Transaksi OTA Airbnb',
        'OTA Expedia' => 'Transaksi OTA Expedia',
        'OTA Pegipegi' => 'Transaksi OTA Pegipegi'
    ];
    if (!empty($filterPayment) && $filterPayment !== 'all') {
        $printTitle = strtoupper($paymentLabels[$filterPayment] ?? 'Transaksi ' . ucfirst($filterPayment));
        $filterTags[] = 'Pembayaran: ' . ucfirst($filterPayment);
    }

    // Division filter label
    if (!empty($filterDivision) && $filterDivision !== 'all') {
        $divName = '';
        foreach ($divisions as $d) {
            if ($d['id'] == $filterDivision) {
                $divName = $d['division_name'];
                break;
            }
        }
        if ($divName) {
            $printTitle = 'LAPORAN KAS DIVISI ' . strtoupper($divName);
            $filterTags[] = 'Divisi: ' . $divName;
        }
    }

    // Type filter label
    if (!empty($filterType) && $filterType !== 'all') {
        $filterTags[] = 'Tipe: ' . ($filterType === 'income' ? 'Pemasukan' : 'Pengeluaran');
    }

    // If both payment + division, combine
    if (!empty($filterPayment) && $filterPayment !== 'all' && !empty($filterDivision) && $filterDivision !== 'all' && $divName) {
        $printTitle = strtoupper(($paymentLabels[$filterPayment] ?? 'Transaksi ' . ucfirst($filterPayment)) . ' - Divisi ' . $divName);
    }

    echo printHeader($db, $displayCompanyName, BUSINESS_ICON, BUSINESS_TYPE, $printTitle, 'Periode: ' . $periodText);
    ?>

    <?php if (!empty($filterTags)): ?>
        <div style="text-align: center; margin-bottom: 0.75rem;">
            <?php foreach ($filterTags as $tag): ?>
                <span style="display: inline-block; padding: 0.2rem 0.6rem; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.75rem; color: #475569; margin: 0 0.15rem;"><?php echo $tag; ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Summary Totals -->
    <div class="print-summary">
        <div class="print-summary-card">
            <div class="print-summary-label">Total Pemasukan</div>
            <div class="print-summary-value income"><?php echo formatCurrency($totalIncome); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php $incomeCount = 0;
                                                                                foreach ($transactions as $t) if ($t['transaction_type'] === 'income') $incomeCount++;
                                                                                echo $incomeCount; ?> transaksi</div>
        </div>
        <div class="print-summary-card">
            <div class="print-summary-label">Total Pengeluaran</div>
            <div class="print-summary-value expense"><?php echo formatCurrency($totalExpense); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php echo count($transactions) - $incomeCount; ?> transaksi</div>
        </div>
        <div class="print-summary-card">
            <div class="print-summary-label">Saldo / Selisih</div>
            <div class="print-summary-value balance"><?php echo formatCurrency($balance); ?></div>
            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem;"><?php echo count($transactions); ?> total transaksi</div>
        </div>
    </div>

    <!-- Transactions Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 8%;">Tanggal</th>
                <th style="width: 4%;">Waktu</th>
                <th style="width: 10%;">Divisi</th>
                <th style="width: 10%;">Kategori</th>
                <th style="width: 5%;">Tipe</th>
                <th style="width: 5%;">Metode</th>
                <th style="width: 12%; text-align: right;">Jumlah</th>
                <th>Deskripsi</th>
                <th style="width: 9%;">Input By</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1;
            foreach ($transactions as $trans): ?>
                <tr>
                    <td style="text-align: center; color: #94a3b8; font-size: 0.75rem;"><?php echo $no++; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?></td>
                    <td><?php echo isset($trans['transaction_time']) ? date('H:i', strtotime($trans['transaction_time'])) : '-'; ?></td>
                    <td><strong><?php echo $trans['division_name']; ?></strong></td>
                    <td><?php echo $trans['category_name']; ?></td>
                    <?php $isCashTransfer = isset($trans['source_type']) && $trans['source_type'] === 'cash_transfer'; ?>
                    <td>
                        <?php if ($isCashTransfer): ?>
                            <span class="badge" style="background:#e0e7ff; color:#4338ca;">Setor Tunai</span>
                        <?php else: ?>
                            <span class="badge <?php echo $trans['transaction_type']; ?>"><?php echo $trans['transaction_type'] === 'income' ? 'Masuk' : 'Keluar'; ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-size: 0.75rem; text-transform: uppercase;"><?php echo htmlspecialchars($trans['payment_method'] ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 700; color: <?php echo $isCashTransfer ? '#4338ca' : ($trans['transaction_type'] === 'income' ? '#059669' : '#dc2626'); ?>;">
                        <?php echo formatCurrency($trans['amount']); ?>
                    </td>
                    <td style="font-size: 0.8rem;"><?php echo $isCashTransfer ? '🔄 Pemindahan Uang / Setoran Harian — ' . htmlspecialchars($trans['description'] ?: '') : ($trans['description'] ?: '-'); ?></td>
                    <td style="font-size: 0.7rem; color: #475569;"><?php echo htmlspecialchars($trans['created_by_name'] ?: 'System'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="font-weight: 600; font-size: 0.78rem;">
                <td colspan="8" style="text-align: right; padding: 0.4rem 0.5rem; border-top: 1.5px solid #d1d5db;">Total Pemasukan:</td>
                <td style="text-align: right; color: #059669; padding: 0.4rem 0.5rem; border-top: 1.5px solid #d1d5db;"><?php echo formatCurrency($totalIncome); ?></td>
                <td style="border-top: 1.5px solid #d1d5db;"></td>
            </tr>
            <tr style="font-weight: 600; font-size: 0.78rem;">
                <td colspan="8" style="text-align: right; padding: 0.4rem 0.5rem;">Total Pengeluaran:</td>
                <td style="text-align: right; color: #dc2626; padding: 0.4rem 0.5rem;"><?php echo formatCurrency($totalExpense); ?></td>
                <td></td>
            </tr>
            <tr style="font-weight: 700; font-size: 0.85rem; background: #f3f4f6;">
                <td colspan="8" style="text-align: right; padding: 0.5rem; border-top: 1.5px solid #9ca3af;">Saldo:</td>
                <td style="text-align: right; padding: 0.5rem; border-top: 1.5px solid #9ca3af; color: #111827;"><?php echo formatCurrency($balance); ?></td>
                <td style="border-top: 1.5px solid #9ca3af;"></td>
            </tr>
        </tfoot>
    </table>

    <!-- Printed by info -->
    <div style="margin-top: 1rem; padding: 0.6rem 0.75rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.72rem; color: #475569;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong style="color: #1e293b;">Dicetak oleh:</strong> <?php echo htmlspecialchars($currentUser['full_name'] ?? 'Admin'); ?>
                &nbsp;&bull;&nbsp;
                <strong style="color: #1e293b;">Tanggal cetak:</strong> <?php echo date('d/m/Y H:i'); ?>
            </div>
            <div>
                <?php
                $printUserNames = [];
                foreach ($transactions as $t) {
                    $uName = $t['created_by_name'] ?: 'System';
                    if (!in_array($uName, $printUserNames)) $printUserNames[] = $uName;
                }
                ?>
                <strong style="color: #1e293b;">User input:</strong> <?php echo htmlspecialchars(implode(', ', $printUserNames)); ?>
            </div>
        </div>
    </div>

    <?php echo printFooter($currentUser['full_name'] ?? 'Admin'); ?>
</div>

<!-- Screen Display Section -->
<div id="screenSection">

    <?php if (isset($_SESSION['success'])): ?>
        <div style="background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(16,185,129,0.15); animation: slideInDown 0.5s ease-out;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="width: 48px; height: 48px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-feather="check-circle" style="width: 24px; height: 24px; color: white;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #065f46; font-size: 1.125rem; margin-bottom: 0.25rem;">✅ Berhasil!</div>
                    <div style="color: #047857; font-size: 0.95rem;"><?php echo $_SESSION['success'];
                                                                        unset($_SESSION['success']); ?></div>
                </div>
                <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #059669; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div style="background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-left: 4px solid #ef4444; padding: 1.25rem 1.5rem; border-radius: 0.75rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(239,68,68,0.15); animation: slideInDown 0.5s ease-out;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="width: 48px; height: 48px; background: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i data-feather="x-circle" style="width: 24px; height: 24px; color: white;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #991b1b; font-size: 1.125rem; margin-bottom: 0.25rem;">❌ Error!</div>
                    <div style="color: #b91c1c; font-size: 0.95rem;"><?php echo $_SESSION['error'];
                                                                        unset($_SESSION['error']); ?></div>
                </div>
                <button onclick="this.parentElement.parentElement.style.display='none'" style="background: none; border: none; color: #dc2626; font-size: 1.5rem; cursor: pointer; padding: 0; width: 32px; height: 32px;">&times;</button>
            </div>
        </div>
    <?php endif; ?>

    <style>
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

    <!-- Summary Cards -->
    <div class="dashboard-grid" style="margin-bottom: 2rem;">
        <?php if ($isCQC):
            // Use actual balance from cash_accounts
            $saldoKasOperasional = $actualPettyCashBalance;
        ?>
            <!-- CQC: Skip top summary cards, shown in Petty Cash CQC section below -->
        <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Total Pemasukan</div>
                        <div class="card-value text-success"><?php echo formatCurrency($totalIncome); ?></div>
                    </div>
                    <div class="card-icon income">
                        <i data-feather="arrow-down-circle"></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isCQC): ?>
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Total Pengeluaran</div>
                        <div class="card-value text-danger"><?php echo formatCurrency($totalExpense); ?></div>
                    </div>
                    <div class="card-icon expense">
                        <i data-feather="arrow-up-circle"></i>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Saldo</div>
                        <div class="card-value <?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($balance); ?>
                        </div>
                    </div>
                    <div class="card-icon balance">
                        <i data-feather="dollar-sign"></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Transactions Table -->
    <div class="table-container">
        <div class="table-header <?php echo $isCQC ? 'table-header-cqc' : ''; ?>" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <?php if ($isCQC): ?>
                    <div style="width: 40px; height: 40px; border-radius: 8px; background: linear-gradient(135deg, #f0b429, #d4960d); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                        ☀️
                    </div>
                <?php else: ?>
                    <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center;">
                        <i data-feather="book" style="width: 20px; height: 20px; color: white;"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h3 style="font-size: 1.125rem; font-weight: 700; margin: 0;">
                        <?php echo $isCQC ? 'Buku Kas Proyek CQC' : 'Daftar Transaksi'; ?>
                    </h3>
                    <p style="font-size: 0.813rem; margin: 0;">
                        <?php echo count($transactions); ?> transaksi ditemukan
                    </p>
                </div>
            </div>
            <div class="table-actions" style="display: flex; gap: 0.5rem;">
                <a href="cash-transfers.php" class="btn btn-secondary btn-white-text cashbook-action-btn cashbook-btn-reset" style="display: flex; align-items: center; gap: 0.5rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                    <i data-feather="send" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                    <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">🏦 Ringkasan Setor Tunai</span>
                </a>
                <a href="logs.php" class="btn btn-secondary btn-white-text cashbook-action-btn cashbook-btn-reset" style="display: flex; align-items: center; gap: 0.5rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                    <i data-feather="activity" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                    <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Audit Log</span>
                </a>
                <a href="add.php" class="btn btn-primary btn-white-text cashbook-action-btn cashbook-btn-filter" style="background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                    <i data-feather="plus" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                    <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Tambah Transaksi</span>
                </a>
            </div>
        </div>

        <!-- Filters -->
        <?php if ($isCQC): ?>
            <form method="GET" action="" autocomplete="off" class="cqc-filter-card">
                <div class="cqc-filter-grid">
                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">📅 Tanggal</label>
                        <input type="date" id="filterDate" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" class="cqc-filter-input" autocomplete="off" onchange="if(this.value) document.getElementById('filterMonth').value=''" <?php echo empty($filterDate) ? ' placeholder="Pilih tanggal"' : ''; ?>>
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">📆 Bulan</label>
                        <input type="month" id="filterMonth" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="cqc-filter-input" autocomplete="off" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" onchange="if(this.value) document.getElementById('filterDate').value=''">
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">📊 Tipe</label>
                        <select name="type" class="cqc-filter-input">
                            <option value="all" <?php echo ($filterType === 'all' || empty($filterType)) ? 'selected' : ''; ?>>Semua</option>
                            <option value="income" <?php echo $filterType === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                            <option value="expense" <?php echo $filterType === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                        </select>
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">☀️ Proyek</label>
                        <select name="division" class="cqc-filter-input">
                            <option value="all">Semua Proyek</option>
                            <?php foreach ($divisions as $div): ?>
                                <option value="<?php echo $div['id']; ?>" <?php echo $filterDivision == $div['id'] ? 'selected' : ''; ?>>
                                    <?php echo $div['division_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">💳 Pembayaran</label>
                        <select name="payment" class="cqc-filter-input">
                            <option value="all" <?php echo ($filterPayment === 'all' || empty($filterPayment)) ? 'selected' : ''; ?>>Semua</option>
                            <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="transfer" <?php echo $filterPayment === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                            <option value="debit" <?php echo $filterPayment === 'debit' ? 'selected' : ''; ?>>Debit</option>
                            <option value="qr" <?php echo $filterPayment === 'qr' ? 'selected' : ''; ?>>QR Code</option>
                        </select>
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">👤 Input By</label>
                        <select name="user" class="cqc-filter-input">
                            <option value="all" <?php echo ($filterUser === 'all' || empty($filterUser)) ? 'selected' : ''; ?>>Semua User</option>
                            <?php foreach ($usersForFilter as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cqc-filter-group">
                        <label class="cqc-filter-label">🔍 Cari Nama/Ket</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" class="cqc-filter-input" placeholder="Cth: Pak Ipin, BBM..." autocomplete="off">
                    </div>

                    <div class="cqc-filter-actions">
                        <button type="submit" class="cqc-btn-filter cashbook-action-btn cashbook-btn-filter btn-white-text" style="background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                            <i data-feather="filter" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                            <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Filter Data</span>
                        </button>
                        <a href="index.php" class="cqc-btn-reset cashbook-action-btn cashbook-btn-reset btn-white-text" style="background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                            <i data-feather="x" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                            <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Reset</span>
                        </a>
                        <button type="button" onclick="cetakPDF()" class="cqc-btn-filter cashbook-action-btn cashbook-btn-pdf btn-white-text" style="flex: 0 0 auto; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important;">
                            <i data-feather="printer" style="width: 16px; height: 16px;"></i>
                            <span>Cetak PDF</span>
                        </button>
                        <button type="button" onclick="exportExcel()" class="cqc-btn-filter cashbook-action-btn cashbook-btn-excel btn-white-text" style="flex: 0 0 auto; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important;">
                            <i data-feather="file-text" style="width: 16px; height: 16px;"></i>
                            <span>Export Excel</span>
                        </button>
                        <button type="button" onclick="sendWhatsApp(event)" class="cqc-btn-filter cashbook-action-btn cashbook-btn-wa btn-white-text" style="flex: 0 0 auto; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important;">
                            <i data-feather="message-circle" style="width: 16px; height: 16px;"></i>
                            <span>Send WhatsApp</span>
                        </button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <form method="GET" action="" autocomplete="off" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0.75rem; margin-bottom: 1.5rem; padding: 1.25rem; background: var(--bg-secondary); border-radius: var(--radius-lg); border: 1px solid var(--bg-tertiary);">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tanggal</label>
                    <input type="date" id="filterDate" name="date" value="<?php echo htmlspecialchars($filterDate); ?>" class="form-control" autocomplete="off" style="height: 38px; font-size: 0.875rem;" onchange="if(this.value) document.getElementById('filterMonth').value=''" <?php echo empty($filterDate) ? ' placeholder="Pilih tanggal"' : ''; ?>>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Bulan</label>
                    <input type="month" id="filterMonth" name="month" value="<?php echo htmlspecialchars($filterMonth); ?>" class="form-control" autocomplete="off" style="height: 38px; font-size: 0.875rem;" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" onchange="if(this.value) document.getElementById('filterDate').value=''">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Tipe</label>
                    <select name="type" class="form-control" style="height: 38px; font-size: 0.875rem;">
                        <option value="all" <?php echo ($filterType === 'all' || empty($filterType)) ? 'selected' : ''; ?>>Semua</option>
                        <option value="income" <?php echo $filterType === 'income' ? 'selected' : ''; ?>>Pemasukan</option>
                        <option value="expense" <?php echo $filterType === 'expense' ? 'selected' : ''; ?>>Pengeluaran</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Divisi</label>
                    <select name="division" class="form-control" style="height: 38px; font-size: 0.875rem;">
                        <option value="all">Semua Divisi</option>
                        <?php foreach ($divisions as $div): ?>
                            <option value="<?php echo $div['id']; ?>" <?php echo $filterDivision == $div['id'] ? 'selected' : ''; ?>>
                                <?php echo $div['division_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Jenis Pembayaran</label>
                    <select name="payment" class="form-control" style="height: 38px; font-size: 0.875rem;">
                        <option value="all" <?php echo ($filterPayment === 'all' || empty($filterPayment)) ? 'selected' : ''; ?>>Semua</option>
                        <optgroup label="Umum">
                            <option value="cash" <?php echo $filterPayment === 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="debit" <?php echo $filterPayment === 'debit' ? 'selected' : ''; ?>>Debit</option>
                            <option value="transfer" <?php echo $filterPayment === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                            <option value="qr" <?php echo $filterPayment === 'qr' ? 'selected' : ''; ?>>QR Code</option>
                            <option value="edc" <?php echo $filterPayment === 'edc' ? 'selected' : ''; ?>>EDC</option>
                        </optgroup>
                        <optgroup label="OTA (Online Travel Agent)">
                            <option value="ota_all" <?php echo $filterPayment === 'ota_all' ? 'selected' : ''; ?>>🌐 Semua OTA</option>
                            <option value="OTA tiket.com" <?php echo $filterPayment === 'OTA tiket.com' ? 'selected' : ''; ?>>tiket.com</option>
                            <option value="OTA Agoda" <?php echo $filterPayment === 'OTA Agoda' ? 'selected' : ''; ?>>Agoda</option>
                            <option value="OTA Booking.com" <?php echo $filterPayment === 'OTA Booking.com' ? 'selected' : ''; ?>>Booking.com</option>
                            <option value="OTA Traveloka" <?php echo $filterPayment === 'OTA Traveloka' ? 'selected' : ''; ?>>Traveloka</option>
                            <option value="OTA Airbnb" <?php echo $filterPayment === 'OTA Airbnb' ? 'selected' : ''; ?>>Airbnb</option>
                            <option value="OTA Expedia" <?php echo $filterPayment === 'OTA Expedia' ? 'selected' : ''; ?>>Expedia</option>
                            <option value="OTA Pegipegi" <?php echo $filterPayment === 'OTA Pegipegi' ? 'selected' : ''; ?>>Pegipegi</option>
                        </optgroup>
                        <option value="other" <?php echo $filterPayment === 'other' ? 'selected' : ''; ?>>Lainnya</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">Input By</label>
                    <select name="user" class="form-control" style="height: 38px; font-size: 0.875rem;">
                        <option value="all" <?php echo ($filterUser === 'all' || empty($filterUser)) ? 'selected' : ''; ?>>Semua User</option>
                        <?php foreach ($usersForFilter as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" style="font-size: 0.75rem; margin-bottom: 0.25rem;">🔍 Cari Nama/Ket</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" class="form-control" style="height: 38px; font-size: 0.875rem;" placeholder="Cth: Pak Ipin, BBM..." autocomplete="off">
                </div>

                <div style="display: flex; align-items: flex-end; gap: 0.625rem; grid-column: span 7;">
                    <button type="submit" class="btn btn-primary btn-white-text cashbook-action-btn cashbook-btn-filter" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                        <i data-feather="filter" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                        <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Filter</span>
                    </button>
                    <a href="index.php" class="btn btn-secondary btn-white-text cashbook-action-btn cashbook-btn-reset" style="flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                        <i data-feather="x" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                        <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Reset</span>
                    </a>
                    <button type="button" onclick="cetakPDF()" class="btn btn-primary btn-white-text cashbook-action-btn cashbook-btn-pdf" style="flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                        <i data-feather="printer" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                        <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Cetak PDF</span>
                    </button>
                    <button type="button" onclick="exportExcel()" class="btn btn-primary btn-white-text cashbook-action-btn cashbook-btn-excel" style="flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                        <i data-feather="file-text" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                        <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Export Excel</span>
                    </button>
                    <button type="button" onclick="sendWhatsApp(event)" class="btn btn-primary btn-white-text cashbook-action-btn cashbook-btn-wa" style="flex: 0 0 auto; display: flex; align-items: center; justify-content: center; gap: 0.5rem; height: 40px; padding: 0 1.25rem; background: #1e3a8a !important; border: 1px solid #1e40af !important; color: #ffffff !important; opacity: 1 !important;">
                        <i data-feather="message-circle" style="width: 16px; height: 16px; color:#ffffff !important; stroke:#ffffff !important; opacity:1 !important;"></i>
                        <span style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; opacity:1 !important; font-weight:700 !important;">Send WhatsApp</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <!-- Cash Available Banner -->
        <?php if (!$isCQC): ?>
            <div id="cashAvailableBanner" style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.25rem; margin-bottom: 1rem; background: linear-gradient(135deg, <?php echo $cashAvailable >= 0 ? 'rgba(16,185,129,0.06), rgba(5,150,105,0.03)' : 'rgba(239,68,68,0.06), rgba(220,38,38,0.03)'; ?>); border: 1px solid <?php echo $cashAvailable >= 0 ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.2)'; ?>; border-radius: var(--radius-lg); gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, <?php echo $cashAvailable >= 0 ? '#10b981, #059669' : '#ef4444, #dc2626'; ?>); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <i data-feather="wallet" style="width: 18px; height: 18px; color: #fff;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 600; color: <?php echo $cashAvailable >= 0 ? '#047857' : '#b91c1c'; ?>; text-transform: uppercase; letter-spacing: 0.5px;">Cash Available</div>
                        <div id="cashAvailableValue" style="font-size: 1.35rem; font-weight: 800; color: <?php echo $cashAvailable >= 0 ? '#059669' : '#dc2626'; ?>; letter-spacing: -0.5px; line-height: 1.2; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($cashAvailable); ?></div>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 0.65rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 0.15rem;">Start Cash (<?php echo date('M'); ?>)</div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($startKas); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Table -->
        <div style="overflow-x: auto;">
            <table class="cb-table">
                <thead>
                    <tr>
                        <th style="width: 85px;">Tanggal</th>
                        <th style="width: 50px;">Waktu</th>
                        <th style="width: 100px;"><?php echo $isCQC ? 'Proyek' : 'Divisi'; ?></th>
                        <th style="width: 110px;">Kategori/Nama</th>
                        <th style="width: 60px;">Tipe</th>
                        <th style="width: 70px;">Metode</th>
                        <th style="width: 100px; text-align: right;">Jumlah</th>
                        <th style="text-align: left;">Keterangan</th>
                        <th style="width: 80px;">Input By</th>
                        <th style="width: 70px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                <i data-feather="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem;"></i>
                                <div>Belum ada transaksi</div>
                                <?php if (!empty($filterDate) || !empty($filterMonth)): ?>
                                    <div style="margin-top: 0.5rem; font-size: 0.8rem;">
                                        Filter aktif: <?php echo !empty($filterDate) ? "Tanggal: {$filterDate}" : "Bulan: {$filterMonth}"; ?>
                                        <br><a href="index.php" style="color: var(--primary-color);">Klik untuk reset filter</a>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        // Pre-calculate users per date (Shift detection)
                        $usersByDate = [];
                        foreach ($transactions as $t) {
                            $d = date('Y-m-d', strtotime($t['transaction_date']));
                            $userName = $t['created_by_name'] ? $t['created_by_name'] : 'System';

                            if (!isset($usersByDate[$d])) {
                                $usersByDate[$d] = [];
                            }

                            // Avoid duplicates
                            if (!in_array($userName, $usersByDate[$d])) {
                                $usersByDate[$d][] = $userName;
                            }
                        }

                        // Pre-calculate cash available at END of each day
                        // $cashAvailable = total cash right now, transactions are DESC
                        // Walk newest→oldest: subtract each day's net to get balance at end of each day
                        $cashByDate = [];
                        $dailyNet = []; // net change per day (income - expense) for cash accounts
                        $cashAccSet = isset($allAccIds) ? array_flip($allAccIds) : [];
                        foreach ($transactions as $t) {
                            $d = date('Y-m-d', strtotime($t['transaction_date']));
                            if (!isset($dailyNet[$d])) $dailyNet[$d] = 0;
                            // Only count cash account transactions (petty cash + owner capital)
                            if (!empty($cashAccSet) && isset($t['cash_account_id']) && isset($cashAccSet[$t['cash_account_id']])) {
                                if ($t['transaction_type'] === 'income') {
                                    $dailyNet[$d] += (float)$t['amount'];
                                } else {
                                    $dailyNet[$d] -= (float)$t['amount'];
                                }
                            } elseif (empty($cashAccSet)) {
                                // Fallback: count all transactions
                                if ($t['transaction_type'] === 'income') {
                                    $dailyNet[$d] += (float)$t['amount'];
                                } else {
                                    $dailyNet[$d] -= (float)$t['amount'];
                                }
                            }
                        }
                        // Build end-of-day cash: start from $cashAvailable, subtract days newest→oldest
                        $runCash = $cashAvailable;
                        $sortedDates = array_keys($dailyNet);
                        usort($sortedDates, function ($a, $b) {
                            return strcmp($b, $a);
                        }); // DESC
                        foreach ($sortedDates as $d) {
                            $cashByDate[$d] = $runCash; // cash at END of this day
                            $runCash -= $dailyNet[$d];   // remove this day's net to get previous day's end
                        }

                        $previousDate = null;
                        foreach ($transactions as $trans):
                            // Date Separator Logic
                            $currentDate = date('Y-m-d', strtotime($trans['transaction_date']));
                            // Show separator for first item OR when date changes
                            if ($previousDate === null || $currentDate !== $previousDate):
                                // Get users for this specific date
                                $shiftUsers = implode(', ', $usersByDate[$currentDate] ?? []);
                                $dayCash = $cashByDate[$currentDate] ?? 0;
                        ?>
                                <tr style="background: linear-gradient(135deg, #f1f5f9, #e2e8f0);">
                                    <td colspan="10" style="text-align: center; font-weight: 700; color: #475569; padding: 0.5rem; font-size: 0.8rem;">
                                        Transaksi tanggal: <?php echo formatDate($trans['transaction_date']); ?>
                                        <span style="margin-left: 15px; font-weight: 500; color: #64748b; font-size: 0.85em;">
                                            <i data-feather="users" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i>
                                            Shift: <?php echo $shiftUsers; ?>
                                        </span>
                                        <span style="margin-left: 15px; font-weight: 700; color: <?php echo $dayCash >= 0 ? '#16a34a' : '#dc2626'; ?>; font-size: 0.85em; background: <?php echo $dayCash >= 0 ? 'rgba(22,163,74,0.1)' : 'rgba(220,38,38,0.1)'; ?>; padding: 2px 8px; border-radius: 4px;">
                                            💰 Cash: Rp <?php echo number_format($dayCash, 0, ',', '.'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endif;
                            $previousDate = $currentDate;
                            ?>
                            <tr>
                                <td style="font-size: 0.8rem; white-space: nowrap;">
                                    <?php echo date('d/m/Y', strtotime($trans['transaction_date'])); ?>
                                </td>
                                <td style="font-size: 0.8rem;"><?php echo date('H:i', strtotime($trans['transaction_time'])); ?></td>
                                <td style="font-size: 0.8rem;">
                                    <?php if ($isCQC): ?>
                                        <?php
                                        // Check for Operational Office first
                                        $descForParse = $trans['description'] ?? '';
                                        $isOperational = strpos($descForParse, '[OPERATIONAL_OFFICE]') !== false;

                                        // Parse [CQC_PROJECT:id] from description
                                        $cqcProjMatch = null;
                                        if (!$isOperational && preg_match('/\[CQC_PROJECT:(\d+)\]/', $descForParse, $pidMatch)) {
                                            $cqcProjMatch = $cqcProjectMap[intval($pidMatch[1])] ?? null;
                                        }
                                        // Fallback: try expense mapping
                                        if (!$isOperational && !$cqcProjMatch) {
                                            $lookupKey = ($trans['category_name'] ?? '') . '|' . number_format($trans['amount'], 2, '.', '') . '|' . $trans['transaction_date'];
                                            if (isset($cqcExpenseProjectMap[$lookupKey])) {
                                                $expMatch = $cqcExpenseProjectMap[$lookupKey];
                                                $cqcProjMatch = $cqcProjectMap[$expMatch['project_id']] ?? null;
                                            }
                                        }
                                        ?>
                                        <?php if ($isOperational): ?>
                                            <span class="cqc-office-tag">🏢 Office</span>
                                            <div style="font-size: 0.7rem; color: #475569; margin-top: 0.15rem;">Operasional Kantor</div>
                                        <?php elseif ($cqcProjMatch): ?>
                                            <span class="cqc-project-tag">☀️ <?php echo htmlspecialchars($cqcProjMatch['project_code']); ?></span>
                                            <div style="font-size: 0.7rem; color: #475569; margin-top: 0.15rem;"><?php echo htmlspecialchars($cqcProjMatch['project_name']); ?></div>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: #9ca3af;">—</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (isset($trans['source_type']) && $trans['source_type'] === 'cash_transfer'): ?>
                                            <span style="font-size: 0.75rem; color: #9ca3af;">—</span>
                                        <?php else: ?>
                                            <strong><?php echo $trans['division_name']; ?></strong>
                                            <div style="font-size: 0.7rem; color: var(--text-muted);"><?php echo $trans['division_code']; ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <?php $rowIsCashTransfer = isset($trans['source_type']) && $trans['source_type'] === 'cash_transfer'; ?>
                                <td style="font-size: 0.8rem;">
                                    <?php
                                    if ($rowIsCashTransfer) {
                                        echo 'Setor Tunai';
                                    } elseif ($trans['source_type'] === 'purchase_order' && strpos($trans['category_name'], 'Supplies') !== false) {
                                        if (preg_match('/Pembayaran PO .* - (.*)/', $trans['description'], $matches)) {
                                            echo 'Payment ' . htmlspecialchars($matches[1]);
                                        } else {
                                            echo 'Payment Supplier';
                                        }
                                    } else {
                                        echo $trans['category_name'];
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($rowIsCashTransfer): ?>
                                        <span class="cb-badge" style="background:#e0e7ff; color:#4338ca;">🔄 SETOR</span>
                                    <?php else: ?>
                                        <span class="cb-badge <?php echo $trans['transaction_type']; ?>">
                                            <?php echo $trans['transaction_type'] === 'income' ? 'MASUK' : 'KELUAR'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cb-method">
                                        <?php echo htmlspecialchars(isset($trans['payment_method']) ? strtoupper($trans['payment_method']) : '-'); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 700; font-size: 0.85rem; color: <?php echo $rowIsCashTransfer ? '#4338ca' : ($trans['transaction_type'] === 'income' ? '#059669' : '#dc2626'); ?>;">
                                    <?php echo formatCurrency($trans['amount']); ?>
                                </td>
                                <td style="font-size: 0.8rem;">
                                    <?php if (!$rowIsCashTransfer && isset($trans['source_type']) && $trans['source_type'] != 'manual'): ?>
                                        <span class="cb-ref-tag">
                                            <i data-feather="shopping-cart" style="width: 10px; height: 10px;"></i>
                                            <?php echo isset($trans['reference_no']) ? $trans['reference_no'] : 'REF'; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php
                                    $descDisplay = $trans['description'] ?: '-';
                                    // Strip CQC project tag and operational tag from display
                                    if ($isCQC) {
                                        $descDisplay = trim(preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $descDisplay));
                                        $descDisplay = trim(preg_replace('/\[OPERATIONAL_OFFICE\]\s*/', '', $descDisplay));
                                        if (empty($descDisplay)) $descDisplay = '-';
                                    }
                                    if ($rowIsCashTransfer) {
                                        echo '<span style="color:#4338ca;">🔄 Pemindahan Uang / Setoran Harian</span> — ' . htmlspecialchars($descDisplay);
                                    } else {
                                        // Render fund source tag as colored badge, strip from plain text
                                        $descClean = $descDisplay;
                                        $fundBadge = '';
                                        if (strpos($descDisplay, '[Rekening Operasional]') !== false) {
                                            $descClean = trim(str_replace('[Rekening Operasional]', '', $descDisplay));
                                            $fundBadge = '<span style="display:inline-block;padding:0.1rem 0.4rem;background:#fef3c7;color:#b45309;border:1px solid #fcd34d;border-radius:4px;font-size:0.62rem;font-weight:700;margin-left:0.3rem;vertical-align:middle;">🏦 REK. OPERASIONAL</span>';
                                        } elseif (strpos($descDisplay, '[Kas Besar]') !== false) {
                                            $descClean = trim(str_replace('[Kas Besar]', '', $descDisplay));
                                            $fundBadge = '<span style="display:inline-block;padding:0.1rem 0.4rem;background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:4px;font-size:0.62rem;font-weight:700;margin-left:0.3rem;vertical-align:middle;">🏛️ KAS BESAR</span>';
                                        } elseif (strpos($descDisplay, '[Petty Cash]') !== false) {
                                            $descClean = trim(str_replace('[Petty Cash]', '', $descDisplay));
                                            $fundBadge = '<span style="display:inline-block;padding:0.1rem 0.4rem;background:#dcfce7;color:#166534;border:1px solid #86efac;border-radius:4px;font-size:0.62rem;font-weight:700;margin-left:0.3rem;vertical-align:middle;">💵 PETTY CASH</span>';
                                        }
                                        echo htmlspecialchars($descClean ?: '-') . $fundBadge;
                                    }
                                    ?>

                                </td>
                                <td style="text-align: center;">
                                    <span class="cb-user-badge">
                                        👤 <?php echo htmlspecialchars($trans['created_by_name'] ?: 'System'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="cb-actions">
                                        <?php if ($auth->canEdit('cashbook')): ?>
                                            <?php if (isset($trans['is_editable']) && $trans['is_editable'] == 1): ?>
                                                <a href="edit.php?id=<?php echo $trans['id']; ?>" class="cb-action-btn edit" title="Edit">
                                                    <i data-feather="edit-2"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="cb-action-btn locked" title="Dari PO">
                                                    <i data-feather="lock"></i>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($auth->canDelete('cashbook')): ?>
                                            <a href="delete.php?id=<?php echo $trans['id']; ?>"
                                                onclick="return confirm('Yakin ingin menghapus transaksi ini?')"
                                                class="cb-action-btn delete"
                                                title="Hapus">
                                                <i data-feather="trash-2"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($transactions)):
                    $incomeCount  = 0;
                    $expenseCount = 0;
                    $transferCount = 0;
                    foreach ($transactions as $t) {
                        if ($t['transaction_type'] === 'income') {
                            $incomeCount++;
                        } elseif (isset($t['source_type']) && $t['source_type'] === 'cash_transfer') {
                            $transferCount++;
                        } else {
                            $expenseCount++;
                        }
                    }
                ?>
                    <tfoot>
                        <tr style="background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-top: 2px solid #10b981;">
                            <td colspan="6" style="padding: 0.65rem 1rem; text-align: right; font-weight: 700; font-size: 0.82rem; color: #065f46;">
                                ⬆️ Total Income
                                <span style="font-weight: 400; font-size: 0.74rem; opacity: 0.8;">(<?php echo $incomeCount; ?> transactions)</span>
                            </td>
                            <td style="padding: 0.65rem 1rem; text-align: right; font-weight: 800; font-size: 0.95rem; color: #059669; white-space: nowrap;">
                                <?php echo formatCurrency($totalIncome); ?>
                            </td>
                            <td colspan="3" style="padding: 0.65rem 0.5rem;"></td>
                        </tr>
                        <tr style="background: linear-gradient(135deg, #fee2e2, #fecaca); border-top: 1px solid #f87171;">
                            <td colspan="6" style="padding: 0.65rem 1rem; text-align: right; font-weight: 700; font-size: 0.82rem; color: #7f1d1d;">
                                ⬇️ Total Expense
                                <span style="font-weight: 400; font-size: 0.74rem; opacity: 0.8;">(<?php echo $expenseCount; ?> transactions)</span>
                            </td>
                            <td style="padding: 0.65rem 1rem; text-align: right; font-weight: 800; font-size: 0.95rem; color: #dc2626; white-space: nowrap;">
                                <?php echo formatCurrency($totalExpense); ?>
                            </td>
                            <td colspan="3" style="padding: 0.65rem 0.5rem;"></td>
                        </tr>
                        <?php if ($totalCashTransfer > 0): ?>
                            <tr style="background: linear-gradient(135deg, #e0e7ff, #c7d2fe); border-top: 1px solid #818cf8;">
                                <td colspan="6" style="padding: 0.65rem 1rem; text-align: right; font-weight: 700; font-size: 0.82rem; color: #3730a3;">
                                    🔄 Total Setor Tunai <span style="font-weight: 400;">(bukan pengeluaran, hanya pindah ke bank)</span>
                                    <span style="font-weight: 400; font-size: 0.74rem; opacity: 0.8;">(<?php echo $transferCount; ?> transaksi)</span>
                                </td>
                                <td style="padding: 0.65rem 1rem; text-align: right; font-weight: 800; font-size: 0.95rem; color: #4338ca; white-space: nowrap;">
                                    <?php echo formatCurrency($totalCashTransfer); ?>
                                </td>
                                <td colspan="3" style="padding: 0.65rem 0.5rem;"></td>
                            </tr>
                        <?php endif; ?>
                        <tr style="background: linear-gradient(135deg, <?php echo $balance >= 0 ? '#eff6ff, #dbeafe' : '#fff7ed, #fed7aa'; ?>); border-top: 2px solid <?php echo $balance >= 0 ? '#3b82f6' : '#f97316'; ?>;">
                            <td colspan="6" style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; font-size: 0.85rem; color: #1e293b;">
                                💰 Balance
                                <span style="font-weight: 400; font-size: 0.74rem; opacity: 0.7;">(<?php echo count($transactions); ?> total)</span>
                            </td>
                            <td style="padding: 0.75rem 1rem; text-align: right; font-weight: 900; font-size: 1.05rem; color: <?php echo $balance >= 0 ? '#1d4ed8' : '#ea580c'; ?>; white-space: nowrap;">
                                <?php echo formatCurrency($balance); ?>
                            </td>
                            <td colspan="3" style="padding: 0.75rem 0.5rem;"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if ($isCQC): ?>
    <!-- CQC Kas Operasional -->
    <div class="cqc-daily-expenses">
        <div class="cqc-daily-header">
            <div class="cqc-daily-icon">💰</div>
            <div>
                <div class="cqc-daily-title">Petty Cash CQC</div>
                <div class="cqc-daily-subtitle">Kas operasional untuk office & proyek • Dompet terpisah dari kas invoice</div>
            </div>
        </div>
        <div class="cqc-daily-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="cqc-daily-card owner">
                <div class="cqc-daily-label">
                    <i data-feather="download" style="width: 14px; height: 14px;"></i>
                    Transfer Petty Cash
                </div>
                <div class="cqc-daily-value">Rp <?php echo number_format($totalOwnerFund, 0, ',', '.'); ?></div>
                <div class="cqc-daily-desc">Total transfer ke petty cash</div>
            </div>
            <div class="cqc-daily-card expense">
                <div class="cqc-daily-label">
                    <i data-feather="upload" style="width: 14px; height: 14px;"></i>
                    Pengeluaran dari Petty Cash
                </div>
                <div class="cqc-daily-value">Rp <?php echo number_format($totalPettyCashExpense, 0, ',', '.'); ?></div>
                <div class="cqc-daily-desc">Office & proyek (sumber: petty cash)</div>
            </div>
            <div class="cqc-daily-card balance">
                <div class="cqc-daily-label">
                    <i data-feather="credit-card" style="width: 14px; height: 14px;"></i>
                    Saldo Petty Cash
                </div>
                <div class="cqc-daily-value" style="color: <?php echo $saldoKasOperasional >= 0 ? '#2563eb' : '#dc2626'; ?>;">
                    Rp <?php echo number_format($saldoKasOperasional, 0, ',', '.'); ?>
                </div>
                <div class="cqc-daily-desc">Saldo aktual petty cash</div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Spin animation for loading state -->
<style>
    @keyframes spin-anim {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    .spin-icon {
        animation: spin-anim 1s linear infinite;
    }
</style>

<!-- html2pdf.js for PDF generation -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<!-- JavaScript for Print Handling -->
<script>
    // Check if print parameter is in URL
    const urlParams = new URLSearchParams(window.location.search);
    const isPrint = urlParams.has('print') || window.location.search.includes('print=1');

    if (isPrint) {
        // Hide sidebar and page header
        document.querySelectorAll('.sidebar, .page-header').forEach(el => el.style.display = 'none');

        // Full width main content
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.style.marginLeft = '0';
            mainContent.style.padding = '0';
            mainContent.style.maxWidth = '100%';
        }

        // Replace screen content with print content
        const printHTML = document.getElementById('printSection').innerHTML;
        document.getElementById('screenSection').innerHTML = '';
        document.getElementById('screenSection').style.cssText = 'max-width: 900px; margin: 0 auto; padding: 2rem 1.5rem; background: white; font-family: Segoe UI, Arial, sans-serif;';
        document.getElementById('screenSection').innerHTML = printHTML;
        document.getElementById('printSection').remove();

        // Inject print-preview styles
        const printStyle = document.createElement('style');
        printStyle.textContent = `
            body { background: #f3f4f6 !important; }
            .print-header {
                display: table; width: 100%;
                margin-bottom: 1rem; border-bottom: 2px solid #111827; padding-bottom: 1rem;
            }
            .print-header-left { display: table-cell; width: 12%; vertical-align: middle; text-align: center; }
            .print-header-center { display: table-cell; width: 76%; vertical-align: middle; text-align: center; padding: 0 1rem; }
            .print-header-right { display: table-cell; width: 12%; vertical-align: middle; text-align: right; }
            .print-logo { width: 65px; height: 65px; object-fit: contain; }
            .print-company-name { font-size: 1.4rem; font-weight: 800; color: #111827; margin: 0 0 0.1rem 0; }
            .print-company-type { display: none; }
            .print-title { font-size: 1rem; font-weight: 700; color: #111827; margin: 0.5rem 0 0.2rem 0; text-transform: uppercase; letter-spacing: 1px; }
            .print-period { font-size: 0.85rem; color: #6b7280; margin: 0; }
            .print-summary {
                display: flex; gap: 0; margin-bottom: 1rem;
                border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden;
            }
            .print-summary-card { flex: 1; padding: 0.6rem 0.75rem; text-align: center; border-right: 1px solid #d1d5db; }
            .print-summary-card:last-child { border-right: none; }
            .print-summary-label { font-size: 0.7rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.15rem; }
            .print-summary-value { font-size: 1.05rem; font-weight: 800; color: #111827; }
            .print-summary-value.income { color: #059669; }
            .print-summary-value.expense { color: #dc2626; }
            .print-summary-value.balance { color: #111827; }
            #screenSection table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
            #screenSection thead { background: #111827; color: white; }
            #screenSection th { padding: 0.45rem 0.5rem; font-weight: 600; font-size: 0.7rem; border: 1px solid #111827; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; }
            #screenSection td { padding: 0.35rem 0.5rem; border: 1px solid #e5e7eb; font-size: 0.78rem; line-height: 1.3; }
            #screenSection tbody tr:nth-child(even) { background: #f9fafb; }
            #screenSection tfoot td { border-color: #d1d5db; }
            #screenSection .badge { display: inline-block; padding: 0.15rem 0.4rem; border-radius: 3px; font-size: 0.65rem; font-weight: 700; }
            #screenSection .badge.income { background: #d1fae5; color: #065f46; }
            #screenSection .badge.expense { background: #fee2e2; color: #991b1b; }
            .print-footer { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #d1d5db; display: flex; justify-content: space-around; text-align: center; }
            .print-footer-item { flex: 1; }
            .print-footer-label { font-size: 0.75rem; color: #6b7280; margin-bottom: 2.5rem; }
            .print-footer-line { border-top: 1px solid #111827; width: 70%; margin: 0 auto 0.3rem auto; }
            .print-footer-text { font-size: 0.8rem; color: #111827; font-weight: 600; }
        `;
        document.head.appendChild(printStyle);

        // Auto-trigger print dialog
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    } else {
        // Hide print section for screen
        document.getElementById('printSection').style.display = 'none';
    }

    /**
     * Build complete standalone HTML for PDF (used by both cetakPDF and sendWhatsApp)
     * All styles are embedded inline - no external dependencies
     */
    function buildPdfHtml() {
        return `<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: white; font-family: 'Segoe UI', Arial, sans-serif; padding: 1.5rem; color: #111827; }
.print-header { display: table; width: 100%; margin-bottom: 1rem; border-bottom: 2px solid #111827; padding-bottom: 1rem; }
.print-header-left { display: table-cell; width: 12%; vertical-align: middle; text-align: center; }
.print-header-center { display: table-cell; width: 76%; vertical-align: middle; text-align: center; padding: 0 1rem; }
.print-header-right { display: table-cell; width: 12%; vertical-align: middle; text-align: right; }
.print-logo { width: 65px; height: 65px; object-fit: contain; }
.print-company-name { font-size: 1.3rem; font-weight: 800; color: #111827; margin: 0 0 0.1rem 0; }
.print-company-type { display: none; }
.print-title { font-size: 0.95rem; font-weight: 700; color: #111827; margin: 0.5rem 0 0.2rem 0; text-transform: uppercase; letter-spacing: 1px; }
.print-period { font-size: 0.8rem; color: #6b7280; margin: 0; }
.print-summary { display: flex; gap: 0; margin-bottom: 1rem; border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; }
.print-summary-card { flex: 1; padding: 0.6rem 0.75rem; text-align: center; border-right: 1px solid #d1d5db; }
.print-summary-card:last-child { border-right: none; }
.print-summary-label { font-size: 0.65rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.15rem; }
.print-summary-value { font-size: 1rem; font-weight: 800; color: #111827; }
.print-summary-value.income { color: #059669; }
.print-summary-value.expense { color: #dc2626; }
.print-summary-value.balance { color: #111827; }
table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
thead { background: #111827; }
th { padding: 0.4rem 0.35rem; font-weight: 600; font-size: 0.62rem; border: 1px solid #111827; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; color: white; }
td { padding: 0.3rem 0.35rem; border: 1px solid #e5e7eb; font-size: 0.7rem; line-height: 1.3; }
tbody tr:nth-child(even) { background: #f9fafb; }
tfoot td { border-color: #d1d5db; }
.badge { display: inline-block; padding: 0.12rem 0.35rem; border-radius: 3px; font-size: 0.58rem; font-weight: 700; }
.badge.income { background: #d1fae5; color: #065f46; }
.badge.expense { background: #fee2e2; color: #991b1b; }
.info-box { margin-top: 0.8rem; padding: 0.5rem 0.65rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.65rem; color: #475569; }
.info-box strong { color: #1e293b; }
.print-footer { margin-top: 1.2rem; padding-top: 0.8rem; border-top: 1px solid #d1d5db; display: flex; justify-content: space-around; text-align: center; }
.print-footer-item { flex: 1; }
.print-footer-label { font-size: 0.7rem; color: #6b7280; margin-bottom: 2rem; }
.print-footer-line { border-top: 1px solid #111827; width: 70%; margin: 0 auto 0.25rem auto; }
.print-footer-text { font-size: 0.75rem; color: #111827; font-weight: 600; }
@media print {
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
  body { padding: 0; }
  @page { margin: 0.4in 0.5in; size: A4; }
}
</style></head><body>` + document.getElementById('printSection').innerHTML + `</body></html>`;
    }

    /**
     * Cetak PDF - Opens print dialog directly from current page
     * Uses the existing printSection content without reloading the page
     */
    function cetakPDF() {
        var htmlContent = buildPdfHtml();
        var printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.write(htmlContent);
        printWindow.document.close();
        printWindow.focus();
        printWindow.onload = function() {
            printWindow.print();
        };
    }

    /**
     * Export Excel - Downloads filtered data as .xls file
     * Passes current filter params to export-excel.php
     */
    function exportExcel() {
        var params = new URLSearchParams(window.location.search);
        params.delete('print');
        window.location.href = 'export-excel.php?' + params.toString();
    }

    /**
     * Send WhatsApp - Generates actual PDF file and shares via WhatsApp
     * Design is exactly the same as Cetak PDF / Print preview
     */
    function sendWhatsApp(e) {
        var btn = e ? e.currentTarget : (event ? event.currentTarget : null);
        if (!btn) return;
        var originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:0.5rem;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin-icon"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg> Generating PDF...</span>';

        // Build a visible iframe offscreen for html2pdf to render from
        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:850px;height:1200px;border:none;';
        document.body.appendChild(iframe);

        var htmlContent = buildPdfHtml();
        iframe.contentDocument.open();
        iframe.contentDocument.write(htmlContent);
        iframe.contentDocument.close();

        var fileName = <?php echo json_encode(
                            'Buku-Kas-' . $displayCompanyName . '-' .
                                (!empty($filterMonth) ? $filterMonth : (!empty($filterDate) ? $filterDate : date('Y-m-d')))
                        ); ?> + '.pdf';
        fileName = fileName.replace(/[^a-zA-Z0-9._\-]/g, '_');

        // Wait for iframe content to fully render
        iframe.onload = function() {
            setTimeout(function() {
                var target = iframe.contentDocument.body;

                var opt = {
                    margin: [8, 8, 8, 8],
                    filename: fileName,
                    image: {
                        type: 'jpeg',
                        quality: 0.95
                    },
                    html2canvas: {
                        scale: 2,
                        useCORS: true,
                        logging: false,
                        letterRendering: true,
                        windowWidth: 850
                    },
                    jsPDF: {
                        unit: 'mm',
                        format: 'a4',
                        orientation: 'portrait'
                    }
                };

                html2pdf().set(opt).from(target).outputPdf('blob').then(function(pdfBlob) {
                    document.body.removeChild(iframe);

                    var pdfFile = new File([pdfBlob], fileName, {
                        type: 'application/pdf'
                    });

                    // Try Web Share API (mobile - direct to WhatsApp)
                    if (navigator.canShare && navigator.canShare({
                            files: [pdfFile]
                        })) {
                        navigator.share({
                            title: <?php echo json_encode($printTitle . ' - ' . $displayCompanyName); ?>,
                            text: <?php echo json_encode($printTitle . ' - Periode: ' . $periodText); ?>,
                            files: [pdfFile]
                        }).then(function() {}).catch(function(err) {
                            if (err.name !== 'AbortError') {
                                downloadAndPromptWhatsApp(pdfBlob, fileName);
                            }
                        });
                    } else {
                        downloadAndPromptWhatsApp(pdfBlob, fileName);
                    }

                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                    if (typeof feather !== 'undefined') feather.replace();
                }).catch(function(err) {
                    document.body.removeChild(iframe);
                    btn.disabled = false;
                    btn.innerHTML = originalHTML;
                    if (typeof feather !== 'undefined') feather.replace();
                    alert('Gagal membuat PDF: ' + err.message);
                });
            }, 300);
        };
    }

    /**
     * Fallback for desktop: Download PDF then open WhatsApp Web
     */
    function downloadAndPromptWhatsApp(pdfBlob, fileName) {
        var url = URL.createObjectURL(pdfBlob);
        var a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 5000);

        var caption = <?php echo json_encode(
                            $printTitle . "\n" .
                                $displayCompanyName . "\n" .
                                'Periode: ' . $periodText . "\n" .
                                '━━━━━━━━━━━━━━━━━━' . "\n" .
                                '✅ Pemasukan: ' . formatCurrency($totalIncome) . "\n" .
                                '❌ Pengeluaran: ' . formatCurrency($totalExpense) . "\n" .
                                '💰 Saldo: ' . formatCurrency($balance) . "\n" .
                                '━━━━━━━━━━━━━━━━━━' . "\n" .
                                '📎 File PDF terlampir'
                        ); ?>;
        var waUrl = 'https://wa.me/?text=' + encodeURIComponent(caption);

        setTimeout(function() {
            if (confirm('PDF berhasil di-download!\\n\\nBuka WhatsApp untuk mengirim?\\nLampirkan file: ' + fileName)) {
                window.open(waUrl, '_blank');
            }
        }, 800);
    }

    feather.replace();
</script>

<?php include '../../includes/footer.php'; ?>