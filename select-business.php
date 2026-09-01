<?php
/**
 * ADF SYSTEM - Select Business
 * Page untuk pilih bisnis jika user punya akses ke multiple business
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/business_helper.php';

$auth = new Auth();
$auth->requireLogin();

// Get user's accessible businesses from master database
try {
    $masterPdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $username = $_SESSION['username'];
    
    // Get user ID from master
    $userStmt = $masterPdo->prepare("SELECT id FROM users WHERE username = ?");
    $userStmt->execute([$username]);
    $userId = $userStmt->fetchColumn();
    
    if (!$userId) {
        setFlash('error', 'User tidak terdaftar di sistem!');
        redirect(BASE_URL . '/logout.php');
    }
    
    // Get businesses user has access to
    // Check whether slug column exists (backward compatibility)
    $hasSlugColumn = false;
    try {
        $hasSlugColumn = (bool)$masterPdo->query("SHOW COLUMNS FROM businesses LIKE 'slug'")->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hasSlugColumn = false;
    }

    // Get businesses user has access to
    $selectFields = "b.id, b.business_code, b.business_name, b.business_type";
    if ($hasSlugColumn) {
        $selectFields .= ", b.slug";
    }
    $bizStmt = $masterPdo->prepare("
        SELECT DISTINCT {$selectFields}
        FROM businesses b
        JOIN user_menu_permissions p ON b.id = p.business_id
        WHERE p.user_id = ?
        ORDER BY b.business_name
    ");
    $bizStmt->execute([$userId]);
    $userBusinesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($userBusinesses)) {
        setFlash('error', 'Anda tidak memiliki akses ke bisnis manapun!');
        redirect(BASE_URL . '/logout.php');
    }
    
    // Handle business selection
    if (isPost()) {
        $selectedBizCode = sanitize(getPost('business_code'));
        
        // Verify user has access and resolve target business slug
        $hasAccess = false;
        $targetBusinessSlug = '';
        foreach ($userBusinesses as $biz) {
            if ($biz['business_code'] === $selectedBizCode) {
                $hasAccess = true;
                if ($hasSlugColumn && !empty($biz['slug'])) {
                    $targetBusinessSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $biz['slug']);
                }
                break;
            }
        }

        if (empty($targetBusinessSlug)) {
            $targetBusinessSlug = businessCodeToSlug($selectedBizCode);
        }
        
        if ($hasAccess) {
            if (setActiveBusinessId($targetBusinessSlug)) {
                redirect(BASE_URL . '/index.php');
            }
            $error = 'Bisnis tidak dapat dibuka. Konfigurasi bisnis belum tersedia.';
        } else {
            $error = 'Anda tidak punya akses ke bisnis tersebut!';
        }
    }
    
} catch (PDOException $e) {
    error_log('Select business error: ' . $e->getMessage());
    setFlash('error', 'Terjadi kesalahan sistem!');
    redirect(BASE_URL . '/logout.php');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Bisnis - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .container {
            background: #1e293b;
            border-radius: 16px;
            padding: 2.5rem;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .header h1 {
            color: white;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        .header p {
            color: #94a3b8;
            font-size: 0.95rem;
        }
        .user-info {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
        }
        .user-details h3 {
            color: white;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .user-details p {
            color: #94a3b8;
            font-size: 0.875rem;
        }
        .business-grid {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .business-card {
            background: #334155;
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        .business-card:hover {
            border-color: #6366f1;
            background: #3b4b63;
            transform: translateY(-2px);
        }
        .business-card input[type="radio"] {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .business-card h3 {
            color: white;
            font-size: 1.125rem;
            margin-bottom: 0.5rem;
            padding-right: 2rem;
        }
        .business-card .type {
            display: inline-block;
            background: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
        }
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
        }
        .logout-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.3s;
        }
        .logout-link:hover {
            color: white;
        }
        .error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Pilih Bisnis</h1>
            <p>Pilih bisnis yang ingin Anda akses</p>
        </div>
        
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo htmlspecialchars($_SESSION['full_name']); ?></h3>
                <p>@<?php echo htmlspecialchars($_SESSION['username']); ?></p>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
        <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="business-grid">
                <?php foreach ($userBusinesses as $biz): ?>
                <label class="business-card">
                    <input type="radio" name="business_code" value="<?php echo htmlspecialchars($biz['business_code']); ?>" required>
                    <h3><?php echo htmlspecialchars($biz['business_name']); ?></h3>
                    <span class="type"><?php echo htmlspecialchars($biz['business_type']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            
            <button type="submit" class="submit-btn">Lanjutkan ke Dashboard</button>
        </form>
        
        <a href="logout.php" class="logout-link">Logout</a>
    </div>
</body>
</html>
