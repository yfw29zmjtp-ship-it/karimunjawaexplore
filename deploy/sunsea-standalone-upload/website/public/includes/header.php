<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
    // Load ALL SEO + branding settings from database in one query
    $seoKeys = [
        'web_meta_title',
        'web_meta_description',
        'web_meta_keywords',
        'web_og_image',
        'web_og_type',
        'web_og_locale',
        'web_ga_id',
        'web_gtm_id',
        'web_google_verification',
        'web_bing_verification',
        'web_schema_star_rating',
        'web_schema_price_range',
        'web_schema_latitude',
        'web_schema_longitude',
        'web_schema_checkin',
        'web_schema_checkout',
        'web_robots_index',
        'web_robots_follow',
        'web_canonical_url',
        'web_favicon',
        'web_logo',
        'web_site_name',
    ];
    $seo = [];
    $faviconPath = '';
    $logoPath = '';
    try {
        $placeholders = implode(',', array_fill(0, count($seoKeys), '?'));
        $seoRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)", $seoKeys);
        foreach ($seoRows as $sr) {
            $seo[$sr['setting_key']] = $sr['setting_value'];
        }
        $faviconPath = $seo['web_favicon'] ?? '';
        $logoPath = $seo['web_logo'] ?? '';
    } catch (Exception $e) {
    }

    // Helper: build URL — if path is already absolute (http/https), use as-is
    // If just a filename (no slashes), prepend known upload directory
    function assetUrl($path, $uploadDir = '')
    {
        if (preg_match('#^https?://#i', $path)) return $path;
        // If it's just a filename without directory, prepend upload dir
        if ($uploadDir && strpos($path, '/') === false) {
            return BASE_URL . '/' . rtrim($uploadDir, '/') . '/' . $path;
        }
        return BASE_URL . '/' . $path;
    }

    $metaTitle = $seo['web_meta_title'] ?? SITE_NAME . ' — ' . SITE_TAGLINE;
    $metaDesc = $seo['web_meta_description'] ?? SITE_DESCRIPTION;
    $metaKeywords = $seo['web_meta_keywords'] ?? 'karimunjawa hotel, island resort, luxury accommodation';
    $canonicalUrl = rtrim($seo['web_canonical_url'] ?? 'https://narayanakarimunjawa.com', '/');
    $ogImage = $seo['web_og_image'] ?? '';
    $ogType = $seo['web_og_type'] ?? 'website';
    $ogLocale = $seo['web_og_locale'] ?? 'id_ID';
    $robotsIndex = ($seo['web_robots_index'] ?? '1') === '1' ? 'index' : 'noindex';
    $robotsFollow = ($seo['web_robots_follow'] ?? '1') === '1' ? 'follow' : 'nofollow';
    $siteName = $seo['web_site_name'] ?? SITE_NAME;
    $pageFullTitle = isset($pageTitle) ? $pageTitle . ' — ' . $siteName : $metaTitle;
    ?>

    <title><?= htmlspecialchars($pageFullTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($metaKeywords) ?>">
    <meta name="robots" content="<?= $robotsIndex ?>, <?= $robotsFollow ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?><?= isset($canonicalPath) ? $canonicalPath : '' ?>">

    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="<?= htmlspecialchars($ogType) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageFullTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($ogLocale) ?>">
    <?php if (!empty($ogImage)): ?>
        <meta property="og:image" content="<?= htmlspecialchars(preg_match('#^https?://#i', $ogImage) ? $ogImage : $canonicalUrl . '/' . $ogImage) ?>">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageFullTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDesc) ?>">
    <?php if (!empty($ogImage)): ?>
        <meta name="twitter:image" content="<?= htmlspecialchars(preg_match('#^https?://#i', $ogImage) ? $ogImage : $canonicalUrl . '/' . $ogImage) ?>">
    <?php endif; ?>

    <!-- Search Engine Verification -->
    <?php if (!empty($seo['web_google_verification'])): ?>
        <meta name="google-site-verification" content="<?= htmlspecialchars($seo['web_google_verification']) ?>">
    <?php endif; ?>
    <?php if (!empty($seo['web_bing_verification'])): ?>
        <meta name="msvalidate.01" content="<?= htmlspecialchars($seo['web_bing_verification']) ?>">
    <?php endif; ?>

    <!-- Structured Data / JSON-LD -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "Hotel",
            "name": "<?= htmlspecialchars($siteName) ?>",
            "description": "<?= htmlspecialchars($metaDesc) ?>",
            "url": "<?= htmlspecialchars($canonicalUrl) ?>",
            <?php if (!empty($ogImage)): ?> "image": "<?= htmlspecialchars(preg_match('#^https?://#i', $ogImage) ? $ogImage : $canonicalUrl . '/' . $ogImage) ?>",
            <?php endif; ?> "starRating": {
                "@type": "Rating",
                "ratingValue": "<?= $seo['web_schema_star_rating'] ?? '5' ?>"
            },
            "priceRange": "<?= htmlspecialchars($seo['web_schema_price_range'] ?? 'Rp 800.000 - Rp 2.500.000') ?>",
            "address": {
                "@type": "PostalAddress",
                "addressLocality": "Karimunjawa",
                "addressRegion": "Central Java",
                "addressCountry": "ID",
                "streetAddress": "<?= htmlspecialchars(BUSINESS_ADDRESS) ?>"
            },
            <?php if (!empty($seo['web_schema_latitude']) && !empty($seo['web_schema_longitude'])): ?> "geo": {
                    "@type": "GeoCoordinates",
                    "latitude": <?= floatval($seo['web_schema_latitude']) ?>,
                    "longitude": <?= floatval($seo['web_schema_longitude']) ?>
                },
            <?php endif; ?> "checkinTime": "<?= $seo['web_schema_checkin'] ?? '14:00' ?>",
            "checkoutTime": "<?= $seo['web_schema_checkout'] ?? '12:00' ?>",
            "telephone": "<?= htmlspecialchars(BUSINESS_PHONE) ?>",
            "email": "<?= htmlspecialchars(BUSINESS_EMAIL) ?>",
            "sameAs": [
                "https://www.instagram.com/<?= htmlspecialchars(BUSINESS_INSTAGRAM) ?>"
            ]
        }
    </script>

    <!-- Google Analytics 4 -->
    <?php if (!empty($seo['web_ga_id'])): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($seo['web_ga_id']) ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());
            gtag('config', '<?= htmlspecialchars($seo['web_ga_id']) ?>');
        </script>
    <?php endif; ?>

    <!-- Google Tag Manager -->
    <?php if (!empty($seo['web_gtm_id'])): ?>
        <script>
            (function(w, d, s, l, i) {
                w[l] = w[l] || [];
                w[l].push({
                    'gtm.start': new Date().getTime(),
                    event: 'gtm.js'
                });
                var f = d.getElementsByTagName(s)[0],
                    j = d.createElement(s),
                    dl = l != 'dataLayer' ? '&l=' + l : '';
                j.async = true;
                j.src =
                    'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                f.parentNode.insertBefore(j, f);
            })(window, document, 'script', 'dataLayer', '<?= htmlspecialchars($seo['web_gtm_id']) ?>');
        </script>
    <?php endif; ?>

    <?php
    // Favicon
    if (!empty($faviconPath)):
        $ext = strtolower(pathinfo($faviconPath, PATHINFO_EXTENSION));
        $mimeMap = ['ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        $mimeType = $mimeMap[$ext] ?? 'image/png';
    ?>
        <link rel="icon" type="<?= $mimeType ?>" href="<?= htmlspecialchars(assetUrl($faviconPath, 'uploads/favicon')) ?>">
        <link rel="shortcut icon" type="<?= $mimeType ?>" href="<?= htmlspecialchars(assetUrl($faviconPath, 'uploads/favicon')) ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;1,400;1,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>

<body>

    <!-- Navigation -->
    <nav class="navbar" id="mainNav">
        <div class="container">
            <a href="<?= BASE_URL ?>/" class="navbar-brand">
                <?php if (!empty($logoPath)): ?>
                    <img src="<?= htmlspecialchars(assetUrl($logoPath, 'uploads/logo')) ?>" alt="Narayana" class="brand-img">
                <?php endif; ?>
                <div class="brand-text">
                    <div class="brand-logo">Narayana</div>
                    <div class="brand-sub">Karimunjawa</div>
                </div>
            </a>
            <ul class="nav-links" id="navLinks">
                <li><a href="<?= BASE_URL ?>/" class="<?= ($currentPage ?? '') === 'home' ? 'active' : '' ?>">Home</a></li>
                <li><a href="<?= BASE_URL ?>/rooms.php" class="<?= ($currentPage ?? '') === 'rooms' ? 'active' : '' ?>">Rooms</a></li>
                <li><a href="<?= BASE_URL ?>/activities.php" class="<?= ($currentPage ?? '') === 'activities' ? 'active' : '' ?>">Activities</a></li>
                <li><a href="<?= BASE_URL ?>/destinations.php" class="<?= ($currentPage ?? '') === 'destinations' ? 'active' : '' ?>">Destinations</a></li>
                <li><a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="<?= ($currentPage ?? '') === 'booking' ? 'active' : '' ?>">Reservations</a></li>
                <li><a href="<?= BASE_URL ?>/contact.php" class="<?= ($currentPage ?? '') === 'contact' ? 'active' : '' ?>">Contact</a></li>
                <li><a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="nav-book-btn">Book Now</a></li>
            </ul>
            <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation">
                <span></span><span></span><span></span>
            </button>
        </div>
    </nav>