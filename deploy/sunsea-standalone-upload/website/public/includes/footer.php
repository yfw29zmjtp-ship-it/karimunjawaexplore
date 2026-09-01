<!-- Footer -->
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-about">
                <h5>Narayana Karimunjawa</h5>
                <p>A beachfront resort on the pristine Karimunjawa Islands. Natural beauty meets refined Indonesian hospitality.</p>
                <div class="footer-social">
                    <a href="https://www.instagram.com/<?= BUSINESS_INSTAGRAM ?>" target="_blank" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                    <a href="mailto:<?= BUSINESS_EMAIL ?>" aria-label="Email"><i class="fas fa-envelope"></i></a>
                </div>
            </div>
            <div>
                <h5>Explore</h5>
                <ul class="footer-links">
                    <li><a href="<?= BASE_URL ?>/rooms.php">Rooms</a></li>
                    <li><a href="<?= BASE_URL ?>/activities.php">Activities</a></li>
                    <li><a href="<?= BASE_URL ?>/destinations.php">Destinations</a></li>
                    <li><a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>">Reservations</a></li>
                    <li><a href="<?= BASE_URL ?>/contact.php">Contact</a></li>
                </ul>
            </div>
            <div>
                <h5>Information</h5>
                <ul class="footer-links">
                    <li><a href="<?= htmlspecialchars(buildCloudbedsReservationUrl()) ?>">Check Availability</a></li>
                    <li><a href="#">Cancellation Policy</a></li>
                    <li><a href="#">Getting Here</a></li>
                </ul>
            </div>
            <div>
                <h5>Contact</h5>
                <ul class="footer-contact">
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= BUSINESS_ADDRESS ?></span>
                    </li>
                    <li>
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?= BUSINESS_PHONE ?>"><?= BUSINESS_PHONE ?></a>
                    </li>
                    <li>
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?= BUSINESS_EMAIL ?>"><?= BUSINESS_EMAIL ?></a>
                    </li>
                    <li>
                        <i class="fas fa-clock"></i>
                        <span>Check-in <?= BUSINESS_CHECKIN_TIME ?> · Check-out <?= BUSINESS_CHECKOUT_TIME ?></span>
                    </li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> Narayana Karimunjawa. All rights reserved.</span>
            <span>Karimunjawa Island, Central Java, Indonesia</span>
        </div>
    </div>
</footer>

<script>
    // Navbar scroll
    const nav = document.getElementById('mainNav');
    window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 50));
    if (window.scrollY > 50) nav.classList.add('scrolled');

    // Mobile nav with overlay
    const navToggle = document.getElementById('navToggle');
    const navLinks = document.getElementById('navLinks');
    // Create overlay element
    const navOverlay = document.createElement('div');
    navOverlay.className = 'nav-overlay';
    document.body.appendChild(navOverlay);

    function toggleMobileNav() {
        const isOpen = navLinks.classList.toggle('open');
        navOverlay.classList.toggle('active', isOpen);
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }

    function closeMobileNav() {
        navLinks.classList.remove('open');
        navOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    navToggle?.addEventListener('click', toggleMobileNav);
    navOverlay.addEventListener('click', closeMobileNav);
    document.querySelectorAll('#navLinks a').forEach(link => {
        link.addEventListener('click', closeMobileNav);
    });

    // Fade-in observer
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });
    document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

    // Utils
    function formatCurrency(amount) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
    }

    function showToast(message, type = 'info') {
        const existing = document.querySelector('.toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    }

    async function apiCall(url, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json'
            }
        };
        if (data && method !== 'GET') options.body = JSON.stringify(data);
        const response = await fetch(url, options);
        return response.json();
    }
</script>
</body>

</html>