<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Backup & Reset Data';

// Load business configuration
$businessConfig = require '../../config/businesses/' . ACTIVE_BUSINESS_ID . '.php';

// Business feature detection (config-based)
$hasProjectModule = in_array('cqc-projects', $businessConfig['enabled_modules'] ?? []);
$isContractor = ($businessConfig['business_type'] ?? '') === 'contractor';
$isCQC = $hasProjectModule; // Legacy compatibility

include '../../includes/header.php';
?>

<style>
    .action-card {
        background: var(--bg-secondary);
        border: 1px solid var(--bg-tertiary);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        transition: var(--transition);
    }
    
    .action-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    
    .action-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    
    .icon-wrapper {
        width: 42px;
        height: 42px;
        border-radius: var(--radius-md);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 0.625rem;
        margin: 1rem 0;
    }
    
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 0.875rem;
        background: var(--bg-primary);
        border: 1px solid var(--bg-tertiary);
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: var(--transition);
    }
    
    .checkbox-label:hover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, 0.05);
    }
    
    .checkbox-label input[type="checkbox"] {
        cursor: pointer;
    }
    
    .warning-box {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.3);
        border-radius: var(--radius-md);
        padding: 0.875rem;
        margin-top: 0.875rem;
        display: flex;
        align-items: start;
        gap: 0.625rem;
    }
    
    .file-upload-area {
        border: 2px dashed var(--bg-tertiary);
        border-radius: var(--radius-md);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        cursor: pointer;
    }
    
    .file-upload-area:hover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, 0.05);
    }
    
    .file-upload-area.dragover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, 0.1);
    }
</style>

<!-- Page Header -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.375rem;">
            Backup & Reset Data
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">
            Kelola backup database, restore, dan reset data sistem
        </p>
    </div>
    <a href="index.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali
    </a>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem;">
    
    <!-- BACKUP DATABASE -->
    <div class="action-card">
        <div class="action-header">
            <div class="icon-wrapper" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0.05));">
                <i data-feather="download" style="width: 20px; height: 20px; color: var(--success);"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Backup Database
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Download file SQL backup
                </p>
            </div>
        </div>
        
        <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Backup semua data database ke file .sql yang dapat di-download dan disimpan sebagai cadangan.
        </p>
        
        <button onclick="backupDatabase()" class="btn btn-success" style="width: 100%;">
            <i data-feather="download" style="width: 16px; height: 16px;"></i>
            Backup Sekarang
        </button>
        
        <div id="backupStatus" style="margin-top: 0.875rem; font-size: 0.813rem;"></div>
    </div>
    
    <!-- RESTORE DATABASE -->
    <div class="action-card">
        <div class="action-header">
            <div class="icon-wrapper" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(59, 130, 246, 0.05));">
                <i data-feather="upload" style="width: 20px; height: 20px; color: var(--info);"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Restore Database
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Upload file SQL backup
                </p>
            </div>
        </div>
        
        <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Restore data dari file backup SQL. Semua data akan dikembalikan sesuai backup.
        </p>
        
        <div class="file-upload-area" id="uploadArea">
            <i data-feather="upload-cloud" style="width: 42px; height: 42px; color: var(--text-muted); margin: 0 auto 0.75rem;"></i>
            <p style="font-size: 0.938rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.375rem;">
                Seret berkas SQL ke sini atau klik untuk mengunggah
            </p>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                Maksimal 50MB
            </p>
            <input type="file" id="restoreFile" accept=".sql" style="display: none;">
        </div>
        
        <div class="warning-box">
            <i data-feather="alert-triangle" style="width: 18px; height: 18px; color: var(--warning); flex-shrink: 0;"></i>
            <p style="font-size: 0.813rem; color: var(--text-secondary); margin: 0;">
                <strong>Perhatian:</strong> Restore akan menimpa data yang ada. Pastikan sudah backup terlebih dahulu!
            </p>
        </div>
        
        <div id="restoreStatus" style="margin-top: 0.875rem; font-size: 0.813rem;"></div>
    </div>
    
    <!-- RESET DATA -->
    <div class="action-card" style="grid-column: span 2;">
        <div class="action-header">
            <div class="icon-wrapper" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.05));">
                <i data-feather="trash-2" style="width: 20px; height: 20px; color: var(--danger);"></i>
            </div>
            <div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                    Reset Data
                </h3>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0;">
                    Hapus data tertentu dari sistem
                </p>
            </div>
        </div>
        
        <p style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 1rem;">
            Pilih data yang ingin direset. Data akan dihapus permanen dari database.
        </p>
        
        <div class="checkbox-group">
            <?php if ($isCQC): ?>
            <!-- CQC SPECIFIC RESET OPTIONS -->
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="cqc_cashbook">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        📒 Data Buku Kas CQC
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua transaksi kas masuk & keluar di buku kas CQC
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="cqc_projects">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        ☀️ Data Proyek CQC
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua data proyek solar panel (proyek, expenses, categories)
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="cqc_expenses">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        💸 Data Pengeluaran Proyek
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua expense proyek (tidak menghapus proyek itu sendiri)
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="logs">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        📋 Data Activity Logs
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua log aktivitas sistem
                    </div>
                </div>
            </label>
            
            <?php else: ?>
            <!-- REGULAR RESET OPTIONS -->
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="accounting">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Accounting (Cash Book)
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua transaksi kas masuk & kas keluar
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="bookings">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Booking / Reservasi
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua data reservasi & booking tamu
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="invoices">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Invoice
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua invoice dan pembayaran
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="procurement">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data PO & Procurement
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua Purchase Order & Goods Receipt
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="inventory">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Inventory / Stok
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus data stok barang dan movement
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="reports">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Reports / Shift
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus data shift reports, daily reports, breakfast records
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="guests">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Tamu
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua data tamu hotel
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="employees">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Karyawan
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua data karyawan
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="users">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data User (kecuali admin)
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus user dengan role non-admin
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="logs">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Activity Logs
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua log aktivitas sistem
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="menu">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Menu Items
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua menu makanan/minuman (untuk cafe/restaurant)
                    </div>
                </div>
            </label>
            
            <label class="checkbox-label">
                <input type="checkbox" name="reset_type" value="orders">
                <div>
                    <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">
                        Data Orders
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                        Hapus semua data pesanan (untuk cafe/restaurant)
                    </div>
                </div>
            </label>
            <?php endif; ?>
        </div>
        
        <div class="warning-box">
            <i data-feather="alert-triangle" style="width: 18px; height: 18px; color: var(--danger); flex-shrink: 0;"></i>
            <p style="font-size: 0.813rem; color: var(--text-secondary); margin: 0;">
                <strong>PERHATIAN!</strong> Reset data tidak dapat dibatalkan. Pastikan sudah backup sebelum reset!
            </p>
        </div>
        
        <button onclick="resetData()" class="btn btn-danger" style="width: 100%; margin-top: 1rem;">
            <i data-feather="trash-2" style="width: 16px; height: 16px;"></i>
            Reset Data yang Dipilih
        </button>
        
        <div id="resetStatus" style="margin-top: 0.875rem; font-size: 0.813rem;"></div>
    </div>
    
</div>

<script>
    feather.replace();
    
    // ============================================
    // BACKUP DATABASE
    // ============================================
    async function backupDatabase() {
        const statusDiv = document.getElementById('backupStatus');
        statusDiv.innerHTML = '<div style="color: var(--info);"><i data-feather="loader" style="width: 14px; height: 14px;"></i> Membuat backup...</div>';
        feather.replace();
        
        try {
            const response = await fetch('<?php echo BASE_URL; ?>/api/backup-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                statusDiv.innerHTML = `
                    <div style="color: var(--success); padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: var(--radius-md);">
                        <div style="font-weight: 600; margin-bottom: 0.375rem;">✅ ${data.message}</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">File: ${data.filename} (${data.file_size})</div>
                        <a href="${data.download_url}" class="btn btn-success btn-sm" style="margin-top: 0.5rem;">
                            <i data-feather="download" style="width: 14px; height: 14px;"></i> Download Backup
                        </a>
                    </div>
                `;
                feather.replace();
            } else {
                statusDiv.innerHTML = `<div style="color: var(--danger);">❌ ${data.message}</div>`;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div style="color: var(--danger);">❌ Error: ${error.message}</div>`;
        }
    }
    
    // ============================================
    // RESTORE DATABASE
    // ============================================
    const uploadArea = document.getElementById('uploadArea');
    const restoreFile = document.getElementById('restoreFile');
    
    uploadArea.addEventListener('click', () => restoreFile.click());
    
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            restoreFile.files = files;
            handleFileUpload(files[0]);
        }
    });
    
    restoreFile.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFileUpload(e.target.files[0]);
        }
    });
    
    async function handleFileUpload(file) {
        const statusDiv = document.getElementById('restoreStatus');
        
        // Validate file
        if (!file.name.endsWith('.sql')) {
            statusDiv.innerHTML = '<div style="color: var(--danger);">❌ File harus berformat .sql</div>';
            return;
        }
        
        if (file.size > 50 * 1024 * 1024) {
            statusDiv.innerHTML = '<div style="color: var(--danger);">❌ File terlalu besar. Maksimal 50MB.</div>';
            return;
        }
        
        // Confirm
        if (!confirm(`Restore database dari file "${file.name}"?\n\n⚠️ PERHATIAN: Semua data akan ditimpa dengan data dari backup!`)) {
            restoreFile.value = '';
            return;
        }
        
        statusDiv.innerHTML = '<div style="color: var(--info);"><i data-feather="loader" style="width: 14px; height: 14px;"></i> Restoring database...</div>';
        feather.replace();
        
        const formData = new FormData();
        formData.append('backup_file', file);
        
        try {
            const response = await fetch('<?php echo BASE_URL; ?>/api/restore-data.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                let message = `<div style="color: var(--success); padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: var(--radius-md);">
                    <div style="font-weight: 600;">✅ ${data.message}</div>`;
                
                if (data.errors && data.errors.length > 0) {
                    message += `<div style="font-size: 0.75rem; color: var(--warning); margin-top: 0.5rem;">
                        <strong>Errors:</strong><br>${data.errors.join('<br>')}</div>`;
                }
                
                message += `<button onclick="location.reload()" class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">
                    <i data-feather="refresh-cw" style="width: 14px; height: 14px;"></i> Reload Halaman
                </button></div>`;
                
                statusDiv.innerHTML = message;
                feather.replace();
            } else {
                statusDiv.innerHTML = `<div style="color: var(--danger);">❌ ${data.message}</div>`;
            }
        } catch (error) {
            statusDiv.innerHTML = `<div style="color: var(--danger);">❌ Error: ${error.message}</div>`;
        }
        
        restoreFile.value = '';
    }
    
    // ============================================
    // RESET DATA
    // ============================================
    async function resetData() {
        const checkboxes = document.querySelectorAll('input[name="reset_type"]:checked');
        const statusDiv = document.getElementById('resetStatus');
        
        if (checkboxes.length === 0) {
            statusDiv.innerHTML = '<div style="color: var(--warning);">⚠️ Pilih minimal 1 data yang ingin direset.</div>';
            return;
        }
        
        const types = Array.from(checkboxes).map(cb => cb.value);
        const typeNames = types.map(t => {
            switch(t) {
                case 'accounting': return 'Data Accounting';
                case 'bookings': return 'Data Booking/Reservasi';
                case 'invoices': return 'Data Invoice';
                case 'procurement': return 'Data PO & Procurement';
                case 'inventory': return 'Data Inventory/Stok';
                case 'reports': return 'Data Reports/Shift';
                case 'guests': return 'Data Tamu';
                case 'employees': return 'Data Karyawan';
                case 'users': return 'Data User';
                case 'logs': return 'Data Activity Logs';
                case 'menu': return 'Data Menu Items';
                case 'orders': return 'Data Orders';
                // CQC Specific
                case 'cqc_cashbook': return 'Data Buku Kas CQC';
                case 'cqc_projects': return 'Data Proyek CQC';
                case 'cqc_expenses': return 'Data Pengeluaran Proyek';
                default: return `Data ${t}`;
            }
        });
        
        if (!confirm(`⚠️ KONFIRMASI RESET DATA\n\nAnda akan menghapus:\n- ${typeNames.join('\n- ')}\n\nData akan dihapus PERMANEN dan TIDAK DAPAT dikembalikan!\n\nLanjutkan?`)) {
            return;
        }
        
        // Double confirmation
        const confirmText = prompt('Ketik "RESET" (tanpa tanda kutip) untuk konfirmasi:');
        if (confirmText !== 'RESET') {
            statusDiv.innerHTML = '<div style="color: var(--warning);">⚠️ Reset dibatalkan.</div>';
            return;
        }
        
        statusDiv.innerHTML = '<div style="color: var(--info);"><i data-feather="loader" style="width: 14px; height: 14px;"></i> Menghapus data...</div>';
        feather.replace();
        
        let successCount = 0;
        let totalDeleted = 0;
        let errorMessages = [];
        
        for (const type of types) {
            try {
                const response = await fetch('<?php echo BASE_URL; ?>/api/reset-data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ reset_type: type }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    successCount++;
                    totalDeleted += data.deleted_count || 0;
                } else {
                    errorMessages.push(`${type}: ${data.message || 'Unknown error'}`);
                }
            } catch (error) {
                console.error('Error resetting:', type, error);
                errorMessages.push(`${type}: ${error.message}`);
            }
        }
        
        if (successCount > 0) {
            statusDiv.innerHTML = `
                <div style="color: var(--success); padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: var(--radius-md);">
                    <div style="font-weight: 600;">✅ Reset berhasil!</div>
                    <div style="font-size: 0.813rem; margin-top: 0.375rem;">
                        ${successCount} kategori data direset, total ${totalDeleted} record dihapus.
                    </div>
                    <button onclick="location.reload()" class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">
                        <i data-feather="refresh-cw" style="width: 14px; height: 14px;"></i> Reload Halaman
                    </button>
                </div>
            `;
            feather.replace();
            
            // Uncheck all
            checkboxes.forEach(cb => cb.checked = false);
        } else {
            let errorHtml = '<div style="color: var(--danger); padding: 0.75rem; background: rgba(239, 68, 68, 0.1); border-radius: var(--radius-md);">';
            errorHtml += '<div style="font-weight: 600;">❌ Error saat reset data.</div>';
            if (errorMessages.length > 0) {
                errorHtml += '<ul style="font-size: 0.813rem; margin-top: 0.5rem; margin-bottom: 0; padding-left: 1.25rem;">';
                errorMessages.forEach(msg => {
                    errorHtml += `<li>${msg}</li>`;
                });
                errorHtml += '</ul>';
            }
            errorHtml += '</div>';
            statusDiv.innerHTML = errorHtml;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
