<?php
/**
 * Cloudbed API Helper Class
 * Utility untuk menggunakan Cloudbed API dalam sistem ADF
 */

class CloudbedHelper {
    private $clientId;
    private $clientSecret;
    private $propertyId;
    private $accessToken;
    private $isActive;
    
    public function __construct() {
        $db = Database::getInstance();
        
        // Load settings from database
        $clientIdSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_client_id'");
        $clientSecretSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_client_secret'");
        $propertyIdSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_property_id'");
        $accessTokenSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_access_token'");
        $activeSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_active'");
        
        $this->clientId = $clientIdSetting['setting_value'] ?? '';
        $this->clientSecret = $clientSecretSetting['setting_value'] ?? '';
        $this->propertyId = $propertyIdSetting['setting_value'] ?? '';
        $this->accessToken = $accessTokenSetting['setting_value'] ?? '';
        $this->isActive = ($activeSetting['setting_value'] ?? '0') === '1';
    }
    
    /**
     * Check if Cloudbed integration is available and configured
     */
    public function isAvailable() {
        return $this->isActive && !empty($this->clientId) && !empty($this->propertyId);
    }
    
    /**
     * Get OAuth authorization URL for initial setup
     */
    public function getAuthorizationUrl($redirectUri) {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:reservation write:reservation read:guest write:guest read:property'
        ];
        
        return 'https://hotels.cloudbeds.com/api/v1.1/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken($code, $redirectUri) {
        $data = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri
        ];
        
        $result = $this->makeAPICall('POST', 'https://hotels.cloudbeds.com/api/v1.1/oauth/token', $data, false);
        
        if ($result['success'] && isset($result['data']['access_token'])) {
            // Save access token to database
            $db = Database::getInstance();
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbed_access_token', ?) 
                         ON DUPLICATE KEY UPDATE setting_value = ?", 
                        [$result['data']['access_token'], $result['data']['access_token']]);
            
            $this->accessToken = $result['data']['access_token'];
            return ['success' => true];
        }
        
        return $result;
    }
    
    /**
     * Get property information
     */
    public function getPropertyInfo() {
        if (!$this->isAvailable()) {
            throw new Exception('Cloudbed integration not available or not configured');
        }
        
        return $this->makeAPICall('GET', "https://hotels.cloudbeds.com/api/v1.1/getProperty", [
            'propertyID' => $this->propertyId
        ]);
    }
    
    /**
     * Get reservations for a date range
     */
    public function getReservations($startDate, $endDate) {
        $params = [
            'propertyID' => $this->propertyId,
            'checkinFrom' => $startDate,
            'checkinTo' => $endDate
        ];
        
        return $this->makeAPICall('GET', 'https://hotels.cloudbeds.com/api/v1.1/getReservations', $params);
    }
    
    /**
     * Get guest information by guest ID
     */
    public function getGuest($guestId) {
        return $this->makeAPICall('GET', 'https://hotels.cloudbeds.com/api/v1.1/getGuest', [
            'guestID' => $guestId
        ]);
    }
    
    /**
     * Create new reservation in Cloudbed
     */
    public function createReservation($reservationData) {
        $data = array_merge([
            'propertyID' => $this->propertyId
        ], $reservationData);
        
        return $this->makeAPICall('POST', 'https://hotels.cloudbeds.com/api/v1.1/postReservation', $data);
    }
    
    /**
     * Update existing reservation
     */
    public function updateReservation($reservationId, $updateData) {
        $data = array_merge([
            'propertyID' => $this->propertyId,
            'reservationID' => $reservationId
        ], $updateData);
        
        return $this->makeAPICall('PUT', 'https://hotels.cloudbeds.com/api/v1.1/putReservation', $data);
    }
    
    /**
     * Get room types and rates
     */
    public function getRoomTypes() {
        return $this->makeAPICall('GET', 'https://hotels.cloudbeds.com/api/v1.1/getRoomTypes', [
            'propertyID' => $this->propertyId
        ]);
    }
    
    /**
     * Update room rates
     */
    public function updateRates($rateData) {
        $data = array_merge([
            'propertyID' => $this->propertyId
        ], $rateData);
        
        return $this->makeAPICall('PUT', 'https://hotels.cloudbeds.com/api/v1.1/putRoomTypeRates', $data);
    }
    
    /**
     * Get availability for date range
     */
    public function getAvailability($startDate, $endDate) {
        return $this->makeAPICall('GET', 'https://hotels.cloudbeds.com/api/v1.1/getRoomTypeAvailability', [
            'propertyID' => $this->propertyId,
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);
    }
    
    /**
     * Sync guest data from Cloudbed to local database
     */
    public function syncGuestData($guestId) {
        $result = $this->getGuest($guestId);
        
        if ($result['success'] && isset($result['data']['data'])) {
            $guestData = $result['data']['data'];
            
            // Save to local database
            $db = Database::getInstance();
            
            try {
                $sql = "INSERT INTO guest_sync (
                    cloudbed_guest_id, first_name, last_name, email, phone,
                    address, city, country, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name),
                    last_name = VALUES(last_name),
                    email = VALUES(email),
                    phone = VALUES(phone),
                    address = VALUES(address),
                    city = VALUES(city),
                    country = VALUES(country),
                    updated_at = NOW()";
                
                $db->query($sql, [
                    $guestData['guestID'],
                    $guestData['guestFirstName'] ?? '',
                    $guestData['guestLastName'] ?? '',
                    $guestData['guestEmail'] ?? '',
                    $guestData['guestPhone'] ?? '',
                    $guestData['guestAddress'] ?? '',
                    $guestData['guestCity'] ?? '',
                    $guestData['guestCountry'] ?? ''
                ]);
                
                return ['success' => true, 'message' => 'Guest data synced successfully'];
            } catch (Exception $e) {
                return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
            }
        }
        
        return $result;
    }
    
    /**
     * Push local reservation to Cloudbed
     */
    public function pushReservationToCloudbed($localReservationId) {
        $db = Database::getInstance();
        
        // Get reservation data from local database
        $reservation = $db->fetchOne("
            SELECT r.*, g.nama_tamu, g.email, g.no_telp, g.alamat 
            FROM reservasi r 
            LEFT JOIN guest g ON r.guest_id = g.guest_id 
            WHERE r.reservasi_id = ?", [$localReservationId]);
        
        if (!$reservation) {
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        
        // Transform local data to Cloudbed format
        $cloudbedData = [
            'startDate' => $reservation['tanggal_checkin'],
            'endDate' => $reservation['tanggal_checkout'],
            'adults' => $reservation['jumlah_tamu'] ?? 1,
            'children' => 0,
            'roomTypeID' => $this->mapLocalRoomToCloudbed($reservation['tipe_kamar']),
            'rateTypeID' => 1, // Default rate type
            'guestFirstName' => $reservation['nama_tamu'],
            'guestEmail' => $reservation['email'],
            'guestPhone' => $reservation['no_telp'],
            'guestAddress' => $reservation['alamat'],
            'totalAmount' => $reservation['total_harga'],
            'source' => 'ADF System'
        ];
        
        $result = $this->createReservation($cloudbedData);
        
        if ($result['success']) {
            // Update local reservation with Cloudbed ID
            $cloudbedReservationId = $result['data']['data']['reservationID'];
            $db->query("UPDATE reservasi SET cloudbed_reservation_id = ? WHERE reservasi_id = ?", 
                        [$cloudbedReservationId, $localReservationId]);
        }
        
        return $result;
    }
    
    /**
     * Map local room types to Cloudbed room type IDs
     */
    private function mapLocalRoomToCloudbed($localRoomType) {
        $mapping = [
            'Standard' => 1,
            'Deluxe' => 2,
            'Suite' => 3,
            'Family' => 4
        ];
        
        return $mapping[$localRoomType] ?? 1;
    }
    
    /**
     * Make API call to Cloudbed
     */
    private function makeAPICall($method, $url, $data = [], $useAuth = true) {
        $curl = curl_init();
        
        $headers = ['Content-Type: application/json'];
        if ($useAuth && !empty($this->accessToken)) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $method === 'GET' && !empty($data) ? $url . '?' . http_build_query($data) : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            curl_close($curl);
            return [
                'success' => false,
                'error' => 'CURL Error: ' . curl_error($curl)
            ];
        }
        
        curl_close($curl);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => json_decode($response, true)
            ];
        } else {
            $errorData = json_decode($response, true);
            return [
                'success' => false,
                'error' => $errorData['message'] ?? 'API call failed',
                'http_code' => $httpCode
            ];
        }
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        try {
            $result = $this->getPropertyInfo();
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}