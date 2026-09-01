<?php

/**
 * Cashbook Export to Excel (.xlsx via HTML table)
 * Accepts the same GET filters as index.php
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Auth check (same as index.php)
$auth = new Auth();
$auth->requireLogin();
$db = Database::getInstance();
$pdo = $db->getConnection();
$pdo->exec("SET time_zone = '+07:00'");

$masterDbName = DB_NAME;

// ── Filters (same as index.php) ──
$rawFilterDate  = trim($_GET['date'] ?? '');
$rawFilterMonth  = trim($_GET['month'] ?? '');
$filterType     = trim($_GET['type'] ?? 'all');
$filterDivision = trim($_GET['division'] ?? 'all');
$filterPayment  = trim($_GET['payment'] ?? 'all');
$filterUser     = trim($_GET['user'] ?? 'all');
$filterSearch   = trim($_GET['search'] ?? '');

$filterDate = '';
$filterMonth = '';
$activePeriodType = 'all';

if (!empty($rawFilterMonth) && preg_match('/^\d{4}-\d{2}$/', $rawFilterMonth)) {
    $filterMonth = $rawFilterMonth;
    $activePeriodType = 'month';
} elseif (!empty($rawFilterDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFilterDate)) {
    $filterDate = $rawFilterDate;
    $activePeriodType = 'date';
} elseif (!isset($_GET['date']) && !isset($_GET['month']) && !isset($_GET['type'])) {
    $filterMonth = date('Y-m');
    $activePeriodType = 'month';
}

if ($activePeriodType === 'month') {
    $filterDate = '';
} elseif ($activePeriodType === 'date') {
    $filterMonth = '';
}

// Build WHERE
$whereClauses = [];
$params = [];

if ($activePeriodType === 'date' && !empty($filterDate)) {
    $whereClauses[] = "cb.transaction_date = :date";
    $params['date'] = $filterDate;
} elseif ($activePeriodType === 'month' && !empty($filterMonth)) {
    $whereClauses[] = "DATE_FORMAT(cb.transaction_date, '%Y-%m') = :month";
    $params['month'] = $filterMonth;
}

if (!empty($filterType) && $filterType !== 'all') {
    $whereClauses[] = "cb.transaction_type = :type";
    $params['type'] = $filterType;
}

if (!empty($filterDivision) && $filterDivision !== 'all') {
    $whereClauses[] = "cb.division_id = :division";
    $params['division'] = $filterDivision;
}

if (!empty($filterPayment) && $filterPayment !== 'all') {
    if ($filterPayment === 'ota_all') {
        $whereClauses[] = "cb.payment_method LIKE 'OTA %'";
    } else {
        $whereClauses[] = "cb.payment_method = :payment";
        $params['payment'] = $filterPayment;
    }
}

if (!empty($filterUser) && $filterUser !== 'all') {
    $whereClauses[] = "cb.created_by = :user_id";
    $params['user_id'] = $filterUser;
}

if (!empty($filterSearch)) {
    $whereClauses[] = "(cb.description LIKE :search OR c.category_name LIKE :search2)";
    $params['search'] = '%' . $filterSearch . '%';
    $params['search2'] = '%' . $filterSearch . '%';
}

$whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Check transaction_time column
$hasTransactionTime = true;
try {
    $pdo->query("SELECT transaction_time FROM cash_book LIMIT 1");
} catch (\Throwable $e) {
    $hasTransactionTime = false;
}
$orderBy = $hasTransactionTime ? 'cb.transaction_date ASC, cb.transaction_time ASC' : 'cb.transaction_date ASC, cb.id ASC';

$transactions = $db->fetchAll(
    "SELECT 
        cb.*,
        COALESCE(d.division_name, 'Unknown') as division_name,
        COALESCE(c.category_name, 'Unknown') as category_name,
        COALESCE(u.full_name, 'System') as created_by_name
    FROM cash_book cb
    LEFT JOIN divisions d ON cb.division_id = d.id
    LEFT JOIN categories c ON cb.category_id = c.id
    LEFT JOIN {$masterDbName}.users u ON cb.created_by = u.id
    {$whereSQL}
    ORDER BY {$orderBy}",
    $params
);

// Company name
$companyNameSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$companyName = ($companyNameSetting && $companyNameSetting['setting_value']) ? $companyNameSetting['setting_value'] : (defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Buku Kas');

// Build filename
$filePeriod = '';
if (!empty($filterDate)) {
    $filePeriod = $filterDate;
} elseif (!empty($filterMonth)) {
    $filePeriod = $filterMonth;
} else {
    $filePeriod = 'semua';
}
$fileName = 'Buku_Kas_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $companyName) . '_' . $filePeriod . '.xls';

// ── Output as Excel-compatible HTML ──
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$totalIncome = 0;
$totalExpense = 0;
foreach ($transactions as $t) {
    if ($t['transaction_type'] === 'income') $totalIncome += $t['amount'];
    else $totalExpense += $t['amount'];
}
$saldo = $totalIncome - $totalExpense;

// Period label
$periodLabel = '';
if (!empty($filterDate)) {
    $periodLabel = 'Tanggal: ' . date('d/m/Y', strtotime($filterDate));
} elseif (!empty($filterMonth)) {
    $periodLabel = 'Bulan: ' . date('F Y', strtotime($filterMonth . '-01'));
} else {
    $periodLabel = 'Semua Periode';
}
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">

<head>
    <meta charset="utf-8">
    <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Buku Kas</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
    <style>
        td,
        th {
            mso-number-format: \@;
        }

        .num {
            mso-number-format: "#,##0";
        }
    </style>
</head>

<body>
    <table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse; font-family:Arial; font-size:11px;">
        <tr>
            <td colspan="9" style="font-size:16px; font-weight:bold; text-align:center; background:#0d1f3c; color:#ffffff; padding:10px;">
                Buku Kas Besar — <?php echo htmlspecialchars($companyName); ?>
            </td>
        </tr>
        <tr>
            <td colspan="9" style="text-align:center; background:#f0f4ff; padding:6px; font-size:11px;">
                <?php echo htmlspecialchars($periodLabel); ?> &nbsp;|&nbsp; Diekspor: <?php echo date('d/m/Y H:i'); ?> WIB
            </td>
        </tr>
        <tr>
            <td colspan="9"></td>
        </tr>
        <tr style="background:#1e3a5f; color:#ffffff; font-weight:bold; text-align:center;">
            <th style="width:60px;">No</th>
            <th style="width:90px;">Tanggal</th>
            <th style="width:60px;">Waktu</th>
            <th style="width:120px;">Divisi</th>
            <th style="width:140px;">Kategori</th>
            <th style="width:70px;">Tipe</th>
            <th style="width:80px;">Metode</th>
            <th style="width:120px;">Jumlah</th>
            <th style="width:200px;">Keterangan</th>
            <th style="width:100px;">Input By</th>
        </tr>
        <?php $no = 0;
        foreach ($transactions as $t): $no++;
            $isIncome = $t['transaction_type'] === 'income';
            $rowBg = $no % 2 === 0 ? '#f9fafb' : '#ffffff';
            $descClean = $t['description'] ?: '-';
            $descClean = trim(preg_replace('/\[CQC_PROJECT:\d+\]\s*/', '', $descClean));
            $descClean = trim(preg_replace('/\[OPERATIONAL_OFFICE\]\s*/', '', $descClean));
        ?>
            <tr style="background:<?php echo $rowBg; ?>;">
                <td style="text-align:center;"><?php echo $no; ?></td>
                <td style="text-align:center;"><?php echo date('d/m/Y', strtotime($t['transaction_date'])); ?></td>
                <td style="text-align:center;"><?php echo isset($t['transaction_time']) ? date('H:i', strtotime($t['transaction_time'])) : '-'; ?></td>
                <td><?php echo htmlspecialchars($t['division_name']); ?></td>
                <td><?php echo htmlspecialchars($t['category_name']); ?></td>
                <td style="text-align:center; color:<?php echo $isIncome ? '#059669' : '#dc2626'; ?>; font-weight:bold;">
                    <?php echo $isIncome ? 'MASUK' : 'KELUAR'; ?>
                </td>
                <td style="text-align:center;"><?php echo htmlspecialchars(strtoupper($t['payment_method'] ?? '-')); ?></td>
                <td class="num" style="text-align:right; font-weight:bold; color:<?php echo $isIncome ? '#059669' : '#dc2626'; ?>;">
                    <?php echo number_format($t['amount'], 0, ',', '.'); ?>
                </td>
                <td><?php echo htmlspecialchars($descClean); ?></td>
                <td style="text-align:center;"><?php echo htmlspecialchars($t['created_by_name'] ?: 'System'); ?></td>
            </tr>
        <?php endforeach; ?>

        <tr>
            <td colspan="10"></td>
        </tr>
        <tr style="background:#e8f5e9; font-weight:bold;">
            <td colspan="7" style="text-align:right; padding:8px;">Total Pemasukan:</td>
            <td class="num" style="text-align:right; color:#059669; padding:8px;"><?php echo number_format($totalIncome, 0, ',', '.'); ?></td>
            <td colspan="2"></td>
        </tr>
        <tr style="background:#fce4ec; font-weight:bold;">
            <td colspan="7" style="text-align:right; padding:8px;">Total Pengeluaran:</td>
            <td class="num" style="text-align:right; color:#dc2626; padding:8px;"><?php echo number_format($totalExpense, 0, ',', '.'); ?></td>
            <td colspan="2"></td>
        </tr>
        <tr style="background:#e3f2fd; font-weight:bold; font-size:13px;">
            <td colspan="7" style="text-align:right; padding:8px;">Saldo:</td>
            <td class="num" style="text-align:right; color:<?php echo $saldo >= 0 ? '#059669' : '#dc2626'; ?>; padding:8px;">
                <?php echo number_format($saldo, 0, ',', '.'); ?>
            </td>
            <td colspan="2"></td>
        </tr>
    </table>
</body>

</html>