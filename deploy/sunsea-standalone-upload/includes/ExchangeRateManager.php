<?php
/**
 * Exchange Rate Manager
 * Handles USD to IDR conversion with API integration
 * Supports: Bank Indonesia API, OpenExchangeRates API, Manual Input
 */

defined('APP_ACCESS') or die('Direct access not permitted');

class ExchangeRateManager {
    private $db;
    private $table = 'exchange_rates';
    
    // API Configuration
    private $bank_indonesia_url = 'https://api.bi.go.id/v1/rates';
    private $openexchange_url = 'https://openexchangerates.org/api/latest.json';
    private $openexchange_api_key = null; // Set this in config if using OpenExchange

    public function __construct($database) {
        $this->db = $database;
        
        // Load API key from config if exists
        if (defined('OPENEXCHANGE_API_KEY')) {
            $this->openexchange_api_key = OPENEXCHANGE_API_KEY;
        }
    }

    /**
     * Get current exchange rate (USD to IDR)
     * Returns rate from most recent record
     */
    public function getCurrentRate() {
        $query = "SELECT usd_to_idr, date_of_rate, source
                  FROM {$this->table}
                  ORDER BY date_of_rate DESC, time_of_rate DESC
                  LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Fetch rate from Bank Indonesia API
     * Returns array with rate and source
     */
    public function fetchFromBankIndonesia() {
        try {
            // Bank Indonesia API endpoint (free tier)
            $url = 'https://api.bi.go.id/v1/rates/USD/IDR/latest?Accept=application/json';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $data = json_decode($response, true);
                
                // Bank Indonesia API response format
                if (isset($data['data']['rate'])) {
                    return [
                        'success' => true,
                        'rate' => (float) $data['data']['rate'],
                        'source' => 'api_bank_indonesia',
                        'date_fetched' => date('Y-m-d H:i:s')
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Failed to fetch from Bank Indonesia API'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Fetch rate from OpenExchangeRates API
     * Requires API key from https://openexchangerates.org/
     */
    public function fetchFromOpenExchangeRates() {
        try {
            if (!$this->openexchange_api_key) {
                return [
                    'success' => false,
                    'message' => 'OpenExchangeRates API key not configured'
                ];
            }

            $url = $this->openexchange_url . '?app_id=' . $this->openexchange_api_key . '&base=USD&symbols=IDR';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $data = json_decode($response, true);
                
                if (isset($data['rates']['IDR'])) {
                    return [
                        'success' => true,
                        'rate' => (float) $data['rates']['IDR'],
                        'source' => 'api_openexchange',
                        'date_fetched' => date('Y-m-d H:i:s')
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Failed to fetch from OpenExchangeRates API'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Save exchange rate to database
     */
    public function saveRate($rate, $source = 'api_bank_indonesia', $is_current = true) {
        try {
            // If this is current rate, unset previous current rates
            if ($is_current) {
                $query = "UPDATE {$this->table} SET is_current = 0 WHERE is_current = 1";
                $stmt = $this->db->prepare($query);
                $stmt->execute();
            }

            // Insert new rate
            $insert_query = "INSERT INTO {$this->table} (date_of_rate, time_of_rate, usd_to_idr, source, is_current)
                            VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($insert_query);
            $stmt->execute([
                date('Y-m-d'),
                date('H:i:s'),
                $rate,
                $source,
                $is_current ? 1 : 0
            ]);

            return [
                'success' => true,
                'message' => 'Kurs berhasil disimpan',
                'rate' => $rate
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update exchange rate automatically
     * Tries Bank Indonesia first, then OpenExchangeRates as fallback
     */
    public function updateRateAuto() {
        // Try Bank Indonesia first (free, no API key needed)
        $result = $this->fetchFromBankIndonesia();
        
        if ($result['success']) {
            return $this->saveRate($result['rate'], $result['source']);
        }

        // Fallback to OpenExchangeRates if configured
        if ($this->openexchange_api_key) {
            $result = $this->fetchFromOpenExchangeRates();
            if ($result['success']) {
                return $this->saveRate($result['rate'], $result['source']);
            }
        }

        return [
            'success' => false,
            'message' => 'Failed to fetch exchange rate from all sources'
        ];
    }

    /**
     * Get exchange rate history
     */
    public function getRateHistory($days = 30) {
        $date_from = date('Y-m-d', strtotime("-{$days} days"));
        
        $query = "SELECT * FROM {$this->table}
                  WHERE date_of_rate >= ?
                  ORDER BY date_of_rate DESC, time_of_rate DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$date_from]);
        return $stmt->fetchAll();
    }

    /**
     * Convert USD to IDR with current rate
     */
    public function convertToIDR($amount_usd) {
        $rate = $this->getCurrentRate();
        
        if (!$rate) {
            return [
                'success' => false,
                'message' => 'Kurs tidak tersedia'
            ];
        }

        $amount_idr = $amount_usd * $rate['usd_to_idr'];

        return [
            'success' => true,
            'amount_usd' => $amount_usd,
            'amount_idr' => $amount_idr,
            'exchange_rate' => $rate['usd_to_idr'],
            'rate_date' => $rate['date_of_rate'],
            'rate_source' => $rate['source']
        ];
    }

    /**
     * Check if rate needs update (older than 24 hours)
     */
    public function isRateStale() {
        $rate = $this->getCurrentRate();
        
        if (!$rate) {
            return true;
        }

        $rate_time = strtotime($rate['date_of_rate']);
        $now = strtotime(date('Y-m-d'));
        
        return ($now - $rate_time) >= 86400; // 24 hours
    }

    /**
     * Manual rate input (admin override)
     */
    public function setManualRate($rate, $admin_id) {
        try {
            // Unset previous current rates
            $query = "UPDATE {$this->table} SET is_current = 0 WHERE is_current = 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute();

            // Insert manual rate
            $insert_query = "INSERT INTO {$this->table} (date_of_rate, time_of_rate, usd_to_idr, source, is_current)
                            VALUES (?, ?, ?, 'manual_input', 1)";
            
            $stmt = $this->db->prepare($insert_query);
            $stmt->execute([
                date('Y-m-d'),
                date('H:i:s'),
                $rate
            ]);

            return [
                'success' => true,
                'message' => 'Kurs manual berhasil disimpan',
                'rate' => $rate
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
}
?>
