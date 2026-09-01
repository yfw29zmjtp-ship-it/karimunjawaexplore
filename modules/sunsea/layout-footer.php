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