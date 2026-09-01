<?php
return [
    'business_id' => 'pwf-furniture',
    'name' => 'PWF Furniture',
    'business_type' => 'manufacture',
    'database' => 'adf_pwf',
    'logo' => '',
    'enabled_modules' => ['cashbook', 'auth', 'settings', 'reports', 'divisions', 'procurement', 'sales', 'bills', 'payroll', 'production'],
    'theme' => [
        'color_primary' => '#4f46e5',
        'color_secondary' => '#312e81',
        'icon' => '🏭'
    ],
    'cashbook_columns' => [],
    'dashboard_widgets' => ['show_daily_sales' => true, 'show_orders' => true, 'show_revenue' => true]
];
