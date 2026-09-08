<?php
require_once __DIR__ . '/includes/website-bootstrap.php';

$pageTitle = 'Blog';
$activeNav = 'blog';
require __DIR__ . '/includes/website-header.php';

$weBlogPosts = $pdo->query("SELECT * FROM website_blog WHERE is_published = 1 ORDER BY created_at DESC")->fetchAll();
$weSelectedSlug = $_GET['slug'] ?? '';
$weSelectedPost = null;
if ($weSelectedSlug !== '') {
    foreach ($weBlogPosts as $p) {
        if ($p['slug'] === $weSelectedSlug) {
            $weSelectedPost = $p;
            break;
        }
    }
}
?>

<section class="we-section">
    <div class="we-container">
        <?php if ($weSelectedPost): ?>
            <a href="blog.php" style="font-size:13px;color:var(--we-ocean);text-decoration:none;">&larr; Kembali ke Blog</a>
            <h2 style="margin-top:14px;"><?php echo htmlspecialchars($weSelectedPost['title']); ?></h2>
            <?php if ($weSelectedPost['cover_image']): ?>
                <img src="<?php echo htmlspecialchars(sunseaAssetUrl($weSelectedPost['cover_image'])); ?>" alt="" style="width:100%;max-height:360px;object-fit:cover;border-radius:10px;margin:16px 0;">
            <?php endif; ?>
            <div style="line-height:1.9;color:var(--we-text);"><?php echo nl2br(htmlspecialchars($weSelectedPost['content'])); ?></div>
        <?php else: ?>
            <div class="we-section-title">
                <h2>Blog &amp; Artikel</h2>
                <p>Tips dan cerita seputar wisata Karimunjawa</p>
            </div>

            <?php if ($weBlogPosts): ?>
                <div class="we-grid">
                    <?php foreach ($weBlogPosts as $post): ?>
                        <a href="blog.php?slug=<?php echo urlencode($post['slug']); ?>" class="we-card" style="text-decoration:none;color:inherit;">
                            <?php if ($post['cover_image']): ?>
                                <img src="<?php echo htmlspecialchars(sunseaAssetUrl($post['cover_image'])); ?>" alt="" style="width:100%;height:150px;object-fit:cover;display:block;">
                            <?php else: ?>
                                <div class="we-card-img">📝</div>
                            <?php endif; ?>
                            <div class="we-card-body">
                                <div class="we-card-title"><?php echo htmlspecialchars($post['title']); ?></div>
                                <div class="we-card-meta"><?php echo htmlspecialchars($post['excerpt']); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="we-empty">Artikel lengkap akan segera hadir. Nantikan update dari kami!</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/includes/website-footer.php'; ?>