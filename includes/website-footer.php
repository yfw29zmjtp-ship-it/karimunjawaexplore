    <footer class="we-footer">
        <div class="we-container">
            <div class="we-footer-grid">
                <div>
                    <h4><?php echo htmlspecialchars($weCompanyName); ?></h4>
                    <p style="opacity:.85;line-height:1.7;max-width:340px;">
                        Jasa tour &amp; travel resmi untuk wisata Karimunjawa — paket open trip, private trip, island hopping,
                        penginapan, dan transport laut/darat.
                    </p>
                    <?php if (array_filter($weSocialLinks)): ?>
                        <div class="we-footer-social">
                            <?php if (!empty($weSocialLinks['facebook'])): ?>
                                <a href="<?php echo htmlspecialchars($weSocialLinks['facebook']); ?>" target="_blank" rel="noopener" aria-label="Facebook">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.91h-2.34V22c4.78-.79 8.44-4.94 8.44-9.94z" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($weSocialLinks['instagram'])): ?>
                                <a href="<?php echo htmlspecialchars($weSocialLinks['instagram']); ?>" target="_blank" rel="noopener" aria-label="Instagram">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.64.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.65-.07-4.85s.02-3.58.07-4.85c.15-3.23 1.67-4.77 4.92-4.92 1.27-.06 1.65-.07 4.85-.07zM12 0C8.74 0 8.33.01 7.05.07c-4.35.2-6.78 2.62-6.98 6.98C.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c4.35-.2 6.79-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.19-4.35-2.62-6.78-6.98-6.98C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zM12 16a4 4 0 1 1 4-4 4 4 0 0 1-4 4zm6.41-10.85a1.44 1.44 0 1 0 1.44 1.44 1.44 1.44 0 0 0-1.44-1.44z" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($weSocialLinks['tiktok'])): ?>
                                <a href="<?php echo htmlspecialchars($weSocialLinks['tiktok']); ?>" target="_blank" rel="noopener" aria-label="TikTok">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M16.6 5.82c-.99-.98-1.55-2.3-1.55-3.72h-3.16v13.98a3.16 3.16 0 1 1-2.24-3.02V9.7a6.34 6.34 0 1 0 5.4 6.28V9.8a8.45 8.45 0 0 0 4.79 1.5V8.14a4.76 4.76 0 0 1-3.24-2.32z" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($weSocialLinks['youtube'])): ?>
                                <a href="<?php echo htmlspecialchars($weSocialLinks['youtube']); ?>" target="_blank" rel="noopener" aria-label="YouTube">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M23.5 7.15a3.02 3.02 0 0 0-2.12-2.14C19.5 4.5 12 4.5 12 4.5s-7.5 0-9.38.51A3.02 3.02 0 0 0 .5 7.15 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 4.85 3.02 3.02 0 0 0 2.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-4.85zM9.6 15.3V8.7l6.27 3.3z" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
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
                    <?php foreach ($weCompanyPhones as $weFooterPhone): ?>
                        <a href="tel:<?php echo htmlspecialchars($weFooterPhone); ?>">&#9742; <?php echo htmlspecialchars($weFooterPhone); ?></a>
                    <?php endforeach; ?>
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

    <?php if ($weWaAdmins):
        // Rotasi admin harian yang adil: index admin berganti tiap hari (day-of-year % jumlah admin)
        // biar semua admin kebagian giliran menerima chat pertama, bukan selalu admin yang sama.
        $weChatTodayAdminIndex = (int)date('z') % count($weWaAdmins);
        $weChatTodayAdminName  = $weWaAdmins[$weChatTodayAdminIndex]['label'] ?? '';
        // Jangan dobel kata "Admin" kalau nama yang diisi di Pengaturan sudah mengandungnya sendiri.
        $weChatTodayAdminDisplay = $weChatTodayAdminName && stripos($weChatTodayAdminName, 'admin') === false
            ? 'Admin ' . $weChatTodayAdminName
            : $weChatTodayAdminName;
    ?>
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
                            <div class="we-chat-status"><?php echo $weChatTodayAdminDisplay ? htmlspecialchars($weChatTodayAdminDisplay) . ' siap membalas via WhatsApp' : 'Biasanya membalas cepat via WhatsApp'; ?></div>
                        </div>
                    </div>
                    <button type="button" class="we-chat-close" onclick="weChatToggle(false)">&times;</button>
                </div>
                <?php if (count($weWaAdmins) > 1): ?>
                    <div class="we-chat-admins" id="weChatAdmins">
                        <?php foreach ($weWaAdmins as $i => $wa): ?>
                            <button type="button" class="we-chat-admin-chip<?php echo $i === $weChatTodayAdminIndex ? ' we-active' : ''; ?>" data-index="<?php echo $i; ?>"><?php echo htmlspecialchars($wa['label']); ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="we-chat-body" id="weChatBody">
                    <div class="we-chat-bubble we-chat-bubble-in">
                        <?php echo $weChatTodayAdminDisplay
                            ? 'Halo! 👋 ' . htmlspecialchars($weChatTodayAdminDisplay) . ' akan membalas pesan Anda. Ada yang bisa kami bantu seputar trip ke Karimunjawa? Tulis pesan Anda di bawah ini.'
                            : 'Halo! 👋 Ada yang bisa kami bantu seputar trip ke Karimunjawa? Tulis pesan Anda di bawah ini.'; ?>
                    </div>
                </div>
                <div class="we-chat-footer">
                    <input type="text" id="weChatInput" class="we-chat-input" placeholder="Tulis pesan Anda..." maxlength="500">
                    <button type="button" class="we-chat-send" id="weChatSendBtn" onclick="weChatSend()" aria-label="Kirim">&#10148;</button>
                </div>
            </div>
            <button type="button" class="we-chat-fab" id="weChatFab" onclick="weChatToggle()" aria-label="Chat WhatsApp">
                <svg class="we-chat-fab-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#fff" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                </svg>
            </button>
        </div>

        <script>
            (function() {
                var admins = <?php echo json_encode(array_map(fn($a) => $a['wa'], $weWaAdmins)); ?>;
                var selectedIndex = <?php echo (int)($weChatTodayAdminIndex ?? 0); ?>;
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

                if (nextBtn) nextBtn.addEventListener('click', function() {
                    next();
                    startAutoplay();
                });
                if (prevBtn) prevBtn.addEventListener('click', function() {
                    prev();
                    startAutoplay();
                });

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