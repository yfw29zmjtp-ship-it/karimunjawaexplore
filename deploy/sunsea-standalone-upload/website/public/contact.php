<?php

/**
 * NARAYANA KARIMUNJAWA — Contact
 * Marriott-style contact page
 */
// Flexible path: works on hosting (config inside webroot) and local dev (config outside public/)
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'contact';
$pageTitle = 'Contact Us';

// Load hero settings for contact page
$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_contact_%'");
$_hero = [];
foreach ($_heroRows as $_h) $_hero[$_h['setting_key']] = $_h['setting_value'];
$heroEyebrow  = $_hero['web_hero_contact_eyebrow']  ?? 'Get in Touch';
$heroTitle     = $_hero['web_hero_contact_title']     ?? 'Contact Us';
$heroSubtitle  = $_hero['web_hero_contact_subtitle']  ?? 'We\'d love to hear from you. Reach out and let us help plan your stay.';
$heroBg        = $_hero['web_hero_contact_background'] ?? '';

include __DIR__ . '/includes/header.php';
?>

<!-- Page Hero -->
<section class="page-hero" <?php if (!empty($heroBg)): ?> style="background: linear-gradient(180deg, rgba(0,0,0,0.4), rgba(0,0,0,0.6)), url('<?= BASE_URL ?>/<?= htmlspecialchars($heroBg) ?>') center/cover;" <?php endif; ?>>
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);"><?= htmlspecialchars($heroEyebrow) ?></div>
        <h1><?= $heroTitle ?></h1>
        <p><?= htmlspecialchars($heroSubtitle) ?></p>
    </div>
</section>

<!-- Contact Content -->
<section class="section" style="padding-top:48px;">
    <div class="container">
        <div class="contact-grid">
            <!-- Cards -->
            <div class="contact-cards">
                <div class="contact-card">
                    <div class="contact-icon"><i class="fab fa-whatsapp"></i></div>
                    <h4>WhatsApp</h4>
                    <p>Chat with our team for quick responses and booking assistance.</p>
                    <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" class="btn btn-primary btn-block">
                        <i class="fab fa-whatsapp"></i> Start Chat
                    </a>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fas fa-phone"></i></div>
                    <h4>Phone</h4>
                    <p>Call our front desk directly for immediate assistance.</p>
                    <a href="tel:<?= BUSINESS_PHONE ?>" class="btn btn-outline btn-block">
                        <i class="fas fa-phone"></i> <?= BUSINESS_PHONE ?>
                    </a>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                    <h4>Email</h4>
                    <p>Send us a detailed inquiry and we'll respond within 24 hours.</p>
                    <a href="mailto:<?= BUSINESS_EMAIL ?>" class="btn btn-outline btn-block">
                        <i class="fas fa-envelope"></i> Send Email
                    </a>
                </div>

                <div class="contact-card">
                    <div class="contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <h4>Location</h4>
                    <p><?= BUSINESS_ADDRESS ?></p>
                    <a href="https://maps.google.com/?q=Karimunjawa+Island" target="_blank" class="btn btn-outline btn-block">
                        <i class="fas fa-directions"></i> Get Directions
                    </a>
                </div>
            </div>

            <!-- Inquiry Form -->
            <div>
                <div class="form-card">
                    <div class="section-eyebrow">Send Inquiry</div>
                    <h3 style="margin-bottom:24px;">Send Us a Message</h3>
                    <form id="contactForm" onsubmit="submitContact(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" class="form-control" id="contactName" placeholder="Your full name" required>
                            </div>
                            <div class="form-group">
                                <label>Email *</label>
                                <input type="email" class="form-control" id="contactEmail" placeholder="your@email.com" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" class="form-control" id="contactPhone" placeholder="+62 xxx-xxxx-xxxx">
                        </div>
                        <div class="form-group">
                            <label>Subject *</label>
                            <select class="form-control" id="contactSubject" required>
                                <option value="">Select a topic</option>
                                <option value="Reservation Inquiry">Reservation Inquiry</option>
                                <option value="Group Booking">Group Booking</option>
                                <option value="Special Request">Special Request</option>
                                <option value="Feedback">Feedback</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Message *</label>
                            <textarea class="form-control" id="contactMessage" rows="5" placeholder="Tell us how we can help..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg">
                            <i class="fas fa-paper-plane"></i> Send via WhatsApp
                        </button>
                        <p style="text-align:center; font-size:12px; color:var(--mid-gray); margin-top:10px;">
                            Your message will be sent directly to our WhatsApp for faster response.
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Map -->
<section class="section section-alt">
    <div class="container">
        <div style="text-align:center; margin-bottom:32px;">
            <div class="section-eyebrow">Location</div>
            <h2 class="section-title">Find Us in Karimunjawa</h2>
            <div class="divider center"></div>
            <p class="section-desc">Narayana is located on the beautiful Karimunjawa Island, Central Java, Indonesia.</p>
        </div>
        <div class="map-container">
            <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d63456.2837399655!2d110.41!3d-5.81!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e778e5f3e5a5b4d%3A0x1234567890abcdef!2sKarimunjawa%2C%20Jepara%2C%20Central%20Java!5e0!3m2!1sen!2sid!4v1704067200000!5m2!1sen!2sid"
                width="100%" height="400" style="border:0; border-radius:var(--radius-lg);" allowfullscreen loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="container" style="text-align:center;">
        <div class="section-eyebrow" style="color:var(--gold-light);">Ready to Book?</div>
        <h2 style="color:var(--white); margin-bottom:12px;">Start Planning Your Island Escape</h2>
        <p style="color:rgba(255,255,255,0.75); margin-bottom:28px;">Browse our rooms and secure the best rate when you book directly.</p>
        <div class="btn-group" style="justify-content:center;">
            <a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>" class="btn btn-gold btn-lg"><i class="fas fa-calendar-alt"></i> Make a Reservation</a>
            <a href="<?= BASE_URL ?>/rooms.php" class="btn btn-outline-white btn-lg">View Rooms</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
    function submitContact(e) {
        e.preventDefault();
        const name = document.getElementById('contactName').value;
        const email = document.getElementById('contactEmail').value;
        const phone = document.getElementById('contactPhone').value;
        const subject = document.getElementById('contactSubject').value;
        const message = document.getElementById('contactMessage').value;

        const text = `*New Inquiry — ${subject}*\n\nName: ${name}\nEmail: ${email}\nPhone: ${phone}\n\n${message}`;
        const encoded = encodeURIComponent(text);
        window.open(`https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=${encoded}`, '_blank');
        showToast('Opening WhatsApp...', 'success');
    }
</script>