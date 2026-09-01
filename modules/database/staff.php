<?php
/**
 * Database Staff - List & Manage Staff/Karyawan
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Database Staf';

// Ensure table exists
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS staff (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_code VARCHAR(30) UNIQUE,
            full_name VARCHAR(100) NOT NULL,
            nickname VARCHAR(50),
            position VARCHAR(100),
            department VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(50),
            id_number VARCHAR(30),
            birth_date DATE,
            join_date DATE,
            bank_name VARCHAR(100),
            bank_account VARCHAR(50),
            bank_holder VARCHAR(100),
            emergency_contact VARCHAR(100),
            emergency_phone VARCHAR(20),
            daily_rate DECIMAL(15,2) DEFAULT 0,
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    // Table might already exist
}

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    
    $data = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'nickname' => trim($_POST['nickname'] ?? ''),
        'position' => trim($_POST['position'] ?? ''),
        'department' => trim($_POST['department'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'id_number' => trim($_POST['id_number'] ?? ''),
        'birth_date' => $_POST['birth_date'] ?: null,
        'join_date' => $_POST['join_date'] ?: null,
        'bank_name' => trim($_POST['bank_name'] ?? ''),
        'bank_account' => trim($_POST['bank_account'] ?? ''),
        'bank_holder' => trim($_POST['bank_holder'] ?? ''),
        'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
        'emergency_phone' => trim($_POST['emergency_phone'] ?? ''),
        'daily_rate' => (float)str_replace([',', '.'], ['', '.'], $_POST['daily_rate'] ?? '0'),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    try {
        if ($action === 'edit' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $db->update('staff', $data, 'id = :where_id', ['where_id' => $id]);
            $_SESSION['success'] = 'Staff berhasil diupdate';
        } else {
            // Generate staff code
            $lastCode = $db->fetchOne("SELECT staff_code FROM staff ORDER BY id DESC LIMIT 1");
            if ($lastCode) {
                $num = (int)substr($lastCode['staff_code'], 4) + 1;
            } else {
                $num = 1;
            }
            $data['staff_code'] = 'STF-' . str_pad($num, 4, '0', STR_PAD_LEFT);
            $data['created_by'] = $currentUser['id'];
            
            $db->insert('staff', $data);
            $_SESSION['success'] = 'Staff berhasil ditambahkan';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menyimpan: ' . $e->getMessage();
    }
    
    header('Location: staff.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->delete('staff', 'id = ?', [$id]);
        $_SESSION['success'] = 'Staff berhasil dihapus';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menghapus: ' . $e->getMessage();
    }
    header('Location: staff.php');
    exit;
}

// Handle toggle status
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        $current = $db->fetchOne("SELECT is_active FROM staff WHERE id = ?", [$id]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->update('staff', ['is_active' => $newStatus], 'id = :where_id', ['where_id' => $id]);
        $_SESSION['success'] = 'Status staff berhasil diubah';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal mengubah status: ' . $e->getMessage();
    }
    header('Location: staff.php');
    exit;
}

// Get edit data if editing
$editData = null;
if (isset($_GET['edit'])) {
    $editData = $db->fetchOne("SELECT * FROM staff WHERE id = ?", [(int)$_GET['edit']]);
}

// Get filters
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';

// Build query
$params = [];
$conditions = [];
if ($search) {
    $conditions[] = "(full_name LIKE :search OR staff_code LIKE :search OR position LIKE :search OR phone LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($department) {
    $conditions[] = "department = :department";
    $params['department'] = $department;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$staffList = $db->fetchAll("SELECT * FROM staff {$where} ORDER BY full_name", $params);

// Get unique departments for filter
$departments = $db->fetchAll("SELECT DISTINCT department FROM staff WHERE department IS NOT NULL AND department != '' ORDER BY department");

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <nav style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                <a href="index.php" style="color: var(--primary-color);">Database</a> / Staf
            </nav>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                👷 Database Staf
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola data staf/karyawan untuk project assignment</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="showModal('addModal')">
            <i data-feather="plus" style="width: 16px; height: 16px;"></i>
            Tambah Staf
        </button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Search -->
<div class="card" style="margin-bottom: 1.25rem; padding: 1rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, kode, posisi, atau phone..." 
               value="<?php echo htmlspecialchars($search); ?>" style="flex: 1;">
        <select name="department" class="form-control" style="width: 180px;">
            <option value="">Semua Departemen</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $department === $dept['department'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($dept['department']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Cari
        </button>
        <?php if ($search || $department): ?>
            <a href="staff.php" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $total = count($staffList);
    $active = count(array_filter($staffList, fn($s) => $s['is_active'] == 1));
    $inactive = $total - $active;
    ?>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #f59e0b20; display: flex; align-items: center; justify-content: center;">
                <i data-feather="users" style="width: 20px; height: 20px; color: #f59e0b;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Total</div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?php echo $total; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #10b98120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="user-check" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Aktif</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?php echo $active; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #ef444420; display: flex; align-items: center; justify-content: center;">
                <i data-feather="user-x" style="width: 20px; height: 20px; color: #ef4444;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Nonaktif</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?php echo $inactive; ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Posisi</th>
                    <th>Departemen</th>
                    <th>Phone</th>
                    <th>Rate/Hari</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">Belum ada data staf</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffList as $staff): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);"><?php echo $staff['staff_code']; ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                                <?php if ($staff['nickname']): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($staff['nickname']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($staff['position'] ?: '-'); ?></td>
                            <td>
                                <?php if ($staff['department']): ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($staff['department']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($staff['phone'] ?: '-'); ?></td>
                            <td style="font-weight: 600;">
                                <?php if ($staff['daily_rate'] > 0): ?>
                                    Rp <?php echo number_format($staff['daily_rate'], 0, ',', '.'); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($staff['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 0.25rem; justify-content: center;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="editStaff(<?php echo htmlspecialchars(json_encode($staff)); ?>)" title="Edit">
                                        <i data-feather="edit" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <a href="?toggle=<?php echo $staff['id']; ?>" class="btn btn-sm <?php echo $staff['is_active'] ? 'btn-warning' : 'btn-success'; ?>" title="Toggle Status">
                                        <i data-feather="<?php echo $staff['is_active'] ? 'eye-off' : 'eye'; ?>" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="?delete=<?php echo $staff['id']; ?>" class="btn btn-sm btn-danger" title="Hapus" onclick="return confirm('Hapus staf ini?')">
                                        <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="addModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah Staf</h3>
            <button type="button" onclick="hideModal('addModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" id="staffForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="staffId">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap <span style="color: red;">*</span></label>
                    <input type="text" name="full_name" id="full_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nama Panggilan</label>
                    <input type="text" name="nickname" id="nickname" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Posisi/Jabatan</label>
                    <input type="text" name="position" id="position" class="form-control" placeholder="Misal: Tukang Bangunan">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Departemen</label>
                    <input type="text" name="department" id="department" class="form-control" list="deptList" placeholder="Misal: Kontraktor">
                    <datalist id="deptList">
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>">
                        <?php endforeach; ?>
                        <option value="Kontraktor">
                        <option value="Elektrik">
                        <option value="Plumbing">
                        <option value="Interior">
                        <option value="Admin">
                    </datalist>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">No. KTP</label>
                    <input type="text" name="id_number" id="id_number" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tanggal Lahir</label>
                    <input type="date" name="birth_date" id="birth_date" class="form-control">
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Alamat</label>
                    <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Kota</label>
                    <input type="text" name="city" id="city" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Tanggal Bergabung</label>
                    <input type="date" name="join_date" id="join_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bank</label>
                    <input type="text" name="bank_name" id="bank_name" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">No. Rekening</label>
                    <input type="text" name="bank_account" id="bank_account" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Atas Nama</label>
                    <input type="text" name="bank_holder" id="bank_holder" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Rate Harian (Rp)</label>
                    <input type="text" name="daily_rate" id="daily_rate" class="form-control" placeholder="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Kontak Darurat</label>
                    <input type="text" name="emergency_contact" id="emergency_contact" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Phone Darurat</label>
                    <input type="text" name="emergency_phone" id="emergency_phone" class="form-control">
                </div>
                
                <div class="form-group" style="display: flex; align-items: center; padding-top: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="is_active" checked>
                        <span>Aktif</span>
                    </label>
                </div>
                
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Catatan</label>
                    <textarea name="notes" id="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding: 1rem 1.5rem; border-top: 1px solid var(--border-color);">
                <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.modal-content {
    background: var(--bg-primary);
    border-radius: 12px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}
.modal-header h3 {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
}
</style>

<script>
feather.replace();

function showModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function hideModal(id) {
    document.getElementById(id).style.display = 'none';
    document.getElementById('staffForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('staffId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Staf';
    document.getElementById('is_active').checked = true;
}

function editStaff(data) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('staffId').value = data.id;
    document.getElementById('modalTitle').textContent = 'Edit Staf';
    
    document.getElementById('full_name').value = data.full_name || '';
    document.getElementById('nickname').value = data.nickname || '';
    document.getElementById('position').value = data.position || '';
    document.getElementById('department').value = data.department || '';
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('id_number').value = data.id_number || '';
    document.getElementById('birth_date').value = data.birth_date || '';
    document.getElementById('address').value = data.address || '';
    document.getElementById('city').value = data.city || '';
    document.getElementById('join_date').value = data.join_date || '';
    document.getElementById('bank_name').value = data.bank_name || '';
    document.getElementById('bank_account').value = data.bank_account || '';
    document.getElementById('bank_holder').value = data.bank_holder || '';
    document.getElementById('daily_rate').value = data.daily_rate || '';
    document.getElementById('emergency_contact').value = data.emergency_contact || '';
    document.getElementById('emergency_phone').value = data.emergency_phone || '';
    document.getElementById('notes').value = data.notes || '';
    document.getElementById('is_active').checked = data.is_active == 1;
    
    showModal('addModal');
}

document.getElementById('addModal').addEventListener('click', function(e) {
    if (e.target === this) hideModal('addModal');
});

<?php if ($editData): ?>
editStaff(<?php echo json_encode($editData); ?>);
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>
