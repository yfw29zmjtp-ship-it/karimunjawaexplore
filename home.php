<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

sunseaEnsurePackageItemsSchema($pdo);
sunseaEnsurePackageMediaSchema($pdo);
$featuredPackages = $pdo->query(
    "SELECT id, code, name, category, duration_days, duration_nights, base_price, cover_image
     FROM trip_packages WHERE is_active = 1 ORDER BY display_order, id DESC"
)->fetchAll();
$weAllPackages = $pdo->query(
    "SELECT id, name, duration_days, duration_nights
     FROM trip_packages WHERE is_active = 1 ORDER BY name ASC"
)->fetchAll();
$weHomeGallery = $pdo->query(
    "SELECT * FROM website_gallery WHERE is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 10"
)->fetchAll();

$weHeroTitle = sunseaSetting($pdo, 'website_hero_title', 'Jelajahi Keindahan Karimunjawa Bersama Kami');
$weHeroSubtitle = sunseaSetting($pdo, 'website_hero_subtitle', 'Paket wisata open trip & private trip, island hopping, penginapan, hingga transport laut/darat — kami urus, Anda tinggal menikmati liburan.');
$weHeroBg = sunseaSetting($pdo, 'website_hero_bg', '');

$weAboutP1 = sunseaSetting($pdo, 'website_about_p1', '') ?: ($weCompanyName . ' adalah penyedia jasa tour & travel yang berfokus pada wisata Kepulauan Karimunjawa, Jepara. Kami melayani open trip maupun private trip, lengkap dengan penginapan, transport laut/darat, island hopping, guide lokal berpengalaman, hingga dokumentasi perjalanan.');
$weAboutExcerpt = trim(preg_replace('/\s+/', ' ', $weAboutP1));
if (mb_strlen($weAboutExcerpt) > 380) {
    $weAboutExcerpt = mb_substr($weAboutExcerpt, 0, 380) . '…';
}
$weAboutImage = $pdo->query("SELECT image_path FROM website_gallery WHERE is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 1")->fetchColumn();
$weHeroStyle = $weHeroBg
    ? 'background-image:linear-gradient(135deg,rgba(6,54,84,.72),rgba(6,54,84,.72)),url(\'' . htmlspecialchars(sunseaAssetUrl($weHeroBg)) . '\');background-size:cover;background-position:center;'
    : '';

// ── Form "Minta Penawaran" cepat (gaya search bar Traveloka) ─────────────────
// Disimpan sebagai quotation baru (created_by='website') supaya langsung
// muncul di menu Penawaran admin dengan notifikasi dot merah.
$weQuoteSuccessMsg = '';
$weQuoteErrorMsg = '';
$weQuoteOld = ['name' => '', 'phone' => '', 'trip_date' => '', 'pax' => 2, 'package_id' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['we_action'] ?? '') === 'quick_quote') {
    $qName = trim($_POST['q_name'] ?? '');
    $qPhone = trim($_POST['q_phone'] ?? '');
    $qDate = trim($_POST['q_trip_date'] ?? '');
    $qPax = max(1, (int)($_POST['q_pax'] ?? 1));
    $qPackageId = (int)($_POST['q_package_id'] ?? 0);
    $weQuoteOld = ['name' => $qName, 'phone' => $qPhone, 'trip_date' => $qDate, 'pax' => $qPax, 'package_id' => $qPackageId];

    if ($qName === '' || $qPhone === '' || $qDate === '' || $qPackageId <= 0) {
        $weQuoteErrorMsg = 'Nama, No. WhatsApp, Tanggal Trip, dan Pilihan Paket wajib diisi.';
    } else {
        try {
            $custStmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ? OR whatsapp = ? LIMIT 1");
            $custStmt->execute([$qPhone, $qPhone]);
            $qCustomerId = (int)$custStmt->fetchColumn();

            if ($qCustomerId <= 0) {
                $lastCode = $pdo->query("SELECT code FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextNum = 1;
                if ($lastCode && preg_match('/(\d+)$/', $lastCode, $mCode)) {
                    $nextNum = (int)$mCode[1] + 1;
                }
                $newCode = 'SS-CUST-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO customers (code, name, type, email, phone, whatsapp, country) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$newCode, $qName, 'individual', '', $qPhone, $qPhone, 'Indonesia']);
                $qCustomerId = (int)$pdo->lastInsertId();
            }

            $qPackageName = $pdo->prepare("SELECT name FROM trip_packages WHERE id = ?");
            $qPackageName->execute([$qPackageId]);
            $qPackageName = $qPackageName->fetchColumn() ?: '-';

            $qNo = sunseaNextNumber($pdo, 'quotation');
            $qNotes = "[Website] Permintaan penawaran cepat.\nPaket: " . $qPackageName;
            $pdo->prepare("INSERT INTO quotations (quotation_no, customer_id, package_id, trip_date, pax_count, notes, valid_until, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$qNo, $qCustomerId, $qPackageId, $qDate, $qPax, $qNotes, date('Y-m-d', strtotime('+7 days')), 'website']);

            $weQuoteSuccessMsg = "Terima kasih, {$qName}! Permintaan penawaran Anda (No. {$qNo}) sudah kami terima. Tim kami akan segera menghubungi Anda via WhatsApp.";
            $weQuoteOld = ['name' => '', 'phone' => '', 'trip_date' => '', 'pax' => 2, 'package_id' => ''];
        } catch (Throwable $e) {
            error_log('home.php quick_quote error: ' . $e->getMessage());
            $weQuoteErrorMsg = 'Maaf, terjadi kendala saat mengirim permintaan. Silakan coba lagi atau hubungi kami langsung.';
        }
    }
}

$pageTitle = 'Beranda';
$activeNav = 'home';
require __DIR__ . '/includes/website-header.php';
?>

<section class="we-hero" style="<?php echo $weHeroStyle; ?>">
    <div class="we-container">
        <h1><?php echo htmlspecialchars($weHeroTitle); ?></h1>
        <p><?php echo nl2br(htmlspecialchars($weHeroSubtitle)); ?></p>
        <div class="we-hero-actions">
            <a href="paket-wisata.php" class="we-btn we-btn-primary">Lihat Paket Wisata</a>
            <a href="kontak.php" class="we-btn we-btn-outline">Booking Sekarang</a>
        </div>
    </div>
</section>

<div class="we-quotebar-wrap">
    <div class="we-container">
        <?php if ($weQuoteSuccessMsg): ?>
            <div class="we-quote-alert success"><?php echo htmlspecialchars($weQuoteSuccessMsg); ?></div>
        <?php elseif ($weQuoteErrorMsg): ?>
            <div class="we-quote-alert error"><?php echo htmlspecialchars($weQuoteErrorMsg); ?></div>
        <?php endif; ?>

        <form method="POST" action="home.php#weQuoteForm" class="we-quotebar" id="weQuoteForm">
            <input type="hidden" name="we_action" value="quick_quote">
            <div class="we-quotebar-field we-quotebar-field-date">
                <label>Tanggal Trip</label>
                <input type="date" name="q_trip_date" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($weQuoteOld['trip_date']); ?>">
            </div>
            <div class="we-quotebar-field">
                <label>Total Pax</label>
                <input type="number" name="q_pax" min="1" required value="<?php echo (int)$weQuoteOld['pax']; ?>">
            </div>
            <div class="we-quotebar-field">
                <label>Pilih Paket</label>
                <select name="q_package_id" required>
                    <option value="">Pilih Paket</option>
                    <?php foreach ($weAllPackages as $pkg): ?>
                        <?php $pkgLabel = $pkg['duration_days'] . 'H' . $pkg['duration_nights'] . 'M - ' . $pkg['name']; ?>
                        <option value="<?php echo (int)$pkg['id']; ?>" <?php echo (string)$weQuoteOld['package_id'] === (string)$pkg['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pkgLabel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="we-quotebar-field we-quotebar-submit">
                <button type="button" class="we-btn we-btn-primary we-quotebar-btn" onclick="weOpenQuoteModal()">Minta Penawaran</button>
            </div>

            <div class="we-quote-modal-overlay" id="weQuoteModalOverlay">
                <div class="we-quote-modal">
                    <button type="button" class="we-quote-modal-close" onclick="weCloseQuoteModal()">&times;</button>
                    <h3>Lengkapi Kontak Anda</h3>
                    <p>Tim kami akan menghubungi Anda via WhatsApp dengan penawaran terbaik.</p>
                    <div class="we-form-row">
                        <label>Nama Lengkap *</label>
                        <input type="text" name="q_name" required value="<?php echo htmlspecialchars($weQuoteOld['name']); ?>">
                    </div>
                    <div class="we-form-row">
                        <label>No. WhatsApp *</label>
                        <input type="text" name="q_phone" required placeholder="08xxxxxxxxxx" value="<?php echo htmlspecialchars($weQuoteOld['phone']); ?>">
                    </div>
                    <button type="submit" class="we-btn we-btn-primary" style="width:100%;">Kirim Permintaan Penawaran</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    function weOpenQuoteModal() {
        // Only validate the fields visible in the bar itself — q_name/q_phone live
        // inside the (still-hidden) modal, and browsers silently fail reportValidity()
        // on required fields that aren't rendered yet, making the button look dead.
        var form = document.getElementById('weQuoteForm');
        var barFields = form.querySelectorAll('.we-quotebar-field:not(.we-quotebar-submit) input, .we-quotebar-field:not(.we-quotebar-submit) select');
        for (var i = 0; i < barFields.length; i++) {
            if (!barFields[i].reportValidity()) return;
        }
        document.getElementById('weQuoteModalOverlay').classList.add('open');
    }

    function weCloseQuoteModal() {
        document.getElementById('weQuoteModalOverlay').classList.remove('open');
    }
    <?php if ($weQuoteErrorMsg): ?>
        document.addEventListener('DOMContentLoaded', weOpenQuoteModal);
    <?php endif; ?>
</script>

<section class="we-section we-section-alt we-about-section">
    <div class="we-container">
        <div class="we-about-block">
            <div class="we-about-media">
                <?php if ($weAboutImage): ?>
                    <img src="<?php echo htmlspecialchars(sunseaAssetUrl($weAboutImage)); ?>" alt="Tentang <?php echo htmlspecialchars($weCompanyName); ?>">
                <?php elseif ($weLogoSrc): ?>
                    <img src="<?php echo htmlspecialchars($weLogoSrc); ?>" alt="Tentang <?php echo htmlspecialchars($weCompanyName); ?>" style="object-fit:contain;background:#fff;padding:16px;">
                <?php else: ?>
                    <div class="we-about-media-fallback">🏝️</div>
                <?php endif; ?>
            </div>
            <div class="we-about-text">
                <h2>Tentang <?php echo htmlspecialchars($weCompanyName); ?></h2>
                <p><?php echo htmlspecialchars($weAboutExcerpt); ?></p>
                <a href="tentang-kami.php" class="we-btn we-btn-primary">Selengkapnya</a>
            </div>
        </div>
    </div>
</section>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Paket Wisata Pilihan</h2>
            <p>Beberapa paket favorit tamu kami di Karimunjawa</p>
        </div>

        <?php if ($featuredPackages): ?>
            <div class="we-carousel" id="wePkgCarousel" data-autoplay="4500">
                <button type="button" class="we-carousel-arrow we-prev" aria-label="Sebelumnya">&#8249;</button>
                <div class="we-carousel-viewport">
                    <div class="we-carousel-track">
                        <?php foreach ($featuredPackages as $pkg): ?>
                            <div class="we-carousel-slide">
                                <div class="we-card">
                                    <?php if (!empty($pkg['cover_image'])): ?>
                                        <img src="<?php echo htmlspecialchars(sunseaAssetUrl($pkg['cover_image'])); ?>" alt="<?php echo htmlspecialchars($pkg['name']); ?>" class="we-card-img" style="width:100%;object-fit:cover;">
                                    <?php else: ?>
                                        <div class="we-card-img">🏝️</div>
                                    <?php endif; ?>
                                    <div class="we-card-body">
                                        <div class="we-card-title"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                        <div class="we-card-meta">
                                            <?php echo (int)$pkg['duration_days']; ?>H<?php echo (int)$pkg['duration_nights']; ?>M
                                            &middot; <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pkg['category']))); ?>
                                        </div>
                                        <div class="we-card-price"><?php echo sunseaRupiah((float)$pkg['base_price']); ?> <span style="font-size:11px;color:var(--we-muted);font-weight:400;">/ pax</span></div>
                                    </div>
                                    <div class="we-card-footer">
                                        <a href="paket-wisata.php?id=<?php echo (int)$pkg['id']; ?>" class="we-card-btn">Lihat Detail</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="we-carousel-arrow we-next" aria-label="Berikutnya">&#8250;</button>
                <div class="we-carousel-dots"></div>
            </div>
        <?php else: ?>
            <div class="we-empty">Paket wisata akan segera hadir. Hubungi kami untuk custom trip.</div>
        <?php endif; ?>
    </div>
</section>

<?php if ($weHomeGallery): ?>
<section class="we-section we-gallery-elegant">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Galeri Tamu Kami</h2>
            <p>Galeri tamu yang sudah dilayani Karimunjawa Explore — momen bahagia mereka menjelajah Karimunjawa bersama kami</p>
        </div>

        <div class="we-carousel we-carousel-gallery" data-autoplay="1000">
            <button type="button" class="we-carousel-arrow we-prev" aria-label="Sebelumnya">&#8249;</button>
            <div class="we-carousel-viewport">
                <div class="we-carousel-track">
                    <?php foreach ($weHomeGallery as $photo): ?>
                        <div class="we-carousel-slide we-gallery-slide">
                            <div class="we-gallery-slide-inner">
                                <img src="<?php echo htmlspecialchars(sunseaAssetUrl($photo['image_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?: 'Tamu Karimunjawa Explore'); ?>">
                                <?php if (!empty($photo['caption'])): ?>
                                    <div class="we-gallery-caption"><?php echo htmlspecialchars($photo['caption']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="we-carousel-arrow we-next" aria-label="Berikutnya">&#8250;</button>
            <div class="we-carousel-dots"></div>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="we-section we-section-alt">
    <div class="we-container" style="max-width:860px;">
        <div class="we-section-title">
            <h2>Kenapa Pilih Kami?</h2>
        </div>
        <div class="we-grid">
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">🛥️</div>
                    <div class="we-card-title">Armada &amp; Guide Berpengalaman</div>
                    <div class="we-card-meta">Kapal dan pemandu lokal yang terpercaya di seluruh perairan Karimunjawa.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">💰</div>
                    <div class="we-card-title">Harga Transparan</div>
                    <div class="we-card-meta">Rincian biaya jelas — tanpa biaya tersembunyi, dengan opsi DP/cicilan.</div>
                </div>
            </div>
            <div class="we-card">
                <div class="we-card-body" style="text-align:center;">
                    <div style="font-size:34px;margin-bottom:10px;">📞</div>
                    <div class="we-card-title">Layanan 24/7</div>
                    <div class="we-card-meta">Tim kami siap membantu perencanaan trip Anda kapan saja.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>