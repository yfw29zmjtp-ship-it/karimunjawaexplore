<?php
/**
 * CQC Quotation - Edit Quotation
 * Theme: Navy + Gold (CQC Engineering)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

try {
    $pdo = getCQCDatabaseConnection();
    ensureCQCQuotationTable($pdo);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get quotation ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: index-cqc.php?tab=quotation');
    exit;
}

// Fetch quotation
$stmt = $pdo->prepare("SELECT * FROM cqc_quotations WHERE id = ?");
$stmt->execute([$id]);
$quotation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    $_SESSION['error'] = 'Quotation tidak ditemukan';
    header('Location: index-cqc.php?tab=quotation');
    exit;
}

// Fetch items
$stmtItems = $pdo->prepare("SELECT * FROM cqc_quotation_items WHERE quotation_id = ? ORDER BY sort_order");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Get customers from business database
$bizDb = Database::getInstance();
$customers = [];
try {
    $customers = $bizDb->fetchAll("SELECT * FROM customers WHERE status = 'active' ORDER BY name");
} catch (Exception $e) {
    // Table might not exist
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Update quotation
        $stmt = $pdo->prepare("
            UPDATE cqc_quotations SET
                quote_date = ?,
                valid_until = ?,
                client_name = ?,
                client_attn = ?,
                client_address = ?,
                client_phone = ?,
                client_email = ?,
                subject = ?,
                project_name = ?,
                project_location = ?,
                solar_capacity_kwp = ?,
                subtotal = ?,
                discount_type = ?,
                discount_value = ?,
                discount_amount = ?,
                ppn_percentage = ?,
                ppn_amount = ?,
                total_amount = ?,
                terms_conditions = ?,
                notes = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $subtotal = floatval($_POST['subtotal']);
        $discountType = $_POST['discount_type'];
        $discountValue = floatval($_POST['discount_value']);
        $discountAmount = $discountType === 'percentage' ? ($subtotal * $discountValue / 100) : $discountValue;
        $afterDiscount = $subtotal - $discountAmount;
        $ppnPercentage = floatval($_POST['ppn_percentage']);
        $ppnAmount = $afterDiscount * $ppnPercentage / 100;
        $totalAmount = $afterDiscount + $ppnAmount;
        
        $stmt->execute([
            $_POST['quote_date'],
            $_POST['valid_until'] ?: null,
            $_POST['client_name'],
            $_POST['client_attn'],
            $_POST['client_address'],
            $_POST['client_phone'],
            $_POST['client_email'],
            $_POST['subject'],
            trim($_POST['project_name'] ?? ''),
            trim($_POST['project_location'] ?? ''),
            floatval($_POST['solar_capacity_kwp'] ?? 0),
            $subtotal,
            $discountType,
            $discountValue,
            $discountAmount,
            $ppnPercentage,
            $ppnAmount,
            $totalAmount,
            $_POST['terms_conditions'],
            $_POST['notes'],
            $_POST['status'],
            $id
        ]);
        
        // Delete existing items
        $stmtDel = $pdo->prepare("DELETE FROM cqc_quotation_items WHERE quotation_id = ?");
        $stmtDel->execute([$id]);
        
        // Insert new items
        $stmtItem = $pdo->prepare("
            INSERT INTO cqc_quotation_items (quotation_id, description, remarks, quantity, unit, unit_price, amount, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $descriptions = $_POST['item_description'] ?? [];
        $remarks = $_POST['item_remarks'] ?? [];
        $quantities = $_POST['item_qty'] ?? [];
        $units = $_POST['item_unit'] ?? [];
        $prices = $_POST['item_price'] ?? [];
        
        foreach ($descriptions as $i => $desc) {
            if (empty(trim($desc))) continue;
            
            $qty = floatval($quantities[$i] ?? 1);
            $price = floatval($prices[$i] ?? 0);
            $amount = $qty * $price;
            
            $stmtItem->execute([
                $id,
                $desc,
                $remarks[$i] ?? '',
                $qty,
                $units[$i] ?? 'Unit',
                $price,
                $amount,
                $i + 1
            ]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Quotation berhasil diupdate!';
        header('Location: index-cqc.php?tab=quotation');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Gagal update quotation: ' . $e->getMessage();
    }
}

$pageTitle = "Edit Quotation";
include '../../includes/header.php';
?>

<style>
    :root {
        --cqc-primary: #0d1f3c;
        --cqc-primary-light: #1a3a5c;
        --cqc-accent: #f0b429;
        --cqc-accent-dark: #d4960d;
        --cqc-success: #10b981;
        --cqc-danger: #ef4444;
        --cqc-muted: #64748b;
        --cqc-border: #e2e8f0;
        --cqc-bg: #f8fafc;
    }
    
    .quote-container { max-width: 1000px; margin: 0 auto; }
    
    .quote-header {
        background: linear-gradient(135deg, var(--cqc-primary), var(--cqc-primary-light));
        color: white; padding: 20px 24px; border-radius: 12px 12px 0 0;
        display: flex; justify-content: space-between; align-items: center;
    }
    .quote-header h1 { font-size: 18px; font-weight: 700; margin: 0; }
    .quote-header p { font-size: 12px; opacity: 0.8; margin: 4px 0 0; }
    
    .quote-form {
        background: white; padding: 24px; border-radius: 0 0 12px 12px;
        border: 1px solid var(--cqc-border); border-top: none;
    }
    
    .form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 16px; }
    .form-row.three { grid-template-columns: repeat(3, 1fr); }
    .form-group { margin-bottom: 0; }
    .form-group.full { grid-column: 1 / -1; }
    
    .form-label {
        display: block; font-size: 11px; font-weight: 600; color: var(--cqc-muted);
        margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--cqc-border);
        border-radius: 8px; font-size: 13px; transition: all 0.2s;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none; border-color: var(--cqc-accent);
        box-shadow: 0 0 0 3px rgba(240, 180, 41, 0.1);
    }
    
    .section-title {
        font-size: 13px; font-weight: 700; color: var(--cqc-primary);
        margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 2px solid var(--cqc-border);
    }
    .section-title:first-of-type { margin-top: 0; }
    
    /* Items Table */
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .items-table th {
        background: var(--cqc-primary); color: white; padding: 10px 8px;
        font-size: 10px; font-weight: 600; text-align: left; text-transform: uppercase;
    }
    .items-table td { padding: 8px; border-bottom: 1px solid var(--cqc-border); }
    .items-table .item-input {
        width: 100%; padding: 8px; border: 1px solid var(--cqc-border);
        border-radius: 6px; font-size: 12px;
    }
    .items-table .col-no { width: 40px; text-align: center; }
    .items-table .col-desc { width: 25%; }
    .items-table .col-remarks { width: 15%; }
    .items-table .col-qty { width: 60px; }
    .items-table .col-unit { width: 70px; }
    .items-table .col-price { width: 120px; }
    .items-table .col-total { width: 120px; }
    .items-table .col-action { width: 40px; text-align: center; }
    
    .btn-add-row {
        background: var(--cqc-bg); border: 1px dashed var(--cqc-border);
        padding: 10px 16px; border-radius: 8px; cursor: pointer;
        font-size: 12px; color: var(--cqc-muted); transition: all 0.2s;
    }
    .btn-add-row:hover { border-color: var(--cqc-accent); color: var(--cqc-primary); }
    
    .btn-remove { background: none; border: none; cursor: pointer; font-size: 16px; }
    
    /* Summary */
    .summary-box {
        background: var(--cqc-bg); border-radius: 8px; padding: 16px;
        margin-top: 16px;
    }
    .summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; }
    .summary-row.total { font-weight: 700; font-size: 15px; border-top: 2px solid var(--cqc-border); padding-top: 12px; margin-top: 8px; }
    
    /* Buttons */
    .form-actions { display: flex; gap: 12px; margin-top: 24px; justify-content: flex-end; }
    .btn {
        padding: 12px 24px; border-radius: 8px; font-size: 13px; font-weight: 700;
        cursor: pointer; transition: all 0.2s; border: none;
    }
    .btn-primary { background: var(--cqc-accent); color: var(--cqc-primary); }
    .btn-primary:hover { background: #ffc942; }
    .btn-secondary { background: var(--cqc-bg); color: var(--cqc-muted); border: 1px solid var(--cqc-border); }
    .btn-secondary:hover { background: #e2e8f0; }
</style>

<div class="quote-container">
    <div class="quote-header">
        <div>
            <h1>✏️ Edit Quotation</h1>
            <p><?php echo htmlspecialchars($quotation['quote_number']); ?></p>
        </div>
        <a href="index-cqc.php?tab=quotation" style="color: white; text-decoration: none; font-size: 13px;">← Kembali</a>
    </div>
    
    <form method="POST" class="quote-form" id="quotationForm">
        <div class="section-title">📋 Informasi Quotation</div>
        
        <div class="form-row three">
            <div class="form-group">
                <label class="form-label">No. Quotation</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars($quotation['quote_number']); ?>" readonly style="background: #f1f5f9;">
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal</label>
                <input type="date" name="quote_date" class="form-input" value="<?php echo $quotation['quote_date']; ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Berlaku Sampai</label>
                <input type="date" name="valid_until" class="form-input" value="<?php echo $quotation['valid_until']; ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" <?php echo $quotation['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $quotation['status'] === 'sent' ? 'selected' : ''; ?>>Terkirim</option>
                    <option value="approved" <?php echo $quotation['status'] === 'approved' ? 'selected' : ''; ?>>Disetujui</option>
                    <option value="rejected" <?php echo $quotation['status'] === 'rejected' ? 'selected' : ''; ?>>Ditolak</option>
                    <option value="expired" <?php echo $quotation['status'] === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Subject</label>
                <input type="text" name="subject" class="form-input" value="<?php echo htmlspecialchars($quotation['subject'] ?? ''); ?>" placeholder="Penawaran jasa...">
            </div>
        </div>
        
        <div class="section-title">👤 Informasi Klien</div>
        
        <?php if (!empty($customers)): ?>
        <div class="form-row">
            <div class="form-group full">
                <label class="form-label">Pilih dari Database Customer</label>
                <select class="form-select" id="customerSelect" onchange="fillCustomerData()">
                    <option value="">-- Pilih Customer --</option>
                    <?php foreach ($customers as $cust): ?>
                        <option value="<?php echo $cust['id']; ?>" 
                            data-name="<?php echo htmlspecialchars($cust['name']); ?>"
                            data-address="<?php echo htmlspecialchars($cust['address'] ?? ''); ?>"
                            data-phone="<?php echo htmlspecialchars($cust['phone'] ?? ''); ?>"
                            data-email="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"
                            data-contact="<?php echo htmlspecialchars($cust['contact_person'] ?? ''); ?>">
                            <?php echo htmlspecialchars($cust['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Perusahaan / Klien *</label>
                <input type="text" name="client_name" id="clientName" class="form-input" value="<?php echo htmlspecialchars($quotation['client_name']); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Attn (Contact Person)</label>
                <input type="text" name="client_attn" id="clientAttn" class="form-input" value="<?php echo htmlspecialchars($quotation['client_attn'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Telepon</label>
                <input type="text" name="client_phone" id="clientPhone" class="form-input" value="<?php echo htmlspecialchars($quotation['client_phone'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="client_email" id="clientEmail" class="form-input" value="<?php echo htmlspecialchars($quotation['client_email'] ?? ''); ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Alamat</label>
            <textarea name="client_address" id="clientAddress" class="form-textarea" rows="2"><?php echo htmlspecialchars($quotation['client_address'] ?? ''); ?></textarea>
        </div>
        
        <div class="section-title">☀️ Informasi Proyek</div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nama Proyek *</label>
                <input type="text" name="project_name" class="form-input" value="<?php echo htmlspecialchars($quotation['project_name'] ?? ''); ?>" required placeholder="Contoh: Solar Panel PT. ABC">
            </div>
            <div class="form-group">
                <label class="form-label">Kapasitas (KWp)</label>
                <input type="number" name="solar_capacity_kwp" class="form-input" value="<?php echo htmlspecialchars($quotation['solar_capacity_kwp'] ?? ''); ?>" step="0.1" min="0" placeholder="3.5">
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">Lokasi Proyek *</label>
            <input type="text" name="project_location" class="form-input" value="<?php echo htmlspecialchars($quotation['project_location'] ?? ''); ?>" required placeholder="Alamat lengkap lokasi proyek">
        </div>
        
        <?php if ($quotation['status'] !== 'approved'): ?>
        <p style="margin: 10px 0 0 0; font-size: 12px; color: #64748b;">
            <strong>💡 Info:</strong> Data proyek di atas akan otomatis membuat proyek baru saat quotation di-ACC oleh klien.
        </p>
        <?php else: ?>
        <p style="margin: 10px 0 0 0; font-size: 12px; color: #22c55e;">
            <strong>✅ ACC:</strong> Quotation ini sudah di-ACC dan proyek telah dibuat.
        </p>
        <?php endif; ?>
        
        <div class="section-title">📦 Item Penawaran</div>
        
        <table class="items-table" id="itemsTable">
            <thead>
                <tr>
                    <th class="col-no">NO</th>
                    <th class="col-desc">DESCRIPTION</th>
                    <th class="col-remarks">REMARKS</th>
                    <th class="col-qty">QTY</th>
                    <th class="col-unit">UNIT</th>
                    <th class="col-price">PRICE</th>
                    <th class="col-total">TOTAL PRICE</th>
                    <th class="col-action"></th>
                </tr>
            </thead>
            <tbody id="itemsBody">
                <?php foreach ($items as $idx => $item): ?>
                <tr class="item-row">
                    <td class="col-no"><?php echo $idx + 1; ?></td>
                    <td><input type="text" name="item_description[]" class="item-input" value="<?php echo htmlspecialchars($item['description']); ?>" placeholder="Deskripsi item"></td>
                    <td><input type="text" name="item_remarks[]" class="item-input" value="<?php echo htmlspecialchars($item['remarks'] ?? ''); ?>" placeholder="CQC"></td>
                    <td><input type="number" name="item_qty[]" class="item-input item-qty" value="<?php echo $item['quantity']; ?>" min="1" onchange="calculateRow(this)"></td>
                    <td>
                        <select name="item_unit[]" class="item-input">
                            <option value="Unit" <?php echo $item['unit'] === 'Unit' ? 'selected' : ''; ?>>Unit</option>
                            <option value="Set" <?php echo $item['unit'] === 'Set' ? 'selected' : ''; ?>>Set</option>
                            <option value="Pcs" <?php echo $item['unit'] === 'Pcs' ? 'selected' : ''; ?>>Pcs</option>
                            <option value="Lot" <?php echo $item['unit'] === 'Lot' ? 'selected' : ''; ?>>Lot</option>
                            <option value="Paket" <?php echo $item['unit'] === 'Paket' ? 'selected' : ''; ?>>Paket</option>
                            <option value="Ls" <?php echo $item['unit'] === 'Ls' ? 'selected' : ''; ?>>Ls</option>
                        </select>
                    </td>
                    <td><input type="number" name="item_price[]" class="item-input item-price" value="<?php echo $item['unit_price']; ?>" min="0" onchange="calculateRow(this)"></td>
                    <td><input type="text" class="item-input item-total" value="<?php echo number_format($item['amount'], 0, ',', '.'); ?>" readonly style="background:#f8fafc;font-weight:600;"></td>
                    <td><button type="button" class="btn-remove" onclick="removeRow(this)">🗑</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr class="item-row">
                    <td class="col-no">1</td>
                    <td><input type="text" name="item_description[]" class="item-input" placeholder="Deskripsi item"></td>
                    <td><input type="text" name="item_remarks[]" class="item-input" placeholder="CQC" value="CQC"></td>
                    <td><input type="number" name="item_qty[]" class="item-input item-qty" value="1" min="1" onchange="calculateRow(this)"></td>
                    <td>
                        <select name="item_unit[]" class="item-input">
                            <option value="Unit">Unit</option>
                            <option value="Set">Set</option>
                            <option value="Pcs">Pcs</option>
                            <option value="Lot">Lot</option>
                            <option value="Paket">Paket</option>
                            <option value="Ls">Ls</option>
                        </select>
                    </td>
                    <td><input type="number" name="item_price[]" class="item-input item-price" value="0" min="0" onchange="calculateRow(this)"></td>
                    <td><input type="text" class="item-input item-total" value="0" readonly style="background:#f8fafc;font-weight:600;"></td>
                    <td><button type="button" class="btn-remove" onclick="removeRow(this)">🗑</button></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <button type="button" class="btn-add-row" onclick="addRow()">+ Tambah Item</button>
        
        <div class="summary-box">
            <div class="form-row">
                <div>
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span id="displaySubtotal">Rp 0</span>
                    </div>
                    <div class="summary-row">
                        <span>
                            Diskon:
                            <select name="discount_type" id="discountType" style="margin-left:8px;padding:4px;border-radius:4px;border:1px solid #ddd;">
                                <option value="fixed" <?php echo ($quotation['discount_type'] ?? 'fixed') === 'fixed' ? 'selected' : ''; ?>>Rp</option>
                                <option value="percentage" <?php echo ($quotation['discount_type'] ?? '') === 'percentage' ? 'selected' : ''; ?>>%</option>
                            </select>
                            <input type="number" name="discount_value" id="discountValue" value="<?php echo $quotation['discount_value'] ?? 0; ?>" min="0" style="width:80px;margin-left:4px;padding:4px;border-radius:4px;border:1px solid #ddd;" onchange="calculateTotals()">
                        </span>
                        <span id="displayDiscount">- Rp 0</span>
                    </div>
                    <div class="summary-row">
                        <span>
                            PPN:
                            <input type="number" name="ppn_percentage" id="ppnPercentage" value="<?php echo $quotation['ppn_percentage'] ?? 11; ?>" min="0" max="100" style="width:50px;margin-left:8px;padding:4px;border-radius:4px;border:1px solid #ddd;" onchange="calculateTotals()">%
                        </span>
                        <span id="displayPPN">Rp 0</span>
                    </div>
                    <div class="summary-row total">
                        <span>GRAND TOTAL:</span>
                        <span id="displayTotal">Rp 0</span>
                    </div>
                </div>
            </div>
            <input type="hidden" name="subtotal" id="subtotalInput" value="<?php echo $quotation['subtotal']; ?>">
        </div>
        
        <div class="section-title">📝 Terms & Conditions</div>
        <div class="form-group">
            <textarea name="terms_conditions" class="form-textarea" rows="5" placeholder="1. Pembayaran: ...&#10;2. Waktu pengerjaan: ...&#10;3. Garansi: ..."><?php echo htmlspecialchars($quotation['terms_conditions'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-group">
            <label class="form-label">Catatan Tambahan</label>
            <textarea name="notes" class="form-textarea" rows="2"><?php echo htmlspecialchars($quotation['notes'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-actions">
            <a href="index-cqc.php?tab=quotation" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
        </div>
    </form>
</div>

<script>
let rowCount = <?php echo count($items) ?: 1; ?>;

function addRow() {
    rowCount++;
    const tbody = document.getElementById('itemsBody');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="col-no">${rowCount}</td>
        <td><input type="text" name="item_description[]" class="item-input" placeholder="Deskripsi item"></td>
        <td><input type="text" name="item_remarks[]" class="item-input" placeholder="CQC" value="CQC"></td>
        <td><input type="number" name="item_qty[]" class="item-input item-qty" value="1" min="1" onchange="calculateRow(this)"></td>
        <td>
            <select name="item_unit[]" class="item-input">
                <option value="Unit">Unit</option>
                <option value="Set">Set</option>
                <option value="Pcs">Pcs</option>
                <option value="Lot">Lot</option>
                <option value="Paket">Paket</option>
                <option value="Ls">Ls</option>
            </select>
        </td>
        <td><input type="number" name="item_price[]" class="item-input item-price" value="0" min="0" onchange="calculateRow(this)"></td>
        <td><input type="text" class="item-input item-total" value="0" readonly style="background:#f8fafc;font-weight:600;"></td>
        <td><button type="button" class="btn-remove" onclick="removeRow(this)">🗑</button></td>
    `;
    tbody.appendChild(tr);
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        renumberRows();
        calculateTotals();
    }
}

function renumberRows() {
    document.querySelectorAll('.item-row').forEach((row, idx) => {
        row.querySelector('.col-no').textContent = idx + 1;
    });
    rowCount = document.querySelectorAll('.item-row').length;
}

function calculateRow(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const total = qty * price;
    row.querySelector('.item-total').value = formatNumber(total);
    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const price = parseFloat(row.querySelector('.item-price').value) || 0;
        subtotal += qty * price;
    });
    
    const discountType = document.getElementById('discountType').value;
    const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;
    const discountAmount = discountType === 'percentage' ? (subtotal * discountValue / 100) : discountValue;
    const afterDiscount = subtotal - discountAmount;
    
    const ppnPct = parseFloat(document.getElementById('ppnPercentage').value) || 0;
    const ppnAmount = afterDiscount * ppnPct / 100;
    const total = afterDiscount + ppnAmount;
    
    document.getElementById('displaySubtotal').textContent = 'Rp ' + formatNumber(subtotal);
    document.getElementById('displayDiscount').textContent = '- Rp ' + formatNumber(discountAmount);
    document.getElementById('displayPPN').textContent = 'Rp ' + formatNumber(ppnAmount);
    document.getElementById('displayTotal').textContent = 'Rp ' + formatNumber(total);
    document.getElementById('subtotalInput').value = subtotal;
}

function formatNumber(num) {
    return Math.round(num).toLocaleString('id-ID');
}

function fillCustomerData() {
    const select = document.getElementById('customerSelect');
    const option = select.options[select.selectedIndex];
    if (option.value) {
        document.getElementById('clientName').value = option.dataset.name || '';
        document.getElementById('clientAttn').value = option.dataset.contact || '';
        document.getElementById('clientPhone').value = option.dataset.phone || '';
        document.getElementById('clientEmail').value = option.dataset.email || '';
        document.getElementById('clientAddress').value = option.dataset.address || '';
    }
}

// Initialize totals on page load
document.addEventListener('DOMContentLoaded', function() {
    calculateTotals();
});
</script>

<?php include '../../includes/footer.php'; ?>
