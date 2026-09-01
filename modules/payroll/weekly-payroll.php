<?php
// modules/payroll/weekly-payroll.php - GAJI MINGGUAN
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$pageTitle = 'Gaji Mingguan';

// Auto-create table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_weekly (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        employee_name VARCHAR(100) NOT NULL,
        position VARCHAR(100),
        department VARCHAR(100),
        period_month INT NOT NULL,
        period_year INT NOT NULL,
        week_1 DECIMAL(15,2) DEFAULT 0,
        week_2 DECIMAL(15,2) DEFAULT 0,
        week_3 DECIMAL(15,2) DEFAULT 0,
        week_4 DECIMAL(15,2) DEFAULT 0,
        total_salary DECIMAL(15,2) DEFAULT 0,
        notes TEXT,
        status VARCHAR(20) DEFAULT 'draft',
        cashbook_synced TINYINT(1) DEFAULT 0,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_emp_period (employee_id, period_month, period_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Current period
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
$monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$periodLabel = $monthNames[$month] . ' ' . $year;

// Get employees
$employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1 ORDER BY full_name ASC");

// Get existing weekly records for this period
$weeklyRecords = [];
$rows = $db->fetchAll("SELECT * FROM payroll_weekly WHERE period_month = ? AND period_year = ? ORDER BY employee_name ASC", [$month, $year]);
foreach ($rows as $r) {
    $weeklyRecords[$r['employee_id']] = $r;
}

// Flash messages
$flash = $_SESSION['flash'] ?? null;
if ($flash && (!isset($flash['type']) || !isset($flash['message']))) $flash = null;
unset($_SESSION['flash']);

include '../../includes/header.php';
?>

<style>
:root{--wk-primary:#6366f1;--wk-success:#10b981;--wk-warning:#f59e0b;--wk-danger:#ef4444;--wk-radius:10px}
.wk-wrap{max-width:1400px;margin:0 auto;padding:0 1rem}
.wk-hero{background:linear-gradient(135deg,#1e1b4b,#312e81,#4338ca);border-radius:16px;padding:1.5rem 2rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem}
.wk-hero h1{font-size:1.5rem;font-weight:800;color:#fff;margin:0;display:flex;align-items:center;gap:.5rem}
.wk-hero-sub{font-size:.8rem;color:rgba(255,255,255,.7);margin-top:.25rem}
.wk-nav{display:flex;align-items:center;gap:.5rem}
.wk-nav-btn{padding:.5rem .75rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:8px;font-size:.75rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:.3rem;transition:all .2s;cursor:pointer}
.wk-nav-btn:hover{background:rgba(255,255,255,.25)}
.wk-nav select{padding:.4rem .6rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:6px;font-size:.8rem}
.wk-nav select option{color:#000}
.wk-card{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:var(--wk-radius);overflow:hidden;margin-bottom:1.5rem}
.wk-card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--bg-tertiary);display:flex;justify-content:space-between;align-items:center}
.wk-card-title{font-size:.95rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.4rem}
.wk-badge{padding:.2rem .5rem;border-radius:6px;font-size:.65rem;font-weight:700;text-transform:uppercase}
.wk-badge.draft{background:rgba(245,158,11,.2);color:#f59e0b}
.wk-badge.paid{background:rgba(16,185,129,.2);color:#10b981}
.wk-table{width:100%;border-collapse:collapse}
.wk-table thead th{padding:.65rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:var(--text-muted);border-bottom:2px solid var(--bg-tertiary);text-align:left;white-space:nowrap}
.wk-table tbody td{padding:.6rem .75rem;font-size:.8rem;color:var(--text-primary);border-bottom:1px solid var(--bg-tertiary);vertical-align:middle}
.wk-table tbody tr:hover{background:var(--bg-primary)}
.wk-emp-name{font-weight:700;font-size:.82rem}
.wk-emp-pos{font-size:.68rem;color:var(--text-muted)}
.wk-input{width:100%;max-width:110px;padding:.4rem .5rem;border-radius:6px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.8rem;text-align:right;font-family:monospace}
.wk-input:focus{outline:none;border-color:var(--wk-primary)}
.wk-total{font-weight:800;font-size:.85rem;color:var(--wk-success);font-family:monospace}
.wk-actions{display:flex;gap:.35rem}
.wk-btn{padding:.35rem .6rem;border-radius:6px;font-size:.68rem;font-weight:600;cursor:pointer;border:none;display:flex;align-items:center;gap:.25rem;transition:all .2s;text-decoration:none}
.wk-btn-save{background:linear-gradient(135deg,#10b981,#059669);color:#fff}
.wk-btn-save:hover{transform:translateY(-1px);box-shadow:0 3px 8px rgba(16,185,129,.3)}
.wk-btn-slip{background:rgba(99,102,241,.15);color:#6366f1}
.wk-btn-slip:hover{background:rgba(99,102,241,.25)}
.wk-btn-kas{background:rgba(245,158,11,.15);color:#f59e0b}
.wk-btn-kas:hover{background:rgba(245,158,11,.25)}
.wk-btn-del{background:rgba(239,68,68,.15);color:#ef4444}
.wk-btn-del:hover{background:rgba(239,68,68,.25)}
.wk-btn-lg{padding:.6rem 1.25rem;font-size:.8rem;border-radius:8px}
.wk-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.wk-stat{background:var(--bg-secondary);border:1px solid var(--bg-tertiary);border-radius:var(--wk-radius);padding:1rem;display:flex;align-items:center;gap:.75rem}
.wk-stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem}
.wk-stat-icon.blue{background:rgba(99,102,241,.15)}
.wk-stat-icon.green{background:rgba(16,185,129,.15)}
.wk-stat-icon.amber{background:rgba(245,158,11,.15)}
.wk-stat-icon.red{background:rgba(239,68,68,.15)}
.wk-stat-val{font-size:1.1rem;font-weight:800;color:var(--text-primary)}
.wk-stat-label{font-size:.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px}
.wk-alert{padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600}
.wk-alert.ok{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#10b981}
.wk-alert.err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444}
.wk-synced{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .4rem;border-radius:4px;font-size:.6rem;font-weight:700;background:rgba(16,185,129,.15);color:#10b981}
.wk-note-input{width:100%;padding:.35rem .5rem;border-radius:5px;background:var(--bg-primary);border:1px solid var(--bg-tertiary);color:var(--text-primary);font-size:.72rem;font-family:inherit}
@media(max-width:900px){.wk-table{display:block;overflow-x:auto}.wk-stats{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.wk-stats{grid-template-columns:1fr}.wk-hero{flex-direction:column;text-align:center}}
</style>

<div class="wk-wrap">
    <!-- Hero -->
    <div class="wk-hero">
        <div>
            <h1>💰 Gaji Mingguan</h1>
            <div class="wk-hero-sub">Tagihan gaji staff mingguan — <?php echo $periodLabel; ?></div>
        </div>
        <div class="wk-nav">
            <?php
                $prevMonth = $month - 1; $prevYear = $year;
                if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                $nextMonth = $month + 1; $nextYear = $year;
                if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
            ?>
            <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="wk-nav-btn">◀ Prev</a>
            <select onchange="location.href='?month='+this.value+'&year=<?php echo $year; ?>'">
                <?php for ($m=1; $m<=12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m==$month?'selected':''; ?>><?php echo $monthNames[$m]; ?></option>
                <?php endfor; ?>
            </select>
            <select onchange="location.href='?month=<?php echo $month; ?>&year='+this.value">
                <?php for ($y=2024; $y<=((int)date('Y')+2); $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $y==$year?'selected':''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="wk-nav-btn">Next ▶</a>
        </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="wk-alert <?php echo $flash['type'] === 'success' ? 'ok' : 'err'; ?>">
        <?php echo $flash['type'] === 'success' ? '✅' : '❌'; ?> <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <?php
        $totalEmp = count($employees);
        $totalFilled = count($weeklyRecords);
        $grandTotal = array_sum(array_column($weeklyRecords, 'total_salary'));
        $totalSynced = count(array_filter($weeklyRecords, function($r){ return $r['cashbook_synced']; }));
    ?>
    <div class="wk-stats">
        <div class="wk-stat">
            <div class="wk-stat-icon blue">👥</div>
            <div><div class="wk-stat-val"><?php echo $totalEmp; ?></div><div class="wk-stat-label">Total Staff</div></div>
        </div>
        <div class="wk-stat">
            <div class="wk-stat-icon green">📝</div>
            <div><div class="wk-stat-val"><?php echo $totalFilled; ?>/<?php echo $totalEmp; ?></div><div class="wk-stat-label">Sudah Diisi</div></div>
        </div>
        <div class="wk-stat">
            <div class="wk-stat-icon amber">💰</div>
            <div><div class="wk-stat-val">Rp <?php echo number_format($grandTotal, 0, ',', '.'); ?></div><div class="wk-stat-label">Total Gaji</div></div>
        </div>
        <div class="wk-stat">
            <div class="wk-stat-icon red">📚</div>
            <div><div class="wk-stat-val"><?php echo $totalSynced; ?>/<?php echo $totalFilled; ?></div><div class="wk-stat-label">Masuk Buku Kas</div></div>
        </div>
    </div>

    <!-- Table -->
    <div class="wk-card">
        <div class="wk-card-head">
            <span class="wk-card-title">📋 Data Gaji Mingguan — <?php echo $periodLabel; ?></span>
        </div>
        <?php if (count($employees) > 0): ?>
        <div style="overflow-x:auto">
        <table class="wk-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Staff</th>
                    <th>Jabatan / Divisi</th>
                    <th style="text-align:right">Minggu 1</th>
                    <th style="text-align:right">Minggu 2</th>
                    <th style="text-align:right">Minggu 3</th>
                    <th style="text-align:right">Minggu 4</th>
                    <th style="text-align:right">Total</th>
                    <th>Catatan</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 0; foreach ($employees as $emp):
                    $no++;
                    $rec = $weeklyRecords[$emp['id']] ?? null;
                    $w1 = $rec ? (float)$rec['week_1'] : 0;
                    $w2 = $rec ? (float)$rec['week_2'] : 0;
                    $w3 = $rec ? (float)$rec['week_3'] : 0;
                    $w4 = $rec ? (float)$rec['week_4'] : 0;
                    $total = $w1 + $w2 + $w3 + $w4;
                    $synced = $rec && $rec['cashbook_synced'];
                ?>
                <tr data-emp-id="<?php echo $emp['id']; ?>">
                    <td><?php echo $no; ?></td>
                    <td>
                        <div class="wk-emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                        <div class="wk-emp-pos"><?php echo htmlspecialchars($emp['employee_code'] ?? ''); ?></div>
                    </td>
                    <td>
                        <div style="font-size:.78rem"><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></div>
                        <div class="wk-emp-pos"><?php echo htmlspecialchars($emp['department'] ?? '-'); ?></div>
                    </td>
                    <td><input type="number" class="wk-input wk-week" data-week="1" value="<?php echo $w1 > 0 ? $w1 : ''; ?>" placeholder="0" min="0" step="1000"></td>
                    <td><input type="number" class="wk-input wk-week" data-week="2" value="<?php echo $w2 > 0 ? $w2 : ''; ?>" placeholder="0" min="0" step="1000"></td>
                    <td><input type="number" class="wk-input wk-week" data-week="3" value="<?php echo $w3 > 0 ? $w3 : ''; ?>" placeholder="0" min="0" step="1000"></td>
                    <td><input type="number" class="wk-input wk-week" data-week="4" value="<?php echo $w4 > 0 ? $w4 : ''; ?>" placeholder="0" min="0" step="1000"></td>
                    <td class="wk-total wk-total-cell">Rp <?php echo number_format($total, 0, ',', '.'); ?></td>
                    <td><input type="text" class="wk-note-input wk-notes" value="<?php echo htmlspecialchars($rec['notes'] ?? ''); ?>" placeholder="Catatan..."></td>
                    <td>
                        <?php if ($synced): ?>
                            <span class="wk-synced">✅ Kas</span>
                        <?php elseif ($rec): ?>
                            <span class="wk-badge draft">Draft</span>
                        <?php else: ?>
                            <span style="font-size:.65rem;color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="wk-actions">
                            <button class="wk-btn wk-btn-save" onclick="saveRow(<?php echo $emp['id']; ?>)" title="Simpan">💾</button>
                            <?php if ($rec): ?>
                            <a href="print-weekly-slip.php?id=<?php echo $rec['id']; ?>" target="_blank" class="wk-btn wk-btn-slip" title="Print Slip">🖨️</a>
                            <?php if (!$synced): ?>
                            <button class="wk-btn wk-btn-kas" onclick="syncKas(<?php echo $emp['id']; ?>)" title="Masukkan ke Buku Kas">📚</button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;color:var(--text-muted)">
            <p style="font-size:1.5rem;margin-bottom:.5rem">👥</p>
            <p>Belum ada data karyawan. <a href="employees.php" style="color:var(--wk-primary)">Tambah karyawan dulu</a></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-calculate total when week inputs change
document.querySelectorAll('.wk-week').forEach(function(inp) {
    inp.addEventListener('input', function() {
        var row = this.closest('tr');
        var weeks = row.querySelectorAll('.wk-week');
        var total = 0;
        weeks.forEach(function(w) { total += parseFloat(w.value) || 0; });
        row.querySelector('.wk-total-cell').textContent = 'Rp ' + total.toLocaleString('id-ID');
    });
});

function saveRow(empId) {
    var row = document.querySelector('tr[data-emp-id="' + empId + '"]');
    var weeks = row.querySelectorAll('.wk-week');
    var notes = row.querySelector('.wk-notes');
    
    var data = {
        action: 'save',
        employee_id: empId,
        period_month: <?php echo $month; ?>,
        period_year: <?php echo $year; ?>,
        week_1: parseFloat(weeks[0].value) || 0,
        week_2: parseFloat(weeks[1].value) || 0,
        week_3: parseFloat(weeks[2].value) || 0,
        week_4: parseFloat(weeks[3].value) || 0,
        notes: notes ? notes.value.trim() : ''
    };

    fetch('weekly-payroll-save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            // Quick visual feedback
            var btn = row.querySelector('.wk-btn-save');
            btn.textContent = '✅';
            setTimeout(function() { btn.textContent = '💾'; }, 1500);
            // Reload to update status & slip links
            setTimeout(function() { location.reload(); }, 800);
        } else {
            alert('❌ ' + (res.message || 'Gagal menyimpan'));
        }
    })
    .catch(function(err) { alert('❌ Error: ' + err.message); });
}

function syncKas(empId) {
    if (!confirm('Masukkan gaji mingguan staff ini ke Buku Kas?')) return;
    
    fetch('weekly-payroll-save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'sync_cashbook',
            employee_id: empId,
            period_month: <?php echo $month; ?>,
            period_year: <?php echo $year; ?>
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            alert('✅ ' + res.message);
            location.reload();
        } else {
            alert('❌ ' + (res.message || 'Gagal sync'));
        }
    })
    .catch(function(err) { alert('❌ Error: ' + err.message); });
}
</script>

<?php include '../../includes/footer.php'; ?>
