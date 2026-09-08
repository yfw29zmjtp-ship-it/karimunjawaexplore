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

    </body>

    </html>