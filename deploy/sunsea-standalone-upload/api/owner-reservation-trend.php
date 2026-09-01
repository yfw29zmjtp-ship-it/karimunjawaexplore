<?php
/**
 * API: Owner Reservation Trend
 * Get reservation count by day for the last 7 days
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
    
    // Get last 7 days reservation counts - aggregate from all businesses
    $labels = [];
    $values = [];
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayLabel = date('D', strtotime($date)); // Mon, Tue, etc.
        
        $labels[] = $dayLabel;
        $dayTotal = 0;
        
        foreach ($businesses as $business) {
            try {
                $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
                $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Try bookings table (hotel)
                try {
                    $stmt = $bizPdo->prepare(
                        "SELECT COUNT(*) as count FROM bookings WHERE DATE(created_at) = ?"
                    );
                    $stmt->execute([$date]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $dayTotal += (int)($result['count'] ?? 0);
                } catch (Exception $e) {}
                
                // Try sales_invoices_header (restaurant/retail)
                try {
                    $stmt = $bizPdo->prepare(
                        "SELECT COUNT(*) as count FROM sales_invoices_header WHERE DATE(invoice_date) = ?"
                    );
                    $stmt->execute([$date]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $dayTotal += (int)($result['count'] ?? 0);
                } catch (Exception $e) {}
                
            } catch (Exception $e) {
                continue;
            }
        }
        
        $values[] = $dayTotal;
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'values' => [0, 0, 0, 0, 0, 0, 0]
    ]);
}
