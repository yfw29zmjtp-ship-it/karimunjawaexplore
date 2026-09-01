<?php
/**
 * SIMPLIFIED API: GET MONTHLY BILLS
 * Standalone version that doesn't depend on complex business logic
 */

// Prevent output buffering issues
if (ob_get_level()) ob_end_clean();

// Set error handling before any output
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json; charset=utf-8');

try {
    // Start session with explicit configuration
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.name', 'NARAYANA_SESSION');
        session_start();
    }
    
    // Get active business - try multiple sources
    $activeBusiness = null;
    
    // Try 1: From session
    if (!empty($_SESSION['active_business_id'])) {
        $activeBusiness = $_SESSION['active_business_id'];
        $sessionSource = 'session';
    }
    // Try 2: From GET parameter (for debugging/fallback)
    else if (!empty($_GET['business'])) {
        $activeBusiness = $_GET['business'];
        $sessionSource = 'get_param';
    }
    // Try 3: From POST parameter
    else if (!empty($_POST['business'])) {
        $activeBusiness = $_POST['business'];
        $sessionSource = 'post_param';
    }
    // Try 4: Default to narayana-hotel (for development)
    else {
        $activeBusiness = 'narayana-hotel';
        $sessionSource = 'default';
    }
    
    if (!$activeBusiness) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No active business selected',
            'session_data' => $_SESSION,
            'source' => 'none'
        ]);
        exit;
    }
    
    // Determine if production or local
    $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
    
    // Determine database name based on business
    $businessDbMap = [
        'narayana-hotel' => 'adf_narayana_hotel',
        'bens-cafe' => 'adf_benscafe',
        'cqc' => 'adf_cqc',
        'demo' => 'adf_demo'
    ];
    
    $dbName = $businessDbMap[$activeBusiness] ?? 'adf_' . str_replace('-', '_', $activeBusiness);
    
    // Map to production if needed
    if ($isProduction) {
        $prodMap = [
            'adf_narayana_hotel' => 'adfb2574_narayana_hotel',
            'adf_benscafe' => 'adfb2574_Adf_Bens',
            'adf_cqc' => 'adfb2574_cqc',
            'adf_demo' => 'adfb2574_demo'
        ];
        $dbName = $prodMap[$dbName] ?? 'adfb2574_' . substr($dbName, 4);
    }
    
    // Setup credentials
    $dbHost = 'localhost';
    $dbUser = $isProduction ? 'adfb2574_adfsystem' : 'root';
    $dbPass = $isProduction ? '@Nnoc2025' : '';
    $dbCharset = 'utf8mb4';
    
    // Connect to database
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $dbCharset"
    ]);
    
    // Get parameters
    $month = $_GET['month'] ?? date('Y-m');
    $status = $_GET['status'] ?? null;
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    // Build query
    $where = ["DATE_FORMAT(bill_month, '%Y-%m') = ?"];
    $params = [$month];
    
    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Query bills
    $query = "
        SELECT 
            mb.id,
            mb.bill_code,
            mb.bill_name,
            mb.bill_month,
            mb.amount,
            mb.paid_amount,
            (mb.amount - mb.paid_amount) as remaining,
            mb.status,
            mb.due_date,
            mb.is_recurring,
            mb.notes,
            COALESCE(d.division_name, 'Unknown') as division_name,
            COALESCE(c.category_name, 'Unknown') as category_name,
            COUNT(DISTINCT bp.id) as payment_count,
            COALESCE(SUM(bp.amount), 0) as total_payments
        FROM monthly_bills mb
        LEFT JOIN divisions d ON mb.division_id = d.id
        LEFT JOIN categories c ON mb.category_id = c.id
        LEFT JOIN bill_payments bp ON mb.id = bp.bill_id
        WHERE $whereClause
        GROUP BY mb.id
        ORDER BY mb.bill_month DESC, mb.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bills = $stmt->fetchAll();
    
    // Get total count
    $countQuery = "
        SELECT COUNT(DISTINCT mb.id) as total
        FROM monthly_bills mb
        WHERE " . str_replace("DATE_FORMAT(bill_month, '%Y-%m') = ?", "DATE_FORMAT(mb.bill_month, '%Y-%m') = ?", $whereClause);
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $countResult = $countStmt->fetch();
    $total = $countResult['total'] ?? 0;
    
    echo json_encode([
        'success' => true,
        'bills' => $bills,
        'total' => $total,
        'month' => $month,
        'database' => $dbName,
        'business' => $activeBusiness
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}
?>
