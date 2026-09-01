<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/db-helper.php';
require_once __DIR__ . '/layout.php';

$pdo = getPwfOfficePdo();
ensurePwfOfficeTables($pdo);

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['_action'] ?? '';
  $uploadDir = BASE_PATH . '/uploads/pwf-settings/';
  if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

  if ($action === 'save_theme') {
    $theme = in_array($_POST['ui_theme'] ?? '', ['light', 'dark']) ? $_POST['ui_theme'] : 'light';
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_ui_theme',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$theme, $theme]);
    $msg = 'Theme updated. Page will reload.';
    header('Location: settings.php?saved=theme');
    exit;
  } elseif ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $newPwd  = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $userId  = $_SESSION['user_id'] ?? 0;
    if (!$userId) {
      $msg = 'Session expired.';
      $msgType = 'error';
    } elseif (strlen($newPwd) < 6) {
      $msg = 'Min 6 characters.';
      $msgType = 'error';
    } elseif ($newPwd !== $confirm) {
      $msg = 'Passwords do not match.';
      $msgType = 'error';
    } else {
      try {
        $mpdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $row = $mpdo->prepare('SELECT password FROM users WHERE id=?');
        $row->execute([$userId]);
        $row = $row->fetch();
        if ($row && password_verify($current, $row['password'])) {
          $mpdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($newPwd, PASSWORD_DEFAULT), $userId]);
          $msg = 'Password changed successfully.';
        } else {
          $msg = 'Current password is incorrect.';
          $msgType = 'error';
        }
      } catch (Exception $e) {
        $msg = 'Error: ' . htmlspecialchars($e->getMessage());
        $msgType = 'error';
      }
    }
  } elseif ($action === 'upload_logo') {
    $file = $_FILES['logo_file'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'svg', 'webp'])) {
        $msg = 'Invalid format.';
        $msgType = 'error';
      } elseif ($file['size'] > 2 * 1024 * 1024) {
        $msg = 'Max 2 MB.';
        $msgType = 'error';
      } else {
        $fname = 'logo_' . time() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
          $wp = BASE_URL . '/uploads/pwf-settings/' . $fname;
          $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_login_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$wp, $wp]);
          $msg = 'Logo uploaded.';
        } else {
          $msg = 'Upload failed.';
          $msgType = 'error';
        }
      }
    } else {
      $msg = 'Select a file.';
      $msgType = 'error';
    }
  } elseif ($action === 'upload_wallpaper') {
    $file = $_FILES['wallpaper_file'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        $msg = 'Format: JPG, PNG, WebP.';
        $msgType = 'error';
      } elseif ($file['size'] > 8 * 1024 * 1024) {
        $msg = 'Max 8 MB.';
        $msgType = 'error';
      } else {
        $fname = 'wallpaper_' . time() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
          $wp = BASE_URL . '/uploads/pwf-settings/' . $fname;
          $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('pwf_login_wallpaper',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$wp, $wp]);
          $msg = 'Wallpaper uploaded.';
        } else {
          $msg = 'Upload failed.';
          $msgType = 'error';
        }
      }
    } else {
      $msg = 'Select a file.';
      $msgType = 'error';
    }
  } elseif ($action === 'save_text') {
    foreach (['pwf_company_name', 'pwf_company_tagline', 'pwf_company_address', 'pwf_company_phone'] as $k) {
      if (isset($_POST[$k])) {
        $v = trim($_POST[$k]);
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k, $v, $v]);
      }
    }
    $msg = 'Company info saved.';
  } elseif ($action === 'reset_logo') {
    $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_logo'");
    $msg = 'Logo reset.';
  } elseif ($action === 'reset_wallpaper') {
    $pdo->exec("DELETE FROM settings WHERE setting_key='pwf_login_wallpaper'");
    $msg = 'Wallpaper reset.';
  } elseif ($action === 'backup_data') {
    // Export all PWF tables as SQL INSERT statements
    $tables = ['pwf_orders', 'pwf_customers', 'pwf_craftsmen', 'pwf_containers', 'pwf_container_items', 'pwf_spk', 'settings'];
    $sql = "-- PWF Office Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n-- ----------------------------------------\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $tbl) {
      try {
        $rows = $pdo->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
          $sql .= "-- $tbl: (empty)\n\n";
          continue;
        }
        $sql .= "-- Table: $tbl (" . count($rows) . " rows)\n";
        $sql .= "TRUNCATE TABLE `$tbl`;\n";
        foreach ($rows as $row) {
          $cols = '`' . implode('`,`', array_keys($row)) . '`';
          $vals = implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row)));
          $sql .= "INSERT INTO `$tbl` ($cols) VALUES ($vals);\n";
        }
        $sql .= "\n";
      } catch (Exception $e) {
        $sql .= "-- $tbl: ERROR - " . $e->getMessage() . "\n\n";
      }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $filename = 'pwf-backup-' . date('Ymd-His') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
  } elseif ($action === 'reset_orders') {
    // Hard reset: wipe all transactional data, keep settings/customers/craftsmen
    try {
      $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

      // Safely truncate tables (check existence first)
      foreach (['pwf_container_items', 'pwf_containers', 'pwf_spk'] as $t) {
        try {
          $check = $pdo->query("SHOW TABLES LIKE '$t'");
          if ($check && $check->rowCount() > 0) {
            $pdo->exec("TRUNCATE TABLE `$t`");
          }
        } catch (Exception $e) {
          // Table doesn't exist - skip silently
        }
      }

      // Reset orders to 'on_progress'
      try {
        $check = $pdo->query("SHOW TABLES LIKE 'pwf_orders'");
        if ($check && $check->rowCount() > 0) {
          $pdo->exec("UPDATE pwf_orders SET status='on_progress', qty_done=0, updated_at=NOW() WHERE 1");
        }
      } catch (Exception $e) {
      }

      $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
      $msg = 'All containers, shipping records and SPK have been reset. Orders reverted to On Progress.';
    } catch (Exception $e) {
      $msg = 'Error during reset: ' . htmlspecialchars($e->getMessage());
      $msgType = 'error';
    }
  } elseif ($action === 'reset_all') {
    // Full reset: wipe everything except settings
    try {
      $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

      $tables = ['pwf_container_items', 'pwf_containers', 'pwf_spk', 'pwf_orders', 'pwf_customers', 'pwf_craftsmen'];
      $truncated = [];

      foreach ($tables as $t) {
        try {
          $check = $pdo->query("SHOW TABLES LIKE '$t'");
          if ($check && $check->rowCount() > 0) {
            $pdo->exec("TRUNCATE TABLE `$t`");
            $truncated[] = $t;
          }
        } catch (Exception $e) {
          // Table doesn't exist or can't truncate - log but continue
          // File error in logs instead of aborting
          @error_log("PWF reset: Could not truncate $t - " . $e->getMessage());
        }
      }

      $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
      $msg = 'All data has been wiped. Settings and media are preserved.' . (count($truncated) < count($tables) ? ' (' . count($truncated) . ' tables cleared)' : '');
    } catch (Exception $e) {
      $msg = 'Error during full reset: ' . htmlspecialchars($e->getMessage());
      $msgType = 'error';
    }
  }
}

$skeys = ['pwf_login_logo', 'pwf_login_wallpaper', 'pwf_company_name', 'pwf_company_tagline', 'pwf_company_address', 'pwf_company_phone', 'pwf_ui_theme'];
$srows = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('" . implode("','", $skeys) . "')")->fetchAll(PDO::FETCH_KEY_PAIR);
$s = [];
foreach ($skeys as $k) $s[$k] = $srows[$k] ?? '';
$currentTheme = $s['pwf_ui_theme'] ?: 'light';
if (isset($_GET['saved'])) $msg = $msg ?: 'Saved.';

pwfOfficeHeader('Settings', 'settings');
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:16px"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- THEME -->
  <div class="pwf-card">
    <div class="pwf-card-header"><i class="bi bi-palette me-2" style="color:var(--gold)"></i>UI Theme</div>
    <div class="pwf-card-body">
      <form method="post">
        <input type="hidden" name="_action" value="save_theme">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
          <label style="cursor:pointer">
            <input type="radio" name="ui_theme" value="light" <?= $currentTheme === 'light' ? 'checked' : '' ?> style="display:none" onchange="previewTheme('light')">
            <div id="cardLight" style="border:2px solid <?= $currentTheme === 'light' ? 'var(--gold)' : 'var(--border)' ?>;border-radius:10px;overflow:hidden;transition:border-color .2s">
              <div style="background:#F8F6F3;padding:10px 12px 8px">
                <div style="background:#fff;border-radius:5px;height:7px;width:70%;margin-bottom:4px"></div>
                <div style="background:#fff;border-radius:5px;height:5px;width:45%"></div>
              </div>
              <div style="background:#fff;padding:6px 10px;display:flex;align-items:center;gap:6px;border-top:1px solid #E7E5E4">
                <div style="width:7px;height:7px;border-radius:50%;background:#B8860B"></div>
                <span style="font-size:11px;font-weight:600;color:#1C1917">Light</span>
              </div>
            </div>
          </label>
          <label style="cursor:pointer">
            <input type="radio" name="ui_theme" value="dark" <?= $currentTheme === 'dark' ? 'checked' : '' ?> style="display:none" onchange="previewTheme('dark')">
            <div id="cardDark" style="border:2px solid <?= $currentTheme === 'dark' ? 'var(--gold)' : 'var(--border)' ?>;border-radius:10px;overflow:hidden;transition:border-color .2s">
              <div style="background:#111113;padding:10px 12px 8px">
                <div style="background:#1C1C1F;border-radius:5px;height:7px;width:70%;margin-bottom:4px"></div>
                <div style="background:#1C1C1F;border-radius:5px;height:5px;width:45%"></div>
              </div>
              <div style="background:#161618;padding:6px 10px;display:flex;align-items:center;gap:6px;border-top:1px solid #2C2C30">
                <div style="width:7px;height:7px;border-radius:50%;background:#D4A017"></div>
                <span style="font-size:11px;font-weight:600;color:#ECECEC">Dark</span>
              </div>
            </div>
          </label>
        </div>
        <button class="btn" type="submit" style="width:100%"><i class="bi bi-check-circle"></i> Apply Theme</button>
      </form>
    </div>
  </div>

  <!-- CHANGE PASSWORD -->
  <div class="pwf-card">
    <div class="pwf-card-header"><i class="bi bi-shield-lock me-2" style="color:var(--gold)"></i>Change Password</div>
    <div class="pwf-card-body">
      <form method="post" autocomplete="off">
        <input type="hidden" name="_action" value="change_password">
        <div class="pwf-form-group"><label>Current Password</label>
          <input class="input" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="pwf-form-group"><label>New Password</label>
          <input class="input" type="password" name="new_password" id="pwdNew" required minlength="6" autocomplete="new-password">
        </div>
        <div class="pwf-form-group"><label>Confirm New Password</label>
          <input class="input" type="password" name="confirm_password" id="pwdConfirm" required minlength="6" autocomplete="new-password" oninput="checkPwd()">
          <div id="pwdMsg" style="font-size:11px;margin-top:3px;min-height:16px"></div>
        </div>
        <button class="btn" type="submit" style="width:100%"><i class="bi bi-key"></i> Update Password</button>
      </form>
    </div>
  </div>

  <!-- LOGO -->
  <div class="pwf-card">
    <div class="pwf-card-header"><i class="bi bi-image me-2" style="color:var(--gold)"></i>Company Logo</div>
    <div class="pwf-card-body">
      <div style="text-align:center;margin-bottom:14px">
        <?php if ($s['pwf_login_logo']): ?>
          <div style="background:var(--nav-hover);padding:14px;border-radius:10px;display:inline-block">
            <img src="<?= htmlspecialchars($s['pwf_login_logo']) ?>" style="max-height:64px;max-width:148px;object-fit:contain">
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:5px">Current logo</div>
        <?php else: ?>
          <div style="background:var(--nav-hover);border-radius:10px;width:80px;height:80px;display:inline-flex;align-items:center;justify-content:center;font-size:32px">🪵</div>
          <div style="font-size:11px;color:var(--muted);margin-top:5px">Default icon</div>
        <?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="upload_logo">
        <div class="pwf-form-group"><label>Upload New Logo</label>
          <input class="input" type="file" name="logo_file" accept=".jpg,.jpeg,.png,.svg,.webp" required>
          <div style="font-size:11px;color:var(--muted);margin-top:3px">JPG, PNG, SVG, WebP — max 2 MB</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn" type="submit" style="flex:1"><i class="bi bi-upload"></i> Upload</button>
          <?php if ($s['pwf_login_logo']): ?>
            <button class="btn btn-outline-secondary" type="button"
              onclick="if(confirm('Reset logo?')){document.getElementById('frmResetLogo').submit()}">
              <i class="bi bi-arrow-counterclockwise"></i>
            </button>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($s['pwf_login_logo']): ?>
        <form method="post" id="frmResetLogo" style="display:none"><input type="hidden" name="_action" value="reset_logo"></form>
      <?php endif; ?>
    </div>
  </div>

  <!-- WALLPAPER -->
  <div class="pwf-card">
    <div class="pwf-card-header"><i class="bi bi-card-image me-2" style="color:var(--gold)"></i>Login Wallpaper</div>
    <div class="pwf-card-body">
      <div style="margin-bottom:14px">
        <?php if ($s['pwf_login_wallpaper']): ?>
          <img src="<?= htmlspecialchars($s['pwf_login_wallpaper']) ?>" style="width:100%;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
          <div style="font-size:11px;color:var(--muted);margin-top:4px">Current wallpaper</div>
        <?php else: ?>
          <div style="background:radial-gradient(ellipse at 70% 20%,#1a3a2e,#0a0e1a);height:80px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;color:rgba(255,255,255,.4)">Default gradient</div>
        <?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="_action" value="upload_wallpaper">
        <div class="pwf-form-group"><label>Upload Wallpaper</label>
          <input class="input" type="file" name="wallpaper_file" accept=".jpg,.jpeg,.png,.webp" required>
          <div style="font-size:11px;color:var(--muted);margin-top:3px">JPG, PNG, WebP — max 8 MB</div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn" type="submit" style="flex:1"><i class="bi bi-upload"></i> Upload</button>
          <?php if ($s['pwf_login_wallpaper']): ?>
            <button class="btn btn-outline-secondary" type="button"
              onclick="if(confirm('Reset wallpaper?')){document.getElementById('frmResetWp').submit()}">
              <i class="bi bi-arrow-counterclockwise"></i>
            </button>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($s['pwf_login_wallpaper']): ?>
        <form method="post" id="frmResetWp" style="display:none"><input type="hidden" name="_action" value="reset_wallpaper"></form>
      <?php endif; ?>
    </div>
  </div>

  <!-- COMPANY INFO -->
  <div class="pwf-card" style="grid-column:1/-1">
    <div class="pwf-card-header"><i class="bi bi-building me-2" style="color:var(--gold)"></i>Company Information</div>
    <div class="pwf-card-body">
      <form method="post">
        <input type="hidden" name="_action" value="save_text">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="pwf-form-group"><label>Company Name</label><input class="input" name="pwf_company_name" value="<?= htmlspecialchars($s['pwf_company_name'] ?: 'Prapen Wood Furniture') ?>"></div>
          <div class="pwf-form-group"><label>Tagline / Slogan</label><input class="input" name="pwf_company_tagline" value="<?= htmlspecialchars($s['pwf_company_tagline']) ?>"></div>
          <div class="pwf-form-group"><label>Phone / WhatsApp</label><input class="input" name="pwf_company_phone" value="<?= htmlspecialchars($s['pwf_company_phone']) ?>"></div>
          <div class="pwf-form-group"><label>Address</label><input class="input" name="pwf_company_address" value="<?= htmlspecialchars($s['pwf_company_address']) ?>"></div>
        </div>
        <button class="btn" type="submit"><i class="bi bi-check-circle"></i> Save Info</button>
      </form>
    </div>
  </div>

  <!-- BACKUP & RESET -->
  <div class="pwf-card" style="grid-column:1/-1;margin-top:4px">
    <div class="pwf-card-header" style="color:#be123c"><i class="bi bi-database-down me-2"></i>Backup &amp; Reset Data</div>
    <div class="pwf-card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">

        <!-- Backup -->
        <div style="border:1px solid var(--border);border-radius:10px;padding:16px">
          <div style="font-size:13px;font-weight:700;color:var(--text);margin-bottom:6px"><i class="bi bi-cloud-download me-2" style="color:#3b82f6"></i>Download Backup</div>
          <div style="font-size:11.5px;color:var(--muted);margin-bottom:14px">Export all data (orders, containers, customers, craftsmen) as an SQL file. Safe — does not modify any data.</div>
          <form method="post">
            <input type="hidden" name="_action" value="backup_data">
            <button class="btn" type="submit" style="background:#3b82f6;color:#fff;border:none;width:100%">
              <i class="bi bi-download me-1"></i> Download .sql Backup
            </button>
          </form>
        </div>

        <!-- Reset Orders only -->
        <div style="border:1px solid #fca5a5;border-radius:10px;padding:16px;background:#fff5f5">
          <div style="font-size:13px;font-weight:700;color:#be123c;margin-bottom:6px"><i class="bi bi-arrow-counterclockwise me-2"></i>Reset Shipping Data</div>
          <div style="font-size:11.5px;color:var(--muted);margin-bottom:14px">Deletes all containers &amp; container items, wipes SPK, and reverts all order statuses to <strong>On Progress</strong>. Keeps customers, craftsmen, and orders.</div>
          <button class="btn" type="button" style="background:#f97316;color:#fff;border:none;width:100%"
            onclick="if(confirm('Reset all shipping/container data?\\nOrders will revert to On Progress.\\n\\nThis cannot be undone!')){document.getElementById('frmResetOrders').submit()}">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Shipping &amp; Containers
          </button>
          <form id="frmResetOrders" method="post" style="display:none"><input type="hidden" name="_action" value="reset_orders"></form>
        </div>

        <!-- Full wipe -->
        <div style="border:2px solid #be123c;border-radius:10px;padding:16px;background:#fff1f2">
          <div style="font-size:13px;font-weight:700;color:#be123c;margin-bottom:6px"><i class="bi bi-trash3 me-2"></i>Full Data Wipe</div>
          <div style="font-size:11.5px;color:var(--muted);margin-bottom:14px">Permanently deletes <strong>all</strong> orders, containers, customers, craftsmen, and SPK. Settings &amp; uploaded files are kept. <span style="color:#be123c;font-weight:600">Cannot be undone.</span></div>
          <div style="margin-bottom:10px">
            <label style="font-size:11px;color:var(--muted)">Type <strong>RESET</strong> to confirm:</label>
            <input type="text" id="resetConfirmInput" class="input" placeholder="RESET" style="width:100%;margin-top:4px">
          </div>
          <button class="btn" type="button" style="background:#be123c;color:#fff;border:none;width:100%"
            onclick="doFullReset()">
            <i class="bi bi-trash3 me-1"></i> Wipe All Data
          </button>
          <form id="frmResetAll" method="post" style="display:none"><input type="hidden" name="_action" value="reset_all"></form>
        </div>

      </div>
    </div>
  </div>

</div>

<script>
  function doFullReset() {
    const v = (document.getElementById('resetConfirmInput')?.value || '').trim();
    if (v !== 'RESET') {
      alert('Please type RESET to confirm.');
      return;
    }
    if (!confirm('FINAL WARNING: This will delete ALL data permanently.\n\nAre you absolutely sure?')) return;
    document.getElementById('frmResetAll').submit();
  }

  function checkPwd() {
    const a = document.getElementById('pwdNew').value;
    const b = document.getElementById('pwdConfirm').value;
    const el = document.getElementById('pwdMsg');
    if (!b) {
      el.textContent = '';
      return;
    }
    if (a === b) {
      el.style.color = 'var(--success)';
      el.textContent = '✓ Match';
    } else {
      el.style.color = 'var(--danger)';
      el.textContent = '✗ Do not match';
    }
  }

  function previewTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    document.getElementById('cardLight').style.borderColor = t === 'light' ? 'var(--gold)' : 'var(--border)';
    document.getElementById('cardDark').style.borderColor = t === 'dark' ? 'var(--gold)' : 'var(--border)';
  }
</script>
<?php pwfOfficeFooter();
