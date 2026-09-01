<?php

/**
 * NARAYANA KARIMUNJAWA — Activities & Experiences
 * What to Do During Your Stay — Inspirational Travel Guide
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'activities';
$pageTitle = 'Activities & Experiences';

// Hero settings
$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_act_%'");
$_heroA = [];
foreach ($_heroRows as $_h) $_heroA[$_h['setting_key']] = $_h['setting_value'];
$actHeroEyebrow  = $_heroA['web_hero_act_eyebrow']  ?? 'Karimunjawa Islands';
$actHeroTitle    = $_heroA['web_hero_act_title']    ?? 'Things to Do During<br>Your <em>Island Stay</em>';
$actHeroSubtitle = $_heroA['web_hero_act_subtitle'] ?? 'Karimunjawa is more than a destination — it\'s a world of its own. Here\'s what awaits you.';
$actHeroBg       = $_heroA['web_hero_act_background'] ?? 'https://images.unsplash.com/photo-1589308078059-be1415eab4c3?w=1920&q=80';

// Activities — load from database (or use defaults)
$_actRow = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'web_activities'");
$_actFromDb = $_actRow ? json_decode($_actRow['setting_value'], true) : [];
// Filter active only and sort by order
if (!empty($_actFromDb)) {
    $_actFromDb = array_filter($_actFromDb, function ($a) {
        return $a['active'] ?? true;
    });
    usort($_actFromDb, function ($a, $b) {
        return ($a['order'] ?? 0) - ($b['order'] ?? 0);
    });
    $activities = array_values($_actFromDb);
} else {
    // Comprehensive defaults — detailed Karimunjawa activity guide
    $activities = [
        [
            'id'       => 'snorkeling',
            'eyebrow'  => 'Underwater World',
            'title'    => 'Snorkelling in Crystal-Clear Waters',
            'image'    => 'https://images.unsplash.com/photo-1534258936925-c58bed479fcb?w=900&q=80',
            'body'     => 'Karimunjawa\'s waters are among the clearest in the entire Java Sea, with visibility regularly reaching <strong>15 to 20 metres</strong> on calm days. Just a short boat ride from Narayana, you\'ll be floating above vibrant coral gardens teeming with life — clownfish darting between anemones, parrotfish grazing on coral, giant clams wedged into reef crevices, and green sea turtles gliding through the blue. The archipelago\'s most celebrated snorkelling spots include <strong>Menjangan Kecil</strong>, where shallow reef tables allow you to snorkel just a metre above hard coral colonies; <strong>Gosong Cemara</strong>, a submerged sandbar surrounded by turquoise water and schooling fish; and <strong>Taka Menyawakan</strong>, a coral atoll where the reef drops away dramatically into deep blue. The marine biodiversity here is extraordinary — over 240 species of coral and more than 240 species of reef fish have been recorded within Karimunjawa National Marine Park. Whether you\'re a first-timer putting your face in the water for the first time or an experienced snorkeller chasing macro life in the shallows, these reefs will leave you speechless.',
            'details'  => ['Best time: 7:00–11:00 (calmest water, best visibility)', 'Top spots: Menjangan Kecil, Gosong Cemara, Taka Menyawakan', 'Distance from hotel: 10–30 min by boat', 'Suitable for all ages and skill levels', 'Equipment rental available on most boat trips', 'Over 240 coral species and 240+ fish species recorded'],
            'order'    => 1,
            'active'   => true,
        ],
        [
            'id'       => 'island-hopping',
            'eyebrow'  => 'Island Exploration',
            'title'    => 'Hopping Between 27 Islands',
            'image'    => 'https://images.unsplash.com/photo-1559128010-7c1ad6e1b6a5?w=900&q=80',
            'body'     => 'The Karimunjawa archipelago comprises <strong>27 islands</strong>, and only five of them are inhabited — the rest remain wild, forested, and fringed by powdery white sand. An island-hopping trip is the quintessential Karimunjawa experience: you board a traditional wooden boat at the harbour and sail between islands, stopping wherever the water is clearest or the beach most inviting. <strong>Cemara Kecil</strong> is famous for its iconic leaning coconut palm — probably the most photographed tree in all of Central Java — and its shallow, glass-like lagoon. <strong>Cemara Besar</strong> offers a long white beach bordered by casuarina trees, perfect for a quiet swim. <strong>Menjangan Besar</strong> has a hilltop viewpoint overlooking the entire western archipelago, and a small shark conservation area where you can swim alongside blacktip reef sharks. <strong>Geleang Island</strong> is a tiny sliver of sand surrounded by some of the best shallow reefs in the park. Most island-hopping tours last a full day and include stops at 3–5 islands, a freshly grilled seafood lunch on the beach, and plenty of time to snorkel, swim, or simply sit on a deserted shore and wonder how a place this beautiful still exists.',
            'details'  => ['Full-day excursion (08:00–16:00)', 'Visit 3–5 islands per trip', 'Popular stops: Cemara Kecil, Cemara Besar, Menjangan Besar, Geleang', 'Grilled seafood lunch included on most trips', 'Customisable routes available for private charters', 'Bring sunscreen, hat, and waterproof bag'],
            'order'    => 2,
            'active'   => true,
        ],
        [
            'id'       => 'diving',
            'eyebrow'  => 'Deep Exploration',
            'title'    => 'Scuba Diving the Marine National Park',
            'image'    => 'https://images.unsplash.com/photo-1682687220742-aba13b6e50ba?w=900&q=80',
            'body'     => 'Karimunjawa has been designated a <strong>National Marine Park</strong> since 2001, and the protection has paid off — the reefs here are among the healthiest in Indonesia\'s northern Java coast. Below the surface you\'ll find towering sea fans up to two metres across, dense staghorn coral thickets, barrel sponges the size of bathtubs, and an astonishing diversity of marine life. Divers regularly encounter <strong>blacktip and whitetip reef sharks</strong>, Napoleon wrasse, banded sea kraits, schools of barracuda, giant trevally, moray eels, and cuttlefish. On lucky days, <strong>whale sharks</strong> pass through the deeper channels between islands — typically between March and May. The underwater topography is dramatic: wall dives at <strong>Taka Malang</strong> drop from 5 metres to beyond 40; drift dives at <strong>Kapal Tenggelam (The Wreck)</strong> take you past an Indonesian patrol vessel resting at 25 metres, now encrusted in coral and home to lionfish and giant groupers; and the gentle slopes of <strong>Pulau Sintok</strong> offer easy dives through coral gardens alive with nudibranchs and juvenile reef fish. Several dive operators near the hotel offer PADI courses, from Discover Scuba Diving for complete beginners to Advanced Open Water certification.',
            'details'  => ['Over 30 mapped dive sites across the archipelago', 'Depth range: 5–40+ metres', 'Marine life: reef sharks, Napoleon wrasse, sea turtles, whale sharks (seasonal)', 'Top sites: Taka Malang, Kapal Tenggelam wreck, Pulau Sintok', 'PADI courses available: DSD, Open Water, Advanced', 'Best visibility: April–September (dry season)', 'Water temperature: 27–30°C year-round'],
            'order'    => 3,
            'active'   => true,
        ],
        [
            'id'       => 'shark-encounter',
            'eyebrow'  => 'Wildlife Encounter',
            'title'    => 'Swimming with Sharks & Rays',
            'image'    => 'https://images.unsplash.com/photo-1560275619-4662e36fa65c?w=900&q=80',
            'body'     => 'One of Karimunjawa\'s most unique and thrilling experiences takes place at <strong>Menjangan Besar Island</strong>, where a natural ocean enclosure allows visitors to enter the water alongside <strong>blacktip reef sharks and giant stingrays</strong>. This isn\'t a theme park — it\'s a community-run conservation initiative where local fishermen have partnered with the national park authority to protect juvenile sharks rather than catch them. You wade or swim into chest-deep water while reef sharks — typically 1 to 1.5 metres long — circle around you with calm, elegant movements. Stingrays glide along the sandy bottom, sometimes brushing past your legs. The experience is both exhilarating and surprisingly peaceful. Guides are always present and the sharks are accustomed to human presence, making it safe even for children (under supervision). This encounter has become one of the <strong>most popular attractions</strong> in Karimunjawa, and for good reason — it offers a rare chance to be in the water with apex predators in a respectful, conservation-minded setting. The site also includes a small marine education area explaining the shark species found in Karimunjawa waters and the importance of reef shark populations to the marine ecosystem.',
            'details'  => ['Location: Menjangan Besar Island (15 min by boat)', 'Species: Blacktip reef sharks & giant stingrays', 'Safe for children under adult supervision', 'Community-run conservation initiative', 'Entrance fee supports local shark protection', 'Best combined with an island-hopping trip', 'Waterproof camera highly recommended'],
            'order'    => 4,
            'active'   => true,
        ],
        [
            'id'       => 'sunset',
            'eyebrow'  => 'Golden Hour',
            'title'    => 'Watching the Sunset from Bukit Love',
            'image'    => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=900&q=80',
            'body'     => 'Karimunjawa faces west across the open Java Sea, which means one thing: <strong>the sunsets are extraordinary</strong>. The most famous viewpoint on the island is <strong>Bukit Love</strong> (Love Hill), a hilltop lookout on the western coast where a large heart-shaped sign frames the horizon — the perfect photo backdrop as the sky turns amber, pink, and violet. The walk up takes about 10 minutes along a gently sloping forest path, and the view from the top is panoramic: you can see the silhouettes of distant islands, fishing boats returning to harbour, and the sun sinking into the sea in a blaze of colour. Another popular sunset spot is the <strong>Sunset Point near Legon Lele</strong>, a quieter, more secluded beach where locals gather in the evening with snacks and warm drinks. For something more special, consider a <strong>sunset boat cruise</strong> — local operators offer traditional wooden boat trips that take you out onto the calm evening water while the sky performs its nightly show. Wherever you choose to watch, arrive about 30 minutes before sunset (typically around 17:15–17:45 depending on season) to settle in and enjoy the gradual transformation of light.',
            'details'  => ['Bukit Love: 10 min walk/ride from most hotels', 'Sunset time: approximately 17:15–17:45 depending on season', 'Alternative spots: Legon Lele beach, Sunset Pier, boat cruise', 'Best from April–October (dry season, clearer skies)', 'Bring camera, light jacket for evening breeze', 'Free access to all hilltop viewpoints', 'Sunset boat cruises available through local operators'],
            'order'    => 5,
            'active'   => true,
        ],
        [
            'id'       => 'mangrove',
            'eyebrow'  => 'Nature & Conservation',
            'title'    => 'Exploring the Mangrove Forest Trail',
            'image'    => 'https://images.unsplash.com/photo-1504681869696-d977211a5f4c?w=900&q=80',
            'body'     => 'Along the southern and eastern shores of Karimunjawa\'s main island stretches a vast <strong>mangrove ecosystem</strong> — one of the most important and biodiverse habitats in the entire national park. The <strong>Mangrove Tracking Area</strong> near Kemujan features a well-maintained wooden boardwalk that winds through dense mangrove forest, passing over tidal channels where you can look down and spot mudskippers, tiny crabs, and juvenile fish sheltering among the tangled roots. The canopy above is alive with birdlife: <strong>kingfishers</strong> perch on low branches waiting to strike, <strong>white-bellied sea eagles</strong> soar overhead, and if you visit in the early morning, you might hear the distinctive call of the mangrove pitta. The mangrove forest is the nursery of the sea — it\'s where juvenile reef fish begin their lives before migrating to the coral reefs, and where the roots filter sediment and protect the coastline from erosion. For a more immersive experience, you can explore the mangrove channels <strong>by kayak</strong>, paddling silently through narrow waterways shaded by arching roots. The quiet is profound — broken only by the splash of a fish or the call of a bird. Guided tours include information about the 15+ mangrove species found here and their critical role in the island\'s marine ecosystem.',
            'details'  => ['Location: Mangrove Tracking Area, near Kemujan', 'Duration: 1–2 hours (walk) or 2–3 hours (kayak)', 'Wooden boardwalk suitable for all fitness levels', 'Best visited in the early morning (cooler, more wildlife)', 'Kayak rentals available on-site', '15+ mangrove species identified in the park', 'Birdlife: kingfishers, sea eagles, herons, mangrove pittas', 'Bring insect repellent and comfortable footwear'],
            'order'    => 6,
            'active'   => true,
        ],
        [
            'id'       => 'motorbike',
            'eyebrow'  => 'Freedom to Roam',
            'title'    => 'Exploring the Island by Motorbike',
            'image'    => 'https://images.unsplash.com/photo-1558981806-ec527fa84c39?w=900&q=80',
            'body'     => 'The best way to discover Karimunjawa at your own pace is on a <strong>motorbike</strong>. The main island is small enough to ride around in a single morning, yet rich enough in scenery  and surprises to fill several days of exploration. Rent a scooter from one of the many rental shops near the harbour and set off with no fixed plan — the beauty of Karimunjawa is in the wandering. Ride through <strong>traditional fishing villages</strong> where brightly painted wooden boats are pulled up on the sand; pass through dense coconut groves where the light filters green through the fronds; stop at a roadside <strong>warung</strong> (local food stall) for grilled fish, sambal, and cold es kelapa; or follow a dirt track into the interior and discover a hilltop view you\'d never find on foot. The road along the <strong>west coast</strong> takes you past a string of small beaches, each more secluded than the last. The <strong>eastern road to Kemujan</strong> crosses a narrow bridge between islands and passes through mangrove-lined channels — a ride that feels like crossing into another world. Traffic is minimal, the roads are well-maintained on main routes, and the islanders are famously welcoming — don\'t be surprised if someone waves you over to share fresh fruit or point you toward a hidden beach.',
            'details'  => ['Scooter rental: approximately IDR 75,000–100,000 per day', 'Main roads are paved and well-maintained', 'Helmets provided with rental', 'No license checks for tourists (but always ride carefully)', 'Petrol stations in the main village area', 'Recommended route: West coast beach road + Kemujan bridge', 'Full island circuit: approximately 2–3 hours with stops', 'Bring sunscreen and a light rain jacket for afternoons'],
            'order'    => 7,
            'active'   => true,
        ],
        [
            'id'       => 'fishing',
            'eyebrow'  => 'Ocean Tradition',
            'title'    => 'Traditional & Sport Fishing',
            'image'    => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?w=900&q=80',
            'body'     => 'Fishing is the lifeblood of Karimunjawa — for centuries, the islanders have lived by the rhythm of the sea, and the fishing tradition runs deep in the local culture. As a visitor, you can experience this firsthand by joining a <strong>traditional fishing trip</strong> on a wooden boat, using hand lines, simple rods, or even nets alongside experienced local fishermen. The waters around the archipelago are rich with <strong>snapper, grouper, trevally, barracuda, and tuna</strong>, and even beginners often pull in a respectable catch. For something more exciting, <strong>sport fishing</strong> trips venture to the deeper channels between islands where bigger game fish patrol — including giant trevally (GT), Spanish mackerel, and mahi-mahi. One of Karimunjawa\'s most memorable fishing experiences is <strong>night fishing (mancing malam)</strong>: you head out after sunset on a boat equipped with lights that attract squid and baitfish, which in turn draw in larger predators from the deep. Under a sky thick with stars and the Milky Way stretching overhead, you fish by lamplight while the crew grills your earlier catch over charcoal on the back of the boat. It\'s an experience that captures the essence of island life — simple, beautiful, and deeply satisfying.',
            'details'  => ['Traditional fishing: half-day trips from the harbour', 'Species: snapper, grouper, trevally, barracuda, tuna, GT', 'Night fishing (mancing malam): depart around 18:00, return by 22:00', 'No experience necessary — local guides teach you', 'Catch can be grilled on the boat or at a local restaurant', 'Sport fishing charters available for serious anglers', 'Best fishing months: March–October', 'All equipment provided on organised trips'],
            'order'    => 8,
            'active'   => true,
        ],
        [
            'id'       => 'kayaking',
            'eyebrow'  => 'Coastal Adventure',
            'title'    => 'Kayaking & Stand-Up Paddleboarding',
            'image'    => 'https://images.unsplash.com/photo-1472745433479-4556f22e32c2?w=900&q=80',
            'body'     => 'Karimunjawa\'s calm, sheltered bays and crystal-clear shallows make it an ideal destination for <strong>kayaking and stand-up paddleboarding (SUP)</strong>. Unlike the open ocean, the waters between islands are protected from large swells, creating flat, glass-like conditions — especially in the mornings before the afternoon breeze picks up. Paddle along the coastline and you\'ll pass over coral gardens visible through the transparent water below, glide past rocky outcrops where monitor lizards sun themselves, and discover tiny coves and beaches accessible only from the water. One of the most beautiful kayaking routes follows the <strong>mangrove channels</strong> on the eastern side of the main island — a quiet, meditative journey through shaded waterways where the only sounds are birdsong and the gentle dip of your paddle. For SUP enthusiasts, the <strong>shallow lagoon near Cemara Kecil</strong> is a dream — you can paddle over white sand and turquoise water so clear it feels like floating on air. Both kayaks and SUP boards are available for hourly or half-day rental at select beaches, and guided tours can be arranged for those who want a curated route with the best swimming and snorkelling stops along the way.',
            'details'  => ['Best conditions: morning (before 11:00) when water is calmest', 'Kayak rental: approximately IDR 50,000–75,000 per hour', 'SUP rental: approximately IDR 75,000–100,000 per hour', 'Top routes: mangrove channels, Cemara Kecil lagoon, west coast coves', 'No prior experience needed for calm-water paddling', 'Guided kayak tours available with snorkelling stops', 'Life jackets provided with all rentals', 'Suitable for ages 8 and up'],
            'order'    => 9,
            'active'   => true,
        ],
        [
            'id'       => 'trekking',
            'eyebrow'  => 'Highland Adventure',
            'title'    => 'Jungle Trekking & Hill Walks',
            'image'    => 'https://images.unsplash.com/photo-1551632811-561732d1e306?w=900&q=80',
            'body'     => 'While most visitors come to Karimunjawa for the sea, the <strong>interior of the main island</strong> offers a completely different kind of beauty. Much of the island\'s highland is covered in dense <strong>tropical lowland forest</strong> — a remnant of the old-growth jungle that once covered the entire archipelago. Several trekking trails wind through this forest, ranging from easy 30-minute walks to more challenging half-day treks that climb to the island\'s highest points. The <strong>Bukit Gajah trail</strong> ascends through forest rich with native trees — including the rare and protected <em>Dewadaru</em> (Crystocalyx macrophylla), a tree considered sacred by locals and found almost nowhere else in Indonesia. Along the way you\'ll hear the calls of tropical birds, spot large butterflies, and if you\'re very quiet, you might see a <strong>Karimunjawa white-eye</strong> (<em>Zosterops chloronothos</em>) — an endemic bird species found only on these islands and classified as critically endangered. The reward at the top is a <strong>360-degree panorama</strong> over the entire archipelago: islands stretching to the horizon, reefs visible as patches of turquoise against the deep blue sea, and the green canopy of the forest below. Guided treks can be arranged through the national park office and are recommended for the longer trails.',
            'details'  => ['Trail options: 30 min easy walk to 3–4 hour challenging trek', 'Highlights: Dewadaru sacred tree, endemic bird species', 'Bukit Gajah: moderate difficulty, panoramic summit views', 'Early morning start recommended (cooler, better wildlife)', 'Guides available through National Park office', 'Bring: water, closed shoes, insect repellent, long trousers', 'Entry permit required for national park trekking zones', 'Birdwatching: Karimunjawa white-eye (endemic, critically endangered)'],
            'order'    => 10,
            'active'   => true,
        ],
        [
            'id'       => 'beach-camping',
            'eyebrow'  => 'Under the Stars',
            'title'    => 'Camping on Uninhabited Islands',
            'image'    => 'https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=900&q=80',
            'body'     => 'For the ultimate Karimunjawa experience, spend a night <strong>camping on an uninhabited island</strong>. Imagine this: a white-sand beach entirely to yourself, a sky filled with more stars than you\'ve ever seen, the gentle sound of waves, and the glow of a campfire reflecting off the water. Several islands in the archipelago are open to overnight camping — the most popular being <strong>Cemara Besar</strong> and <strong>Geleang Island</strong>. Local operators arrange everything: the boat transfer, tents, sleeping mats, a portable cooking setup, and a crew who will grill fresh fish and prepare simple Indonesian meals over an open fire. As night falls, the <strong>Milky Way</strong> arcs overhead with astonishing clarity — there is zero light pollution on these outer islands, making the stargazing exceptional. Many campers wake early to snorkel at dawn, when the reef is at its quietest and the light illuminates the coral in soft gold. Beach camping in Karimunjawa is not roughing it — it\'s a curated experience that brings you as close to nature as possible while still being comfortable. It\'s particularly popular with couples and small groups of friends looking for something genuinely different.',
            'details'  => ['Popular islands: Cemara Besar, Geleang, Menjangan Kecil', 'Duration: overnight (1 night) or extended (2 nights)', 'All equipment provided: tents, mats, cooking gear', 'Fresh seafood dinner prepared by local crew', 'Zero light pollution — exceptional stargazing', 'Best during dry season: April–October', 'Advance booking recommended (limited permits)', 'Bring: flashlight, insect repellent, personal items'],
            'order'    => 11,
            'active'   => true,
        ],
        [
            'id'       => 'village-culture',
            'eyebrow'  => 'Local Heritage',
            'title'    => 'Village Tours & Local Culture',
            'image'    => 'https://images.unsplash.com/photo-1590523741831-ab7e8b8f9c7f?w=900&q=80',
            'body'     => 'Karimunjawa\'s culture is as rich and layered as its marine ecosystem. The islands have been home to <strong>Javanese and Bugis fishing communities</strong> for centuries, and their traditions, beliefs, and way of life remain remarkably intact. A village tour takes you into the heart of this living culture. In <strong>Karimunjawa Village</strong> — the main settlement on the island — you can walk through narrow lanes past traditional wooden houses with carved details, visit the <strong>historic cemetery of Sunan Nyamplungan</strong> (one of the early Islamic missionaries believed to have settled here in the 15th century), and watch boatbuilders at the harbour crafting traditional wooden fishing vessels using techniques passed down through generations. The <strong>Bugis community in Kemujan</strong> maintains distinct cultural traditions, including their own style of house construction on stilts and a deep-sea fishing heritage. Food is a central part of the cultural experience — join a local family for a simple meal of <strong>nasi goreng ikan</strong> (fried rice with fresh fish), <strong>sate kelapa</strong> (coconut satay), or <strong>bakso ikan</strong> (fish meatball soup), all made with ingredients caught or grown that morning. The warmth and hospitality of the Karimunjawa people is something every visitor remembers long after they leave.',
            'details'  => ['Karimunjawa Village: main settlement, walking distance from harbour', 'Sunan Nyamplungan cemetery: historic Islamic heritage site', 'Bugis community in Kemujan: distinct culture and traditions', 'Traditional boatbuilding: still active at the main harbour', 'Local cuisine: nasi goreng ikan, sate kelapa, bakso ikan', 'Batik-making workshops occasionally available', 'Best experienced with a local guide (arranged via hotel)', 'Respectful dress recommended when visiting religious sites'],
            'order'    => 12,
            'active'   => true,
        ],
    ];

    // Auto-seed to database so activities appear in developer panel
    try {
        $seedJson = json_encode($activities);
        dbQuery(
            "INSERT INTO settings (setting_key, setting_value, setting_type, description) 
                 VALUES ('web_activities', ?, 'text', 'Website Activities') 
                 ON DUPLICATE KEY UPDATE setting_value = CASE WHEN setting_value = '[]' OR setting_value = '' THEN VALUES(setting_value) ELSE setting_value END",
            [$seedJson]
        );
    } catch (Exception $e) {
        // Silently fail — defaults will still render
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<section class="hero hero-activities">
    <div class="hero-bg" style="background-image: url('<?= htmlspecialchars($actHeroBg) ?>');"></div>
</section>

<!-- Intro -->
<section class="section" style="padding: 36px 0 28px;">
    <div class="container">
        <div class="act-intro fade-in">
            <div class="act-intro-text">
                <div class="section-eyebrow">During Your Stay</div>
                <h2 class="section-title" style="font-size: clamp(1.4rem, 2.2vw, 1.8rem);">Life at Narayana</h2>
                <div class="divider"></div>
                <p style="color: var(--warm-gray); font-size: 0.88rem; line-height: 1.8; max-width: 480px;">
                    Narayana sits at the heart of Karimunjawa — where the water is warm, the reefs are alive, and the pace of life slows to something worth remembering.
                </p>
            </div>
            <div class="act-intro-meta">
                <div class="act-intro-stat">
                    <span class="act-intro-stat-num"><?= count($activities) ?></span>
                    <span class="act-intro-stat-label">Experiences</span>
                </div>
                <div class="act-intro-stat">
                    <span class="act-intro-stat-num">27</span>
                    <span class="act-intro-stat-label">Islands</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Activities — Editorial Alternating Layout -->
<div id="activities-guide">
    <?php foreach ($activities as $i => $act):
        $reverse = $i % 2 !== 0;
    ?>
        <section class="act-story <?= $reverse ? 'act-story-reverse' : '' ?>">
            <div class="container">
                <div class="act-story-inner fade-in">

                    <!-- Photo -->
                    <div class="act-story-image">
                        <img src="<?= htmlspecialchars($act['image']) ?>" alt="<?= htmlspecialchars($act['title']) ?>" loading="lazy">
                        <div class="act-story-image-label"><?= htmlspecialchars($act['eyebrow']) ?></div>
                    </div>

                    <!-- Text -->
                    <div class="act-story-content">
                        <div class="act-story-num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></div>
                        <div class="section-eyebrow"><?= htmlspecialchars($act['eyebrow']) ?></div>
                        <h2><?= htmlspecialchars($act['title']) ?></h2>
                        <div class="divider"></div>
                        <p class="act-story-body"><?= $act['body'] ?></p>

                        <ul class="act-story-details">
                            <?php foreach ($act['details'] as $d): ?>
                                <li><i class="fas fa-circle-dot"></i> <?= htmlspecialchars($d) ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%20Narayana%2C%20I%27d%20like%20to%20know%20more%20about%20<?= urlencode($act['title']) ?>" target="_blank" class="act-story-link">
                            Ask us about this <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                </div>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<!-- Final CTA -->
<section class="cta-section">
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Ready?</div>
        <h2>Start Planning Your Stay</h2>
        <p>Book a room at Narayana and explore everything Karimunjawa has to offer — right from your doorstep.</p>
        <div class="btn-group" style="justify-content:center;">
            <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn btn-gold btn-lg">Reserve a Room</a>
            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%20Narayana%2C%20I%27d%20like%20to%20know%20more%20about%20activities%20during%20my%20stay" target="_blank" class="btn btn-outline-white btn-lg">
                <i class="fab fa-whatsapp"></i> Ask Us Anything
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>