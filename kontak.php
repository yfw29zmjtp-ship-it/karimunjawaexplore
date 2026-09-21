<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

sunseaEnsureBookingSchema($pdo);
sunseaEnsurePackageItemsSchema($pdo);

$packages = $pdo->query(
    "SELECT id, name, duration_days, duration_nights, base_price, min_pax, max_pax FROM trip_packages WHERE is_active = 1 ORDER BY name"
)->fetchAll();

$selectedPackageId = (int)($_GET['package_id'] ?? 0);
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $packageId  = (int)($_POST['package_id'] ?? 0);
    $startDate  = $_POST['start_date'] ?? '';
    $pax        = max(1, (int)($_POST['pax'] ?? 1));
    $message    = trim($_POST['message'] ?? '');

    if ($name === '' || $phone === '' || $startDate === '') {
        $errorMsg = 'Nama, No. WhatsApp, dan Tanggal Mulai wajib diisi.';
    } else {
        try {
            $pkg = null;
            if ($packageId > 0) {
                $pkgStmt = $pdo->prepare("SELECT * FROM trip_packages WHERE id = ? AND is_active = 1");
                $pkgStmt->execute([$packageId]);
                $pkg = $pkgStmt->fetch();
            }

            // Paket grup tetap (mis. "Pax untuk 4 Orang") sudah dihargai untuk seluruh grup -
            // paksa pax ke jumlah tetap itu di server, jangan percaya input tamu (bisa dimanipulasi
            // walau di form sudah dikunci via JS), supaya nominal tidak dikali salah (mis. 4x).
            $fixedPax = $pkg ? sunseaPackageFixedPax($pkg) : 0;
            if ($fixedPax > 0) {
                $pax = $fixedPax;
            }

            $durationDays = $pkg ? max(1, (int)$pkg['duration_days']) : 3;
            $endDate = date('Y-m-d', strtotime($startDate . ' + ' . ($durationDays - 1) . ' days'));

            $pdo->beginTransaction();

            // Cari customer berdasarkan HP + nama sekaligus. Kalau nomor sama dipakai nama lain,
            // buat customer baru — jangan timpa nama lama (itu akan ikut mengubah nama di semua
            // penawaran lama milik customer tersebut).
            $custStmt = $pdo->prepare("SELECT id FROM customers WHERE (phone = ? OR whatsapp = ?) AND name = ? LIMIT 1");
            $custStmt->execute([$phone, $phone, $name]);
            $customerId = (int)($custStmt->fetchColumn() ?: 0);

            if ($customerId <= 0) {
                $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextNum = 1;
                if ($lastCode && preg_match('/(\d+)$/', $lastCode, $mCode)) $nextNum = (int)$mCode[1] + 1;
                $newCode = 'SS-CUST-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO customers (code, name, type, email, phone, whatsapp, country) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$newCode, $name, 'individual', $email, $phone, $phone, 'Indonesia']);
                $customerId = (int)$pdo->lastInsertId();
            }

            $quotationNo = sunseaNextNumber($pdo, 'quotation');
            $quoteNotes = "[Website] Permintaan booking dari form kontak.\n" . ($message !== '' ? "Pesan: {$message}" : '');
            $subtotal = $pkg ? (float)$pkg['base_price'] * $pax : 0;

            $pdo->prepare("INSERT INTO quotations
                (quotation_no, customer_id, package_id, trip_date, trip_end_date, pax_count, status, subtotal, total_amount, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $quotationNo,
                    $customerId,
                    $pkg ? $packageId : null,
                    $startDate,
                    $endDate,
                    $pax,
                    'draft',
                    $subtotal,
                    $subtotal,
                    $quoteNotes,
                    'website',
                ]);
            $quotationId = (int)$pdo->lastInsertId();

            if ($pkg) {
                $pdo->prepare("INSERT INTO quotation_items
                    (quotation_id, item_type, description, qty, unit, unit_price, subtotal)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([
                        $quotationId,
                        'other',
                        $pkg['name'],
                        $pax,
                        'pax',
                        (float)$pkg['base_price'],
                        $subtotal,
                    ]);
            }

            $pdo->commit();

            sunseaNotifyAdminNewQuotation($pdo, $quotationId);

            $successMsg = "Terima kasih! Permintaan booking Anda telah kami terima dengan No. Penawaran {$quotationNo}. Tim kami akan segera menghubungi Anda.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('kontak.php booking insert error: ' . $e->getMessage());
            $errorMsg = 'Maaf, terjadi kendala saat mengirim permintaan booking. Silakan coba lagi atau hubungi kami langsung via WhatsApp.';
        }
    }
}

$pageTitle = 'Kontak & Booking';
$activeNav = 'kontak';
require __DIR__ . '/includes/website-header.php';
?>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Kontak &amp; Booking</h2>
            <p>Isi form di bawah untuk mengajukan permintaan booking, tim kami akan segera menghubungi Anda</p>
        </div>

        <div class="we-contact-info">
            <div>
                <div class="we-ci-icon">&#9742;</div>
                <b>Telepon / WhatsApp</b>
                <?php if ($weCompanyPhones): ?>
                    <?php foreach ($weCompanyPhones as $weKontakPhone): ?>
                        <span><?php echo htmlspecialchars($weKontakPhone); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span>-</span>
                <?php endif; ?>
            </div>
            <div>
                <div class="we-ci-icon">&#9993;</div>
                <b>Email</b>
                <span><?php echo htmlspecialchars($weCompanyEmail ?: '-'); ?></span>
            </div>
            <div>
                <div class="we-ci-icon">&#128205;</div>
                <b>Lokasi</b>
                <span><?php echo htmlspecialchars($weCompanyAddr); ?></span>
            </div>
        </div>

        <div class="we-form-box">
            <?php if ($successMsg): ?><div class="we-alert we-alert-success"><?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
            <?php if ($errorMsg): ?><div class="we-alert we-alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

            <form method="POST">
                <div class="we-form-row">
                    <label>Nama Lengkap *</label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>
                <div class="we-form-cols">
                    <div class="we-form-row">
                        <label>No. WhatsApp *</label>
                        <input type="text" name="phone" required placeholder="08xxxxxxxxxx" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                    <div class="we-form-row">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>
                <div class="we-form-row">
                    <label>Pilih Paket (opsional)</label>
                    <select name="package_id" id="weKontakPackage">
                        <option value="0">-- Custom Trip / Belum Menentukan --</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?php echo (int)$pkg['id']; ?>" data-min-pax="<?php echo (int)$pkg['min_pax']; ?>" data-max-pax="<?php echo (int)$pkg['max_pax']; ?>" <?php echo ($selectedPackageId === (int)$pkg['id'] || (int)($_POST['package_id'] ?? 0) === (int)$pkg['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo (int)$pkg['duration_days']; ?>H<?php echo (int)$pkg['duration_nights']; ?>M)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="we-form-cols">
                    <div class="we-form-row">
                        <label>Tanggal Mulai Trip *</label>
                        <input type="date" name="start_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                    </div>
                    <div class="we-form-row">
                        <label>Jumlah Peserta *</label>
                        <input type="number" name="pax" id="weKontakPax" min="1" required value="<?php echo htmlspecialchars($_POST['pax'] ?? '1'); ?>">
                        <small id="weKontakPaxHint" style="display:none;color:#c2410c;font-weight:600;"></small>
                    </div>
                </div>
                <div class="we-form-row">
                    <label>Pesan / Catatan Tambahan</label>
                    <textarea name="message" rows="3" placeholder="Contoh: ingin trip 3 hari 2 malam, request kapal privat, dll."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="we-btn we-btn-primary" style="width:100%;border:none;cursor:pointer;">Kirim Permintaan Booking</button>
            </form>
        </div>
    </div>
</section>

<script>
    // Sama seperti form quick-quote di beranda: paket dengan grup tetap (mis. "Pax untuk 4 Orang")
    // sudah dihargai per-paket, bukan per-orang - kunci Jumlah Peserta agar tidak salah dikali.
    function weKontakApplyFixedPax() {
        var select = document.getElementById('weKontakPackage');
        var paxInput = document.getElementById('weKontakPax');
        var hint = document.getElementById('weKontakPaxHint');
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value || opt.value === '0') {
            paxInput.readOnly = false;
            hint.style.display = 'none';
            return;
        }
        var minPax = parseInt(opt.getAttribute('data-min-pax') || '0', 10);
        var maxPax = parseInt(opt.getAttribute('data-max-pax') || '0', 10);
        var fixedMatch = opt.textContent.match(/Pax\s+untuk\s+(\d+)\s+Orang/i);
        var fixedPax = fixedMatch ? parseInt(fixedMatch[1], 10) : (minPax > 0 && minPax === maxPax ? minPax : 0);

        if (fixedPax > 0) {
            paxInput.value = fixedPax;
            paxInput.readOnly = true;
            hint.textContent = 'Paket ini untuk grup tetap ' + fixedPax + ' orang, jumlah peserta otomatis mengikuti paket.';
            hint.style.display = 'block';
        } else {
            paxInput.readOnly = false;
            hint.style.display = 'none';
        }
    }
    document.getElementById('weKontakPackage').addEventListener('change', weKontakApplyFixedPax);
    document.addEventListener('DOMContentLoaded', weKontakApplyFixedPax);
</script>

<?php require __DIR__ . '/includes/website-footer.php'; ?>