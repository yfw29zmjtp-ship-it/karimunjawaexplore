<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/CloudinaryHelper.php';

$auth = new Auth();
$auth->requireLogin();

// Check if user has permission to access settings
if (!$auth->hasPermission('settings')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Logo Hotel';

// Get current logo
$currentLogo = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'hotel_logo'");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['hotel_logo'])) {
    $file = $_FILES['hotel_logo'];
    
    // Validate file
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowedTypes)) {
            setFlash('error', 'Jenis berkas tidak diizinkan. Gunakan JPEG, PNG, atau GIF.');
        } elseif ($file['size'] > $maxSize) {
            setFlash('error', 'Ukuran file terlalu besar. Maksimal 2MB.');
        } else {
            // Generate filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $localFilename = 'hotel_logo_' . time() . '.' . $extension;
            
            // Smart upload: Cloudinary first, fallback to local
            $cloudinary = CloudinaryHelper::getInstance();
            $result = $cloudinary->smartUpload($file, 'uploads/logos', $localFilename, 'logos', 'hotel_logo');
            
            if ($result['success']) {
                // Delete old local logo if exists
                $uploadDir = BASE_PATH . '/uploads/logos/';
                if ($currentLogo && !empty($currentLogo['setting_value']) && strpos($currentLogo['setting_value'], 'http') !== 0) {
                    if (file_exists($uploadDir . $currentLogo['setting_value'])) {
                        unlink($uploadDir . $currentLogo['setting_value']);
                    }
                }
                
                // Store path (full URL for cloud, filename for local)
                $storedValue = $result['path'];
                
                // Update database
                $exists = $db->fetchOne("SELECT COUNT(*) as count FROM settings WHERE setting_key = 'hotel_logo'");
                
                if ($exists['count'] > 0) {
                    $db->query("UPDATE settings SET setting_value = :filename WHERE setting_key = 'hotel_logo'", [
                        'filename' => $storedValue
                    ]);
                } else {
                    $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('hotel_logo', :filename)", [
                        'filename' => $storedValue
                    ]);
                }
                
                setFlash('success', 'Logo hotel berhasil diunggah!' . ($result['is_cloud'] ? ' (Cloudinary)' : ''));
                header('Location: hotel-logo.php');
                exit;
            } else {
                setFlash('error', 'Tidak berhasil mengunggah berkas: ' . ($result['error'] ?? 'Unknown error'));
            }
        }
    } else {
        setFlash('error', 'Error upload file: ' . $file['error']);
    }
}

// Handle delete
if (isset($_GET['delete']) && $_GET['delete'] === 'true') {
    if ($currentLogo) {
        $uploadDir = BASE_PATH . '/uploads/logos/';
        if (file_exists($uploadDir . $currentLogo['setting_value'])) {
            unlink($uploadDir . $currentLogo['setting_value']);
        }
        
        $db->query("DELETE FROM settings WHERE setting_key = 'hotel_logo'");
        setFlash('success', 'Logo hotel berhasil dihapus!');
        header('Location: hotel-logo.php');
        exit;
    }
}

include '../../includes/header.php';
?>

<style>
    .logo-preview {
        width: 200px;
        height: 200px;
        border: 2px dashed var(--bg-tertiary);
        border-radius: var(--radius-lg);
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-primary);
        overflow: hidden;
        position: relative;
    }
    
    .logo-preview img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    
    .logo-preview.empty {
        color: var(--text-muted);
    }
    
    .upload-area {
        border: 2px dashed var(--bg-tertiary);
        border-radius: var(--radius-lg);
        padding: 2rem;
        text-align: center;
        transition: var(--transition);
        cursor: pointer;
    }
    
    .upload-area:hover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, 0.05);
    }
    
    .upload-area.dragover {
        border-color: var(--primary-color);
        background: rgba(99, 102, 241, 0.1);
    }
</style>

<!-- Page Header -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
    <div>
        <h2 style="font-size: 1.75rem; font-weight: 800; color: var(--text-primary); margin-bottom: 0.375rem;">
            Logo Hotel
        </h2>
        <p style="color: var(--text-muted); font-size: 0.875rem;">
            Upload logo hotel yang akan tampil di sidebar aplikasi
        </p>
    </div>
    <a href="index.php" class="btn btn-secondary">
        <i data-feather="arrow-left" style="width: 16px; height: 16px;"></i>
        Kembali
    </a>
</div>

<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 1.5rem;">
    
    <!-- Preview Logo -->
    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem;">
            Preview Logo Saat Ini
        </h3>
        
        <div style="display: flex; flex-direction: column; align-items: center; gap: 1rem;">
            <div class="logo-preview <?php echo empty($currentLogo) ? 'empty' : ''; ?>">
                <?php 
                $logoUrl = null;
                if ($currentLogo && $currentLogo['setting_value']) {
                    $cl = CloudinaryHelper::getInstance();
                    $logoUrl = $cl->getDisplayUrl($currentLogo['setting_value'], 'uploads/logos/', ['width' => 400, 'quality' => 'auto']);
                }
                ?>
                <?php if ($logoUrl): ?>
                    <img src="<?php echo $logoUrl; ?>" alt="Hotel Logo">
                <?php else: ?>
                    <div style="text-align: center;">
                        <i data-feather="image" style="width: 48px; height: 48px; color: var(--text-muted); margin-bottom: 0.5rem;"></i>
                        <p style="font-size: 0.875rem; color: var(--text-muted);">Belum ada logo</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($currentLogo): ?>
                <button onclick="deleteLogo()" class="btn btn-danger btn-sm">
                    <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                    Hapus Logo
                </button>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(99, 102, 241, 0.1); border-radius: var(--radius-md); border-left: 3px solid var(--primary-color);">
            <h4 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                <i data-feather="info" style="width: 14px; height: 14px; vertical-align: middle;"></i>
                Rekomendasi
            </h4>
            <ul style="font-size: 0.813rem; color: var(--text-secondary); margin: 0; padding-left: 1.25rem;">
                <li>Format: JPEG, PNG, atau GIF</li>
                <li>Ukuran: Maksimal 2MB</li>
                <li>Dimensi: 200x200px (kotak)</li>
                <li>Latar belakang transparan (PNG)</li>
            </ul>
        </div>
    </div>
    
    <!-- Upload Form -->
    <div class="card">
        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--text-primary); margin-bottom: 1.25rem;">
            Upload Logo Baru
        </h3>
        
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-area" id="uploadArea">
                <i data-feather="upload-cloud" style="width: 48px; height: 48px; color: var(--text-muted); margin: 0 auto 0.75rem;"></i>
                <h4 style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem;">
                    Seret berkas ke sini atau klik untuk mengunggah
                </h4>
                <p style="font-size: 0.813rem; color: var(--text-muted); margin-bottom: 1rem;">
                    JPEG, PNG, atau GIF • Maksimal 2MB
                </p>
                <input type="file" name="hotel_logo" id="hotelLogo" accept="image/*" style="display: none;" required>
                <button type="button" onclick="document.getElementById('hotelLogo').click()" class="btn btn-primary">
                    <i data-feather="folder" style="width: 16px; height: 16px;"></i>
                    Pilih Berkas
                </button>
            </div>
            
            <div id="selectedFile" style="margin-top: 1rem; display: none; padding: 0.875rem; background: var(--bg-primary); border-radius: var(--radius-md); border: 1px solid var(--bg-tertiary);">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <i data-feather="file" style="width: 18px; height: 18px; color: var(--primary-color);"></i>
                    <div style="flex: 1;">
                        <div id="fileName" style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);"></div>
                        <div id="fileSize" style="font-size: 0.75rem; color: var(--text-muted);"></div>
                    </div>
                    <button type="button" onclick="clearFile()" class="btn btn-secondary btn-sm">
                        <i data-feather="x" style="width: 14px; height: 14px;"></i>
                    </button>
                </div>
            </div>
            
            <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">
                    <i data-feather="upload" style="width: 16px; height: 16px;"></i>
                    Upload Logo
                </button>
                <button type="button" onclick="clearFile()" class="btn btn-secondary">
                    <i data-feather="x" style="width: 16px; height: 16px;"></i>
                    Batal
                </button>
            </div>
        </form>
        
        <!-- Preview Sidebar -->
        <div style="margin-top: 2rem; padding: 1.5rem; background: var(--bg-primary); border-radius: var(--radius-lg); border: 1px solid var(--bg-tertiary);">
            <h4 style="font-size: 0.938rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1rem;">
                Preview di Sidebar
            </h4>
            <div style="background: var(--bg-secondary); padding: 1rem; border-radius: var(--radius-md);">
                <div style="display: flex; align-items: center; gap: 0.875rem;">
                    <?php if ($logoUrl): ?>
                        <img src="<?php echo $logoUrl; ?>" alt="Logo" style="width: 42px; height: 42px; border-radius: var(--radius-md); object-fit: cover; border: 2px solid var(--bg-tertiary);">
                    <?php else: ?>
                        <div style="width: 42px; height: 42px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-tertiary);">
                            <span style="font-size: 1.5rem; font-weight: 800; color: white;">N</span>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size: 1rem; font-weight: 700; color: var(--text-primary);">Narayana</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Hotel Management</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
</div>

<script>
    feather.replace();
    
    const uploadArea = document.getElementById('uploadArea');
    const fileInput = document.getElementById('hotelLogo');
    const selectedFileDiv = document.getElementById('selectedFile');
    
    // Click to upload
    uploadArea.addEventListener('click', (e) => {
        if (e.target.tagName !== 'BUTTON') {
            fileInput.click();
        }
    });
    
    // Drag and drop
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
            fileInput.files = files;
            showSelectedFile(files[0]);
        }
    });
    
    // File selected
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            showSelectedFile(e.target.files[0]);
        }
    });
    
    function showSelectedFile(file) {
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = formatFileSize(file.size);
        selectedFileDiv.style.display = 'block';
        feather.replace();
    }
    
    function clearFile() {
        fileInput.value = '';
        selectedFileDiv.style.display = 'none';
    }
    
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    
    function deleteLogo() {
        if (confirm('Yakin ingin menghapus logo hotel?')) {
            window.location.href = 'hotel-logo.php?delete=true';
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
        fileInputHotel.value = '';
        selectedFileDivHotel.style.display = 'none';
    }
    
    // Invoice Logo Upload
    const uploadAreaInvoice = document.getElementById('uploadAreaInvoice');
    const fileInputInvoice = document.getElementById('invoiceLogo');
    const selectedFileDivInvoice = document.getElementById('selectedFileInvoice');
    
    uploadAreaInvoice.addEventListener('click', (e) => {
        if (e.target.tagName !== 'BUTTON') {
            fileInputInvoice.click();
        }
    });
    
    uploadAreaInvoice.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadAreaInvoice.classList.add('dragover');
    });
    
    uploadAreaInvoice.addEventListener('dragleave', () => {
        uploadAreaInvoice.classList.remove('dragover');
    });
    
    uploadAreaInvoice.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadAreaInvoice.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInputInvoice.files = files;
            showSelectedFileInvoice(files[0]);
        }
    });
    
    fileInputInvoice.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            showSelectedFileInvoice(e.target.files[0]);
        }
    });
    
    function showSelectedFileInvoice(file) {
        document.getElementById('fileNameInvoice').textContent = file.name;
        document.getElementById('fileSizeInvoice').textContent = formatFileSize(file.size);
        selectedFileDivInvoice.style.display = 'block';
        feather.replace();
    }
    
    function clearFileInvoice() {
        fileInputInvoice.value = '';
        selectedFileDivInvoice.style.display = 'none';
    }
    
    // Utilities
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    
    function deleteLogo(type) {
        const label = type === 'invoice_logo' ? 'Logo Invoice' : 'Logo Hotel';
        if (confirm('Yakin ingin menghapus ' + label + '?')) {
            window.location.href = 'hotel-logo.php?delete=true&type=' + type;
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
