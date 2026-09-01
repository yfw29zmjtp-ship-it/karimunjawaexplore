<?php
/**
 * CQC Projects - Add/Edit Project
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

// Database connection
try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch customers from Database module
$customers = [];
try {
    $bizDb = Database::getInstance();
    $customers = $bizDb->fetchAll("SELECT id, customer_code, customer_name, company_name, phone, email, address, city FROM customers WHERE is_active = 1 ORDER BY customer_name");
} catch (Exception $e) {
    // Customers table may not exist yet
}

// Check if editing existing project
$isEdit = false;
$project = [];
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
    $stmt->execute([$editId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($project) {
        $isEdit = true;
    } else {
        header('Location: dashboard.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'project_name' => trim($_POST['project_name'] ?? ''),
        'project_code' => trim($_POST['project_code'] ?? ''),
        'location' => trim($_POST['location'] ?? ''),
        'status' => $_POST['status'] ?? 'planning',
        'description' => trim($_POST['description'] ?? ''),
        'client_name' => trim($_POST['client_name'] ?? ''),
        'client_phone' => trim($_POST['client_phone'] ?? ''),
        'client_email' => trim($_POST['client_email'] ?? ''),
        'solar_capacity_kwp' => floatval($_POST['solar_capacity_kwp'] ?? 0),
        'panel_count' => intval($_POST['panel_count'] ?? 0),
        'panel_type' => trim($_POST['panel_type'] ?? ''),
        'inverter_type' => trim($_POST['inverter_type'] ?? ''),
        'budget_idr' => floatval(str_replace(['.', ','], '', $_POST['budget_idr'] ?? '0')),
        'progress_percentage' => intval($_POST['progress_percentage'] ?? 0),
        'start_date' => !empty($_POST['start_date']) ? $_POST['start_date'] : null,
        'estimated_completion' => !empty($_POST['estimated_completion']) ? $_POST['estimated_completion'] : null,
    ];

    if (empty($data['project_name']) || empty($data['project_code'])) {
        $error = 'Nama proyek dan kode proyek wajib diisi.';
    } else {
        try {
            if ($isEdit) {
                $sql = "UPDATE cqc_projects SET 
                    project_name = ?, project_code = ?, location = ?, status = ?, 
                    description = ?, client_name = ?, client_phone = ?, client_email = ?,
                    solar_capacity_kwp = ?, panel_count = ?, panel_type = ?, inverter_type = ?,
                    budget_idr = ?, progress_percentage = ?, start_date = ?, estimated_completion = ?,
                    updated_at = NOW()
                    WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $data['project_name'], $data['project_code'], $data['location'], $data['status'],
                    $data['description'], $data['client_name'], $data['client_phone'], $data['client_email'],
                    $data['solar_capacity_kwp'], $data['panel_count'], $data['panel_type'], $data['inverter_type'],
                    $data['budget_idr'], $data['progress_percentage'], $data['start_date'], $data['estimated_completion'],
                    $editId
                ]);
            } else {
                $currentUserId = $_SESSION['user_id'] ?? 1;
                $sql = "INSERT INTO cqc_projects (
                    project_name, project_code, location, status, description,
                    client_name, client_phone, client_email,
                    solar_capacity_kwp, panel_count, panel_type, inverter_type,
                    budget_idr, progress_percentage, start_date, estimated_completion,
                    created_by, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $data['project_name'], $data['project_code'], $data['location'], $data['status'],
                    $data['description'], $data['client_name'], $data['client_phone'], $data['client_email'],
                    $data['solar_capacity_kwp'], $data['panel_count'], $data['panel_type'], $data['inverter_type'],
                    $data['budget_idr'], $data['progress_percentage'], $data['start_date'], $data['estimated_completion'],
                    $currentUserId
                ]);
                $editId = $pdo->lastInsertId();
            }

            header('Location: detail.php?id=' . $editId);
            exit;
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
    // Keep submitted data in form on error
    $project = $data;
}

$pageTitle = $isEdit ? "Edit Proyek - CQC" : "Tambah Proyek Baru - CQC";
$pageSubtitle = "Solar Panel Installation Projects";

include '../../includes/header.php';
?>

<style>
        /* CQC Form Styles - Navy + Gold Theme */
        :root {
            --cqc-primary: #0d1f3c;
            --cqc-primary-light: #1a3a5c;
            --cqc-accent: #f0b429;
            --cqc-accent-dark: #d4960d;
        }
        .cqc-form-card {
            background: var(--bg-secondary, white);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        .cqc-form-section { margin-bottom: 30px; }

        .cqc-form-section h3 {
            color: var(--cqc-primary);
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--cqc-accent);
        }

        .cqc-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .cqc-form-group { display: flex; flex-direction: column; }

        .cqc-form-group label {
            font-weight: 600;
            color: var(--text-primary, #333);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .cqc-form-group input,
        .cqc-form-group textarea,
        .cqc-form-group select {
            padding: 12px;
            border: 1px solid var(--bg-tertiary, #ddd);
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
            background: var(--bg-primary, white);
            color: var(--text-primary, #333);
        }

        .cqc-form-group input:focus,
        .cqc-form-group textarea:focus,
        .cqc-form-group select:focus {
            outline: none;
            border-color: var(--cqc-accent);
            box-shadow: 0 0 0 3px rgba(240, 180, 41, 0.15);
        }

        .cqc-form-group textarea { resize: vertical; min-height: 100px; }
        .cqc-form-group.full { grid-column: 1 / -1; }

        .cqc-form-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--bg-tertiary, #eee);
        }

        .cqc-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .cqc-btn-primary {
            background: var(--cqc-accent);
            color: var(--cqc-primary);
            font-weight: 700;
        }

        .cqc-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .cqc-btn-secondary { background: var(--bg-tertiary, #f0f0f0); color: var(--text-primary, #333); }
        .cqc-btn-secondary:hover { opacity: 0.8; }

        .cqc-alert {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .cqc-required { color: #d32f2f; }
        .cqc-hint { font-size: 12px; color: var(--text-muted, #999); margin-top: 4px; }
</style>

    <div style="max-width: 900px;">
        <!-- Form -->
        <div class="cqc-form-card">
            <?php if (isset($error)): ?>
                <div class="cqc-alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <!-- Project Basic Info -->
                <div class="cqc-form-section">
                    <h3>📋 Informasi Dasar Proyek</h3>
                    <div class="cqc-form-grid">
                        <div class="cqc-form-group">
                            <label>Nama Proyek <span class="cqc-required">*</span></label>
                            <input type="text" name="project_name" required value="<?php echo htmlspecialchars($project['project_name'] ?? ''); ?>" placeholder="Contoh: Solar Panel PT XYZ">
                        </div>

                        <div class="cqc-form-group">
                            <label>Kode Proyek <span class="cqc-required">*</span></label>
                            <input type="text" name="project_code" required value="<?php echo htmlspecialchars($project['project_code'] ?? ''); ?>" placeholder="Contoh: PRJ-2024-001">
                            <p class="cqc-hint">Harus unik, digunakan untuk tracking</p>
                        </div>

                        <div class="cqc-form-group">
                            <label>Lokasi <span class="cqc-required">*</span></label>
                            <input type="text" name="location" required value="<?php echo htmlspecialchars($project['location'] ?? ''); ?>" placeholder="Contoh: Jl. Merdeka No. 123, Jakarta">
                        </div>

                        <div class="cqc-form-group">
                            <label>Status Proyek</label>
                            <select name="status">
                                <option value="planning" <?php echo ($project['status'] ?? 'planning') === 'planning' ? 'selected' : ''; ?>>Planning</option>
                                <option value="procurement" <?php echo ($project['status'] ?? '') === 'procurement' ? 'selected' : ''; ?>>Procurement</option>
                                <option value="installation" <?php echo ($project['status'] ?? '') === 'installation' ? 'selected' : ''; ?>>Installation</option>
                                <option value="testing" <?php echo ($project['status'] ?? '') === 'testing' ? 'selected' : ''; ?>>Testing</option>
                                <option value="completed" <?php echo ($project['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="on_hold" <?php echo ($project['status'] ?? '') === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            </select>
                        </div>

                        <div class="cqc-form-group full">
                            <label>Deskripsi Proyek</label>
                            <textarea name="description" placeholder="Detail lengkap tentang proyek..."><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Client Info -->
                <div class="cqc-form-section">
                    <h3>👤 Informasi Klien</h3>
                    <div class="cqc-form-grid">
                        <div class="cqc-form-group">
                            <label>Pilih dari Database</label>
                            <select id="customer_select" class="cqc-customer-select" onchange="fillCustomerData()">
                                <option value="">-- Pilih Customer atau input manual --</option>
                                <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($cust['customer_name'] . ($cust['company_name'] ? ' - ' . $cust['company_name'] : '')); ?>"
                                    data-phone="<?php echo htmlspecialchars($cust['phone'] ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                                    data-address="<?php echo htmlspecialchars(($cust['address'] ?? '') . ($cust['city'] ? ', ' . $cust['city'] : '')); ?>">
                                    <?php echo htmlspecialchars($cust['customer_code'] . ' - ' . $cust['customer_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="cqc-hint">Pilih customer untuk auto-fill, atau kosongkan untuk input manual</p>
                        </div>

                        <div class="cqc-form-group">
                            <label>Nama Klien</label>
                            <input type="text" name="client_name" id="client_name" value="<?php echo htmlspecialchars($project['client_name'] ?? ''); ?>" placeholder="Nama klien atau perusahaan">
                        </div>

                        <div class="cqc-form-group">
                            <label>Telepon Klien</label>
                            <input type="tel" name="client_phone" id="client_phone" value="<?php echo htmlspecialchars($project['client_phone'] ?? ''); ?>" placeholder="0812-3456-7890">
                        </div>

                        <div class="cqc-form-group">
                            <label>Email Klien</label>
                            <input type="email" name="client_email" id="client_email" value="<?php echo htmlspecialchars($project['client_email'] ?? ''); ?>" placeholder="client@example.com">
                        </div>
                    </div>
                </div>

                <!-- Solar Panel Specifications -->
                <div class="cqc-form-section">
                    <h3>☀️ Kapasitas Sistem</h3>
                    <div class="cqc-form-grid">
                        <div class="cqc-form-group">
                            <label>Kapasitas (KWp)</label>
                            <input type="number" name="solar_capacity_kwp" step="0.1" value="<?php echo htmlspecialchars($project['solar_capacity_kwp'] ?? ''); ?>" placeholder="3.5">
                            <p class="cqc-hint">Kilowatt Peak</p>
                        </div>
                    </div>
                </div>

                <!-- Budget & Schedule -->
                <div class="cqc-form-section">
                    <h3>💰 Budget & Jadwal</h3>
                    <div class="cqc-form-grid">
                        <div class="cqc-form-group">
                            <label>Budget Total (Rp) <span class="cqc-required">*</span></label>
                            <input type="text" name="budget_idr" required value="<?php echo isset($project['budget_idr']) ? number_format($project['budget_idr'], 0) : ''; ?>" placeholder="100000000">
                            <p class="cqc-hint">Tanpa koma/titik</p>
                        </div>

                        <div class="cqc-form-group">
                            <label>Progress (%)</label>
                            <input type="range" name="progress_percentage" min="0" max="100" value="<?php echo htmlspecialchars($project['progress_percentage'] ?? 0); ?>" id="progressSlider">
                            <p class="cqc-hint" id="progressValue"><?php echo htmlspecialchars($project['progress_percentage'] ?? 0); ?>%</p>
                        </div>

                        <div class="cqc-form-group">
                            <label>Tanggal Mulai</label>
                            <input type="date" name="start_date" value="<?php echo htmlspecialchars($project['start_date'] ?? ''); ?>">
                        </div>

                        <div class="cqc-form-group">
                            <label>Tanggal Selesai Rencana</label>
                            <input type="date" name="end_date" value="<?php echo htmlspecialchars($project['end_date'] ?? ''); ?>">
                        </div>

                        <div class="cqc-form-group">
                            <label>Estimasi Selesai</label>
                            <input type="date" name="estimated_completion" value="<?php echo htmlspecialchars($project['estimated_completion'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="cqc-form-actions">
                    <button type="submit" class="cqc-btn cqc-btn-primary">
                        <?php echo $isEdit ? '✅ Simpan Perubahan' : '➕ Buat Proyek'; ?>
                    </button>
                    <button type="button" class="cqc-btn cqc-btn-secondary" onclick="history.back()">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Update progress value display
        document.getElementById('progressSlider').addEventListener('input', function() {
            document.getElementById('progressValue').textContent = this.value + '%';
        });

        // Format budget input
        document.querySelector('input[name="budget_idr"]').addEventListener('change', function() {
            const value = this.value.replace(/\D/g, '');
            this.value = value ? new Intl.NumberFormat('id-ID').format(value) : '';
        });

        // Auto-fill customer data from dropdown
        function fillCustomerData() {
            const select = document.getElementById('customer_select');
            const option = select.options[select.selectedIndex];
            
            if (option.value) {
                document.getElementById('client_name').value = option.dataset.name || '';
                document.getElementById('client_phone').value = option.dataset.phone || '';
                document.getElementById('client_email').value = option.dataset.email || '';
            }
        }
    </script>

<?php include '../../includes/footer.php'; ?>
