<?php
/**
 * Cloudbed OAuth Callback Handler
 * Handles the OAuth callback from Cloudbed and exchanges code for access token
 */

require_once '../config/config.php';
require_once '../includes/CloudbedHelper.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized access');
}

// Check for authorization code
if (!isset($_GET['code'])) {
    http_response_code(400);
    die('Authorization code not provided');
}

$code = $_GET['code'];
$redirectUri = 'http://' . $_SERVER['HTTP_HOST'] . '/adf_system/api/cloudbed-callback.php';

try {
    $cloudbed = new CloudbedHelper();
    $result = $cloudbed->exchangeCodeForToken($code, $redirectUri);
    
    if ($result['success']) {
        // Redirect back to settings with success message
        $_SESSION['cloudbed_success'] = 'Cloudbed integration configured successfully!';
        header('Location: ../modules/settings/api-integrations.php');
        exit;
    } else {
        // Redirect back with error
        $_SESSION['cloudbed_error'] = 'Failed to configure Cloudbed: ' . ($result['error'] ?? 'Unknown error');
        header('Location: ../modules/settings/api-integrations.php');
        exit;
    }
    
} catch (Exception $e) {
    $_SESSION['cloudbed_error'] = 'Error during Cloudbed configuration: ' . $e->getMessage();
    header('Location: ../modules/settings/api-integrations.php');
    exit;
}
?>