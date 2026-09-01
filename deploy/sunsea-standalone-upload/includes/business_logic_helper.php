<?php
/**
 * Business Logic Helper
 * Loads and applies business-specific logic and customizations
 * 
 * Usage in your code:
 * $businessLogic = getBusinessLogic(ACTIVE_BUSINESS_ID);
 * 
 * if ($businessLogic['business_type'] === 'contractor') {
 *     // Apply contractor-specific logic
 * }
 * 
 * $cashbookFields = $businessLogic['cashbook_columns'];
 */

if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

/**
 * Get business logic and customizations from config
 * @param string $businessId Business ID (e.g., 'cqc', 'bens-cafe')
 * @return array Business configuration with logic
 */
function getBusinessLogic($businessId = null) {
    if (!$businessId) {
        $businessId = ACTIVE_BUSINESS_ID ?? 'bens-cafe';
    }
    
    $businessFile = __DIR__ . '/../config/businesses/' . $businessId . '.php';
    
    if (!file_exists($businessFile)) {
        // Return default config if file doesn't exist
        return getDefaultBusinessLogic();
    }
    
    $config = require $businessFile;
    
    // Ensure all keys exist with defaults
    return array_merge(getDefaultBusinessLogic(), $config);
}

/**
 * Get default business logic
 * @return array
 */
function getDefaultBusinessLogic() {
    return [
        'business_id' => 'default',
        'name' => 'Default Business',
        'business_type' => 'general',
        'database' => 'adf_system',
        'logo' => '',
        'enabled_modules' => [
            'cashbook', 'auth', 'settings', 'reports', 
            'divisions', 'procurement', 'sales', 'bills', 'payroll'
        ],
        'theme' => [
            'color_primary' => '#667eea',
            'color_secondary' => '#764ba2',
            'icon' => '🏢'
        ],
        'cashbook_columns' => [],
        'dashboard_widgets' => [
            'show_daily_sales' => true,
            'show_orders' => true,
            'show_revenue' => true
        ],
        'custom_fields' => []
    ];
}

/**
 * Check if business has specific business type
 * @param string $businessType Type to check (e.g., 'contractor', 'hotel')
 * @param string $businessId Optional - Business ID to check. If null, uses ACTIVE_BUSINESS_ID
 * @return bool
 */
function isBusinessType($businessType, $businessId = null) {
    $logic = getBusinessLogic($businessId);
    return ($logic['business_type'] ?? 'general') === $businessType;
}

/**
 * Get cashbook columns for specific business
 * @param string $businessId Optional - Business ID
 * @return array
 */
function getCashbookColumnsForBusiness($businessId = null) {
    $logic = getBusinessLogic($businessId);
    return $logic['cashbook_columns'] ?? [];
}

/**
 * Get dashboard widgets for specific business
 * @param string $businessId Optional - Business ID
 * @return array
 */
function getDashboardWidgetsForBusiness($businessId = null) {
    $logic = getBusinessLogic($businessId);
    return $logic['dashboard_widgets'] ?? [];
}

/**
 * Check if widget is enabled for business
 * @param string $widgetName Widget name (e.g., 'daily_sales')
 * @param string $businessId Optional - Business ID
 * @return bool
 */
function isWidgetEnabled($widgetName, $businessId = null) {
    $widgets = getDashboardWidgetsForBusiness($businessId);
    $key = 'show_' . $widgetName;
    return isset($widgets[$key]) ? $widgets[$key] : false;
}

/**
 * Get custom fields for business
 * @param string $businessId Optional - Business ID
 * @return array
 */
function getCustomFieldsForBusiness($businessId = null) {
    $logic = getBusinessLogic($businessId);
    return $logic['custom_fields'] ?? [];
}

/**
 * Apply business-specific logic to query or data processing
 * 
 * Example usage in index.php (dashboard):
 * $logic = getBusinessLogic();
 * if ($logic['business_type'] === 'contractor') {
 *     // Apply contractor-specific dashboard logic
 *     $result = applyContractorDashboardLogic($data);
 * }
 * 
 * @param string $businessId Optional - Business ID
 * @return array Business configuration
 */
function applyBusinessLogic($businessId = null) {
    return getBusinessLogic($businessId);
}

// Example: How to use in index.php
/*

At the top of index.php, right after authentication:

require_once 'includes/business_logic_helper.php';

// Get business logic
$businessLogic = getBusinessLogic(ACTIVE_BUSINESS_ID);
$businessType = $businessLogic['business_type'];

// Example: Different logic for contractor
if ($businessType === 'contractor') {
    // Load contractor-specific queries/logic
    // Maybe add project_code filtering
    // Different dashboard layout
    // Custom report fields
}

// Example: Build dynamic queries based on business
$cashbookFields = getCashbookColumnsForBusiness();
foreach ($cashbookFields as $fieldName => $fieldConfig) {
    // Add these fields to your cashbook form
}

// Example: Show/hide dashboard widgets
if (isWidgetEnabled('daily_sales')) {
    // Show daily sales widget
}

*/

?>
