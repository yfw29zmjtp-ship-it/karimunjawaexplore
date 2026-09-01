<?php
return [
    'business_id' => 'cqc',
    'name' => 'CQC Enjiniring',
    'business_type' => 'contractor',
    'database' => 'adf_cqc',
    
    // Company Info for Invoice
    'logo' => 'cqc-logo.png', // Place logo file in /uploads/ folder
    'tagline' => 'Solar Panel Installation Contractor',
    'address' => 'Jl. Example No. 123', // UPDATE THIS
    'city' => 'Jakarta', // UPDATE THIS
    'phone' => '+62 21 1234567', // UPDATE THIS
    'email' => 'info@cqc-engineering.com', // UPDATE THIS
    'npwp' => '00.000.000.0-000.000', // UPDATE THIS
    
    // Bank Info for Invoice Payment
    'bank_name' => 'Bank Mandiri', // UPDATE THIS
    'bank_account' => '1234567890', // UPDATE THIS
    'bank_holder' => 'PT CQC Enjiniring', // UPDATE THIS
    
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
        'cqc-projects'
    ],
    'theme' => [
        'color_primary' => '#0066CC',
        'color_secondary' => '#004499',
        'icon' => '☀️'
    ],
    'cashbook_columns' => [
    ],
    'dashboard_widgets' => [
    ],
];
