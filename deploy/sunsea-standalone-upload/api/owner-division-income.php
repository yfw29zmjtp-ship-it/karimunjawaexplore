<?php
/**
 * API: Owner Division Income
 * Get income breakdown by division/module for pie chart
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
$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    $thisMonth = date('Y-m');
    
    // Get businesses list with their database names
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($branchId === 'all' || $branchId === '' || $branchId === 0) {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Division income mapping - aggregate from all selected businesses
    $frontdeskIncome = 0;
    $salesIncome = 0;
    $cashbookIncome = 0;
    
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get Frontdesk Income (from bookings table)
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT COALESCE(SUM(final_price), 0) as total FROM bookings 
                     WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND status != 'cancelled'"
                );
                $stmt->execute([$thisMonth]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $frontdeskIncome += (float)($result['total'] ?? 0);
            } catch (Exception $e) {}
            
            // Get Sales Income (from sales_invoices_header)
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT COALESCE(SUM(total_amount), 0) as total FROM sales_invoices_header 
                     WHERE DATE_FORMAT(invoice_date, '%Y-%m') = ?"
                );
                $stmt->execute([$thisMonth]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $salesIncome += (float)($result['total'] ?? 0);
            } catch (Exception $e) {}
            
            // Get cashbook income
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT COALESCE(SUM(amount), 0) as total FROM cash_book 
                     WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ? AND transaction_type = 'income'"
                );
                $stmt->execute([$thisMonth]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $cashbookIncome += (float)($result['total'] ?? 0);
            } catch (Exception $e) {}
            
        } catch (Exception $e) {
            // Skip this business if database doesn't exist
            continue;
        }
    }
    
    // Build divisions array
    $divisions = [];
    if ($frontdeskIncome > 0) {
        $divisions[] = ['name' => 'Frontdesk', 'amount' => $frontdeskIncome];
    }
    if ($salesIncome > 0) {
        $divisions[] = ['name' => 'Sales', 'amount' => $salesIncome];
    }
    $otherIncome = max(0, $cashbookIncome - $frontdeskIncome - $salesIncome);
    if ($otherIncome > 0) {
        $divisions[] = ['name' => 'Other', 'amount' => $otherIncome];
    }
    
    // If no data, return placeholder
    if (empty($divisions)) {
        $divisions = [
            ['name' => 'No Data', 'amount' => 1]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'divisions' => $divisions,
        'period' => $thisMonth
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'divisions' => []
    ]);
}
