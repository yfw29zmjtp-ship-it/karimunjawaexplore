<?php
/**
 * API: Owner Occupancy
 * Get room occupancy statistics
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

$branchId = isset($_GET['branch_id']) ? $_GET['branch_id'] : 'all';

try {
    $today = date('Y-m-d');
    
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
    
    // Aggregate room statistics from all hotel businesses
    $totalRooms = 0;
    $occupiedRooms = 0;
    $availableRooms = 0;
    $maintenanceRooms = 0;
    $todayCheckins = 0;
    $todayCheckouts = 0;
    
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get room statistics from rooms table
            try {
                $stmt = $bizPdo->query(
                    "SELECT 
                        COUNT(*) as total_rooms,
                        COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_rooms,
                        COUNT(CASE WHEN status = 'available' THEN 1 END) as available_rooms,
                        COUNT(CASE WHEN status IN ('maintenance', 'blocked') THEN 1 END) as maintenance_rooms
                     FROM rooms"
                );
                $roomStats = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($roomStats) {
                    $totalRooms += (int)$roomStats['total_rooms'];
                    $occupiedRooms += (int)$roomStats['occupied_rooms'];
                    $availableRooms += (int)$roomStats['available_rooms'];
                    $maintenanceRooms += (int)$roomStats['maintenance_rooms'];
                }
            } catch (Exception $e) {}
            
            // Get today's check-ins and check-outs from bookings table
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT 
                        COUNT(CASE WHEN check_in_date = ? THEN 1 END) as today_checkins,
                        COUNT(CASE WHEN check_out_date = ? THEN 1 END) as today_checkouts
                     FROM bookings
                     WHERE status IN ('checked_in', 'checked_out', 'confirmed')"
                );
                $stmt->execute([$today, $today]);
                $todayActivity = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($todayActivity) {
                    $todayCheckins += (int)$todayActivity['today_checkins'];
                    $todayCheckouts += (int)$todayActivity['today_checkouts'];
                }
            } catch (Exception $e) {}
            
        } catch (Exception $e) {
            continue;
        }
    }
    
    $occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
    
    echo json_encode([
        'success' => true,
        'total_rooms' => $totalRooms,
        'occupied_rooms' => $occupiedRooms,
        'available_rooms' => $availableRooms,
        'maintenance_rooms' => $maintenanceRooms,
        'occupancy_rate' => round($occupancyRate, 1),
        'today_checkins' => $todayCheckins,
        'today_checkouts' => $todayCheckouts,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
