<?php
return [
    'business_id' => 'eaat-meet',
    'name' => 'Eat Meet',
    'business_type' => 'restaurant',
    'database' => 'adf_eat_meet',

    // Logo (optional, jika kosong akan pakai icon)
    'logo' => '',

    'enabled_modules' => [
        'cashbook',
        'auth',
        'settings',
        'reports',
        'divisions',
        'procurement',
        'bills',
        'payroll'
        // sales_invoice & cafe-invoice modules dihilangkan atas permintaan user (2026-07-25)
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
