<?php
/**
 * SEND WHATSAPP REPORT API
 * Generates WhatsApp message and returns URL for Web app
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

try {
    // Get request data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('Invalid request data');
    }
    
    // Get WhatsApp number from business settings or use provided admin_phone
    $db = Database::getInstance();
    $settings = $db->fetchOne(
        "SELECT whatsapp_number FROM business_settings WHERE business_id = ? LIMIT 1",
        [ACTIVE_BUSINESS_ID]
    );
    
    $phoneNumber = $data['admin_phone'] ?? ($settings['whatsapp_number'] ?? '');
    
    if (empty($phoneNumber)) {
        throw new Exception('Nomor WhatsApp belum diatur. Silakan atur di Settings > End Shift Configuration');
    }
    
    // Clean phone number (remove non-digits)
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // Ensure phone starts with country code
    if (substr($phoneNumber, 0, 1) === '0') {
        $phoneNumber = '62' . substr($phoneNumber, 1);
    }
    
    // Format WhatsApp message
    $businessName = $data['business_name'] ?? BUSINESS_NAME;
    $userName = $data['user_name'] ?? 'Staff';
    $date = date('d/m/Y');
    
    $message = "*🌅 LAPORAN END SHIFT*\n\n";
    $message .= "*Business:* " . $businessName . "\n";
    $message .= "*Tanggal:* " . $date . "\n";
    $message .= "*Petugas:* " . $userName . "\n\n";
    $message .= "━━━━━━━━━━━━━━━━━━━\n";
    $message .= "*💰 RINGKASAN TRANSAKSI*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "💵 Total Pemasukan:\n";
    $message .= "    Rp " . number_format($data['total_income'] ?? 0, 0, ',', '.') . "\n\n";
    $message .= "💸 Total Pengeluaran:\n";
    $message .= "    Rp " . number_format($data['total_expense'] ?? 0, 0, ',', '.') . "\n\n";
    $message .= "💰 Saldo Bersih:\n";
    $message .= "    Rp " . number_format($data['net_balance'] ?? 0, 0, ',', '.') . "\n\n";
    $message .= "━━━━━━━━━━━━━━━━━━━\n\n";
    $message .= "📊 Jumlah Transaksi: " . ($data['transaction_count'] ?? 0) . "\n";
    
    if (isset($data['po_count']) && $data['po_count'] > 0) {
        $message .= "📦 Purchase Order: " . $data['po_count'] . "\n";
    }
    
    $message .= "\n_Laporan otomatis dari ADF System_";
    
    // URL encode the message
    $encodedMessage = urlencode($message);
    $whatsappUrl = "https://wa.me/" . $phoneNumber . "?text=" . $encodedMessage;
    
    echo json_encode([
        'status' => 'success',
        'whatsapp_url' => $whatsappUrl,
        'phone' => $phoneNumber,
        'message' => 'WhatsApp link generated successfully'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
exit;
