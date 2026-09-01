<?php

/**
 * CashbookHelper - Handles automatic sync of payments to cashbook
 * Centralizes all cashbook sync logic for reliability
 */

class CashbookHelper
{
    private $db;
    private $masterDb;
    private $businessId;
    private $userId;

    // Cached values
    private $divisionId = null;
    private $categoryId = null;
    private $hasCashAccountId = null;
    private $allowedPaymentMethods = null;
    private $hasTransactionIdCol = null;

    public function __construct($db, $businessId = null, $userId = null)
    {
        $this->db = $db;
        $this->businessId = $businessId ?? ($_SESSION['business_id'] ?? 1);
        $this->userId = $userId ?? ($_SESSION['user_id'] ?? 1);

        // Log for debugging
        error_log("CashbookHelper: Init with businessId={$this->businessId}, userId={$this->userId}");

        $this->initMasterDb();
        $this->validateUserId();
    }

    /**
     * Initialize master database connection
     */
    private function initMasterDb()
    {
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';

        // Log for debugging
        error_log("CashbookHelper: Connecting to master DB: {$masterDbName}");

        try {
            $this->masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            error_log("CashbookHelper: Master DB connected successfully");
        } catch (\Throwable $e) {
            error_log("CashbookHelper: Master DB connection failed: " . $e->getMessage());
            // Fallback: Use current DB for single-DB hosting
            if (defined('DB_NAME')) {
                try {
                    $this->masterDb = new PDO(
                        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                        DB_USER,
                        DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                    error_log("CashbookHelper: Fallback to DB_NAME: " . DB_NAME);
                } catch (\Throwable $e2) {
                    $this->masterDb = $this->db->getConnection();
                    error_log("CashbookHelper: Fallback to current DB connection");
                }
            } else {
                $this->masterDb = $this->db->getConnection();
                error_log("CashbookHelper: Using current DB connection as master");
            }
        }
    }

    /**
     * Validate user ID exists in business DB
     */
    private function validateUserId()
    {
        try {
            $userExists = $this->db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$this->userId]);
            if (!$userExists) {
                $firstUser = $this->db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $this->userId = $firstUser['id'] ?? 1;
            }
        } catch (\Throwable $e) {
            $this->userId = 1;
        }
    }

    /**
     * Get or create cash account for the business
     */
    public function getCashAccount($paymentMethod = 'cash')
    {
        $accountType = ($paymentMethod === 'cash') ? 'cash' : 'bank';

        error_log("CashbookHelper::getCashAccount: Looking for {$accountType} account for business_id={$this->businessId}");

        // Try to get existing account
        $stmt = $this->masterDb->prepare("
            SELECT id, account_name, current_balance 
            FROM cash_accounts 
            WHERE business_id = ? 
            AND account_type = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$this->businessId, $accountType]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            error_log("CashbookHelper::getCashAccount: No {$accountType} account found, trying any account");
            // Fallback to any account
            $stmt = $this->masterDb->prepare("
                SELECT id, account_name, current_balance 
                FROM cash_accounts 
                WHERE business_id = ? 
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$this->businessId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Create default account if none exists
        if (!$account) {
            error_log("CashbookHelper::getCashAccount: No account found at all, creating default");
            $account = $this->createDefaultCashAccount($accountType);
        } else {
            error_log("CashbookHelper::getCashAccount: Found account ID={$account['id']}, name={$account['account_name']}");
        }

        return $account;
    }

    /**
     * Create default cash account if missing
     */
    private function createDefaultCashAccount($accountType = 'cash')
    {
        $accountName = ($accountType === 'cash') ? 'KAS TUNAI (AUTO)' : 'REKENING BANK (AUTO)';

        try {
            $stmt = $this->masterDb->prepare("
                INSERT INTO cash_accounts (
                    business_id, account_name, account_type, currency, 
                    current_balance, created_at
                ) VALUES (?, ?, ?, 'IDR', 0, NOW())
            ");
            $stmt->execute([$this->businessId, $accountName, $accountType]);

            $accountId = $this->masterDb->lastInsertId();

            return [
                'id' => $accountId,
                'account_name' => $accountName,
                'current_balance' => 0
            ];
        } catch (\Throwable $e) {
            error_log("CashbookHelper: Failed to create default account - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get division ID for hotel/frontdesk transactions
     */
    public function getDivisionId()
    {
        if ($this->divisionId !== null) {
            return $this->divisionId;
        }

        $division = $this->db->fetchOne("
            SELECT id FROM divisions 
            WHERE LOWER(division_name) LIKE '%hotel%' 
               OR LOWER(division_name) LIKE '%front%' 
               OR LOWER(division_name) LIKE '%room%' 
               OR LOWER(division_name) LIKE '%kamar%' 
            ORDER BY id ASC LIMIT 1
        ");

        if (!$division) {
            $division = $this->db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
        }

        // Create default division if none exists
        if (!$division) {
            try {
                $this->db->query("INSERT INTO divisions (division_name, created_at) VALUES ('Hotel / Frontdesk', NOW())");
                $division = ['id' => $this->db->getConnection()->lastInsertId()];
            } catch (\Throwable $e) {
                $division = ['id' => 1];
            }
        }

        $this->divisionId = $division['id'] ?? 1;
        return $this->divisionId;
    }

    /**
     * Get category ID for room sales income
     */
    public function getCategoryId()
    {
        if ($this->categoryId !== null) {
            return $this->categoryId;
        }

        // Priority 1: Exact match for room sales/rental categories
        $category = $this->db->fetchOne("
            SELECT id FROM categories 
            WHERE category_type = 'income' 
            AND (
                LOWER(category_name) = 'room sell'
                OR LOWER(category_name) = 'room rental'
                OR LOWER(category_name) = 'penjualan kamar'
                OR LOWER(category_name) = 'sewa kamar'
                OR LOWER(category_name) LIKE '%room sell%'
                OR LOWER(category_name) LIKE '%room rental%'
                OR LOWER(category_name) LIKE '%penjualan kamar%'
            )
            ORDER BY id ASC LIMIT 1
        ");

        // Priority 2: Contains 'kamar' but exclude 'service'
        if (!$category) {
            $category = $this->db->fetchOne("
                SELECT id FROM categories 
                WHERE category_type = 'income' 
                AND (
                    LOWER(category_name) LIKE '%kamar%'
                    OR LOWER(category_name) LIKE '%room%'
                )
                AND LOWER(category_name) NOT LIKE '%service%'
                ORDER BY id ASC LIMIT 1
            ");
        }

        // Priority 3: Any income category
        if (!$category) {
            $category = $this->db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
        }

        // Create default category if none exists
        if (!$category) {
            try {
                $this->db->query("INSERT INTO categories (category_name, category_type, created_at) VALUES ('Penjualan Kamar', 'income', NOW())");
                $category = ['id' => $this->db->getConnection()->lastInsertId()];
            } catch (\Throwable $e) {
                $category = ['id' => 1];
            }
        }

        $this->categoryId = $category['id'] ?? 1;
        return $this->categoryId;
    }

    /**
     * Check if cash_book has cash_account_id column
     */
    public function hasCashAccountIdColumn()
    {
        if ($this->hasCashAccountId !== null) {
            return $this->hasCashAccountId;
        }

        try {
            $colChk = $this->db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
            $this->hasCashAccountId = $colChk && $colChk->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->hasCashAccountId = false;
        }

        return $this->hasCashAccountId;
    }

    /**
     * Get allowed payment methods from cash_book ENUM
     */
    public function getAllowedPaymentMethods()
    {
        if ($this->allowedPaymentMethods !== null) {
            return $this->allowedPaymentMethods;
        }

        try {
            $pmColInfo = $this->db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
            if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                $this->allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
            }
        } catch (\Throwable $e) {
            $this->allowedPaymentMethods = null;
        }

        return $this->allowedPaymentMethods;
    }

    /**
     * Map and validate payment method for cash_book
     */
    public function mapPaymentMethod($paymentMethod)
    {
        $pmMap = ['bank_transfer' => 'transfer', 'credit_card' => 'debit', 'credit' => 'debit', 'card' => 'debit', 'qris' => 'qr'];
        $cbMethod = $paymentMethod ?? 'cash';

        // OTA payments come via bank transfer from OTA platform → map to 'transfer'
        // OTA source label is stored in description instead
        if (stripos($cbMethod, 'OTA ') === 0 || stripos($cbMethod, 'ota_') === 0) {
            $cbMethod = 'transfer';
        } else {
            $cbMethod = strtolower($cbMethod);
            $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
        }

        $allowedMethods = $this->getAllowedPaymentMethods();
        if ($allowedMethods !== null && !in_array($cbMethod, $allowedMethods)) {
            $cbMethod = in_array('transfer', $allowedMethods) ? 'transfer' : (in_array('other', $allowedMethods) ? 'other' : (in_array('cash', $allowedMethods) ? 'cash' : $allowedMethods[0]));
        } elseif ($allowedMethods === null) {
            $validMethods = ['cash', 'debit', 'transfer', 'qr', 'card', 'qris', 'bank_transfer', 'ota', 'agoda', 'booking', 'other'];
            if (!in_array($cbMethod, $validMethods)) {
                $cbMethod = 'transfer';
            }
        }

        return $cbMethod;
    }

    /**
     * Normalize OTA source name for consistent matching
     */
    private function normalizeOtaSource($bookingSource)
    {
        $source = strtolower(trim($bookingSource ?? ''));
        // Remove common suffixes
        $source = str_replace(['.com', '.co.id', '.id'], '', $source);
        // Remove spaces and special chars
        $source = preg_replace('/[^a-z0-9]/', '', $source);
        return $source;
    }

    /**
     * Calculate OTA fee based on booking source
     */
    public function calculateOtaFee($amount, $bookingSource)
    {
        // Normalize source name (tiket.com -> tiket, Booking.com -> booking, etc)
        $normalizedSource = $this->normalizeOtaSource($bookingSource);

        // All recognized OTA sources
        $otaSources = [
            'agoda',
            'booking',
            'bookingcom',
            'tiket',
            'tiketcom',
            'airbnb',
            'traveloka',
            'expedia',
            'pegipegi',
            'ota'
        ];

        // Check if this is an OTA
        $isOta = false;
        $matchedSource = 'ota';  // default fallback
        foreach ($otaSources as $ota) {
            if (strpos($normalizedSource, $ota) !== false || $normalizedSource === $ota) {
                $isOta = true;
                $matchedSource = $ota;
                break;
            }
        }

        if (!$isOta) {
            return ['gross' => $amount, 'fee_percent' => 0, 'fee_amount' => 0, 'net' => $amount];
        }

        // Map normalized source to setting key
        $settingKeyMap = [
            'agoda' => 'ota_fee_agoda',
            'booking' => 'ota_fee_booking_com',
            'bookingcom' => 'ota_fee_booking_com',
            'tiket' => 'ota_fee_tiket_com',
            'tiketcom' => 'ota_fee_tiket_com',
            'airbnb' => 'ota_fee_airbnb',
            'traveloka' => 'ota_fee_traveloka',
            'expedia' => 'ota_fee_expedia',
            'pegipegi' => 'ota_fee_pegipegi',
            'ota' => 'ota_fee_other_ota'
        ];

        // Use matched source (normalized) for lookup
        $settingKey = $settingKeyMap[$matchedSource] ?? 'ota_fee_other_ota';

        error_log("CashbookHelper::calculateOtaFee: source='{$bookingSource}' normalized='{$normalizedSource}' matched='{$matchedSource}'");

        try {
            // Primary: read fee directly from booking_sources table in BUSINESS DB (single source of truth)
            $feeQuery = null;
            try {
                $feeStmt = $this->db->getConnection()->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
                $feeStmt->execute([$matchedSource]);
                $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                error_log("CashbookHelper::calculateOtaFee: booking_sources not in business DB: " . $e->getMessage());
            }

            // Fallback 1: try booking_sources in master DB
            if (!$feeQuery) {
                try {
                    $feeStmt = $this->masterDb->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
                    $feeStmt->execute([$matchedSource]);
                    $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    // Table might not exist in master DB either
                }
            }

            if (!$feeQuery) {
                // Fallback 2: read from settings table
                $settingKey = $settingKeyMap[$matchedSource] ?? 'ota_fee_other_ota';
                error_log("CashbookHelper::calculateOtaFee: booking_sources miss, fallback to settings key '{$settingKey}'");
                $feeStmt = $this->masterDb->prepare("SELECT setting_value as fee_percent FROM settings WHERE setting_key = ?");
                $feeStmt->execute([$settingKey]);
                $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($feeQuery) {
                $feePercent = (float)($feeQuery['fee_percent'] ?? 0);
                error_log("CashbookHelper::calculateOtaFee: feePercent={$feePercent}% amount={$amount}");
                if ($feePercent > 0) {
                    $feeAmount = ($amount * $feePercent) / 100;
                    error_log("CashbookHelper::calculateOtaFee: feeAmount={$feeAmount} net=" . ($amount - $feeAmount));
                    return [
                        'gross' => $amount,
                        'fee_percent' => $feePercent,
                        'fee_amount' => $feeAmount,
                        'net' => $amount - $feeAmount
                    ];
                }
            } else {
                error_log("CashbookHelper::calculateOtaFee: No fee found for source '{$matchedSource}'");
            }
        } catch (\Throwable $e) {
            error_log("CashbookHelper: OTA fee calculation error - " . $e->getMessage());
        }

        return ['gross' => $amount, 'fee_percent' => 0, 'fee_amount' => 0, 'net' => $amount];
    }

    /**
     * Check if cash_account_transactions has transaction_id column
     */
    public function hasTransactionIdColumn()
    {
        if ($this->hasTransactionIdCol !== null) {
            return $this->hasTransactionIdCol;
        }

        try {
            $chk = $this->masterDb->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'");
            $this->hasTransactionIdCol = $chk && $chk->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->hasTransactionIdCol = false;
        }

        return $this->hasTransactionIdCol;
    }

    /**
     * Main method: Sync booking payment to cashbook
     * 
     * @param array $paymentData Payment data with keys:
     *   - payment_id: ID from booking_payments table
     *   - booking_id: Related booking ID
     *   - amount: Payment amount
     *   - payment_method: cash, transfer, etc.
     *   - guest_name: Guest name
     *   - booking_code: Booking code
     *   - room_number: Room number (optional)
     *   - booking_source: Original booking source (for OTA fee)
     *   - final_price: Total price of booking (for LUNAS/DP label)
     *   - total_paid: Total paid so far (optional)
     *   - payment_date: Payment date (optional, defaults to NOW())
     *   - is_new_reservation: True if this is from new reservation (for label)
     * 
     * @return array Result with keys: success, transaction_id, account_name, message, ota_fee
     */
    public function syncPaymentToCashbook($paymentData)
    {
        error_log("CashbookHelper::syncPaymentToCashbook: START - businessId={$this->businessId}, data=" . json_encode($paymentData));

        $result = [
            'success' => false,
            'transaction_id' => null,
            'account_name' => '',
            'message' => '',
            'ota_fee' => null
        ];

        try {
            // Validate required data
            if (empty($paymentData['amount']) || $paymentData['amount'] <= 0) {
                $result['message'] = 'Amount harus lebih dari 0';
                error_log("CashbookHelper::syncPaymentToCashbook: FAILED - Amount not valid");
                return $result;
            }

            // Get cash account
            $account = $this->getCashAccount($paymentData['payment_method'] ?? 'cash');
            if (!$account) {
                $result['message'] = 'Tidak dapat membuat/menemukan akun kas';
                error_log("CashbookHelper::syncPaymentToCashbook: FAILED - Cannot get cash account");
                return $result;
            }

            $result['account_name'] = $account['account_name'];

            $bookingCode = $paymentData['booking_code'] ?? '';
            $isOtaCheckin = $paymentData['is_ota_checkin'] ?? false;

            // Calculate OTA fee if applicable
            $bookingSource = $paymentData['booking_source'] ?? '';

            if ($isOtaCheckin) {
                // OTA check-in: calculate NET amount (gross - OTA commission)
                // final_price is GROSS (room_price * nights - discount), OTA fee not yet deducted
                $grossAmount = (float)($paymentData['final_price'] ?: $paymentData['amount']);
                $otaCalc = $this->calculateOtaFee($grossAmount, $bookingSource);
                $amountToRecord = $otaCalc['net'];
                error_log("CashbookHelper: OTA check-in - gross={$grossAmount}, fee={$otaCalc['fee_percent']}%, net={$amountToRecord}");
            } else {
                $otaCalc = $this->calculateOtaFee($paymentData['amount'], $bookingSource);
                $amountToRecord = $otaCalc['net'];
            }
            $result['ota_fee'] = $otaCalc;

            // ---- DEDUP CHECK: only block a TRUE duplicate submit (same booking,
            // same amount, within a short time window - e.g. double-click or a
            // network retry resubmitting the exact same payment). Must NOT block
            // a legitimate later payment for the same booking (e.g. pelunasan
            // after DP) - those are different transactions and must both be
            // recorded in the cashbook, even if by coincidence the amounts match
            // (the time-window check protects against that edge case).
            if ($bookingCode) {
                $existingEntry = $this->db->fetchOne("
                    SELECT id FROM cash_book 
                    WHERE description LIKE ? AND transaction_type = 'income' AND amount = ?
                    AND created_at >= (NOW() - INTERVAL 30 SECOND)
                    LIMIT 1
                ", ['%' . $bookingCode . '%', $amountToRecord]);
                if ($existingEntry) {
                    $result['success'] = true;
                    $result['transaction_id'] = $existingEntry['id'];
                    $result['message'] = 'Entry sudah ada di buku kas (skip duplikat)';
                    error_log("CashbookHelper: DEDUP - Duplicate submit detected for {$bookingCode} amount={$amountToRecord}, id={$existingEntry['id']}");
                    return $result;
                }
            }
            // ---- END DEDUP CHECK ----

            // Get division and category
            $divisionId = $this->getDivisionId();
            $categoryId = $this->getCategoryId();

            // Build description
            $guestName = $paymentData['guest_name'] ?? 'Guest';
            $bookingCode = $paymentData['booking_code'] ?? '';
            $roomNumber = $paymentData['room_number'] ?? '';
            $bookingNotes = trim($paymentData['booking_notes'] ?? '');

            $description = "{$guestName}";
            if ($roomNumber) {
                $description .= " - Room {$roomNumber}";
            }
            if ($bookingCode) {
                $description .= " ({$bookingCode})";
            }

            // Determine payment status label
            $isNewReservation = $paymentData['is_new_reservation'] ?? false;
            $finalPrice = (float)($paymentData['final_price'] ?? 0);
            $totalPaid = (float)($paymentData['total_paid'] ?? $paymentData['amount']);

            if ($isNewReservation) {
                // New reservation: LUNAS or DP
                if ($totalPaid >= $finalPrice && $finalPrice > 0) {
                    $description .= ' [LUNAS]';
                } else {
                    $description .= ' [DP]';
                }
            } else {
                // Additional payment: PELUNASAN or CICILAN
                if ($totalPaid >= $finalPrice && $finalPrice > 0) {
                    $description .= ' [PELUNASAN]';
                } else {
                    $description .= ' [CICILAN]';
                }
            }

            // Add booking notes if available
            if ($bookingNotes) {
                $description .= ' - ' . $bookingNotes;
            }

            // Add OTA source label to description (since payment_method is mapped to 'transfer')
            if ($isOtaCheckin && !empty($bookingSource)) {
                $sourceLabel = ucfirst($bookingSource);
                $description .= " [OTA {$sourceLabel}]";
                if ($otaCalc['fee_percent'] > 0) {
                    $description .= " (fee {$otaCalc['fee_percent']}%)";
                }
            }

            // Check if this is OTA check-in (should be editable for reconciliation)
            $isOtaCheckin = $paymentData['is_ota_checkin'] ?? false;

            // Map payment method
            $cbMethod = $this->mapPaymentMethod($paymentData['payment_method'] ?? 'cash');

            // Get payment date
            $paymentDate = $paymentData['payment_date'] ?? date('Y-m-d H:i:s');

            // Set is_editable: 1 for OTA (reconciliation needed), 0 for direct
            $isEditable = $isOtaCheckin ? 1 : 1;  // Default all editable, but OTA marked specially

            // Insert into cash_book with is_editable flag
            if ($this->hasCashAccountIdColumn()) {
                $stmt = $this->db->getConnection()->prepare("
                    INSERT INTO cash_book (
                        transaction_date, transaction_time, division_id, category_id,
                        description, transaction_type, amount, payment_method,
                        cash_account_id, is_editable, created_by, created_at
                    ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $paymentDate,
                    $paymentDate,
                    $divisionId,
                    $categoryId,
                    $description,
                    $amountToRecord,
                    $cbMethod,
                    $account['id'],
                    $isEditable,
                    $this->userId
                ]);
            } else {
                $stmt = $this->db->getConnection()->prepare("
                    INSERT INTO cash_book (
                        transaction_date, transaction_time, division_id, category_id,
                        description, transaction_type, amount, payment_method,
                        is_editable, created_by, created_at
                    ) VALUES (DATE(?), TIME(?), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $paymentDate,
                    $paymentDate,
                    $divisionId,
                    $categoryId,
                    $description,
                    $amountToRecord,
                    $cbMethod,
                    $isEditable,
                    $this->userId
                ]);
            }

            $transactionId = $this->db->getConnection()->lastInsertId();
            $result['transaction_id'] = $transactionId;

            // Insert into master cash_account_transactions
            if ($this->hasTransactionIdColumn()) {
                $masterStmt = $this->masterDb->prepare("
                    INSERT INTO cash_account_transactions (
                        cash_account_id, transaction_id, transaction_date,
                        description, amount, transaction_type,
                        reference_number, created_by, created_at
                    ) VALUES (?, ?, DATE(?), ?, ?, 'income', ?, ?, NOW())
                ");
                $masterStmt->execute([
                    $account['id'],
                    $transactionId,
                    $paymentDate,
                    $description,
                    $amountToRecord,
                    $bookingCode,
                    $this->userId
                ]);
            } else {
                $masterStmt = $this->masterDb->prepare("
                    INSERT INTO cash_account_transactions (
                        cash_account_id, transaction_date,
                        description, amount, transaction_type,
                        reference_number, created_by, created_at
                    ) VALUES (?, DATE(?), ?, ?, 'income', ?, ?, NOW())
                ");
                $masterStmt->execute([
                    $account['id'],
                    $paymentDate,
                    $description,
                    $amountToRecord,
                    $bookingCode,
                    $this->userId
                ]);
            }

            // Update cash account balance
            $newBalance = $account['current_balance'] + $amountToRecord;
            $updateStmt = $this->masterDb->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?");
            $updateStmt->execute([$newBalance, $account['id']]);

            // Mark booking_payment as synced (if payment_id provided)
            if (!empty($paymentData['payment_id'])) {
                try {
                    $this->db->query(
                        "UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?",
                        [$transactionId, $paymentData['payment_id']]
                    );
                } catch (\Throwable $e) {
                    error_log("CashbookHelper: Failed to mark payment synced - " . $e->getMessage());
                }
            }

            $result['success'] = true;
            $result['message'] = "Tercatat di Buku Kas - {$account['account_name']}";

            // Log success
            error_log("CashbookHelper::syncPaymentToCashbook: SUCCESS - transactionId={$transactionId}, accountId={$account['id']}, accountName={$account['account_name']}, amount=" . number_format($amountToRecord) . ", bookingCode={$bookingCode}");
        } catch (\Throwable $e) {
            $result['message'] = "Error: " . $e->getMessage();
            error_log("CashbookHelper::syncPaymentToCashbook: EXCEPTION - " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        }

        return $result;
    }

    /**
     * Sync all unsynced payments in batch
     * 
     * @return array Result with count of synced payments
     */
    public function syncAllUnsynced()
    {
        $result = ['synced' => 0, 'errors' => 0, 'details' => []];

        try {
            // Get unsynced payments (OTA only when checked_in/checked_out)
            $otaSources = ['agoda', 'booking', 'bookingcom', 'tiket', 'tiketcom', 'airbnb', 'traveloka', 'expedia', 'pegipegi', 'ota'];
            $otaPlaceholders = implode(',', array_fill(0, count($otaSources), '?'));
            $unsyncedPayments = $this->db->fetchAll("
                SELECT bp.id as payment_id, bp.booking_id, bp.amount, bp.payment_method, 
                       bp.payment_date, b.booking_code, b.booking_source, b.final_price,
                       b.status as booking_status, g.guest_name, r.room_number
                FROM booking_payments bp
                JOIN bookings b ON bp.booking_id = b.id
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE bp.synced_to_cashbook = 0
                AND (
                    -- Direct Booking: sync anytime
                    (LOWER(COALESCE(b.booking_source,'')) NOT IN ({$otaPlaceholders})
                     AND LOWER(COALESCE(b.booking_source,'')) NOT LIKE '%ota%')
                    OR
                    -- OTA: only sync if checked_in or checked_out
                    (b.status IN ('checked_in', 'checked_out'))
                )
                ORDER BY bp.id ASC
            ", $otaSources);

            foreach ($unsyncedPayments as $payment) {
                // Get total paid for this booking
                $totalPaid = $this->db->fetchOne(
                    "SELECT COALESCE(SUM(amount), 0) as total FROM booking_payments WHERE booking_id = ?",
                    [$payment['booking_id']]
                );

                $syncResult = $this->syncPaymentToCashbook([
                    'payment_id' => $payment['payment_id'],
                    'booking_id' => $payment['booking_id'],
                    'amount' => $payment['amount'],
                    'payment_method' => $payment['payment_method'],
                    'guest_name' => $payment['guest_name'],
                    'booking_code' => $payment['booking_code'],
                    'room_number' => $payment['room_number'],
                    'booking_source' => $payment['booking_source'],
                    'final_price' => $payment['final_price'],
                    'total_paid' => $totalPaid['total'] ?? $payment['amount'],
                    'payment_date' => $payment['payment_date'],
                    'is_new_reservation' => false
                ]);

                if ($syncResult['success']) {
                    $result['synced']++;
                } else {
                    $result['errors']++;
                }

                $result['details'][] = [
                    'payment_id' => $payment['payment_id'],
                    'booking_code' => $payment['booking_code'],
                    'success' => $syncResult['success'],
                    'message' => $syncResult['message']
                ];
            }
        } catch (\Throwable $e) {
            error_log("CashbookHelper: Batch sync error - " . $e->getMessage());
        }

        return $result;
    }
}
