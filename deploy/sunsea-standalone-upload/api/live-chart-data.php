<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Live Chart Data API - Daily Transactions (30 Days)
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$db = Database::getInstance();

// ============================================
// EXCLUDE OWNER CAPITAL FROM OPERATIONAL STATS
// ============================================
$ownerCapitalAccountIds = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessId = getMasterBusinessId();
    
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerCapitalAccountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching owner capital accounts: " . $e->getMessage());
}

// Build exclusion condition for CASE statement
$ownerCapitalExcludeCondition = '';
if (!empty($ownerCapitalAccountIds)) {
    $ownerCapitalExcludeCondition = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalAccountIds) . "))";
}

// Get selected month from parameter, default to current month
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

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
// IMPORTANT: Exclude owner capital ONLY from income, NOT from expense!
// Also exclude owner_fund source_type (Bu Sita transfers) from income statistics
$transData = $db->fetchAll(
    "SELECT 
        DATE(transaction_date) as date,
        SUM(CASE WHEN transaction_type = 'income'{$ownerCapitalExcludeCondition} AND (source_type IS NULL OR source_type NOT IN ('owner_fund','owner_project')) THEN amount ELSE 0 END) as income,
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
    $transMap[$data['date']] = [
        'income' => (float)$data['income'],
        'expense' => (float)$data['expense']
    ];
}

// Format data for chart (fill all days in month)
$labels = [];
$incomeData = [];
$expenseData = [];

foreach ($dates as $date) {
    $day = (int)date('d', strtotime($date));
    $labels[] = $day; // Just show day number (1-31)
    $incomeData[] = isset($transMap[$date]) ? $transMap[$date]['income'] : 0;
    $expenseData[] = isset($transMap[$date]) ? $transMap[$date]['expense'] : 0;
}

// ============================================
// CQC: Include Petty Cash data for live updates
// ============================================
$cqcData = null;
$isCQC = false;
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bizId = getMasterBusinessId();
    
    // Detect CQC using config file (same method as dashboard)
    $configFile = __DIR__ . '/../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';
    if (file_exists($configFile)) {
        $businessConfig = require $configFile;
        $isCQC = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
    }
} catch (Exception $e) {}

if ($isCQC) {
    try {
        // Get Petty Cash actual balance
        $stmtPetty = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPetty->execute([$bizId]);
        $pettyCashAccount = $stmtPetty->fetch(PDO::FETCH_ASSOC);
        $pettyCashBalance = (float)($pettyCashAccount['balance'] ?? 0);
        
        // Get Bank (Kas Besar) balance
        $stmtBank = $masterDb->prepare("SELECT COALESCE(current_balance, 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBank->execute([$bizId]);
        $bankAccount = $stmtBank->fetch(PDO::FETCH_ASSOC);
        $bankBalance = (float)($bankAccount['balance'] ?? 0);
        
        // Get Petty Cash transfers this month
        $pettyCashMonth = $db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total 
             FROM cash_book 
             WHERE transaction_type = 'income' 
             AND source_type = 'owner_fund'
             AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
            [$selectedMonth]
        );
        $pettyCashTransfers = (float)($pettyCashMonth['total'] ?? 0);
        
        // Get Petty Cash account ID
        $stmtPettyId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'cash' LIMIT 1");
        $stmtPettyId->execute([$bizId]);
        $pettyCashAccountId = (int)($stmtPettyId->fetchColumn() ?? 0);
        
        // Get Bank account ID
        $stmtBankId = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'bank' LIMIT 1");
        $stmtBankId->execute([$bizId]);
        $bankAccountId = (int)($stmtBankId->fetchColumn() ?? 0);
        
        // Get expenses from Petty Cash this month
        $expenseFromPettyCash = 0;
        if ($pettyCashAccountId > 0) {
            $expPetty = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'expense' 
                 AND cash_account_id = ?
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                [$pettyCashAccountId, $selectedMonth]
            );
            $expenseFromPettyCash = (float)($expPetty['total'] ?? 0);
        }
        
        // Get expenses from Bank this month
        $expenseFromBank = 0;
        if ($bankAccountId > 0) {
            $expBank = $db->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total 
                 FROM cash_book 
                 WHERE transaction_type = 'expense' 
                 AND cash_account_id = ?
                 AND DATE_FORMAT(transaction_date, '%Y-%m') = ?",
                [$bankAccountId, $selectedMonth]
            );
            $expenseFromBank = (float)($expBank['total'] ?? 0);
        }
        
        $cqcData = [
            'petty_cash_balance' => $pettyCashBalance,
            'bank_balance' => $bankBalance,
            'petty_cash_transfers' => $pettyCashTransfers,
            'expense_from_petty_cash' => $expenseFromPettyCash,
            'expense_from_bank' => $expenseFromBank
        ];
    } catch (Exception $e) {
        error_log("CQC live data error: " . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'labels' => $labels,
    'income' => $incomeData,
    'expense' => $expenseData,
    'month' => $selectedMonth,
    'days_in_month' => $daysInMonth,
    'timestamp' => date('Y-m-d H:i:s'),
    'cqc' => $cqcData ?? null
]);
