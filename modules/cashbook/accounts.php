<?php
/**
 * Cash Account Management - Setup Rekening / Akun Kas
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// Business feature detection (config-based)
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isCQC = $hasProjectModule; // Legacy compatibility

// Master DB connection
$masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$businessId = getMasterBusinessId();

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // ADD account
    if ($_GET['ajax'] === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['account_name'] ?? '');
        $type = trim($_POST['account_type'] ?? 'bank');
        $desc = trim($_POST['description'] ?? '');
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Nama akun wajib diisi']);
            exit;
        }
        
        $stmt = $masterDb->prepare("INSERT INTO cash_accounts (business_id, account_name, account_type, description, current_balance, is_active, created_at) VALUES (?, ?, ?, ?, 0, 1, NOW())");
        $stmt->execute([$businessId, $name, $type, $desc]);
        
        echo json_encode(['success' => true, 'message' => 'Akun berhasil ditambahkan', 'id' => $masterDb->lastInsertId()]);
        exit;
    }
    
    // UPDATE account
    if ($_GET['ajax'] === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['account_name'] ?? '');
        $type = trim($_POST['account_type'] ?? 'bank');
        $desc = trim($_POST['description'] ?? '');
        
        if (empty($name) || !$id) {
            echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
            exit;
        }
        
        $stmt = $masterDb->prepare("UPDATE cash_accounts SET account_name = ?, account_type = ?, description = ? WHERE id = ? AND business_id = ?");
        $stmt->execute([$name, $type, $desc, $id, $businessId]);
        
        echo json_encode(['success' => true, 'message' => 'Akun berhasil diupdate']);
        exit;
    }
    
    // DELETE account
    if ($_GET['ajax'] === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
            exit;
        }
        
        // Check if account has transactions
        $stmt = $masterDb->prepare("SELECT COUNT(*) as cnt FROM cash_account_transactions WHERE cash_account_id = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        if ($count > 0) {
            // Soft delete - just deactivate
            $stmt = $masterDb->prepare("UPDATE cash_accounts SET is_active = 0 WHERE id = ? AND business_id = ?");
            $stmt->execute([$id, $businessId]);
            echo json_encode(['success' => true, 'message' => 'Akun dinonaktifkan (ada transaksi terkait)']);
        } else {
            $stmt = $masterDb->prepare("DELETE FROM cash_accounts WHERE id = ? AND business_id = ?");
            $stmt->execute([$id, $businessId]);
            echo json_encode(['success' => true, 'message' => 'Akun berhasil dihapus']);
        }
        exit;
    }
    
    // LIST accounts
    if ($_GET['ajax'] === 'list') {
        $stmt = $masterDb->prepare("SELECT id, account_name, account_type, description, current_balance, is_active, created_at FROM cash_accounts WHERE business_id = ? ORDER BY is_active DESC, account_type, account_name");
        $stmt->execute([$businessId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'accounts' => $accounts]);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// Full page view
$pageTitle = $isCQC ? '☀️ Setup Rekening' : '⚙️ Kelola Akun Kas';
$pageSubtitle = 'Atur rekening dan akun kas bisnis';

// Load accounts
$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY is_active DESC, account_type, account_name");
$stmt->execute([$businessId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>

<?php if ($isCQC): ?>
<style>
:root, body { --primary-color: #f0b429 !important; --primary-dark: #d4960d !important; --secondary-color: #0d1f3c !important; }
.btn-primary { background: linear-gradient(135deg, #0d1f3c, #1a3a5c) !important; color: #f0b429 !important; border: none !important; }
.btn-primary:hover { background: linear-gradient(135deg, #122a4e, #1f4570) !important; }
</style>
<?php endif; ?>

<style>
.accounts-container { max-width: 920px; margin: 0 auto; }
.account-card {
    display: grid; grid-template-columns: auto 1fr auto; gap: 1rem; align-items: center;
    padding: 1rem 1.25rem; background: var(--bg-primary); border: 1px solid var(--bg-tertiary);
    border-radius: 10px; margin-bottom: 0.5rem; transition: all 0.2s;
}
.account-card:hover { border-color: <?php echo $isCQC ? '#f0b429' : 'var(--primary-color)'; ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.account-icon {
    width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
}
.account-icon.cash { background: rgba(16,185,129,0.12); }
.account-icon.bank { background: rgba(59,130,246,0.12); }
.account-icon.e-wallet { background: rgba(245,158,11,0.12); }
.account-icon.owner_capital { background: rgba(139,92,246,0.12); }
.account-icon.credit_card { background: rgba(236,72,153,0.12); }
.account-name { font-weight: 700; font-size: 0.9rem; color: var(--text-primary); }
.account-meta { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem; }
.account-balance { text-align: right; }
.account-balance .amount { font-weight: 700; font-size: 0.95rem; color: var(--text-primary); }
.account-balance .label { font-size: 0.7rem; color: var(--text-muted); }
.account-actions { display: flex; gap: 0.4rem; }
.account-actions button { 
    width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--bg-tertiary); 
    background: var(--bg-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: all 0.15s;
}
.account-actions button:hover { border-color: var(--primary-color); }
.account-actions button.delete:hover { background: #fee2e2; border-color: #ef4444; }
.inactive-tag { font-size: 0.65rem; background: #fee2e2; color: #dc2626; padding: 0.1rem 0.4rem; border-radius: 4px; font-weight: 600; }

/* Add Form */
.add-form-card {
    padding: 1.25rem; background: var(--bg-primary); border: 2px dashed <?php echo $isCQC ? '#f0b429' : 'var(--primary-color)'; ?>;
    border-radius: 12px; margin-bottom: 1rem;
}
.add-form-card .form-row { display: grid; grid-template-columns: 1fr 150px 1fr auto; gap: 0.75rem; align-items: end; }
.add-form-card label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem; display: block; }
.add-form-card input, .add-form-card select { height: 38px; font-size: 0.85rem; }
</style>

<div class="accounts-container">
    <!-- Header -->
    <div class="card" style="margin-bottom: 1rem; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; <?php echo $isCQC ? 'border-left: 4px solid #f0b429;' : ''; ?>">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: <?php echo $isCQC ? 'linear-gradient(135deg, #f0b429, #d4960d)' : 'linear-gradient(135deg, var(--primary-color), var(--secondary-color))'; ?>; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                🏦
            </div>
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0; color: var(--text-primary);">Setup Rekening & Akun Kas</h3>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">Kelola rekening bank, kas tunai, dan akun modal</p>
            </div>
        </div>
        <a href="add.php" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.5rem 1rem;">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>
    
    <!-- Add New Account Form -->
    <div class="add-form-card" id="addAccountForm">
        <h4 style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary); margin: 0 0 0.75rem; display: flex; align-items: center; gap: 0.4rem;">
            <span style="font-size: 1rem;">➕</span> Tambah Rekening Baru
        </h4>
        <div class="form-row">
            <div>
                <label>Nama Rekening *</label>
                <input type="text" id="newAccName" class="form-control" placeholder="cth: BNI PT CQC Solar">
            </div>
            <div>
                <label>Jenis</label>
                <select id="newAccType" class="form-control">
                    <option value="bank">🏦 Bank</option>
                    <option value="cash">💵 Kas Tunai</option>
                    <option value="e-wallet">📱 E-Wallet</option>
                    <option value="owner_capital">👤 Modal Owner</option>
                    <option value="credit_card">💳 Kartu Kredit</option>
                </select>
            </div>
            <div>
                <label>Keterangan</label>
                <input type="text" id="newAccDesc" class="form-control" placeholder="Nomor rekening / catatan">
            </div>
            <div>
                <button onclick="addAccount()" class="btn btn-primary" style="height: 38px; padding: 0 1.25rem; font-size: 0.85rem; white-space: nowrap;">
                    <i data-feather="plus" style="width: 14px; height: 14px;"></i> Tambah
                </button>
            </div>
        </div>
    </div>
    
    <!-- Account List -->
    <div class="card" style="padding: 1.25rem;">
        <h4 style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin: 0 0 1rem; display: flex; align-items: center; gap: 0.5rem;">
            📋 Daftar Rekening (<?php echo count($accounts); ?>)
        </h4>
        
        <div id="accountList">
            <?php if (empty($accounts)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🏦</div>
                    <p>Belum ada rekening. Tambahkan rekening baru di atas.</p>
                </div>
            <?php else: ?>
                <?php 
                $typeIcons = ['cash'=>'💵','bank'=>'🏦','e-wallet'=>'📱','owner_capital'=>'👤','credit_card'=>'💳'];
                $typeLabels = ['cash'=>'Kas Tunai','bank'=>'Bank','e-wallet'=>'E-Wallet','owner_capital'=>'Modal Owner','credit_card'=>'Kartu Kredit'];
                foreach ($accounts as $acc): 
                ?>
                <div class="account-card" id="acc-<?php echo $acc['id']; ?>" data-id="<?php echo $acc['id']; ?>">
                    <div class="account-icon <?php echo $acc['account_type']; ?>">
                        <?php echo $typeIcons[$acc['account_type']] ?? '💰'; ?>
                    </div>
                    <div>
                        <div class="account-name">
                            <?php echo htmlspecialchars($acc['account_name']); ?>
                            <?php if (!$acc['is_active']): ?><span class="inactive-tag">Nonaktif</span><?php endif; ?>
                        </div>
                        <div class="account-meta">
                            <?php echo $typeLabels[$acc['account_type']] ?? $acc['account_type']; ?>
                            <?php if ($acc['description']): ?> · <?php echo htmlspecialchars($acc['description']); ?><?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div class="account-balance">
                            <div class="amount">Rp <?php echo number_format($acc['current_balance'], 0, ',', '.'); ?></div>
                            <div class="label">Saldo</div>
                        </div>
                        <div class="account-actions">
                            <button onclick="editAccount(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['account_name'])); ?>', '<?php echo $acc['account_type']; ?>', '<?php echo htmlspecialchars(addslashes($acc['description'] ?? '')); ?>')" title="Edit">
                                <i data-feather="edit-2" style="width: 14px; height: 14px; color: #3b82f6;"></i>
                            </button>
                            <button class="delete" onclick="deleteAccount(<?php echo $acc['id']; ?>, '<?php echo htmlspecialchars(addslashes($acc['account_name'])); ?>')" title="Hapus">
                                <i data-feather="trash-2" style="width: 14px; height: 14px; color: #ef4444;"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: none; align-items: center; justify-content: center;">
    <div style="background: var(--bg-primary); border-radius: 12px; padding: 1.5rem; width: 90%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <h4 style="font-size: 1rem; font-weight: 700; margin: 0 0 1rem;">✏️ Edit Rekening</h4>
        <input type="hidden" id="editAccId">
        <div style="margin-bottom: 0.75rem;">
            <label style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">Nama Rekening</label>
            <input type="text" id="editAccName" class="form-control" style="height: 38px;">
        </div>
        <div style="margin-bottom: 0.75rem;">
            <label style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">Jenis</label>
            <select id="editAccType" class="form-control" style="height: 38px;">
                <option value="bank">🏦 Bank</option>
                <option value="cash">💵 Kas Tunai</option>
                <option value="e-wallet">📱 E-Wallet</option>
                <option value="owner_capital">👤 Modal Owner</option>
                <option value="credit_card">💳 Kartu Kredit</option>
            </select>
        </div>
        <div style="margin-bottom: 1rem;">
            <label style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 0.25rem;">Keterangan</label>
            <input type="text" id="editAccDesc" class="form-control" style="height: 38px;" placeholder="Nomor rekening / catatan">
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button onclick="closeEditModal()" class="btn btn-secondary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Batal</button>
            <button onclick="saveEditAccount()" class="btn btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Simpan</button>
        </div>
    </div>
</div>

<script>
feather.replace();

const typeIcons = {cash:'💵',bank:'🏦','e-wallet':'📱',owner_capital:'👤',credit_card:'💳'};
const typeLabels = {cash:'Kas Tunai',bank:'Bank','e-wallet':'E-Wallet',owner_capital:'Modal Owner',credit_card:'Kartu Kredit'};

function addAccount() {
    const name = document.getElementById('newAccName').value.trim();
    const type = document.getElementById('newAccType').value;
    const desc = document.getElementById('newAccDesc').value.trim();
    
    if (!name) { alert('Nama rekening wajib diisi!'); return; }
    
    const formData = new FormData();
    formData.append('account_name', name);
    formData.append('account_type', type);
    formData.append('description', desc);
    
    fetch('accounts.php?ajax=add', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function editAccount(id, name, type, desc) {
    document.getElementById('editAccId').value = id;
    document.getElementById('editAccName').value = name;
    document.getElementById('editAccType').value = type;
    document.getElementById('editAccDesc').value = desc;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function saveEditAccount() {
    const formData = new FormData();
    formData.append('id', document.getElementById('editAccId').value);
    formData.append('account_name', document.getElementById('editAccName').value.trim());
    formData.append('account_type', document.getElementById('editAccType').value);
    formData.append('description', document.getElementById('editAccDesc').value.trim());
    
    fetch('accounts.php?ajax=update', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

function deleteAccount(id, name) {
    if (!confirm('Hapus rekening "' + name + '"?')) return;
    
    const formData = new FormData();
    formData.append('id', id);
    
    fetch('accounts.php?ajax=delete', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php include '../../includes/footer.php'; ?>
