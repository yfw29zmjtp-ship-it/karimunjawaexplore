<?php

/**
 * MULTI-BUSINESS MANAGEMENT SYSTEM
 * Dashboard - Main Page
 */

ob_start();

define('APP_ACCESS', true);
require_once 'config/config.php';

// Check if database exists, redirect to installer if not
try {
    $testConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
} catch (PDOException $e) {
    // Database not exists, redirect to setup page
    header('Location: setup-required.html');
    exit;
}

require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/trial_check.php';

$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();

// Load business configuration (already loaded in config.php, use safe fallback)
$businessConfigFile = __DIR__ . '/config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
$businessConfig = file_exists($businessConfigFile) ? require $businessConfigFile : $BUSINESS_CONFIG;

// Check trial status
$currentUser = $auth->getCurrentUser();
$trialStatus = checkTrialStatus($currentUser);

// Get WhatsApp number from settings
$waSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'developer_whatsapp'");
$developerWA = $waSetting['setting_value'] ?? null;

// Get company name from settings, fallback to BUSINESS_NAME
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value'])
    ? $companyNameSetting['setting_value']
    : BUSINESS_NAME;

$pageTitle = BUSINESS_ICON . ' ' . $displayCompanyName;
$pageSubtitle = 'Dashboard & Monitoring Real-time';

// ============================================
// BUSINESS FEATURE DETECTION (CONFIG-BASED)
// Uses enabled_modules and business_type from config
// ============================================
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isHotel = ($businessConfig['business_type'] ?? '') === 'hotel';
$isCQC = $hasProjectModule; // Legacy compatibility

// Dynamic color palette based on business config
// Primary glow/tint color (replaces purple rgba(99,102,241,...))
$cPrimaryRgb = $isContractor ? '240, 180, 41' : '99, 102, 241';
// Secondary tint (replaces secondary purple rgba(139,92,246,...))
$cSecondaryRgb = $isContractor ? '13, 31, 60' : '139, 92, 246';
// Action button color (replaces blue #0071e3)
$cAccent = $isContractor ? '#0d1f3c' : '#0071e3';
$cAccentDark = $isContractor ? '#122a4e' : '#0055b8';
// Action button rgb (replaces blue rgba(0,113,227,...))
$cAccentRgb = $isContractor ? '13, 31, 60' : '0, 113, 227';
// Kas tersedia highlight color
$cKasColor = $isContractor ? '#f0b429' : '#0071e3';

// Get date range (today, this month, this year)
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');

// Get selected month from GET parameter for filtering all dashboard data
$selected_dashboard_month = isset($_GET['dashboard_month']) ? $_GET['dashboard_month'] : date('Y-m');
$selected_dashboard_year = date('Y', strtotime($selected_dashboard_month . '-01'));
$monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Initialize CQC account IDs at global scope (will be populated if CQC business)
$pettyCashAccountId = 0;
$bankAccountId = 0;

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
// First check if cash_account_id column exists in cash_book (may not exist on hosting)
$hasCashAccountIdCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
    $hasCashAccountIdCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasCashAccountIdCol = false;
}

// Also check if transaction_time column exists
$hasTransactionTimeCol = true;
try {
    $db->getConnection()->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTimeCol = false;
}

// Get owner capital account IDs to exclude from operational income
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $businessId = getMasterBusinessId();

    // Get owner_capital account IDs (for legacy support)
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get cash (Kas Operasional) account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $kasOperasionalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion clause - exclude ONLY explicit owner fund
// Cash payment income to Petty Cash IS real income (from customers)
// Only source_type = 'owner_fund' should be excluded from income stats
$excludeOwnerCapital = '';
$hasSourceTypeCol = false;
try {
    $colCheck = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'source_type'");
    $hasSourceTypeCol = $colCheck && $colCheck->rowCount() > 0;
} catch (\Throwable $e) {
    $hasSourceTypeCol = false;
}

if ($hasSourceTypeCol) {
    // Use source_type to exclude owner fund AND project expenses (not hotel P&L)
    $excludeOwnerCapital = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
} elseif ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    // Fallback: only exclude owner_capital accounts (not petty cash)
    $excludeOwnerCapital = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Colors for divisions - Sharp Neon Digital Palette
$divisionColors = [
    '#00D4FF',
    '#FF3CAC',
    '#00F5A0',
    '#FFD93D',
    '#FF6B6B',
    '#6C5CE7',
    '#00CEC9',
    '#FD79A8',
    '#81ECEC',
    '#A29BFE',
    '#55EFC4'
];

// ============================================
// TODAY STATISTICS (Exclude Owner Capital)
// ============================================
$todayIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND transaction_date = :date" . $excludeOwnerCapital,
    ['date' => $today]
);
$todayIncome = ['total' => $todayIncomeResult[0]['total'] ?? 0];

$todayExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND transaction_date = :date",
    ['date' => $today]
);
$todayExpense = ['total' => $todayExpenseResult[0]['total'] ?? 0];

// ============================================
// MONTHLY STATISTICS (Exclude Owner Capital)
// ============================================
$monthlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $excludeOwnerCapital,
    ['month' => $selected_dashboard_month]
);
$monthlyIncome = ['total' => $monthlyIncomeResult[0]['total'] ?? 0];

$monthlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = :month",
    ['month' => $selected_dashboard_month]
);
$monthlyExpense = ['total' => $monthlyExpenseResult[0]['total'] ?? 0];

// ============================================
// YEARLY STATISTICS (Exclude Owner Capital)
// ============================================
$yearlyIncomeResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'income' AND YEAR(transaction_date) = :year" . $excludeOwnerCapital,
    ['year' => $thisYear]
);
$yearlyIncome = ['total' => $yearlyIncomeResult[0]['total'] ?? 0];

$yearlyExpenseResult = $db->fetchAll(
    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
     WHERE transaction_type = 'expense' AND YEAR(transaction_date) = :year",
    ['year' => $thisYear]
);
$yearlyExpense = ['total' => $yearlyExpenseResult[0]['total'] ?? 0];

// ============================================
// CURRENT BALANCE (YEARLY)
// ============================================
$totalBalance = ($yearlyIncome['total'] ?? 0) - ($yearlyExpense['total'] ?? 0);

// ============================================
// ALL TIME CASH (REAL MONEY - Only Cash Payment Method)
// ============================================
$allTimeCashResult = $db->fetchOne(
    "SELECT SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as balance FROM cash_book WHERE payment_method = 'cash'" . $excludeOwnerCapital
);
$totalRealCash = $allTimeCashResult['balance'] ?? 0;

// ============================================
// KAS OPERASIONAL HARIAN (This Month) - From Master DB
// ============================================
$dashCashAvailable = 0;
$startKasHariIni = 0;
try {
    // Get owner capital account from master database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $businessId = getMasterBusinessId();

    // Get ALL owner_capital account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $capitalAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get ALL cash (Petty Cash) account IDs
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
    $stmt->execute([$businessId]);
    $pettyCashAccounts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $allAccIds = array_merge($capitalAccounts, $pettyCashAccounts);

    $capitalStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];

    $pettyCashStats = [
        'received' => 0,
        'used' => 0,
        'balance' => 0
    ];

    // Keep expense logic consistent with dashboard charts AND Buku Kas (cashbook/index.php):
    // - project-related expenses (owner_project) are not part of operational expense.
    // - internal cash transfers (Setor Tunai / cash_transfer) are NOT a real business
    //   expense (money just moves between own accounts), so exclude them too —
    //   otherwise this widget's "Expense" figure won't match Buku Kas totals.
    $expenseCaseExpr = "CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END";
    if ($hasSourceTypeCol) {
        $expenseCaseExpr = "CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type NOT IN ('owner_project', 'cash_transfer')) THEN amount ELSE 0 END";
    }

    // Query Modal Owner stats - only if cash_account_id column exists
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));

        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM($expenseCaseExpr) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM($expenseCaseExpr)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";

        $params = array_merge($capitalAccounts, [$selected_dashboard_month]);
        $result = $db->fetchOne($query, $params);

        $capitalStats['received'] = $result['received'] ?? 0;
        $capitalStats['used'] = $result['used'] ?? 0;
        $capitalStats['balance'] = $result['balance'] ?? 0;
    }

    // Query Petty Cash / Kas Operasional stats - based on cash_account_id, NOT payment_method
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));

        $query = "
            SELECT 
                SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as received,
                SUM($expenseCaseExpr) as used,
                (SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) - 
                 SUM($expenseCaseExpr)) as balance
            FROM cash_book 
            WHERE cash_account_id IN ($placeholders)
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
        ";

        $params = array_merge($pettyCashAccounts, [$selected_dashboard_month]);
        $result = $db->fetchOne($query, $params);

        $pettyCashStats['received'] = $result['received'] ?? 0;
        $pettyCashStats['used'] = $result['used'] ?? 0;
        $pettyCashStats['balance'] = $result['balance'] ?? 0;
    }

    // TOTAL KAS OPERASIONAL = Petty Cash + Modal Owner (physical cash available)
    $totalOperationalCash = $pettyCashStats['balance'] + $capitalStats['balance'];

    // TOTAL PENGELUARAN OPERASIONAL = Petty Cash expense + Modal Owner expense
    $totalOperationalExpense = $pettyCashStats['used'] + $capitalStats['used'];

    // TOTAL UANG MASUK = Petty Cash received + Modal Owner received
    $totalOperationalIncome = $pettyCashStats['received'] + $capitalStats['received'];

    // ============================================
    // START KAS = Saldo akhir bulan sebelumnya
    // (untuk bulan baru, reset dari sisa bulan lalu)
    // ============================================
    $today = date('Y-m-d');
    $firstDayOfMonth = $selected_dashboard_month . '-01';
    $startKasOwner = 0;
    $startKasPetty = 0;
    $ownerTransferThisMonth = 0;

    // Modal Owner: all transactions before THIS MONTH (end of last month)
    if ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
        $pStart = array_merge($capitalAccounts, [$firstDayOfMonth]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasOwner = $rStart['bal'] ?? 0;
    }

    // Petty Cash / Kas Operasional: all transactions before THIS MONTH
    if ($hasCashAccountIdCol && !empty($pettyCashAccounts)) {
        $placeholders = implode(',', array_fill(0, count($pettyCashAccounts), '?'));
        $qStart = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date < ?";
        $pStart = array_merge($pettyCashAccounts, [$firstDayOfMonth]);
        $rStart = $db->fetchOne($qStart, $pStart);
        $startKasPetty = $rStart['bal'] ?? 0;
    }

    $startKasHariIni = $startKasOwner + $startKasPetty;

    // Cash Available should follow cashbook formula for selected month:
    // saldo sebelum periode + net transaksi dalam periode.
    $dashCashAvailable = $startKasHariIni + $totalOperationalCash;
    if ($hasCashAccountIdCol && !empty($allAccIds)) {
        $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
        $periodEnd = date('Y-m-t', strtotime($firstDayOfMonth));
        $qPeriodBal = "SELECT
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as bal
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date BETWEEN ? AND ?";
        $pPeriodBal = array_merge($allAccIds, [$firstDayOfMonth, $periodEnd]);
        $rPeriodBal = $db->fetchOne($qPeriodBal, $pPeriodBal);
        $periodBal = (float)($rPeriodBal['bal'] ?? 0);
        $dashCashAvailable = $startKasHariIni + $periodBal;
    }

    // Owner Transfer THIS MONTH only (source_type = 'owner_fund')
    $ownerTransferThisMonth = 0;
    if ($hasSourceTypeCol) {
        $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
            FROM cash_book WHERE source_type = 'owner_fund'
            AND transaction_type = 'income'
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
        $rOwner = $db->fetchOne($qOwner, [$selected_dashboard_month]);
        $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    } elseif ($hasCashAccountIdCol && !empty($capitalAccounts)) {
        $placeholders = implode(',', array_fill(0, count($capitalAccounts), '?'));
        $qOwner = "SELECT COALESCE(SUM(amount), 0) as total
            FROM cash_book WHERE cash_account_id IN ($placeholders) 
            AND transaction_type = 'income'
            AND DATE_FORMAT(transaction_date, '%Y-%m') = ?";
        $pOwner = array_merge($capitalAccounts, [$selected_dashboard_month]);
        $rOwner = $db->fetchOne($qOwner, $pOwner);
        $ownerTransferThisMonth = $rOwner['total'] ?? 0;
    }

    // Today's transactions
    $todayIncome = 0;
    $todayExpense = 0;
    if ($hasCashAccountIdCol && !empty($allAccIds)) {
        $placeholders = implode(',', array_fill(0, count($allAccIds), '?'));
        $qToday = "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) as inc,
            COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) as exp
            FROM cash_book WHERE cash_account_id IN ($placeholders) AND transaction_date = ?";
        $pToday = array_merge($allAccIds, [$today]);
        $rToday = $db->fetchOne($qToday, $pToday);
        $todayIncome = $rToday['inc'] ?? 0;
        $todayExpense = $rToday['exp'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error fetching operational cash stats: " . $e->getMessage());
    $capitalStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $pettyCashStats = ['received' => 0, 'used' => 0, 'balance' => 0];
    $totalOperationalCash = 0;
    $totalOperationalExpense = 0;
    $totalOperationalIncome = 0;
    $startKasHariIni = 0;
    $dashCashAvailable = 0;
    $todayIncome = 0;
    $todayExpense = 0;
}

// ============================================
// GUEST CASH INCOME (cash payments from guests only, NOT owner transfers)
// payment_method = 'cash' AND source_type != 'owner_fund'
// ============================================
$guestCashIncome = 0;
try {
    if ($hasSourceTypeCol) {
        $cashIncomeResult = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'income' 
             AND payment_method = 'cash'
             AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$selected_dashboard_month]
        );
    } else {
        // Fallback: cash payments excluding owner_capital accounts
        $excludeAccountIds = $capitalAccounts ?? [];
        if (!empty($excludeAccountIds)) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeAccountIds), '?'));
            $cashIncomeResult = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'income' 
                 AND payment_method = 'cash'
                 AND (cash_account_id IS NULL OR cash_account_id NOT IN ($excludePlaceholders))
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                array_merge($excludeAccountIds, [$selected_dashboard_month])
            );
        } else {
            $cashIncomeResult = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'income' 
                 AND payment_method = 'cash'
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                [$selected_dashboard_month]
            );
        }
    }
    $guestCashIncome = $cashIncomeResult['total'] ?? 0;
} catch (Exception $e) {
    error_log("Error fetching cash income: " . $e->getMessage());
}

// ============================================
// TOP DIVISIONS (This Month)
// ============================================
// Exclude owner capital ONLY from income, not from expense
$divisionOwnerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $divisionOwnerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topDivisions = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE 0 END), 0) as income,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'expense' THEN cb.amount ELSE 0 END), 0) as expense,
        COALESCE(SUM(CASE WHEN cb.transaction_type = 'income' THEN cb.amount ELSE -cb.amount END), 0) as net
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionOwnerCapitalFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    ORDER BY net DESC
    LIMIT 5",
    ['month' => $selected_dashboard_month]
);

// ============================================
// RECENT TRANSACTIONS
// ============================================
$recentTransactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN users u ON cb.created_by = u.id
    ORDER BY cb.transaction_date DESC, cb.id DESC
    LIMIT 10"
);

// ============================================
// CHART DATA - Division Income (Pie Chart)
// ============================================
// Exclude owner fund from division income chart (not hotel profit)
$divisionIncomeFilter = '';
if ($hasSourceTypeCol) {
    // Exclude owner_fund and owner_project using source_type
    $divisionIncomeFilter = " AND (cb.source_type IS NULL OR cb.source_type NOT IN ('owner_fund','owner_project'))";
} elseif ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    // Fallback: exclude owner_capital accounts
    $divisionIncomeFilter = " AND (cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$divisionIncomeData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'income'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $divisionIncomeFilter . "
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CHART DATA - Expense per Division (for pie chart)
// ============================================
$expenseDivisionData = $db->fetchAll(
    "SELECT 
        d.division_name,
        d.division_code,
        COALESCE(SUM(cb.amount), 0) as total
    FROM divisions d
    LEFT JOIN cash_book cb ON d.id = cb.division_id 
        AND cb.transaction_type = 'expense'
        AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month
        AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')
    WHERE d.is_active = 1
    GROUP BY d.id, d.division_name, d.division_code
    HAVING total > 0
    ORDER BY total DESC",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CHART DATA - Daily Income vs Expense (Monthly View)
// ============================================
// Use dashboard_month filter for all dashboard data consistency
$selectedMonth = $selected_dashboard_month;

// Get first and last day of selected month
$firstDay = $selectedMonth . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));
$daysInMonth = date('t', strtotime($firstDay));

// Generate all dates in the month
$dates = [];
for ($i = 1; $i <= $daysInMonth; $i++) {
    $dates[] = $selectedMonth . '-' . sprintf('%02d', $i);
}

// Get actual transaction data for the month
// IMPORTANT: Exclude owner capital ONLY from income (not from expense!)
// ALL BUSINESSES: Exclude owner_fund (kas operasional top-up from owner = NOT real income)
$ownerFundFilter = " AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project'))";
$transData = $db->fetchAll(
    "SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'income'" . $excludeOwnerCapital . $ownerFundFilter . " THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN transaction_type = 'expense' AND (source_type IS NULL OR source_type != 'owner_project') THEN amount ELSE 0 END) as expense
    FROM cash_book
    WHERE DATE_FORMAT(transaction_date, '%Y-%m') = :month
    GROUP BY DATE(transaction_date)
    ORDER BY date ASC",
    ['month' => $selectedMonth]
);

// Map transaction data by date
$transMap = [];
foreach ($transData as $data) {
    $transMap[$data['date']] = $data;
}

// Fill all days in month (missing dates will have 0 values)
$dailyData = [];
foreach ($dates as $date) {
    $dailyData[] = [
        'date' => $date,
        'income' => isset($transMap[$date]) ? $transMap[$date]['income'] : 0,
        'expense' => isset($transMap[$date]) ? $transMap[$date]['expense'] : 0
    ];
}

// ============================================
// CHART DATA - Top Categories This Month
// ============================================
// Exclude owner capital ONLY from income, not from expense
$ownerCapitalFilter = '';
if ($hasCashAccountIdCol && !empty($ownerCapitalAccountIds)) {
    $ownerCapitalFilter = " AND (cb.transaction_type = 'expense' OR cb.cash_account_id IS NULL OR cb.cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

$topCategories = $db->fetchAll(
    "SELECT 
        c.category_name,
        d.division_name,
        SUM(cb.amount) as total,
        cb.transaction_type
    FROM cash_book cb
    JOIN categories c ON cb.category_id = c.id
    JOIN divisions d ON cb.division_id = d.id
    WHERE DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month" . $ownerCapitalFilter . "
    GROUP BY c.id, c.category_name, d.division_name, cb.transaction_type
    ORDER BY total DESC
    LIMIT 10",
    ['month' => $selected_dashboard_month]
);

// ============================================
// CQC PROJECT DATA (if CQC business)
// ============================================
$cqcProjects = [];
if ($isCQC) {
    try {
        require_once __DIR__ . '/modules/cqc-projects/db-helper.php';
        $cqcPdo = getCQCDatabaseConnection();

        // Get total budget and spent directly (same as dashboard-2028.php)
        $totalsStmt = $cqcPdo->query("SELECT COALESCE(SUM(spent_idr), 0) as total_spent, COALESCE(SUM(budget_idr), 0) as total_budget FROM cqc_projects WHERE status != 'completed'");
        $projTotals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        $totalCqcBudget = (float)($projTotals['total_budget'] ?? 0);
        $totalCqcSpent = (float)($projTotals['total_spent'] ?? 0);

        // Get project list
        $stmt = $cqcPdo->query("
            SELECT p.id, p.project_name, p.project_code, p.status, 
                   p.progress_percentage, p.budget_idr, p.spent_idr,
                   p.client_name, p.location, p.solar_capacity_kwp,
                   COALESCE(SUM(e.amount), 0) as actual_spent
            FROM cqc_projects p
            LEFT JOIN cqc_project_expenses e ON p.id = e.project_id
            GROUP BY p.id
            ORDER BY p.status ASC, p.progress_percentage DESC
        ");
        $cqcProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Update spent_idr with actual expense totals
        foreach ($cqcProjects as &$proj) {
            if ($proj['actual_spent'] > 0) {
                $proj['spent_idr'] = $proj['actual_spent'];
            }
        }
        unset($proj);

        // CQC: Do NOT override dailyData with budget!
        // Budget is just RAB (cost estimate), NOT income.
        // Income only comes from actual invoice payments in cash_book.
        // The dailyData from cash_book query above already has the correct data.

    } catch (Exception $e) {
        error_log('CQC project data error: ' . $e->getMessage());
    }

    // CQC: Fetch recent 10 transactions from cashbook for dashboard
    $masterDbName = DB_NAME;
    $recentCashbook = $db->fetchAll(
        "SELECT cb.*, 
                COALESCE(c.category_name, 'Umum') as category_name,
                COALESCE(d.division_name, '-') as division_name,
                COALESCE(u.full_name, 'System') as created_by_name
         FROM cash_book cb
         LEFT JOIN categories c ON cb.category_id = c.id
         LEFT JOIN divisions d ON cb.division_id = d.id
         LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
         ORDER BY cb.transaction_date DESC, cb.transaction_time DESC, cb.id DESC
         LIMIT 10"
    );

    // CQC: Calculate Petty Cash actual balance from cash_accounts table
    $cqcPettyCashBalance = 0;
    $cqcBankBalance = 0; // Bank (Kas Besar) balance
    $cqcPettyCashTransfers = 0; // How much was transferred to petty cash this month
    try {
        // Get actual Petty Cash balance from master DB cash_accounts
        $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $businessId = getMasterBusinessId();

        // Get Petty Cash account balance (account_type = 'cash')
        $stmtPetty = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPetty->execute([$businessId]);
        $pettyCashAccount = $stmtPetty->fetch(PDO::FETCH_ASSOC);
        $cqcPettyCashBalance = (float)($pettyCashAccount['balance'] ?? 0);

        // Get Bank account balance (account_type = 'bank') - Kas Besar
        $stmtBank = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBank->execute([$businessId]);
        $bankAccount = $stmtBank->fetch(PDO::FETCH_ASSOC);
        $cqcBankBalance = (float)($bankAccount['balance'] ?? 0);

        // Get transfers to petty cash this month (from cash_book source_type = owner_fund)
        $pettyCashMonth = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'income' 
             AND source_type = 'owner_fund'
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$selected_dashboard_month]
        );
        $cqcPettyCashTransfers = (float)($pettyCashMonth['total'] ?? 0);

        // Get Petty Cash account ID for expense summary
        $stmtPettyId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPettyId->execute([$businessId]);
        $pettyCashAccountId = (int)($stmtPettyId->fetchColumn() ?? 0);

        // Get Bank account ID for expense summary  
        $stmtBankId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBankId->execute([$businessId]);
        $bankAccountId = (int)($stmtBankId->fetchColumn() ?? 0);
    } catch (Exception $e) {
        error_log('CQC Petty Cash balance error: ' . $e->getMessage());
    }

    // Get expenses from Petty Cash this month
    $cqcExpenseFromPettyCash = 0;
    $cqcExpenseFromBank = 0;

    if (isset($pettyCashAccountId) && $pettyCashAccountId > 0) {
        $expPetty = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'expense' 
             AND cash_account_id = ?
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$pettyCashAccountId, $selected_dashboard_month]
        );
        $cqcExpenseFromPettyCash = (float)($expPetty['total'] ?? 0);
    }

    if (isset($bankAccountId) && $bankAccountId > 0) {
        $expBank = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'expense' 
             AND cash_account_id = ?
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$bankAccountId, $selected_dashboard_month]
        );
        $cqcExpenseFromBank = (float)($expBank['total'] ?? 0);
    }
}

include 'includes/header.php';
?>

<?php if ($isCQC): ?>
    <style>
        /* CQC Theme - Gold accent, navy text */
        :root,
        body,
        body[data-theme="light"],
        body[data-theme="dark"] {
            --primary-color: #f0b429 !important;
            --primary-dark: #d4960d !important;
            --primary-light: #f5c842 !important;
            --secondary-color: #0d1f3c !important;
            --accent-color: #f0b429 !important;
        }
    </style>
<?php endif; ?>

<?php
// Show trial notification if applicable
if ($trialStatus) {
    echo getTrialNotificationHtml($trialStatus, $developerWA);
}
?>

<!-- PREMIUM TRADING CHART - PALING ATAS -->
<div id="tradingChartCard" style="margin-bottom: 1.5rem; overflow: hidden; border-radius: 20px; background: var(--chart-card-bg); border: 1px solid var(--chart-card-border); box-shadow: var(--chart-card-shadow);">
    <!-- Header Row -->
    <div class="chart-head-wrap">
        <div class="chart-head-row">
            <div class="chart-title-wrap">
                <div class="chart-title-accent"></div>
                <div class="chart-title-block">
                    <div class="chart-kicker"><?php echo strtoupper(BUSINESS_NAME); ?></div>
                    <div class="chart-main-title">Dashboard Finansial Digital</div>
                    <div class="chart-sub-title">Arus pemasukan, pengeluaran, dan profit harian dalam satu tampilan</div>
                </div>
                <div id="liveIndicator" class="chart-live-pill">
                    <span class="chart-live-dot"></span>
                    <span class="chart-live-text">LIVE</span>
                </div>
            </div>
            <div class="chart-controls-wrap">
                <div id="dailyFilter" style="display: none; align-items: center;">
                    <input type="date" id="chartDateFilter" value="<?php echo date('Y-m-d'); ?>" class="chart-filter-input" onchange="updateChartDate(this.value)">
                </div>
                <div id="monthlyFilter" style="display: flex; align-items: center;">
                    <input type="month" name="chart_month" id="chartMonthFilter" value="<?php echo $selectedMonth; ?>" class="chart-filter-input" onchange="updateChartMonth(this.value)">
                </div>
                <div id="yearlyFilter" style="display: none; align-items: center;">
                    <select id="chartYearFilter" class="chart-filter-input" onchange="updateChartYear(this.value)">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="chart-view-toggle">
                    <button id="btnDaily" onclick="switchView('daily')" class="btn-view-toggle">Harian</button>
                    <button id="btnMonthly" onclick="switchView('monthly')" class="btn-view-toggle active">Bulanan</button>
                    <button id="btnYearly" onclick="switchView('yearly')" class="btn-view-toggle">Tahunan</button>
                    <button id="btnAllTime" onclick="switchView('alltime')" class="btn-view-toggle">All</button>
                </div>
            </div>
        </div>

        <!-- Summary Numbers Row -->
        <div class="chart-summary-grid">
            <?php
            $totalIncome = array_sum(array_column($dailyData, 'income'));
            $displayIncome = $isCQC ? ($totalIncome - ($cqcPettyCashTransfers ?? 0) - ($cqcExpenseFromBank ?? 0)) : $totalIncome;
            $totalExpense = array_sum(array_column($dailyData, 'expense'));
            if ($isCQC) {
                $netBalance = ($cqcPettyCashBalance ?? 0) + ($cqcBankBalance ?? 0);
            } else {
                $netBalance = $totalIncome - $totalExpense;
            }
            ?>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: #10b981;"></div>
                    <span class="chart-metric-name"><?php echo $isCQC ? 'Saldo Kas Besar' : 'Pemasukan'; ?></span>
                    <span class="chart-badge chart-badge-up">↑</span>
                </div>
                <div class="chart-metric-amount" id="summaryIncome"><?php echo formatCurrency($displayIncome); ?></div>
            </div>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: #f97316;"></div>
                    <span class="chart-metric-name">Pengeluaran</span>
                    <span class="chart-badge chart-badge-down">↓</span>
                </div>
                <div class="chart-metric-amount" id="summaryExpense"><?php echo formatCurrency($totalExpense); ?></div>
            </div>
            <div class="chart-metric-card">
                <div class="chart-metric-top">
                    <div class="chart-metric-dot" style="background: rgb(<?php echo $cPrimaryRgb; ?>);"></div>
                    <span class="chart-metric-name"><?php echo $isCQC ? 'Saldo Bersih' : 'Net Balance'; ?></span>
                    <span class="chart-badge <?php echo $netBalance >= 0 ? 'chart-badge-up' : 'chart-badge-down'; ?>"><?php echo $netBalance >= 0 ? '↑' : '↓'; ?></span>
                </div>
                <div class="chart-metric-amount" id="summaryNet" style="color: <?php echo $netBalance >= 0 ? '#10b981' : '#ef4444'; ?>;"><?php echo formatCurrency($netBalance); ?></div>
            </div>
        </div>
    </div>

    <!-- Chart Canvas Area with Pie Chart -->
    <div class="chart-main-container">
        <!-- Line Chart Section (Left Side) -->
        <div class="chart-canvas-wrap chart-canvas-left">
            <div class="chart-canvas-inner">
                <canvas id="tradingChart"></canvas>
            </div>
        </div>

        <!-- Pie Chart Section (Right Side) -->
        <div class="chart-pie-section">
            <div class="chart-pie-card">
                <div class="chart-pie-header">
                    <h3 style="margin: 0; font-size: 0.95rem; color: #1e293b; font-weight: 700;">Ringkasan Bulan Ini</h3>
                    <span style="font-size: 0.8rem; color: #64748b;">Perbandingan pemasukan & pengeluaran</span>
                </div>
                <div class="chart-pie-container">
                    <canvas id="summaryPieChart"></canvas>
                </div>
                <div class="chart-pie-legend">
                    <div class="chart-pie-legend-item">
                        <span class="chart-pie-legend-dot" style="background: #10b981;"></span>
                        <span class="chart-pie-legend-label">Pemasukan</span>
                        <span class="chart-pie-legend-value" id="pieIncomeValue">Rp 0</span>
                    </div>
                    <div class="chart-pie-legend-item">
                        <span class="chart-pie-legend-dot" style="background: #f97316;"></span>
                        <span class="chart-pie-legend-label">Pengeluaran</span>
                        <span class="chart-pie-legend-value" id="pieExpenseValue">Rp 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Bar -->
    <div class="chart-footer-bar">
        <span id="periodDisplay" class="chart-period-display">1 - <?php echo date('t', strtotime($firstDay)); ?> <?php echo date('M Y', strtotime($firstDay)); ?></span>
        <div class="chart-legend-wrap">
            <div class="chart-legend-item"><span class="chart-legend-dot" style="background: #10b981;"></span>Pemasukan</div>
            <div class="chart-legend-item"><span class="chart-legend-dot" style="background: #f97316;"></span>Pengeluaran</div>
            <div class="chart-legend-item"><span class="chart-legend-dot" style="background: rgb(<?php echo $cPrimaryRgb; ?>);"></span>Net Harian</div>
        </div>
    </div>
</div>

<style>
    @keyframes livePulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.4;
            transform: scale(1.3);
        }
    }

    @keyframes pulse {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.6;
            transform: scale(1.1);
        }
    }

    /* === CHART CARD - Digital Elegant === */
    :root {
        --chart-card-bg: linear-gradient(135deg, #ffffff 0%, #f7fbff 58%, #f2f8ff 100%);
        --chart-card-border: rgba(30, 41, 59, 0.08);
        --chart-card-shadow: 0 8px 28px rgba(15, 23, 42, 0.08), 0 1px 2px rgba(15, 23, 42, 0.05);
        --chart-wrap-bg: radial-gradient(circle at 12% 0%, rgba(56, 189, 248, 0.08), transparent 42%), #f8fbff;
        --chart-wrap-border: rgba(30, 41, 59, 0.10);
        --chart-tick-color: rgba(71, 85, 105, 0.68);
        --chart-grid-color: rgba(148, 163, 184, 0.26);
        --chart-metric-bg: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(241, 245, 249, 0.82));
        --chart-metric-border: rgba(148, 163, 184, 0.26);
    }

    body[data-theme="dark"] {
        --chart-card-bg: linear-gradient(135deg, #0f172a 0%, #111827 58%, #0b1220 100%);
        --chart-card-border: rgba(148, 163, 184, 0.2);
        --chart-card-shadow: 0 8px 30px rgba(0, 0, 0, 0.35), 0 1px 2px rgba(0, 0, 0, 0.25);
        --chart-wrap-bg: radial-gradient(circle at 12% 0%, rgba(56, 189, 248, 0.16), transparent 45%), rgba(15, 23, 42, 0.95);
        --chart-wrap-border: rgba(148, 163, 184, 0.2);
        --chart-tick-color: rgba(203, 213, 225, 0.72);
        --chart-grid-color: rgba(148, 163, 184, 0.20);
        --chart-metric-bg: linear-gradient(145deg, rgba(30, 41, 59, 0.78), rgba(15, 23, 42, 0.78));
        --chart-metric-border: rgba(148, 163, 184, 0.25);
    }

    #tradingChartCard {
        background: var(--chart-card-bg);
        transition: box-shadow 0.3s, transform 0.3s;
        position: relative;
        backdrop-filter: blur(6px);
    }

    #tradingChartCard:hover {
        box-shadow: 0 8px 40px rgba(0, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.1);
        transform: translateY(-1px);
    }

    #tradingChartCard::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at 100% 0%, rgba(56, 189, 248, 0.14), transparent 36%),
            radial-gradient(circle at 0% 100%, rgba(16, 185, 129, 0.08), transparent 38%);
        pointer-events: none;
    }

    .chart-head-wrap {
        padding: 1.1rem 1.25rem 0;
        position: relative;
        z-index: 2;
    }

    .chart-head-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .chart-title-wrap {
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }

    .chart-title-accent {
        width: 4px;
        height: 34px;
        border-radius: 999px;
        background: linear-gradient(180deg, #0ea5e9 0%, #14b8a6 100%);
        box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1);
    }

    .chart-kicker {
        font-size: 0.56rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        line-height: 1;
    }

    .chart-main-title {
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--text-primary);
        margin-top: 0.16rem;
        line-height: 1.15;
    }

    .chart-sub-title {
        font-size: 0.67rem;
        color: var(--text-muted);
        margin-top: 0.24rem;
        line-height: 1.3;
        max-width: 420px;
    }

    .chart-controls-wrap {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    /* Live indicator */
    .chart-live-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.18rem 0.55rem;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.18);
    }

    .chart-live-dot {
        width: 5px;
        height: 5px;
        background: #10b981;
        border-radius: 50%;
        animation: livePulse 2s infinite;
        box-shadow: 0 0 6px rgba(16, 185, 129, 0.4);
    }

    .chart-live-text {
        font-size: 0.57rem;
        font-weight: 700;
        color: #10b981;
        letter-spacing: 0.1em;
    }

    /* Filter inputs */
    .chart-filter-input {
        max-width: 125px;
        height: 30px;
        font-size: 0.66rem;
        font-weight: 700;
        border: 1px solid var(--chart-metric-border);
        border-radius: 9px;
        background: var(--chart-wrap-bg);
        color: var(--text-primary);
        padding: 0 0.55rem;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .chart-filter-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.14);
    }

    /* View toggle pill bar */
    .chart-view-toggle {
        display: flex;
        align-items: center;
        gap: 2px;
        background: var(--chart-wrap-bg);
        padding: 3px;
        border-radius: 10px;
        border: 1px solid var(--chart-metric-border);
    }

    .btn-view-toggle {
        padding: 0.3rem 0.56rem;
        border: none;
        background: transparent;
        color: var(--text-muted);
        border-radius: 7px;
        font-size: 0.63rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        white-space: nowrap;
    }

    .btn-view-toggle.active {
        background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
        color: #fff;
        box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
    }

    .btn-view-toggle:not(.active):hover {
        background: var(--chart-metric-bg);
        color: var(--text-primary);
    }

    /* Metric cards */
    .chart-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
        margin-bottom: 0.7rem;
    }

    .chart-metric-card {
        padding: 0.5rem 0.65rem;
        border-radius: 10px;
        background: var(--chart-metric-bg);
        border: 1px solid var(--chart-metric-border);
        transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
        position: relative;
        overflow: hidden;
    }

    .chart-metric-card::after {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, rgba(14, 165, 233, 0.9), rgba(20, 184, 166, 0.4));
        opacity: 0.75;
    }

    .chart-metric-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
        border-color: rgba(14, 165, 233, 0.28);
    }

    .chart-metric-top {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        margin-bottom: 0.35rem;
    }

    .chart-metric-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .chart-metric-name {
        font-size: 0.6rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        flex: 1;
    }

    .chart-metric-amount {
        font-size: 0.98rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1.25;
        font-variant-numeric: tabular-nums;
    }

    /* Chart badge */
    .chart-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.08rem 0.35rem;
        border-radius: 4px;
        font-size: 0.55rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .chart-badge-up {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .chart-badge-down {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    /* Canvas area - transparent gray */
    .chart-canvas-wrap {
        background: var(--chart-wrap-bg);
        margin: 0 0.7rem;
        border-radius: 12px;
        border: 1px solid var(--chart-wrap-border);
        position: relative;
        overflow: hidden;
    }

    .chart-canvas-wrap::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: linear-gradient(to right, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
        background-size: 42px 100%;
        opacity: 0.24;
        pointer-events: none;
    }

    .chart-canvas-inner {
        position: relative;
        height: 220px;
        padding: 0.5rem 0.82rem 0.3rem;
        z-index: 1;
    }

    /* Main container for chart and pie charts */
    .chart-main-container {
        display: flex;
        gap: 1rem;
        margin: 0 0.7rem;
        padding: 0;
    }

    /* Left side - line chart */
    .chart-canvas-left {
        flex: 0 0 56%;
        margin: 0;
    }

    /* Right side - pie chart */
    .chart-pie-section {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    /* Individual pie chart card */
    .chart-pie-card {
        background: var(--chart-wrap-bg);
        border-radius: 12px;
        border: 1px solid var(--chart-wrap-border);
        padding: 0.75rem;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .chart-pie-header {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        margin-bottom: 0.6rem;
        padding-bottom: 0.6rem;
        border-bottom: 1px solid var(--chart-wrap-border);
    }

    /* Pie chart canvas container */
    .chart-pie-container {
        flex: 1;
        position: relative;
        min-height: 130px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.6rem;
    }

    .chart-pie-container canvas {
        max-width: 115px;
        max-height: 115px;
    }

    /* Pie chart legend */
    .chart-pie-legend {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        padding-top: 0.55rem;
        border-top: 1px solid var(--chart-wrap-border);
    }

    .chart-pie-legend-item {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        font-size: 0.875rem;
    }

    .chart-pie-legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .chart-pie-legend-label {
        flex: 1;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .chart-pie-legend-value {
        color: var(--text-primary);
        font-weight: 700;
        font-size: 0.9rem;
        text-align: right;
    }

    /* Footer bar */
    .chart-footer-bar {
        padding: 0.75rem 1.1rem 0.95rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid var(--chart-wrap-border);
        background: rgba(148, 163, 184, 0.02);
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .chart-period-display {
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .chart-legend-wrap {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .chart-legend-item {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        padding: 0.32rem 0.58rem;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.1);
        border: 1px solid rgba(148, 163, 184, 0.18);
    }

    .chart-legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    @media (max-width: 860px) {
        .chart-head-wrap {
            padding: 1rem 1rem 0;
        }

        .chart-main-title {
            font-size: 0.9rem;
        }

        .chart-sub-title {
            display: none;
        }

        .chart-summary-grid {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }

        .chart-canvas-inner {
            height: 250px;
            padding: 0.45rem 0.5rem 0.2rem;
        }

        .chart-main-container {
            flex-direction: column;
            margin: 0 0.7rem;
        }

        .chart-canvas-left {
            flex: 0 0 auto;
        }

        .chart-pie-section {
            flex-direction: column;
        }

        .chart-pie-container {
            min-height: 150px;
        }

        .chart-pie-container canvas {
            max-width: 120px;
            max-height: 120px;
        }

        .chart-filter-input {
            max-width: 110px;
        }
    }

    /* Card hover effects for operational section */
    div[style*="grid-template-columns: repeat(4"]>div {
        cursor: pointer;
    }

    div[style*="grid-template-columns: repeat(4"]>div:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
        border-color: rgba(<?php echo $cAccentRgb; ?>, 0.15) !important;
    }

    div[style*="grid-template-columns: repeat(4"]>div:hover .card-top-bar {
        opacity: 1 !important;
    }

    /* Dashboard Month Selector */
    .dashboard-month-selector {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding: 1rem 1.25rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .dashboard-month-selector label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #374151;
        margin: 0;
    }

    .dashboard-month-selector select {
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: #1f2937;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .dashboard-month-selector select:hover {
        border-color: var(--primary-color);
        box-shadow: 0 2px 6px rgba(<?php echo $cAccentRgb; ?>, 0.1);
    }

    .dashboard-month-selector select:focus {
        outline: none;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(<?php echo $cAccentRgb; ?>, 0.1);
    }

    .dashboard-month-selector button {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(<?php echo $cAccentRgb; ?>, 0.25);
    }

    .dashboard-month-selector button:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(<?php echo $cAccentRgb; ?>, 0.35);
    }
</style>

<?php if (!$isCQC): ?>
    <!-- DASHBOARD MONTH SELECTOR -->
    <div class="dashboard-month-selector">
        <form method="GET" style="display: flex; align-items: center; gap: 0.75rem; margin: 0;">
            <label for="dashboardMonthSelect">Filter Period:</label>
            <select name="dashboard_month" id="dashboardMonthSelect" onchange="this.form.submit();">
                <?php
                $currentMonth = $selected_dashboard_month;
                for ($m = 1; $m <= 12; $m++) {
                    $monthVal = sprintf('%04d-%02d', $selected_dashboard_year, $m);
                    $selected = ($monthVal == $selected_dashboard_month) ? 'selected' : '';
                    echo "<option value=\"$monthVal\" $selected>" . $monthNames[$m - 1] . " " . $selected_dashboard_year . "</option>";
                }
                ?>
            </select>
            <select name="dashboard_year" id="dashboardYearSelect" onchange="updateDashboardYear(this.value);">
                <?php
                $currentYear = date('Y');
                for ($y = $currentYear; $y >= $currentYear - 5; $y--) {
                    $selected = ($y == $selected_dashboard_year) ? 'selected' : '';
                    echo "<option value=\"$y\" $selected>$y</option>";
                }
                ?>
            </select>
        </form>
        <span style="font-size: 0.75rem; color: #9ca3af; margin-left: 0.5rem;">
            Showing data for: <strong><?php echo $monthNames[(int)date('m', strtotime($selected_dashboard_month . '-01')) - 1] . ' ' . $selected_dashboard_year; ?></strong>
        </span>
    </div>

    <!-- DAILY CASH Widget -->
    <div class="card fade-in" style="margin-bottom: 0.75rem; background: #fff; border: 1px solid #e5e7eb;">
        <div style="padding: 0.6rem 0.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <h3 style="font-size: 0.75rem; color: #1e293b; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.4rem;">
                    Daily Cash
                    <span style="font-size: 0.62rem; color: #94a3b8; font-weight: 500;"><?php echo date('M Y', strtotime($selected_dashboard_month . '-01')); ?></span>
                </h3>
                <a href="modules/owner/owner-capital-monitor.php" style="padding: 0.32rem 0.65rem; background: #1e3a8a; color: #ffffff !important; border-radius: 6px; text-decoration: none; font-size: 0.65rem; font-weight: 600; transition: all 0.2s ease; border: 1px solid #1e40af;" onmouseover="this.style.background='#1e40af'" onmouseout="this.style.background='#1e3a8a'">
                    Detail
                </a>
            </div>

            <!-- Compact Kas Summary -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                <!-- Start Cash -->
                <div style="background: #f8fafc; padding: 0.6rem 0.7rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 0.6rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 0.2rem;">Start Cash (<?php echo date('M', strtotime($selected_dashboard_month . '-01')); ?>)</div>
                    <div style="font-size: 0.92rem; font-weight: 700; color: #1e293b; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($startKasHariIni); ?></div>
                </div>
                <!-- Cash Available -->
                <div style="background: #f8fafc; padding: 0.6rem 0.7rem; border-radius: 8px; border: 1px solid <?php echo $dashCashAvailable >= 0 ? '#e2e8f0' : '#fecaca'; ?>; border-left: 3px solid <?php echo $dashCashAvailable >= 0 ? '#1e3a8a' : '#dc2626'; ?>;">
                    <div style="font-size: 0.6rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 0.2rem;">Cash Available</div>
                    <div style="font-size: 0.92rem; font-weight: 700; color: <?php echo $dashCashAvailable >= 0 ? '#1e293b' : '#dc2626'; ?>; font-family: 'Monaco', 'Courier New', monospace;"><?php echo formatCurrency($dashCashAvailable); ?></div>
                </div>
            </div>

            <!-- Guest Cash Income -->
            <?php if ($guestCashIncome > 0): ?>
                <div style="margin-bottom: 0.5rem; padding: 0.55rem 0.7rem; background: #eff6ff; border-radius: 8px; border: 1px solid #dbeafe; display: flex; align-items: center; gap: 0.6rem;">
                    <div style="width: 30px; height: 30px; border-radius: 8px; background: #1e3a8a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                        </svg>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-size: 0.58rem; color: #1e40af; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;">Cash Income</div>
                        <div style="font-size: 0.92rem; font-weight: 700; color: #1e3a8a; font-family: 'Monaco', monospace; display: flex; align-items: center; gap: 0.3rem;">
                            <span>+</span><?php echo formatCurrency($guestCashIncome); ?>
                        </div>
                    </div>
                    <div style="font-size: 0.6rem; color: #1e3a8a; background: #fff; padding: 0.2rem 0.45rem; border-radius: 4px; font-weight: 600;">Cash</div>
                </div>
            <?php endif; ?>

            <!-- Detail: 2 compact cards (expense card intentionally hidden) -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem;">
                <!-- Owner Transfer This Month -->
                <div style="background: #fff; padding: 0.55rem 0.7rem; border-radius: 8px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 0.55rem;">
                    <div style="width: 28px; height: 28px; border-radius: 7px; background: #1e3a8a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                            <rect x="2" y="6" width="20" height="12" rx="2" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 0.58rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;">Owner Transfer</div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: #1e293b; font-family: 'Monaco', monospace;"><?php echo formatCurrency($ownerTransferThisMonth); ?></div>
                    </div>
                </div>
                <!-- Income (Owner + Guest Cash) -->
                <div style="background: #fff; padding: 0.55rem 0.7rem; border-radius: 8px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 0.55rem;">
                    <div style="width: 28px; height: 28px; border-radius: 7px; background: #1e3a8a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
                            <polyline points="7 13 12 8 17 13" />
                            <line x1="12" y1="8" x2="12" y2="20" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 0.58rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;">Owner + Guest</div>
                        <div style="font-size: 0.85rem; font-weight: 700; color: #1e293b; font-family: 'Monaco', monospace;"><?php echo formatCurrency($ownerTransferThisMonth + $guestCashIncome); ?></div>
                    </div>
                </div>
            </div>
            <div style="margin-top: 0.4rem; font-size: 0.6rem; color: #94a3b8;">
                * Angka di atas hanya kas fisik (Petty Cash + Modal Owner), tidak termasuk transaksi Rekening Bank. Total pengeluaran seluruh akun ada di Buku Kas.
            </div>

            <?php if ($dashCashAvailable < 0): ?>
                <div style="margin-top: 0.4rem; padding: 0.35rem 0.65rem; background: #fef2f2; border-left: 2px solid #dc2626; border-radius: 4px;">
                    <div style="font-size: 0.65rem; color: #dc2626; font-weight: 600;">⚠️ Negative cash!</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Charts & Data - 3 Pie Charts -->
<?php endif; // !$isCQC - end kas operasional + charts hide 
?>

<?php if ($isCQC): ?>
    <!-- CQC Transaction Summary Title -->
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
        <div style="width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, #f0b429, #d4960d); display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
            </svg>
        </div>
        <div>
            <div style="font-size: 1.1rem; font-weight: 800; color: #0d1f3c;">CQC Transaction Summary</div>
            <div style="font-size: 0.75rem; color: #6b7280;">Main Bank & Petty Cash Overview</div>
        </div>
    </div>

    <!-- CQC Main Bank Summary -->
    <div style="background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #3b82f6; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #2563eb); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">🏦</div>
            <div>
                <div style="font-size: 1rem; font-weight: 700; color: #0d1f3c;">Main Bank Account</div>
                <div style="font-size: 0.75rem; color: #6b7280;">Primary fund from invoice payments • Source for operations</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            <div style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); padding: 1rem; border-radius: 12px; border: 1px solid #a7f3d0;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #047857; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="7 13 12 8 17 13" />
                        <line x1="12" y1="8" x2="12" y2="20" />
                    </svg>
                    Invoice Income
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #065f46;"><?php echo formatCurrency($totalIncome ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #059669; margin-top: 0.25rem;">Total invoice payments this month</div>
            </div>
            <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 1rem; border-radius: 12px; border: 1px solid #fecaca;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="17 11 12 16 7 11" />
                        <line x1="12" y1="16" x2="12" y2="4" />
                    </svg>
                    Bank Expenses
                </div>
                <div id="expenseFromBank" style="font-size: 1.35rem; font-weight: 800; color: #991b1b;"><?php echo formatCurrency($cqcExpenseFromBank ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #dc2626; margin-top: 0.25rem; display: flex; align-items: center; gap: 0.25rem;">
                    <span style="background: #fef3c7; color: #d97706; padding: 0.1rem 0.35rem; border-radius: 4px; font-weight: 600;">+ <?php echo formatCurrency($cqcPettyCashTransfers ?? 0); ?></span>
                    <span>to Petty Cash</span>
                </div>
            </div>
            <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 1rem; border-radius: 12px; border: 1px solid #bfdbfe;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    Bank Balance
                </div>
                <div id="dashboardBankBalance" style="font-size: 1.35rem; font-weight: 800; color: <?php echo ($cqcBankBalance ?? 0) >= 0 ? '#1e40af' : '#dc2626'; ?>;"><?php echo formatCurrency($cqcBankBalance ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #3b82f6; margin-top: 0.25rem;">Current bank balance</div>
            </div>
        </div>

        <!-- Recent Bank Expenses + Transfers -->
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
            <div style="font-size: 0.9rem; font-weight: 700; color: #6b7280; margin-bottom: 0.75rem;">📋 Recent Bank Transactions</div>
            <?php
            // Get recent expenses from Bank + Transfers to Petty Cash
            $recentBankExpenses = [];
            if ($bankAccountId > 0 || $pettyCashAccountId > 0) {
                // Query expenses from bank + transfers to petty cash
                $recentBankExpenses = $db->fetchAll(
                    "(SELECT cb.description, cb.amount, cb.transaction_date, c.category_name as category, 'expense' as record_type
                 FROM cash_book cb
                 LEFT JOIN categories c ON cb.category_id = c.id
                 WHERE cb.transaction_type = 'expense' 
                 AND cb.cash_account_id = ?)
                UNION ALL
                (SELECT cb.description, cb.amount, cb.transaction_date, 'Transfer' as category, 'transfer' as record_type
                 FROM cash_book cb
                 WHERE cb.transaction_type = 'income' 
                 AND cb.cash_account_id = ?
                 AND cb.description LIKE '%Transfer%')
                ORDER BY transaction_date DESC
                LIMIT 5",
                    [$bankAccountId, $pettyCashAccountId]
                );
            }
            ?>
            <?php if (!empty($recentBankExpenses)): ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($recentBankExpenses as $exp):
                        $isTransfer = ($exp['record_type'] ?? '') === 'transfer';
                        $bgColor = $isTransfer ? '#eff6ff' : '#fef2f2';
                        $textColor = $isTransfer ? '#2563eb' : '#dc2626';
                        $icon = $isTransfer ? '➡️' : '-';
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: <?php echo $bgColor; ?>; border-radius: 8px;">
                            <div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #374151;">
                                    <?php if ($isTransfer): ?><span style="background: #dbeafe; padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.75rem; color: #1d4ed8; margin-right: 0.4rem;">TRANSFER</span><?php endif; ?>
                                    <?php echo htmlspecialchars(preg_replace('/\[.*?\]\s*/', '', $exp['description'])); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo date('d M', strtotime($exp['transaction_date'])); ?> • <?php echo htmlspecialchars($exp['category'] ?? 'Uncategorized'); ?></div>
                            </div>
                            <div style="font-size: 1rem; font-weight: 700; color: <?php echo $textColor; ?>;"><?php echo $icon; ?><?php echo formatCurrency($exp['amount']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 1rem; text-align: center; color: #9ca3af; font-size: 0.8rem;">No bank transactions yet</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CQC Petty Cash Summary -->
    <div style="background: #fff; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.25rem; margin-bottom: 1.5rem; border-left: 4px solid #f0b429; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #fbbf24, #f59e0b); display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">💰</div>
            <div>
                <div style="font-size: 1rem; font-weight: 700; color: #0d1f3c;">Petty Cash</div>
                <div style="font-size: 0.75rem; color: #6b7280;">Operational cash for office & projects • Separate wallet from invoice</div>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
            <div style="background: linear-gradient(135deg, #fffbeb, #fef3c7); padding: 1rem; border-radius: 12px; border: 1px solid #fde68a;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="7 13 12 8 17 13" />
                        <line x1="12" y1="8" x2="12" y2="20" />
                    </svg>
                    Transfer In
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #78350f;"><?php echo formatCurrency($cqcPettyCashTransfers ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #a16207; margin-top: 0.25rem;">From main bank to petty cash</div>
            </div>
            <div style="background: linear-gradient(135deg, #fef2f2, #fee2e2); padding: 1rem; border-radius: 12px; border: 1px solid #fecaca;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="17 11 12 16 7 11" />
                        <line x1="12" y1="16" x2="12" y2="4" />
                    </svg>
                    Petty Cash Spent
                </div>
                <div style="font-size: 1.35rem; font-weight: 800; color: #991b1b;"><?php echo formatCurrency(($cqcPettyCashTransfers ?? 0) - ($cqcPettyCashBalance ?? 0)); ?></div>
                <div style="font-size: 0.7rem; color: #dc2626; margin-top: 0.25rem;">Office & project expenses</div>
            </div>
            <div style="background: linear-gradient(135deg, #eff6ff, #dbeafe); padding: 1rem; border-radius: 12px; border: 1px solid #bfdbfe;">
                <div style="font-size: 0.7rem; font-weight: 700; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    Petty Cash Balance
                </div>
                <div id="dashboardPettyCashBalance" style="font-size: 1.35rem; font-weight: 800; color: <?php echo ($cqcPettyCashBalance ?? 0) >= 0 ? '#1e40af' : '#dc2626'; ?>;"><?php echo formatCurrency($cqcPettyCashBalance ?? 0); ?></div>
                <div style="font-size: 0.7rem; color: #3b82f6; margin-top: 0.25rem;">Current petty cash balance</div>
            </div>
        </div>

        <!-- Recent Petty Cash Expenses -->
        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
            <div style="font-size: 0.9rem; font-weight: 700; color: #6b7280; margin-bottom: 0.75rem;">📋 Recent Petty Cash Expenses</div>
            <?php
            // Get recent expenses from Petty Cash
            $recentPettyExpenses = [];
            if ($pettyCashAccountId > 0) {
                $recentPettyExpenses = $db->fetchAll(
                    "SELECT cb.description, cb.amount, cb.transaction_date, c.category_name as category
                 FROM cash_book cb
                 LEFT JOIN categories c ON cb.category_id = c.id
                 WHERE cb.transaction_type = 'expense' 
                 AND cb.cash_account_id = ?
                 ORDER BY cb.transaction_date DESC, cb.id DESC
                 LIMIT 5",
                    [$pettyCashAccountId]
                );
            }
            ?>
            <?php if (!empty($recentPettyExpenses)): ?>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($recentPettyExpenses as $exp): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: #fef2f2; border-radius: 8px;">
                            <div>
                                <div style="font-size: 0.95rem; font-weight: 600; color: #374151;"><?php echo htmlspecialchars($exp['description']); ?></div>
                                <div style="font-size: 0.8rem; color: #9ca3af;"><?php echo date('d M', strtotime($exp['transaction_date'])); ?> • <?php echo htmlspecialchars($exp['category'] ?? 'Uncategorized'); ?></div>
                            </div>
                            <div style="font-size: 1rem; font-weight: 700; color: #dc2626;">-<?php echo formatCurrency($exp['amount']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="padding: 1rem; text-align: center; color: #9ca3af; font-size: 0.8rem;">No petty cash expenses yet</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($isCQC): ?>
    <!-- ============================================ -->
    <!-- CQC PROJECT OVERVIEW - PIE CHARTS -->
    <!-- ============================================ -->
    <style>
        .cqc-project-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            padding: 1.25rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .cqc-project-card:hover {
            box-shadow: 0 8px 24px rgba(13, 31, 60, 0.12);
            transform: translateY(-2px);
        }

        .cqc-chart-container {
            position: relative;
            width: 160px;
            height: 160px;
            margin: 0 auto 1rem;
        }

        .cqc-center-pct {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .cqc-center-pct .pct-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0d1f3c;
        }

        .cqc-center-pct .pct-label {
            font-size: 0.65rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cqc-stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .cqc-stat-row:last-child {
            border-bottom: none;
        }

        .cqc-stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .cqc-stat-value {
            font-size: 0.85rem;
            font-weight: 700;
            font-family: 'Monaco', 'Courier New', monospace;
        }

        .cqc-status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .cqc-status-planning {
            background: #eef2ff;
            color: #4a6cf7;
        }

        .cqc-status-procurement {
            background: #fef3c7;
            color: #d97706;
        }

        .cqc-status-installation {
            background: #dbeafe;
            color: #2563eb;
        }

        .cqc-status-testing {
            background: #fce7f3;
            color: #db2777;
        }

        .cqc-status-completed {
            background: #d1fae5;
            color: #059669;
        }

        .cqc-status-on_hold {
            background: #f3f4f6;
            color: #6b7280;
        }
    </style>

    <?php if (!empty($cqcProjects)): ?>
        <!-- CQC Project Monitoring - Clean 2027 Style -->
        <div class="fade-in" style="margin: 12px 0; padding: 18px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;">
            <!-- Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; background: #f8fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="5" />
                            <path d="m12 1v2m0 18v2m4.22-18.36 1.42 1.42M4.93 19.07l1.41 1.42m12.73 0 1.41-1.42M4.93 4.93l1.42 1.42M1 12h2m18 0h2" />
                        </svg>
                    </div>
                    <div>
                        <div style="font-size: 9px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">CQC Enjiniring</div>
                        <div style="font-size: 14px; font-weight: 600; color: #1e293b; letter-spacing: -0.2px;">Pencapaian & Keuangan</div>
                    </div>
                </div>
                <a href="modules/cqc-projects/" style="padding: 6px 12px; background: #1e293b; color: white; border-radius: 6px; font-size: 11px; font-weight: 600; text-decoration: none; transition: all 0.15s;" onmouseover="this.style.background='#0f172a';" onmouseout="this.style.background='#1e293b';">
                    Kelola →
                </a>
            </div>

            <!-- Summary Stats - Clean -->
            <?php
            $totalBudget = array_sum(array_column($cqcProjects, 'budget_idr'));
            $totalSpent = array_sum(array_column($cqcProjects, 'spent_idr'));
            $totalRemaining = $totalBudget - $totalSpent;
            $avgProgress = count($cqcProjects) > 0 ? round(array_sum(array_column($cqcProjects, 'progress_percentage')) / count($cqcProjects)) : 0;
            $budgetUsedPct = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;
            ?>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px;">
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 20px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo count($cqcProjects); ?></div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Proyek</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 13px; font-weight: 700; color: #1e293b;">Rp <?php echo number_format($totalBudget / 1000000000, 2); ?>M</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Budget</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 13px; font-weight: 700; color: #ef4444;">Rp <?php echo number_format($totalSpent / 1000000, 0); ?>jt</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Terpakai <?php echo $budgetUsedPct; ?>%</div>
                </div>
                <div style="text-align: center; padding: 12px 8px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div style="font-size: 20px; font-weight: 700; color: #10b981; line-height: 1;"><?php echo $avgProgress; ?>%</div>
                    <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.3px;">Progress</div>
                </div>
            </div>

            <!-- Project Cards Grid - Clean -->
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 16px;">
                <?php foreach ($cqcProjects as $idx => $proj):
                    $budget = floatval($proj['budget_idr'] ?? 0);
                    $spent = floatval($proj['spent_idr'] ?? 0);
                    $remaining = $budget - $spent;
                    $progress = intval($proj['progress_percentage'] ?? 0);
                    $spentPct = $budget > 0 ? round(($spent / $budget) * 100, 1) : 0;
                    $statusLabels = ['planning' => 'Planning', 'procurement' => 'Procurement', 'installation' => 'Installation', 'testing' => 'Testing', 'completed' => 'Completed', 'on_hold' => 'On Hold'];
                    $statusLabel = $statusLabels[$proj['status']] ?? ucfirst($proj['status']);
                    $statusColors = ['planning' => ['#f1f5f9', '#475569'], 'procurement' => ['#fef3c7', '#92400e'], 'installation' => ['#d1fae5', '#065f46'], 'testing' => ['#e0f2fe', '#0369a1'], 'completed' => ['#dcfce7', '#166534'], 'on_hold' => ['#fee2e2', '#991b1b']];
                    $statusBg = $statusColors[$proj['status']][0] ?? '#f1f5f9';
                    $statusText = $statusColors[$proj['status']][1] ?? '#475569';
                    $kwp = floatval($proj['solar_capacity_kwp'] ?? 0);
                    $clientName = $proj['client_name'] ?? '';
                ?>
                    <div class="cqc-project-card" style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer; transition: all 0.15s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.borderColor='#cbd5e1';" onmouseout="this.style.boxShadow='0 1px 3px rgba(0,0,0,0.04)'; this.style.borderColor='#e2e8f0';">

                        <!-- Header -->
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 10px; color: #64748b; font-weight: 600; letter-spacing: 0.5px;"><?php echo htmlspecialchars($proj['project_code']); ?></div>
                                <div style="font-size: 13px; font-weight: 600; color: #1e293b; margin-top: 2px; line-height: 1.3;"><?php echo htmlspecialchars($proj['project_name']); ?></div>
                                <?php if ($clientName): ?>
                                    <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;"><?php echo htmlspecialchars($clientName); ?></div>
                                <?php endif; ?>
                            </div>
                            <span style="padding: 4px 8px; border-radius: 5px; font-size: 10px; font-weight: 600; background: <?php echo $statusBg; ?>; color: <?php echo $statusText; ?>; white-space: nowrap;"><?php echo $statusLabel; ?></span>
                        </div>

                        <!-- Main Content: Progress + Financial -->
                        <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 10px;">

                            <!-- Progress Circle - Simple -->
                            <div style="flex-shrink: 0; text-align: center;">
                                <div style="position: relative; width: 60px; height: 60px;">
                                    <canvas id="cqcPie<?php echo $idx; ?>"></canvas>
                                    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                                        <div style="font-size: 14px; font-weight: 700; color: #1e293b; line-height: 1;"><?php echo $progress; ?>%</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Financial - Clean -->
                            <div style="flex: 1; min-width: 0;">
                                <!-- Budget -->
                                <div style="margin-bottom: 6px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px;">
                                        <span style="font-size: 10px; color: #64748b; font-weight: 500;">Budget</span>
                                        <span style="font-size: 12px; font-weight: 600; color: #1e293b;"><?php echo number_format($budget / 1000000, 0); ?>jt</span>
                                    </div>
                                    <div style="height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                                        <div style="width: <?php echo min($spentPct, 100); ?>%; height: 100%; background: <?php echo $spentPct > 90 ? '#ef4444' : '#0ea5e9'; ?>; border-radius: 2px;"></div>
                                    </div>
                                </div>

                                <!-- Spent & Sisa -->
                                <div style="display: flex; gap: 12px; font-size: 11px;">
                                    <div>
                                        <span style="color: #94a3b8;">Terpakai:</span>
                                        <span style="color: #ef4444; font-weight: 600; margin-left: 2px;"><?php echo number_format($spent / 1000000, 1); ?>jt</span>
                                    </div>
                                    <div>
                                        <span style="color: #94a3b8;">Sisa:</span>
                                        <span style="color: <?php echo $remaining >= 0 ? '#10b981' : '#ef4444'; ?>; font-weight: 600; margin-left: 2px;"><?php echo number_format($remaining / 1000000, 1); ?>jt</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Row -->
                        <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                            <div style="display: flex; gap: 6px;">
                                <?php if ($kwp > 0): ?>
                                    <span style="padding: 3px 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 10px; font-weight: 500; color: #64748b;">
                                        ⚡ <?php echo number_format($kwp, 1); ?> kWp
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- View Button -->
                            <div style="display: flex; gap: 6px;">
                                <a href="modules/cqc-projects/detail.php?id=<?php echo $proj['id']; ?>"
                                    style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #fff; color: #475569; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #e2e8f0; transition: all 0.15s;"
                                    onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';"
                                    onmouseout="this.style.background='#fff'; this.style.borderColor='#e2e8f0';">
                                    View
                                </a>
                                <?php if ($proj['status'] !== 'completed' && $proj['status'] !== 'planning'): ?>
                                    <a href="modules/cqc-projects/dashboard.php?action=finish&id=<?php echo $proj['id']; ?>"
                                        style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #059669; color: #fff; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #059669; transition: all 0.15s;"
                                        onmouseover="this.style.background='#047857';"
                                        onmouseout="this.style.background='#059669';"
                                        onclick="return confirm('Selesaikan proyek <?php echo htmlspecialchars($proj['project_name']); ?>?')">
                                        ✓ Finish
                                    </a>
                                <?php elseif ($proj['status'] === 'completed'): ?>
                                    <a href="modules/cqc-projects/report.php?id=<?php echo $proj['id']; ?>"
                                        style="display: flex; align-items: center; gap: 4px; padding: 5px 12px; background: #2563eb; color: #fff; border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 600; border: 1px solid #2563eb; transition: all 0.15s;"
                                        onmouseover="this.style.background='#1d4ed8';"
                                        onmouseout="this.style.background='#2563eb';">
                                        📊 Report
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Overall Charts - Clean -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0;">
                    <h3 style="font-size: 12px; font-weight: 600; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 24px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83" />
                                <path d="M22 12A10 10 0 0 0 12 2v10z" />
                            </svg>
                        </span>
                        Distribusi Budget
                    </h3>
                    <div style="position: relative; height: 180px;">
                        <canvas id="cqcBudgetPie"></canvas>
                    </div>
                </div>
                <div style="background: #fff; border-radius: 10px; padding: 14px; border: 1px solid #e2e8f0;">
                    <h3 style="font-size: 12px; font-weight: 600; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 24px; height: 24px; background: #f8fafc; border-radius: 6px; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                <line x1="16" y1="2" x2="16" y2="6" />
                                <line x1="8" y1="2" x2="8" y2="6" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                        </span>
                        Budget vs Spent
                    </h3>
                    <div style="position: relative; height: 180px;">
                        <canvas id="cqcBudgetVsSpentChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Recent Transactions from Buku Kas -->
            <div style="background: #fff; border-radius: 10px; padding: 16px; border: 1px solid #e2e8f0; margin-top: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                    <h3 style="font-size: 13px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <span style="width: 28px; height: 28px; background: linear-gradient(135deg, #f0b429, #d4960d); border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px;">📋</span>
                        10 Transaksi Terakhir
                    </h3>
                    <a href="modules/cashbook/" style="font-size: 11px; color: #f0b429; text-decoration: none; font-weight: 600; padding: 4px 10px; border: 1px solid #f0b429; border-radius: 6px; transition: all 0.15s;"
                        onmouseover="this.style.background='#f0b429'; this.style.color='#fff';" onmouseout="this.style.background='transparent'; this.style.color='#f0b429';">
                        Lihat Semua →
                    </a>
                </div>
                <?php if (!empty($recentCashbook)): ?>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Tanggal</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Keterangan</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Kategori</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Tipe</th>
                                    <th style="padding: 8px 10px; text-align: right; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Jumlah</th>
                                    <th style="padding: 8px 10px; text-align: left; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px;">Oleh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentCashbook as $i => $tx):
                                    $isIncome = $tx['transaction_type'] === 'income';
                                    $rowBg = $i % 2 === 0 ? '#fff' : '#fafbfc';
                                    // Clean description: remove [CQC_PROJECT:X] and [OPERATIONAL_OFFICE] tags for display
                                    $cleanDesc = preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $tx['description'] ?? '');
                                    $cleanDesc = preg_replace('/\[OPERATIONAL_OFFICE\]\s*/', '', $cleanDesc);
                                    // Detect if project expense
                                    $isProject = preg_match('/\[CQC_PROJECT:(\d+)\]/', $tx['description'] ?? '', $projMatch);
                                    $sourceLabel = '';
                                    if ($isIncome && isset($tx['source_type'])) {
                                        if ($tx['source_type'] === 'owner_fund') $sourceLabel = 'Owner Fund';
                                        elseif ($tx['source_type'] === 'invoice_payment') $sourceLabel = 'Invoice';
                                    }
                                    if (!$isIncome && isset($tx['source_type']) && $tx['source_type'] === 'owner_project') $sourceLabel = 'Proyek';
                                    elseif (!$isIncome && $isProject) $sourceLabel = 'Proyek';
                                    elseif (!$isIncome && !$isProject) $sourceLabel = 'Office';
                                ?>
                                    <tr style="background: <?php echo $rowBg; ?>; border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background='<?php echo $rowBg; ?>'">
                                        <td style="padding: 9px 10px; white-space: nowrap;">
                                            <div style="font-weight: 500; color: #1e293b;"><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?></div>
                                            <?php if (!empty($tx['transaction_time'])): ?>
                                                <div style="font-size: 10px; color: #94a3b8;"><?php echo date('H:i', strtotime($tx['transaction_time'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 9px 10px; max-width: 250px;">
                                            <div style="color: #1e293b; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($cleanDesc ?: '-'); ?></div>
                                            <?php if ($sourceLabel): ?>
                                                <span style="font-size: 10px; padding: 1px 6px; border-radius: 3px; font-weight: 500;
                                <?php if ($sourceLabel === 'Owner Fund'): ?>background: #fef3c7; color: #92400e;
                                <?php elseif ($sourceLabel === 'Invoice'): ?>background: #d1fae5; color: #065f46;
                                <?php elseif ($sourceLabel === 'Proyek'): ?>background: #e0e7ff; color: #3730a3;
                                <?php else: ?>background: #f1f5f9; color: #475569;
                                <?php endif; ?>">
                                                    <?php echo $sourceLabel; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 9px 10px; color: #64748b;"><?php echo htmlspecialchars($tx['category_name']); ?></td>
                                        <td style="padding: 9px 10px;">
                                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;
                                <?php echo $isIncome ? 'background: #ecfdf5; color: #059669;' : 'background: #fef2f2; color: #dc2626;'; ?>">
                                                <?php echo $isIncome ? '↓ Masuk' : '↑ Keluar'; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 9px 10px; text-align: right; font-weight: 600; font-family: 'JetBrains Mono', monospace; <?php echo $isIncome ? 'color: #059669;' : 'color: #dc2626;'; ?>">
                                            <?php echo ($isIncome ? '+' : '-') . ' Rp ' . number_format($tx['amount'], 0, ',', '.'); ?>
                                        </td>
                                        <td style="padding: 9px 10px; color: #64748b; font-size: 11px;"><?php echo htmlspecialchars($tx['created_by_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 24px; color: #94a3b8;">
                        <div style="font-size: 28px; margin-bottom: 6px;">📭</div>
                        <div style="font-size: 12px;">Belum ada transaksi</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card fade-in" style="padding: 2rem; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 0.75rem;">☀️</div>
            <h3 style="font-size: 1rem; color: #0d1f3c; font-weight: 700; margin-bottom: 0.5rem;">Belum Ada Proyek</h3>
            <p style="color: #6b7280; font-size: 0.85rem; margin-bottom: 1rem;">Tambahkan proyek pertama Anda untuk melihat grafik pencapaian.</p>
            <a href="modules/cqc-projects/add.php" style="padding: 0.6rem 1.5rem; background: linear-gradient(135deg, #0d1f3c, #1a3a5c); color: #f0b429; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 0.85rem;">+ Tambah Proyek</a>
        </div>
    <?php endif; ?>
<?php else: // not CQC 
?>

    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1rem;">

        <!-- Pie Chart 1 - Income per Division -->
        <div class="card" style="overflow: hidden;">
            <div style="padding: 0.6rem 0.7rem; border-bottom: 1px solid var(--bg-tertiary); position: relative;">
                <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(180deg, #10b981, #34d399);"></div>
                <h3 style="font-size: 0.76rem; color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 0.35rem; letter-spacing: 0.01em;">
                    <i data-feather="pie-chart" style="width: 13px; height: 13px; color: var(--success);"></i>
                    Pemasukan per Divisi
                </h3>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.4rem;">
                    <input type="month" id="divisionIncomeMonth" value="<?php echo $selected_dashboard_month; ?>"
                        class="form-control" style="font-size: 0.68rem; height: 26px; padding: 0.2rem 0.45rem; flex: 1;"
                        onchange="updateDivisionIncomeChart(this.value)">
                </div>
            </div>
            <div style="position: relative; height: 205px; padding: 0.6rem;">
                <?php if (empty($divisionIncomeData)): ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                        <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0; font-size: 0.813rem;">Belum ada data</p>
                    </div>
                <?php else: ?>
                    <canvas id="divisionPieChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pie Chart 2 - Expense per Division (NEW) -->
        <div class="card" style="overflow: hidden;">
            <div style="padding: 0.6rem 0.7rem; border-bottom: 1px solid var(--bg-tertiary); position: relative;">
                <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(180deg, #f43f5e, #fb7185);"></div>
                <h3 style="font-size: 0.76rem; color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 0.35rem; letter-spacing: 0.01em;">
                    <i data-feather="pie-chart" style="width: 13px; height: 13px; color: var(--danger);"></i>
                    Pengeluaran per Divisi
                </h3>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.4rem;">
                    <input type="month" id="expenseCategoryMonth" value="<?php echo $selected_dashboard_month; ?>"
                        class="form-control" style="font-size: 0.68rem; height: 26px; padding: 0.2rem 0.45rem; flex: 1;"
                        onchange="updateExpenseCategoryChart(this.value)">
                </div>
            </div>
            <div style="position: relative; height: 205px; padding: 0.6rem;">
                <?php if (empty($expenseDivisionData)): ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                        <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0; font-size: 0.813rem;">Belum ada data</p>
                    </div>
                <?php else: ?>
                    <canvas id="expenseCategoryChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Daily Activity Summary -->
        <div class="card" style="overflow: hidden;">
            <div style="padding: 0.6rem 0.7rem; border-bottom: 1px solid var(--bg-tertiary); position: relative;">
                <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(180deg, var(--primary-color), var(--primary-dark));"></div>
                <h3 style="font-size: 0.76rem; color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 0.35rem; letter-spacing: 0.01em;">
                    <i data-feather="activity" style="width: 13px; height: 13px; color: var(--primary-color);"></i>
                    Ringkasan Aktivitas Bulan Ini
                </h3>
            </div>
            <div style="padding: 0.6rem 0.7rem; height: 205px; display: flex; flex-direction: column; justify-content: center;">
                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.6rem; background: var(--bg-tertiary); border-radius: 8px;">
                        <span style="font-size: 0.68rem; color: var(--text-muted);">Total Hari Transaksi</span>
                        <span style="font-size: 0.9rem; font-weight: 800; color: var(--primary-color);">
                            <?php echo count(array_filter($dailyData, function ($d) {
                                return $d['income'] > 0 || $d['expense'] > 0;
                            })); ?> hari
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.6rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border-radius: 8px;">
                        <span style="font-size: 0.68rem; color: var(--success);">Rata-rata Pemasukan/Hari</span>
                        <span style="font-size: 0.82rem; font-weight: 800; color: var(--success);">
                            <?php
                            $activeDays = count(array_filter($dailyData, function ($d) {
                                return $d['income'] > 0 || $d['expense'] > 0;
                            }));
                            echo formatCurrency($activeDays > 0 ? array_sum(array_column($dailyData, 'income')) / $activeDays : 0);
                            ?>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.6rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); border-radius: 8px;">
                        <span style="font-size: 0.68rem; color: var(--danger);">Rata-rata Pengeluaran/Hari</span>
                        <span style="font-size: 0.82rem; font-weight: 800; color: var(--danger);">
                            <?php
                            echo formatCurrency($activeDays > 0 ? array_sum(array_column($dailyData, 'expense')) / $activeDays : 0);
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; // else not CQC - end charts section 
?>

<?php if (!$isCQC): ?>
    <!-- Top Categories & Top Divisions - Compact -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">

        <!-- Top Categories Chart -->
        <div class="card">
            <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <i data-feather="trending-up" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    Top 10 Kategori Transaksi
                </h3>
            </div>
            <div style="position: relative; height: 240px; padding: 0.75rem 0.5rem;">
                <?php if (empty($topCategories)): ?>
                    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--text-muted);">
                        <i data-feather="inbox" style="width: 40px; height: 40px; margin-bottom: 0.5rem;"></i>
                        <p style="margin: 0; font-size: 0.813rem;">Belum ada data transaksi</p>
                    </div>
                <?php else: ?>
                    <canvas id="topCategoriesChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">

            <!-- Top 5 Divisions -->
            <div class="card">
                <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary);">
                    <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                        <i data-feather="award" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                        Top 5 Divisi
                    </h3>
                </div>

                <?php if (empty($topDivisions)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada data</p>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.5rem 0;">
                        <?php foreach ($topDivisions as $index => $division): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.75rem; background: var(--bg-tertiary); border-radius: var(--radius-md);">
                                <div style="flex: 1;">
                                    <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem; font-size: 0.813rem;">
                                        #<?php echo $index + 1; ?> <?php echo $division['division_name']; ?>
                                    </div>
                                    <div style="font-size: 0.688rem; color: var(--text-muted);">
                                        <span class="text-success">+<?php echo formatCurrency($division['income']); ?></span>
                                        <span style="margin: 0 0.25rem;">•</span>
                                        <span class="text-danger">-<?php echo formatCurrency($division['expense']); ?></span>
                                    </div>
                                </div>
                                <div style="font-weight: 800; font-size: 0.938rem; color: <?php echo $division['net'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                                    <?php echo formatCurrency($division['net']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; // !$isCQC top categories 
    ?>

    <?php if (!$isCQC): ?>
        <!-- Recent Transactions - Full Width -->
        <div class="card">
            <div style="padding: 0.65rem 0 0.4rem 0; border-bottom: 1px solid var(--bg-tertiary); margin-bottom: 0.5rem;">
                <h3 style="font-size: 0.875rem; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <i data-feather="clock" style="width: 16px; height: 16px; color: var(--primary-color);"></i>
                    Transaksi Terakhir
                </h3>
            </div>

            <?php if (empty($recentTransactions)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 1.25rem; font-size: 0.813rem;">Belum ada transaksi</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.4rem; padding: 0.5rem 0;">
                    <?php foreach ($recentTransactions as $trans): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.65rem; border-bottom: 1px solid var(--bg-tertiary);">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.1rem; font-size: 0.75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo $trans['division_name']; ?> - <?php echo $trans['category_name']; ?>
                                </div>
                                <div style="font-size: 0.688rem; color: var(--text-muted);">
                                    <?php echo formatDate($trans['transaction_date']); ?>
                                </div>
                            </div>
                            <div style="text-align: right; margin-left: 0.5rem;">
                                <div style="font-weight: 700; font-size: 0.875rem; color: <?php echo $trans['transaction_type'] === 'income' ? 'var(--success)' : 'var(--danger)'; ?>; white-space: nowrap;">
                                    <?php echo $trans['transaction_type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 0.65rem; text-align: center;">
                <a href="<?php echo BASE_URL; ?>/modules/cashbook/index.php" class="btn btn-secondary btn-sm">
                    Lihat Semua →
                </a>
            </div>
        </div>

    <?php endif; // !$isCQC recent transactions 
    ?>

    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // ============================================
        // CHART CONFIGURATION
        // ============================================
        Chart.defaults.font.family = "'Inter', sans-serif";

        // Dynamic chart colors based on theme
        function getChartTextColor() {
            const isLight = document.body.getAttribute('data-theme') === 'light';
            // Light theme: dark text, Dark theme: light text
            return isLight ? '#475569' : '#94a3b8';
        }

        function getLegendTextColor() {
            const isLight = document.body.getAttribute('data-theme') === 'light';
            // Light theme: dark text, Dark theme: light text
            return isLight ? '#1e293b' : '#e2e8f0';
        }

        Chart.defaults.color = getChartTextColor();

        // Update chart colors when theme changes
        function updateChartColors() {
            Chart.defaults.color = getChartTextColor();
            // Update all chart instances
            Chart.instances.forEach(chart => {
                if (chart.options.plugins && chart.options.plugins.legend) {
                    chart.options.plugins.legend.labels.color = getLegendTextColor();
                }
                if (chart.options.scales) {
                    if (chart.options.scales.x && chart.options.scales.x.ticks) {
                        chart.options.scales.x.ticks.color = getChartTextColor();
                    }
                    if (chart.options.scales.y && chart.options.scales.y.ticks) {
                        chart.options.scales.y.ticks.color = getChartTextColor();
                    }
                }
                chart.update();
            });
        }

        // Listen for theme changes
        const themeObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'data-theme') {
                    updateChartColors();
                }
            });
        });
        themeObserver.observe(document.body, {
            attributes: true
        });

        // ============================================
        // PIE CHART - Division Income
        // ============================================
        <?php if (!$isCQC && !empty($divisionIncomeData)): ?>
            const divisionPieCtx = document.getElementById('divisionPieChart').getContext('2d');
            let divisionPieChart = new Chart(divisionPieCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($divisionIncomeData as $index => $div): ?> <?php echo json_encode((string)$div['division_name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Pemasukan',
                        data: [
                            <?php foreach ($divisionIncomeData as $div): ?>
                                <?php echo $div['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            <?php foreach ($divisionIncomeData as $index => $div): ?> '<?php echo $divisionColors[$index % count($divisionColors)]; ?>',
                            <?php endforeach; ?>
                        ],
                        borderWidth: 1.5,
                        borderColor: 'rgba(255,255,255,0.2)',
                        hoverOffset: 8,
                        hoverBorderWidth: 2,
                        hoverBorderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '40%',
                    radius: '92%',
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 800,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 6,
                                font: {
                                    size: 8.5,
                                    weight: '600',
                                    family: "'Inter', sans-serif"
                                },
                                color: getLegendTextColor(),
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 6,
                                boxHeight: 6
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(13, 31, 60, 0.95)',
                            padding: 12,
                            titleFont: {
                                size: 11,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 11,
                                weight: '500'
                            },
                            titleColor: '#fff',
                            bodyColor: 'rgba(255, 255, 255, 0.9)',
                            borderColor: 'rgba(255,255,255,0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            boxPadding: 4,
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    let value = context.parsed || 0;
                                    let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = ((value / total) * 100).toFixed(1);
                                    return label + ': Rp ' + value.toLocaleString('id-ID') + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        // ============================================
        // PIE CHART 2 - Expense per Division (NEW)
        // ============================================
        <?php if (!$isCQC && !empty($expenseDivisionData)): ?>
            const expenseCategoryCtx = document.getElementById('expenseCategoryChart').getContext('2d');
            let expenseCategoryChart = new Chart(expenseCategoryCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($expenseDivisionData as $index => $div): ?> <?php echo json_encode((string)$div['division_name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Pengeluaran',
                        data: [
                            <?php foreach ($expenseDivisionData as $div): ?>
                                <?php echo $div['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            '#FF3D71',
                            '#FF9F43',
                            '#FECA57',
                            '#1DD1A1',
                            '#54A0FF',
                            '#5F27CD',
                            '#00D2D3',
                            '#FF6B81',
                            '#2ED573',
                            '#7158E2',
                            '#3AE374',
                            '#FF4757'
                        ],
                        borderWidth: 1.5,
                        borderColor: 'rgba(255,255,255,0.2)',
                        hoverOffset: 8,
                        hoverBorderWidth: 2,
                        hoverBorderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '40%',
                    radius: '92%',
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 800,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 6,
                                font: {
                                    size: 8.5,
                                    weight: '600'
                                },
                                color: getLegendTextColor(),
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 6,
                                boxHeight: 6
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(13, 31, 60, 0.95)',
                            padding: 12,
                            titleFont: {
                                size: 11,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 11,
                                weight: '500'
                            },
                            titleColor: '#fff',
                            bodyColor: 'rgba(255, 255, 255, 0.9)',
                            borderColor: 'rgba(255,255,255,0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            boxPadding: 4,
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    let value = context.parsed || 0;
                                    let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    let percentage = ((value / total) * 100).toFixed(1);
                                    return label + ': Rp ' + value.toLocaleString('id-ID') + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        <?php if (!$isCQC): ?>
            // Function to update division income chart
            function updateDivisionIncomeChart(month) {
                fetch(`api/division-income-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.divisions.length > 0) {
                            divisionPieChart.data.labels = data.divisions;
                            divisionPieChart.data.datasets[0].data = data.amounts;
                            divisionPieChart.update();
                        } else {
                            // Show empty state
                            divisionPieChart.data.labels = [];
                            divisionPieChart.data.datasets[0].data = [];
                            divisionPieChart.update();
                        }
                    })
                    .catch(error => console.error('Error updating division income chart:', error));
            }

            // Function to update expense category chart
            function updateExpenseCategoryChart(month) {
                fetch(`api/expense-category-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.categories.length > 0) {
                            expenseCategoryChart.data.labels = data.categories;
                            expenseCategoryChart.data.datasets[0].data = data.amounts;
                            expenseCategoryChart.update();
                        } else {
                            // Show empty state
                            expenseCategoryChart.data.labels = [];
                            expenseCategoryChart.data.datasets[0].data = [];
                            expenseCategoryChart.update();
                        }
                    })
                    .catch(error => console.error('Error updating expense category chart:', error));
            }
        <?php endif; // !$isCQC pie chart functions 
        ?>

        // ============================================
        // CLEAN FINANCIAL FLOW CHART
        // ============================================
        <?php if (!empty($dailyData)): ?>
            const tradingCtx = document.getElementById('tradingChart').getContext('2d');

            const buildNetSeries = (incomeSeries, expenseSeries) => incomeSeries.map((value, index) => value - (expenseSeries[index] || 0));

            // Soft gradients for a lighter, more elegant chart
            const incomeGradient = tradingCtx.createLinearGradient(0, 0, 0, 280);
            incomeGradient.addColorStop(0, 'rgba(16, 185, 129, 0.32)');
            incomeGradient.addColorStop(1, 'rgba(16, 185, 129, 0.12)');

            const expenseGradient = tradingCtx.createLinearGradient(0, 0, 0, 280);
            expenseGradient.addColorStop(0, 'rgba(249, 115, 22, 0.28)');
            expenseGradient.addColorStop(1, 'rgba(249, 115, 22, 0.10)');

            const netGradient = tradingCtx.createLinearGradient(0, 0, 0, 280);
            netGradient.addColorStop(0, 'rgba(<?php echo $cPrimaryRgb; ?>, 0.22)');
            netGradient.addColorStop(1, 'rgba(<?php echo $cPrimaryRgb; ?>, 0.03)');

            const dailyIncomeSeries = [
                <?php foreach ($dailyData as $data): ?>
                    <?php echo $data['income']; ?>,
                <?php endforeach; ?>
            ];
            const dailyExpenseSeries = [
                <?php foreach ($dailyData as $data): ?>
                    <?php echo $data['expense']; ?>,
                <?php endforeach; ?>
            ];
            const dailyNetSeries = buildNetSeries(dailyIncomeSeries, dailyExpenseSeries);

            let tradingChart = new Chart(tradingCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($dailyData as $data): ?>
                            <?php echo (int)date('d', strtotime($data['date'])); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                            label: 'Pemasukan',
                            data: dailyIncomeSeries,
                            backgroundColor: incomeGradient,
                            borderColor: '#10b981',
                            borderWidth: 1.2,
                            borderRadius: 10,
                            barPercentage: 0.58,
                            categoryPercentage: 0.64,
                            maxBarThickness: 18,
                            order: 2
                        },
                        {
                            label: 'Pengeluaran',
                            data: dailyExpenseSeries,
                            backgroundColor: expenseGradient,
                            borderColor: '#f97316',
                            borderWidth: 1.2,
                            borderRadius: 10,
                            barPercentage: 0.58,
                            categoryPercentage: 0.64,
                            maxBarThickness: 18,
                            order: 3
                        },
                        {
                            type: 'line',
                            label: 'Net Harian',
                            data: dailyNetSeries,
                            borderColor: 'rgb(<?php echo $cPrimaryRgb; ?>)',
                            backgroundColor: netGradient,
                            borderWidth: 2.5,
                            fill: false,
                            tension: 0.35,
                            pointRadius: 2.5,
                            pointHoverRadius: 6,
                            pointBackgroundColor: 'rgb(<?php echo $cPrimaryRgb; ?>)',
                            pointHoverBorderColor: 'rgba(255,255,255,0.9)',
                            pointHoverBorderWidth: 2,
                            pointBorderWidth: 2,
                            pointStyle: 'circle',
                            borderCapStyle: 'round',
                            order: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    animation: {
                        duration: 800,
                        easing: 'easeOutQuart'
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(15, 23, 42, 0.96)',
                            titleColor: 'rgba(255, 255, 255, 0.76)',
                            bodyColor: 'rgba(255, 255, 255, 0.96)',
                            borderColor: 'rgba(148, 163, 184, 0.16)',
                            borderWidth: 1,
                            padding: {
                                top: 8,
                                bottom: 8,
                                left: 12,
                                right: 12
                            },
                            titleFont: {
                                size: 10,
                                weight: '500',
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                size: 11,
                                weight: '600',
                                family: "'Inter', sans-serif"
                            },
                            cornerRadius: 10,
                            displayColors: true,
                            boxWidth: 7,
                            boxHeight: 7,
                            boxPadding: 5,
                            usePointStyle: true,
                            callbacks: {
                                title: function(context) {
                                    return 'Tgl ' + context[0].label;
                                },
                                label: function(context) {
                                    let value = context.parsed.y || 0;
                                    return ' ' + context.dataset.label + ': Rp ' + value.toLocaleString('id-ID');
                                },
                                footer: function(tooltipItems) {
                                    let income = 0,
                                        expense = 0;
                                    tooltipItems.forEach(item => {
                                        if (item.dataset.label === 'Pemasukan') income = item.parsed.y;
                                        if (item.dataset.label === 'Pengeluaran') expense = item.parsed.y;
                                    });
                                    let net = income - expense;
                                    let sign = net >= 0 ? '+' : '';
                                    return 'Net harian: ' + sign + 'Rp ' + net.toLocaleString('id-ID');
                                }
                            },
                            footerFont: {
                                size: 10,
                                weight: '600'
                            },
                            footerColor: 'rgba(255, 255, 255, 0.5)'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--chart-grid-color').trim() || 'rgba(148,163,184,0.07)',
                                drawBorder: false,
                                lineWidth: 1,
                                borderDash: [3, 6]
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                padding: 8,
                                font: {
                                    size: 9.5,
                                    weight: '500',
                                    family: "'Inter', sans-serif"
                                },
                                color: getComputedStyle(document.documentElement).getPropertyValue('--chart-tick-color').trim() || 'rgba(148,163,184,0.45)',
                                maxTicksLimit: 5,
                                callback: function(value) {
                                    if (value >= 1000000) return 'Rp ' + (value / 1000000).toFixed(1) + ' jt';
                                    if (value >= 1000) return 'Rp ' + (value / 1000).toFixed(0) + ' rb';
                                    return 'Rp ' + value;
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            border: {
                                display: false
                            },
                            ticks: {
                                padding: 6,
                                font: {
                                    size: 10,
                                    weight: '500',
                                    family: "'Inter', sans-serif"
                                },
                                color: getComputedStyle(document.documentElement).getPropertyValue('--chart-tick-color').trim() || 'rgba(148,163,184,0.45)',
                                maxRotation: 0,
                                minRotation: 0,
                                maxTicksLimit: 15
                            }
                        }
                    }
                }
            });

            // ============================================
            // SUMMARY PIE CHART - Income vs Expense
            // ============================================
            const totalIncome = <?php echo array_sum(array_column($dailyData, 'income')); ?>;
            const totalExpense = <?php echo array_sum(array_column($dailyData, 'expense')); ?>;

            const summaryCtx = document.getElementById('summaryPieChart').getContext('2d');
            new Chart(summaryCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Pemasukan', 'Pengeluaran'],
                    datasets: [{
                        data: [totalIncome, totalExpense],
                        backgroundColor: ['#10b981', '#f97316'],
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--chart-wrap-bg').trim() || '#f8fafc',
                        borderWidth: 3,
                        borderRadius: 6,
                        hoverOffset: 8,
                        spacing: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.9)',
                            padding: 12,
                            titleFont: {
                                size: 12,
                                weight: '600',
                                family: "'Inter', sans-serif"
                            },
                            bodyFont: {
                                size: 11,
                                weight: '500',
                                family: "'Inter', sans-serif"
                            },
                            borderRadius: 8,
                            displayColors: true,
                            boxWidth: 8,
                            boxHeight: 8,
                            boxPadding: 8,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percent = ((value / total) * 100).toFixed(1);
                                    return 'Rp ' + value.toLocaleString('id-ID') + ' (' + percent + '%)';
                                }
                            }
                        }
                    }
                }
            });

            // Update legend values
            document.getElementById('pieIncomeValue').textContent = 'Rp ' + totalIncome.toLocaleString('id-ID');
            document.getElementById('pieExpenseValue').textContent = 'Rp ' + totalExpense.toLocaleString('id-ID');

            // ============================================
            // LIVE UPDATE - Auto refresh every 30 seconds
            // ============================================
            function updateLiveChart() {
                const selectedMonth = document.getElementById('chartMonthFilter').value;
                fetch(`api/live-chart-data.php?month=${selectedMonth}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart data
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update('none'); // Update without animation

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);

                            // Income from API already excludes owner_fund (petty cash transfers)
                            const isCQC = data.cqc !== null && data.cqc !== undefined;
                            let netBalance = totalIncome - totalExpense;

                            // CQC: displayIncome = invoice - petty cash transfers
                            let displayIncome = totalIncome;
                            if (isCQC) {
                                const pettyCashTransfers = data.cqc.petty_cash_transfers || 0;
                                const pettyCashBalance = data.cqc.petty_cash_balance || 0;
                                const bankBalance = data.cqc.bank_balance || 0;
                                const expenseFromPettyCash = data.cqc.expense_from_petty_cash || 0;
                                const expenseFromBank = data.cqc.expense_from_bank || 0;
                                displayIncome = totalIncome - pettyCashTransfers;
                                // CQC: Saldo Bersih = Petty Cash + Bank (actual cash position)
                                netBalance = pettyCashBalance + bankBalance;

                                // Update chart summary containers
                                const pettyCashEl = document.getElementById('totalPettyCash');
                                if (pettyCashEl) pettyCashEl.textContent = formatRupiah(pettyCashBalance);

                                const kasBesarEl = document.getElementById('totalKasBesar');
                                if (kasBesarEl) kasBesarEl.textContent = formatRupiah(bankBalance);

                                // Update widget containers
                                const dashPettyEl = document.getElementById('dashboardPettyCashBalance');
                                if (dashPettyEl) dashPettyEl.textContent = formatRupiah(pettyCashBalance);

                                const dashBankEl = document.getElementById('dashboardBankBalance');
                                if (dashBankEl) dashBankEl.textContent = formatRupiah(bankBalance);

                                const expBankEl = document.getElementById('expenseFromBank');
                                if (expBankEl) expBankEl.textContent = formatRupiah(expenseFromBank);
                            }

                            updateSummaryCards(displayIncome, totalExpense, netBalance);

                            console.log('Chart updated at:', data.timestamp);
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Update chart when month filter changes
            function updateChartMonth(month) {
                fetch(`api/live-chart-data.php?month=${month}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);

                            // Income from API already excludes owner_fund (petty cash transfers)
                            const isCQC = data.cqc !== null && data.cqc !== undefined;
                            let netBalance = totalIncome - totalExpense;

                            // CQC: displayIncome = invoice - petty cash transfers
                            let displayIncome = totalIncome;
                            if (isCQC) {
                                const pettyCashTransfers = data.cqc.petty_cash_transfers || 0;
                                const pettyCashBalance = data.cqc.petty_cash_balance || 0;
                                const bankBalance = data.cqc.bank_balance || 0;
                                const expenseFromPettyCash = data.cqc.expense_from_petty_cash || 0;
                                const expenseFromBank = data.cqc.expense_from_bank || 0;
                                displayIncome = totalIncome - pettyCashTransfers;
                                // CQC: Saldo Bersih = Petty Cash + Bank (actual cash position)
                                netBalance = pettyCashBalance + bankBalance;

                                // Update chart summary containers
                                const pettyCashEl = document.getElementById('totalPettyCash');
                                if (pettyCashEl) pettyCashEl.textContent = formatRupiah(pettyCashBalance);

                                const kasBesarEl = document.getElementById('totalKasBesar');
                                if (kasBesarEl) kasBesarEl.textContent = formatRupiah(bankBalance);

                                // Update widget containers
                                const dashPettyEl = document.getElementById('dashboardPettyCashBalance');
                                if (dashPettyEl) dashPettyEl.textContent = formatRupiah(pettyCashBalance);

                                const dashBankEl = document.getElementById('dashboardBankBalance');
                                if (dashBankEl) dashBankEl.textContent = formatRupiah(bankBalance);

                                const expBankEl = document.getElementById('expenseFromBank');
                                if (expBankEl) expBankEl.textContent = formatRupiah(expenseFromBank);
                            }

                            updateSummaryCards(displayIncome, totalExpense, netBalance);

                            // Update period display
                            const monthObj = new Date(month + '-01');
                            const monthStr = monthObj.toLocaleDateString('id-ID', {
                                month: 'long',
                                year: 'numeric'
                            });
                            const daysInMonth = new Date(monthObj.getFullYear(), monthObj.getMonth() + 1, 0).getDate();
                            document.getElementById('periodDisplay').textContent = '1 - ' + daysInMonth + ' ' + monthStr;

                            // Update URL without reload
                            const url = new URL(window.location);
                            url.searchParams.set('chart_month', month);
                            window.history.pushState({}, '', url);
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Helper function to format currency
            function formatRupiah(amount) {
                return 'Rp ' + amount.toLocaleString('id-ID');
            }

            // Helper function to update summary cards
            function updateSummaryCards(income, expense, net) {
                const incEl = document.getElementById('summaryIncome');
                const expEl = document.getElementById('summaryExpense');
                const netEl = document.getElementById('summaryNet');
                if (incEl) incEl.textContent = formatRupiah(income);
                if (expEl) expEl.textContent = formatRupiah(expense);
                if (netEl) {
                    netEl.textContent = formatRupiah(net);
                    netEl.style.color = net >= 0 ? '#10b981' : '#ef4444';
                }
            }

            // Auto refresh every 30 seconds
            setInterval(updateLiveChart, 30000);

            // ============================================
            // SWITCH VIEW - Daily, Monthly, Yearly, All-Time
            // ============================================
            let currentView = 'monthly';

            function switchView(view) {
                currentView = view;

                // Update button styles
                const btnDaily = document.getElementById('btnDaily');
                const btnMonthly = document.getElementById('btnMonthly');
                const btnYearly = document.getElementById('btnYearly');
                const btnAllTime = document.getElementById('btnAllTime');
                const dailyFilter = document.getElementById('dailyFilter');
                const monthlyFilter = document.getElementById('monthlyFilter');
                const yearlyFilter = document.getElementById('yearlyFilter');

                // Reset all buttons
                [btnDaily, btnMonthly, btnYearly, btnAllTime].forEach(btn => {
                    btn.classList.remove('active');
                    btn.style.background = '';
                    btn.style.color = '';
                });

                // Hide all filters
                dailyFilter.style.display = 'none';
                monthlyFilter.style.display = 'none';
                yearlyFilter.style.display = 'none';

                if (view === 'daily') {
                    btnDaily.classList.add('active');
                    dailyFilter.style.display = 'flex';

                    // Load daily data (hourly breakdown)
                    const selectedDate = document.getElementById('chartDateFilter').value;
                    updateChartDate(selectedDate);
                } else if (view === 'monthly') {
                    btnMonthly.classList.add('active');
                    monthlyFilter.style.display = 'flex';

                    // Load monthly data (daily breakdown)
                    const selectedMonth = document.getElementById('chartMonthFilter').value;
                    updateChartMonth(selectedMonth);
                } else if (view === 'yearly') {
                    btnYearly.classList.add('active');
                    yearlyFilter.style.display = 'flex';

                    // Load yearly data (monthly breakdown)
                    const selectedYear = document.getElementById('chartYearFilter').value;
                    updateChartYear(selectedYear);
                } else if (view === 'alltime') {
                    btnAllTime.classList.add('active');

                    // Load all-time data (yearly breakdown)
                    updateChartAllTime();
                }
            }

            function updateChartDate(date) {
                fetch(`api/daily-chart-data.php?date=${date}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            const dateObj = new Date(date);
                            const dateStr = dateObj.toLocaleDateString('id-ID', {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric'
                            });
                            document.getElementById('periodDisplay').textContent = dateStr + ' (24 jam)';
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            function updateChartAllTime() {
                fetch(`api/alltime-chart-data.php`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            if (data.labels.length > 0) {
                                const firstYear = data.labels[0];
                                const lastYear = data.labels[data.labels.length - 1];
                                document.getElementById('periodDisplay').textContent = firstYear + ' - ' + lastYear + ' (' + data.labels.length + ' tahun)';
                            } else {
                                document.getElementById('periodDisplay').textContent = 'Tidak ada data';
                            }
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            function updateChartYear(year) {
                fetch(`api/yearly-chart-data.php?year=${year}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Calculate daily net series
                            const netSeries = buildNetSeries(data.income, data.expense);

                            // Update chart with animation
                            tradingChart.data.labels = data.labels;
                            tradingChart.data.datasets[0].data = data.income;
                            tradingChart.data.datasets[1].data = data.expense;
                            tradingChart.data.datasets[2].data = netSeries;
                            tradingChart.update();

                            // Update summary cards
                            const totalIncome = data.income.reduce((a, b) => a + b, 0);
                            const totalExpense = data.expense.reduce((a, b) => a + b, 0);
                            const netBalance = totalIncome - totalExpense;

                            updateSummaryCards(totalIncome, totalExpense, netBalance);

                            // Update period display
                            document.getElementById('periodDisplay').textContent = 'Jan - Des ' + year + ' (12 bulan)';
                        }
                    })
                    .catch(error => console.error('Error updating chart:', error));
            }

            // Update dashboard month from year selector
            function updateDashboardYear(year) {
                const monthSelect = document.getElementById('dashboardMonthSelect');
                const currentMonth = monthSelect.value.split('-')[1];
                const newMonth = year + '-' + currentMonth;
                monthSelect.value = newMonth;
                monthSelect.form.submit();
            }
        <?php endif; ?>
        // ============================================
        // HORIZONTAL BAR CHART - Top Categories
        // ============================================
        // ============================================
        // CQC PROJECT PIE CHARTS - Modern Elegant 2026
        // ============================================
        <?php if ($isCQC && !empty($cqcProjects)): ?>
            const cqcColors = ['#10b981', '#f59e0b', '#3b82f6', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#f97316'];
            const cqcColorsLight = ['#34d399', '#fbbf24', '#60a5fa', '#a78bfa', '#f472b6', '#22d3ee', '#a3e635', '#fb923c'];

            // Individual project doughnut charts with gradient
            <?php
            // Clean 2027 style - simple colors
            foreach ($cqcProjects as $idx => $proj):
                $progress = intval($proj['progress_percentage'] ?? 0);
            ?>
                    (function() {
                        const ctx = document.getElementById('cqcPie<?php echo $idx; ?>');
                        if (!ctx) return;

                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Progress', 'Remaining'],
                                datasets: [{
                                    data: [<?php echo $progress; ?>, <?php echo 100 - $progress; ?>],
                                    backgroundColor: ['#0ea5e9', '#e2e8f0'],
                                    borderWidth: 0,
                                    borderRadius: 4,
                                    hoverBackgroundColor: ['#0284c7', '#cbd5e1'],
                                    hoverOffset: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '70%',
                                rotation: -90,
                                circumference: 360,
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        enabled: true,
                                        backgroundColor: '#1e293b',
                                        titleColor: '#fff',
                                        bodyColor: '#e2e8f0',
                                        cornerRadius: 6,
                                        padding: 10,
                                        displayColors: false,
                                        titleFont: {
                                            size: 11,
                                            weight: '600'
                                        },
                                        bodyFont: {
                                            size: 11
                                        },
                                        callbacks: {
                                            label: function(ctx) {
                                                return ctx.label + ': ' + ctx.parsed + '%';
                                            }
                                        }
                                    }
                                },
                                animation: {
                                    animateRotate: true,
                                    duration: 600,
                                    easing: 'easeOutQuart'
                                }
                            }
                        });
                    })();
            <?php endforeach; ?>

                // Budget Distribution Doughnut - Modern Style
                (function() {
                    const ctx = document.getElementById('cqcBudgetPie');
                    if (!ctx) return;
                    new Chart(ctx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: [<?php echo implode(',', array_map(function ($p) {
                                            return "'" . addslashes($p['project_name']) . "'";
                                        }, $cqcProjects)); ?>],
                            datasets: [{
                                data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                                backgroundColor: cqcColors.slice(0, <?php echo count($cqcProjects); ?>),
                                borderWidth: 3,
                                borderColor: '#fff',
                                hoverOffset: 18,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            cutout: '55%',
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 16,
                                        font: {
                                            size: 12,
                                            weight: '600'
                                        },
                                        usePointStyle: true,
                                        pointStyle: 'circle',
                                        boxWidth: 10,
                                        generateLabels: function(chart) {
                                            const data = chart.data;
                                            return data.labels.map((label, i) => ({
                                                text: label.length > 15 ? label.substring(0, 15) + '...' : label,
                                                fillStyle: data.datasets[0].backgroundColor[i],
                                                strokeStyle: '#fff',
                                                lineWidth: 0,
                                                hidden: false,
                                                index: i,
                                                pointStyle: 'circle'
                                            }));
                                        }
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                    titleColor: '#fbbf24',
                                    bodyColor: '#e5e7eb',
                                    cornerRadius: 12,
                                    padding: 16,
                                    titleFont: {
                                        size: 13,
                                        weight: '700'
                                    },
                                    bodyFont: {
                                        size: 12
                                    },
                                    callbacks: {
                                        label: function(ctx) {
                                            let total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                            let pct = ((ctx.parsed / total) * 100).toFixed(1);
                                            return ctx.label + ': Rp ' + ctx.parsed.toLocaleString('id-ID') + ' (' + pct + '%)';
                                        }
                                    }
                                }
                            },
                            animation: {
                                animateRotate: true,
                                duration: 1000,
                                easing: 'easeOutQuart'
                            }
                        }
                    });
                })();

            // Budget vs Spent Bar Chart - Modern Style
            (function() {
                const ctx = document.getElementById('cqcBudgetVsSpentChart');
                if (!ctx) return;
                new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function ($p) {
                                        return "'" . addslashes($p['project_code']) . "'";
                                    }, $cqcProjects)); ?>],
                        datasets: [{
                                label: 'Budget',
                                data: [<?php echo implode(',', array_column($cqcProjects, 'budget_idr')); ?>],
                                backgroundColor: 'rgba(16, 185, 129, 0.85)',
                                borderColor: '#10b981',
                                borderWidth: 0,
                                borderRadius: 8,
                                borderSkipped: false
                            },
                            {
                                label: 'Pengeluaran',
                                data: [<?php echo implode(',', array_column($cqcProjects, 'spent_idr')); ?>],
                                backgroundColor: 'rgba(239, 68, 68, 0.85)',
                                borderColor: '#ef4444',
                                borderWidth: 0,
                                borderRadius: 8,
                                borderSkipped: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    padding: 16,
                                    font: {
                                        size: 12,
                                        weight: '600'
                                    },
                                    usePointStyle: true,
                                    pointStyle: 'rect',
                                    boxWidth: 12
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                                titleColor: '#fbbf24',
                                bodyColor: '#e5e7eb',
                                cornerRadius: 12,
                                padding: 14,
                                titleFont: {
                                    size: 13,
                                    weight: '700'
                                },
                                bodyFont: {
                                    size: 12
                                },
                                callbacks: {
                                    label: function(ctx) {
                                        return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(148,163,184,0.08)'
                                },
                                ticks: {
                                    callback: function(v) {
                                        return v >= 1000000 ? 'Rp ' + (v / 1000000).toFixed(1) + 'jt' : 'Rp ' + (v / 1000).toFixed(0) + 'rb';
                                    },
                                    font: {
                                        size: 10
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 10,
                                        weight: '600'
                                    }
                                }
                            }
                        }
                    }
                });
            })();
        <?php endif; ?>

        <?php if (!empty($topCategories)): ?>
            const topCategoriesCtx = document.getElementById('topCategoriesChart').getContext('2d');
            new Chart(topCategoriesCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php foreach ($topCategories as $cat): ?> <?php echo json_encode((string)$cat['category_name'] . ' (' . (string)$cat['division_name'] . ')', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>,
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'Total Transaksi',
                        data: [
                            <?php foreach ($topCategories as $cat): ?>
                                <?php echo $cat['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            <?php foreach ($topCategories as $index => $cat): ?> '<?php echo $cat['transaction_type'] === 'income' ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)'; ?>',
                            <?php endforeach; ?>
                        ],
                        borderColor: [
                            <?php foreach ($topCategories as $index => $cat): ?> '<?php echo $cat['transaction_type'] === 'income' ? 'rgb(16, 185, 129)' : 'rgb(239, 68, 68)'; ?>',
                            <?php endforeach; ?>
                        ],
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            padding: 12,
                            titleFont: {
                                size: 14,
                                weight: '700'
                            },
                            bodyFont: {
                                size: 13
                            },
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    let value = context.parsed.x || 0;
                                    return 'Total: Rp ' + value.toLocaleString('id-ID');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + (value / 1000000).toFixed(1) + 'jt';
                                },
                                font: {
                                    size: 12,
                                    weight: '600'
                                },
                                color: getChartTextColor()
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12,
                                    weight: '600'
                                },
                                color: getLegendTextColor()
                            }
                        }
                    }
                }
            });
        <?php endif; ?>
    </script>

    <?php include 'includes/footer.php'; ?>