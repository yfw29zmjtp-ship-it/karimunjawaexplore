<?php
header('Content-Type: application/json');
require_once '../includes/db.php';
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get project_id from GET parameter
$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Project ID is required']);
    exit;
}

try {
    $business_id = $_SESSION['business_id'];
    $db = getDbConnection($business_id);
    
    // Initialize division breakdown array
    $division_breakdown = [];
    $total_amount = 0;
    
    // 1. Get expenses from project_expenses table (if it has division_name column)
    try {
        // Check if division_name column exists in project_expenses
        $stmt = $db->query("DESCRIBE project_expenses");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('division_name', $columns)) {
            $stmt = $db->prepare("
                SELECT division_name, SUM(amount) as total 
                FROM project_expenses 
                WHERE project_id = ? 
                  AND division_name IS NOT NULL 
                  AND division_name != '' 
                GROUP BY division_name
            ");
            $stmt->execute([$project_id]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $division_name = $row['division_name'];
                $amount = floatval($row['total']);
                
                if (!isset($division_breakdown[$division_name])) {
                    $division_breakdown[$division_name] = 0;
                }
                $division_breakdown[$division_name] += $amount;
                $total_amount += $amount;
            }
        }
    } catch (Exception $e) {
        // Column doesn't exist, skip
    }
    
    // 2. Get expenses from project_division_expenses table
    try {
        $stmt = $db->prepare("
            SELECT division_name, SUM(amount) as total 
            FROM project_division_expenses 
            WHERE project_id = ? 
            GROUP BY division_name
        ");
        $stmt->execute([$project_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $division_name = $row['division_name'];
            $amount = floatval($row['total']);
            
            if (!isset($division_breakdown[$division_name])) {
                $division_breakdown[$division_name] = 0;
            }
            $division_breakdown[$division_name] += $amount;
            $total_amount += $amount;
        }
    } catch (Exception $e) {
        // Table doesn't exist or error, continue
    }
    
    // 3. Prepare response data
    $divisions = [];
    foreach ($division_breakdown as $name => $amount) {
        $divisions[] = [
            'division_name' => $name,
            'amount' => $amount,
            'formatted_amount' => 'Rp ' . number_format($amount, 0, ',', '.'),
            'percentage' => $total_amount > 0 ? round(($amount / $total_amount) * 100, 1) : 0
        ];
    }
    
    // Sort by amount descending
    usort($divisions, function($a, $b) {
        return $b['amount'] <=> $a['amount'];
    });
    
    echo json_encode([
        'success' => true,
        'data' => [
            'divisions' => $divisions,
            'total_amount' => $total_amount,
            'formatted_total' => 'Rp ' . number_format($total_amount, 0, ',', '.'),
            'count' => count($divisions)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching division breakdown: ' . $e->getMessage()
    ]);
}
