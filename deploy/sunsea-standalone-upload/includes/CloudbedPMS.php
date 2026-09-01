<?php
/**
 * Cloudbed PMS Integration Class
 * Integrasi dengan Cloudbed Property Management System untuk sinkronisasi hotel data
 */

class CloudbedPMS {
    private $clientId;
    private $clientSecret;
    private $propertyId;
    private $accessToken;
    private $baseUrl = 'https://hotels.cloudbeds.com/api/v1.2';
    private $isActive;
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        
        // Load settings from database
        $settings = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'cloudbed_%'");
        
        foreach ($settings as $setting) {
            switch ($setting['setting_key']) {
                case 'cloudbed_client_id':
                    $this->clientId = $setting['setting_value'];
                    break;
                case 'cloudbed_client_secret':
                    $this->clientSecret = $setting['setting_value'];
                    break;
                case 'cloudbed_property_id':
                    $this->propertyId = $setting['setting_value'];
                    break;
                case 'cloudbed_access_token':
                    $this->accessToken = $setting['setting_value'];
                    break;
                case 'cloudbed_active':
                    $this->isActive = $setting['setting_value'] === '1';
                    break;
            }
        }
    }
    
    /**
     * Check if Cloudbed PMS integration is available
     */
    public function isAvailable() {
        return $this->isActive && 
               !empty($this->clientId) && 
               !empty($this->clientSecret) && 
               !empty($this->propertyId);
    }
    
    /**
     * Test API connection
     */
    public function testConnection() {
        if (!$this->isAvailable()) {
            return ['success' => false, 'error' => 'Cloudbed not configured'];
        }
        
        $result = $this->makeAPICall('GET', '/getProperty', ['propertyID' => $this->propertyId]);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'Connected to Cloudbed PMS',
                'property' => $result['data']['data']['propertyName'] ?? 'Unknown Property'
            ];
        }
        
        return $result;
    }
    
    /**
     * Get property information from Cloudbed
     */
    public function getPropertyInfo() {
        return $this->makeAPICall('GET', '/getProperty', ['propertyID' => $this->propertyId]);
    }
    
    /**
     * Sync reservations from Cloudbed to ADF System
     */
    public function syncReservationsFromCloudbed($startDate = null, $endDate = null) {
        if (!$startDate) $startDate = date('Y-m-d');
        if (!$endDate) $endDate = date('Y-m-d', strtotime('+30 days'));
        
        $result = $this->makeAPICall('GET', '/getReservations', [
            'propertyID' => $this->propertyId,
            'checkinFrom' => $startDate,
            'checkinTo' => $endDate,
            'includeInHouse' => 'true'
        ]);
        
        if (!$result['success']) {
            return $result;
        }
        
        $reservations = $result['data']['data'] ?? [];
        $syncedCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($reservations as $cbReservation) {
            try {
                $this->syncSingleReservation($cbReservation);
                $syncedCount++;
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = "Reservation {$cbReservation['reservationID']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'message' => "Synced $syncedCount reservations, $errorCount errors",
            'synced_count' => $syncedCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
    
    /**
     * Sync single reservation from Cloudbed to ADF
     */
    private function syncSingleReservation($cbReservation) {
        // Map Cloudbed data to ADF format
        $adfReservation = $this->mapCloudbedToADF($cbReservation);
        
        // Check if reservation already exists
        $existing = $this->db->fetchOne("SELECT reservasi_id FROM reservasi WHERE cloudbed_reservation_id = ?", 
                                      [$cbReservation['reservationID']]);
        
        if ($existing) {
            // Update existing reservation
            $this->updateADFReservation($adfReservation, $existing['reservasi_id']);
        } else {
            // Create new reservation
            $this->createADFReservation($adfReservation);
        }
    }
    
    /**
     * Map Cloudbed reservation data to ADF format
     */
    private function mapCloudbedToADF($cbReservation) {
        $guest = $cbReservation['guest'] ?? [];
        
        return [
            'cloudbed_reservation_id' => $cbReservation['reservationID'],
            'nama_tamu' => trim(($guest['guestFirstName'] ?? '') . ' ' . ($guest['guestLastName'] ?? '')),
            'email' => $guest['guestEmail'] ?? '',
            'no_telp' => $guest['guestPhone'] ?? '',
            'alamat' => $guest['guestAddress'] ?? '',
            'tanggal_checkin' => $cbReservation['startDate'],
            'tanggal_checkout' => $cbReservation['endDate'],
            'tipe_kamar' => $this->mapCloudbedRoomType($cbReservation['roomTypeName'] ?? ''),
            'jumlah_tamu' => $cbReservation['adults'] + $cbReservation['children'],
            'total_harga' => $cbReservation['grandTotal'] ?? 0,
            'status_reservasi' => $this->mapCloudbedStatus($cbReservation['status']),
            'sumber_reservasi' => $cbReservation['source'] ?? 'Cloudbed',
            'catatan' => $cbReservation['guestNotes'] ?? '',
            'sync_date' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Map Cloudbed room type to ADF room type
     */
    private function mapCloudbedRoomType($cloudbedRoomType) {
        $mapping = [
            'Standard Room' => 'Standard',
            'Deluxe Room' => 'Deluxe',
            'Suite' => 'Suite',
            'Family Room' => 'Family'
        ];
        
        return $mapping[$cloudbedRoomType] ?? 'Standard';
    }
    
    /**
     * Map Cloudbed status to ADF status
     */
    private function mapCloudbedStatus($cloudbedStatus) {
        $mapping = [
            'confirmed' => 'confirmed',
            'canceled' => 'cancelled',
            'checked_in' => 'checkedin',
            'checked_out' => 'checkedout',
            'not_confirmed' => 'pending',
            'no_show' => 'noshow'
        ];
        
        return $mapping[$cloudbedStatus] ?? 'pending';
    }
    
    /**
     * Create new reservation in ADF from Cloudbed data
     */
    private function createADFReservation($adfReservation) {
        // First, create or update guest
        $guestId = $this->createOrUpdateGuest([
            'nama_tamu' => $adfReservation['nama_tamu'],
            'email' => $adfReservation['email'],
            'no_telp' => $adfReservation['no_telp'],
            'alamat' => $adfReservation['alamat']
        ]);
        
        // Create reservation
        $sql = "INSERT INTO reservasi (
            cloudbed_reservation_id, guest_id, tanggal_checkin, tanggal_checkout,
            tipe_kamar, jumlah_tamu, total_harga, status_reservasi, sumber_reservasi,
            catatan, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
            $this->db->query($sql, [
                $adfReservation['cloudbed_reservation_id'],
                $guestId,
                $adfReservation['tanggal_checkin'],
                $adfReservation['tanggal_checkout'],
                $adfReservation['tipe_kamar'],
                $adfReservation['jumlah_tamu'],
                $adfReservation['total_harga'],
                $adfReservation['status_reservasi'],
                $adfReservation['sumber_reservasi'],
                $adfReservation['catatan']
            ]);
    }
    
    /**
     * Update existing ADF reservation with Cloudbed data
     */
    private function updateADFReservation($adfReservation, $reservationId) {
        $sql = "UPDATE reservasi SET 
            tanggal_checkin = ?, tanggal_checkout = ?, tipe_kamar = ?,
            jumlah_tamu = ?, total_harga = ?, status_reservasi = ?,
            catatan = ?, updated_at = NOW()
            WHERE reservasi_id = ?";
        
        $this->db->query($sql, [
            $adfReservation['tanggal_checkin'],
            $adfReservation['tanggal_checkout'],
            $adfReservation['tipe_kamar'],
            $adfReservation['jumlah_tamu'],
            $adfReservation['total_harga'],
            $adfReservation['status_reservasi'],
            $adfReservation['catatan'],
            $reservationId
        ]);
    }
    
    /**
     * Create or update guest data
     */
    private function createOrUpdateGuest($guestData) {
        if (!empty($guestData['email'])) {
            // Check by email
            $existing = $this->db->fetchOne("SELECT guest_id FROM guest WHERE email = ?", [$guestData['email']]);
        } else {
            // Check by name and phone
            $existing = $this->db->fetchOne("SELECT guest_id FROM guest WHERE nama_tamu = ? AND no_telp = ?", 
                                          [$guestData['nama_tamu'], $guestData['no_telp']]);
        }
        
        if ($existing) {
            // Update existing guest
            $this->db->query("UPDATE guest SET nama_tamu = ?, email = ?, no_telp = ?, alamat = ?, updated_at = NOW() WHERE guest_id = ?", [
                $guestData['nama_tamu'], $guestData['email'], $guestData['no_telp'], 
                $guestData['alamat'], $existing['guest_id']
            ]);
            return $existing['guest_id'];
        } else {
            // Create new guest
            $this->db->query("INSERT INTO guest (nama_tamu, email, no_telp, alamat, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [
                $guestData['nama_tamu'], $guestData['email'], $guestData['no_telp'], $guestData['alamat']
            ]);
            return $this->db->getConnection()->lastInsertId();
        }
    }
    
    /**
     * Push ADF reservation to Cloudbed
     */
    public function pushReservationToCloudbed($reservationId) {
        $reservation = $this->db->fetchOne("
            SELECT r.*, g.nama_tamu, g.email, g.no_telp, g.alamat 
            FROM reservasi r 
            JOIN guest g ON r.guest_id = g.guest_id 
            WHERE r.reservasi_id = ?", [$reservationId]);
        
        if (!$reservation) {
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        
        // Don't push if already synced from Cloudbed
        if (!empty($reservation['cloudbed_reservation_id'])) {
            return ['success' => false, 'error' => 'Reservation already exists in Cloudbed'];
        }
        
        $cloudbedData = $this->mapADFToCloudbed($reservation);
        $result = $this->makeAPICall('POST', '/postReservation', $cloudbedData);
        
        if ($result['success']) {
            $cloudbedReservationId = $result['data']['data']['reservationID'];
            
            // Update ADF reservation with Cloudbed ID
            $this->db->query("UPDATE reservasi SET cloudbed_reservation_id = ?, updated_at = NOW() WHERE reservasi_id = ?", 
                           [$cloudbedReservationId, $reservationId]);
            
            return [
                'success' => true,
                'message' => 'Reservation pushed to Cloudbed successfully',
                'cloudbed_reservation_id' => $cloudbedReservationId
            ];
        }
        
        return $result;
    }
    
    /**
     * Map ADF reservation to Cloudbed format
     */
    private function mapADFToCloudbed($adfReservation) {
        return [
            'propertyID' => $this->propertyId,
            'startDate' => $adfReservation['tanggal_checkin'],
            'endDate' => $adfReservation['tanggal_checkout'],
            'adults' => $adfReservation['jumlah_tamu'],
            'children' => 0,
            'roomTypeID' => $this->getCloudebdRoomTypeId($adfReservation['tipe_kamar']),
            'guestFirstName' => $this->getFirstName($adfReservation['nama_tamu']),
            'guestLastName' => $this->getLastName($adfReservation['nama_tamu']),
            'guestEmail' => $adfReservation['email'],
            'guestPhone' => $adfReservation['no_telp'],
            'guestAddress' => $adfReservation['alamat'],
            'totalAmount' => $adfReservation['total_harga'],
            'source' => 'ADF System'
        ];
    }
    
    /**
     * Get room rates from Cloudbed
     */
    public function getRoomRates($startDate = null, $endDate = null) {
        if (!$startDate) $startDate = date('Y-m-d');
        if (!$endDate) $endDate = date('Y-m-d', strtotime('+30 days'));
        
        return $this->makeAPICall('GET', '/getRoomTypeRates', [
            'propertyID' => $this->propertyId,
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);
    }
    
    /**
     * Get room availability from Cloudbed
     */
    public function getRoomAvailability($startDate = null, $endDate = null) {
        if (!$startDate) $startDate = date('Y-m-d');
        if (!$endDate) $endDate = date('Y-m-d', strtotime('+30 days'));
        
        return $this->makeAPICall('GET', '/getRoomTypeAvailability', [
            'propertyID' => $this->propertyId,
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);
    }
    
    /**
     * Update room rates in Cloudbed 
     */
    public function updateRoomRates($roomTypeId, $startDate, $endDate, $rate) {
        return $this->makeAPICall('PUT', '/putRoomTypeRates', [
            'propertyID' => $this->propertyId,
            'roomTypeID' => $roomTypeId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rate' => $rate
        ]);
    }
    
    /**
     * Get statistics for dashboard
     */
    public function getSyncStats() {
        $total_synced_result = $this->db->fetchOne("SELECT COUNT(*) as count FROM reservasi WHERE cloudbed_reservation_id IS NOT NULL");
        $last_sync_result = $this->db->fetchOne("SELECT MAX(updated_at) as last_sync FROM reservasi WHERE cloudbed_reservation_id IS NOT NULL");
        $pending_push_result = $this->db->fetchOne("SELECT COUNT(*) as count FROM reservasi WHERE cloudbed_reservation_id IS NULL");
        
        $stats = [
            'total_synced' => $total_synced_result ? $total_synced_result['count'] : 0,
            'last_sync' => $last_sync_result ? $last_sync_result['last_sync'] : null,
            'pending_push' => $pending_push_result ? $pending_push_result['count'] : 0
        ];
        
        return $stats;
    }
    
    /**
     * Helper functions
     */
    private function getFirstName($fullName) {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }
    
    private function getLastName($fullName) {
        $parts = explode(' ', trim($fullName));
        array_shift($parts); // Remove first name
        return implode(' ', $parts);
    }
    
    private function getCloudebdRoomTypeId($adfRoomType) {
        // This should be configurable based on your property's room types
        $mapping = [
            'Standard' => 1,
            'Deluxe' => 2,
            'Suite' => 3,
            'Family' => 4
        ];
        
        return $mapping[$adfRoomType] ?? 1;
    }
    
    /**
     * Make API call to Cloudbed
     */
    private function makeAPICall($method, $endpoint, $data = []) {
        if (empty($this->accessToken)) {
            return ['success' => false, 'error' => 'No access token available'];
        }
        
        $url = $this->baseUrl . $endpoint;
        $curl = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $method === 'GET' && !empty($data) ? $url . '?' . http_build_query($data) : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            return ['success' => false, 'error' => 'CURL Error: ' . $error];
        }
        
        // Log API usage
        $this->logAPIUsage($endpoint, $method, $httpCode, $data);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => json_decode($response, true),
                'http_code' => $httpCode
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
     * Log API usage for monitoring
     */
    private function logAPIUsage($endpoint, $method, $httpCode, $requestData) {
        try {
            $this->db->query("INSERT INTO cloudbed_api_log (endpoint, method, http_code, request_data, created_at) VALUES (?, ?, ?, ?, NOW())", 
            [$endpoint, $method, $httpCode, json_encode($requestData)]);
        } catch (Exception $e) {
            // Ignore logging errors
        }
    }
}