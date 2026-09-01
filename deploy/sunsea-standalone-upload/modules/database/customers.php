<?php

/**
 * Database Customer - List & Manage Customers
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Database Customer';

// Ensure table exists
try {
    $db->query("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_code VARCHAR(30) UNIQUE,
            customer_name VARCHAR(100) NOT NULL,
            customer_type ENUM('individual', 'company', 'member') DEFAULT 'individual',
            company_name VARCHAR(100),
            contact_person VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            city VARCHAR(50),
            province VARCHAR(50),
            postal_code VARCHAR(10),
            npwp VARCHAR(30),
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

$customerColumns = [];
try {
    $columnRows = $db->fetchAll("SHOW COLUMNS FROM customers");
    $customerColumns = array_map(function ($row) {
        return $row['Field'];
    }, $columnRows);
} catch (Exception $e) {
    $customerColumns = [];
}

$nameColumn = in_array('customer_name', $customerColumns, true) ? 'customer_name' : (in_array('name', $customerColumns, true) ? 'name' : 'customer_name');
$typeColumn = in_array('customer_type', $customerColumns, true) ? 'customer_type' : null;
$statusColumn = in_array('is_active', $customerColumns, true) ? 'is_active' : (in_array('status', $customerColumns, true) ? 'status' : 'is_active');

// Handle form submission (Add/Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    $rawCustomerName = trim($_POST['customer_name'] ?? '');
    $rawCustomerType = $_POST['customer_type'] ?? 'individual';
    $rawIsActive = isset($_POST['is_active']) ? 1 : 0;

    $data = [];
    if (in_array('customer_name', $customerColumns, true)) {
        $data['customer_name'] = $rawCustomerName;
    } elseif (in_array('name', $customerColumns, true)) {
        $data['name'] = $rawCustomerName;
    }

    if (in_array('customer_type', $customerColumns, true)) {
        $data['customer_type'] = $rawCustomerType;
    }
    if (in_array('company_name', $customerColumns, true)) {
        $data['company_name'] = trim($_POST['company_name'] ?? '');
    }
    if (in_array('contact_person', $customerColumns, true)) {
        $data['contact_person'] = trim($_POST['contact_person'] ?? '');
    }
    if (in_array('email', $customerColumns, true)) {
        $data['email'] = trim($_POST['email'] ?? '');
    }
    if (in_array('phone', $customerColumns, true)) {
        $data['phone'] = trim($_POST['phone'] ?? '');
    }
    if (in_array('address', $customerColumns, true)) {
        $data['address'] = trim($_POST['address'] ?? '');
    }
    if (in_array('city', $customerColumns, true)) {
        $data['city'] = trim($_POST['city'] ?? '');
    }
    if (in_array('province', $customerColumns, true)) {
        $data['province'] = trim($_POST['province'] ?? '');
    }
    if (in_array('postal_code', $customerColumns, true)) {
        $data['postal_code'] = trim($_POST['postal_code'] ?? '');
    }
    if (in_array('npwp', $customerColumns, true)) {
        $data['npwp'] = trim($_POST['npwp'] ?? '');
    }
    if (in_array('notes', $customerColumns, true)) {
        $data['notes'] = trim($_POST['notes'] ?? '');
    }
    if (in_array('is_active', $customerColumns, true)) {
        $data['is_active'] = $rawIsActive;
    } elseif (in_array('status', $customerColumns, true)) {
        $data['status'] = $rawIsActive ? 'active' : 'inactive';
    }

    try {
        if ($action === 'edit' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            if ($id <= 0) {
                throw new Exception('ID customer tidak valid');
            }
            if (empty($data)) {
                throw new Exception('Tidak ada kolom yang bisa diupdate pada tabel customers');
            }

            $updatedRows = $db->update('customers', $data, 'id = :where_id', ['where_id' => $id]);
            if ($updatedRows === false) {
                throw new Exception('Query update customer gagal dijalankan');
            }

            if ($updatedRows > 0) {
                $_SESSION['success'] = 'Customer berhasil diupdate';
            } else {
                $_SESSION['success'] = 'Tidak ada perubahan data customer';
            }
        } else {
            // Generate customer code
            if (in_array('customer_code', $customerColumns, true)) {
                $lastCode = $db->fetchOne("SELECT customer_code FROM customers ORDER BY id DESC LIMIT 1");
                if ($lastCode && !empty($lastCode['customer_code'])) {
                    $num = (int)substr($lastCode['customer_code'], 5) + 1;
                } else {
                    $num = 1;
                }
                $data['customer_code'] = 'CUST-' . str_pad($num, 4, '0', STR_PAD_LEFT);
            }
            if (in_array('created_by', $customerColumns, true)) {
                $data['created_by'] = $currentUser['id'];
            }

            if (empty($data)) {
                throw new Exception('Tidak ada kolom yang bisa disimpan pada tabel customers');
            }

            $insertedId = $db->insert('customers', $data);
            if (!$insertedId) {
                throw new Exception('Insert customer gagal dijalankan');
            }
            $_SESSION['success'] = 'Customer berhasil ditambahkan';
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menyimpan: ' . $e->getMessage();
    }

    header('Location: customers.php');
    exit;
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $db->delete('customers', 'id = ?', [$id]);
        $_SESSION['success'] = 'Customer berhasil dihapus';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal menghapus: ' . $e->getMessage();
    }
    header('Location: customers.php');
    exit;
}

// Handle toggle status
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    try {
        if ($statusColumn === 'status') {
            $current = $db->fetchOne("SELECT status FROM customers WHERE id = ?", [$id]);
            $newStatus = ($current && ($current['status'] ?? '') === 'active') ? 'inactive' : 'active';
            $result = $db->update('customers', ['status' => $newStatus], 'id = :where_id', ['where_id' => $id]);
        } else {
            $current = $db->fetchOne("SELECT is_active FROM customers WHERE id = ?", [$id]);
            $newStatus = ($current && (int)($current['is_active'] ?? 0) === 1) ? 0 : 1;
            $result = $db->update('customers', ['is_active' => $newStatus], 'id = :where_id', ['where_id' => $id]);
        }

        if ($result === false) {
            throw new Exception('Gagal update status customer');
        }

        $_SESSION['success'] = 'Status customer berhasil diubah';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Gagal mengubah status: ' . $e->getMessage();
    }
    header('Location: customers.php');
    exit;
}

// Get edit data if editing
$editData = null;
if (isset($_GET['edit'])) {
    $editData = $db->fetchOne("SELECT * FROM customers WHERE id = ?", [(int)$_GET['edit']]);
}

// Get filters
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';

// Build query
$params = [];
$conditions = [];
if ($search) {
    $searchableColumns = [];
    foreach ([$nameColumn, 'customer_code', 'company_name', 'phone'] as $col) {
        if ($col && in_array($col, $customerColumns, true)) {
            $searchableColumns[] = $col;
        }
    }
    if (!empty($searchableColumns)) {
        $orParts = array_map(function ($col) {
            return $col . ' LIKE :search';
        }, $searchableColumns);
        $conditions[] = '(' . implode(' OR ', $orParts) . ')';
    }
    $params['search'] = '%' . $search . '%';
}
if ($type && $typeColumn) {
    $conditions[] = $typeColumn . " = :type";
    $params['type'] = $type;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$customers = $db->fetchAll("SELECT * FROM customers {$where} ORDER BY {$nameColumn}", $params);
foreach ($customers as &$cust) {
    if (!isset($cust['customer_name']) && isset($cust['name'])) {
        $cust['customer_name'] = $cust['name'];
    }
    if (!isset($cust['is_active']) && isset($cust['status'])) {
        $cust['is_active'] = strtolower((string)$cust['status']) === 'active' ? 1 : 0;
    }
    if (!isset($cust['customer_type'])) {
        $cust['customer_type'] = 'individual';
    }
}
unset($cust);

include '../../includes/header.php';
?>

<div style="margin-bottom: 1.25rem;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <nav style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                <a href="index.php" style="color: var(--primary-color);">Database</a> / Customer
            </nav>
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.25rem;">
                👥 Database Customer
            </h2>
            <p style="color: var(--text-muted); font-size: 0.875rem;">Kelola data pelanggan untuk invoice & project</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="showModal('addModal')">
            <i data-feather="plus" style="width: 16px; height: 16px;"></i>
            Tambah Customer
        </button>
    </div>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success'];
                                        unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error'];
                                    unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Search -->
<div class="card" style="margin-bottom: 1.25rem; padding: 1rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="text" name="search" class="form-control" placeholder="Cari nama, kode, perusahaan, atau phone..."
            value="<?php echo htmlspecialchars($search); ?>" style="flex: 1;">
        <select name="type" class="form-control" style="width: 150px;">
            <option value="">Semua Tipe</option>
            <option value="individual" <?php echo $type === 'individual' ? 'selected' : ''; ?>>Individual</option>
            <option value="company" <?php echo $type === 'company' ? 'selected' : ''; ?>>Company</option>
            <option value="member" <?php echo $type === 'member' ? 'selected' : ''; ?>>Member</option>
        </select>
        <button type="submit" class="btn btn-primary">
            <i data-feather="search" style="width: 16px; height: 16px;"></i> Cari
        </button>
        <?php if ($search || $type): ?>
            <a href="customers.php" class="btn btn-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Statistics -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
    <?php
    $total = count($customers);
    $active = count(array_filter($customers, fn($c) => $c['is_active'] == 1));
    $individuals = count(array_filter($customers, fn($c) => $c['customer_type'] === 'individual'));
    $companies = count(array_filter($customers, fn($c) => $c['customer_type'] === 'company'));
    ?>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #6366f120; display: flex; align-items: center; justify-content: center;">
                <i data-feather="users" style="width: 20px; height: 20px; color: #6366f1;"></i>
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
                <i data-feather="check-circle" style="width: 20px; height: 20px; color: #10b981;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Aktif</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981;"><?php echo $active; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #f59e0b20; display: flex; align-items: center; justify-content: center;">
                <i data-feather="user" style="width: 20px; height: 20px; color: #f59e0b;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Individual</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #f59e0b;"><?php echo $individuals; ?></div>
            </div>
        </div>
    </div>
    <div class="card" style="padding: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 40px; height: 40px; border-radius: 8px; background: #8b5cf620; display: flex; align-items: center; justify-content: center;">
                <i data-feather="briefcase" style="width: 20px; height: 20px; color: #8b5cf6;"></i>
            </div>
            <div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Company</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #8b5cf6;"><?php echo $companies; ?></div>
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
                    <th>Nama Customer</th>
                    <th>Tipe</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Kota</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            <i data-feather="inbox" style="width: 48px; height: 48px; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">Belum ada data customer</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $cust): ?>
                        <tr>
                            <td style="font-weight: 600; color: var(--primary-color);"><?php echo $cust['customer_code']; ?></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($cust['customer_name']); ?></div>
                                <?php if ($cust['company_name']): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($cust['company_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $typeColors = ['individual' => '#f59e0b', 'company' => '#8b5cf6', 'member' => '#10b981'];
                                $typeLabels = ['individual' => 'Individual', 'company' => 'Company', 'member' => 'Member'];
                                $color = $typeColors[$cust['customer_type']] ?? '#6b7280';
                                $label = $typeLabels[$cust['customer_type']] ?? $cust['customer_type'];
                                ?>
                                <span class="badge" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                    <?php echo $label; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($cust['phone'] ?: '-'); ?></td>
                            <td style="font-size: 0.813rem;"><?php echo htmlspecialchars($cust['email'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust['city'] ?: '-'); ?></td>
                            <td>
                                <?php if ($cust['is_active']): ?>
                                    <span class="badge badge-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 0.25rem; justify-content: center;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="editCustomer(<?php echo htmlspecialchars(json_encode($cust)); ?>)" title="Edit">
                                        <i data-feather="edit" style="width: 14px; height: 14px;"></i>
                                    </button>
                                    <a href="?toggle=<?php echo $cust['id']; ?>" class="btn btn-sm <?php echo $cust['is_active'] ? 'btn-warning' : 'btn-success'; ?>" title="Toggle Status">
                                        <i data-feather="<?php echo $cust['is_active'] ? 'eye-off' : 'eye'; ?>" style="width: 14px; height: 14px;"></i>
                                    </a>
                                    <a href="?delete=<?php echo $cust['id']; ?>" class="btn btn-sm btn-danger" title="Hapus" onclick="return confirm('Hapus customer ini?')">
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
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah Customer</h3>
            <button type="button" onclick="hideModal('addModal')" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" id="customerForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="customerId">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; padding: 1.5rem;">
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Nama Customer <span style="color: red;">*</span></label>
                    <input type="text" name="customer_name" id="customer_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Tipe Customer</label>
                    <select name="customer_type" id="customer_type" class="form-control" onchange="toggleCompanyField()">
                        <option value="individual">Individual</option>
                        <option value="company">Company</option>
                        <option value="member">Member</option>
                    </select>
                </div>

                <div class="form-group" id="companyField">
                    <label class="form-label">Nama Perusahaan</label>
                    <input type="text" name="company_name" id="company_name" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" id="contact_person" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
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
                    <label class="form-label">Provinsi</label>
                    <input type="text" name="province" id="province" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Kode Pos</label>
                    <input type="text" name="postal_code" id="postal_code" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">NPWP</label>
                    <input type="text" name="npwp" id="npwp" class="form-control">
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
        background: rgba(0, 0, 0, 0.5);
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

    function toggleCompanyField() {
        const type = document.getElementById('customer_type').value;
        document.getElementById('companyField').style.display = type === 'company' ? 'block' : 'none';
    }

    function showModal(id) {
        document.getElementById(id).style.display = 'flex';
        toggleCompanyField();
    }

    function hideModal(id) {
        document.getElementById(id).style.display = 'none';
        document.getElementById('customerForm').reset();
        document.getElementById('formAction').value = 'add';
        document.getElementById('customerId').value = '';
        document.getElementById('modalTitle').textContent = 'Tambah Customer';
        document.getElementById('is_active').checked = true;
    }

    function editCustomer(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('customerId').value = data.id;
        document.getElementById('modalTitle').textContent = 'Edit Customer';

        document.getElementById('customer_name').value = data.customer_name || '';
        document.getElementById('customer_type').value = data.customer_type || 'individual';
        document.getElementById('company_name').value = data.company_name || '';
        document.getElementById('contact_person').value = data.contact_person || '';
        document.getElementById('phone').value = data.phone || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('address').value = data.address || '';
        document.getElementById('city').value = data.city || '';
        document.getElementById('province').value = data.province || '';
        document.getElementById('postal_code').value = data.postal_code || '';
        document.getElementById('npwp').value = data.npwp || '';
        document.getElementById('notes').value = data.notes || '';
        document.getElementById('is_active').checked = data.is_active == 1;

        showModal('addModal');
    }

    document.getElementById('addModal').addEventListener('click', function(e) {
        if (e.target === this) hideModal('addModal');
    });

    <?php if ($editData): ?>
        editCustomer(<?php echo json_encode($editData); ?>);
    <?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>