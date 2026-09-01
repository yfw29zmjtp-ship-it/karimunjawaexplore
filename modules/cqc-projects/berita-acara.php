<?php
/**
 * CQC Berita Acara Serah Terima Proyek
 * Official project completion certificate - printable A4
 * With language toggle (Indonesian / English)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once 'db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$autoPrint = isset($_GET['print']) && $_GET['print'] == '1';
$lang = isset($_GET['lang']) ? $_GET['lang'] : 'id';

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Get project
$stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("Proyek tidak ditemukan.");
}

// Get invoices
$stmt = $pdo->prepare("SELECT * FROM cqc_termin_invoices WHERE project_id = ? ORDER BY termin_number ASC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$totalPaid = array_sum(array_column($invoices, 'paid_amount'));
$contractValue = !empty($invoices) ? floatval($invoices[0]['contract_value']) : floatval($project['budget_idr']);
$outstandingBalance = $contractValue - $totalPaid;

// Company info — load from business_settings + PDF settings (invoice_logo)
$db = Database::getInstance();
$companyName = 'CQC Enjiniring';
$companyTagline = 'Solar Panel Installation Contractor';
$companyAddress = '';
$companyCity = '';
$companyPhone = '-';
$companyEmail = '-';
$companyNPWP = '-';
$companyLogo = '';
$companyLogoUrl = '';

try {
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM business_settings WHERE business_id = 7");
    foreach ($settings as $s) {
        switch ($s['setting_key']) {
            case 'business_name': if ($s['setting_value']) $companyName = $s['setting_value']; break;
            case 'tagline': if ($s['setting_value']) $companyTagline = $s['setting_value']; break;
            case 'address': if ($s['setting_value']) $companyAddress = $s['setting_value']; break;
            case 'city': if ($s['setting_value']) $companyCity = $s['setting_value']; break;
            case 'phone': if ($s['setting_value']) $companyPhone = $s['setting_value']; break;
            case 'email': if ($s['setting_value']) $companyEmail = $s['setting_value']; break;
            case 'npwp': if ($s['setting_value']) $companyNPWP = $s['setting_value']; break;
            case 'logo': if ($s['setting_value']) $companyLogo = $s['setting_value']; break;
        }
    }
} catch (Exception $e) {}

// Priority: Get logo from PDF/Invoice settings (Settings > Report Settings)
$businessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : 'cqc';
try {
    // Priority 1: invoice_logo_[businessId] from settings table
    $logoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", 
        ['invoice_logo_' . $businessId]);
    if ($logoResult && !empty($logoResult['setting_value'])) {
        $logoVal = $logoResult['setting_value'];
        $companyLogoUrl = (strpos($logoVal, 'http') === 0) ? $logoVal : BASE_URL . '/uploads/logos/' . $logoVal;
    }
    // Priority 2: Global invoice_logo
    if (!$companyLogoUrl) {
        $logoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'invoice_logo'");
        if ($logoResult && !empty($logoResult['setting_value'])) {
            $logoVal = $logoResult['setting_value'];
            $companyLogoUrl = (strpos($logoVal, 'http') === 0) ? $logoVal : BASE_URL . '/uploads/logos/' . $logoVal;
        }
    }
    // Priority 3: company_logo
    if (!$companyLogoUrl) {
        $logoResult = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_logo'");
        if ($logoResult && !empty($logoResult['setting_value'])) {
            $logoVal = $logoResult['setting_value'];
            $companyLogoUrl = (strpos($logoVal, 'http') === 0) ? $logoVal : BASE_URL . '/' . ltrim($logoVal, '/');
        }
    }
} catch (Exception $e) {}

// Fallback: logo from business_settings or config
if (!$companyLogoUrl && $companyLogo) {
    $companyLogoUrl = (strpos($companyLogo, 'http') === 0) ? $companyLogo : BASE_URL . '/' . ltrim($companyLogo, '/');
}

// Fallback from config file
$configPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(dirname(__DIR__));
$configFile = $configPath . '/config/businesses/cqc.php';
if (file_exists($configFile)) {
    $cqcConfig = include $configFile;
    if ($companyName === 'CQC Enjiniring' && isset($cqcConfig['name'])) $companyName = $cqcConfig['name'];
    if (empty($companyLogoUrl) && isset($cqcConfig['logo'])) $companyLogoUrl = BASE_URL . '/' . ltrim($cqcConfig['logo'], '/');
    if (empty($companyAddress) && isset($cqcConfig['address'])) $companyAddress = $cqcConfig['address'];
    if (empty($companyCity) && isset($cqcConfig['city'])) $companyCity = $cqcConfig['city'];
}

$completionDate = $project['actual_completion'] ?: ($project['end_date'] ?: date('Y-m-d'));
$baNumber = 'BA/' . date('Y/m', strtotime($completionDate)) . '/' . str_pad($id, 3, '0', STR_PAD_LEFT) . '/CQC';

// Duration
$startDate = $project['start_date'] ? new DateTime($project['start_date']) : null;
$endDate = new DateTime($completionDate);
$duration = $startDate ? $startDate->diff($endDate)->days : 0;

// ========== LANGUAGE STRINGS ==========
$t = [];
if ($lang === 'en') {
    $t['doc_title'] = 'Project Completion & Handover Certificate';
    $t['doc_subtitle'] = 'Berita Acara Serah Terima Pekerjaan';
    $t['opening'] = 'On this day, <strong>' . date('l', strtotime($completionDate)) . '</strong>, dated <strong>' . date('F d, Y', strtotime($completionDate)) . '</strong>, the undersigned parties hereby declare that the following project work has been completed in accordance with the agreed scope of work:';
    $t['sec1'] = 'I. Project Information';
    $t['project_name'] = 'Project Name';
    $t['project_code'] = 'Project Code';
    $t['client_name'] = 'Client Name';
    $t['location'] = 'Location';
    $t['capacity'] = 'Capacity';
    $t['panel_count'] = 'Panel Count';
    $t['start_date'] = 'Start Date';
    $t['end_date'] = 'Completion Date';
    $t['duration'] = 'Duration';
    $t['status'] = 'Status';
    $t['status_done'] = '✓ COMPLETED';
    $t['days'] = 'days';
    $t['units'] = 'units';
    $t['sec2'] = 'II. Financial Summary';
    $t['payment_detail'] = 'Payment Schedule Details:';
    $t['no'] = 'No';
    $t['invoice'] = 'Invoice';
    $t['termin'] = 'Term';
    $t['value'] = 'Value';
    $t['paid'] = 'Paid';
    $t['status_col'] = 'Status';
    $t['contract_value'] = 'Contract Value';
    $t['total_paid'] = 'Total Paid';
    $t['outstanding'] = 'OUTSTANDING BALANCE';
    $t['paid_full'] = '✓ PAID IN FULL';
    $t['sec3'] = 'III. Scope of Work';
    $t['scope_text'] = 'The completed work includes the installation of a Solar Photovoltaic (PV) Power System with a capacity of <strong>' . number_format($project['solar_capacity_kwp'] ?? 0, 1) . ' kWp</strong>'
        . ($project['panel_count'] ? ', consisting of <strong>' . $project['panel_count'] . ' units</strong> of solar panels' : '')
        . ($project['panel_type'] ? ' type <strong>' . htmlspecialchars($project['panel_type']) . '</strong>' : '')
        . ($project['inverter_type'] ? ' with <strong>' . htmlspecialchars($project['inverter_type']) . '</strong> inverter' : '')
        . '. The installation was carried out at <strong>' . htmlspecialchars($project['location'] ?? '-') . '</strong> and has undergone commissioning testing in accordance with applicable standards.';
    $t['sec4'] = 'IV. Declaration';
    $t['declaration_intro'] = 'By signing this Certificate, both parties declare that:';
    $t['decl1'] = 'All installation work has been completed in accordance with the agreed specifications and scope of work.';
    $t['decl2'] = 'The system has been tested and is functioning properly in accordance with applicable technical standards.';
    $t['decl3'] = 'The handover of work results has been carried out from the contractor to the client.';
    $t['decl4'] = 'The warranty period commences from the date of signing of this Certificate.';
    $t['sig_contractor'] = 'First Party (Contractor)';
    $t['sig_client'] = 'Second Party (Client)';
    $t['sig_role_client'] = 'Client / Owner';
    $t['footer_text'] = 'This document was electronically generated by ' . htmlspecialchars($companyName) . ' on ' . date('F d, Y H:i') . ' WIB';
    $t['btn_back'] = '← Back to Report';
    $t['btn_print'] = '🖨 Print Certificate';
    $t['lang_label'] = 'Language';
} else {
    $t['doc_title'] = 'Berita Acara Serah Terima Pekerjaan';
    $t['doc_subtitle'] = 'Project Completion & Handover Certificate';
    $t['opening'] = 'Pada hari ini, <strong>' . date('l', strtotime($completionDate)) . '</strong>, tanggal <strong>' . date('d F Y', strtotime($completionDate)) . '</strong>, kami yang bertanda tangan di bawah ini menyatakan bahwa pekerjaan proyek berikut telah diselesaikan sesuai dengan lingkup pekerjaan yang telah disepakati:';
    $t['sec1'] = 'I. Informasi Proyek';
    $t['project_name'] = 'Nama Proyek';
    $t['project_code'] = 'Kode Proyek';
    $t['client_name'] = 'Nama Klien';
    $t['location'] = 'Lokasi';
    $t['capacity'] = 'Kapasitas';
    $t['panel_count'] = 'Jumlah Panel';
    $t['start_date'] = 'Tanggal Mulai';
    $t['end_date'] = 'Tanggal Selesai';
    $t['duration'] = 'Durasi Pengerjaan';
    $t['status'] = 'Status';
    $t['status_done'] = '✓ SELESAI';
    $t['days'] = 'hari';
    $t['units'] = 'unit';
    $t['sec2'] = 'II. Rekapitulasi Keuangan';
    $t['payment_detail'] = 'Rincian Pembayaran Termin:';
    $t['no'] = 'No';
    $t['invoice'] = 'Invoice';
    $t['termin'] = 'Termin';
    $t['value'] = 'Nilai';
    $t['paid'] = 'Dibayar';
    $t['status_col'] = 'Status';
    $t['contract_value'] = 'Nilai Kontrak';
    $t['total_paid'] = 'Total Dibayar';
    $t['outstanding'] = 'SISA TAGIHAN';
    $t['paid_full'] = '✓ LUNAS';
    $t['sec3'] = 'III. Lingkup Pekerjaan';
    $t['scope_text'] = 'Pekerjaan yang telah diselesaikan meliputi instalasi sistem pembangkit listrik tenaga surya (PLTS) dengan kapasitas <strong>' . number_format($project['solar_capacity_kwp'] ?? 0, 1) . ' kWp</strong>'
        . ($project['panel_count'] ? ', terdiri dari <strong>' . $project['panel_count'] . ' unit</strong> panel surya' : '')
        . ($project['panel_type'] ? ' tipe <strong>' . htmlspecialchars($project['panel_type']) . '</strong>' : '')
        . ($project['inverter_type'] ? ' dengan inverter <strong>' . htmlspecialchars($project['inverter_type']) . '</strong>' : '')
        . '. Instalasi telah dilakukan di lokasi <strong>' . htmlspecialchars($project['location'] ?? '-') . '</strong> dan telah melalui proses pengujian (commissioning) sesuai standar yang berlaku.';
    $t['sec4'] = 'IV. Pernyataan';
    $t['declaration_intro'] = 'Dengan ditandatanganinya Berita Acara ini, kedua belah pihak menyatakan bahwa:';
    $t['decl1'] = 'Seluruh pekerjaan instalasi telah diselesaikan sesuai dengan spesifikasi dan lingkup pekerjaan yang disepakati.';
    $t['decl2'] = 'Sistem telah diuji dan berfungsi dengan baik sesuai standar teknis yang berlaku.';
    $t['decl3'] = 'Penyerahan hasil pekerjaan telah dilakukan dari pihak kontraktor kepada pihak pemberi kerja.';
    $t['decl4'] = 'Masa garansi dimulai sejak tanggal penandatanganan Berita Acara ini.';
    $t['sig_contractor'] = 'Pihak Pertama (Kontraktor)';
    $t['sig_client'] = 'Pihak Kedua (Pemberi Kerja)';
    $t['sig_role_client'] = 'Klien / Pemilik';
    $t['footer_text'] = 'Dokumen ini dicetak secara elektronik oleh ' . htmlspecialchars($companyName) . ' pada ' . date('d F Y H:i') . ' WIB';
    $t['btn_back'] = '← Kembali ke Laporan';
    $t['btn_print'] = '🖨 Cetak Berita Acara';
    $t['lang_label'] = 'Bahasa';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Berita Acara - <?php echo htmlspecialchars($project['project_name']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { size: A4; margin: 15mm 20mm; }
        
        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-size: 12px; line-height: 1.7; color: #1e293b; background: #f1f5f9;
        }

        .page {
            max-width: 210mm; margin: 20px auto; background: #fff;
            padding: 40px 50px; box-shadow: 0 4px 30px rgba(0,0,0,0.08);
            min-height: 297mm; border-top: 4px solid #f0b429;
        }

        /* Header */
        .ba-header {
            display: flex; justify-content: space-between; align-items: center;
            padding-bottom: 16px; margin-bottom: 4px;
            border-bottom: 2px solid #0d1f3c;
        }
        .ba-company { display: flex; align-items: center; gap: 14px; }
        .ba-logo {
            width: 55px; height: 55px; background: linear-gradient(135deg, #f0b429, #d4960d);
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 800; color: #0d1f3c; flex-shrink: 0;
        }
        .ba-logo img { width: 55px; height: 55px; border-radius: 10px; object-fit: cover; }
        .ba-company-name { font-size: 20px; font-weight: 800; color: #0d1f3c; }
        .ba-company-tagline { font-size: 10px; color: #64748b; font-weight: 500; }
        .ba-company-contact { font-size: 9px; color: #94a3b8; margin-top: 2px; }

        .ba-doc-info { text-align: right; flex-shrink: 0; }
        .ba-doc-number {
            font-size: 11px; font-weight: 700; color: #0d1f3c;
            background: #fffbeb; padding: 4px 10px; border-radius: 6px; border: 1px solid #f0b429;
        }
        .ba-doc-date { font-size: 10px; color: #64748b; margin-top: 6px; }

        /* Gold accent line */
        .ba-gold-line {
            height: 3px; background: linear-gradient(90deg, #f0b429, #d4960d, #f0b429);
            margin-bottom: 22px; border-radius: 2px;
        }

        /* Title */
        .ba-title {
            text-align: center; margin-bottom: 22px; padding: 18px 20px;
            background: linear-gradient(135deg, #0d1f3c 0%, #1a3a5c 100%);
            border-radius: 10px; color: #fff; position: relative; overflow: hidden;
        }
        .ba-title::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, #f0b429, #fbbf24, #f0b429);
        }
        .ba-title h1 { font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; color: #f0b429; }
        .ba-title .ba-subtitle { font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 4px; font-style: italic; }

        /* Sections */
        .ba-section { margin-bottom: 18px; }
        .ba-section-title {
            font-size: 12px; font-weight: 700; color: #0d1f3c; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 10px; padding: 6px 12px;
            background: linear-gradient(90deg, #fffbeb, #fff); border-left: 3px solid #f0b429;
            border-radius: 0 6px 6px 0;
        }

        /* Info Grid */
        .ba-info-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; font-size: 11.5px;
            padding: 0 8px;
        }
        .ba-info-row { display: flex; gap: 8px; padding: 3px 0; }
        .ba-info-label { color: #64748b; min-width: 130px; flex-shrink: 0; }
        .ba-info-value { color: #1e293b; font-weight: 600; }

        /* Table */
        .ba-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px; }
        .ba-table th {
            padding: 9px 10px; text-align: left; background: #0d1f3c; color: #f0b429;
            font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .ba-table th:first-child { border-radius: 6px 0 0 0; }
        .ba-table th:last-child { border-radius: 0 6px 0 0; }
        .ba-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
        .ba-table tr:nth-child(even) { background: #fafbfc; }
        .ba-table tr:hover { background: #fffbeb; }

        /* Financial Summary Box */
        .ba-financial {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #f0b429; border-radius: 10px;
            padding: 16px 20px; margin-top: 12px;
        }
        .ba-financial-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 7px 0; font-size: 12.5px; color: #1e293b;
        }
        .ba-financial-row span:first-child { font-weight: 500; }
        .ba-financial-row span:last-child { font-weight: 700; font-family: 'Segoe UI', monospace; }
        .ba-financial-row.outstanding {
            border-top: 2px solid #0d1f3c; margin-top: 8px; padding-top: 10px;
            font-size: 15px;
        }
        .ba-financial-row.outstanding span:first-child { font-weight: 800; color: #0d1f3c; }
        .ba-financial-row.outstanding span:last-child { font-weight: 800; color: #b91c1c; }
        .ba-financial-row.paid-full span:last-child { color: #059669 !important; }

        /* Signatures */
        .ba-signatures {
            display: grid; grid-template-columns: 1fr 1fr; gap: 40px;
            margin-top: 40px; text-align: center;
        }
        .ba-sig-title { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 4px; }
        .ba-sig-space { height: 70px; border-bottom: 1px solid #1e293b; margin: 0 20px; }
        .ba-sig-name { font-size: 12px; font-weight: 700; color: #1e293b; margin-top: 6px; }
        .ba-sig-role { font-size: 10px; color: #64748b; }

        /* Footer */
        .ba-footer {
            margin-top: 30px; padding-top: 12px; border-top: 1px solid #e2e8f0;
            text-align: center; font-size: 9px; color: #94a3b8;
        }

        /* Print */
        @media print {
            body { background: #fff; }
            .page { box-shadow: none; margin: 0; padding: 30px 40px; border-top: 4px solid #f0b429; }
            .no-print { display: none !important; }
            .ba-table tr:hover { background: transparent; }
        }

        /* Action Bar */
        .action-bar {
            max-width: 210mm; margin: 0 auto 16px;
            display: flex; gap: 8px; justify-content: space-between; align-items: center;
            flex-wrap: wrap;
        }
        .action-bar-left { display: flex; gap: 8px; align-items: center; }
        .action-bar-right { display: flex; gap: 8px; align-items: center; }
        .action-btn {
            padding: 8px 18px; border-radius: 8px; text-decoration: none; font-size: 12px;
            font-weight: 600; cursor: pointer; border: none; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .action-btn-back { background: #fff; color: #475569; border: 1px solid #e2e8f0; }
        .action-btn-back:hover { background: #f8fafc; border-color: #cbd5e1; }
        .action-btn-print { background: linear-gradient(135deg, #f0b429, #d4960d); color: #0d1f3c; font-weight: 700; }
        .action-btn-print:hover { background: linear-gradient(135deg, #d4960d, #b7800a); }

        /* Language Toggle */
        .lang-toggle {
            display: flex; border-radius: 8px; overflow: hidden; border: 2px solid #f0b429;
        }
        .lang-btn {
            padding: 6px 16px; font-size: 12px; font-weight: 600; cursor: pointer;
            text-decoration: none; transition: all 0.15s; border: none;
        }
        .lang-btn.active { background: #f0b429; color: #0d1f3c; }
        .lang-btn:not(.active) { background: #fff; color: #64748b; }
        .lang-btn:not(.active):hover { background: #fffbeb; color: #0d1f3c; }
        .lang-label { font-size: 11px; color: #64748b; font-weight: 600; margin-right: 6px; }
    </style>
</head>
<body>

<!-- Action Bar -->
<div class="action-bar no-print">
    <div class="action-bar-left">
        <a href="report.php?id=<?php echo $id; ?>" class="action-btn action-btn-back"><?php echo $t['btn_back']; ?></a>
        <a href="dashboard.php" class="action-btn action-btn-back">Dashboard</a>
    </div>
    <div class="action-bar-right">
        <span class="lang-label">🌐 <?php echo $t['lang_label']; ?>:</span>
        <div class="lang-toggle">
            <a href="?id=<?php echo $id; ?>&lang=id" class="lang-btn <?php echo $lang === 'id' ? 'active' : ''; ?>">🇮🇩 Indonesia</a>
            <a href="?id=<?php echo $id; ?>&lang=en" class="lang-btn <?php echo $lang === 'en' ? 'active' : ''; ?>">🇬🇧 English</a>
        </div>
        <button onclick="window.print();" class="action-btn action-btn-print"><?php echo $t['btn_print']; ?></button>
    </div>
</div>

<div class="page">
    <!-- Header -->
    <div class="ba-header">
        <div class="ba-company">
            <div class="ba-logo">
                <?php if ($companyLogoUrl): ?>
                <img src="<?php echo $companyLogoUrl; ?>" alt="Logo">
                <?php else: ?>
                CQC
                <?php endif; ?>
            </div>
            <div>
                <div class="ba-company-name"><?php echo htmlspecialchars($companyName); ?></div>
                <div class="ba-company-tagline"><?php echo htmlspecialchars($companyTagline); ?></div>
                <div class="ba-company-contact">
                    <?php echo htmlspecialchars($companyAddress); ?>
                    <?php if ($companyCity): ?>, <?php echo htmlspecialchars($companyCity); ?><?php endif; ?>
                    <?php if ($companyPhone !== '-'): ?> | <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ba-doc-info">
            <div class="ba-doc-number">No: <?php echo $baNumber; ?></div>
            <div class="ba-doc-date"><?php echo $lang === 'en' ? date('F d, Y', strtotime($completionDate)) : date('d F Y', strtotime($completionDate)); ?></div>
        </div>
    </div>
    <div class="ba-gold-line"></div>

    <!-- Title -->
    <div class="ba-title">
        <h1><?php echo $t['doc_title']; ?></h1>
        <div class="ba-subtitle"><?php echo $t['doc_subtitle']; ?></div>
    </div>

    <!-- Opening -->
    <div class="ba-section">
        <p style="text-align: justify; margin-bottom: 10px; font-size: 11.5px;">
            <?php echo $t['opening']; ?>
        </p>
    </div>

    <!-- Project Info -->
    <div class="ba-section">
        <div class="ba-section-title"><?php echo $t['sec1']; ?></div>
        <div class="ba-info-grid">
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['project_name']; ?></span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['project_name']); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['project_code']; ?></span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['project_code']); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['client_name']; ?></span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['client_name'] ?? '-'); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['location']; ?></span>
                <span class="ba-info-value">: <?php echo htmlspecialchars($project['location'] ?? '-'); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['capacity']; ?></span>
                <span class="ba-info-value">: <?php echo number_format($project['solar_capacity_kwp'] ?? 0, 1); ?> kWp</span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['panel_count']; ?></span>
                <span class="ba-info-value">: <?php echo $project['panel_count'] ?? '-'; ?> <?php echo $t['units']; ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['start_date']; ?></span>
                <span class="ba-info-value">: <?php echo $project['start_date'] ? ($lang === 'en' ? date('F d, Y', strtotime($project['start_date'])) : date('d F Y', strtotime($project['start_date']))) : '-'; ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['end_date']; ?></span>
                <span class="ba-info-value">: <?php echo $lang === 'en' ? date('F d, Y', strtotime($completionDate)) : date('d F Y', strtotime($completionDate)); ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['duration']; ?></span>
                <span class="ba-info-value">: <?php echo $duration; ?> <?php echo $t['days']; ?></span>
            </div>
            <div class="ba-info-row">
                <span class="ba-info-label"><?php echo $t['status']; ?></span>
                <span class="ba-info-value" style="color: #059669;">: <?php echo $t['status_done']; ?></span>
            </div>
        </div>
    </div>

    <!-- Financial Summary -->
    <div class="ba-section">
        <div class="ba-section-title"><?php echo $t['sec2']; ?></div>
        
        <?php if (!empty($invoices)): ?>
        <p style="margin-bottom: 8px; font-size: 11px; color: #64748b; padding-left: 8px;"><?php echo $t['payment_detail']; ?></p>
        <table class="ba-table">
            <thead>
                <tr>
                    <th style="width: 35px;"><?php echo $t['no']; ?></th>
                    <th><?php echo $t['invoice']; ?></th>
                    <th><?php echo $t['termin']; ?></th>
                    <th style="text-align: right;"><?php echo $t['value']; ?></th>
                    <th style="text-align: right;"><?php echo $t['paid']; ?></th>
                    <th style="width: 60px;"><?php echo $t['status_col']; ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $i => $inv): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td style="font-weight: 600;"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                    <td><?php echo ($lang === 'en' ? 'Term ' : 'Termin ') . $inv['termin_number']; ?> (<?php echo $inv['percentage']; ?>%)</td>
                    <td style="text-align: right; font-family: 'Segoe UI', monospace;">Rp <?php echo number_format($inv['total_amount'], 0, ',', '.'); ?></td>
                    <td style="text-align: right; font-family: 'Segoe UI', monospace; color: <?php echo floatval($inv['paid_amount']) > 0 ? '#059669' : '#94a3b8'; ?>;">
                        Rp <?php echo number_format($inv['paid_amount'], 0, ',', '.'); ?>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-size: 9px; padding: 2px 8px; border-radius: 4px; font-weight: 600;
                            <?php echo $inv['payment_status'] === 'paid' ? 'background: #dcfce7; color: #166534;' : 'background: #fef3c7; color: #92400e;'; ?>">
                            <?php echo ucfirst($inv['payment_status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="ba-financial">
            <div class="ba-financial-row">
                <span><?php echo $t['contract_value']; ?></span>
                <span>Rp <?php echo number_format($contractValue, 0, ',', '.'); ?></span>
            </div>
            <div class="ba-financial-row" style="color: #059669;">
                <span><?php echo $t['total_paid']; ?></span>
                <span style="color: #059669;">− Rp <?php echo number_format($totalPaid, 0, ',', '.'); ?></span>
            </div>
            <div class="ba-financial-row outstanding <?php echo $outstandingBalance <= 0 ? 'paid-full' : ''; ?>">
                <span><?php echo $t['outstanding']; ?></span>
                <span>
                    <?php if ($outstandingBalance <= 0): ?>
                        <?php echo $t['paid_full']; ?>
                    <?php else: ?>
                        Rp <?php echo number_format($outstandingBalance, 0, ',', '.'); ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Scope -->
    <div class="ba-section">
        <div class="ba-section-title"><?php echo $t['sec3']; ?></div>
        <p style="text-align: justify; font-size: 11.5px; padding: 0 8px;">
            <?php echo $t['scope_text']; ?>
        </p>
    </div>

    <!-- Declaration -->
    <div class="ba-section">
        <div class="ba-section-title"><?php echo $t['sec4']; ?></div>
        <p style="text-align: justify; font-size: 11.5px; margin-bottom: 8px; padding: 0 8px;">
            <?php echo $t['declaration_intro']; ?>
        </p>
        <ol style="font-size: 11.5px; padding-left: 28px; line-height: 1.9;">
            <li><?php echo $t['decl1']; ?></li>
            <li><?php echo $t['decl2']; ?></li>
            <li><?php echo $t['decl3']; ?></li>
            <li><?php echo $t['decl4']; ?></li>
        </ol>
    </div>

    <!-- Signatures -->
    <div class="ba-signatures">
        <div>
            <div class="ba-sig-title"><?php echo $t['sig_contractor']; ?></div>
            <div class="ba-sig-space"></div>
            <div class="ba-sig-name">(........................................)</div>
            <div class="ba-sig-role"><?php echo htmlspecialchars($companyName); ?></div>
        </div>
        <div>
            <div class="ba-sig-title"><?php echo $t['sig_client']; ?></div>
            <div class="ba-sig-space"></div>
            <div class="ba-sig-name"><?php echo htmlspecialchars($project['client_name'] ?? '(........................................)'); ?></div>
            <div class="ba-sig-role"><?php echo $t['sig_role_client']; ?></div>
        </div>
    </div>

    <!-- Footer -->
    <div class="ba-footer">
        <p><?php echo $t['footer_text']; ?></p>
        <p style="margin-top: 4px;"><?php echo $baNumber; ?> | <?php echo htmlspecialchars($project['project_code']); ?></p>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>

</body>
</html>
