<?php
/**
 * API: Catat Setoran Investor
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    $investor_id = intval($_POST['investor_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $deposit_date = $_POST['deposit_date'] ?? date('Y-m-d');
    $description = trim($_POST['description'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'transfer';

    // Validate
    if (!$investor_id) {
        echo json_encode(['success' => false, 'message' => 'Please select an investor first']);
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Deposit amount must be greater than 0']);
        exit;
    }

    // Check investor exists
    $stmt = $db->prepare("SELECT * FROM investors WHERE id = ?");
    $stmt->execute([$investor_id]);
    $investor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$investor) {
        echo json_encode(['success' => false, 'message' => 'Investor not found']);
        exit;
    }

    $db->beginTransaction();

    // Check investor_transactions table structure
    $stmt = $db->query("DESCRIBE investor_transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Build insert based on available columns
    $sql_cols = [];
    $params = [];

    // investor_id
    $sql_cols[] = 'investor_id';
    $params[] = $investor_id;

    // transaction_type or type (ENUM: capital, expense, return)
    if (in_array('transaction_type', $columns)) {
        $sql_cols[] = 'transaction_type';
        $params[] = 'capital'; // capital for investor deposits
    } elseif (in_array('type', $columns)) {
        $sql_cols[] = 'type';
        $params[] = 'capital'; // capital for investor deposits
    }

    // amount
    if (in_array('amount', $columns)) {
        $sql_cols[] = 'amount';
        $params[] = $amount;
    } elseif (in_array('amount_idr', $columns)) {
        $sql_cols[] = 'amount_idr';
        $params[] = $amount;
    }

    // date
    if (in_array('transaction_date', $columns)) {
        $sql_cols[] = 'transaction_date';
        $params[] = $deposit_date;
    } elseif (in_array('date', $columns)) {
        $sql_cols[] = 'date';
        $params[] = $deposit_date;
    }

    // description
    if (in_array('description', $columns)) {
        $sql_cols[] = 'description';
        $params[] = $description ?: 'Investor deposit';
    } elseif (in_array('note', $columns)) {
        $sql_cols[] = 'note';
        $params[] = $description ?: 'Investor deposit';
    }

    // payment_method
    if (in_array('payment_method', $columns)) {
        $sql_cols[] = 'payment_method';
        $params[] = $payment_method;
    }

    // created_by
    if (in_array('created_by', $columns)) {
        $sql_cols[] = 'created_by';
        $params[] = $_SESSION['user_id'] ?? 1;
    }

    // created_at
    if (in_array('created_at', $columns)) {
        $sql_cols[] = 'created_at';
        $params[] = date('Y-m-d H:i:s');
    }

    $sql = "INSERT INTO investor_transactions (" . implode(', ', $sql_cols) . ") VALUES (" . implode(', ', array_fill(0, count($params), '?')) . ")";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $transaction_id = $db->lastInsertId();

    // Update investor balance
    $stmt = $db->query("DESCRIBE investors");
    $investorCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('balance', $investorCols)) {
        $stmt = $db->prepare("UPDATE investors SET balance = COALESCE(balance, 0) + ? WHERE id = ?");
        $stmt->execute([$amount, $investor_id]);
    } elseif (in_array('total_capital', $investorCols)) {
        $stmt = $db->prepare("UPDATE investors SET total_capital = COALESCE(total_capital, 0) + ? WHERE id = ?");
        $stmt->execute([$amount, $investor_id]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Deposit recorded successfully',
        'transaction_id' => $transaction_id
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Investor deposit error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
