<?php
return [
    'business_id' => 'demo',
    'name' => 'Demo Business',
    'business_type' => 'general',
    'database' => 'adf_demo',
    
    'logo' => '',
    
    'enabled_modules' => [
        'cashbook',
        'auth',
        'settings',
        'reports',
        'frontdesk',
        'divisions',
        'procurement',
        'sales',
        'bills',
        'investor',
        'payroll'
    ],
    
    'theme' => [
        'color_primary' => '#059669',
        'color_secondary' => '#065f46',
        'icon' => '🏢'
    ],
    
    'cashbook_columns' => [],
    
    'dashboard_widgets' => [
        'show_daily_sales' => true,
        'show_orders' => true,
        'show_revenue' => true
    ]
];
