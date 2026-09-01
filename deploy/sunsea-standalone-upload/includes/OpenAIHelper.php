<?php
/**
 * OpenAI API Helper Class
 * Utility untuk menggunakan OpenAI API dalam sistem ADF
 */

class OpenAIHelper {
    private $apiKey;
    private $model;
    private $isActive;
    
    public function __construct() {
        $db = Database::getInstance();
        
        // Load settings from database
        $apiKeySetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'openai_api_key'");
        $modelSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'openai_model'");
        $activeSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'openai_active'");
        
        $this->apiKey = $apiKeySetting['setting_value'] ?? '';
        $this->model = $modelSetting['setting_value'] ?? 'gpt-3.5-turbo';
        $this->isActive = ($activeSetting['setting_value'] ?? '0') === '1';
    }
    
    /**
     * Check if OpenAI integration is available and configured
     */
    public function isAvailable() {
        return $this->isActive && !empty($this->apiKey);
    }
    
    /**
     * Generate AI completion with given prompt
     */
    public function generateCompletion($prompt, $systemMessage = '', $maxTokens = 500) {
        if (!$this->isAvailable()) {
            throw new Exception('OpenAI integration not available or not configured');
        }
        
        $messages = [];
        if (!empty($systemMessage)) {
            $messages[] = ['role' => 'system', 'content' => $systemMessage];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];
        
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ];
        
        return $this->makeAPICall('https://api.openai.com/v1/chat/completions', $data);
    }
    
    /**
     * Analyze guest review sentiment
     */
    public function analyzeReviewSentiment($reviewText) {
        $systemMessage = "You are a hotel review analysis expert. Analyze the sentiment and extract key insights from guest reviews. Respond in JSON format with: sentiment (positive/negative/neutral), score (1-10), key_points (array), and suggestions (array).";
        
        $prompt = "Analyze this hotel guest review:\n\n\"$reviewText\"";
        
        $result = $this->generateCompletion($prompt, $systemMessage, 300);
        
        if ($result['success']) {
            try {
                $content = $result['data']['choices'][0]['message']['content'];
                return [
                    'success' => true,
                    'analysis' => json_decode($content, true)
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Failed to parse AI response'
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Generate personalized guest welcome message
     */
    public function generateWelcomeMessage($guestName, $roomType, $preferences = []) {
        $systemMessage = "You are a friendly hotel concierge. Generate a warm, personalized welcome message for hotel guests. Keep it professional but friendly, and mention relevant hotel amenities.";
        
        $preferencesText = empty($preferences) ? '' : "\nGuest preferences: " . implode(', ', $preferences);
        
        $prompt = "Create a welcome message for:\nGuest: $guestName\nRoom Type: $roomType$preferencesText";
        
        $result = $this->generateCompletion($prompt, $systemMessage, 200);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => trim($result['data']['choices'][0]['message']['content'], '"')
            ];
        }
        
        return $result;
    }
    
    /**
     * Generate revenue optimization suggestions
     */
    public function generateRevenueInsights($occupancyRate, $averageRate, $seasonalData = []) {
        $systemMessage = "You are a hotel revenue management expert. Provide specific, actionable recommendations to optimize hotel revenue based on occupancy and pricing data.";
        
        $seasonalText = empty($seasonalData) ? '' : "\nSeasonal trends: " . json_encode($seasonalData);
        
        $prompt = "Analyze this hotel performance data and provide revenue optimization recommendations:\n\nCurrent occupancy rate: {$occupancyRate}%\nAverage daily rate: Rp " . number_format($averageRate, 0, ',', '.') . $seasonalText;
        
        $result = $this->generateCompletion($prompt, $systemMessage, 400);
        
        if ($result['success']) {
            return [
                'success' => true,
                'insights' => $result['data']['choices'][0]['message']['content']
            ];
        }
        
        return $result;
    }
    
    /**
     * Generate automated report summary
     */
    public function generateReportSummary($reportData) {
        $systemMessage = "You are a hotel management analyst. Create concise, executive-level summaries of hotel performance reports with key insights and actionable recommendations.";
        
        $dataText = json_encode($reportData, JSON_PRETTY_PRINT);
        $prompt = "Create an executive summary for this hotel performance report:\n\n$dataText";
        
        $result = $this->generateCompletion($prompt, $systemMessage, 300);
        
        if ($result['success']) {
            return [
                'success' => true,
                'summary' => $result['data']['choices'][0]['message']['content']
            ];
        }
        
        return $result;
    }
    
    /**
     * Make API call to OpenAI
     */
    private function makeAPICall($url, $data) {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);
        
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
        
        if ($httpCode === 200) {
            return [
                'success' => true,
                'data' => json_decode($response, true)
            ];
        } else {
            $errorData = json_decode($response, true);
            return [
                'success' => false,
                'error' => $errorData['error']['message'] ?? 'API call failed',
                'http_code' => $httpCode
            ];
        }
    }
    
    /**
     * Get current API usage/cost (if available from OpenAI)
     */
    public function getUsageStats() {
        // OpenAI doesn't provide real-time usage in their API
        // This would need to be tracked locally or estimated
        return [
            'requests_today' => 0,
            'estimated_cost' => 0,
            'model' => $this->model
        ];
    }
}