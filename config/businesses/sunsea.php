<?php

/**
 * Sunsea Travel Bureau - Business Configuration
 * Biro Wisata: Invoice, Penawaran, Kalkulasi Harga, Database Customer
 * 
 * Database Local  : adf_sunsea
 * Database Hosting: adfb2574_sunsea
 * 
 * Designed to be hostable independently - all modules are self-contained
 * under modules/sunsea/ with its own layout and design language.
 */
return [
    'business_id'   => 'sunsea',
    'name'          => 'Explore Karimunjawa',
    'business_type' => 'travel_bureau',
    'database'      => 'adf_sunsea',
    'logo'          => '', // Place logo in uploads/logos/sunsea.png

    'enabled_modules' => [
        'sunsea',       // Core: dashboard, customers, packages, quotations, invoices, calculator
        'cashbook',     // Kas harian
        'bills',        // Tagihan rutin
        'payroll',      // Penggajian
        'settings',
        'reports',
    ],

    // Custom design - elegant orange theme, completely different from other businesses
    'theme' => [
        'color_primary'   => '#C2410C',   // Terracotta / burnt orange
        'color_secondary' => '#7C2D12',   // Deep chestnut
        'color_accent'    => '#F59E0B',   // Warm gold accent
        'color_bg'        => '#FFF7ED',   // Light warm cream
        'icon'            => '🌊',
        'design_variant'  => 'sunsea',    // Triggers custom layout in modules
    ],

    // Sunsea-specific settings
    'sunsea' => [
        'currency'           => 'IDR',
        'currency_symbol'    => 'Rp',
        'default_tax_pct'    => 11,       // PPN 11%
        'invoice_prefix'     => 'SS-INV',
        'quotation_prefix'   => 'SS-QUO',
        'company_name'       => 'Explore Karimunjawa',
        'tagline'            => 'Your Trusted Travel Partner in Karimunjawa',
        'address'            => '', // filled in Settings
        'phone'              => '',
        'email'              => '',
        'website'            => '',
        'bank_name'          => '',
        'bank_account'       => '',
        'bank_holder'        => '',
    ],

    'dashboard_widgets' => [
        'show_active_quotations'  => true,
        'show_pending_invoices'   => true,
        'show_monthly_revenue'    => true,
        'show_top_packages'       => true,
        'show_customer_stats'     => true,
    ],
];
