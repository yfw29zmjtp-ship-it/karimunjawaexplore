<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Blog';
$activeNav = 'blog';
require __DIR__ . '/includes/website-header.php';

// Placeholder artikel — belum ada modul CMS blog di sistem.
$weBlogPlaceholder = [
    ['title' => '5 Spot Snorkeling Terbaik di Karimunjawa', 'desc' => 'Rekomendasi titik island hopping dengan air paling jernih.'],
    ['title' => 'Tips Menyiapkan Trip Karimunjawa untuk Pemula', 'desc' => 'Persiapan barang, waktu terbaik berkunjung, dan estimasi biaya.'],
    ['title' => 'Kuliner Wajib Coba Saat di Karimunjawa', 'desc' => 'Dari seafood segar sampai jajanan khas pulau.'],
];
?>

<section class="we-section">
    <div class="we-container">
        <div class="we-section-title">
            <h2>Blog &amp; Artikel</h2>
            <p>Tips dan cerita seputar wisata Karimunjawa</p>
        </div>

        <div class="we-grid">
            <?php foreach ($weBlogPlaceholder as $post): ?>
                <div class="we-card">
                    <div class="we-card-img">📝</div>
                    <div class="we-card-body">
                        <div class="we-card-title"><?php echo htmlspecialchars($post['title']); ?></div>
                        <div class="we-card-meta"><?php echo htmlspecialchars($post['desc']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="we-empty">Artikel lengkap akan segera hadir. Nantikan update dari kami!</div>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>