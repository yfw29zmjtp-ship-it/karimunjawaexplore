<?php
/**
 * Get Server IP Address
 * Returns the server's local IP address for mobile access
 * RESTRICTED: Only works on localhost (development)
 */
header('Content-Type: application/json');

// Security: Only allow from localhost
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true) || 
           (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false);
if (!$isLocal) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

function getServerIP() {
    // Method 1: $_SERVER variables
    if (!empty($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '127.0.0.1') {
        return $_SERVER['SERVER_ADDR'];
    }
    
    if (!empty($_SERVER['LOCAL_ADDR']) && $_SERVER['LOCAL_ADDR'] !== '127.0.0.1') {
        return $_SERVER['LOCAL_ADDR'];
    }
    
    // Method 2: gethostbyname
    $hostname = gethostname();
    $ip = gethostbyname($hostname);
    if ($ip !== $hostname && $ip !== '127.0.0.1') {
        return $ip;
    }
    
    // Fallback
    return '192.168.1.2';
}

try {
    $ip = getServerIP();
    $port = !empty($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '8080';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    
    echo json_encode([
        'success' => true,
        'ip' => $ip,
        'port' => $port,
        'protocol' => $protocol,
        'owner_login_url' => "{$protocol}://{$ip}:{$port}/narayana/owner-login.php",
        'owner_dashboard_url' => "{$protocol}://{$ip}:{$port}/narayana/modules/owner/dashboard.php",
        'hostname' => gethostname()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'ip' => '192.168.1.2' // fallback
    ]);
}
