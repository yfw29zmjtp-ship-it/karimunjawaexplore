<?php

/**
 * NARAYANA KARIMUNJAWA — Destinations & Travel Guide
 * Karimunjawa Island Tourist Destinations with editorial layout
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'destinations';
$pageTitle = 'Destinations';

// Helper: resolve image URL (supports absolute & relative)
function destImgUrl($img)
{
    if (empty($img)) return '';
    if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) return $img;
    return BASE_URL . '/' . $img;
}

// Default Karimunjawa destinations with free images
$defaultDestinations = [
    [
        'id'       => 'tanjung-gelam',
        'title'    => 'Tanjung Gelam Beach',
        'subtitle' => 'The Most Famous Sunset Spot in Karimunjawa',
        'category' => 'Beach',
        'icon'     => 'fa-umbrella-beach',
        'image'    => 'https://images.pexels.com/photos/5028877/pexels-photo-5028877.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Tanjung Gelam is the crown jewel of Karimunjawa sunsets. Located on the western tip of the main island, this beach offers golden sand, swaying palm trees, and the most breathtaking sunset panorama in the entire archipelago. Visitors can rent beanbags, enjoy fresh coconut drinks, and watch the sky transform into shades of orange and purple every evening. The calm waters make it perfect for swimming and wading.',
        'tips'     => 'Best visited 1 hour before sunset. Bring a camera!',
        'distance' => '15 min from Narayana',
        'active'   => true,
        'order'    => 1,
    ],
    [
        'id'       => 'menjangan-besar',
        'title'    => 'Menjangan Besar Island',
        'subtitle' => 'Shark Nursery & Snorkeling Paradise',
        'category' => 'Island',
        'icon'     => 'fa-water',
        'image'    => 'https://images.pexels.com/photos/3041867/pexels-photo-3041867.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Menjangan Besar is one of the most visited islands in the Karimunjawa archipelago, famous for its baby shark nursery where you can swim alongside harmless reef sharks. The island features a stunning underwater world perfect for snorkeling, with vibrant coral gardens and tropical fish. You can also feed stingrays and explore the clear, shallow waters that make it ideal for families and beginners.',
        'tips'     => 'Bring underwater camera. Shark feeding at 10 AM.',
        'distance' => '20 min by boat',
        'active'   => true,
        'order'    => 2,
    ],
    [
        'id'       => 'blue-lagoon',
        'title'    => 'Blue Lagoon',
        'subtitle' => 'Crystal Clear Turquoise Waters',
        'category' => 'Lagoon',
        'icon'     => 'fa-gem',
        'image'    => 'https://images.pexels.com/photos/1532432/pexels-photo-1532432.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'The Blue Lagoon of Karimunjawa is a hidden gem tucked between small islands, where the water is so clear you can see the white sandy bottom from your boat. The stunning gradient of blues — from turquoise to deep sapphire — creates a surreal, almost unreal seascape. Perfect for snorkeling, freediving, or simply floating in the calm, warm waters while surrounded by untouched nature.',
        'tips'     => 'Part of island-hopping tours. Bring snorkel gear.',
        'distance' => '30 min by boat',
        'active'   => true,
        'order'    => 3,
    ],
    [
        'id'       => 'bens-cafe',
        'title'    => "Ben's Cafe Karimunjawa",
        'subtitle' => 'Iconic Overwater Dining with Sunset Views',
        'category' => 'Dining',
        'icon'     => 'fa-utensils',
        'image'    => 'https://images.pexels.com/photos/3125524/pexels-photo-3125524.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => "Ben's Cafe is the most iconic dining spot in Karimunjawa, built on wooden stilts over crystal-clear turquoise waters. This legendary overwater restaurant offers fresh seafood, cold drinks, and the most Instagram-worthy sunset views on the island. The rustic wooden structure and laid-back atmosphere make it the perfect place to end a day of island adventures. Watch the sun melt into the Java Sea while enjoying grilled fish and tropical cocktails.",
        'tips'     => 'Arrive early for best sunset seats. Cash only.',
        'distance' => '10 min from Narayana',
        'active'   => true,
        'order'    => 4,
    ],
    [
        'id'       => 'mangrove-forest',
        'title'    => 'Mangrove Forest Trail',
        'subtitle' => 'Eco-Tour Through Ancient Mangrove Ecosystem',
        'category' => 'Nature',
        'icon'     => 'fa-tree',
        'image'    => 'https://images.pexels.com/photos/4450655/pexels-photo-4450655.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'The Karimunjawa Mangrove Forest is a protected ecological zone featuring a beautiful wooden boardwalk trail winding through dense mangrove trees. This educational eco-tour takes you through the heart of the island mangrove ecosystem where you can observe unique bird species, crabs, mudskippers, and learn about the vital role mangroves play in protecting the coastline. The elevated walkway provides stunning views over the mangrove canopy.',
        'tips'     => 'Best in early morning for bird watching.',
        'distance' => '20 min from Narayana',
        'active'   => true,
        'order'    => 5,
    ],
    [
        'id'       => 'cemara-kecil',
        'title'    => 'Cemara Kecil Island',
        'subtitle' => 'Tiny Pine Island with White Sand Beach',
        'category' => 'Island',
        'icon'     => 'fa-island-tropical',
        'image'    => 'https://images.pexels.com/photos/417083/pexels-photo-417083.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Cemara Kecil (Small Pine Island) is a picture-perfect tiny island with a white sand beach, crystal clear shallow water, and a cluster of iconic pine trees that give it its name. This small paradise is one of the most photographed spots in Karimunjawa — the combination of white sand, turquoise water, and green pine trees creates a tropical postcard scene. Perfect for a quick stop during island hopping.',
        'tips'     => 'Great for photography. Visit during low tide.',
        'distance' => '25 min by boat',
        'active'   => true,
        'order'    => 6,
    ],
    [
        'id'       => 'menjangan-kecil',
        'title'    => 'Menjangan Kecil Island',
        'subtitle' => 'Glass Bottom Boat & Coral Gardens',
        'category' => 'Island',
        'icon'     => 'fa-fish',
        'image'    => 'https://images.pexels.com/photos/3569318/pexels-photo-3569318.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Menjangan Kecil offers some of the best coral viewing in the entire Karimunjawa National Park. The island is famous for its glass-bottom boat experience, where you can admire the vibrant underwater world without getting wet. For the more adventurous, the snorkeling here is world-class — expect to see colorful reef fish, sea turtles, and pristine coral formations. The island also features a cliff-jumping spot popular with thrill-seekers.',
        'tips'     => 'Glass bottom boat IDR 35,000. Cliff jump at west side.',
        'distance' => '15 min by boat',
        'active'   => true,
        'order'    => 7,
    ],
    [
        'id'       => 'ujung-gelam',
        'title'    => 'Ujung Gelam Beach',
        'subtitle' => 'Pristine White Sand & Coconut Palms',
        'category' => 'Beach',
        'icon'     => 'fa-sun',
        'image'    => 'https://images.pexels.com/photos/35649388/pexels-photo-35649388.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Ujung Gelam is one of the most serene and untouched beaches in Karimunjawa. With its powdery white sand, leaning coconut palms, and gentle waves, this beach feels like your own private paradise. The shallow, calm waters are perfect for swimming, and the natural shade from palm trees makes it ideal for a relaxing afternoon. During low season, you may have this stunning beach entirely to yourself.',
        'tips'     => 'Bring your own food and drinks. Very peaceful.',
        'distance' => '20 min from Narayana',
        'active'   => true,
        'order'    => 8,
    ],
    [
        'id'       => 'bukit-love',
        'title'    => 'Bukit Love (Love Hill)',
        'subtitle' => 'Panoramic Hilltop Viewpoint',
        'category' => 'Viewpoint',
        'icon'     => 'fa-mountain',
        'image'    => 'https://images.pexels.com/photos/36332947/pexels-photo-36332947.jpeg?auto=compress&cs=tinysrgb&w=800',
        'content'  => 'Bukit Love is Karimunjawa\'s most beloved hilltop viewpoint, offering a sweeping 360-degree panorama of the archipelago. From the top, you can see the main island, surrounding smaller islands, the deep blue Java Sea, and on clear days even the distant coast of Java. The short hike through tropical forest is rewarded with one of the most spectacular views in Central Java. Popular for sunrise and sunset visits.',
        'tips'     => 'Bring water. 15-minute uphill walk. Sunrise is magical.',
        'distance' => '25 min from Narayana',
        'active'   => true,
        'order'    => 9,
    ],
];

// Load destinations from database (override defaults if available)
$destinations = [];
try {
    $destRow = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'web_destinations'");
    if ($destRow && !empty($destRow['setting_value'])) {
        $allDest = json_decode($destRow['setting_value'], true) ?: [];
        foreach ($allDest as $d) {
            if (!empty($d['active'])) {
                $destinations[] = $d;
            }
        }
        usort($destinations, function ($a, $b) {
            return ($a['order'] ?? 0) - ($b['order'] ?? 0);
        });
    }
} catch (Exception $e) {
}

// Use defaults when database is empty
if (empty($destinations)) {
    $destinations = $defaultDestinations;
}

// Single destination detail view
$selectedDest = null;
if (isset($_GET['id'])) {
    $reqId = $_GET['id'];
    foreach ($destinations as $d) {
        if ($d['id'] === $reqId) {
            $selectedDest = $d;
            $pageTitle = $d['title'];
            break;
        }
    }
}

// Load hero settings
$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_dest_%'");
$_heroD = [];
foreach ($_heroRows as $_h) $_heroD[$_h['setting_key']] = $_h['setting_value'];
$destHeroEyebrow  = $_heroD['web_hero_dest_eyebrow']  ?? 'Explore Karimunjawa';
$destHeroTitle     = $_heroD['web_hero_dest_title']     ?? 'Discover the Island';
$destHeroSubtitle  = $_heroD['web_hero_dest_subtitle']  ?? 'Your guide to the most breathtaking destinations and hidden gems of Karimunjawa.';
$destHeroBg        = $_heroD['web_hero_dest_background'] ?? '';

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($selectedDest): ?>
    <!-- ============== SINGLE DESTINATION DETAIL ============== -->
    <?php $detImg = destImgUrl($selectedDest['image'] ?? ''); ?>
    <section class="dest-hero" <?php if ($detImg): ?>style="background-image: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.55)), url('<?= htmlspecialchars($detImg) ?>')" <?php endif; ?>>
        <div class="container">
            <div class="dest-hero-content">
                <a href="<?= BASE_URL ?>/destinations.php" class="dest-back-link"><i class="fas fa-arrow-left"></i> All Destinations</a>
                <?php if (!empty($selectedDest['category'])): ?>
                    <div class="dest-hero-eyebrow"><?= htmlspecialchars($selectedDest['category']) ?></div>
                <?php endif; ?>
                <h1 class="dest-hero-title"><?= htmlspecialchars($selectedDest['title']) ?></h1>
                <?php if (!empty($selectedDest['subtitle'])): ?>
                    <p style="color:rgba(255,255,255,0.8); font-size:15px; margin-top:12px; max-width:600px;"><?= htmlspecialchars($selectedDest['subtitle']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section" style="padding: 48px 0;">
        <div class="container">
            <div class="dest-detail-content">
                <?php if ($detImg): ?>
                    <div class="dest-detail-image">
                        <img src="<?= htmlspecialchars($detImg) ?>" alt="<?= htmlspecialchars($selectedDest['title']) ?>">
                    </div>
                <?php endif; ?>

                <div class="dest-detail-text">
                    <?php if (!empty($selectedDest['content'])): ?>
                        <p><?= $selectedDest['content'] ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($selectedDest['tips']) || !empty($selectedDest['distance'])): ?>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin:24px 0;">
                        <?php if (!empty($selectedDest['tips'])): ?>
                            <div style="background:var(--cream); padding:20px; border-radius:8px;">
                                <div style="font-size:11px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--gold); margin-bottom:6px;"><i class="fas fa-lightbulb"></i> Travel Tips</div>
                                <p style="font-size:13px; color:var(--warm-gray); line-height:1.6; margin:0;"><?= htmlspecialchars($selectedDest['tips']) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($selectedDest['distance'])): ?>
                            <div style="background:var(--cream); padding:20px; border-radius:8px;">
                                <div style="font-size:11px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:var(--gold); margin-bottom:6px;"><i class="fas fa-location-dot"></i> Distance</div>
                                <p style="font-size:13px; color:var(--warm-gray); line-height:1.6; margin:0;"><?= htmlspecialchars($selectedDest['distance']) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="dest-detail-cta">
                    <div class="dest-cta-box">
                        <h3>Interested in visiting <?= htmlspecialchars($selectedDest['title']) ?>?</h3>
                        <p>Book your stay at Narayana Karimunjawa and explore this amazing destination during your trip.</p>
                        <div class="dest-cta-buttons">
                            <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn-primary-dest">Book Your Stay</a>
                            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%2C%20I%27m%20interested%20in%20visiting%20<?= urlencode($selectedDest['title']) ?>" target="_blank" class="btn-outline-dest"><i class="fab fa-whatsapp"></i> Ask Us</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Other Destinations -->
            <?php
            $otherDest = array_filter($destinations, fn($d) => $d['id'] !== $selectedDest['id']);
            if (!empty($otherDest)):
            ?>
                <div class="dest-other-section">
                    <h2 class="dest-other-title">Explore More Destinations</h2>
                    <div class="dest-grid">
                        <?php foreach (array_slice(array_values($otherDest), 0, 3) as $other):
                            $otherImg = destImgUrl($other['image'] ?? '');
                        ?>
                            <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($other['id']) ?>" class="dest-card fade-in">
                                <div class="dest-card-image">
                                    <?php if ($otherImg): ?>
                                        <img src="<?= htmlspecialchars($otherImg) ?>" alt="<?= htmlspecialchars($other['title']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="dest-card-placeholder"><i class="fas fa-mountain"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="dest-card-body">
                                    <h3><?= htmlspecialchars($other['title']) ?></h3>
                                    <?php if (!empty($other['subtitle'])): ?>
                                        <p class="dest-card-subtitle"><?= htmlspecialchars($other['subtitle']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

<?php else: ?>
    <!-- ============== DESTINATIONS LISTING PAGE ============== -->

    <!-- Hero -->
    <section class="dest-page-hero" <?php if (!empty($destHeroBg)): ?> style="background: linear-gradient(180deg, rgba(0,0,0,0.4), rgba(0,0,0,0.6)), url('<?= htmlspecialchars(destImgUrl($destHeroBg)) ?>') center/cover;" <?php endif; ?>>
        <div class="container">
            <div class="dest-page-hero-content">
                <div class="section-eyebrow" style="color:var(--gold-light);"><?= htmlspecialchars($destHeroEyebrow) ?></div>
                <h1 class="dest-page-title"><?= $destHeroTitle ?></h1>
                <p class="dest-page-desc"><?= htmlspecialchars($destHeroSubtitle) ?></p>
            </div>
        </div>
    </section>

    <!-- Quick Stats Bar -->
    <section style="background:var(--dark); padding:0;">
        <div class="container">
            <div style="display:grid; grid-template-columns:repeat(3,1fr); text-align:center;">
                <div style="padding:20px; border-right:1px solid rgba(255,255,255,0.1);">
                    <div style="font-family:var(--font-heading); font-size:1.6rem; color:var(--gold-light);"><?= count($destinations) ?></div>
                    <div style="font-size:10px; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,0.5);">Destinations</div>
                </div>
                <div style="padding:20px; border-right:1px solid rgba(255,255,255,0.1);">
                    <div style="font-family:var(--font-heading); font-size:1.6rem; color:var(--gold-light);">27</div>
                    <div style="font-size:10px; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,0.5);">Islands</div>
                </div>
                <div style="padding:20px;">
                    <div style="font-family:var(--font-heading); font-size:1.6rem; color:var(--gold-light);">1</div>
                    <div style="font-size:10px; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,0.5);">National Park</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Destination (first one) -->
    <?php $featured = $destinations[0];
    $featImg = destImgUrl($featured['image'] ?? ''); ?>
    <section class="dest-featured-section">
        <div class="container">
            <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($featured['id']) ?>" class="dest-featured fade-in">
                <div class="dest-featured-image">
                    <?php if ($featImg): ?>
                        <img src="<?= htmlspecialchars($featImg) ?>" alt="<?= htmlspecialchars($featured['title']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="dest-card-placeholder" style="height:100%;"><i class="fas fa-mountain" style="font-size:48px;"></i></div>
                    <?php endif; ?>
                </div>
                <div class="dest-featured-content">
                    <div class="dest-featured-badge"><i class="fas fa-star"></i> Featured Destination</div>
                    <h2><?= htmlspecialchars($featured['title']) ?></h2>
                    <?php if (!empty($featured['subtitle'])): ?>
                        <p class="dest-featured-subtitle"><?= htmlspecialchars($featured['subtitle']) ?></p>
                    <?php endif; ?>
                    <p class="dest-featured-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($featured['content'] ?? ''), 0, 180)) ?>...</p>
                    <?php if (!empty($featured['distance'])): ?>
                        <p style="font-size:12px; color:var(--mid-gray); margin-bottom:16px;"><i class="fas fa-location-dot" style="color:var(--gold);"></i> <?= htmlspecialchars($featured['distance']) ?></p>
                    <?php endif; ?>
                    <span class="dest-read-more">Explore <i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
        </div>
    </section>

    <!-- All Destinations - Editorial Grid -->
    <?php $gridDest = array_slice($destinations, 1); ?>
    <?php if (!empty($gridDest)): ?>
        <section class="section" style="padding:48px 0 64px;">
            <div class="container">
                <div class="section-header text-center fade-in" style="margin-bottom:36px;">
                    <div class="section-eyebrow">Island Guide</div>
                    <h2 class="section-title" style="font-size:clamp(1.4rem, 2.5vw, 1.8rem);">More Places to Explore</h2>
                    <div class="divider center"></div>
                </div>
                <div class="dest-grid">
                    <?php foreach ($gridDest as $dest):
                        $dImg = destImgUrl($dest['image'] ?? '');
                    ?>
                        <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($dest['id']) ?>" class="dest-card fade-in">
                            <div class="dest-card-image">
                                <?php if ($dImg): ?>
                                    <img src="<?= htmlspecialchars($dImg) ?>" alt="<?= htmlspecialchars($dest['title']) ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="dest-card-placeholder"><i class="fas fa-mountain"></i></div>
                                <?php endif; ?>
                                <div class="dest-card-overlay">
                                    <span>View Details</span>
                                </div>
                                <?php if (!empty($dest['category'])): ?>
                                    <div class="dest-card-cat"><?= htmlspecialchars($dest['category']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="dest-card-body">
                                <h3><?= htmlspecialchars($dest['title']) ?></h3>
                                <?php if (!empty($dest['subtitle'])): ?>
                                    <p class="dest-card-subtitle"><?= htmlspecialchars($dest['subtitle']) ?></p>
                                <?php endif; ?>
                                <p class="dest-card-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($dest['content'] ?? ''), 0, 100)) ?>...</p>
                                <div class="dest-card-meta">
                                    <?php if (!empty($dest['distance'])): ?>
                                        <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($dest['distance']) ?></span>
                                    <?php endif; ?>
                                    <span class="dest-read-more">Explore <i class="fas fa-arrow-right"></i></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- CTA Section -->
    <section class="dest-cta-section">
        <div class="container text-center">
            <div class="section-eyedrow" style="font-size:10px; letter-spacing:3px; text-transform:uppercase; color:var(--gold-light); margin-bottom:12px;">Start Your Adventure</div>
            <h2>Ready to Explore Karimunjawa?</h2>
            <p>Stay at Narayana and discover all these amazing destinations during your island getaway.</p>
            <div class="dest-cta-buttons" style="justify-content:center;">
                <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn-primary-dest">Book Your Stay</a>
                <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" class="btn-outline-dest"><i class="fab fa-whatsapp"></i> Contact Us</a>
            </div>
        </div>
    </section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>