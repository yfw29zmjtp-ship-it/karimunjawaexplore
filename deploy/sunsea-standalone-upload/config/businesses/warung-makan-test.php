<?php
return [
    'business_id' => 'warung-makan-test',
    'name' => 'Warung Makan Test',
    'business_type' => 'restaurant',
    'database' => 'adf_warung_test',
    'logo' => '',
    'enabled_modules' => ['cashbook', 'auth', 'settings', 'reports', 'divisions', 'procurement', 'sales', 'bills', 'payroll'],
    'theme' => [
        'color_primary' => '#dc2626',
        'color_secondary' => '#7f1d1d',
        'icon' => '🍽️'
    ],
    'cashbook_columns' => [],
    'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true]
];
