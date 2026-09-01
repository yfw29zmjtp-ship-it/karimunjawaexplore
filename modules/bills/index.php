<?php

/**
 * MONTHLY BILLS MODULE - SIMPLE VERSION
 * Direct bill entry without templates
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/business_helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ../../login.php');
    exit;
}

$bizConfig = getActiveBusinessConfig();
$themeColor = $bizConfig['theme']['color_primary'] ?? '#0d1f3c';
$themeSecondary = $bizConfig['theme']['color_secondary'] ?? '#1e3a5c';

// Gudang Nasita monthly bill tab is only relevant for businesses that receive stock transfers from Gudang Nasita
$showGudangBillTab = in_array($bizConfig['business_id'] ?? '', ['bens-cafe', 'eaat-meet', 'narayana-hotel'], true);
// Bens Cafe / Eat & Meet have no car rental division, so Driver/Motor/Trip tabs don't apply to them
$hideDriverTabs = in_array($bizConfig['business_id'] ?? '', ['bens-cafe', 'eaat-meet'], true);

// Cash accounts for the "Bayar" (pay driver trip) modal - same source used by modules/cashbook/add.php
$cashAccounts = [];
try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $masterBusinessId = getMasterBusinessId();
    if ($masterBusinessId) {
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type FROM cash_accounts WHERE business_id = ? AND is_active = 1 AND account_type IN ('cash', 'bank', 'e-wallet', 'credit_card') ORDER BY account_type = 'cash' DESC, account_type = 'bank' DESC, account_name");
        $stmt->execute([$masterBusinessId]);
        $cashAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching cash accounts for bills page: " . $e->getMessage());
    $cashAccounts = [];
}

// Divisions & categories for the "Tambah Tagihan Manual" form - so the cashbook entry
// gets the correct division/category instead of always defaulting to "Biaya Operasional"
$billDivisions = [];
$billCategories = [];
try {
    $bizDb = Database::getInstance();
    $billDivisions = $bizDb->fetchAll(
        "SELECT id, division_name FROM divisions WHERE is_active = 1 AND division_type IN ('expense', 'both') ORDER BY division_name"
    );
    $billCategories = $bizDb->fetchAll(
        "SELECT id, category_name, division_id FROM categories WHERE category_type = 'expense' ORDER BY category_name"
    );
} catch (Exception $e) {
    error_log("Error fetching divisions/categories for bills page: " . $e->getMessage());
    $billDivisions = [];
    $billCategories = [];
}

$driverDropPartnerName = 'Bp. Moyong';
try {
    $settingsDb = Database::getInstance();
    $savedPartnerName = trim((string)($settingsDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'driver_drop_partner_name' LIMIT 1")['setting_value'] ?? ''));
    if ($savedPartnerName !== '') {
        $driverDropPartnerName = $savedPartnerName;
    }
} catch (Exception $e) {
    error_log('Error fetching driver partner name setting: ' . $e->getMessage());
}

$driverReceiptMeta = [
    'companyName' => 'Narayana Hotel',
    'companyTagline' => '',
    'companyAddress' => '',
    'companyPhone' => '',
    'companyEmail' => '',
    'companyWebsite' => '',
    'companyLogo' => '',
];

try {
    $settingsDb = Database::getInstance();
    $settingRows = $settingsDb->fetchAll("SELECT setting_key, setting_value FROM settings");
    $settingsMap = [];
    foreach ($settingRows as $row) {
        $settingsMap[$row['setting_key']] = $row['setting_value'];
    }

    foreach (['company_name', 'company_tagline', 'company_address', 'company_phone', 'company_email', 'company_website'] as $settingKey) {
        if (!empty($settingsMap[$settingKey])) {
            $metaKey = [
                'company_name' => 'companyName',
                'company_tagline' => 'companyTagline',
                'company_address' => 'companyAddress',
                'company_phone' => 'companyPhone',
                'company_email' => 'companyEmail',
                'company_website' => 'companyWebsite',
            ][$settingKey];
            $driverReceiptMeta[$metaKey] = $settingsMap[$settingKey];
        }
    }

    $activeBusinessId = (int)($_SESSION['business_id'] ?? 0);
    foreach (
        [
            'invoice_logo_' . $activeBusinessId,
            'company_logo_' . $activeBusinessId,
            'invoice_logo',
            'company_logo',
        ] as $logoKey
    ) {
        if (!empty($settingsMap[$logoKey])) {
            $driverReceiptMeta['companyLogo'] = $settingsMap[$logoKey];
            break;
        }
    }

    if ($activeBusinessId > 0) {
        $bizRow = $settingsDb->fetchOne("SELECT business_name, logo FROM businesses WHERE id = ? LIMIT 1", [$activeBusinessId]);
        if ($bizRow) {
            if ($driverReceiptMeta['companyName'] === 'Narayana Hotel' && !empty($bizRow['business_name'])) {
                $driverReceiptMeta['companyName'] = $bizRow['business_name'];
            }
            if ($driverReceiptMeta['companyLogo'] === '' && !empty($bizRow['logo'])) {
                $driverReceiptMeta['companyLogo'] = $bizRow['logo'];
            }
        }
    }
} catch (Exception $e) {
    error_log('Error preparing driver receipt metadata: ' . $e->getMessage());
}

include '../../includes/header.php';
?>

<style>
    :root {
        --navy: <?php echo htmlspecialchars($themeColor); ?>;
        --navy2: <?php echo htmlspecialchars($themeSecondary); ?>;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .main-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 14px 16px;
    }

    .page-header {
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid #e3e8f3;
        border-radius: 10px;
        background: linear-gradient(135deg, #fbfcff, #f3f7ff);
    }

    .page-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
        box-shadow: 0 5px 10px rgba(13, 31, 60, 0.22);
    }

    .page-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h1 {
        font-size: 16px;
        color: #1e293b;
        margin-bottom: 0;
        line-height: 1.2;
    }

    .page-header p {
        color: #64748b;
        font-size: 11px;
        margin-top: 1px;
    }

    .page-header-badges {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .ph-badge {
        padding: 5px 9px;
        border-radius: 999px;
        border: 1px solid #dce4f4;
        background: #fff;
        color: #415270;
        font-size: 10.5px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        padding: 10px;
    }

    .card h2 {
        font-size: 13px;
        color: #333;
        margin-bottom: 8px;
        border-bottom: 1px solid #d7def1;
        padding-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        font-family: inherit;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--navy);
        box-shadow: 0 0 0 3px rgba(13, 31, 60, 0.1);
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .btn-submit {
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 10px;
        transition: all 0.3s;
    }

    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(13, 31, 60, 0.35);
    }

    .alert {
        padding: 12px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-size: 14px;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .bill-row {
        background: #f3f5fb;
        padding: 9px 12px;
        border-radius: 6px;
        margin-bottom: 6px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border-left: 3px solid var(--navy);
        transition: background .15s;
    }

    .bill-row:hover {
        background: #e9edf7;
    }

    .bill-info h4 {
        font-size: 12.5px;
        color: #1a2540;
        margin-bottom: 3px;
        font-weight: 700;
    }

    .bill-info h4 small {
        color: #6b7690;
        font-weight: 500;
    }

    .bill-info p {
        font-size: 11px;
        color: #5a6478;
        font-weight: 500;
        margin: 1px 0;
    }

    .bill-amount {
        text-align: right;
    }

    .bill-amount .total {
        font-size: 13px;
        font-weight: 700;
        color: #1a2540;
    }

    .bill-amount .status {
        font-size: 10px;
        margin-top: 4px;
        padding: 2px 7px;
        border-radius: 3px;
        display: inline-block;
        font-weight: 700;
    }

    .status-paid {
        background: #d4edda;
        color: #155724;
    }

    .status-partial {
        background: #fff3cd;
        color: #856404;
    }

    .status-pending {
        background: #d1ecf1;
        color: #0c5460;
    }

    .btn-action {
        padding: 4px 9px;
        margin-left: 5px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 10.5px;
        font-weight: 600;
    }

    .btn-pay {
        background: #22c55e;
        color: white;
    }

    .btn-pay:hover {
        background: #16a34a;
    }

    .btn-edit {
        background: var(--navy);
        color: #fff;
    }

    .btn-edit:hover {
        background: var(--navy2);
    }

    .tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 8px;
        border-bottom: 0;
    }

    .tab-btn {
        padding: 7px 12px;
        border: 1px solid #e3e8f2;
        background: #f8faff;
        border-radius: 999px;
        cursor: pointer;
        font-size: 11.5px;
        font-weight: 700;
        color: #5b667c;
        transition: all .2s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .tab-btn:hover {
        border-color: #cdd8ee;
        background: #f1f6ff;
    }

    .tab-btn.active {
        color: #fff;
        border-color: var(--navy);
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        box-shadow: 0 5px 10px rgba(25, 57, 120, 0.22);
    }

    .bill-list {
        max-height: 600px;
        overflow-y: auto;
    }

    .category-tabs {
        display: flex;
        gap: 6px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .category-btn {
        flex: 1 1 160px;
        padding: 7px 10px;
        border: 1px solid #e2e6ee;
        background: #f7f8fb;
        border-radius: 999px;
        cursor: pointer;
        font-size: 11.5px;
        font-weight: 700;
        color: #4f5d77;
        transition: all .2s ease;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .category-btn:hover {
        background: #eef2fb;
        border-color: #d5ddee;
    }

    .category-btn.active {
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        border-color: var(--navy);
        color: #fff;
        box-shadow: 0 5px 11px rgba(25, 57, 120, 0.22);
    }

    .category-btn .ico,
    .tab-btn .ico,
    .pay-filter-btn .ico {
        font-size: 11px;
        opacity: .92;
    }

    .driver-recap-card {
        background: #fff;
        border: 1px solid #e2e6ee;
        border-radius: 8px;
        padding: 8px 10px;
        margin-bottom: 8px;
    }

    .driver-recap-card .dr-name {
        font-size: 12px;
        font-weight: 700;
        color: #1a2540;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .btn-print-recap {
        flex-shrink: 0;
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 700;
        border: 1px solid #cbd5f5;
        border-radius: 5px;
        background: #eef2ff;
        color: #3546a3;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-print-recap:hover {
        background: #dfe5fb;
    }

    .driver-recap-card .dr-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(95px, 1fr));
        gap: 5px;
        font-size: 10.5px;
        margin-bottom: 4px;
    }

    .driver-recap-card .dr-stat {
        background: #f7f8fb;
        border-radius: 6px;
        padding: 4px;
        text-align: center;
    }

    .driver-recap-card .dr-stat .v {
        font-size: 11px;
        font-weight: 800;
        color: #1a2540;
    }

    .driver-recap-card .dr-stat .l {
        color: #6b7690;
        font-size: 9px;
    }

    .driver-recap-card .dr-breakdown {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 5px;
        margin-bottom: 4px;
    }

    .driver-recap-card .dr-breakdown .v {
        font-size: 10.5px;
        font-weight: 700;
        color: #1a2540;
    }

    .driver-recap-card .dr-breakdown .l {
        color: #6b7690;
        font-size: 8.8px;
    }

    .driver-recap-card table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
        margin-top: 4px;
    }

    .driver-recap-card th {
        text-align: left;
        color: #6b7690;
        font-weight: 600;
        padding: 3px 2px;
        border-bottom: 1px solid #eef0f5;
    }

    .driver-recap-card td {
        padding: 3px 2px;
        border-bottom: 1px solid #f4f6fa;
        color: #333;
    }

    .pay-filter-bar {
        display: flex;
        gap: 6px;
        margin-bottom: 8px;
    }

    .driver-setting-bar {
        display: flex;
        gap: 6px;
        align-items: end;
        margin-bottom: 8px;
        padding: 7px 8px;
        border: 1px solid #e2e6ee;
        border-radius: 7px;
        background: #fbfcff;
    }

    .driver-setting-field {
        flex: 1;
    }

    .driver-setting-field label {
        display: block;
        font-size: 9px;
        font-weight: 700;
        color: #6b7280;
        margin-bottom: 2px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .driver-setting-field input {
        width: 100%;
        padding: 7px 9px;
        border: 1px solid #d8dfeb;
        border-radius: 6px;
        background: #fff;
        font-size: 10.5px;
        color: #1f2937;
    }

    .driver-setting-save {
        border: none;
        border-radius: 6px;
        padding: 7px 10px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        color: #fff;
        font-size: 10.5px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
    }

    .driver-setting-help {
        margin-top: 2px;
        font-size: 9px;
        color: #64748b;
    }

    .pay-filter-btn {
        flex: 1;
        padding: 7px 9px;
        border: 1px solid #e2e6ee;
        background: #f8faff;
        border-radius: 999px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 700;
        color: #5b667c;
        transition: all .2s ease;
        text-align: center;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .pay-filter-btn:hover {
        background: #eef3ff;
        border-color: #d4ddf1;
    }

    .pay-filter-btn.active {
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        border-color: var(--navy);
        color: #fff;
        box-shadow: 0 5px 10px rgba(25, 57, 120, 0.22);
    }

    .driver-recap-card .dr-paid-summary {
        display: flex;
        justify-content: space-between;
        font-size: 10px;
        color: #6b7690;
        margin: -1px 0 6px;
        padding: 0 2px;
    }

    .btn-trip-paid {
        color: #16794d;
        font-weight: 700;
        font-size: 10px;
        white-space: nowrap;
    }

    .btn-trip-pay {
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 700;
        border: none;
        border-radius: 4px;
        background: #22c55e;
        color: #fff;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-trip-pay:hover {
        background: #16a34a;
    }

    .btn-trip-edit {
        padding: 3px 7px;
        font-size: 10px;
        font-weight: 700;
        border: 1.5px solid #3b82f6;
        border-radius: 4px;
        background: #fff;
        color: #1d4ed8;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-trip-edit:hover {
        background: #eff6ff;
    }

    .btn-trip-receipt {
        padding: 3px 7px;
        font-size: 10px;
        font-weight: 700;
        border: 1.5px solid #7c3aed;
        border-radius: 4px;
        background: #faf5ff;
        color: #6d28d9;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-trip-receipt:hover {
        background: #f3e8ff;
    }

    /* PAY DRIVER TRIP MODAL */
    .dp-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(10, 15, 30, 0.55);
        z-index: 99999;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .dp-modal-overlay.open {
        display: flex;
    }

    .dp-modal {
        background: #fff;
        border-radius: 12px;
        padding: 20px 22px;
        width: 100%;
        max-width: 380px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    }

    .dp-modal h3 {
        margin: 0 0 14px;
        font-size: 15px;
        font-weight: 700;
        color: #1a2540;
    }

    .dp-summary {
        background: #f3f5fb;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 14px;
    }

    .dp-summary div {
        display: flex;
        justify-content: space-between;
        font-size: 12.5px;
        color: #4b5568;
        padding: 3px 0;
    }

    .dp-summary strong {
        color: #1a2540;
    }

    .dp-field {
        margin-bottom: 14px;
    }

    .dp-field label {
        display: block;
        font-size: 11.5px;
        font-weight: 700;
        color: #6b7690;
        margin-bottom: 6px;
    }

    .dp-field select {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #dfe3ee;
        border-radius: 7px;
        font-size: 13px;
        color: #1a2540;
        background: #fff;
    }

    .dp-method-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .dp-method-btn {
        padding: 9px 6px;
        font-size: 12.5px;
        font-weight: 600;
        border: 1.5px solid #dfe3ee;
        border-radius: 8px;
        background: #fff;
        color: #4b5568;
        cursor: pointer;
    }

    .dp-method-btn.active {
        border-color: var(--navy);
        background: var(--navy);
        color: #fff;
    }

    .dp-actions {
        display: flex;
        gap: 10px;
        margin-top: 6px;
    }

    .dp-btn-cancel,
    .dp-btn-confirm {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }

    .dp-btn-cancel {
        background: #eef0f5;
        color: #4b5568;
    }

    .dp-btn-confirm {
        background: #22c55e;
        color: #fff;
    }

    .dp-btn-confirm:hover {
        background: #16a34a;
    }

    .dp-btn-confirm:disabled {
        background: #9ad6b3;
        cursor: not-allowed;
    }

    .checkbox-group {
        display: flex;
        gap: 20px;
        margin-top: 10px;
    }

    .checkbox-group label {
        display: flex;
        align-items: center;
        cursor: pointer;
        margin-bottom: 0;
    }

    .checkbox-group input {
        width: auto;
        margin-right: 8px;
    }

    .bill-form-launch {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .bill-toolbar-info {
        font-size: 10px;
        color: #56627a;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        font-weight: 700;
    }

    .bill-toolbar-controls {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .bill-toolbar-month {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .bill-toolbar-month label {
        display: inline-block;
        font-size: 10.5px;
        font-weight: 700;
        color: #334155;
        margin-bottom: 0;
    }

    .bill-toolbar-month-input {
        width: 146px;
        padding: 6px 8px;
        border: 1px solid #d7dfef;
        border-radius: 7px;
        font-size: 11px;
        color: #1e293b;
        background: #fff;
    }

    .btn-open-bill-modal {
        width: auto;
        min-width: 154px;
        padding: 8px 10px;
        border: none;
        border-radius: 8px;
        background: linear-gradient(135deg, var(--navy), var(--navy2));
        color: #fff !important;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 6px 12px rgba(13, 31, 60, 0.24);
        transition: transform .2s ease, box-shadow .2s ease;
        text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25);
    }

    .btn-open-bill-modal * {
        color: #fff !important;
    }

    .btn-open-bill-modal:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 14px rgba(13, 31, 60, 0.26);
    }

    .bill-form-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1200;
        padding: 16px;
    }

    .bill-form-modal {
        background: #fff;
        width: min(760px, 100%);
        max-height: 92vh;
        overflow-y: auto;
        border-radius: 14px;
        box-shadow: 0 22px 48px rgba(15, 23, 42, 0.28);
        padding: 16px;
    }

    .bill-form-modal-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }

    .bill-form-modal-close {
        width: 34px;
        height: 34px;
        border: none;
        border-radius: 8px;
        background: #eef2f8;
        color: #334155;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
    }

    .bill-form-modal-close:hover {
        background: #e2e8f0;
    }

    body.bill-form-open {
        overflow: hidden;
    }

    @media (max-width: 768px) {
        .page-header {
            padding: 8px 10px;
        }

        .page-header p {
            display: none;
        }

        .page-header-badges {
            display: none;
        }

        .bill-form-launch {
            align-items: stretch;
        }

        .bill-toolbar-controls {
            width: 100%;
            align-items: stretch;
        }

        .bill-toolbar-month {
            flex: 1;
        }

        .btn-open-bill-modal {
            width: 100%;
        }

        .form-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-container">
    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon">🧾</div>
            <div>
                <h1>Tagihan</h1>
                <p>Ringkas, cepat, dan mudah dipantau</p>
            </div>
        </div>
        <div class="page-header-badges">
            <?php if (!$hideDriverTabs): ?>
                <span class="ph-badge">🚕 Driver</span>
                <span class="ph-badge">🧭 Trip</span>
            <?php endif; ?>
            <span class="ph-badge">🧾 Manual</span>
            <span class="ph-badge">🔁 Bulanan</span>
            <?php if ($showGudangBillTab): ?>
                <span class="ph-badge">📦 Gudang</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- CONTENT GRID -->
    <div class="content-grid">
        <!-- LIST TAGIHAN -->
        <div class="card">
            <h2>📋 Daftar Tagihan</h2>

            <div class="bill-form-launch">
                <div class="bill-toolbar-info"><span class="ico">⚡</span>Ringkasan cepat</div>
                <div class="bill-toolbar-controls">
                    <div class="bill-toolbar-month">
                        <label>📅 Bulan</label>
                        <input
                            type="month"
                            id="filterMonth"
                            onchange="onMonthChange()"
                            class="bill-toolbar-month-input">
                    </div>
                    <button type="button" class="btn-open-bill-modal" onclick="openBillFormModal()">＋ Tambah Tagihan</button>
                </div>
            </div>

            <div class="category-tabs">
                <?php if (!$hideDriverTabs): ?>
                    <button class="category-btn active" data-cat="driver" onclick="switchCategory('driver')"><span class="ico">🚕</span> Driver</button>
                    <button class="category-btn" data-cat="motor" onclick="switchCategory('motor')"><span class="ico">🏍️</span> Motor</button>
                    <button class="category-btn" data-cat="trip" onclick="switchCategory('trip')"><span class="ico">🧭</span> Trip</button>
                <?php endif; ?>
                <button class="category-btn" data-cat="manual" onclick="switchCategory('manual')"><span class="ico">🧾</span> Manual</button>
                <button class="category-btn" data-cat="bulanan" onclick="switchCategory('bulanan')"><span class="ico">🔁</span> Bulanan</button>
                <?php if ($showGudangBillTab): ?>
                    <button class="category-btn <?php echo $hideDriverTabs ? 'active' : ''; ?>" data-cat="gudang" onclick="switchCategory('gudang')"><span class="ico">📦</span> Gudang</button>
                <?php endif; ?>
            </div>

            <div id="manualBillsWrap">
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('all', event)"><span class="ico">📋</span>Semua</button>
                    <button class="tab-btn" onclick="switchTab('pending', event)"><span class="ico">⏳</span>Pending</button>
                    <button class="tab-btn" onclick="switchTab('partial', event)"><span class="ico">🪙</span>Cicilan</button>
                    <button class="tab-btn" onclick="switchTab('paid', event)"><span class="ico">✅</span>Lunas</button>
                </div>

                <div id="billsList" class="bill-list">
                    <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
                </div>
            </div>

            <div id="driverRecapSection" class="bill-list" style="display:none;">
                <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
            </div>

            <div id="motorRecapSection" class="bill-list" style="display:none;">
                <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
            </div>

            <?php if ($showGudangBillTab): ?>
                <div id="gudangRecapSection" class="bill-list" style="display:none;">
                    <p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ADD BILL MODAL -->
<div id="billFormModalOverlay" class="bill-form-modal-overlay" onclick="if(event.target===this)closeBillFormModal()">
    <div class="bill-form-modal">
        <div class="bill-form-modal-head">
            <h2>➕ Tambah Tagihan Baru</h2>
            <button type="button" class="bill-form-modal-close" onclick="closeBillFormModal()">&times;</button>
        </div>

        <div id="formMessage"></div>

        <form id="billForm" onsubmit="submitBill(event)">
            <div class="form-group">
                <label for="billName">Nama Tagihan *</label>
                <input
                    type="text"
                    id="billName"
                    name="bill_name"
                    placeholder="Contoh: Listrik, Air, Gaji, Sewa"
                    required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="billMonth">Bulan *</label>
                    <input
                        type="month"
                        id="billMonth"
                        name="bill_month"
                        required>
                </div>
                <div class="form-group">
                    <label for="amount">Jumlah (Rp) *</label>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        placeholder="500000"
                        min="0"
                        step="1000"
                        required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="dueDate">Tanggal Jatuh Tempo</label>
                    <input
                        type="date"
                        id="dueDate"
                        name="due_date">
                </div>
                <div class="form-group">
                    <label for="divisionId">Divisi</label>
                    <select id="divisionId" name="division_id" onchange="filterBillCategories()">
                        <option value="">-- Pilih Divisi --</option>
                        <?php foreach ($billDivisions as $div): ?>
                            <option value="<?php echo (int)$div['id']; ?>"><?php echo htmlspecialchars($div['division_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="category">Kategori</label>
                <select id="category" name="category_id">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($billCategories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>" data-division="<?php echo (int)($cat['division_id'] ?? 0); ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="notes">Catatan</label>
                <textarea
                    id="notes"
                    name="notes"
                    rows="3"
                    placeholder="Contoh: Tagihan bulanan dari PLN..."></textarea>
            </div>

            <div class="checkbox-group">
                <label>
                    <input
                        type="checkbox"
                        id="isRecurring"
                        name="is_recurring"
                        value="1">
                    Tagihan Berulang (Bulanan)
                </label>
            </div>

            <button type="submit" class="btn-submit">💾 Simpan Tagihan</button>
        </form>
    </div>
</div>

<!-- PAY DRIVER TRIP MODAL -->
<div id="payTripModalOverlay" class="dp-modal-overlay" onclick="if(event.target===this)closePayTripModal()">
    <div class="dp-modal">
        <h3>💸 Bayar Trip Driver</h3>
        <div class="dp-summary">
            <div><span>Driver / Mitra</span><strong id="ptDriverName">-</strong></div>
            <div><span>Jumlah</span><strong id="ptAmount">Rp 0</strong></div>
        </div>
        <div class="dp-field">
            <label>Metode Pembayaran</label>
            <div class="dp-method-grid">
                <button type="button" class="dp-method-btn active" data-method="cash" onclick="selectPayMethod('cash')">💵 Cash</button>
                <button type="button" class="dp-method-btn" data-method="transfer" onclick="selectPayMethod('transfer')">🏦 Transfer</button>
                <button type="button" class="dp-method-btn" data-method="card" onclick="selectPayMethod('card')">💳 Kartu</button>
                <button type="button" class="dp-method-btn" data-method="other" onclick="selectPayMethod('other')">⚙️ Lainnya</button>
            </div>
        </div>
        <div class="dp-field">
            <label>Sumber Dana / Rekening</label>
            <select id="ptCashAccount">
                <?php foreach ($cashAccounts as $acc): ?>
                    <option value="<?php echo (int)$acc['id']; ?>"><?php echo htmlspecialchars($acc['account_name']); ?> (<?php echo htmlspecialchars($acc['account_type']); ?>)</option>
                <?php endforeach; ?>
                <?php if (empty($cashAccounts)): ?>
                    <option value="1">Kas Tunai (default)</option>
                <?php endif; ?>
            </select>
        </div>
        <div class="dp-actions">
            <button type="button" class="dp-btn-cancel" onclick="closePayTripModal()">Batal</button>
            <button type="button" class="dp-btn-confirm" id="ptConfirmBtn" onclick="confirmPayDriverTrip()">✅ Bayar & Catat ke Kas</button>
        </div>
    </div>
</div>

<!-- EDIT DRIVER TRIP AMOUNT MODAL -->
<div id="editTripModalOverlay" class="dp-modal-overlay" onclick="if(event.target===this)closeEditTripModal()">
    <div class="dp-modal">
        <h3>✏️ Edit Nominal Trip</h3>
        <div class="dp-summary">
            <div><span>Driver / Mitra</span><strong id="etDriverName">-</strong></div>
            <div><span>Trip</span><strong id="etTripLabel" style="font-size:11px;text-align:right;max-width:55%;">-</strong></div>
        </div>
        <div class="dp-field">
            <label>Total Tarif (Rp)</label>
            <input type="number" id="etTotalPrice" min="0" step="1000"
                style="width:100%;padding:8px 10px;border:1px solid #dfe3ee;border-radius:7px;font-size:13px;color:#1a2540;box-sizing:border-box;"
                oninput="updateEditCompanyAmount()">
        </div>
        <div class="dp-field">
            <label>Bagian Driver / Pemilik (Rp) <span id="etSuggestLink" style="display:none;font-size:11px;color:#2563eb;cursor:pointer;font-weight:normal" onclick="applyEtSuggest()"></span></label>
            <input type="number" id="etOwnerAmount" min="0" step="1000"
                style="width:100%;padding:8px 10px;border:1px solid #dfe3ee;border-radius:7px;font-size:13px;color:#1a2540;box-sizing:border-box;"
                oninput="updateEditCompanyAmount()">
        </div>
        <div class="dp-summary">
            <div><span>Bagian Perusahaan / Hotel (otomatis)</span><strong id="etCompanyAmount" style="color:#1d4ed8;">Rp 0</strong></div>
        </div>
        <div class="dp-actions">
            <button type="button" class="dp-btn-cancel" onclick="closeEditTripModal()">Batal</button>
            <button type="button" class="dp-btn-confirm" id="etConfirmBtn" onclick="confirmEditDriverTrip()" style="background:#1d4ed8;">💾 Simpan</button>
        </div>
    </div>
</div>

<script>
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const ACTIVE_BUSINESS = '<?php echo $_SESSION['active_business_id'] ?? 'narayana-hotel'; ?>';
    let DRIVER_DROP_PARTNER_NAME = <?php echo json_encode($driverDropPartnerName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    const DRIVER_RECEIPT_META = <?php echo json_encode($driverReceiptMeta, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    // Catalog data for driver split suggestions in edit modal
    window.CATALOG_DATA_BILLS = <?php
                                try {
                                    $catStmt = $pdo->prepare("SELECT service_type, item_name, default_price, driver_rate FROM hotel_service_catalog WHERE business_id=? AND is_active=1 ORDER BY sort_order, item_name");
                                    $catStmt->execute([$businessId]);
                                    $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);
                                    $catByType = [];
                                    foreach ($catRows as $cr) {
                                        $catByType[$cr['service_type']][] = ['name' => $cr['item_name'], 'price' => (float)$cr['default_price'], 'driver_rate' => (float)($cr['driver_rate'] ?? 0)];
                                    }
                                    echo json_encode($catByType, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?: '{}';
                                } catch (\Throwable $ex) {
                                    echo '{}';
                                }
                                ?>;

    // Set default month to current month
    document.getElementById('billMonth').valueAsDate = new Date();
    document.getElementById('filterMonth').valueAsDate = new Date();

    function openBillFormModal() {
        const overlay = document.getElementById('billFormModalOverlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.classList.add('bill-form-open');
        const firstInput = document.getElementById('billName');
        if (firstInput) firstInput.focus();
    }

    function closeBillFormModal() {
        const overlay = document.getElementById('billFormModalOverlay');
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.classList.remove('bill-form-open');
    }

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeBillFormModal();
        }
    });

    let currentTab = 'all';
    let currentCategory = <?php echo json_encode($hideDriverTabs ? 'gudang' : 'driver'); ?>;

    // Reload whichever category is currently active when the month filter changes
    function onMonthChange() {
        if (currentCategory === 'driver' || currentCategory === 'trip') {
            loadDriverRecap();
        } else if (currentCategory === 'motor') {
            loadMotorRecap();
        } else if (currentCategory === 'gudang') {
            loadGudangRecap();
        } else {
            loadBills();
        }
    }

    // SWITCH CATEGORY (Driver / Motor / Trip / Manual / Bulanan / Gudang)
    function switchCategory(cat) {
        currentCategory = cat;
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.cat === cat);
        });

        const isRecap = cat === 'driver' || cat === 'motor' || cat === 'trip';
        const isMotor = cat === 'motor';
        const isDriver = cat === 'driver' || cat === 'trip';
        const isGudang = cat === 'gudang';

        document.getElementById('manualBillsWrap').style.display = (isRecap || isGudang) ? 'none' : 'block';
        document.getElementById('driverRecapSection').style.display = isDriver ? 'block' : 'none';
        document.getElementById('motorRecapSection').style.display = isMotor ? 'block' : 'none';
        const gudangSection = document.getElementById('gudangRecapSection');
        if (gudangSection) {
            gudangSection.style.display = isGudang ? 'block' : 'none';
        }

        if (isDriver) {
            loadDriverRecap();
        } else if (isMotor) {
            loadMotorRecap();
        } else if (isGudang) {
            loadGudangRecap();
        } else {
            loadBills();
        }
    }

    // SHOW ONLY CATEGORIES BELONGING TO THE SELECTED DIVISION
    function filterBillCategories() {
        const divisionId = document.getElementById('divisionId').value;
        const categorySelect = document.getElementById('category');
        const options = categorySelect.querySelectorAll('option[data-division]');

        options.forEach(opt => {
            const matches = !divisionId || opt.getAttribute('data-division') === divisionId;
            opt.hidden = !matches;
        });

        // Reset selected category if it no longer belongs to the chosen division
        const selectedOpt = categorySelect.selectedOptions[0];
        if (selectedOpt && selectedOpt.hidden) {
            categorySelect.value = '';
        }
    }

    // SUBMIT FORM
    async function submitBill(e) {
        e.preventDefault();

        const formData = new FormData(document.getElementById('billForm'));
        formData.append('business', ACTIVE_BUSINESS);
        try {
            const response = await fetch(BASE_URL + '/api/add-monthly-bill.php', {
                method: 'POST',
                body: formData,
                credentials: 'include' // Include cookies for authentication
            });

            const result = await response.json();
            const msgEl = document.getElementById('formMessage');

            if (result.success) {
                msgEl.innerHTML = `<div class="alert alert-success">✅ ${result.message} (${result.bill_code})</div>`;
                document.getElementById('billForm').reset();
                document.getElementById('billMonth').valueAsDate = new Date();
                filterBillCategories();
                setTimeout(() => closeBillFormModal(), 900);

                setTimeout(() => loadBills(), 1000);
            } else {
                msgEl.innerHTML = `<div class="alert alert-error">❌ ${result.message}</div>`;
            }
        } catch (error) {
            document.getElementById('formMessage').innerHTML =
                `<div class="alert alert-error">❌ Error: ${error.message}</div>`;
        }
    }

    // LOAD BILLS LIST
    async function loadBills() {
        const month = document.getElementById('filterMonth').value;
        const listEl = document.getElementById('billsList');

        if (!month) {
            listEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        try {
            const url = BASE_URL + `/api/get-monthly-bills.php?month=${month}&limit=50`;
            console.log('[Bills] Fetching from:', url);
            console.log('[Bills] Active business:', ACTIVE_BUSINESS);

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include' // Include cookies for session
            });

            console.log('[Bills] Response status:', response.status);
            console.log('[Bills] Response headers:', response.headers);

            if (!response.ok) {
                const errorText = await response.text();
                console.error('[Bills] Error response:', errorText);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            console.log('[Bills] Data loaded successfully:', result);

            if (!result.success) {
                listEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            if (!result.bills || result.bills.length === 0) {
                listEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan bulan ini</p>';
                return;
            }

            // Filter by category (manual = one-time entries, bulanan = recurring), then by status tab
            let filtered = result.bills.filter(b => {
                if (currentCategory === 'bulanan') return b.is_recurring === 1;
                return b.source_type !== 'driver_trip' && b.is_recurring !== 1;
            });
            if (currentTab !== 'all') {
                filtered = filtered.filter(b => b.status === currentTab);
            }

            if (filtered.length === 0) {
                listEl.innerHTML = `<p style="color: #999; text-align: center; padding: 40px;">Tidak ada tagihan dengan status ini</p>`;
                return;
            }

            let html = '';
            filtered.forEach(bill => {
                const statusClass = `status-${bill.status}`;
                const progress = bill.amount > 0 ? Math.round((bill.paid_amount / bill.amount) * 100) : 0;

                html += `
                <div class="bill-row">
                    <div class="bill-info">
                        <h4>${bill.bill_name} <small>(${bill.bill_code})</small></h4>
                        <p>${bill.category_name || 'Umum'} &middot; Rp ${formatNumber(bill.paid_amount)} / Rp ${formatNumber(bill.amount)}</p>
                        <div style="margin-top: 4px; background: #dfe4f0; height: 4px; border-radius: 3px; overflow: hidden; width: 100%;">
                            <div style="background: var(--navy); height: 100%; width: ${progress}%;"></div>
                        </div>
                    </div>
                    <div style="text-align: right; white-space: nowrap;">
                        <div class="bill-amount">
                            <div class="total">Rp ${formatNumber(bill.amount)}</div>
                            <span class="status ${statusClass}">${bill.status.toUpperCase()}</span>
                        </div>
                        <div style="margin-top: 6px;">
                            <button onclick="editBill(${bill.id})" class="btn-action btn-edit">Edit</button>
                            <button onclick="openPayment(${bill.id}, '${bill.bill_name}', ${bill.amount}, ${bill.paid_amount})" class="btn-action btn-pay">Bayar</button>
                        </div>
                    </div>
                </div>
            `;
            });

            listEl.innerHTML = html;
        } catch (error) {
            console.error('[Bills] Error:', error);
            listEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    // LOAD DRIVER/MITRA RECAP (Tagihan Driver tab)
    let lastDriverRecap = [];
    let driverPayFilter = 'all'; // all | unpaid | paid

    async function loadDriverRecap() {
        const month = document.getElementById('filterMonth').value;
        const recapEl = document.getElementById('driverRecapSection');

        if (!month) {
            recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>';

        try {
            const response = await fetch(BASE_URL + `/api/get-driver-recap.php?month=${month}`, {
                method: 'GET',
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (!result.success) {
                recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            lastDriverRecap = result.recap || [];
            renderDriverRecap();
        } catch (error) {
            console.error('[DriverRecap] Error:', error);
            recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    // RENDER DRIVER/MITRA RECAP (uses lastDriverRecap + driverPayFilter, no re-fetch)
    function renderDriverRecap() {
        const recapEl = document.getElementById('driverRecapSection');
        const isTripTab = currentCategory === 'trip';
        const safeDriverPartnerName = String(DRIVER_DROP_PARTNER_NAME || 'Bp. Moyong')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const settingsBar = isTripTab ? '' : `
            <div class="driver-setting-bar">
                <div class="driver-setting-field">
                    <label for="driverPartnerNameInput">Nama Mitra Drop Default</label>
                    <input type="text" id="driverPartnerNameInput" value="${safeDriverPartnerName}" placeholder="Contoh: Bp. Moyong">
                    <div class="driver-setting-help">Dipakai untuk Airport/Harbor Drop yang tidak punya nama mitra spesifik.</div>
                </div>
                <button type="button" class="driver-setting-save" onclick="saveDriverPartnerName()">Simpan Nama</button>
            </div>`;

        if (!lastDriverRecap || lastDriverRecap.length === 0) {
            recapEl.innerHTML = settingsBar + `<p style="color: #999; text-align: center; padding: 40px;">Belum ada ${isTripTab ? 'tagihan trip' : 'tagihan driver/mitra'} bulan ini</p>`;
            return;
        }

        const typeLabel = {
            car_rental: 'Rental Mobil',
            airport_drop: 'Airport Drop',
            harbor_drop: 'Harbor Drop',
            narayana_trip: 'Narayana Trip'
        };

        let html = settingsBar + `
            <div class="pay-filter-bar">
                <button class="pay-filter-btn ${driverPayFilter === 'all' ? 'active' : ''}" onclick="setDriverPayFilter('all')"><span class="ico">📋</span>Semua Trip</button>
                <button class="pay-filter-btn ${driverPayFilter === 'unpaid' ? 'active' : ''}" onclick="setDriverPayFilter('unpaid')"><span class="ico">⏳</span>Belum Dibayar</button>
                <button class="pay-filter-btn ${driverPayFilter === 'paid' ? 'active' : ''}" onclick="setDriverPayFilter('paid')"><span class="ico">✅</span>Sudah Dibayar</button>
            </div>
        `;

        lastDriverRecap.forEach((dr, idx) => {
            const baseRows = (dr.detail_rows || []).filter(d => {
                const isTripService = String(d.service_type || '') === 'narayana_trip';
                return isTripTab ? isTripService : !isTripService;
            });

            if (baseRows.length === 0) return;

            const rows = baseRows.filter(d => {
                if (driverPayFilter === 'unpaid') return !d.paid;
                if (driverPayFilter === 'paid') return d.paid;
                return true;
            });

            if (rows.length === 0 && driverPayFilter !== 'all') return;

            const scopedTotalRevenue = baseRows.reduce((sum, r) => sum + (parseFloat(r.total_price) || 0), 0);
            const scopedOwnerTotal = baseRows.reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedPaidTotal = baseRows.filter(r => r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedUnpaidTotal = baseRows.filter(r => !r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedPaidTrips = baseRows.filter(r => r.paid).length;
            const scopedUnpaidTrips = baseRows.length - scopedPaidTrips;
            const scopedHotelTotal = Math.max(0, scopedTotalRevenue - scopedOwnerTotal);
            const scopedAvgPct = scopedTotalRevenue > 0 ? Math.round((scopedOwnerTotal / scopedTotalRevenue) * 100) : 0;
            const scopedNarayanaTrips = baseRows.filter(r => r.service_type === 'narayana_trip').length;

            const driverNameSafe = (dr.partner_owner || 'Tanpa Pemilik').replace(/'/g, "\\'");

            const detailRows = rows.slice(0, 15).map(d => `
                <tr>
                    <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                    <td>${typeLabel[d.service_type] || d.service_type}<br><span style="color:#94a0b8;">${d.label || ''}</span></td>
                    <td>${d.guest_name || '—'}${d.room_number ? '<br><span style="color:#94a0b8;">Kamar ' + d.room_number + '</span>' : ''}</td>
                    <td style="text-align:right;font-weight:700;">Rp ${formatNumber(d.total_price)}</td>
                    <td style="text-align:right;font-weight:700;color:#16794d;">Rp ${formatNumber(d.owner_amount)}</td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:wrap;">
                            <button class="btn-trip-edit" onclick="editDriverTripAmount(${d.trip_id}, '${d.source || 'trip'}', ${d.total_price}, ${d.owner_amount}, '${driverNameSafe}', '${typeLabel[d.service_type] || d.service_type}', '${d.service_type}')">✏️ Edit</button>
                            ${d.paid
                                ? `<button class="btn-trip-receipt" onclick="printDriverTripReceipt(${idx}, ${d.trip_id}, '${d.source || 'trip'}')">🖨️ Cetak</button><span class="btn-trip-paid">✅ Lunas</span>`
                                : `<button class="btn-trip-pay" onclick="payDriverTrip(${d.trip_id}, '${d.service_type}', ${d.owner_amount}, '${driverNameSafe}', '${d.source || 'trip'}')">Bayar</button>`
                            }
                        </div>
                    </td>
                </tr>`).join('');

            html += `
                <div class="driver-recap-card">
                    <div class="dr-name">
                        <span>${isTripTab ? '🧭' : '🤝'} ${dr.partner_owner || 'Tanpa Pemilik'}${dr.owner_phone ? ' <span style="font-weight:400;color:#6b7690;font-size:11px;">&middot; ' + dr.owner_phone + '</span>' : ''}</span>
                        <button class="btn-print-recap" onclick="printDriverRecap(${idx})">🖨️ Cetak Rekap</button>
                    </div>
                    <div class="dr-stats">
                        ${isTripTab 
                            ? `<div class="dr-stat"><div class="v">${baseRows.length}</div><div class="l">Narayana Trip</div></div>`
                            : `
                                <div class="dr-stat"><div class="v">${baseRows.filter(r => r.service_type === 'car_rental').length}</div><div class="l">🚗 Rental Mobil</div></div>
                                <div class="dr-stat"><div class="v">${baseRows.filter(r => r.service_type === 'airport_drop').length}</div><div class="l">✈️ Airport Drop</div></div>
                                <div class="dr-stat"><div class="v">${baseRows.filter(r => r.service_type === 'harbor_drop').length}</div><div class="l">⚓ Harbor Drop</div></div>
                            `}
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedTotalRevenue)}</div><div class="l">Total Revenue</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedOwnerTotal)}</div><div class="l">Bagian Pemilik (${scopedAvgPct}%)</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedHotelTotal)}</div><div class="l">Komisi Hotel</div></div>
                    </div>
                    <div class="dr-paid-summary">
                        <span>✅ Sudah Dibayar: <strong style="color:#16794d;">Rp ${formatNumber(scopedPaidTotal)}</strong> (${scopedPaidTrips} trip)</span>
                        <span>⏳ Belum Dibayar: <strong style="color:#d97706;">Rp ${formatNumber(scopedUnpaidTotal)}</strong> (${scopedUnpaidTrips} trip)</span>
                    </div>
                    ${detailRows ? `
                    <div style="font-size:11px;font-weight:700;color:#475569;margin-top:8px;">Detail Transaksi</div>
                    <table>
                        <thead><tr><th>Tanggal</th><th>Jenis</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Pemilik</th><th style="text-align:right;">Aksi</th></tr></thead>
                        <tbody>${detailRows}</tbody>
                    </table>` : '<p style="color:#999;font-size:11px;text-align:center;padding:8px;">Tidak ada trip dengan filter ini</p>'}
                </div>`;
        });

        recapEl.innerHTML = html;
    }

    let lastMotorRecap = [];
    let motorPayFilter = 'all';

    function setMotorPayFilter(filter) {
        motorPayFilter = filter;
        renderMotorRecap();
    }

    async function loadMotorRecap() {
        const month = document.getElementById('filterMonth').value;
        const recapEl = document.getElementById('motorRecapSection');

        if (!month) {
            recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>';

        try {
            const response = await fetch(BASE_URL + `/api/get-motor-recap.php?month=${month}`, {
                method: 'GET',
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (!result.success) {
                recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            lastMotorRecap = result.recap || [];
            renderMotorRecap();
        } catch (error) {
            console.error('[MotorRecap] Error:', error);
            recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    function renderMotorRecap() {
        const recapEl = document.getElementById('motorRecapSection');

        if (!lastMotorRecap || lastMotorRecap.length === 0) {
            recapEl.innerHTML = `<p style="color: #999; text-align: center; padding: 40px;">Belum ada tagihan motor bulan ini</p>`;
            return;
        }

        let html = `
            <div class="pay-filter-bar">
                <button class="pay-filter-btn ${motorPayFilter === 'all' ? 'active' : ''}" onclick="setMotorPayFilter('all')"><span class="ico">📋</span>Semua Rental</button>
                <button class="pay-filter-btn ${motorPayFilter === 'unpaid' ? 'active' : ''}" onclick="setMotorPayFilter('unpaid')"><span class="ico">⏳</span>Belum Dibayar</button>
                <button class="pay-filter-btn ${motorPayFilter === 'paid' ? 'active' : ''}" onclick="setMotorPayFilter('paid')"><span class="ico">✅</span>Sudah Dibayar</button>
            </div>
        `;

        lastMotorRecap.forEach((motor, idx) => {
            const baseRows = motor.detail_rows || [];

            if (baseRows.length === 0) return;

            const rows = baseRows.filter(d => {
                if (motorPayFilter === 'unpaid') return !d.paid;
                if (motorPayFilter === 'paid') return d.paid;
                return true;
            });

            if (rows.length === 0 && motorPayFilter !== 'all') return;

            const scopedTotalRevenue = baseRows.reduce((sum, r) => sum + (parseFloat(r.total_price) || 0), 0);
            const scopedOwnerTotal = baseRows.reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedPaidTotal = baseRows.filter(r => r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedUnpaidTotal = baseRows.filter(r => !r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
            const scopedPaidTrips = baseRows.filter(r => r.paid).length;
            const scopedUnpaidTrips = baseRows.length - scopedPaidTrips;
            const scopedHotelTotal = Math.max(0, scopedTotalRevenue - scopedOwnerTotal);
            const scopedAvgPct = scopedTotalRevenue > 0 ? Math.round((scopedOwnerTotal / scopedTotalRevenue) * 100) : 0;

            const motorNameSafe = (motor.partner_owner || 'Tanpa Pemilik').replace(/'/g, "\\'");

            const detailRows = rows.slice(0, 15).map(d => `
                <tr>
                    <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                    <td>${d.motor_name || '—'}<br><span style="color:#94a0b8;">${d.plate_number || ''}</span></td>
                    <td>${d.guest_name || '—'}${d.room_number ? '<br><span style="color:#94a0b8;">Kamar ' + d.room_number + '</span>' : ''}</td>
                    <td style="text-align:right;font-weight:700;">Rp ${formatNumber(d.total_price)}</td>
                    <td style="text-align:right;font-weight:700;color:#16794d;">Rp ${formatNumber(d.owner_amount)}</td>
                    <td style="text-align:right;">
                        <div style="display:flex;gap:4px;justify-content:flex-end;align-items:center;flex-wrap:wrap;">
                            <button class="btn-trip-edit" onclick="editMotorRentalAmount(${d.rental_id}, ${d.total_price}, ${d.owner_amount}, '${motorNameSafe}')">✏️ Edit</button>
                            <button class="btn-trip-edit" onclick="toggleMotorPartnerPaidStatus(${d.rental_id}, ${d.paid ? 0 : 1})">${d.paid ? '↩️ Set Belum' : '✅ Set Lunas'}</button>
                            ${d.paid
                                ? `<span class="btn-trip-paid">✅ Lunas</span>`
                                : `<button class="btn-trip-pay" onclick="payMotorRental(${d.rental_id}, ${d.owner_amount}, '${motorNameSafe}')">Bayar</button>`
                            }
                        </div>
                    </td>
                </tr>`).join('');

            html += `
                <div class="driver-recap-card">
                    <div class="dr-name">
                        <span>🏍️ ${motor.partner_owner || 'Tanpa Pemilik'}${motor.owner_phone ? ' <span style="font-weight:400;color:#6b7690;font-size:11px;">&middot; ' + motor.owner_phone + '</span>' : ''}</span>
                        <button class="btn-print-recap" onclick="printMotorRecap(${idx})">🖨️ Cetak Rekap</button>
                    </div>
                    <div class="dr-stats">
                        <div class="dr-stat"><div class="v">${baseRows.length}</div><div class="l">🏍️ Total Rental</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedTotalRevenue)}</div><div class="l">Total Revenue</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedOwnerTotal)}</div><div class="l">Bagian Mitra (${scopedAvgPct}%)</div></div>
                        <div class="dr-stat"><div class="v">Rp ${formatNumber(scopedHotelTotal)}</div><div class="l">Komisi Hotel</div></div>
                    </div>
                    <div class="dr-paid-summary">
                        <span>✅ Sudah Dibayar: <strong style="color:#16794d;">Rp ${formatNumber(scopedPaidTotal)}</strong> (${scopedPaidTrips} rental)</span>
                        <span>⏳ Belum Dibayar: <strong style="color:#d97706;">Rp ${formatNumber(scopedUnpaidTotal)}</strong> (${scopedUnpaidTrips} rental)</span>
                    </div>
                    ${detailRows ? `
                    <div style="font-size:11px;font-weight:700;color:#475569;margin-top:8px;">Detail Transaksi</div>
                    <table>
                        <thead><tr><th>Tanggal</th><th>Motor</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Mitra</th><th style="text-align:right;">Aksi</th></tr></thead>
                        <tbody>${detailRows}</tbody>
                    </table>` : '<p style="color:#999;font-size:11px;text-align:center;padding:8px;">Tidak ada rental dengan filter ini</p>'}
                </div>`;
        });

        recapEl.innerHTML = html;
    }

    // TAGIHAN GUDANG NASITA (read-only recap of this business' own monthly bill)
    async function loadGudangRecap() {
        const month = document.getElementById('filterMonth').value;
        const recapEl = document.getElementById('gudangRecapSection');
        if (!recapEl) return;

        if (!month) {
            recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px;">Pilih bulan terlebih dahulu</p>';
            return;
        }

        recapEl.innerHTML = '<p style="color: #999; text-align: center; padding: 40px 20px;">Loading...</p>';

        try {
            const response = await fetch(BASE_URL + `/api/get-gudang-bill-recap.php?month=${month}`, {
                method: 'GET',
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (!result.success) {
                recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">Error: ${result.message}</p>`;
                return;
            }

            const r = result.recap;
            const paidBadge = r.is_paid ?
                `<span style="background:#d1fae5;color:#065f46;font-size:0.7rem;font-weight:700;padding:2px 10px;border-radius:999px;">✅ Lunas${r.paid_at ? ' &middot; ' + r.paid_at : ''}</span>` :
                `<span style="background:#fef3c7;color:#92400e;font-size:0.7rem;font-weight:700;padding:2px 10px;border-radius:999px;">⏳ Belum Dibayar</span>`;

            recapEl.innerHTML = `
                <div class="driver-recap-card">
                    <div class="dr-name">
                        <span>📦 Tagihan Gudang Nasita</span>
                        ${paidBadge}
                    </div>
                    <div style="font-size:0.85rem;color:#475569;display:flex;justify-content:space-between;margin:10px 0 6px;">
                        <span>Transfer bulan ini (${r.transfer_count}x, ${Number(r.transfer_qty).toFixed(2)} qty)</span>
                        <span style="font-weight:700;">Rp ${formatNumber(r.transfer_nilai)}</span>
                    </div>
                    <div style="font-size:0.85rem;color:#475569;display:flex;justify-content:space-between;margin-bottom:10px;">
                        <span>Share TKBM bulan ini</span>
                        <span style="font-weight:700;">Rp ${formatNumber(r.tkbm_share)}</span>
                    </div>
                    <div style="border-top:1px dashed #e2e8f0;padding-top:10px;display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:700;">Total Tagihan Bulan Ini</span>
                        <span style="font-size:1.05rem;font-weight:800;color:#0f9d6a;">Rp ${formatNumber(r.total)}</span>
                    </div>
                    ${r.is_paid ? '' : '<button type="button" class="btn btn-success" style="width:100%;margin-top:12px;" onclick="bayarTagihanGudang()">💰 Bayar Tagihan</button>'}
                </div>`;
        } catch (error) {
            console.error('[GudangRecap] Error:', error);
            recapEl.innerHTML = `<p style="color: #d32f2f; text-align: center; padding: 20px;">❌ Error: ${error.message}</p>`;
        }
    }

    async function bayarTagihanGudang() {
        const month = document.getElementById('filterMonth').value;
        if (!month) return;

        if (!confirm('Konfirmasi bayar Tagihan Gudang Nasita bulan ini?\n\nJumlah akan dipotong dari rekening bank utama dan dicatat sebagai pengeluaran di Buku Kas Besar (kategori "Bayar Tagihan Gudang Nasita").')) {
            return;
        }

        try {
            const response = await fetch(BASE_URL + '/api/pay-gudang-bill.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'month=' + encodeURIComponent(month)
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (result.success) {
                alert(`✅ ${result.message}`);
                loadGudangRecap();
            } else {
                alert(`❌ ${result.message}`);
            }
        } catch (error) {
            alert(`❌ Error: ${error.message}`);
        }
    }


    function payMotorRental(rentalId, ownerAmount, mitraName) {
        pendingMotorPay = {
            rentalId,
            ownerAmount,
            mitraName
        };
        pendingPayMethod = 'cash';
        document.getElementById('ptDriverName').textContent = mitraName || 'Mitra';
        document.getElementById('ptAmount').textContent = 'Rp ' + formatNumber(ownerAmount);
        document.querySelectorAll('.dp-method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === 'cash'));
        const btn = document.getElementById('ptConfirmBtn');
        btn.disabled = false;
        btn.textContent = '✅ Bayar & Catat ke Kas';
        btn.onclick = confirmPayMotorRental;
        document.getElementById('payTripModalOverlay').classList.add('open');
    }

    async function confirmPayMotorRental() {
        if (!pendingMotorPay) return;
        const {
            rentalId,
            mitraName
        } = pendingMotorPay;
        const cashAccountId = document.getElementById('ptCashAccount').value || '1';
        const btn = document.getElementById('ptConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Memproses...';

        const fd = new FormData();
        fd.append('rental_id', rentalId);
        fd.append('payment_method', pendingPayMethod || 'cash');
        fd.append('cash_account_id', cashAccountId);
        fd.append('mitra_name', mitraName || 'Mitra');

        try {
            const res = await fetch(BASE_URL + '/api/pay-motor-rental.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const result = await res.json();
            if (result.success) {
                document.getElementById('payTripModalOverlay').classList.remove('open');
                pendingMotorPay = null;
                await loadMotorRecap();
            } else {
                alert('Error: ' + (result.message || 'Gagal'));
                btn.disabled = false;
                btn.textContent = '✅ Bayar & Catat ke Kas';
            }
        } catch (e) {
            alert('Network error');
            btn.disabled = false;
            btn.textContent = '✅ Bayar & Catat ke Kas';
        }
    }

    function printMotorRecap(idx) {
        const motor = lastMotorRecap[idx];
        if (!motor) return;
        const monthVal = document.getElementById('filterMonth').value;
        const monthLabel = monthVal ?
            new Date(monthVal + '-01').toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric'
            }) :
            '';
        const rows = motor.detail_rows || [];
        const sumRevenue = rows.reduce((s, r) => s + (parseFloat(r.total_price) || 0), 0);
        const sumOwner = rows.reduce((s, r) => s + (parseFloat(r.owner_amount) || 0), 0);
        const sumPaid = rows.filter(r => r.paid).reduce((s, r) => s + (parseFloat(r.owner_amount) || 0), 0);
        const sumUnpaid = rows.filter(r => !r.paid).reduce((s, r) => s + (parseFloat(r.owner_amount) || 0), 0);

        const rowsHtml = rows.map((d, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                <td>${d.motor_name || '—'}<br><small>${d.plate_number || ''}</small></td>
                <td>${d.guest_name || '—'}${d.room_number ? ' (Kamar ' + d.room_number + ')' : ''}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.total_price)}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.owner_amount)}</td>
                <td style="text-align:center;">${d.paid ? '✅ Lunas' : '⏳ Belum'}</td>
            </tr>`).join('');

        const pw = window.open('', '_blank', 'width=900,height=700');
        pw.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8">
            <title>Rekap Motor Mitra - ${motor.partner_owner || 'Tanpa Pemilik'}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #1a2540; }
                h1 { font-size: 18px; margin-bottom: 2px; }
                .sub { color: #6b7690; font-size: 12px; margin-bottom: 16px; }
                .summary { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
                .summary div { border: 1px solid #e2e6ee; border-radius: 6px; padding: 8px 14px; font-size: 12px; }
                .summary b { display: block; font-size: 15px; }
                table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
                th, td { border: 1px solid #d8dee8; padding: 5px 6px; }
                th { background: #f3f5fb; text-align: left; }
                small { color: #6b7690; }
                .footer { margin-top: 28px; display: flex; justify-content: space-between; font-size: 12px; }
                .footer div { text-align: center; width: 200px; }
                .footer .line { margin-top: 48px; border-top: 1px solid #333; padding-top: 4px; }
                @media print { .no-print { display: none; } }
            </style></head><body>
            <button class="no-print" onclick="window.print()" style="float:right;padding:6px 14px;">🖨️ Cetak</button>
            <h1>Rekap Tagihan Motor Mitra</h1>
            <div class="sub">${motor.partner_owner || 'Tanpa Pemilik'}${motor.owner_phone ? ' · ' + motor.owner_phone : ''} &mdash; Periode ${monthLabel}</div>
            <div class="summary">
                <div><b>${rows.length}</b>Total Rental</div>
                <div><b>Rp ${formatNumber(sumRevenue)}</b>Total Revenue</div>
                <div><b>Rp ${formatNumber(sumOwner)}</b>Bagian Mitra</div>
                <div><b>Rp ${formatNumber(sumPaid)}</b>Sudah Dibayar</div>
                <div><b>Rp ${formatNumber(sumUnpaid)}</b>Belum Dibayar</div>
            </div>
            <table>
                <thead><tr><th>#</th><th>Tanggal</th><th>Motor</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Bagian Mitra</th><th style="text-align:center;">Status</th></tr></thead>
                <tbody>${rowsHtml || '<tr><td colspan="7" style="text-align:center;color:#999;">Tidak ada rental bulan ini</td></tr>'}</tbody>
            </table>
            <div class="footer">
                <div>Mitra<div class="line">${motor.partner_owner || ''}</div></div>
                <div>Hotel<div class="line">Frontdesk</div></div>
            </div></body></html>`);
        pw.document.close();
        pw.focus();
    }

    function editMotorRentalAmount(rentalId, totalPrice, ownerAmount, mitraName) {
        pendingMotorEdit = {
            rentalId
        };
        document.getElementById('etDriverName').textContent = mitraName || '-';
        document.getElementById('etTripLabel').textContent = 'Motor Rental';
        document.getElementById('etTotalPrice').value = totalPrice;
        document.getElementById('etOwnerAmount').value = ownerAmount;
        const suggestEl = document.getElementById('etSuggestLink');
        if (suggestEl) suggestEl.style.display = 'none';
        const btn = document.getElementById('etConfirmBtn');
        btn.disabled = false;
        btn.textContent = '💾 Simpan';
        btn.onclick = confirmEditMotorRental;
        updateEditCompanyAmount();
        document.getElementById('editTripModalOverlay').classList.add('open');
    }

    async function confirmEditMotorRental() {
        if (!pendingMotorEdit) return;
        const totalPrice = parseFloat(document.getElementById('etTotalPrice').value);
        const ownerAmount = parseFloat(document.getElementById('etOwnerAmount').value);
        if (isNaN(totalPrice) || totalPrice < 0) {
            alert('Total tarif tidak valid');
            return;
        }
        if (isNaN(ownerAmount) || ownerAmount < 0) {
            alert('Bagian mitra tidak valid');
            return;
        }
        if (ownerAmount > totalPrice) {
            alert('Bagian mitra tidak boleh melebihi total');
            return;
        }

        const btn = document.getElementById('etConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        const fd = new FormData();
        fd.append('rental_id', pendingMotorEdit.rentalId);
        fd.append('total_price', totalPrice);
        fd.append('owner_amount', ownerAmount);

        try {
            const res = await fetch(BASE_URL + '/api/edit-motor-rental-amount.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const result = await res.json();
            if (result.success) {
                closeEditTripModal();
                pendingMotorEdit = null;
                await loadMotorRecap();
            } else {
                alert('Error: ' + (result.message || 'Gagal menyimpan'));
                btn.disabled = false;
                btn.textContent = '💾 Simpan';
            }
        } catch (e) {
            alert('Network error');
            btn.disabled = false;
            btn.textContent = '💾 Simpan';
        }
    }

    async function toggleMotorPartnerPaidStatus(rentalId, paidFlag) {
        const nextPaid = Number(paidFlag) === 1;
        const ok = confirm(nextPaid ?
            'Set pembayaran mitra menjadi LUNAS?' :
            'Set pembayaran mitra menjadi BELUM dibayar?');
        if (!ok) return;

        const fd = new FormData();
        fd.append('rental_id', rentalId);
        fd.append('paid', nextPaid ? '1' : '0');

        try {
            const res = await fetch(BASE_URL + '/api/update-motor-rental-payment-status.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const result = await res.json();
            if (!result.success) {
                throw new Error(result.message || 'Gagal update status pembayaran');
            }
            await loadMotorRecap();
            if (result.note) {
                alert(result.note);
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    }

    async function saveDriverPartnerName() {
        const input = document.getElementById('driverPartnerNameInput');
        if (!input) return;

        const partnerName = (input.value || '').trim();
        if (!partnerName) {
            alert('Nama mitra tidak boleh kosong');
            input.focus();
            return;
        }

        const btn = document.querySelector('.driver-setting-save');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Menyimpan...';
        }

        const formData = new FormData();
        formData.append('partner_name', partnerName);

        try {
            const response = await fetch(BASE_URL + '/api/save-driver-partner-name.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Gagal menyimpan nama mitra');
            }

            DRIVER_DROP_PARTNER_NAME = result.partner_name || partnerName;
            await loadDriverRecap();
        } catch (error) {
            alert(`❌ ${error.message}`);
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Simpan Nama';
            }
        }
    }

    // PRINT A DRIVER'S FULL TRIP RECAP (so the driver can carry a physical copy)
    function printDriverRecap(idx) {
        const dr = lastDriverRecap[idx];
        if (!dr) return;
        const isTripTab = currentCategory === 'trip';

        const typeLabel = {
            car_rental: 'Rental Mobil',
            airport_drop: 'Airport Drop',
            harbor_drop: 'Harbor Drop',
            narayana_trip: 'Narayana Trip'
        };
        const monthVal = document.getElementById('filterMonth').value;
        const monthLabel = monthVal ?
            new Date(monthVal + '-01').toLocaleDateString('id-ID', {
                month: 'long',
                year: 'numeric'
            }) :
            '';
        const rows = (dr.detail_rows || []).filter(d => {
            const isTripService = String(d.service_type || '') === 'narayana_trip';
            return isTripTab ? isTripService : !isTripService;
        });

        const sumRevenue = rows.reduce((sum, r) => sum + (parseFloat(r.total_price) || 0), 0);
        const sumOwner = rows.reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
        const sumPaid = rows.filter(r => r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);
        const sumUnpaid = rows.filter(r => !r.paid).reduce((sum, r) => sum + (parseFloat(r.owner_amount) || 0), 0);

        const rowsHtml = rows.map((d, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${new Date(d.trx_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                <td>${typeLabel[d.service_type] || d.service_type}<br><small>${d.label || ''}</small></td>
                <td>${d.guest_name || '—'}${d.room_number ? ' (Kamar ' + d.room_number + ')' : ''}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.total_price)}</td>
                <td style="text-align:right;">Rp ${formatNumber(d.owner_amount)}</td>
                <td style="text-align:center;">${d.paid ? 'Lunas' : 'Belum'}</td>
            </tr>`).join('');

        const printWindow = window.open('', '_blank', 'width=900,height=700');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Rekap Trip - ${dr.partner_owner || 'Tanpa Pemilik'}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #1a2540; }
                    h1 { font-size: 18px; margin-bottom: 2px; }
                    .sub { color: #6b7690; font-size: 12px; margin-bottom: 16px; }
                    .summary { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
                    .summary div { border: 1px solid #e2e6ee; border-radius: 6px; padding: 8px 14px; font-size: 12px; }
                    .summary b { display: block; font-size: 15px; }
                    table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
                    th, td { border: 1px solid #d8dee8; padding: 5px 6px; }
                    th { background: #f3f5fb; text-align: left; }
                    small { color: #6b7690; }
                    .footer { margin-top: 28px; display: flex; justify-content: space-between; font-size: 12px; }
                    .footer div { text-align: center; width: 200px; }
                    .footer .line { margin-top: 48px; border-top: 1px solid #333; padding-top: 4px; }
                    @media print { .no-print { display: none; } }
                </style>
            </head>
            <body>
                <button class="no-print" onclick="window.print()" style="float:right;padding:6px 14px;">Cetak</button>
                <h1>${isTripTab ? 'Rekap Tagihan Trip (Guide)' : 'Rekap Trip Driver / Mitra'}</h1>
                <div class="sub">${dr.partner_owner || 'Tanpa Pemilik'}${dr.owner_phone ? ' · ' + dr.owner_phone : ''} &mdash; Periode ${monthLabel}</div>
                <div class="summary">
                    <div><b>${rows.length}</b>Total Trip</div>
                    <div><b>Rp ${formatNumber(sumRevenue)}</b>Total Revenue</div>
                    <div><b>Rp ${formatNumber(sumOwner)}</b>Bagian Pemilik</div>
                    <div><b>Rp ${formatNumber(sumPaid)}</b>Sudah Dibayar</div>
                    <div><b>Rp ${formatNumber(sumUnpaid)}</b>Belum Dibayar</div>
                </div>
                <table>
                    <thead>
                        <tr><th>#</th><th>Tanggal</th><th>Jenis</th><th>Tamu</th><th style="text-align:right;">Total</th><th style="text-align:right;">Bagian Pemilik</th><th style="text-align:center;">Status</th></tr>
                    </thead>
                    <tbody>${rowsHtml || '<tr><td colspan="7" style="text-align:center;color:#999;">Tidak ada trip bulan ini</td></tr>'}</tbody>
                </table>
                <div class="footer">
                    <div>Driver / Mitra<div class="line">${dr.partner_owner || ''}</div></div>
                    <div>Hotel<div class="line">Frontdesk</div></div>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function resolveAssetUrl(path) {
        if (!path) return '';
        if (/^(https?:|data:)/i.test(path)) return path;
        if (path.startsWith('/')) return window.location.origin + path;
        return window.location.origin + BASE_URL + '/' + path.replace(/^\/+/, '');
    }

    function formatDriverPaymentMethod(method) {
        const labels = {
            cash: 'Tunai',
            transfer: 'Transfer Bank',
            card: 'Kartu',
            other: 'Lainnya',
            'e-wallet': 'E-Wallet',
            credit_card: 'Kartu Kredit'
        };
        return labels[method] || (method ? method : '-');
    }

    function formatLongDateTime(value) {
        if (!value) return '-';
        const dt = new Date(value);
        if (Number.isNaN(dt.getTime())) return value;
        return dt.toLocaleString('id-ID', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function printDriverTripReceipt(driverIdx, tripId, source) {
        const dr = lastDriverRecap[driverIdx];
        if (!dr) return;

        const trip = (dr.detail_rows || []).find(row => Number(row.trip_id) === Number(tripId) && String(row.source || 'trip') === String(source || 'trip'));
        if (!trip || !trip.paid) {
            alert('Tanda terima hanya tersedia untuk pembayaran driver yang sudah lunas.');
            return;
        }

        const typeLabel = {
            car_rental: 'Rental Mobil',
            airport_drop: 'Airport Drop',
            harbor_drop: 'Harbor Drop',
            narayana_trip: 'Narayana Trip'
        };

        const companyName = DRIVER_RECEIPT_META.companyName || 'Narayana Hotel';
        const companyTagline = DRIVER_RECEIPT_META.companyTagline || '';
        const companyAddress = DRIVER_RECEIPT_META.companyAddress || '';
        const companyPhone = DRIVER_RECEIPT_META.companyPhone || '';
        const companyEmail = DRIVER_RECEIPT_META.companyEmail || '';
        const companyWebsite = DRIVER_RECEIPT_META.companyWebsite || '';
        const logoUrl = resolveAssetUrl(DRIVER_RECEIPT_META.companyLogo || '');
        const receiptNo = trip.driver_paid_cashbook_id > 0 ?
            `DRV-${String(trip.driver_paid_cashbook_id).padStart(6, '0')}` :
            `DRV-TRIP-${trip.trip_id}`;
        const paymentDate = formatLongDateTime(trip.driver_paid_at || trip.trx_date);
        const serviceName = typeLabel[trip.service_type] || trip.service_type;
        const guestLabel = trip.guest_name || '-';
        const roomLabel = trip.room_number ? `Kamar ${trip.room_number}` : '-';
        const printedAt = formatLongDateTime(new Date().toISOString());
        const paymentMethod = formatDriverPaymentMethod(trip.payment_method);
        const statement = `Telah dibayarkan kepada driver/mitra sebesar Rp ${formatNumber(trip.owner_amount)} untuk layanan ${serviceName}. Dokumen ini merupakan bukti pembayaran resmi dari hotel.`;

        const printWindow = window.open('', '_blank', 'width=960,height=760');
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>Tanda Terima Driver - ${escapeHtml(receiptNo)}</title>
                <style>
                    :root {
                        --ink: #14213d;
                        --muted: #64748b;
                        --line: #dbe4f0;
                        --soft: #f8fafc;
                        --accent: #0f172a;
                        --accent2: #334155;
                    }
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        background: #eef3f8;
                        font-family: Georgia, 'Times New Roman', serif;
                        color: var(--ink);
                        padding: 20px;
                    }
                    .sheet {
                        max-width: 760px;
                        margin: 0 auto;
                        background: #fff;
                        border: 1px solid #d6dee8;
                        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.1);
                    }
                    .toolbar {
                        padding: 12px 16px 0;
                        text-align: right;
                    }
                    .print-btn {
                        padding: 7px 14px;
                        border: 0;
                        border-radius: 999px;
                        background: linear-gradient(135deg, #1e293b, #334155);
                        color: #fff;
                        font: 600 11px Arial, sans-serif;
                        letter-spacing: 0.03em;
                        cursor: pointer;
                    }
                    .paper {
                        padding: 20px 24px 28px;
                    }
                    .header {
                        display: grid;
                        grid-template-columns: minmax(0, 1fr) 220px;
                        gap: 14px;
                        align-items: center;
                        border-bottom: 1px solid #ccd6e2;
                        padding-bottom: 12px;
                    }
                    .brand {
                        display: grid;
                        grid-template-columns: 76px minmax(0, 1fr);
                        gap: 16px;
                        align-items: center;
                    }
                    .logo {
                        width: 76px;
                        height: 76px;
                        border-radius: 16px;
                        object-fit: contain;
                        background: #fff;
                        border: 1px solid var(--line);
                        padding: 8px;
                    }
                    .logo-fallback {
                        width: 76px;
                        height: 76px;
                        border-radius: 16px;
                        background: linear-gradient(135deg, #0f172a, #334155);
                        color: #fff;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font: 700 20px Arial, sans-serif;
                    }
                    .brand h1 {
                        margin: 0;
                        font-size: 13px;
                        letter-spacing: 0.16em;
                        text-transform: uppercase;
                    }
                    .brand p {
                        margin: 3px 0 0;
                        color: var(--muted);
                        font: 10px/1.55 Arial, sans-serif;
                    }
                    .doc-meta {
                        min-width: 220px;
                        background: transparent;
                        border: 0;
                        border-left: 1px solid #d8e1ec;
                        border-radius: 0;
                        padding: 4px 0 4px 16px;
                    }
                    .doc-meta .eyebrow {
                        color: var(--muted);
                        font: 700 9px/1 Arial, sans-serif;
                        letter-spacing: 0.22em;
                        text-transform: uppercase;
                        margin-bottom: 5px;
                    }
                    .doc-meta h2 {
                        margin: 0 0 7px;
                        font-size: 10.5px;
                        line-height: 1.45;
                        letter-spacing: 0.02em;
                    }
                    .meta-row {
                        display: flex;
                        justify-content: space-between;
                        gap: 8px;
                        margin-top: 5px;
                        font: 10px/1.45 Arial, sans-serif;
                    }
                    .meta-row strong { text-align: right; }
                    .meta-row span:first-child { color: var(--muted); }
                    .section {
                        margin-top: 18px;
                    }
                    .section-title {
                        font: 700 9px/1 Arial, sans-serif;
                        letter-spacing: 0.24em;
                        text-transform: uppercase;
                        color: var(--muted);
                        margin-bottom: 8px;
                    }
                    .statement {
                        border: 1px solid var(--line);
                        background: linear-gradient(180deg, #fff, #f8fbff);
                        border-radius: 14px;
                        padding: 12px 14px;
                        font: 10px/1.8 Arial, sans-serif;
                    }
                    .grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 10px;
                    }
                    .info-box {
                        border: 1px solid var(--line);
                        border-radius: 13px;
                        padding: 12px 14px;
                        background: #fff;
                    }
                    .info-box .label {
                        display: block;
                        color: var(--muted);
                        font: 700 8.5px/1 Arial, sans-serif;
                        letter-spacing: 0.18em;
                        text-transform: uppercase;
                        margin-bottom: 7px;
                    }
                    .info-box .value {
                        font: 700 11px/1.4 Arial, sans-serif;
                        color: var(--accent);
                    }
                    .info-box .sub {
                        margin-top: 4px;
                        color: var(--muted);
                        font: 9.5px/1.55 Arial, sans-serif;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 6px;
                        font: 10px/1.5 Arial, sans-serif;
                    }
                    th, td {
                        border-bottom: 1px solid var(--line);
                        padding: 8px 6px;
                        vertical-align: top;
                    }
                    th {
                        color: var(--muted);
                        text-transform: uppercase;
                        letter-spacing: 0.12em;
                        font-size: 8.5px;
                        text-align: left;
                    }
                    td:last-child, th:last-child { text-align: right; }
                    .signatures {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 26px;
                        margin-top: 28px;
                    }
                    .sign-box {
                        text-align: center;
                    }
                    .sign-title {
                        font: 700 9px/1 Arial, sans-serif;
                        color: var(--muted);
                        text-transform: uppercase;
                        letter-spacing: 0.16em;
                    }
                    .sign-line {
                        margin-top: 48px;
                        border-top: 1px solid #23324d;
                        padding-top: 6px;
                        font: 700 10px/1.4 Arial, sans-serif;
                    }
                    .footnote {
                        margin-top: 18px;
                        text-align: center;
                        color: var(--muted);
                        font: italic 9px/1.6 Arial, sans-serif;
                    }
                    @media print {
                        body { background: #fff; padding: 0; }
                        .sheet { box-shadow: none; border: none; max-width: none; }
                        .toolbar { display: none; }
                        .paper { padding: 0; }
                    }
                    @media (max-width: 720px) {
                        .header {
                            grid-template-columns: 1fr;
                        }
                        .doc-meta {
                            border-left: 0;
                            border-top: 1px solid #d8e1ec;
                            padding: 12px 0 0;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="sheet">
                    <div class="toolbar"><button class="print-btn" onclick="window.print()">Cetak Dokumen</button></div>
                    <div class="paper">
                        <div class="header">
                            <div class="brand">
                                ${logoUrl ? `<img src="${escapeHtml(logoUrl)}" alt="Logo" class="logo">` : `<div class="logo-fallback">${escapeHtml((companyName || 'NH').slice(0, 2).toUpperCase())}</div>`}
                                <div>
                                    <h1>${escapeHtml(companyName)}</h1>
                                    ${companyTagline ? `<p>${escapeHtml(companyTagline)}</p>` : ''}
                                    ${(companyAddress || companyPhone || companyEmail || companyWebsite) ? `<p>${escapeHtml([companyAddress, companyPhone, companyEmail, companyWebsite].filter(Boolean).join(' | '))}</p>` : ''}
                                </div>
                            </div>
                            <div class="doc-meta">
                                <div class="eyebrow">Dokumen Resmi</div>
                                <h2>Tanda Terima Driver</h2>
                                <div class="meta-row"><span>No. Bukti</span><strong>${escapeHtml(receiptNo)}</strong></div>
                                <div class="meta-row"><span>Tanggal Bayar</span><strong>${escapeHtml(paymentDate)}</strong></div>
                                <div class="meta-row"><span>Metode</span><strong>${escapeHtml(paymentMethod)}</strong></div>
                                <div class="meta-row"><span>Dicetak</span><strong>${escapeHtml(printedAt)}</strong></div>
                            </div>
                        </div>

                        <div class="section">
                            <div class="section-title">Pernyataan</div>
                            <div class="statement">${escapeHtml(statement)}</div>
                        </div>

                        <div class="section grid">
                            <div class="info-box">
                                <span class="label">Dibayarkan Kepada</span>
                                <div class="value">${escapeHtml(dr.partner_owner || 'Tanpa Pemilik')}</div>
                                <div class="sub">Driver / Mitra ${dr.owner_phone ? '· ' + escapeHtml(dr.owner_phone) : ''}</div>
                            </div>
                            <div class="info-box">
                                <span class="label">Jumlah Dibayarkan</span>
                                <div class="value">Rp ${formatNumber(trip.owner_amount)}</div>
                                <div class="sub">Bagian driver dari trip ini</div>
                            </div>
                        </div>

                        <div class="section">
                            <div class="section-title">Rincian Trip</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Layanan</th>
                                        <th>Tamu</th>
                                        <th>Keterangan</th>
                                        <th>Total Trip</th>
                                        <th>Bagian Driver</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>${escapeHtml(serviceName)}</strong><br><span style="color:#64748b;font-size:12px;">${escapeHtml(trip.label || '-')}</span></td>
                                        <td>${escapeHtml(guestLabel)}<br><span style="color:#64748b;font-size:12px;">${escapeHtml(roomLabel)}</span></td>
                                        <td>Tanggal Trip: ${escapeHtml(formatLongDateTime(trip.trx_date))}</td>
                                        <td>Rp ${formatNumber(trip.total_price)}</td>
                                        <td>Rp ${formatNumber(trip.owner_amount)}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="signatures">
                            <div class="sign-box">
                                <div class="sign-title">Penerima</div>
                                <div class="sign-line">${escapeHtml(dr.partner_owner || 'Driver / Mitra')}</div>
                            </div>
                            <div class="sign-box">
                                <div class="sign-title">Pembayar</div>
                                <div class="sign-line">${escapeHtml(companyName)}</div>
                            </div>
                        </div>

                        <div class="footnote">Dokumen ini dicetak dari sistem dan sah digunakan sebagai bukti pembayaran driver/mitra.</div>
                    </div>
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
    }

    // PAY A SINGLE DRIVER TRIP (marks trip as paid + auto-syncs to buku kas)
    let pendingPayTrip = null;
    let pendingPayMethod = 'cash';

    function payDriverTrip(tripId, sourceType, amount, driverName, source = 'trip') {
        pendingPayTrip = {
            tripId,
            sourceType,
            amount,
            driverName,
            source
        };
        pendingPayMethod = 'cash';

        document.getElementById('ptDriverName').textContent = driverName;
        document.getElementById('ptAmount').textContent = 'Rp ' + formatNumber(amount);
        document.querySelectorAll('.dp-method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === 'cash'));
        document.getElementById('ptConfirmBtn').disabled = false;

        document.getElementById('payTripModalOverlay').classList.add('open');
    }

    function selectPayMethod(method) {
        pendingPayMethod = method;
        document.querySelectorAll('.dp-method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === method));
    }

    function closePayTripModal() {
        document.getElementById('payTripModalOverlay').classList.remove('open');
        pendingPayTrip = null;
    }

    async function confirmPayDriverTrip() {
        if (!pendingPayTrip) return;
        const {
            tripId,
            sourceType,
            driverName,
            source
        } = pendingPayTrip;
        const cashAccountId = document.getElementById('ptCashAccount').value || '1';

        const confirmBtn = document.getElementById('ptConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Memproses...';

        const formData = new FormData();
        formData.append('trip_id', tripId);
        formData.append('source_type', sourceType);
        formData.append('source', source);
        formData.append('payment_method', pendingPayMethod);
        formData.append('cash_account_id', cashAccountId);
        formData.append('driver_name', driverName);
        formData.append('business', ACTIVE_BUSINESS);

        try {
            const response = await fetch(BASE_URL + '/api/pay-driver-trip.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });

            const result = await response.json();

            if (result.success) {
                closePayTripModal();
                loadDriverRecap();
            } else {
                alert(`❌ ${result.message}`);
                confirmBtn.disabled = false;
                confirmBtn.textContent = '✅ Bayar & Catat ke Kas';
            }
        } catch (error) {
            alert(`❌ Error: ${error.message}`);
            confirmBtn.disabled = false;
            confirmBtn.textContent = '✅ Bayar & Catat ke Kas';
        }
    }

    // EDIT DRIVER TRIP AMOUNT
    let pendingEditTrip = null;

    function editDriverTripAmount(tripId, source, totalPrice, ownerAmount, driverName, tripLabel, serviceType) {
        pendingEditTrip = {
            tripId,
            source
        };
        document.getElementById('etDriverName').textContent = driverName || '-';
        document.getElementById('etTripLabel').textContent = tripLabel || '-';
        document.getElementById('etTotalPrice').value = totalPrice;
        document.getElementById('etOwnerAmount').value = ownerAmount;
        document.getElementById('etConfirmBtn').disabled = false;
        document.getElementById('etConfirmBtn').textContent = '💾 Simpan';
        // Show catalog-based suggestion if catalog has a driver_rate for this service type
        const suggestEl = document.getElementById('etSuggestLink');
        if (suggestEl) {
            const catItems = (window.CATALOG_DATA_BILLS || {})[serviceType] || [];
            const catItem = catItems.find(ci => (ci.driver_rate || 0) > 0);
            if (catItem && catItem.driver_rate > 0) {
                const suggestOwner = Math.max(0, totalPrice - (totalPrice - catItem.driver_rate));
                suggestEl.dataset.suggestOwner = catItem.driver_rate;
                suggestEl.textContent = `↗ Pakai dari katalog: Rp ${formatNumber(catItem.driver_rate)}`;
                suggestEl.style.display = '';
            } else {
                suggestEl.style.display = 'none';
            }
        }
        updateEditCompanyAmount();
        document.getElementById('editTripModalOverlay').classList.add('open');
    }

    function applyEtSuggest() {
        const el = document.getElementById('etSuggestLink');
        if (!el || !el.dataset.suggestOwner) return;
        document.getElementById('etOwnerAmount').value = el.dataset.suggestOwner;
        updateEditCompanyAmount();
    }

    function updateEditCompanyAmount() {
        const total = parseFloat(document.getElementById('etTotalPrice').value) || 0;
        const owner = parseFloat(document.getElementById('etOwnerAmount').value) || 0;
        document.getElementById('etCompanyAmount').textContent = 'Rp ' + formatNumber(Math.max(0, total - owner));
    }

    function closeEditTripModal() {
        document.getElementById('editTripModalOverlay').classList.remove('open');
        pendingEditTrip = null;
    }

    async function confirmEditDriverTrip() {
        if (!pendingEditTrip) return;
        const totalPrice = parseFloat(document.getElementById('etTotalPrice').value);
        const ownerAmount = parseFloat(document.getElementById('etOwnerAmount').value);

        if (isNaN(totalPrice) || totalPrice < 0) {
            alert('Total tarif tidak valid');
            return;
        }
        if (isNaN(ownerAmount) || ownerAmount < 0) {
            alert('Bagian pemilik tidak valid');
            return;
        }
        if (ownerAmount > totalPrice) {
            alert('Bagian pemilik tidak boleh melebihi total tarif');
            return;
        }

        const btn = document.getElementById('etConfirmBtn');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';

        const fd = new FormData();
        fd.append('trip_id', pendingEditTrip.tripId);
        fd.append('source', pendingEditTrip.source);
        fd.append('total_price', totalPrice);
        fd.append('owner_amount', ownerAmount);

        try {
            const res = await fetch(BASE_URL + '/api/edit-driver-trip-amount.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const result = await res.json();
            if (result.success) {
                closeEditTripModal();
                loadDriverRecap();
            } else {
                alert('❌ ' + result.message);
                btn.disabled = false;
                btn.textContent = '💾 Simpan';
            }
        } catch (err) {
            alert('❌ Error: ' + err.message);
            btn.disabled = false;
            btn.textContent = '💾 Simpan';
        }
    }

    // SWITCH PAY FILTER (Semua / Belum Dibayar / Sudah Dibayar)
    function setDriverPayFilter(status) {
        driverPayFilter = status;
        renderDriverRecap();
    }

    // SWITCH TABS
    function switchTab(tab, event) {
        event.preventDefault();
        currentTab = tab;
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        loadBills();
    }

    // FORMAT NUMBER
    function formatNumber(num) {
        return new Intl.NumberFormat('id-ID').format(num);
    }

    // EDIT BILL (placeholder)
    function editBill(billId) {
        alert(`Edit bill ${billId} - Coming soon!`);
    }

    // OPEN PAYMENT MODAL
    function openPayment(billId, billName, amount, paidAmount) {
        const remaining = amount - paidAmount;
        const paymentAmount = prompt(
            `Bayar tagihan: ${billName}\n\nJumlah tagihan: Rp ${formatNumber(amount)}\nSudah dibayar: Rp ${formatNumber(paidAmount)}\nSisa: Rp ${formatNumber(remaining)}\n\nBerapa yang mau dibayar?`,
            remaining
        );

        if (paymentAmount === null) return;

        const paymentValue = parseFloat(paymentAmount);
        if (isNaN(paymentValue) || paymentValue <= 0) {
            alert('Jumlah tidak valid');
            return;
        }

        if (paymentValue > remaining) {
            alert(`Pembayaran melebihi sisa tagihan!\nSisa: Rp ${formatNumber(remaining)}`);
            return;
        }

        const paymentMethod = prompt('Metode pembayaran? (cash, transfer, card, other)', 'cash');
        if (!paymentMethod) return;

        const cashAccountId = prompt('Dari rekening mana? (1=Kas Tunai, 2=Bank Utama, dst)\nBiarkan kosong jika default', '1');
        if (cashAccountId === null) return;

        recordPayment(billId, paymentValue, paymentMethod, cashAccountId || '1');
    }

    // RECORD PAYMENT
    async function recordPayment(billId, amount, method, accountId) {
        const formData = new FormData();
        formData.append('bill_id', billId);
        formData.append('amount', amount);
        formData.append('payment_method', method);
        formData.append('cash_account_id', accountId);
        formData.append('business', ACTIVE_BUSINESS);

        try {
            const response = await fetch(BASE_URL + '/api/pay-monthly-bill.php', {
                method: 'POST',
                body: formData,
                credentials: 'include' // Include cookies for authentication
            });

            const result = await response.json();

            if (result.success) {
                alert(`✅ ${result.message}\nStatus: ${result.bill_status}\nSisa: Rp ${formatNumber(result.remaining)}`);
                loadBills();
            } else {
                alert(`❌ ${result.message}`);
            }
        } catch (error) {
            alert(`❌ Error: ${error.message}`);
        }
    }

    // Load on page load
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const reqCat = urlParams.get('cat');
        const allowed = ['driver', 'trip', 'manual', 'bulanan', 'motor', 'gudang'];
        const defaultCat = <?php echo json_encode($hideDriverTabs ? 'gudang' : 'driver'); ?>;
        switchCategory(allowed.includes(reqCat) ? reqCat : defaultCat);
    });
</script>

<?php include '../../includes/footer.php'; ?>