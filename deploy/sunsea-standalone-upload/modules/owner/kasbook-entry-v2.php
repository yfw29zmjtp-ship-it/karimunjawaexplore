<?php
/**
 * KASBOOK ENTRY - Simple Form untuk Record Transaksi (FIXED VERSION)
 * Menambah Kas Masuk (Owner/Revenue) atau Kas Keluar (Operasional)
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
$successMsg = '';

try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $businessId = getMasterBusinessId();
    
    // Get Petty Cash account
    $stmt = $masterDb->prepare(
        "SELECT id, account_name, current_balance FROM cash_accounts 
         WHERE business_id = ? AND account_type = 'petty_cash' LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $pettyCashAccount = $stmt->fetch();
    
    if (!$pettyCashAccount) {
        $errorMsg = '❌ Petty Cash account tidak ditemukan. Hubungi admin.';
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$errorMsg) {
        $transactionType = $_POST['transaction_type'] ?? '';
        $kasmasukSource = $_POST['kasmasuk_source'] ?? '';
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $transactionDate = $_POST['transaction_date'] ?? date('Y-m-d');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        
        // Validasi
        if (!in_array($transactionType, ['debit', 'credit'])) {
            $errorMsg = '❌ Jenis transaksi tidak valid';
        } elseif ($transactionType == 'debit' && !in_array($kasmasukSource, ['owner', 'revenue'])) {
            $errorMsg = '❌ Sumber Kas Masuk tidak valid';
        } elseif ($amount <= 0) {
            $errorMsg = '❌ Nominal harus lebih dari 0';
        } elseif (empty($description)) {
            $errorMsg = '❌ Keterangan harus diisi';
        }
        
        if (!$errorMsg) {
            // Build full description with source tag
            $fullDescription = $description;
            
            if ($transactionType == 'debit') {
                if ($kasmasukSource == 'owner') {
                    $fullDescription = "[OWNER] " . $description;
                } elseif ($kasmasukSource == 'revenue') {
                    $fullDescription = "[REVENUE] " . $description;
                }
            }
            
            try {
                $stmt = $masterDb->prepare(
                    "INSERT INTO cash_account_transactions 
                    (cash_account_id, transaction_type, amount, description, reference_number, transaction_date, created_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
                );
                
                $stmt->execute([
                    $pettyCashAccount['id'],
                    $transactionType,
                    $amount,
                    $fullDescription,
                    $referenceNumber ?: NULL,
                    $transactionDate,
                    $_SESSION['user_id'] ?? NULL
                ]);
                
                // Update current balance di cash_accounts
                $newBalance = $pettyCashAccount['current_balance'] + 
                    ($transactionType == 'debit' ? $amount : -$amount);
                
                $stmt = $masterDb->prepare(
                    "UPDATE cash_accounts SET current_balance = ? WHERE id = ?"
                );
                $stmt->execute([$newBalance, $pettyCashAccount['id']]);
                
                $successMsg = '✅ Transaksi berhasil disimpan!';
                
                // Reset form
                $transactionType = '';
                $kasmasukSource = '';
                $amount = 0;
                $description = '';
                $transactionDate = date('Y-m-d');
                $referenceNumber = '';
                
                // Reload account info
                $stmt = $masterDb->prepare(
                    "SELECT id, account_name, current_balance FROM cash_accounts 
                     WHERE business_id = ? AND account_type = 'petty_cash' LIMIT 1"
                );
                $stmt->execute([$businessId]);
                $pettyCashAccount = $stmt->fetch();
                
            } catch (Exception $e) {
                $errorMsg = '❌ Gagal menyimpan: ' . $e->getMessage();
            }
        }
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
    <title>Kasbook Entry - Tambah Transaksi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
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
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .container-main {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .form-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 16px;
        }

        .form-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        .form-section {
            margin-bottom: 24px;
        }

        .section-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        .option-group {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .option-group.full {
            grid-template-columns: 1fr;
        }

        .option-btn {
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            background: white;
            cursor: pointer;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .option-btn:hover {
            border-color: var(--primary);
            background: rgba(0, 113, 227, 0.05);
        }

        .option-btn.selected {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .option-btn.selected.debit {
            background: var(--success);
            border-color: var(--success);
        }

        .option-btn.selected.credit {
            background: var(--danger);
            border-color: var(--danger);
        }

        .source-options {
            display: none;
        }

        .source-options.show {
            display: block;
        }

        .form-group-custom {
            margin-bottom: 16px;
        }

        .form-group-custom label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
            display: block;
        }

        .form-group-custom input,
        .form-group-custom textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
        }

        .form-group-custom textarea {
            resize: none;
            min-height: 60px;
        }

        .form-group-custom input:focus,
        .form-group-custom textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 113, 227, 0.1);
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 12px;
        }

        .btn-submit:hover {
            background: #0052a3;
        }

        .btn-secondary {
            width: 100%;
            padding: 10px;
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            margin-top: 8px;
        }

        .alert-box {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .alert-error {
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .alert-success {
            background: rgba(52, 199, 89, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .info-box {
            background: rgba(0, 113, 227, 0.05);
            border: 1px solid rgba(0, 113, 227, 0.2);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 12px;
        }

        .info-box strong {
            color: var(--primary);
        }

        .saldo-info {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            margin-bottom: 16px;
        }

        .saldo-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .saldo-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            font-family: monospace;
        }

        @media (max-width: 768px) {
            .container-main {
                padding: 16px;
            }
            .option-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="navbar-custom">
        <div class="container-main" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;">
            <h6 style="margin: 0; font-size: 14px; font-weight: 700;">📝 Tambah Transaksi</h6>
            <a href="kasbook-daily-simple-v2.php" style="text-decoration: none; color: var(--gray); font-size: 12px;">← Kasbook</a>
        </div>
    </div>

    <div class="container-main">
        <div class="form-card">

            <?php if (!empty($errorMsg)): ?>
                <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <?php if (!empty($successMsg)): ?>
                <div class="alert-box alert-success"><?= htmlspecialchars($successMsg) ?></div>
            <?php endif; ?>

            <?php if (empty($errorMsg) && $pettyCashAccount): ?>
                <div class="saldo-info">
                    <div class="saldo-label">Saldo Kas Saat Ini</div>
                    <div class="saldo-value"><?= formatCurrency($pettyCashAccount['current_balance']) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Transaction Type Selection -->
                <div class="form-section">
                    <div class="section-label">1. Jenis Transaksi</div>
                    <div class="option-group">
                        <label class="option-btn <?= ($_POST['transaction_type'] ?? '') == 'debit' ? 'selected debit' : '' ?>">
                            💵 Kas Masuk
                            <input type="radio" name="transaction_type" value="debit" 
                                   style="display: none;" 
                                   onchange="toggleSource()"
                                   <?= ($_POST['transaction_type'] ?? '') == 'debit' ? 'checked' : '' ?>>
                        </label>
                        <label class="option-btn <?= ($_POST['transaction_type'] ?? '') == 'credit' ? 'selected credit' : '' ?>">
                            💸 Kas Keluar
                            <input type="radio" name="transaction_type" value="credit" 
                                   style="display: none;" 
                                   onchange="toggleSource()"
                                   <?= ($_POST['transaction_type'] ?? '') == 'credit' ? 'checked' : '' ?>>
                        </label>
                    </div>
                </div>

                <!-- Source Selection (for Kas Masuk) -->
                <div class="form-section source-options <?= ($_POST['transaction_type'] ?? '') == 'debit' ? 'show' : '' ?>">
                    <div class="section-label">2. Sumber Kas Masuk</div>
                    <div class="option-group full">
                        <label class="option-btn <?= ($_POST['kasmasuk_source'] ?? '') == 'owner' ? 'selected' : '' ?>">
                            👤 Setoran dari Owner
                            <input type="radio" name="kasmasuk_source" value="owner" 
                                   style="display: none;"
                                   <?= ($_POST['kasmasuk_source'] ?? '') == 'owner' ? 'checked' : '' ?>>
                        </label>
                        <label class="option-btn <?= ($_POST['kasmasuk_source'] ?? '') == 'revenue' ? 'selected' : '' ?>">
                            🏨 Pendapatan Revenue
                            <input type="radio" name="kasmasuk_source" value="revenue" 
                                   style="display: none;"
                                   <?= ($_POST['kasmasuk_source'] ?? '') == 'revenue' ? 'checked' : '' ?>>
                        </label>
                    </div>
                </div>

                <!-- Amount -->
                <div class="form-group-custom">
                    <label>Nominal</label>
                    <input type="number" name="amount" 
                           placeholder="0" 
                           step="1" 
                           min="0"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                           required>
                </div>

                <!-- Description -->
                <div class="form-group-custom">
                    <label>Keterangan</label>
                    <textarea name="description" 
                              placeholder="Contoh: Bayar listrik, Beli supplies, dll"
                              required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <!-- Reference Number (Optional) -->
                <div class="form-group-custom">
                    <label>No. Ref. / Invoice (Opsional)</label>
                    <input type="text" name="reference_number" 
                           placeholder="Contoh: INV-001, CVR-123"
                           value="<?= htmlspecialchars($_POST['reference_number'] ?? '') ?>">
                </div>

                <!-- Transaction Date -->
                <div class="form-group-custom">
                    <label>Tanggal Transaksi</label>
                    <input type="date" name="transaction_date" 
                           value="<?= htmlspecialchars($_POST['transaction_date'] ?? date('Y-m-d')) ?>"
                           required>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit">✓ Simpan Transaksi</button>
                <a href="kasbook-daily-simple-v2.php" class="btn-secondary">Batal</a>
            </form>

            <div class="info-box" style="margin-top: 24px;">
                <strong>💡 Tips:</strong>
                <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                    <li>Kas Masuk = uang masuk (dari owner atau revenue)</li>
                    <li>Kas Keluar = pengeluaran operasional</li>
                    <li>Keterangan harus jelas untuk audit trail</li>
                </ul>
            </div>

        </div>

        <p style="text-align: center; font-size: 11px; color: var(--gray); margin-top: 16px;">
            <a href="kasbook-daily-simple-v2.php" style="color: var(--primary); text-decoration: none;">← Lihat Ringkasan Kasbook</a>
        </p>
    </div>

    <script>
        function toggleSource() {
            const typeDebit = document.querySelector('input[name="transaction_type"][value="debit"]').checked;
            const sourceOptions = document.querySelector('.source-options');
            
            if (typeDebit) {
                sourceOptions.classList.add('show');
            } else {
                sourceOptions.classList.remove('show');
            }
        }

        // Better option button styling
        document.querySelectorAll('.option-btn').forEach(btn => {
            const radio = btn.querySelector('input[type="radio"]');
            
            btn.addEventListener('click', function() {
                radio.checked = true;
                radio.dispatchEvent(new Event('change'));
                
                // Update styling
                document.querySelectorAll(`.option-btn[name="${radio.name}"]`).forEach(b => {
                    b.classList.remove('selected', 'debit', 'credit');
                });
                
                this.classList.add('selected');
                if (radio.value === 'debit') this.classList.add('debit');
                if (radio.value === 'credit') this.classList.add('credit');
            });
            
            if (radio.checked) {
                btn.classList.add('selected');
                if (radio.value === 'debit') btn.classList.add('debit');
                if (radio.value === 'credit') btn.classList.add('credit');
            }
        });
    </script>
</body>
</html>
