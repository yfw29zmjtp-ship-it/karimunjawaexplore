<?php

/**
 * LAPORAN PENGAJUAN UANG - Cetak daftar pengeluaran projek
 * Bisa difilter per status pembayaran (belum lunas / lunas)
 */

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

$projectId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$projectId) {
    die('Project ID tidak valid');
}

$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
    die('Projek tidak ditemukan');
}

$purchaseSources = ['jepara' => 'Jepara', 'karimunjawa' => 'Karimunjawa'];
$paymentStatuses = ['belum_lunas' => 'Belum Lunas', 'lunas' => 'Lunas'];
$categories = [
    'material' => 'Material/Bahan',
    'upah' => 'Upah Kerja',
    'transport' => 'Transport',
    'equipment' => 'Peralatan',
    'consumable' => 'Bahan Habis Pakai',
    'other' => 'Lainnya'
];

$statusFilter = $_GET['payment_status'] ?? 'all';
if (!isset($paymentStatuses[$statusFilter])) {
    $statusFilter = 'all';
}

$sql = "SELECT * FROM project_expenses WHERE project_id = ?";
$params = [$projectId];
if ($statusFilter !== 'all') {
    $sql .= " AND payment_status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY expense_date ASC, created_at ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
foreach ($expenses as $exp) {
    $total += (float)($exp['amount_idr'] ?? $exp['amount'] ?? 0);
}

$filterLabel = $paymentStatuses[$statusFilter] ?? 'Semua';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Pengajuan Uang - <?= htmlspecialchars($project['name']) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #1f2937;
            padding: 24px;
            max-width: 900px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.2rem;
            margin-bottom: 0.25rem;
        }

        .subtitle {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 1.25rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            text-align: left;
        }

        th {
            background: #f3f4f6;
        }

        .text-right {
            text-align: right;
        }

        tfoot td {
            font-weight: 700;
            background: #f9fafb;
        }

        .badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge.lunas {
            background: #d1fae5;
            color: #059669;
        }

        .badge.belum_lunas {
            background: #fee2e2;
            color: #dc2626;
        }

        .print-actions {
            margin-bottom: 1.25rem;
        }

        .print-actions button {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
            font-size: 0.85rem;
        }

        @media print {
            .print-actions {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="print-actions">
        <button onclick="window.print()">🖨️ Cetak</button>
    </div>

    <h1>Laporan Pengajuan Uang</h1>
    <div class="subtitle">
        Projek: <strong><?= htmlspecialchars($project['name']) ?></strong>
        &nbsp;|&nbsp; Status: <strong><?= htmlspecialchars($filterLabel) ?></strong>
        &nbsp;|&nbsp; Dicetak: <?= date('d M Y H:i') ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Kategori</th>
                <th>Sumber</th>
                <th>Pemborong</th>
                <th>Keterangan</th>
                <th class="text-right">Jumlah</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($expenses)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; color:#6b7280;">Tidak ada data pengeluaran</td>
                </tr>
            <?php else: ?>
                <?php foreach ($expenses as $exp):
                    $paySt = $exp['payment_status'] ?? 'belum_lunas';
                    $src = $exp['purchase_source'] ?? '';
                    $contractor = trim($exp['contractor_name'] ?? '');
                    $amount = (float)($exp['amount_idr'] ?? $exp['amount'] ?? 0);
                ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($exp['expense_date'] ?? $exp['created_at'])) ?></td>
                        <td><?= $categories[$exp['category'] ?? 'other'] ?? $exp['category'] ?></td>
                        <td><?= $src ? ($purchaseSources[$src] ?? $src) : '-' ?></td>
                        <td><?= $contractor !== '' ? htmlspecialchars($contractor) : '-' ?></td>
                        <td><?= htmlspecialchars($exp['description'] ?? '-') ?></td>
                        <td class="text-right">Rp <?= number_format($amount, 0, ',', '.') ?></td>
                        <td><span class="badge <?= $paySt ?>"><?= $paymentStatuses[$paySt] ?? $paySt ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">TOTAL</td>
                <td class="text-right">Rp <?= number_format($total, 0, ',', '.') ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>

</html>
