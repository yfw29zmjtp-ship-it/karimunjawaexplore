<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

sunseaEnsurePackageItemsSchema($pdo);
sunseaEnsurePackageMediaSchema($pdo);
$pkgId = (int)($_GET['id'] ?? 0);

if ($pkgId > 0) {
    // ---- Detail satu paket ----
    $stmt = $pdo->prepare("SELECT * FROM trip_packages WHERE id = ? AND is_active = 1");
    $stmt->execute([$pkgId]);
    $pkg = $stmt->fetch();

    if (!$pkg) {
        header('Location: paket-wisata.php');
        exit;
    }

    $galleryStmt = $pdo->prepare("SELECT * FROM trip_package_gallery WHERE package_id = ? ORDER BY sort_order, id");
    $galleryStmt->execute([$pkg['id']]);
    $pkgGallery = $galleryStmt->fetchAll();

    // Pecah teks multi-baris (dari textarea admin) jadi array baris bersih, buang bullet lama & baris kosong.
    $weSplitLines = function (?string $text): array {
        $lines = preg_split('/\r\n|\r|\n/', (string)$text);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $line = ltrim($line, "-•*  \t");
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return $out;
    };
    $pkgIncludes  = $weSplitLines($pkg['includes'] ?? '');
    $pkgExcludes  = $weSplitLines($pkg['excludes'] ?? '');
    $pkgItinerary = $weSplitLines($pkg['itinerary'] ?? '');

    $pageTitle = $pkg['name'];
    $activeNav = 'paket';
    require __DIR__ . '/includes/website-header.php';
?>
    <section class="we-section">
        <div class="we-container" style="max-width:1080px;">
            <a href="paket-wisata.php" class="we-pkg-back">&larr; Kembali ke Semua Paket</a>

            <?php if (!empty($pkg['cover_image'])): ?>
                <img src="<?php echo htmlspecialchars(sunseaAssetUrl($pkg['cover_image'])); ?>" alt="<?php echo htmlspecialchars($pkg['name']); ?>" class="we-pkg-hero-img">
            <?php else: ?>
                <div class="we-card-img we-pkg-hero-img" style="font-size:60px;">🏝️</div>
            <?php endif; ?>

            <div class="we-pkg-header">
                <div>
                    <h1 class="we-pkg-title"><?php echo htmlspecialchars($pkg['name']); ?></h1>
                    <div class="we-pkg-badges">
                        <span class="we-pkg-badge">📅 <?php echo (int)$pkg['duration_days']; ?>H<?php echo (int)$pkg['duration_nights']; ?>M</span>
                        <span class="we-pkg-badge">🏷️ <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pkg['category']))); ?></span>
                        <span class="we-pkg-badge">👥 Min. <?php echo (int)$pkg['min_pax']; ?> – Maks. <?php echo (int)$pkg['max_pax']; ?> pax</span>
                    </div>
                </div>
            </div>

            <div class="we-pkg-grid">
                <div>
                    <?php if ($pkg['description']): ?>
                        <div class="we-pkg-section">
                            <h3 class="we-pkg-section-title">📝 Deskripsi Paket</h3>
                            <p class="we-pkg-desc"><?php echo nl2br(htmlspecialchars(trim($pkg['description']))); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($pkgIncludes): ?>
                        <div class="we-pkg-section">
                            <h3 class="we-pkg-section-title">✅ Harga Sudah Termasuk</h3>
                            <ul class="we-pkg-list">
                                <?php foreach ($pkgIncludes as $line): ?>
                                    <li><?php echo htmlspecialchars($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($pkgExcludes): ?>
                        <div class="we-pkg-section">
                            <h3 class="we-pkg-section-title">🚫 Tidak Termasuk</h3>
                            <ul class="we-pkg-list we-pkg-list-x">
                                <?php foreach ($pkgExcludes as $line): ?>
                                    <li><?php echo htmlspecialchars($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($pkgItinerary): ?>
                        <div class="we-pkg-section">
                            <h3 class="we-pkg-section-title">🗺️ Itinerary</h3>
                            <ul class="we-pkg-itinerary">
                                <?php foreach ($pkgItinerary as $line): ?>
                                    <li><?php echo htmlspecialchars($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($pkgGallery): ?>
                        <div class="we-pkg-section">
                            <h3 class="we-pkg-section-title">📸 Galeri Trip</h3>
                            <div class="we-gallery-grid">
                                <?php foreach ($pkgGallery as $gp): ?>
                                    <div class="we-gallery-item" style="background:none;">
                                        <img src="<?php echo htmlspecialchars(sunseaAssetUrl($gp['image_path'])); ?>" alt="<?php echo htmlspecialchars($gp['caption'] ?? ''); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:10px;">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="we-pkg-sidebar">
                    <div class="we-pkg-price-label">Harga mulai dari</div>
                    <div class="we-pkg-price"><?php echo sunseaRupiah((float)$pkg['base_price']); ?> <span>/ pax</span></div>

                    <div class="we-pkg-sidebar-meta">
                        <div class="we-pkg-sidebar-meta-row"><span>Durasi</span><span><?php echo (int)$pkg['duration_days']; ?> Hari <?php echo (int)$pkg['duration_nights']; ?> Malam</span></div>
                        <div class="we-pkg-sidebar-meta-row"><span>Tipe Trip</span><span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pkg['category']))); ?></span></div>
                        <div class="we-pkg-sidebar-meta-row"><span>Kapasitas</span><span><?php echo (int)$pkg['min_pax']; ?>–<?php echo (int)$pkg['max_pax']; ?> pax</span></div>
                    </div>

                    <a href="kontak.php?package_id=<?php echo (int)$pkg['id']; ?>" class="we-btn we-btn-primary">Booking Paket Ini</a>
                </div>
            </div>
        </div>
    </section>
<?php
    require __DIR__ . '/includes/website-footer.php';
    exit;
}

// ---- Daftar semua paket ----
$packages = $pdo->query(
    "SELECT id, name, category, duration_days, duration_nights, base_price, cover_image
     FROM trip_packages WHERE is_active = 1 ORDER BY display_order, name"
)->fetchAll();

$pageTitle = 'Paket Wisata & Harga';
$activeNav = 'paket';
require __DIR__ . '/includes/website-header.php';
?>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Paket Wisata &amp; Harga</h2>
            <p>Pilih paket sesuai kebutuhan trip Anda ke Karimunjawa</p>
        </div>

        <?php if ($packages): ?>
            <div class="we-grid">
                <?php foreach ($packages as $pkg): ?>
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
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="we-empty">Belum ada paket wisata aktif saat ini. Hubungi kami untuk custom trip sesuai kebutuhan Anda.</div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>