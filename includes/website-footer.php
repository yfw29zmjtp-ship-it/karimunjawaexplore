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

    <?php if ($weCompanyPhone): ?>
        <?php $weChatWaBase = sunseaWaLink($weCompanyPhone); ?>
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
                    <path d="M12 3C7.03 3 3 6.58 3 11c0 2.39 1.19 4.53 3.08 6.02-.1.98-.42 2.28-1.28 3.48-.14.2.02.47.27.44 1.7-.2 3.13-.9 4.2-1.58.86.24 1.78.37 2.73.37 4.97 0 9-3.58 9-8s-4.03-8-9-8z" fill="currentColor"/>
                </svg>
            </button>
        </div>

        <script>
            (function () {
                var waBase = <?php echo json_encode($weChatWaBase); ?>;
                var panel = document.getElementById('weChatPanel');
                var widget = document.getElementById('weChatWidget');
                var body = document.getElementById('weChatBody');
                var input = document.getElementById('weChatInput');

                window.weChatToggle = function (forceOpen) {
                    var open = typeof forceOpen === 'boolean' ? forceOpen : !widget.classList.contains('we-open');
                    widget.classList.toggle('we-open', open);
                    if (open) input.focus();
                };

                window.weChatSend = function () {
                    var msg = input.value.trim();
                    if (!msg) return;

                    var bubble = document.createElement('div');
                    bubble.className = 'we-chat-bubble we-chat-bubble-out';
                    bubble.textContent = msg;
                    body.appendChild(bubble);
                    body.scrollTop = body.scrollHeight;
                    input.value = '';

                    // Pesan diteruskan ke WhatsApp asli (bukan sekadar link statis) — nomor
                    // dari setting company_phone, dibuka di tab baru begitu tamu menekan Enter/Kirim.
                    window.open(waBase + '?text=' + encodeURIComponent(msg), '_blank');
                };

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        weChatSend();
                    }
                });
            })();
        </script>
    <?php endif; ?>

    </body>

    </html>