<?php
/**
 * Owner: Manage User Business Access
 * Owner dapat mengatur akses user ke bisnis tertentu
 */

require_once '../../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || ($auth->getCurrentUser()['role'] !== 'owner' && $auth->getCurrentUser()['role'] !== 'admin')) {
    header('Location: ../../login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../config/businesses.php';
require_once '../../includes/business_access.php';

$currentUser = $auth->getCurrentUser();
$db = Database::getInstance();

// Get all users (except superadmin)
$users = $db->fetchAll(
    "SELECT * FROM users WHERE role != 'superadmin' ORDER BY full_name"
);

// Get accessible businesses for current owner
$ownerBusinesses = getUserAvailableBusinesses($currentUser['id']);

// Get company name from settings
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$displayCompanyName = ($companyNameSetting && $companyNameSetting['setting_value']) 
    ? $companyNameSetting['setting_value'] 
    : 'Narayana';

$pageTitle = "Manage User Business Access";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= $displayCompanyName ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #1a202c;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #718096;
            font-size: 14px;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .user-card {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s;
        }
        
        .user-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .user-info h3 {
            color: #1a202c;
            font-size: 18px;
            margin-bottom: 4px;
        }
        
        .user-info .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            background: #edf2f7;
            color: #4a5568;
        }
        
        .user-info .role-badge.owner {
            background: #fef5e7;
            color: #f39c12;
        }
        
        .user-info .role-badge.admin {
            background: #e8f4fd;
            color: #3498db;
        }
        
        .business-access {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        
        .business-checkbox {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .business-checkbox:hover {
            border-color: #667eea;
            background: #f7fafc;
        }
        
        .business-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }
        
        .business-checkbox label {
            cursor: pointer;
            font-size: 14px;
            color: #2d3748;
            flex: 1;
        }
        
        .business-checkbox input[type="checkbox"]:checked + label {
            font-weight: 600;
            color: #667eea;
        }
        
        .save-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #4a5568;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #2d3748;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👥 <?= $pageTitle ?></h1>
            <p>Kelola akses user ke bisnis yang Anda miliki</p>
            <a href="dashboard.php" class="back-btn" style="margin-top: 12px;">← Kembali ke Dashboard</a>
        </div>
        
        <div class="card">
            <div id="message"></div>
            
            <?php foreach ($users as $user): ?>
                <?php
                // Get current business access for this user
                $userBusinessAccess = getUserAvailableBusinesses($user['id']);
                $userBusinessIds = array_column($userBusinessAccess, 'id');
                ?>
                <div class="user-card" data-user-id="<?= $user['id'] ?>">
                    <div class="user-header">
                        <div class="user-info">
                            <h3><?= htmlspecialchars($user['full_name']) ?></h3>
                            <span class="role-badge <?= $user['role'] ?>"><?= strtoupper($user['role']) ?></span>
                            <span style="color: #718096; font-size: 13px; margin-left: 8px;">
                                <?= htmlspecialchars($user['username']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="business-access">
                        <?php foreach ($ownerBusinesses as $business): ?>
                            <div class="business-checkbox">
                                <input 
                                    type="checkbox" 
                                    id="user_<?= $user['id'] ?>_business_<?= $business['id'] ?>"
                                    name="business_access[<?= $user['id'] ?>][]"
                                    value="<?= $business['id'] ?>"
                                    <?= in_array($business['id'], $userBusinessIds) ? 'checked' : '' ?>
                                    onchange="saveUserAccess(<?= $user['id'] ?>, this)"
                                >
                                <label for="user_<?= $user['id'] ?>_business_<?= $business['id'] ?>">
                                    <?= htmlspecialchars($business['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>
        function saveUserAccess(userId, checkbox) {
            const userCard = document.querySelector(`[data-user-id="${userId}"]`);
            const checkboxes = userCard.querySelectorAll('input[type="checkbox"]:checked');
            const businessIds = Array.from(checkboxes).map(cb => cb.value);
            
            // Show loading
            const messageDiv = document.getElementById('message');
            messageDiv.innerHTML = '<div class="alert" style="background: #e3f2fd; color: #1565c0;">💾 Menyimpan...</div>';
            
            // Send AJAX request
            fetch('../../api/update-user-business-access.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    user_id: userId,
                    business_ids: businessIds
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = '<div class="alert success">✅ Akses berhasil disimpan!</div>';
                    setTimeout(() => {
                        messageDiv.innerHTML = '';
                    }, 3000);
                } else {
                    messageDiv.innerHTML = '<div class="alert error">❌ Error: ' + data.message + '</div>';
                    // Revert checkbox if failed
                    checkbox.checked = !checkbox.checked;
                }
            })
            .catch(error => {
                messageDiv.innerHTML = '<div class="alert error">❌ Error: ' + error.message + '</div>';
                // Revert checkbox if failed
                checkbox.checked = !checkbox.checked;
            });
        }
    </script>
</body>
</html>
