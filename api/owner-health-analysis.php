<?php
/**
 * API: Company Health Analysis
 * AI-powered company health metrics and recommendations
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

// Switch to hotel database
$db = Database::switchDatabase(getDbName('adf_narayana_hotel'));
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

try {
    $today = date('Y-m-d');
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));
    $last3Months = date('Y-m', strtotime('-3 months'));
    
    // Build WHERE clause
    $branchWhere = '';
    $params = [];
    if ($branchId) {
        $branchWhere = ' AND branch_id = :branch_id';
        $params['branch_id'] = $branchId;
    }
    
    // ===== 1. FINANCIAL METRICS =====
    
    // Current month
    $currentMonth = $db->fetchOne(
        "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
         FROM cash_book 
         WHERE DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $branchWhere,
        array_merge(['month' => $thisMonth], $params)
    );
    
    // Last month
    $previousMonth = $db->fetchOne(
        "SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as expense
         FROM cash_book 
         WHERE DATE_FORMAT(transaction_date, '%Y-%m') = :month" . $branchWhere,
        array_merge(['month' => $lastMonth], $params)
    );
    
    // Last 3 months average
    $last3MonthsData = $db->fetchOne(
        "SELECT 
            COALESCE(AVG(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) as avg_income,
            COALESCE(AVG(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as avg_expense
         FROM cash_book 
         WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)" . $branchWhere,
        $params
    );
    
    // Calculate metrics
    $currentIncome = (float)$currentMonth['income'];
    $currentExpense = (float)$currentMonth['expense'];
    $currentProfit = $currentIncome - $currentExpense;
    $profitMargin = $currentIncome > 0 ? ($currentProfit / $currentIncome) * 100 : 0;
    
    $prevIncome = (float)$previousMonth['income'];
    $prevExpense = (float)$previousMonth['expense'];
    $incomeGrowth = $prevIncome > 0 ? (($currentIncome - $prevIncome) / $prevIncome) * 100 : 0;
    $expenseGrowth = $prevExpense > 0 ? (($currentExpense - $prevExpense) / $prevExpense) * 100 : 0;
    
    // ===== 2. OCCUPANCY METRICS (if frontdesk exists) =====
    $occupancyData = ['total_rooms' => 0, 'occupied_rooms' => 0, 'occupancy_rate' => 0];
    
    try {
        $roomStats = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_rooms,
                COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_rooms
             FROM frontdesk_rooms
             WHERE 1=1" . $branchWhere,
            $params
        );
        
        if ($roomStats) {
            $occupancyData['total_rooms'] = (int)$roomStats['total_rooms'];
            $occupancyData['occupied_rooms'] = (int)$roomStats['occupied_rooms'];
            $occupancyData['occupancy_rate'] = $roomStats['total_rooms'] > 0 
                ? ($roomStats['occupied_rooms'] / $roomStats['total_rooms']) * 100 
                : 0;
        }
    } catch (Exception $e) {
        // Table doesn't exist, skip
    }
    
    // ===== 3. EXPENSE RATIO =====
    $expenseRatio = $currentIncome > 0 ? ($currentExpense / $currentIncome) * 100 : 0;
    
    // ===== 4. CASH FLOW TREND (Last 7 days) =====
    $cashFlowTrend = $db->fetchAll(
        "SELECT 
            DATE(transaction_date) as date,
            SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END) as net_flow
         FROM cash_book
         WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $branchWhere . "
         GROUP BY DATE(transaction_date)
         ORDER BY date DESC",
        $params
    );
    
    $avgDailyFlow = 0;
    if (count($cashFlowTrend) > 0) {
        $totalFlow = array_sum(array_column($cashFlowTrend, 'net_flow'));
        $avgDailyFlow = $totalFlow / count($cashFlowTrend);
    }
    
    // ===== 5. AI HEALTH SCORING (0-100) =====
    $healthScore = 0;
    $maxScore = 100;
    
    // Factor 1: Profit Margin (30 points)
    if ($profitMargin >= 30) $healthScore += 30;
    elseif ($profitMargin >= 20) $healthScore += 25;
    elseif ($profitMargin >= 10) $healthScore += 20;
    elseif ($profitMargin >= 5) $healthScore += 15;
    elseif ($profitMargin >= 0) $healthScore += 10;
    else $healthScore += 0;
    
    // Factor 2: Income Growth (25 points)
    if ($incomeGrowth >= 15) $healthScore += 25;
    elseif ($incomeGrowth >= 10) $healthScore += 20;
    elseif ($incomeGrowth >= 5) $healthScore += 15;
    elseif ($incomeGrowth >= 0) $healthScore += 10;
    else $healthScore += 5;
    
    // Factor 3: Expense Control (20 points)
    if ($expenseRatio <= 50) $healthScore += 20;
    elseif ($expenseRatio <= 60) $healthScore += 16;
    elseif ($expenseRatio <= 70) $healthScore += 12;
    elseif ($expenseRatio <= 80) $healthScore += 8;
    else $healthScore += 4;
    
    // Factor 4: Occupancy Rate (15 points)
    if ($occupancyData['occupancy_rate'] >= 80) $healthScore += 15;
    elseif ($occupancyData['occupancy_rate'] >= 70) $healthScore += 12;
    elseif ($occupancyData['occupancy_rate'] >= 60) $healthScore += 9;
    elseif ($occupancyData['occupancy_rate'] >= 50) $healthScore += 6;
    else $healthScore += 3;
    
    // Factor 5: Cash Flow Stability (10 points)
    if ($avgDailyFlow > 0) $healthScore += 10;
    elseif ($avgDailyFlow >= -100000) $healthScore += 5;
    else $healthScore += 0;
    
    // ===== 6. AI RECOMMENDATIONS =====
    $recommendations = [];
    $alerts = [];
    $strengths = [];
    
    // Analyze Profit Margin
    if ($profitMargin < 10) {
        $alerts[] = [
            'severity' => 'high',
            'title' => 'Profit Margin Rendah',
            'message' => 'Profit margin hanya ' . number_format($profitMargin, 1) . '%. Target minimal 15%.'
        ];
        $recommendations[] = [
            'priority' => 'high',
            'category' => 'Profitabilitas',
            'title' => 'Tingkatkan Profit Margin',
            'actions' => [
                'Review pricing strategy - pertimbangkan kenaikan harga 5-10%',
                'Identifikasi produk/layanan dengan margin tertinggi',
                'Kurangi biaya operasional yang tidak efisien',
                'Fokus pada upselling dan cross-selling'
            ]
        ];
    } elseif ($profitMargin >= 25) {
        $strengths[] = '✅ Profit margin sangat baik (' . number_format($profitMargin, 1) . '%)';
    }
    
    // Analyze Income Growth
    if ($incomeGrowth < 0) {
        $alerts[] = [
            'severity' => 'high',
            'title' => 'Pendapatan Menurun',
            'message' => 'Pendapatan turun ' . number_format(abs($incomeGrowth), 1) . '% dari bulan lalu.'
        ];
        $recommendations[] = [
            'priority' => 'urgent',
            'category' => 'Pendapatan',
            'title' => 'Recover Pendapatan',
            'actions' => [
                'Lakukan promosi atau diskon terbatas untuk menarik customer',
                'Review strategi marketing - tingkatkan digital marketing',
                'Survey customer untuk feedback dan improvement',
                'Cari segment pasar baru atau expand product line'
            ]
        ];
    } elseif ($incomeGrowth > 10) {
        $strengths[] = '✅ Pertumbuhan pendapatan positif (+' . number_format($incomeGrowth, 1) . '%)';
    }
    
    // Analyze Expense Ratio
    if ($expenseRatio > 75) {
        $alerts[] = [
            'severity' => 'medium',
            'title' => 'Biaya Operasional Tinggi',
            'message' => 'Biaya mencapai ' . number_format($expenseRatio, 1) . '% dari pendapatan.'
        ];
        $recommendations[] = [
            'priority' => 'high',
            'category' => 'Efisiensi',
            'title' => 'Optimasi Biaya Operasional',
            'actions' => [
                'Audit semua biaya bulanan - identifikasi pemborosan',
                'Negosiasi ulang kontrak supplier untuk harga lebih baik',
                'Implementasi sistem inventory management untuk reduce waste',
                'Evaluasi staffing - pastikan produktivitas optimal'
            ]
        ];
    } elseif ($expenseRatio < 60) {
        $strengths[] = '✅ Kontrol biaya sangat baik (' . number_format($expenseRatio, 1) . '% dari revenue)';
    }
    
    // Analyze Occupancy
    if ($occupancyData['occupancy_rate'] < 60 && $occupancyData['total_rooms'] > 0) {
        $alerts[] = [
            'severity' => 'medium',
            'title' => 'Occupancy Rate Rendah',
            'message' => 'Hanya ' . number_format($occupancyData['occupancy_rate'], 1) . '% kamar terisi.'
        ];
        $recommendations[] = [
            'priority' => 'high',
            'category' => 'Occupancy',
            'title' => 'Tingkatkan Tingkat Hunian',
            'actions' => [
                'Listing di OTA (Booking.com, Agoda, Traveloka) untuk reach lebih luas',
                'Buat paket promo menarik (weekend, long stay, corporate)',
                'Improve online presence - update foto, review, dan deskripsi',
                'Partnership dengan travel agent dan corporate'
            ]
        ];
    } elseif ($occupancyData['occupancy_rate'] >= 80) {
        $strengths[] = '✅ Occupancy rate excellent (' . number_format($occupancyData['occupancy_rate'], 1) . '%)';
        if ($occupancyData['occupancy_rate'] >= 90) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Revenue Management',
                'title' => 'Optimasi Revenue',
                'actions' => [
                    'Pertimbangkan kenaikan harga saat high demand',
                    'Implementasi dynamic pricing strategy',
                    'Batasi diskon - fokus pada value added services',
                    'Consider expansion atau renovasi untuk tambah kapasitas'
                ]
            ];
        }
    }
    
    // Cash Flow Analysis
    if ($avgDailyFlow < 0) {
        $alerts[] = [
            'severity' => 'urgent',
            'title' => 'Cash Flow Negatif',
            'message' => 'Rata-rata cash flow harian negatif. Perhatian khusus diperlukan!'
        ];
        $recommendations[] = [
            'priority' => 'urgent',
            'category' => 'Cash Flow',
            'title' => 'Perbaiki Aliran Kas',
            'actions' => [
                'Percepat collection dari customer - tighten payment terms',
                'Delay non-critical expenses jika memungkinkan',
                'Review working capital - optimasi inventory turnover',
                'Siapkan emergency fund atau credit line sebagai buffer'
            ]
        ];
    }
    
    // General best practices
    if ($healthScore >= 80) {
        $recommendations[] = [
            'priority' => 'low',
            'category' => 'Growth',
            'title' => 'Strategi Pertumbuhan',
            'actions' => [
                'Bisnis dalam kondisi sehat - pertimbangkan ekspansi',
                'Investasi dalam marketing untuk pertumbuhan lebih cepat',
                'Develop new revenue streams atau diversifikasi',
                'Build cash reserves untuk peluang investasi'
            ]
        ];
    }
    
    // ===== 7. HEALTH STATUS =====
    $healthStatus = '';
    $healthColor = '';
    
    if ($healthScore >= 80) {
        $healthStatus = 'Sangat Sehat';
        $healthColor = '#10b981';
    } elseif ($healthScore >= 65) {
        $healthStatus = 'Sehat';
        $healthColor = '#3b82f6';
    } elseif ($healthScore >= 50) {
        $healthStatus = 'Cukup Sehat';
        $healthColor = '#f59e0b';
    } elseif ($healthScore >= 35) {
        $healthStatus = 'Perlu Perhatian';
        $healthColor = '#ef4444';
    } else {
        $healthStatus = 'Kritis';
        $healthColor = '#dc2626';
    }
    
    // ===== RESPONSE =====
    echo json_encode([
        'success' => true,
        'health_score' => round($healthScore, 1),
        'health_status' => $healthStatus,
        'health_color' => $healthColor,
        'metrics' => [
            'current_income' => $currentIncome,
            'current_expense' => $currentExpense,
            'current_profit' => $currentProfit,
            'profit_margin' => round($profitMargin, 1),
            'income_growth' => round($incomeGrowth, 1),
            'expense_growth' => round($expenseGrowth, 1),
            'expense_ratio' => round($expenseRatio, 1),
            'avg_daily_flow' => round($avgDailyFlow, 0)
        ],
        'occupancy' => [
            'total_rooms' => $occupancyData['total_rooms'],
            'occupied_rooms' => $occupancyData['occupied_rooms'],
            'occupancy_rate' => round($occupancyData['occupancy_rate'], 1)
        ],
        'alerts' => $alerts,
        'strengths' => $strengths,
        'recommendations' => $recommendations,
        'analysis_date' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
