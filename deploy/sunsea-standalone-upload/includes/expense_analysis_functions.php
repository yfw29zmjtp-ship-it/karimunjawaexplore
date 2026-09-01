<?php
/**
 * Expense Analysis Functions
 * Query expenses breakdown by division using purchases_detail and purchases_header
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Get total expenses breakdown by Division
 * Joins purchases_detail and purchases_header
 * Groups by division_id and sums subtotal
 * 
 * @param array $filters Optional filters: date_from, date_to, division_id, payment_status
 * @return array Expense breakdown by division
 */
function getExpensesByDivision($filters = []) {
    $db = Database::getInstance();
    
    // Build WHERE conditions
    $where_conditions = ["ph.id IS NOT NULL"];
    $params = [];
    
    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    
    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }
    
    if (isset($filters['division_id']) && !empty($filters['division_id'])) {
        $where_conditions[] = "d.id = :division_id";
        $params['division_id'] = $filters['division_id'];
    }
    
    if (isset($filters['payment_status']) && !empty($filters['payment_status'])) {
        $where_conditions[] = "ph.payment_status = :payment_status";
        $params['payment_status'] = $filters['payment_status'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Main query: Join purchases_detail with purchases_header
    // Group by division_id and sum subtotal
    $query = "
        SELECT 
            d.id as division_id,
            d.division_code,
            d.division_name,
            COUNT(DISTINCT ph.id) as total_invoices,
            COUNT(pd.id) as total_items,
            SUM(pd.quantity) as total_quantity_purchased,
            SUM(pd.subtotal) as total_expenses,
            AVG(pd.subtotal) as avg_item_cost,
            MIN(ph.invoice_date) as first_purchase_date,
            MAX(ph.invoice_date) as last_purchase_date
        FROM divisions d
        LEFT JOIN purchases_detail pd ON d.id = pd.division_id
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        WHERE {$where_clause}
        GROUP BY d.id, d.division_code, d.division_name
        ORDER BY total_expenses DESC
    ";
    
    $results = $db->fetchAll($query, $params);
    
    // Calculate percentage of total
    $grand_total = array_sum(array_column($results, 'total_expenses'));
    
    foreach ($results as &$row) {
        $row['percentage_of_total'] = $grand_total > 0 
            ? round(($row['total_expenses'] / $grand_total) * 100, 2) 
            : 0;
    }
    
    return $results;
}

/**
 * Get monthly expenses breakdown by Division
 * 
 * @param int $months Number of months to analyze (default 12)
 * @return array Monthly expense data grouped by division
 */
function getMonthlyExpensesByDivision($months = 12) {
    $db = Database::getInstance();
    
    $query = "
        SELECT 
            d.id as division_id,
            d.division_code,
            d.division_name,
            DATE_FORMAT(ph.invoice_date, '%Y-%m') as month_year,
            DATE_FORMAT(ph.invoice_date, '%M %Y') as month_name,
            COUNT(DISTINCT ph.id) as total_invoices,
            COUNT(pd.id) as total_items,
            SUM(pd.subtotal) as total_expenses,
            AVG(pd.subtotal) as avg_item_cost
        FROM divisions d
        LEFT JOIN purchases_detail pd ON d.id = pd.division_id
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        WHERE ph.invoice_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
            AND ph.id IS NOT NULL
        GROUP BY d.id, d.division_code, d.division_name, 
                 DATE_FORMAT(ph.invoice_date, '%Y-%m'), 
                 DATE_FORMAT(ph.invoice_date, '%M %Y')
        ORDER BY month_year DESC, d.division_name
    ";
    
    return $db->fetchAll($query, ['months' => $months]);
}

/**
 * Get expenses breakdown by Division and Supplier
 * 
 * @param array $filters Optional filters: date_from, date_to, division_id, supplier_id
 * @return array Expense breakdown by division and supplier
 */
function getExpensesByDivisionAndSupplier($filters = []) {
    $db = Database::getInstance();
    
    $where_conditions = ["ph.id IS NOT NULL"];
    $params = [];
    
    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    
    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }
    
    if (isset($filters['division_id']) && !empty($filters['division_id'])) {
        $where_conditions[] = "d.id = :division_id";
        $params['division_id'] = $filters['division_id'];
    }
    
    if (isset($filters['supplier_id']) && !empty($filters['supplier_id'])) {
        $where_conditions[] = "s.id = :supplier_id";
        $params['supplier_id'] = $filters['supplier_id'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "
        SELECT 
            d.id as division_id,
            d.division_name,
            s.id as supplier_id,
            s.supplier_name,
            s.supplier_code,
            COUNT(DISTINCT ph.id) as total_invoices,
            COUNT(pd.id) as total_items,
            SUM(pd.subtotal) as total_expenses
        FROM divisions d
        LEFT JOIN purchases_detail pd ON d.id = pd.division_id
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        WHERE {$where_clause}
        GROUP BY d.id, d.division_name, s.id, s.supplier_name, s.supplier_code
        ORDER BY d.division_name, total_expenses DESC
    ";
    
    return $db->fetchAll($query, $params);
}

/**
 * Get top purchased items by Division
 * 
 * @param int $division_id Division ID
 * @param int $limit Number of top items to return (default 10)
 * @param array $filters Optional filters: date_from, date_to
 * @return array Top purchased items
 */
function getTopItemsByDivision($division_id, $limit = 10, $filters = []) {
    $db = Database::getInstance();
    
    $where_conditions = ["pd.division_id = :division_id", "ph.id IS NOT NULL"];
    $params = ['division_id' => $division_id];
    
    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    
    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "
        SELECT 
            pd.item_name,
            COUNT(pd.id) as purchase_count,
            SUM(pd.quantity) as total_quantity,
            pd.unit_of_measure,
            AVG(pd.unit_price) as avg_unit_price,
            MAX(pd.unit_price) as highest_unit_price,
            MIN(pd.unit_price) as lowest_unit_price,
            SUM(pd.subtotal) as total_spent
        FROM purchases_detail pd
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        WHERE {$where_clause}
        GROUP BY pd.item_name, pd.unit_of_measure
        ORDER BY total_spent DESC
        LIMIT :limit
    ";
    
    $params['limit'] = $limit;
    
    return $db->fetchAll($query, $params);
}

/**
 * Get expense comparison (current vs previous period)
 * 
 * @param string $period 'month', 'quarter', or 'year'
 * @return array Expense comparison by division
 */
function getExpenseComparison($period = 'month') {
    $db = Database::getInstance();
    
    // Determine date ranges based on period
    switch ($period) {
        case 'quarter':
            $current_start = date('Y-m-d', strtotime('first day of -2 months'));
            $current_end = date('Y-m-d');
            $previous_start = date('Y-m-d', strtotime('first day of -5 months'));
            $previous_end = date('Y-m-d', strtotime('last day of -3 months'));
            break;
        case 'year':
            $current_start = date('Y-01-01');
            $current_end = date('Y-12-31');
            $previous_start = date('Y-01-01', strtotime('-1 year'));
            $previous_end = date('Y-12-31', strtotime('-1 year'));
            break;
        default: // month
            $current_start = date('Y-m-01');
            $current_end = date('Y-m-t');
            $previous_start = date('Y-m-01', strtotime('-1 month'));
            $previous_end = date('Y-m-t', strtotime('-1 month'));
    }
    
    $query = "
        SELECT 
            d.id as division_id,
            d.division_code,
            d.division_name,
            SUM(CASE 
                WHEN ph.invoice_date BETWEEN :current_start AND :current_end 
                THEN pd.subtotal ELSE 0 
            END) as current_period_expenses,
            SUM(CASE 
                WHEN ph.invoice_date BETWEEN :previous_start AND :previous_end 
                THEN pd.subtotal ELSE 0 
            END) as previous_period_expenses,
            SUM(CASE 
                WHEN ph.invoice_date BETWEEN :current_start AND :current_end 
                THEN pd.subtotal ELSE 0 
            END) - SUM(CASE 
                WHEN ph.invoice_date BETWEEN :previous_start AND :previous_end 
                THEN pd.subtotal ELSE 0 
            END) as difference,
            ROUND(
                ((SUM(CASE WHEN ph.invoice_date BETWEEN :current_start AND :current_end THEN pd.subtotal ELSE 0 END) - 
                  SUM(CASE WHEN ph.invoice_date BETWEEN :previous_start AND :previous_end THEN pd.subtotal ELSE 0 END)) / 
                 NULLIF(SUM(CASE WHEN ph.invoice_date BETWEEN :previous_start AND :previous_end THEN pd.subtotal ELSE 0 END), 0)) * 100, 
            2) as growth_percentage
        FROM divisions d
        LEFT JOIN purchases_detail pd ON d.id = pd.division_id
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        WHERE ph.invoice_date BETWEEN :previous_start AND :current_end
        GROUP BY d.id, d.division_code, d.division_name
        ORDER BY current_period_expenses DESC
    ";
    
    return $db->fetchAll($query, [
        'current_start' => $current_start,
        'current_end' => $current_end,
        'previous_start' => $previous_start,
        'previous_end' => $previous_end
    ]);
}

/**
 * Get detailed expense report for a specific division
 * 
 * @param int $division_id Division ID
 * @param array $filters Optional filters: date_from, date_to
 * @return array Detailed expense list
 */
function getDivisionExpenseDetails($division_id, $filters = []) {
    $db = Database::getInstance();
    
    $where_conditions = ["pd.division_id = :division_id"];
    $params = ['division_id' => $division_id];
    
    if (isset($filters['date_from']) && !empty($filters['date_from'])) {
        $where_conditions[] = "ph.invoice_date >= :date_from";
        $params['date_from'] = $filters['date_from'];
    }
    
    if (isset($filters['date_to']) && !empty($filters['date_to'])) {
        $where_conditions[] = "ph.invoice_date <= :date_to";
        $params['date_to'] = $filters['date_to'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "
        SELECT 
            ph.invoice_number,
            ph.invoice_date,
            s.supplier_name,
            pd.line_number,
            pd.item_name,
            pd.item_description,
            pd.quantity,
            pd.unit_of_measure,
            pd.unit_price,
            pd.subtotal,
            ph.payment_status,
            ph.gl_posted,
            u.full_name as created_by
        FROM purchases_detail pd
        LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
        LEFT JOIN suppliers s ON ph.supplier_id = s.id
        LEFT JOIN users u ON ph.created_by = u.user_id
        WHERE {$where_clause}
        ORDER BY ph.invoice_date DESC, ph.invoice_number, pd.line_number
    ";
    
    return $db->fetchAll($query, $params);
}
