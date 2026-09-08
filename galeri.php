<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Galeri';
$activeNav = 'galeri';
require __DIR__ . '/includes/website-header.php';

$weGalleryIcons = ['🏝️', '🌅', '🛥️', '🐠', '⛺', '🏖️', '🌊', '📸'];
$weGalleryPhotos = $pdo->query("SELECT * FROM website_gallery WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll();
?>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Galeri Karimunjawa</h2>
            <p>Momen-momen indah perjalanan tamu kami</p>
        </div>

        <?php if ($weGalleryPhotos): ?>
            <div class="we-gallery-grid">
                <?php foreach ($weGalleryPhotos as $photo): ?>
                    <div class="we-gallery-item" style="padding:0;overflow:hidden;">
                        <img src="<?php echo htmlspecialchars(sunseaAssetUrl($photo['image_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?: 'Galeri Karimunjawa'); ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="we-gallery-grid">
                <?php foreach ($weGalleryIcons as $icon): ?>
                    <div class="we-gallery-item"><?php echo $icon; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="we-empty">Foto akan ditambahkan menyusul.</div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>