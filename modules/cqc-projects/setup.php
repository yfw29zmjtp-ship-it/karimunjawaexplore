<?php
/**
 * CQC Projects Setup Script - FIXED VERSION
 * Dengan proper environment detection untuk localhost vs hosting
 */

session_start();

// Setup adalah public page, boleh diakses siapa saja tanpa login
// Pastikan ini hanya diakses sekali di awal setup

$setupMessage = '';
$setupError = '';

function getDBConnection() {
    // Detect environment
    $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
    
    $dbHost = 'localhost';
    $dbUser = $isLocalhost ? 'root' : 'adfb2574_adfsystem';
    $dbPass = $isLocalhost ? '' : '@Nnoc2025';
    $dbName = $isLocalhost ? 'adf_cqc' : 'adfb2574_cqc';
    
    try {
        // First, connect without database to create it if needed
        $pdo = new PDO(
            "mysql:host=" . $dbHost,
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS " . $dbName . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Now connect to the specific database
        $pdo = new PDO(
            "mysql:host=" . $dbHost . ";dbname=" . $dbName,
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (Exception $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = getDBConnection();
        
        // Read SQL file
        $sqlFile = '../../database/migration-cqc-projects.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception("SQL file not found: " . $sqlFile);
        }
        
        $sql = file_get_contents($sqlFile);
        
        // Split queries and execute
        $queries = array_filter(
            array_map('trim', explode(';', $sql)),
            function($q) { return !empty($q) && strpos($q, '--') !== 0; }
        );
        
        $queryCount = 0;
        foreach ($queries as $query) {
            if (!empty(trim($query))) {
                $pdo->exec($query);
                $queryCount++;
            }
        }
        
        $setupMessage = "✅ Database setup berhasil! Dijalankan " . $queryCount . " queries. Tables sudah dibuat dan initial data sudah diinsert.";
        
    } catch (Exception $e) {
        $setupError = "❌ Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup CQC Projects</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        .setup-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .setup-card h1 {
            color: #0066CC;
            font-size: 32px;
            margin-bottom: 20px;
        }

        .setup-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .features {
            text-align: left;
            background: #f5f7fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid #FFD700;
        }

        .features h3 {
            color: #0066CC;
            font-size: 16px;
            margin-bottom: 15px;
        }

        .features ul {
            list-style: none;
            padding: 0;
        }

        .features li {
            padding: 8px 0;
            color: #666;
            font-size: 14px;
            border-bottom: 1px solid #eee;
        }

        .features li:last-child {
            border-bottom: none;
        }

        .features li:before {
            content: "✓ ";
            color: #10b981;
            font-weight: bold;
            margin-right: 8px;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        form {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }

        button {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-setup {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
        }

        .btn-setup:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 102, 204, 0.3);
        }

        .btn-back {
            background: #f0f0f0;
            color: #333;
        }

        .btn-back:hover {
            background: #e0e0e0;
        }

        .code-box {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            text-align: left;
            overflow-x: auto;
            margin: 15px 0;
        }

        @media (max-width: 600px) {
            .setup-card {
                padding: 25px;
            }

            .setup-card h1 {
                font-size: 24px;
            }

            button {
                padding: 12px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="setup-card">
            <h1>☀️ CQC Projects Setup</h1>
            <p>Setup database untuk CQC Solar Projects Management System</p>

            <?php
            $isLocalhost = (strpos($_SERVER['HTTP_HOST'] ?? 'localhost', 'localhost') !== false);
            $envLabel = $isLocalhost ? 'LOCAL DEVELOPMENT' : 'PRODUCTION';
            $dbNameDisplay = $isLocalhost ? 'adf_cqc' : 'adfb2574_cqc';
            ?>

            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin: 20px 0; text-align: left; font-size: 13px;">
                <strong style="color: #856404;">Environment Detected:</strong><br>
                Environment: <strong><?php echo $envLabel; ?></strong><br>
                Database Name: <strong><?php echo $dbNameDisplay; ?></strong><br>
                Host: <strong><?php echo $_SERVER['HTTP_HOST']; ?></strong>
            </div>

            <?php if ($setupMessage): ?>
                <div class="alert success"><?php echo $setupMessage; ?></div>
            <?php elseif ($setupError): ?>
                <div class="alert error"><?php echo $setupError; ?></div>
            <?php endif; ?>

            <div class="features">
                <h3>📦 Yang akan dibuat:</h3>
                <ul>
                    <li>Tabel <strong>cqc_projects</strong> - Data proyek instalasi panel surya</li>
                    <li>Tabel <strong>cqc_project_expenses</strong> - Tracking pengeluaran per proyek</li>
                    <li>Tabel <strong>cqc_expense_categories</strong> - 10 kategori pengeluaran standar</li>
                    <li>Tabel <strong>cqc_project_balances</strong> - Summary budget vs pengeluaran</li>
                </ul>
            </div>

            <?php if (!$setupMessage): ?>
                <form method="POST">
                    <button type="submit" class="btn-setup">🚀 Mulai Setup</button>
                </form>
            <?php else: ?>
                <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                    <p style="color: #155724; font-size: 14px; line-height: 1.6;">
                        <strong>✅ Setup berhasil!</strong> Anda sekarang bisa mengakses project dashboard di:
                    </p>
                    <div class="code-box">
<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']; ?>/adf_system/modules/cqc-projects/dashboard.php
                    </div>
                </div>

                <a href="dashboard.php" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #0066CC 0%, #004499 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">📊 Buka Dashboard</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
