<?php
/**
 * API: Owner Statistics
 * Get today and monthly statistics
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$currentUser = $auth->getCurrentUser();

// Check if user is owner, admin, manager, or developer
if (!in_array($currentUser['role'], ['owner', 'admin', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Get selected business
$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));
    
    // Get businesses list with their database names
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($branchId === 'all' || $branchId === '') {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Initialize variables
    $todayIncome = 0;
    $todayExpense = 0;
    $todayIncomeCount = 0;
    $todayExpenseCount = 0;
    $monthIncome = 0;
    $monthExpense = 0;
    $lastMonthIncome = 0;
    $lastMonthExpense = 0;
    $operationalBalance = 0;
    $todayCapitalReceived = 0;
    $monthCapitalReceived = 0;
    $totalPettyCash = 0;
    $totalModalOwner = 0;
    
    // Loop through each business database and aggregate
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get owner capital account IDs from master DB to exclude from operational income
            $ownerCapitalIds = [];
            try {
                $stmt = $mainPdo->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                $stmt->execute([$business['id']]);
                $ownerCapitalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                // Continue without exclusion if master DB not available
            }
            
            // Build exclusion clause for owner capital accounts
            $excludeClause = "";
            $excludeParams = [$today];
            if (!empty($ownerCapitalIds)) {
                $placeholders = implode(',', array_fill(0, count($ownerCapitalIds), '?'));
                $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN ($placeholders))";
                $excludeParams = array_merge($excludeParams, $ownerCapitalIds);
            }
            
            // TODAY STATS (exclude Modal Owner)
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense,
                    COUNT(CASE WHEN transaction_type = 'income' THEN 1 END) as income_count,
                    COUNT(CASE WHEN transaction_type = 'expense' THEN 1 END) as expense_count
                 FROM cash_book 
                 WHERE transaction_date = ?" . $excludeClause
            );
            $stmt->execute($excludeParams);
            $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($todayStats) {
                $todayIncome += (float)$todayStats['income'];
                $todayExpense += (float)$todayStats['expense'];
                $todayIncomeCount += (int)$todayStats['income_count'];
                $todayExpenseCount += (int)$todayStats['expense_count'];
            }
            
            // THIS MONTH STATS (exclude Modal Owner)
            $excludeParamsMonth = [$thisMonth];
            if (!empty($ownerCapitalIds)) {
                $excludeParamsMonth = array_merge($excludeParamsMonth, $ownerCapitalIds);
            }
            
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book 
                 WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeClause
            );
            $stmt->execute($excludeParamsMonth);
            $monthStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($monthStats) {
                $monthIncome += (float)$monthStats['income'];
                $monthExpense += (float)$monthStats['expense'];
            }
            
            // LAST MONTH STATS (exclude Modal Owner)
            $excludeParamsLastMonth = [$lastMonth];
            if (!empty($ownerCapitalIds)) {
                $excludeParamsLastMonth = array_merge($excludeParamsLastMonth, $ownerCapitalIds);
            }
            
            $stmt = $bizPdo->prepare(
                "SELECT 
                    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
                 FROM cash_book 
                 WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?" . $excludeClause
            );
            $stmt->execute($excludeParamsLastMonth);
            $lastMonthStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastMonthStats) {
                $lastMonthIncome += (float)$lastMonthStats['income'];
                $lastMonthExpense += (float)$lastMonthStats['expense'];
            }
            
            // GET OPERATIONAL BALANCE (Petty Cash current balance)
            $businessPettyCashBalance = 0;
            $businessModalOwnerBalance = 0;
            try {
                $stmt = $mainPdo->prepare("SELECT COALESCE(SUM(current_balance), 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'cash'");
                $stmt->execute([$business['id']]);
                $balanceData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($balanceData) {
                    $businessPettyCashBalance = (float)$balanceData['balance'];
                    $operationalBalance += $businessPettyCashBalance;
                }
                
                // Get Modal Owner balance
                $stmt = $mainPdo->prepare("SELECT COALESCE(SUM(current_balance), 0) as balance FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
                $stmt->execute([$business['id']]);
                $modalData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($modalData) {
                    $businessModalOwnerBalance = (float)$modalData['balance'];
                }
                
                // Accumulate totals
                $totalPettyCash += $businessPettyCashBalance;
                $totalModalOwner += $businessModalOwnerBalance;
            } catch (Exception $e) {
                // Skip if master DB not available
            }
            
            // GET CAPITAL RECEIVED TODAY (from owner)
            try {
                $stmt = $mainPdo->prepare("
                    SELECT COALESCE(SUM(cat.amount), 0) as capital 
                    FROM cash_account_transactions cat
                    JOIN cash_accounts ca ON cat.cash_account_id = ca.id
                    WHERE ca.business_id = ? 
                    AND ca.account_type = 'owner_capital'
                    AND cat.transaction_type = 'income'
                    AND DATE(cat.transaction_date) = ?
                ");
                $stmt->execute([$business['id'], $today]);
                $capitalData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($capitalData) {
                    $todayCapitalReceived += (float)$capitalData['capital'];
                }
            } catch (Exception $e) {
                // Skip if master DB not available
            }
            
            // GET CAPITAL RECEIVED THIS MONTH (from owner)
            try {
                $stmt = $mainPdo->prepare("
                    SELECT COALESCE(SUM(cat.amount), 0) as capital 
                    FROM cash_account_transactions cat
                    JOIN cash_accounts ca ON cat.cash_account_id = ca.id
                    WHERE ca.business_id = ? 
                    AND ca.account_type = 'owner_capital'
                    AND cat.transaction_type = 'income'
                    AND DATE_FORMAT(cat.transaction_date, '%Y-%m') = ?
                ");
                $stmt->execute([$business['id'], $thisMonth]);
                $capitalData = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($capitalData) {
                    $monthCapitalReceived += (float)$capitalData['capital'];
                }
            } catch (Exception $e) {
                // Skip if master DB not available
            }
            
        } catch (Exception $e) {
            // Skip this business if database doesn't exist or table missing
            continue;
        }
    }
    
    // Calculate change percentages
    $incomeChange = 0;
    $expenseChange = 0;
    
    if ($lastMonthIncome > 0) {
        $incomeChange = (($monthIncome - $lastMonthIncome) / $lastMonthIncome) * 100;
    }
    
    if ($lastMonthExpense > 0) {
        $expenseChange = (($monthExpense - $lastMonthExpense) / $lastMonthExpense) * 100;
    }
    
    echo json_encode([
        'success' => true,
        'today' => [
            'income' => $todayIncome, // Real business income (exclude Modal Owner)
            'expense' => $todayExpense,
            'income_count' => $todayIncomeCount,
            'expense_count' => $todayExpenseCount,
            'net' => $todayIncome - $todayExpense,
            'capital_received' => $todayCapitalReceived // Cash from owner today
        ],
        'month' => [
            'income' => $monthIncome, // Real business income (exclude Modal Owner)
            'expense' => $monthExpense,
            'net' => $monthIncome - $monthExpense,
            'income_change' => round($incomeChange, 1),
            'expense_change' => round($expenseChange, 1),
            'capital_received' => $monthCapitalReceived // Cash from owner this month
        ],
        'last_month' => [
            'income' => $lastMonthIncome,
            'expense' => $lastMonthExpense
        ],
        'operational_balance' => $operationalBalance, // Daily operational cash (Petty Cash)
        'petty_cash' => [
            'balance' => $totalPettyCash
        ],
        'owner_capital' => [
            'balance' => $totalModalOwner
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
