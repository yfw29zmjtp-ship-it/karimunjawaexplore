<?php

/**
 * Sunsea - Owner mobile view: Input Transaksi Kas (income/expense)
 * Menulis langsung ke tabel cash_book yang sama dipakai system utama (finance.php),
 * jadi otomatis sinkron - tidak ada proses sync terpisah.
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: owner-login.php');
    exit;
}
$auth->requireLogin();

$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'] ?? '', ['developer', 'owner'], true)) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getSunseaConnection();
sunseaEnsureFinanceSchema($pdo);
$username = $currentUser['username'] ?? 'owner';

$categoryOptions = [
    'Tiket & Retribusi',
    'Transportasi',
    'Penginapan',
    'Konsumsi / Catering',
    'Guide & Coordinator',
    'Fasilitas Tambahan',
    'Operasional Kantor',
    'Lainnya',
];

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type        = ($_POST['type'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $date        = $_POST['transaction_date'] ?: date('Y-m-d');
    $category    = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount      = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
    $reference   = trim($_POST['reference'] ?? '');

    if ($description === '' || $amount <= 0) {
        $errorMsg = 'Keterangan dan jumlah wajib diisi (jumlah harus lebih dari 0).';
    } else {
        try {
            $pdo->prepare("
                INSERT INTO cash_book (transaction_date, type, category, description, amount, reference, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$date, $type, $category, $description, $amount, $reference, $username]);
            header('Location: owner-finance.php');
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Input Transaksi';
$backUrl = 'owner-finance.php';
include 'owner-mobile-header.php';
?>

<style>
    .ob-form-group {
        margin-bottom: 12px;
    }

    .ob-form-label {
        display: block;
        font-size: 11.5px;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 5px;
    }

    .ob-form-input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 9px;
        font-size: 13px;
        background: #fff;
        color: var(--text);
        font-family: inherit;
    }

    .ob-mode-switch {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
    }

    .ob-mode-btn {
        flex: 1;
        text-align: center;
        padding: 10px;
        border-radius: 10px;
        border: 1.5px solid var(--border);
        background: #fff;
        color: var(--muted);
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
    }

    .ob-mode-btn.type-expense.active {
        background: var(--danger);
        border-color: var(--danger);
        color: #fff;
    }

    .ob-mode-btn.type-income.active {
        background: var(--success);
        border-color: var(--success);
        color: #fff;
    }

    .ob-submit-btn {
        width: 100%;
        padding: 13px;
        border: none;
        border-radius: 10px;
        background: var(--ocean);
        color: #fff;
        font-size: 14px;
        font-weight: 800;
        cursor: pointer;
        margin-top: 8px;
    }

    .ob-alert-error {
        background: #FEE2E2;
        color: var(--danger);
        padding: 10px 12px;
        border-radius: 9px;
        font-size: 12.5px;
        margin-bottom: 12px;
    }
</style>

<?php if ($errorMsg): ?>
    <div class="ob-alert-error"><i data-feather="alert-triangle"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<form method="POST" id="txForm">
    <input type="hidden" name="type" id="typeInput" value="expense">

    <div class="ob-mode-switch">
        <div class="ob-mode-btn type-expense active" id="btnTypeExpense" onclick="setTxType('expense')">
            <i data-feather="arrow-down-circle"></i> Pengeluaran
        </div>
        <div class="ob-mode-btn type-income" id="btnTypeIncome" onclick="setTxType('income')">
            <i data-feather="arrow-up-circle"></i> Pemasukan
        </div>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Tanggal</label>
        <input type="date" name="transaction_date" class="ob-form-input" value="<?php echo date('Y-m-d'); ?>" required>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Kategori</label>
        <select name="category" class="ob-form-input">
            <option value="">-- Pilih Kategori (opsional) --</option>
            <?php foreach ($categoryOptions as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Keterangan</label>
        <input type="text" name="description" class="ob-form-input" placeholder="Contoh: Sewa kapal, Guide, dll" required>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Jumlah (Rp)</label>
        <input type="text" name="amount" class="ob-form-input" placeholder="0" inputmode="numeric" required>
    </div>

    <div class="ob-form-group">
        <label class="ob-form-label">Referensi (opsional)</label>
        <input type="text" name="reference" class="ob-form-input" placeholder="No. nota / referensi lain">
    </div>

    <button type="submit" class="ob-submit-btn">Simpan Transaksi</button>
</form>

<script>
    function setTxType(type) {
        document.getElementById('typeInput').value = type;
        document.getElementById('btnTypeExpense').classList.toggle('active', type === 'expense');
        document.getElementById('btnTypeIncome').classList.toggle('active', type === 'income');
    }
</script>

<?php include 'owner-mobile-footer.php'; ?>
