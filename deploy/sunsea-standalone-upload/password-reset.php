<?php
/**
 * Password Reset Tool - Emergency Access
 * SECURITY: Only accessible from localhost OR authenticated admin/developer
 */

define("APP_ACCESS", true);
require_once "config/config.php";
require_once "config/database.php";
require_once "includes/auth.php";

// Security: Only allow from localhost OR authenticated admin/developer
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true) || 
           (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);

if (!$isLocal) {
    $auth = new Auth();
    if (!$auth->isLoggedIn() || !in_array($auth->getCurrentUser()['role'] ?? '', ['admin', 'developer'])) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><body><h1>403 Forbidden</h1></body></html>';
        exit;
    }
}

$db = Database::getInstance();
$message = "";
$error = "";

// Handle password reset
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = sanitize($_POST["username"] ?? "");
    $newPassword = $_POST["new_password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    
    if ($username && $newPassword && $confirmPassword) {
        if ($newPassword !== $confirmPassword) {
            $error = "Passwords do not match!";
        } else if (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters!";
        } else {
            // Hash password with bcrypt (secure)
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            
            try {
                $result = $db->update("users", ["password" => $passwordHash], "username = ?", [$username]);
                $message = " Password reset successfully for user: <strong>" . htmlspecialchars($username) . "</strong>";
            } catch (Exception $e) {
                $error = "Error updating password.";
                error_log("Password reset error: " . $e->getMessage());
            }
        }
    } else {
        $error = "Please fill all fields!";
    }
}

// Get all users
$users = [];
try {
    $users = $db->fetchAll("SELECT id, username, role FROM users ORDER BY username");
} catch (Exception $e) {
    $error = "Could not fetch users.";
    error_log("Password reset users fetch error: " . $e->getMessage());
}

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Reset Tool</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .success { color: #4caf50; padding: 10px; background: #e8f5e9; border-radius: 4px; margin: 10px 0; }
        .error { color: #f44336; padding: 10px; background: #ffebee; border-radius: 4px; margin: 10px 0; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { padding: 10px 20px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0b7dda; }
        .user-list { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px; }
        .user-list h3 { margin-top: 0; }
        .user-item { padding: 8px; background: white; margin: 5px 0; border-radius: 3px; border-left: 3px solid #2196F3; }
        .user-item strong { color: #2196F3; }
        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 1.25rem;
            user-select: none;
            transition: color 0.2s;
        }
        .password-toggle:hover { color: #333; }
    </style>
</head>
<body>
    <div class="container">
        <h1> Password Reset Tool</h1>
        
        <?php if ($message): ?>
            <div class="success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username:</label>
                <select id="username" name="username" required>
                    <option value="">-- Select User --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['username']; ?>">
                            <?php echo $user['username']; ?> (<?php echo $user['role']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="new_password">New Password:</label>
                <div class="password-wrapper">
                    <input type="password" id="new_password" name="new_password" required style="padding-right: 45px;">
                    <span class="password-toggle" onclick="togglePassword('new_password', this)">👁️</span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password:</label>
                <div class="password-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required style="padding-right: 45px;">
                    <span class="password-toggle" onclick="togglePassword('confirm_password', this)">👁️</span>
                </div>
            </div>
            
            <button type="submit">Reset Password</button>
        </form>
        
        <div class="user-list">
            <h3>Current Users:</h3>
            <?php foreach ($users as $user): ?>
                <div class="user-item">
                    <strong><?php echo $user['username']; ?></strong> - Role: <?php echo $user['role']; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">
        <p style="font-size: 12px; color: #999;">
            <strong>Default Passwords:</strong><br>
            admin  admin<br>
            manager  manager<br>
            cashier  cashier
        </p>
    </div>
    
    <script>
    function togglePassword(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.textContent = '👁️‍🗨️';
        } else {
            input.type = 'password';
            iconElement.textContent = '👁️';
        }
    }
    </script>
</body>
</html>
