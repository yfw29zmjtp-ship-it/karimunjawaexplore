<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

sunseaEnsurePackageItemsSchema($pdo);
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

    $pageTitle = $pkg['name'];
    $activeNav = 'paket';
    require __DIR__ . '/includes/website-header.php';
?>
    <section class="we-section">
        <div class="we-container" style="max-width:820px;">
            <a href="paket-wisata.php" style="color:var(--we-ocean);font-size:13px;font-weight:700;">&larr; Kembali ke Semua Paket</a>

            <div class="we-card-img" style="height:220px;border-radius:14px;margin:16px 0 22px;font-size:60px;">🏝️</div>

            <h1 style="font-size:26px;font-weight:800;color:var(--we-brand-dark);margin:0 0 8px;"><?php echo htmlspecialchars($pkg['name']); ?></h1>
            <div class="we-card-meta" style="font-size:13.5px;margin-bottom:18px;">
                <?php echo (int)$pkg['duration_days']; ?>H<?php echo (int)$pkg['duration_nights']; ?>M
                &middot; <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $pkg['category']))); ?>
                &middot; Min. <?php echo (int)$pkg['min_pax']; ?> - Maks. <?php echo (int)$pkg['max_pax']; ?> pax
            </div>

            <div class="we-card-price" style="font-size:22px;margin-bottom:22px;"><?php echo sunseaRupiah((float)$pkg['base_price']); ?> <span style="font-size:12px;color:var(--we-muted);font-weight:400;">/ pax</span></div>

            <?php if ($pkg['description']): ?>
                <p style="line-height:1.8;color:var(--we-text);"><?php echo nl2br(htmlspecialchars($pkg['description'])); ?></p>
            <?php endif; ?>

            <?php if ($pkg['includes']): ?>
                <h3 style="color:var(--we-brand-dark);font-size:15px;margin-top:24px;">Termasuk</h3>
                <p style="line-height:1.8;color:var(--we-text);white-space:pre-line;"><?php echo htmlspecialchars($pkg['includes']); ?></p>
            <?php endif; ?>

            <?php if ($pkg['excludes']): ?>
                <h3 style="color:var(--we-brand-dark);font-size:15px;margin-top:18px;">Tidak Termasuk</h3>
                <p style="line-height:1.8;color:var(--we-text);white-space:pre-line;"><?php echo htmlspecialchars($pkg['excludes']); ?></p>
            <?php endif; ?>

            <?php if ($pkg['itinerary']): ?>
                <h3 style="color:var(--we-brand-dark);font-size:15px;margin-top:18px;">Itinerary</h3>
                <p style="line-height:1.8;color:var(--we-text);white-space:pre-line;"><?php echo htmlspecialchars($pkg['itinerary']); ?></p>
            <?php endif; ?>

            <a href="kontak.php?package_id=<?php echo (int)$pkg['id']; ?>" class="we-btn we-btn-primary" style="margin-top:26px;">Booking Paket Ini</a>
        </div>
    </section>
<?php
    require __DIR__ . '/includes/website-footer.php';
    exit;
}

// ---- Daftar semua paket ----
$packages = $pdo->query(
    "SELECT id, name, category, duration_days, duration_nights, base_price
     FROM trip_packages WHERE is_active = 1 ORDER BY name"
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
                        <div class="we-card-img">🏝️</div>
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