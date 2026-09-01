<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();
$pageTitle = 'Preferensi Pengguna';

// Ensure user_preferences table exists
try {
    $db->fetchOne("SELECT 1 FROM user_preferences LIMIT 1");
    // Drop foreign key constraint if it exists (causes errors in multi-DB setup)
    try {
        $db->getConnection()->exec("ALTER TABLE user_preferences DROP FOREIGN KEY user_preferences_ibfk_1");
    } catch (Exception $fkErr) {
        // FK doesn't exist — fine
    }
} catch (Exception $e) {
    // Table doesn't exist, create it (no FK to avoid errors on fresh DBs)
    try {
        $db->getConnection()->exec("
            CREATE TABLE IF NOT EXISTS user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                branch_id VARCHAR(50) NOT NULL DEFAULT '',
                theme VARCHAR(50) DEFAULT 'dark',
                language VARCHAR(20) DEFAULT 'id',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_branch (user_id, branch_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $createError) {
        error_log('Failed to create user_preferences table: ' . $createError->getMessage());
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $theme = $_POST['theme'] ?? 'light';
    $language = $_POST['language'] ?? 'id';
    
    // Save preferences to database
    try {
        // Check if user preferences exist
        $existing = $db->fetchOne(
            "SELECT id FROM user_preferences WHERE user_id = ?",
            [$currentUser['id']]
        );
        
        if ($existing) {
            // Update
            $db->update('user_preferences', [
                'theme' => $theme,
                'language' => $language,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'user_id = :user_id', ['user_id' => $currentUser['id']]);
        } else {
            // Insert
            $db->insert('user_preferences', [
                'user_id' => $currentUser['id'],
                'theme' => $theme,
                'language' => $language
            ]);
        }
        
        // IMPORTANT: Update session immediately for changes to take effect
        $_SESSION['user_theme'] = $theme;
        $_SESSION['user_language'] = $language;
        
        setFlashMessage('success', 'Preferensi berhasil disimpan! Halaman akan dimuat ulang...');
        
        // Redirect with JavaScript to force reload and apply theme
        echo '<script>
            setTimeout(function() {
                window.location.href = "preferences.php?saved=1";
            }, 500);
        </script>';
        exit;
    } catch (Exception $e) {
        setFlashMessage('error', 'Error: ' . $e->getMessage());
    }
}

// Get current preferences
$preferences = $db->fetchOne(
    "SELECT * FROM user_preferences WHERE user_id = ?",
    [$currentUser['id']]
);

$currentTheme = $preferences['theme'] ?? $_SESSION['user_theme'] ?? 'dark';
$currentLanguage = $preferences['language'] ?? $_SESSION['user_language'] ?? 'id';

include '../../includes/header.php';

// Show success message if just saved
if (isset($_GET['saved'])) {
    echo '<script>
        setTimeout(function() {
            const successDiv = document.createElement("div");
            successDiv.style.cssText = "position: fixed; top: 20px; right: 20px; background: var(--success); color: white; padding: 1rem 1.5rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); z-index: 9999; animation: slideIn 0.3s ease-out;";
            successDiv.innerHTML = "<div style=\"display: flex; align-items: center; gap: 0.5rem;\"><i data-feather=\"check-circle\" style=\"width: 16px; height: 16px;\"></i><span>Preferensi berhasil disimpan dan diterapkan!</span></div>";
            document.body.appendChild(successDiv);
            feather.replace();
            setTimeout(() => {
                successDiv.style.animation = "slideOut 0.3s ease-out";
                setTimeout(() => successDiv.remove(), 300);
            }, 3000);
        }, 100);
    </script>';
}
?>

<div style="max-width: 800px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="index.php" class="btn btn-secondary btn-sm">
            <i data-feather="arrow-left" style="width: 14px; height: 14px;"></i> Kembali
        </a>
    </div>

    <div class="card">
        <div style="padding: 1rem; border-bottom: 1px solid var(--bg-tertiary);">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                <i data-feather="sliders" style="width: 18px; height: 18px; color: var(--primary-color);"></i>
                Preferensi Pengguna
            </h3>
            <p style="font-size: 0.813rem; color: var(--text-muted); margin: 0.5rem 0 0 0;">
                Pengaturan tema dan bahasa untuk akun Anda
            </p>
        </div>
        
        <form method="POST" style="padding: 1.5rem;">
            <!-- Theme Selection -->
            <div class="form-group">
                <label class="form-label">
                    <i data-feather="moon" style="width: 14px; height: 14px;"></i>
                    Tema Tampilan
                </label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.75rem;">
                    <!-- Light Theme -->
                    <label class="theme-option <?php echo $currentTheme === 'light' ? 'active' : ''; ?>" data-theme="light">
                        <input type="radio" name="theme" value="light" <?php echo $currentTheme === 'light' ? 'checked' : ''; ?> style="display: none;">
                        <div style="padding: 1.25rem; border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg); cursor: pointer; transition: all 0.3s;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                                <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: linear-gradient(135deg, #fff, #f8fafc); border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;">
                                    <i data-feather="sun" style="width: 20px; height: 20px; color: #f59e0b;"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-primary); font-size: 0.938rem;">Tema Terang</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Mode siang hari</div>
                                </div>
                            </div>
                            <div style="height: 60px; border-radius: var(--radius-md); background: linear-gradient(135deg, #ffffff, #f8fafc); border: 1px solid #e2e8f0; padding: 0.75rem;">
                                <div style="height: 6px; width: 70%; background: #cbd5e1; border-radius: 3px; margin-bottom: 0.5rem;"></div>
                                <div style="height: 6px; width: 50%; background: #cbd5e1; border-radius: 3px;"></div>
                            </div>
                        </div>
                    </label>
                    
                    <!-- Dark Theme -->
                    <label class="theme-option <?php echo $currentTheme === 'dark' ? 'active' : ''; ?>" data-theme="dark">
                        <input type="radio" name="theme" value="dark" <?php echo $currentTheme === 'dark' ? 'checked' : ''; ?> style="display: none;">
                        <div style="padding: 1.25rem; border: 2px solid var(--bg-tertiary); border-radius: var(--radius-lg); cursor: pointer; transition: all 0.3s;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                                <div style="width: 40px; height: 40px; border-radius: var(--radius-md); background: linear-gradient(135deg, #1e293b, #0f172a); display: flex; align-items: center; justify-content: center;">
                                    <i data-feather="moon" style="width: 20px; height: 20px; color: #6366f1;"></i>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-primary); font-size: 0.938rem;">Tema Gelap</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Mode malam hari</div>
                                </div>
                            </div>
                            <div style="height: 60px; border-radius: var(--radius-md); background: #1e293b; padding: 0.75rem;">
                                <div style="height: 6px; width: 70%; background: #475569; border-radius: 3px; margin-bottom: 0.5rem;"></div>
                                <div style="height: 6px; width: 50%; background: #475569; border-radius: 3px;"></div>
                            </div>
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Language Selection -->
            <div class="form-group" style="margin-top: 1.5rem;">
                <label class="form-label">
                    <i data-feather="globe" style="width: 14px; height: 14px;"></i>
                    Bahasa / Language
                </label>
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                    Pilih bahasa untuk seluruh aplikasi. Perubahan akan berlaku otomatis di semua halaman.
                </p>
                <select name="language" class="form-control" style="max-width: 300px;" id="languageSelect">
                    <option value="id" <?php echo $currentLanguage === 'id' ? 'selected' : ''; ?>>🇮🇩 Bahasa Indonesia</option>
                    <option value="en" <?php echo $currentLanguage === 'en' ? 'selected' : ''; ?>>🇬🇧 English</option>
                </select>
            </div>
            
            <!-- Info Box -->
            <div style="margin-top: 1.5rem; padding: 1rem; background: rgba(99, 102, 241, 0.1); border-left: 4px solid var(--primary-color); border-radius: var(--radius-md);">
                <div style="display: flex; gap: 0.75rem;">
                    <i data-feather="info" style="width: 18px; height: 18px; color: var(--primary-color); flex-shrink: 0;"></i>
                    <div>
                        <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary); margin-bottom: 0.25rem;">
                            Preferensi Otomatis
                        </div>
                        <div style="font-size: 0.813rem; color: var(--text-muted);">
                            Pengaturan ini akan tersimpan dan berlaku otomatis setiap kali Anda login. 
                            Bahasa yang dipilih akan diterapkan di seluruh sistem termasuk saat ada update fitur baru.
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Submit Button -->
            <div style="display: flex; gap: 0.75rem; padding-top: 1.5rem; border-top: 1px solid var(--bg-tertiary); margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <i data-feather="save" style="width: 14px; height: 14px;"></i> Simpan Perubahan
                </button>
                <a href="index.php" class="btn btn-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<style>
.theme-option > div {
    transition: all 0.3s;
}

.theme-option:hover > div {
    border-color: var(--primary-color);
    transform: translateY(-2px);
}

.theme-option.active > div {
    border-color: var(--primary-color);
    background: rgba(99, 102, 241, 0.05);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
</style>

<script>
feather.replace();

// Apply theme instantly when selected (preview mode)
document.querySelectorAll('.theme-option').forEach(option => {
    option.addEventListener('click', function() {
        // Update UI
        document.querySelectorAll('.theme-option').forEach(opt => opt.classList.remove('active'));
        this.classList.add('active');
        this.querySelector('input[type="radio"]').checked = true;
        
        // Apply theme immediately to body
        const selectedTheme = this.querySelector('input[type="radio"]').value;
        document.body.setAttribute('data-theme', selectedTheme);
        
        // Show preview indicator
        showPreviewMessage('Pratinjau tema ' + (selectedTheme === 'light' ? 'terang' : 'gelap') + ' - Klik Simpan untuk menyimpan perubahan');
    });
});

// Language change preview
document.getElementById('languageSelect').addEventListener('change', function() {
    const lang = this.value === 'id' ? 'Indonesia' : 'English';
    showPreviewMessage('Bahasa akan berubah ke ' + lang + ' setelah disimpan');
});

// Preview message function
function showPreviewMessage(message) {
    // Remove existing preview message
    const existing = document.getElementById('preview-message');
    if (existing) existing.remove();
    
    // Create new preview message
    const div = document.createElement('div');
    div.id = 'preview-message';
    div.style.cssText = 'position: fixed; top: 20px; right: 20px; background: var(--primary-color); color: white; padding: 1rem 1.5rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); z-index: 9999; animation: slideIn 0.3s ease-out;';
    div.innerHTML = '<div style="display: flex; align-items: center; gap: 0.5rem;"><i data-feather="eye" style="width: 16px; height: 16px;"></i><span>' + message + '</span></div>';
    document.body.appendChild(div);
    
    // Replace icons
    feather.replace();
    
    // Remove after 3 seconds
    setTimeout(() => {
        div.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => div.remove(), 300);
    }, 3000);
}

// Add animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(400px); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(400px); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Apply current theme on page load
document.addEventListener('DOMContentLoaded', function() {
    const currentTheme = '<?php echo $currentTheme; ?>';
    document.body.setAttribute('data-theme', currentTheme);
});

// Handle form submission
document.querySelector('form').addEventListener('submit', function(e) {
    const saveBtn = document.getElementById('saveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i data-feather="loader" style="width: 14px; height: 14px; animation: spin 1s linear infinite;"></i> Menyimpan...';
    
    // Add loading spinner animation
    const style = document.createElement('style');
    style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
    
    feather.replace();
});
</script>

<?php include '../../includes/footer.php'; ?>
