<?php

/**
 * CQC Quotation - ACC (Approve) Quotation
 * Creates a new project automatically from quotation data
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
    ensureCQCQuotationTable($pdo);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Get quotation ID
$quotationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($quotationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID quotation tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get quotation data
    $stmt = $pdo->prepare("SELECT * FROM cqc_quotations WHERE id = ?");
    $stmt->execute([$quotationId]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        throw new Exception('Quotation tidak ditemukan');
    }

    if ($quotation['status'] === 'approved') {
        throw new Exception('Quotation sudah di-ACC sebelumnya');
    }

    // Check if project already linked
    if (!empty($quotation['project_id'])) {
        throw new Exception('Quotation sudah terhubung ke proyek');
    }

    // Generate project code: PRJ-YYMM-XXX
    $month = date('m');
    $year = date('y');
    $prefix = "PRJ-{$year}{$month}-";

    $stmtCount = $pdo->prepare("SELECT COUNT(*) as cnt FROM cqc_projects WHERE project_code LIKE ?");
    $stmtCount->execute([$prefix . '%']);
    $count = $stmtCount->fetch(PDO::FETCH_ASSOC)['cnt'] + 1;
    $projectCode = $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);

    // Prepare project data from quotation
    $projectName = $quotation['project_name'] ?: $quotation['subject'] ?: 'Proyek - ' . $quotation['client_name'];
    $projectLocation = $quotation['project_location'] ?: $quotation['client_address'] ?: '';
    $solarCapacityKwp = floatval($quotation['solar_capacity_kwp'] ?? 0);
    $budgetIdr = floatval($quotation['total_amount'] ?? 0);

    // Create new project
    $stmtProject = $pdo->prepare("
        INSERT INTO cqc_projects 
        (project_code, project_name, location, status, description,
         client_name, client_phone, client_email,
         solar_capacity_kwp, budget_idr,
         start_date, created_by, created_at, updated_at)
        VALUES (?, ?, ?, 'planning', ?, ?, ?, ?, ?, ?, CURDATE(), ?, NOW(), NOW())
    ");

    $description = "Dibuat otomatis dari Quotation: " . $quotation['quote_number'];
    if ($quotation['subject']) {
        $description .= "\nSubject: " . $quotation['subject'];
    }

    $stmtProject->execute([
        $projectCode,
        $projectName,
        $projectLocation,
        $description,
        $quotation['client_name'],
        $quotation['client_phone'] ?? '',
        $quotation['client_email'] ?? '',
        $solarCapacityKwp,
        $budgetIdr,
        $_SESSION['user_id'] ?? 1
    ]);

    $projectId = $pdo->lastInsertId();

    // Update quotation: set status to approved and link to project
    $stmtUpdate = $pdo->prepare("
        UPDATE cqc_quotations 
        SET status = 'approved', project_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmtUpdate->execute([$projectId, $quotationId]);

    // Try to add/update customer in main database
    try {
        $bizDb = Database::getInstance();

        // Check if customer exists by name or phone
        $existingCustomer = null;
        if (!empty($quotation['client_phone'])) {
            $existingCustomer = $bizDb->fetchOne(
                "SELECT id FROM customers WHERE phone = ? LIMIT 1",
                [$quotation['client_phone']]
            );
        }
        if (!$existingCustomer && !empty($quotation['client_email'])) {
            $existingCustomer = $bizDb->fetchOne(
                "SELECT id FROM customers WHERE email = ? LIMIT 1",
                [$quotation['client_email']]
            );
        }

        if (!$existingCustomer) {
            // Generate customer code: CUST-YYMM-XXX
            $custPrefix = "CUST-{$year}{$month}-";
            $custCount = $bizDb->fetchOne("SELECT COUNT(*) as cnt FROM customers WHERE customer_code LIKE ?", [$custPrefix . '%']);
            $custCode = $custPrefix . str_pad(($custCount['cnt'] ?? 0) + 1, 3, '0', STR_PAD_LEFT);

            // Create new customer
            $bizDb->query(
                "INSERT INTO customers (customer_code, customer_name, company_name, phone, email, address, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())",
                [
                    $custCode,
                    $quotation['client_name'],
                    $quotation['client_name'], // company_name same as client_name
                    $quotation['client_phone'] ?? '',
                    $quotation['client_email'] ?? '',
                    $quotation['client_address'] ?? ''
                ]
            );
        }
    } catch (Exception $e) {
        // Customer table may not exist or other issue - continue anyway
        error_log('ACC Quotation - Customer creation skipped: ' . $e->getMessage());
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Quotation berhasil di-ACC',
        'project_id' => $projectId,
        'project_code' => $projectCode
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
