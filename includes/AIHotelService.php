<?php
/**
 * AI Hotel Service
 * Service class untuk fitur-fitur AI hotel menggunakan OpenAI dan Cloudbed
 */

require_once 'OpenAIHelper.php';
require_once 'CloudbedHelper.php';

class AIHotelService {
    private $openai;
    private $cloudbed;
    private $db;
    
    public function __construct() {
        $this->openai = new OpenAIHelper();
        $this->cloudbed = new CloudbedHelper();
        $this->db = Database::getInstance();
    }
    
    /**
     * Smart Guest Assistant - Generate personalized responses for guest inquiries
     */
    public function generateGuestResponse($guestMessage, $guestId = null) {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI assistant not available'];
        }
        
        $guestContext = '';
        if ($guestId) {
            $guest = $this->db->fetchOne("SELECT * FROM guest WHERE guest_id = ?", [$guestId]);
            if ($guest) {
                $guestContext = "\nGuest Info: {$guest['nama_tamu']}, staying in {$guest['tipe_kamar'] ?? 'N/A'}";
            }
        }
        
        $systemMessage = "You are a helpful hotel concierge assistant. Provide professional, friendly responses to guest inquiries. Always be courteous and offer specific help when possible. If asked about hotel amenities, services, or local attractions, provide detailed and helpful information.$guestContext";
        
        return $this->openai->generateCompletion($guestMessage, $systemMessage, 300);
    }
    
    /**
     * Automated Review Analysis and Response
     */
    public function analyzeAndRespondToReview($reviewText, $rating, $platform = '') {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI analysis not available'];
        }
        
        // First, analyze the review
        $analysis = $this->openai->analyzeReviewSentiment($reviewText);
        
        if (!$analysis['success']) {
            return $analysis;
        }
        
        // Generate appropriate response based on sentiment
        $sentiment = $analysis['analysis']['sentiment'];
        $platformText = $platform ? " on $platform" : '';
        
        $systemMessage = "You are a hotel manager responding to guest reviews$platformText. Generate a professional, empathetic response that addresses the guest's concerns and thanks them for their feedback. Keep responses concise but personal.";
        
        $prompt = "Generate a professional response to this $sentiment review (Rating: $rating/5):\n\n\"$reviewText\"\n\nAnalysis insights: " . json_encode($analysis['analysis']['key_points']);
        
        $responseResult = $this->openai->generateCompletion($prompt, $systemMessage, 250);
        
        if ($responseResult['success']) {
            // Save analysis to database for tracking
            $this->db->query("INSERT INTO review_analysis (
                review_text, rating, platform, sentiment, analysis_data, 
                suggested_response, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())", [
                $reviewText,
                $rating,
                $platform,
                $sentiment,
                json_encode($analysis['analysis']),
                $responseResult['data']['choices'][0]['message']['content']
            ]);
            
            return [
                'success' => true,
                'analysis' => $analysis['analysis'],
                'suggested_response' => $responseResult['data']['choices'][0]['message']['content']
            ];
        }
        
        return $responseResult;
    }
    
    /**
     * Revenue Optimization Insights
     */
    public function generateRevenueInsights($dateFrom, $dateTo) {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI insights not available'];
        }
        
        // Get performance data from database
        $performanceData = $this->getPerformanceData($dateFrom, $dateTo);
        
        if (empty($performanceData)) {
            return ['success' => false, 'error' => 'No performance data available for the specified period'];
        }
        
        // Get Cloudbed data if available
        $cloudbedData = [];
        if ($this->cloudbed->isAvailable()) {
            $cloudbedResult = $this->cloudbed->getReservations($dateFrom, $dateTo);
            if ($cloudbedResult['success']) {
                $cloudbedData = $cloudbedResult['data'];
            }
        }
        
        return $this->openai->generateRevenueInsights(
            $performanceData['occupancy_rate'],
            $performanceData['average_rate'],
            $performanceData['seasonal_data']
        );
    }
    
    /**
     * Personalized Guest Welcome Messages
     */
    public function generatePersonalizedWelcome($reservationId) {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI personalization not available'];
        }
        
        // Get reservation and guest data
        $reservation = $this->db->fetchOne("
            SELECT r.*, g.nama_tamu, g.email, g.preferensi 
            FROM reservasi r 
            LEFT JOIN guest g ON r.guest_id = g.guest_id 
            WHERE r.reservasi_id = ?", [$reservationId]);
        
        if (!$reservation) {
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        
        $preferences = [];
        if (!empty($reservation['preferensi'])) {
            $preferences = explode(',', $reservation['preferensi']);
        }
        
        return $this->openai->generateWelcomeMessage(
            $reservation['nama_tamu'],
            $reservation['tipe_kamar'],
            $preferences
        );
    }
    
    /**
     * Predictive Rate Recommendations
     */
    public function generateRateRecommendations($roomType, $checkInDate, $checkOutDate) {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI recommendations not available'];
        }
        
        // Get historical data
        $historicalData = $this->getHistoricalRateData($roomType, $checkInDate);
        
        // Get current market conditions
        $marketData = $this->getMarketConditions($checkInDate);
        
        $systemMessage = "You are a hotel revenue management expert. Based on historical data, market conditions, and seasonal trends, recommend optimal room rates. Provide specific rate suggestions with reasoning.";
        
        $prompt = "Recommend optimal room rates for:\n" .
                 "Room Type: $roomType\n" .
                 "Check-in: $checkInDate\n" .
                 "Check-out: $checkOutDate\n" .
                 "Historical Data: " . json_encode($historicalData) . "\n" .
                 "Market Conditions: " . json_encode($marketData);
        
        return $this->openai->generateCompletion($prompt, $systemMessage, 400);
    }
    
    /**
     * Automated Daily Reports with AI Insights
     */
    public function generateDailyReport($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }
        
        // Collect data
        $occupancyData = $this->getDailyOccupancy($date);
        $revenueData = $this->getDailyRevenue($date);
        $guestData = $this->getDailyGuestData($date);
        $issuesData = $this->getDailyIssues($date);
        
        $reportData = [
            'date' => $date,
            'occupancy' => $occupancyData,
            'revenue' => $revenueData,
            'guests' => $guestData,
            'issues' => $issuesData
        ];
        
        // Generate AI summary if available
        $aiSummary = '';
        if ($this->openai->isAvailable()) {
            $summaryResult = $this->openai->generateReportSummary($reportData);
            if ($summaryResult['success']) {
                $aiSummary = $summaryResult['summary'];
            }
        }
        
        // Save report to database
        $this->db->query("INSERT INTO daily_reports (
            report_date, occupancy_rate, total_revenue, guest_count, 
            ai_summary, report_data, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())", [
            $date,
            $occupancyData['occupancy_rate'],
            $revenueData['total_revenue'],
            $guestData['total_guests'],
            $aiSummary,
            json_encode($reportData)
        ]);
        
        return [
            'success' => true,
            'report' => $reportData,
            'ai_summary' => $aiSummary
        ];
    }
    
    /**
     * Guest Preference Prediction
     */
    public function predictGuestPreferences($guestId) {
        if (!$this->openai->isAvailable()) {
            return ['success' => false, 'error' => 'AI prediction not available'];
        }
        
        // Get guest history
        $guestHistory = $this->db->fetchAll("
            SELECT r.*, g.preferensi, g.usia, g.negara 
            FROM reservasi r 
            LEFT JOIN guest g ON r.guest_id = g.guest_id 
            WHERE r.guest_id = ? 
            ORDER BY r.tanggal_checkin DESC", [$guestId]);
        
        if (empty($guestHistory)) {
            return ['success' => false, 'error' => 'No guest history available'];
        }
        
        $systemMessage = "You are a hospitality personalization expert. Based on guest history and preferences, predict what services, amenities, or room settings this guest would prefer. Provide specific, actionable recommendations.";
        
        $historyText = json_encode($guestHistory, JSON_PRETTY_PRINT);
        $prompt = "Predict preferences and recommendations for this guest based on their history:\n\n$historyText";
        
        return $this->openai->generateCompletion($prompt, $systemMessage, 300);
    }
    
    /**
     * Helper methods for data collection
     */
    private function getPerformanceData($dateFrom, $dateTo) {
        $occupancy = $this->db->fetchOne("
            SELECT 
                ROUND(COUNT(*) * 100.0 / (DATEDIFF(?, ?) * (SELECT COUNT(*) FROM kamar)), 2) as occupancy_rate,
                ROUND(AVG(total_harga), 0) as average_rate
            FROM reservasi 
            WHERE tanggal_checkin BETWEEN ? AND ?", 
            [$dateTo, $dateFrom, $dateFrom, $dateTo]);
        
        return [
            'occupancy_rate' => $occupancy['occupancy_rate'] ?? 0,
            'average_rate' => $occupancy['average_rate'] ?? 0,
            'seasonal_data' => $this->getSeasonalData($dateFrom, $dateTo)
        ];
    }
    
    private function getSeasonalData($dateFrom, $dateTo) {
        // Get monthly comparison data
        return $this->db->fetchAll("
            SELECT 
                MONTH(tanggal_checkin) as month,
                ROUND(AVG(total_harga), 0) as avg_rate,
                COUNT(*) as bookings
            FROM reservasi 
            WHERE tanggal_checkin BETWEEN DATE_SUB(?, INTERVAL 1 YEAR) AND ?
            GROUP BY MONTH(tanggal_checkin)
            ORDER BY month", [$dateFrom, $dateTo]);
    }
    
    private function getHistoricalRateData($roomType, $date) {
        return $this->db->fetchAll("
            SELECT AVG(total_harga) as avg_rate, COUNT(*) as bookings 
            FROM reservasi 
            WHERE tipe_kamar = ? 
            AND DAYOFYEAR(tanggal_checkin) = DAYOFYEAR(?) 
            AND YEAR(tanggal_checkin) < YEAR(?)
            GROUP BY YEAR(tanggal_checkin)
            ORDER BY YEAR(tanggal_checkin) DESC 
            LIMIT 3", [$roomType, $date, $date]);
    }
    
    private function getMarketConditions($date) {
        return [
            'season' => $this->determineSeason($date),
            'day_of_week' => date('l', strtotime($date)),
            'local_events' => $this->getLocalEvents($date)
        ];
    }
    
    private function determineSeason($date) {
        $month = (int)date('m', strtotime($date));
        if ($month >= 12 || $month <= 2) return 'high'; // Holiday season
        if ($month >= 6 && $month <= 8) return 'high';   // Summer
        return 'regular';
    }
    
    private function getLocalEvents($date) {
        // This would be enhanced with actual local event data
        return [];
    }
    
    private function getDailyOccupancy($date) {
        return $this->db->fetchOne("
            SELECT 
                COUNT(*) as occupied_rooms,
                (SELECT COUNT(*) FROM kamar) as total_rooms,
                ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM kamar), 2) as occupancy_rate
            FROM reservasi 
            WHERE ? BETWEEN tanggal_checkin AND tanggal_checkout", [$date]);
    }
    
    private function getDailyRevenue($date) {
        return $this->db->fetchOne("
            SELECT 
                COALESCE(SUM(total_harga), 0) as total_revenue,
                COALESCE(AVG(total_harga), 0) as average_rate,
                COUNT(*) as reservations
            FROM reservasi 
            WHERE DATE(tanggal_checkin) = ?", [$date]);
    }
    
    private function getDailyGuestData($date) {
        return $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_guests,
                COUNT(DISTINCT guest_id) as unique_guests
            FROM reservasi 
            WHERE ? BETWEEN tanggal_checkin AND tanggal_checkout", [$date]);
    }
    
    private function getDailyIssues($date) {
        // This would collect any issues, complaints, or special requests
        return [
            'maintenance_requests' => 0,
            'guest_complaints' => 0,
            'special_requests' => 0
        ];
    }
}