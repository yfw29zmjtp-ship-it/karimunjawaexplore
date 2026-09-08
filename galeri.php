<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Galeri';
$activeNav = 'galeri';
require __DIR__ . '/includes/website-header.php';

$weGalleryIcons = ['🏝️', '🌅', '🛥️', '🐠', '⛺', '🏖️', '🌊', '📸'];
?>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Galeri Karimunjawa</h2>
            <p>Momen-momen indah perjalanan tamu kami (foto akan ditambahkan menyusul)</p>
        </div>

        <div class="we-gallery-grid">
            <?php foreach ($weGalleryIcons as $icon): ?>
                <div class="we-gallery-item"><?php echo $icon; ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>