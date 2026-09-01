<?php
/**
 * API: Owner Guest Overview
 * Get inhouse guests and upcoming check-ins
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
    $today = date('Y-m-d');
    $weekEnd = date('Y-m-d', strtotime('+7 days'));
    
    // Get businesses list with their database names
    $mainPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName('adf_system') . ";charset=utf8mb4", DB_USER, DB_PASS);
    $mainPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($branchId === 'all' || $branchId === '' || $branchId === 0) {
        $stmt = $mainPdo->query("SELECT id, business_name, database_name, business_type FROM businesses WHERE is_active = 1 ORDER BY id");
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $mainPdo->prepare("SELECT id, business_name, database_name, business_type FROM businesses WHERE id = ? AND is_active = 1");
        $stmt->execute([$branchId]);
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get Inhouse Guests and Upcoming Check-ins - aggregate from hotel businesses
    $inhouseGuests = 0;
    $inhouseRooms = 0;
    $inhouseList = [];
    $upcomingList = [];
    
    foreach ($businesses as $business) {
        try {
            $bizPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . getDbName($business['database_name']) . ";charset=utf8mb4", DB_USER, DB_PASS);
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Get Inhouse Guests (only for hotel type businesses with bookings table)
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT COUNT(*) as guests, COUNT(DISTINCT room_id) as rooms 
                     FROM bookings 
                     WHERE status = 'checked_in'"
                );
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $inhouseGuests += (int)($result['guests'] ?? 0);
                $inhouseRooms += (int)($result['rooms'] ?? 0);
                
                // Get inhouse guest names with room info
                $stmt2 = $bizPdo->prepare(
                    "SELECT 
                        g.guest_name,
                        r.room_number,
                        b.check_in_date,
                        b.check_out_date,
                        DATEDIFF(b.check_out_date, b.check_in_date) as nights,
                        (COALESCE(b.adults, 1) + COALESCE(b.children, 0)) as total_guests
                     FROM bookings b
                     LEFT JOIN guests g ON b.guest_id = g.id
                     LEFT JOIN rooms r ON b.room_id = r.id
                     WHERE b.status = 'checked_in'
                     ORDER BY r.room_number ASC
                     LIMIT 10"
                );
                $stmt2->execute();
                $inhouse = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $inhouseList = array_merge($inhouseList, $inhouse);
            } catch (Exception $e) {
                // Log error for debugging
                error_log("Inhouse query error: " . $e->getMessage());
            }
            
            // Get Upcoming Check-ins
            try {
                $stmt = $bizPdo->prepare(
                    "SELECT 
                        g.guest_name,
                        b.check_in_date,
                        b.check_out_date,
                        DATEDIFF(b.check_out_date, b.check_in_date) as nights,
                        r.room_number,
                        ? as business_name
                     FROM bookings b
                     LEFT JOIN guests g ON b.guest_id = g.id
                     LEFT JOIN rooms r ON b.room_id = r.id
                     WHERE b.check_in_date BETWEEN ? AND ?
                       AND b.status IN ('confirmed', 'pending')
                     ORDER BY b.check_in_date ASC
                     LIMIT 10"
                );
                $stmt->execute([$business['business_name'], $today, $weekEnd]);
                $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $upcomingList = array_merge($upcomingList, $upcoming);
            } catch (Exception $e) {}
            
        } catch (Exception $e) {
            // Skip this business if database doesn't exist
            continue;
        }
    }
    
    // Sort upcoming by check_in_date and limit to 10
    usort($upcomingList, function($a, $b) {
        return strtotime($a['check_in_date']) - strtotime($b['check_in_date']);
    });
    $upcomingList = array_slice($upcomingList, 0, 10);
    
    // Format dates
    foreach ($upcomingList as &$item) {
        $item['check_in_date'] = date('d M', strtotime($item['check_in_date']));
    }
    $upcomingCount = count($upcomingList);
    
    // Format inhouse dates
    foreach ($inhouseList as &$item) {
        $item['check_out_formatted'] = date('d M', strtotime($item['check_out_date']));
    }

    echo json_encode([
        'success' => true,
        'inhouse' => [
            'guests' => (int)$inhouseGuests,
            'rooms' => (int)$inhouseRooms,
            'list' => $inhouseList
        ],
        'upcoming' => [
            'count' => (int)$upcomingCount,
            'list' => $upcomingList
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'inhouse' => ['guests' => 0, 'rooms' => 0, 'list' => []],
        'upcoming' => ['count' => 0, 'list' => []]
    ]);
}
