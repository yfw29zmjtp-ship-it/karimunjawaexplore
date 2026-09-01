<?php

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$biz = trim((string)($_GET['biz'] ?? ''));
$allowedBiz = ['narayana-hotel', 'bens-cafe', 'eaat-meet'];
if (!in_array($biz, $allowedBiz, true)) {
    http_response_code(404);
    echo 'Menu tidak ditemukan.';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$bizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $biz));
$activeBiz = (string)($_SESSION['active_business_id'] ?? '');
$activeBizNorm = strtolower((string)preg_replace('/[^a-z0-9]/', '', $activeBiz));
$isDev = isset($_SESSION['role']) && $_SESSION['role'] === 'developer';
$isAllowedInternal = $isDev || $activeBizNorm === $bizNorm;
if (!$isAllowedInternal) {
    http_response_code(403);
    echo 'Akses halaman menu ini dibatasi.';
    exit;
}

require_once __DIR__ . '/config/businesses/' . $biz . '.php';

$db = Database::getInstance();
$pdo = $db->getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS menu_book_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(255) NOT NULL,
    page_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_order (page_order),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $pdo->query('SELECT title, image_path FROM menu_book_pages WHERE is_active = 1 ORDER BY page_order ASC, id ASC');
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$bizTitle = defined('BUSINESS_NAME') ? BUSINESS_NAME : strtoupper(str_replace('-', ' ', $biz));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($bizTitle); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@400;500;700&display=swap');

        :root {
            --bg-1: #f4ecdf;
            --bg-2: #e7d7bf;
            --ink: #1f1a14;
            --gold: #a1732a;
            --card: #fdf8ef;
            --line: #d9c7aa;
            --shadow: rgba(65, 40, 12, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100svh;
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 640px at 8% 0%, rgba(255, 255, 255, 0.82), transparent 60%),
                radial-gradient(900px 500px at 94% 100%, rgba(162, 110, 44, 0.24), transparent 60%),
                linear-gradient(140deg, var(--bg-1), var(--bg-2));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .book-shell {
            width: 100%;
            min-height: 100svh;
            display: flex;
            flex-direction: column;
        }

        .book-head {
            display: none;
        }

        .viewer {
            position: relative;
            border-radius: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
            backdrop-filter: none;
            padding: 0;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .viewer-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .viewer-page-chip {
            font-size: 0.7rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #5b4021;
            background: #f5e8d5;
            border: 1px solid #e3ccb0;
            border-radius: 999px;
            padding: 4px 10px;
            font-weight: 700;
        }

        .viewer-hint {
            font-size: 0.67rem;
            color: #81613a;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            opacity: 0.9;
        }

        .viewer::after {
            display: none;
        }

        .book-stage {
            position: relative;
            height: 100%;
            min-height: 100svh;
            aspect-ratio: auto;
            max-width: 100%;
            border-radius: 0;
            overflow: hidden;
            border: 0;
            background: linear-gradient(180deg, #fefcf8, #f6eddf);
            box-shadow: none;
            touch-action: pan-y;
        }

        .book-stage::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, rgba(255, 255, 255, 0.16) 0%, transparent 40%, transparent 60%, rgba(120, 85, 30, 0.08) 100%);
            z-index: 4;
        }

        .tap-zone {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 23%;
            border: 0;
            background: transparent;
            z-index: 8;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }

        .tap-zone.left {
            left: 0;
        }

        .tap-zone.right {
            right: 0;
        }

        .page-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
            border-radius: 14px;
            opacity: 0;
            pointer-events: none;
            display: none;
            will-change: transform, opacity;
            backface-visibility: hidden;
            transform-style: preserve-3d;
        }

        .page-layer.is-active {
            opacity: 1;
            pointer-events: auto;
            display: block;
        }

        .page-sheet {
            position: absolute;
            inset: 0;
            padding: 6px;
            background: linear-gradient(180deg, #fffefc, #f8efe2);
            border-radius: 14px;
        }

        .page-sheet img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
            display: block;
            border-radius: 10px;
            border: 1px solid #eadcc8;
            box-shadow: 0 8px 20px rgba(88, 58, 18, 0.11);
        }

        .sheet-meta {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            min-height: 24px;
            background: linear-gradient(180deg, rgba(35, 22, 7, 0.03), rgba(35, 22, 7, 0.38));
            border-radius: 10px;
            padding: 6px 8px;
        }

        .sheet-title {
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            color: #fff4e3;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sheet-page-number {
            font-size: 0.66rem;
            font-weight: 700;
            color: #ffefdb;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 231, 193, 0.44);
            padding: 3px 8px;
            border-radius: 999px;
        }

        .finale-panel {
            position: absolute;
            left: 10px;
            right: 10px;
            bottom: 10px;
            z-index: 12;
            background: linear-gradient(180deg, rgba(36, 20, 4, 0.78), rgba(22, 12, 3, 0.9));
            border: 1px solid rgba(255, 227, 184, 0.46);
            border-radius: 14px;
            padding: 10px;
            color: #fae9d1;
            transform: translateY(112%);
            opacity: 0;
            pointer-events: none;
            transition: transform .42s cubic-bezier(0.2, 0.9, 0.2, 1), opacity .35s ease;
        }

        .finale-panel.is-visible {
            transform: translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .finale-title {
            margin: 0 0 5px 0;
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffe9ca;
        }

        .finale-text {
            margin: 0 0 9px 0;
            color: #f7dfbd;
            font-size: 0.68rem;
            line-height: 1.42;
        }

        .finale-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 7px;
        }

        .finale-btn {
            border: 1px solid rgba(255, 223, 180, 0.55);
            background: linear-gradient(180deg, rgba(255, 248, 237, 0.95), rgba(251, 226, 192, 0.95));
            color: #4a3015;
            border-radius: 999px;
            padding: 8px 10px;
            font-size: 0.67rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            cursor: pointer;
        }

        .empty {
            text-align: center;
            background: var(--card);
            border-radius: 12px;
            min-height: 50vh;
            display: grid;
            place-items: center;
            color: #64523f;
            padding: 20px;
            border: 1px solid #e5d4bc;
        }

        @media (max-width: 860px) {
            .viewer {
                padding: 0;
            }

            .book-stage {
                min-height: 100svh;
            }

            .page-sheet {
                padding: 5px;
            }
        }

        @media (max-width: 520px) {
            body {
                padding: env(safe-area-inset-top, 0) 0 env(safe-area-inset-bottom, 0);
                align-items: stretch;
            }

            .book-shell {
                width: 100%;
                min-height: 100svh;
                display: flex;
                flex-direction: column;
                position: relative;
            }

            .book-head {
                position: absolute;
                top: calc(env(safe-area-inset-top, 0) + 6px);
                left: 10px;
                right: 10px;
                margin: 0;
                z-index: 20;
                pointer-events: none;
            }

            .viewer {
                border-radius: 0;
                border: 0;
                box-shadow: none;
                padding: 0;
                flex: 1;
                display: flex;
                flex-direction: column;
                background: transparent;
                backdrop-filter: none;
            }

            .viewer::after {
                display: none;
            }

            .viewer-topbar {
                margin: 0;
                padding: 0;
                position: absolute;
                top: calc(env(safe-area-inset-top, 0) + 8px);
                left: 10px;
                right: 10px;
                z-index: 20;
            }

            .viewer-page-chip,
            .viewer-hint {
                color: #fff0d9;
                background: rgba(31, 19, 6, 0.45);
                border-color: rgba(255, 230, 194, 0.45);
                backdrop-filter: blur(4px);
            }

            .book-stage {
                height: 100svh;
                min-height: 100svh;
                aspect-ratio: auto;
                border-radius: 0;
                border: 0;
            }

            .sheet-meta {
                left: 8px;
                right: 8px;
                bottom: 8px;
                padding: 5px 7px;
            }

            .sheet-title {
                font-size: 0.64rem;
            }

            .tap-zone {
                width: 28%;
            }

            .finale-panel {
                left: 8px;
                right: 8px;
                bottom: calc(env(safe-area-inset-bottom, 0) + 8px);
                padding: 9px;
                border-radius: 12px;
            }

            .finale-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="book-shell">
        <?php if (empty($pages)): ?>
            <div class="empty">The menu book is not available yet.</div>
        <?php else: ?>
            <div class="viewer">
                <div class="viewer-topbar">
                    <div class="viewer-page-chip" id="pageBadge">Page 1 of <?php echo count($pages); ?></div>
                    <div class="viewer-hint">Swipe or tap edge</div>
                </div>
                <div class="book-stage" id="bookStage" aria-live="polite">
                    <div class="page-layer is-active" id="pageA"></div>
                    <div class="page-layer" id="pageB"></div>
                    <button class="tap-zone left" id="zonePrev" aria-label="Previous page"></button>
                    <button class="tap-zone right" id="zoneNext" aria-label="Next page"></button>

                    <div class="finale-panel" id="finalePanel">
                        <h3 class="finale-title">Continue Your Journey</h3>
                        <p class="finale-text">Explore hotel offers, dining promos, and partner business highlights from our group.</p>
                        <div class="finale-actions">
                            <button class="finale-btn" type="button">Hotel Offers</button>
                            <button class="finale-btn" type="button">Dining Promo</button>
                            <button class="finale-btn" type="button">Tour Packages</button>
                            <button class="finale-btn" type="button">Partner Deals</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($pages)): ?>
        <script>
            const PAGES = <?php
                            $safePages = [];
                            foreach ($pages as $r) {
                                $safePages[] = [
                                    'title' => (string)($r['title'] ?? ''),
                                    'image' => BASE_URL . '/' . ltrim((string)$r['image_path'], '/'),
                                ];
                            }
                            echo json_encode($safePages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            ?>;

            let current = 0;
            let isAnimating = false;
            let activeLayer = document.getElementById('pageA');
            let passiveLayer = document.getElementById('pageB');
            const stage = document.getElementById('bookStage');
            const prevBtn = document.getElementById('zonePrev');
            const nextBtn = document.getElementById('zoneNext');
            const badge = document.getElementById('pageBadge');
            const finalePanel = document.getElementById('finalePanel');

            function pageHtml(idx) {
                if (idx < 0 || idx >= PAGES.length) {
                    return `
                        <div class="page-sheet" style="display:grid;place-items:center;color:#9a9387;">
                            <div>No page available</div>
                        </div>
                    `;
                }
                const p = PAGES[idx];
                const n = idx + 1;
                const title = (p.title || '').trim() || 'Signature Menu';
                return `
                    <article class="page-sheet">
                        <img src="${p.image}" alt="Menu page ${n}">
                        <div class="sheet-meta">
                            <div class="sheet-title">${title}</div>
                            <div class="sheet-page-number">Page ${n}</div>
                        </div>
                    </article>
                `;
            }

            function updateControls() {
                const pageNumber = current + 1;
                badge.textContent = `Page ${pageNumber} of ${PAGES.length}`;
                prevBtn.disabled = current <= 0 || isAnimating;
                nextBtn.disabled = current >= PAGES.length - 1 || isAnimating;
                finalePanel.classList.toggle('is-visible', current >= PAGES.length - 1);
            }

            function renderInitial() {
                activeLayer.innerHTML = pageHtml(current);
                activeLayer.classList.add('is-active');
                passiveLayer.classList.remove('is-active');
                updateControls();
            }

            function animateTo(targetIndex) {
                if (isAnimating || targetIndex < 0 || targetIndex >= PAGES.length || targetIndex === current) {
                    return;
                }

                isAnimating = true;
                const direction = targetIndex > current ? 1 : -1;

                passiveLayer.innerHTML = pageHtml(targetIndex);
                passiveLayer.classList.add('is-active');
                passiveLayer.style.transform = `translateX(${direction * 108}%) scale(0.98)`;
                passiveLayer.style.opacity = '0.4';
                activeLayer.style.transform = 'translateX(0) scale(1)';
                activeLayer.style.opacity = '1';

                requestAnimationFrame(() => {
                    const duration = 560;
                    const easing = 'cubic-bezier(0.22, 1, 0.36, 1)';

                    passiveLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                    activeLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;

                    passiveLayer.style.transform = 'translateX(0) scale(1)';
                    passiveLayer.style.opacity = '1';

                    activeLayer.style.transform = `translateX(${direction * -20}%) scale(0.985)`;
                    activeLayer.style.opacity = '0.2';

                    window.setTimeout(() => {
                        const oldActive = activeLayer;
                        activeLayer = passiveLayer;
                        passiveLayer = oldActive;

                        passiveLayer.classList.remove('is-active');
                        passiveLayer.style.transition = '';
                        passiveLayer.style.transform = 'translateX(0) scale(1)';
                        passiveLayer.style.opacity = '1';

                        activeLayer.style.transition = '';
                        activeLayer.style.transform = 'translateX(0) scale(1)';
                        activeLayer.style.opacity = '1';

                        current = targetIndex;
                        isAnimating = false;
                        updateControls();
                    }, duration + 24);
                });
            }

            prevBtn.addEventListener('click', () => animateTo(current - 1));
            nextBtn.addEventListener('click', () => animateTo(current + 1));

            document.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') {
                    animateTo(current - 1);
                }
                if (e.key === 'ArrowRight') {
                    animateTo(current + 1);
                }
            });

            function resetLayerStyles(layer) {
                layer.style.transition = '';
                layer.style.transformOrigin = 'center center';
                layer.style.transform = 'translateX(0) scale(1)';
                layer.style.opacity = '1';
            }

            let dragStartX = 0;
            let dragDx = 0;
            let isDragging = false;
            let dragPreviewDirection = 0;

            function dragThresholdPx() {
                return Math.max(48, Math.min(130, stage.clientWidth * 0.18));
            }

            function updatePreviewForDirection(direction) {
                if (direction === 0 || direction === dragPreviewDirection) {
                    return;
                }
                const previewIndex = current + direction;
                if (previewIndex < 0 || previewIndex >= PAGES.length) {
                    dragPreviewDirection = 0;
                    passiveLayer.classList.remove('is-active');
                    return;
                }
                dragPreviewDirection = direction;
                passiveLayer.innerHTML = pageHtml(previewIndex);
                passiveLayer.classList.add('is-active');
            }

            function onDragMove(clientX) {
                if (!isDragging || isAnimating) {
                    return;
                }

                dragDx = clientX - dragStartX;
                const direction = dragDx < 0 ? 1 : (dragDx > 0 ? -1 : 0);
                const previewIndex = current + direction;
                if (direction === 0 || previewIndex < 0 || previewIndex >= PAGES.length) {
                    dragPreviewDirection = 0;
                    passiveLayer.classList.remove('is-active');
                    activeLayer.style.transform = `translateX(${dragDx * 0.18}px) scale(0.995)`;
                    return;
                }

                updatePreviewForDirection(direction);

                const stageWidth = Math.max(stage.clientWidth, 1);
                const progress = Math.min(Math.abs(dragDx) / stageWidth, 1);
                const offsetPercent = (dragDx / stageWidth) * 100;
                const pullPx = Math.abs(dragDx);

                activeLayer.style.transition = 'none';
                passiveLayer.style.transition = 'none';

                const isNext = direction > 0;
                const rotateDeg = Math.min(38, (pullPx / stageWidth) * 42) * (isNext ? -1 : 1);
                activeLayer.style.transformOrigin = isNext ? 'left center' : 'right center';
                activeLayer.style.transform = `perspective(1600px) translateX(${offsetPercent * 0.72}%) rotateY(${rotateDeg}deg) scale(${1 - progress * 0.055})`;
                activeLayer.style.opacity = String(1 - progress * 0.42);

                const enterStart = isNext ? 32 : -32;
                passiveLayer.style.transformOrigin = isNext ? 'right center' : 'left center';
                passiveLayer.style.transform = `perspective(1600px) translateX(${enterStart + (offsetPercent * 0.4)}%) rotateY(${isNext ? 6 : -6}deg) scale(${0.978 + progress * 0.022})`;
                passiveLayer.style.opacity = String(0.3 + progress * 0.7);
            }

            function completeDragTransition(target, direction) {
                isAnimating = true;
                const duration = 310;
                const easing = 'cubic-bezier(0.2, 0.9, 0.24, 1)';
                const isNext = direction > 0;

                activeLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                passiveLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;

                activeLayer.style.transformOrigin = isNext ? 'left center' : 'right center';
                activeLayer.style.transform = `perspective(1600px) translateX(${isNext ? -88 : 88}%) rotateY(${isNext ? -48 : 48}deg) scale(0.94)`;
                activeLayer.style.opacity = '0.02';

                passiveLayer.style.transformOrigin = 'center center';
                passiveLayer.style.transform = 'perspective(1600px) translateX(0) rotateY(0deg) scale(1)';
                passiveLayer.style.opacity = '1';

                window.setTimeout(() => {
                    const oldActive = activeLayer;
                    activeLayer = passiveLayer;
                    passiveLayer = oldActive;

                    passiveLayer.classList.remove('is-active');
                    resetLayerStyles(passiveLayer);
                    resetLayerStyles(activeLayer);

                    current = target;
                    isAnimating = false;
                    updateControls();
                }, duration + 18);
            }

            function endDrag() {
                if (!isDragging || isAnimating) {
                    return;
                }

                isDragging = false;
                const direction = dragDx < 0 ? 1 : (dragDx > 0 ? -1 : 0);
                const canNavigate = direction !== 0 && (current + direction) >= 0 && (current + direction) < PAGES.length;
                const passThreshold = Math.abs(dragDx) > dragThresholdPx();

                if (canNavigate && passThreshold) {
                    const target = current + direction;
                    dragDx = 0;
                    dragPreviewDirection = 0;
                    completeDragTransition(target, direction);
                    return;
                }

                const duration = 360;
                const easing = 'cubic-bezier(0.2, 0.9, 0.2, 1)';
                activeLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                activeLayer.style.transform = 'translateX(0) scale(1)';
                activeLayer.style.opacity = '1';

                passiveLayer.style.transition = `transform ${duration}ms ${easing}, opacity ${duration}ms ${easing}`;
                passiveLayer.style.transform = 'translateX(0) scale(1)';
                passiveLayer.style.opacity = '0';

                window.setTimeout(() => {
                    passiveLayer.classList.remove('is-active');
                    resetLayerStyles(activeLayer);
                    resetLayerStyles(passiveLayer);
                }, duration + 16);

                dragDx = 0;
                dragPreviewDirection = 0;
            }

            stage.addEventListener('pointerdown', (e) => {
                if (isAnimating || e.pointerType === 'mouse' && e.button !== 0) {
                    return;
                }
                isDragging = true;
                dragStartX = e.clientX;
                dragDx = 0;
                dragPreviewDirection = 0;
                stage.setPointerCapture(e.pointerId);
            });

            stage.addEventListener('pointermove', (e) => {
                onDragMove(e.clientX);
            });

            stage.addEventListener('pointerup', () => {
                endDrag();
            });

            stage.addEventListener('pointercancel', () => {
                endDrag();
            });

            renderInitial();
        </script>
    <?php endif; ?>
</body>

</html>