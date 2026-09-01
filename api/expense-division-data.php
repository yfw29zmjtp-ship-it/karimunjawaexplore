<?php
/**
 * API: Expense per Division data for pie chart
 * Used by owner dashboard-2028.php - Multi-business aware
 */
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/business_helper.php';

header('Content-Type: application/json');

// Auth check
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$role = $_SESSION['role'] ?? null;
if (!$role || !in_array($role, ['admin', 'owner', 'manager', 'developer'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get business DB - from param or active session
$businessCode = isset($_GET['business']) ? $_GET['business'] : getActiveBusinessId();
$bizConfig = getAvailableBusinesses()[$businessCode] ?? getActiveBusinessConfig();
$businessDbName = getDbName($bizConfig['database'] ?? 'adf_narayana_hotel');

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=$businessDbName;charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        SELECT 
            d.division_name,
            d.division_code,
            COALESCE(SUM(cb.amount), 0) as total
        FROM divisions d
        LEFT JOIN cash_book cb ON d.id = cb.division_id 
            AND cb.transaction_type = 'expense'
            AND DATE_FORMAT(cb.transaction_date, '%Y-%m') = ?
            AND (cb.source_type IS NULL OR cb.source_type != 'owner_project')
        WHERE d.is_active = 1
        GROUP BY d.id, d.division_name, d.division_code
        HAVING total > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$month]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $divisions = [];
    $amounts = [];
    foreach ($data as $row) {
        $divisions[] = $row['division_name'];
        $amounts[] = (float)$row['total'];
    }

    echo json_encode([
        'success' => true,
        'divisions' => $divisions,
        'amounts' => $amounts,
        'month' => $month
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
