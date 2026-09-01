<?php
return [
    'business_id' => 'bens-cafe',
    'name' => 'Bens Cafe',
    'business_type' => 'cafe',
    'database' => 'adf_benscafe',
    
    // Logo (optional, jika kosong akan pakai icon)
    'logo' => '', // Contoh: 'bens-cafe.png' di uploads/logos/
    
    'enabled_modules' => [
        'cashbook',
        'auth',
        'settings',
        'reports',
        'divisions',
        'procurement',
        'sales',
        'bills',
        'payroll',
        'cafe-invoice'
        // No frontdesk, investor, project for cafe
    ],
    
    'theme' => [
        'color_primary' => '#1e3a5f',
        'color_secondary' => '#2563eb',
        'icon' => '☕'
    ],
    
    'cashbook_columns' => [
        'order_number' => ['label' => 'Order #', 'type' => 'text', 'required' => false],
        'table_number' => ['label' => 'Table #', 'type' => 'text', 'required' => false],
        'barista_name' => ['label' => 'Barista', 'type' => 'text', 'required' => false]
    ],
    
    'dashboard_widgets' => [
        'show_daily_sales' => true,
        'show_orders' => true,
        'show_revenue' => true,
        'show_best_drinks' => true
    ]
];
