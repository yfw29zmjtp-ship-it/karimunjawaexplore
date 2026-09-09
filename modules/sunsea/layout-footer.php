    </div><!-- /.ss-content -->
    </div><!-- /.ss-main -->

    <script>
        feather.replace();

        // Show sidebar toggle on mobile
        (function() {
            var btn = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768 && btn) btn.style.display = 'block';
            window.addEventListener('resize', function() {
                if (btn) btn.style.display = window.innerWidth <= 768 ? 'block' : 'none';
            });
        })();

        // Poll unread count for "Email Kantor" nav dot (IMAP check is slow, so it's async).
        (function() {
            var dot = document.getElementById('sunseaEmailUnreadDot');
            if (!dot) return;
            async function checkEmailUnread() {
                try {
                    const res = await fetch('<?php echo BASE_URL; ?>/modules/email/unread-count.php');
                    const data = await res.json();
                    dot.style.display = (data.unread > 0) ? 'inline-block' : 'none';
                } catch (e) {
                    /* ignore */
                }
            }
            checkEmailUnread();
            setInterval(checkEmailUnread, 180000);
        })();
    </script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($inlineJS)): ?>
        <script>
            <?php echo $inlineJS; ?>
        </script>
    <?php endif; ?>
    </body>

    </html>