<?php
/**
 * KASBOOK ENTRY - Record Daily Cash Transactions
 * Admin masukkan transaksi kas masuk/keluar setiap hari
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Check authorization
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$message = '';
$message_type = '';

try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $businessId = getMasterBusinessId();
    
    // Get petty cash account
    $stmt = $masterDb->prepare(
        "SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'petty_cash' LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $pettyCashAccount = $stmt->fetch();
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
        $transactionType = $_POST['transaction_type'] ?? ''; // debit (masuk) or credit (keluar)
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
        $kasSource = $_POST['kas_source'] ?? ''; // 'owner' or 'revenue'
        
        // Validation
        $errors = [];
        if (!in_array($transactionType, ['debit', 'credit'])) $errors[] = 'Jenis transaksi tidak valid';
        if ($amount <= 0) $errors[] = 'Nominal harus lebih dari 0';
        if (empty($description)) $errors[] = 'Keterangan harus diisi';
        if ($transactionType === 'debit' && !in_array($kasSource, ['owner', 'revenue'])) {
            $errors[] = 'Sumber kas harus dipilih untuk kas masuk';
        }
        
        if (empty($errors)) {
            // Add prefix to description based on source
            $fullDescription = $description;
            if ($transactionType === 'debit' && $kasSource === 'owner') {
                $fullDescription = '[OWNER] ' . $description;
            } elseif ($transactionType === 'debit' && $kasSource === 'revenue') {
                $fullDescription = '[REVENUE] ' . $description;
            }
            
            // Insert transaction
            $stmt = $masterDb->prepare(
                "INSERT INTO cash_account_transactions 
                 (cash_account_id, transaction_type, amount, description, transaction_date, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            
            if ($stmt->execute([$pettyCashAccount['id'], $transactionType, $amount, $fullDescription, $transactionDate])) {
                $message = '✅ Transaksi berhasil dicatat!';
                $message_type = 'success';
            } else {
                $message = '❌ Gagal mencatat transaksi';
                $message_type = 'danger';
            }
        } else {
            $message = '⚠️ ' . implode(', ', $errors);
            $message_type = 'warning';
        }
    }
    
} catch (Exception $e) {
    $message = '❌ Error: ' . $e->getMessage();
    $message_type = 'danger';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasbook Entry - Record Transactions</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">
    <style>
        :root {
            --primary: #0071e3;
            --success: #34c759;
            --danger: #ff3b30;
            --gray: #6c757d;
            --border: #e5e7eb;
            --light: #f5f5f5;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--light);
        }

        .navbar-custom {
            background: white;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .container-form {
            max-width: 600px;
            margin: 32px auto;
            padding: 0 16px;
        }

        .card {
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid var(--border);
            padding: 24px;
            border-radius: 12px 12px 0 0;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .card-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #333;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.1);
            outline: none;
        }

        .form-group.kas-source {
            display: none;
        }

        .form-group.kas-source.active {
            display: block;
        }

        .options-group {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .option-btn {
            padding: 12px 16px;
            border: 2px solid var(--border);
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .option-btn:hover {
            border-color: var(--primary);
        }

        .option-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-submit {
            background: var(--success);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background: #2BAE4E;
        }

        .alert-custom {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: none;
        }

        .alert-success {
            background: rgba(52, 199, 89, 0.1);
            color: #2BAE4E;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: rgba(255, 59, 48, 0.1);
            color: #C70039;
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: rgba(255, 149, 0, 0.1);
            color: #D98500;
            border-left: 4px solid var(--warning);
        }

        .info-box {
            background: rgba(0, 113, 227, 0.05);
            border: 1px solid rgba(0, 113, 227, 0.2);
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
            font-size: 12px;
            color: #333;
            line-height: 1.6;
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 24px 0;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 24px;
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <div class="navbar-custom">
        <div class="navbar-brand p-3" style="max-width: 1200px; margin: 0 auto;">
            <h6 style="font-size: 14px; font-weight: 700; margin: 0;">📝 Kasbook Entry</h6>
        </div>
    </div>

    <!-- Form Container -->
    <div class="container-form">
        <a href="kasbook-daily-simple.php" class="back-link">← Back to Kasbook Daily</a>

        <!-- Message -->
        <?php if (!empty($message)): ?>
            <div class="alert-custom alert-<?= $message_type ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Entry Form Card -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">➕ Tambah Transaksi Kas</h5>
                <small style="color: var(--gray);">Catat kas masuk atau keluar hari ini</small>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_transaction">

                    <!-- Transaction Type Selection -->
                    <div class="form-group">
                        <label class="form-label">Jenis Transaksi</label>
                        <div class="options-group">
                            <label class="option-btn active" onclick="selectType('debit', this)">
                                <input type="radio" name="transaction_type" value="debit" checked onchange="updateKasSource()">
                                💵 Kas Masuk
                            </label>
                            <label class="option-btn" onclick="selectType('credit', this)">
                                <input type="radio" name="transaction_type" value="credit" onchange="updateKasSource()">
                                💸 Kas Keluar
                            </label>
                        </div>
                    </div>

                    <!-- Kas Source (only for incoming) -->
                    <div class="form-group kas-source active" id="kasSourceGroup">
                        <label class="form-label">Sumber Kas (dari mana?)</label>
                        <div class="options-group">
                            <label class="option-btn active" onclick="selectSource('owner', this)">
                                <input type="radio" name="kas_source" value="owner" checked>
                                👤 Dari Owner
                            </label>
                            <label class="option-btn" onclick="selectSource('revenue', this)">
                                <input type="radio" name="kas_source" value="revenue">
                                🏨 Dari Revenue
                            </label>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div class="form-group">
                        <label class="form-label">Nominal (Rp)</label>
                        <input type="number" name="amount" class="form-control" placeholder="Contoh: 500000" required step="1000">
                    </div>

                    <!-- Description -->
                    <div class="form-group">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="description" class="form-control" placeholder="Contoh: Bayar gaji, Beli supplies, dll" required>
                    </div>

                    <!-- Date -->
                    <div class="form-group">
                        <label class="form-label">Tanggal Transaksi</label>
                        <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="divider"></div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-submit">
                        ✅ Simpan Transaksi
                    </button>

                    <!-- Info Box -->
                    <div class="info-box">
                        <strong>💡 Tips:</strong><br>
                        • <strong>Kas Masuk dari Owner:</strong> Setoran modal untuk operasional harian<br>
                        • <strong>Kas Masuk dari Revenue:</strong> Uang dari penjualan hotel yang masuk ke kas<br>
                        • <strong>Kas Keluar:</strong> Pengeluaran operasional (gaji, supplies, maintenance, dll)
                    </div>
                </form>
            </div>
        </div>

        <!-- Footer Links -->
        <div style="margin-top: 32px; text-align: center; color: var(--gray); font-size: 12px;">
            <a href="kasbook-daily-simple.php" style="color: var(--primary); text-decoration: none; margin-right: 16px;">📊 Lihat Summary</a>
            <a href="dashboard.php" style="color: var(--primary); text-decoration: none;">🏠 Dashboard Owner</a>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        feather.replace();

        function selectType(type, element) {
            document.querySelectorAll('.options-group label').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            document.querySelector(`input[value="${type}"]`).checked = true;
            updateKasSource();
        }

        function selectSource(source, element) {
            element.parentElement.querySelectorAll('label').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            document.querySelector(`input[value="${source}"]`).checked = true;
        }

        function updateKasSource() {
            const type = document.querySelector('input[name="transaction_type"]:checked').value;
            const kasSourceGroup = document.getElementById('kasSourceGroup');
            
            if (type === 'debit') {
                kasSourceGroup.classList.add('active');
            } else {
                kasSourceGroup.classList.remove('active');
            }
        }
    </script>
</body>
</html>
