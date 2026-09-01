<?php
/**
 * KASBOOK DAILY SIMPLE - Owner Cash Management (FIXED VERSION)
 * Clean & Simple Interface for Daily Cash Tracking
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$errorMsg = '';
try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $businessId = getMasterBusinessId();
    
    $selectedDate = $_GET['date'] ?? date('Y-m-d');
    
    // Validate date format
    DateTime::createFromFormat('Y-m-d', $selectedDate) or die('Invalid date format');
    
    $displayDate = new DateTime($selectedDate);
    
    // Get Petty Cash account (single source of truth)
    $stmt = $masterDb->prepare(
        "SELECT id, account_name, current_balance FROM cash_accounts 
         WHERE business_id = ? AND account_type = 'petty_cash' LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $pettyCashAccount = $stmt->fetch();
    
    if (!$pettyCashAccount) {
        $errorMsg = '❌ Petty Cash account tidak ditemukan. Hubungi admin.';
    } else {
        $pettyCashAccountId = $pettyCashAccount['id'];
        
        // KAS MASUK - Owner capital injections untuk hari ini
        $kasMasukOwner = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? 
             AND transaction_type = 'debit' AND description LIKE '%OWNER%'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasMasukOwner = $stmt->fetchColumn();
        
        // KAS MASUK - Revenue cash untuk hari ini
        $kasMasukRevenue = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? 
             AND transaction_type = 'debit' AND description LIKE '%REVENUE%'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasMasukRevenue = $stmt->fetchColumn();
        
        $kasMasukTotal = $kasMasukOwner + $kasMasukRevenue;
        
        // KAS KELUAR - operasional untuk hari ini
        $kasKeluar = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? AND transaction_type = 'credit'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasKeluar = $stmt->fetchColumn();
        
        // SALDO AKHIR - hitung dari semua transaksi sampai hari ini
        $saldoAkhir = $pettyCashAccount['current_balance'] ?? 0;
        
        // Get transaksi detail untuk hari ini
        $transaksiDetail = [];
        $stmt = $masterDb->prepare(
            "SELECT id, transaction_type, amount, description, reference_number, created_at
             FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ?
             ORDER BY created_at ASC"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $transaksiDetail = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    $errorMsg = '❌ Database Error: ' . $e->getMessage();
}

function formatCurrency($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasbook Daily - Simple Tracking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">
    <style>
        :root {
            --primary: #0071e3;
            --success: #34c759;
            --danger: #ff3b30;
            --gray: #6c757d;
            --text-light: #8b8b8f;
            --border: #e5e7eb;
            --light: #f5f5f5;
            --white: #ffffff;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #eef2f8 100%);
            min-height: 100vh;
        }

        .navbar-custom {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }

        .container-main {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title {
            font-size: 32px;
            font-weight: 800;
            margin: 0;
            background: linear-gradient(135deg, #0071e3 0%, #0055b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-subtitle {
            font-size: 14px;
            color: var(--text-light);
            margin: 0;
            font-weight: 500;
        }

        .date-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .date-controls input,
        .date-controls button {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: var(--white);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .date-controls input:hover,
        .date-controls input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.08);
            outline: none;
        }

        .date-controls button {
            background: linear-gradient(135deg, var(--primary) 0%, #0055b8 100%);
            color: white;
            border: none;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 113, 227, 0.3);
            transition: all 0.3s ease;
        }

        .date-controls button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 113, 227, 0.4);
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .card-simple {
            background: var(--white);
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .card-simple::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .card-simple:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: rgba(0, 113, 227, 0.1);
        }

        .card-simple:hover::before {
            opacity: 1;
        }

        .card-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 28px;
            transition: all 0.3s ease;
        }

        .card-simple:hover .card-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .card-icon.incoming {
            background: linear-gradient(135deg, rgba(52, 199, 89, 0.15) 0%, rgba(52, 199, 89, 0.05) 100%);
        }

        .card-icon.outgoing {
            background: linear-gradient(135deg, rgba(255, 59, 48, 0.15) 0%, rgba(255, 59, 48, 0.05) 100%);
        }

        .card-icon.balance {
            background: linear-gradient(135deg, rgba(0, 113, 227, 0.15) 0%, rgba(0, 113, 227, 0.05) 100%);
        }

        .card-label {
            font-size: 10px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 0.8px;
        }

        .card-value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            font-family: 'Monaco', 'Courier New', monospace;
            color: #1a1a1a;
            line-height: 1.2;
        }

        .card-description {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 16px;
            font-weight: 500;
        }

        .breakdown {
            font-size: 12px;
            padding-top: 16px;
            border-top: 1px solid rgba(0, 0, 0, 0.04);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            color: var(--text-light);
            font-size: 12px;
        }

        .breakdown-item strong {
            color: #1a1a1a;
            font-weight: 600;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-wrapper {
            background: var(--white);
            border: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        }

        .table-wrapper table {
            width: 100%;
            margin: 0;
        }

        .table-wrapper th {
            background: linear-gradient(135deg, rgba(0, 113, 227, 0.02) 0%, rgba(0, 113, 227, 0.01) 100%);
            padding: 14px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            color: #1a1a1a;
        }

        .table-wrapper td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            font-size: 13px;
            color: #1a1a1a;
        }

        .table-wrapper tbody tr {
            transition: all 0.2s ease;
        }

        .table-wrapper tbody tr:hover {
            background: rgba(0, 113, 227, 0.02);
        }

        .table-wrapper tr:last-child td {
            border: none;
        }

        .badge-debit {
            background: linear-gradient(135deg, rgba(52, 199, 89, 0.15) 0%, rgba(52, 199, 89, 0.05) 100%);
            color: #34c759;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            border: 1px solid rgba(52, 199, 89, 0.2);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-credit {
            background: linear-gradient(135deg, rgba(255, 59, 48, 0.15) 0%, rgba(255, 59, 48, 0.05) 100%);
            color: #ff3b30;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            border: 1px solid rgba(255, 59, 48, 0.2);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .error-box {
            background: linear-gradient(135deg, rgba(255, 59, 48, 0.08) 0%, rgba(255, 59, 48, 0.02) 100%);
            border: 1px solid rgba(255, 59, 48, 0.2);
            color: #c81a0f;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        @media (max-width: 768px) {
            .header-section {
                flex-direction: column;
                align-items: flex-start;
            }
            .cards-grid {
                grid-template-columns: 1fr;
            }
            .header-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="navbar-custom">
        <div class="container-main" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;">
            <h6 style="margin: 0; font-size: 14px; font-weight: 700;">📊 Kasbook Daily</h6>
            <a href="dashboard.php" style="text-decoration: none; color: var(--gray); font-size: 12px;">← Dashboard</a>
        </div>
    </div>

    <div class="container-main">
        <div class="header-section">
            <div>
                <h1 class="header-title">Kasbook Daily</h1>
                <p class="header-subtitle"><?= $displayDate->format('l, d F Y') ?></p>
            </div>
            <div class="date-controls">
                <input type="date" id="dateInput" value="<?= $selectedDate ?>" class="form-control" style="width: auto; max-width: 150px;">
                <button onclick="updateDate()" style="min-width: 100px;">Load</button>
                <button onclick="window.print()" style="min-width: 100px; background: var(--gray);">Print</button>
            </div>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="error-box"><?= $errorMsg ?></div>
        <?php else: ?>

            <!-- Main Cards -->
            <div class="cards-grid">
                <div class="card-simple">
                    <div class="card-icon incoming">💵</div>
                    <div class="card-label">Kas Masuk Hari Ini</div>
                    <div class="card-value"><?= formatCurrency($kasMasukTotal) ?></div>
                    <div class="card-description">Total uang masuk ke kasir</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>📦 Dari Owner:</span>
                            <strong><?= formatCurrency($kasMasukOwner) ?></strong>
                        </div>
                        <div class="breakdown-item">
                            <span>🏨 Dari Revenue:</span>
                            <strong><?= formatCurrency($kasMasukRevenue) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-simple">
                    <div class="card-icon outgoing">💸</div>
                    <div class="card-label">Kas Keluar Hari Ini</div>
                    <div class="card-value"><?= formatCurrency($kasKeluar) ?></div>
                    <div class="card-description">Pengeluaran operasional</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Operasional:</span>
                            <strong><?= formatCurrency($kasKeluar) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-simple">
                    <div class="card-icon balance">💰</div>
                    <div class="card-label">Saldo Akhir Hari</div>
                    <div class="card-value"><?= formatCurrency($saldoAkhir) ?></div>
                    <div class="card-description">Kas di tangan (final)</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Status:</span>
                            <strong style="color: <?= $saldoAkhir >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $saldoAkhir >= 0 ? '✅ OK' : '⚠️ MINUS' ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detail Table -->
            <div>
                <h5 class="section-title">📋 Detail Transaksi Hari Ini</h5>
                
                <?php if (empty($transaksiDetail)): ?>
                    <div class="table-wrapper">
                        <div class="empty-state">
                            <p>Belum ada transaksi hari ini</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Jenis</th>
                                    <th>Keterangan</th>
                                    <th style="text-align: right;">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transaksiDetail as $txn): ?>
                                <tr>
                                    <td>
                                        <?= isset($txn['created_at']) ? substr($txn['created_at'], 11, 5) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($txn['transaction_type'] == 'debit'): ?>
                                            <span class="badge-debit">MASUK</span>
                                        <?php else: ?>
                                            <span class="badge-credit">KELUAR</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($txn['description'] ?? '-') ?></td>
                                    <td style="text-align: right; font-weight: 600;">
                                        <?= formatCurrency($txn['amount']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div style="text-align: center; margin-top: 40px; padding: 24px; background: linear-gradient(135deg, rgba(0, 113, 227, 0.03) 0%, rgba(0, 113, 227, 0.01) 100%); border-radius: 12px; border: 1px solid rgba(0, 113, 227, 0.1);">
                <a href="kasbook-entry.php" style="display: inline-flex; align-items: center; gap: 8px; color: white; text-decoration: none; background: linear-gradient(135deg, var(--primary) 0%, #0055b8 100%); padding: 10px 18px; border-radius: 8px; margin-right: 12px; font-weight: 600; font-size: 13px; box-shadow: 0 2px 8px rgba(0, 113, 227, 0.3); transition: all 0.3s ease;">
                    <span>+</span> Tambah Transaksi
                </a>
                <span style="color: #ccc; margin: 0 8px;">|</span>
                <a href="dashboard.php" style="display: inline-flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; margin-left: 12px; font-weight: 600; font-size: 13px; transition: all 0.3s ease;">
                    ← Kembali ke Dashboard
                </a>
            </div>

        <?php endif; ?>
    </div>

    <script>
        function updateDate() {
            const date = document.getElementById('dateInput').value;
            if (date) {
                window.location.href = '?date=' + date;
            }
        }
        document.getElementById('dateInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') updateDate();
        });
    </script>
</body>
</html>
