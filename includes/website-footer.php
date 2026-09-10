    <footer class="we-footer">
        <div class="we-container">
            <div class="we-footer-grid">
                <div>
                    <h4><?php echo htmlspecialchars($weCompanyName); ?></h4>
                    <p style="opacity:.85;line-height:1.7;max-width:340px;">
                        Jasa tour &amp; travel resmi untuk wisata Karimunjawa — paket open trip, private trip, island hopping,
                        penginapan, dan transport laut/darat.
                    </p>
                </div>
                <div>
                    <h4>Navigasi</h4>
                    <a href="home.php">Beranda</a>
                    <a href="paket-wisata.php">Paket Wisata</a>
                    <a href="galeri.php">Galeri</a>
                    <a href="tentang-kami.php">Tentang Kami</a>
                    <a href="blog.php">Blog</a>
                </div>
                <div>
                    <h4>Kontak</h4>
                    <?php if ($weCompanyPhone): ?><a href="tel:<?php echo htmlspecialchars($weCompanyPhone); ?>">&#9742; <?php echo htmlspecialchars($weCompanyPhone); ?></a><?php endif; ?>
                    <?php if ($weCompanyEmail): ?><a href="mailto:<?php echo htmlspecialchars($weCompanyEmail); ?>">&#9993; <?php echo htmlspecialchars($weCompanyEmail); ?></a><?php endif; ?>
                    <a href="kontak.php">&#128205; <?php echo htmlspecialchars($weCompanyAddr); ?></a>
                </div>
            </div>
            <div class="we-footer-bottom">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($weCompanyName); ?>. Seluruh hak cipta dilindungi.
                &middot; Powered by &copy; AdFsystem.online 2026
            </div>
        </div>
    </footer>

    <?php if ($weWaAdmins): ?>
        <div class="we-chat-widget" id="weChatWidget">
            <div class="we-chat-panel" id="weChatPanel">
                <div class="we-chat-header">
                    <div class="we-chat-header-info">
                        <span class="we-chat-avatar">
                            <?php if ($weLogoSrc): ?>
                                <img src="<?php echo htmlspecialchars($weLogoSrc); ?>" alt="<?php echo htmlspecialchars($weCompanyName); ?>">
                            <?php else: ?>
                                🌊
                            <?php endif; ?>
                        </span>
                        <div>
                            <div class="we-chat-title"><?php echo htmlspecialchars($weCompanyName); ?></div>
                            <div class="we-chat-status">Biasanya membalas cepat via WhatsApp</div>
                        </div>
                    </div>
                    <button type="button" class="we-chat-close" onclick="weChatToggle(false)">&times;</button>
                </div>
                <?php if (count($weWaAdmins) > 1): ?>
                    <div class="we-chat-admins" id="weChatAdmins">
                        <?php foreach ($weWaAdmins as $i => $wa): ?>
                            <button type="button" class="we-chat-admin-chip<?php echo $i === 0 ? ' we-active' : ''; ?>" data-index="<?php echo $i; ?>"><?php echo htmlspecialchars($wa['label']); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="we-chat-body" id="weChatBody">
                    <div class="we-chat-bubble we-chat-bubble-in">
                        Halo! 👋 Ada yang bisa kami bantu seputar trip ke Karimunjawa? Tulis pesan Anda di bawah ini.
                    </div>
                </div>
                <div class="we-chat-footer">
                    <input type="text" id="weChatInput" class="we-chat-input" placeholder="Tulis pesan Anda..." maxlength="500">
                    <button type="button" class="we-chat-send" id="weChatSendBtn" onclick="weChatSend()" aria-label="Kirim">&#10148;</button>
                </div>
            </div>
            <button type="button" class="we-chat-fab" id="weChatFab" onclick="weChatToggle()" aria-label="Chat WhatsApp">
                <svg class="we-chat-fab-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 3C7.03 3 3 6.58 3 11c0 2.39 1.19 4.53 3.08 6.02-.1.98-.42 2.28-1.28 3.48-.14.2.02.47.27.44 1.7-.2 3.13-.9 4.2-1.58.86.24 1.78.37 2.73.37 4.97 0 9-3.58 9-8s-4.03-8-9-8z" fill="currentColor" />
                </svg>
            </button>
        </div>

        <script>
            (function() {
                var admins = <?php echo json_encode(array_map(fn($a) => $a['wa'], $weWaAdmins)); ?>;
                var selectedIndex = 0;
                var panel = document.getElementById('weChatPanel');
                var widget = document.getElementById('weChatWidget');
                var body = document.getElementById('weChatBody');
                var input = document.getElementById('weChatInput');
                var adminsBar = document.getElementById('weChatAdmins');

                window.weChatToggle = function(forceOpen) {
                    var open = typeof forceOpen === 'boolean' ? forceOpen : !widget.classList.contains('we-open');
                    widget.classList.toggle('we-open', open);
                    if (open) input.focus();
                };

                if (adminsBar) {
                    adminsBar.addEventListener('click', function(e) {
                        var chip = e.target.closest('.we-chat-admin-chip');
                        if (!chip) return;
                        selectedIndex = parseInt(chip.dataset.index, 10) || 0;
                        adminsBar.querySelectorAll('.we-chat-admin-chip').forEach(function(c) {
                            c.classList.toggle('we-active', c === chip);
                        });
                    });
                }

                window.weChatSend = function() {
                    var msg = input.value.trim();
                    if (!msg) return;

                    var bubble = document.createElement('div');
                    bubble.className = 'we-chat-bubble we-chat-bubble-out';
                    bubble.textContent = msg;
                    body.appendChild(bubble);
                    body.scrollTop = body.scrollHeight;
                    input.value = '';

                    // Pesan diteruskan ke WhatsApp asli (bukan sekadar link statis) — nomor
                    // admin yang dipilih tamu (kalau lebih dari 1), dibuka di tab baru saat Enter/Kirim.
                    var waBase = admins[selectedIndex] || admins[0];
                    window.open(waBase + '?text=' + encodeURIComponent(msg), '_blank');
                };

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        weChatSend();
                    }
                });
            })();
        </script>
    <?php endif; ?>

    <script>
        (function() {
            document.querySelectorAll('.we-carousel').forEach(function(carousel) {
                var track = carousel.querySelector('.we-carousel-track');
                var slides = Array.prototype.slice.call(carousel.querySelectorAll('.we-carousel-slide'));
                var dotsWrap = carousel.querySelector('.we-carousel-dots');
                var prevBtn = carousel.querySelector('.we-prev');
                var nextBtn = carousel.querySelector('.we-next');
                if (!track || slides.length === 0) return;

                var perView = 3;
                var index = 0;
                var autoplayMs = parseInt(carousel.dataset.autoplay, 10) || 0;
                var timer = null;

                function updatePerView() {
                    if (carousel.classList.contains('we-carousel-gallery')) {
                        perView = 1;
                        return;
                    }
                    var w = window.innerWidth;
                    perView = w <= 620 ? 1 : (w <= 860 ? 2 : 3);
                }

                function maxIndex() {
                    return Math.max(0, slides.length - perView);
                }

                function renderDots() {
                    if (!dotsWrap) return;
                    dotsWrap.innerHTML = '';
                    if (slides.length <= perView) return;
                    for (var i = 0; i <= maxIndex(); i++) {
                        var dot = document.createElement('button');
                        dot.type = 'button';
                        dot.className = 'we-carousel-dot' + (i === index ? ' we-active' : '');
                        dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                        (function(target) {
                            dot.addEventListener('click', function() {
                                goTo(target);
                            });
                        })(i);
                        dotsWrap.appendChild(dot);
                    }
                }

                function goTo(i) {
                    index = Math.max(0, Math.min(i, maxIndex()));
                    var slideWidth = slides[0].getBoundingClientRect().width;
                    track.style.transform = 'translateX(-' + (index * slideWidth) + 'px)';
                    renderDots();
                }

                function next() {
                    goTo(index >= maxIndex() ? 0 : index + 1);
                }

                function prev() {
                    goTo(index <= 0 ? maxIndex() : index - 1);
                }

                function startAutoplay() {
                    if (!autoplayMs || slides.length <= perView) return;
                    stopAutoplay();
                    timer = setInterval(next, autoplayMs);
                }

                function stopAutoplay() {
                    if (timer) clearInterval(timer);
                    timer = null;
                }

                if (nextBtn) nextBtn.addEventListener('click', function() { next(); startAutoplay(); });
                if (prevBtn) prevBtn.addEventListener('click', function() { prev(); startAutoplay(); });

                carousel.addEventListener('mouseenter', stopAutoplay);
                carousel.addEventListener('mouseleave', startAutoplay);

                // Drag / swipe support (mouse + touch) via Pointer Events.
                // Only treat it as a "drag" once the pointer actually moves past a small
                // threshold — otherwise a plain click/tap (e.g. on "Lihat Detail") must
                // pass through untouched, so we don't setPointerCapture / preventDefault
                // until real dragging is confirmed.
                var pointerDown = false;
                var dragging = false;
                var startX = 0;
                var startOffset = 0;
                var moveThreshold = 6;

                track.addEventListener('pointerdown', function(e) {
                    if (e.target.closest('a, button')) return;
                    pointerDown = true;
                    dragging = false;
                    startX = e.clientX;
                    startOffset = -index * slides[0].getBoundingClientRect().width;
                });

                track.addEventListener('pointermove', function(e) {
                    if (!pointerDown) return;
                    var delta = e.clientX - startX;
                    if (!dragging) {
                        if (Math.abs(delta) < moveThreshold) return;
                        dragging = true;
                        track.classList.add('we-dragging');
                        stopAutoplay();
                        track.setPointerCapture(e.pointerId);
                    }
                    track.style.transform = 'translateX(' + (startOffset + delta) + 'px)';
                });

                function endDrag(e) {
                    pointerDown = false;
                    if (!dragging) return;
                    dragging = false;
                    track.classList.remove('we-dragging');
                    var delta = e.clientX - startX;
                    var threshold = slides[0].getBoundingClientRect().width * 0.2;
                    if (delta < -threshold) next();
                    else if (delta > threshold) prev();
                    else goTo(index);
                    startAutoplay();
                }

                track.addEventListener('pointerup', endDrag);
                track.addEventListener('pointerleave', function(e) {
                    if (pointerDown) endDrag(e);
                });

                window.addEventListener('resize', function() {
                    updatePerView();
                    goTo(index);
                });

                updatePerView();
                goTo(0);
                startAutoplay();
            });
        })();
    </script>

    </body>

    </html>