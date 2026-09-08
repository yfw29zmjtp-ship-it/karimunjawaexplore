<?php

/**
 * Sunsea - Setting Website Publik
 * Mengelola konten website karimunjawaexplore.com (hero, tentang kami, galeri, blog).
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once 'db-helper.php';

$auth = new Auth();
$auth->requireLogin();
$pdo = getSunseaConnection();
sunseaEnsureWebsiteContentSchema($pdo);

$tab = $_GET['tab'] ?? 'hero';
$flashMsg = '';
$flashType = '';

function wsUploadImage(string $fileField, string $destDir, string $prefix): array
{
    if (empty($_FILES[$fileField]['tmp_name'])) return ['', ''];
    if ((int)($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['', "Upload gambar gagal (kode error: " . $_FILES[$fileField]['error'] . ")."];
    }
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $ext = strtolower(pathinfo($_FILES[$fileField]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'ico'])) {
        return ['', 'Format gambar harus PNG, JPG, JPEG, WEBP, GIF, atau ICO.'];
    }
    $fname = $prefix . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$fileField]['tmp_name'], $destDir . $fname)) {
        return ['', 'Gagal menyimpan file ke server (cek permission folder uploads/sunsea).'];
    }
    return ['uploads/sunsea/website/' . $fname, ''];
}

$uploadDir = __DIR__ . '/../../uploads/sunsea/website/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = $_POST['tab'] ?? 'hero';

    if ($postTab === 'hero') {
        sunseaSetSetting($pdo, 'website_hero_title', trim($_POST['hero_title'] ?? ''));
        sunseaSetSetting($pdo, 'website_hero_subtitle', trim($_POST['hero_subtitle'] ?? ''));

        [$bgPath, $bgErr] = wsUploadImage('hero_bg', $uploadDir, 'hero_bg');
        if ($bgErr) {
            $flashMsg = 'Konten beranda disimpan, tetapi background gagal diupload: ' . $bgErr;
            $flashType = 'error';
        } else {
            if ($bgPath !== '') {
                sunseaSetSetting($pdo, 'website_hero_bg', $bgPath);
            }
            if (($_POST['remove_hero_bg'] ?? '') === '1' && $bgPath === '') {
                sunseaSetSetting($pdo, 'website_hero_bg', '');
            }
            $flashMsg = 'Konten beranda berhasil disimpan.';
            $flashType = 'success';
        }
        $tab = 'hero';
    }

    if ($postTab === 'about') {
        sunseaSetSetting($pdo, 'website_about_p1', trim($_POST['about_p1'] ?? ''));
        sunseaSetSetting($pdo, 'website_about_p2', trim($_POST['about_p2'] ?? ''));
        sunseaSetSetting($pdo, 'website_about_visi', trim($_POST['about_visi'] ?? ''));
        sunseaSetSetting($pdo, 'website_about_misi', trim($_POST['about_misi'] ?? ''));
        sunseaSetSetting($pdo, 'website_about_nilai', trim($_POST['about_nilai'] ?? ''));
        $flashMsg = 'Konten Tentang Kami berhasil disimpan.';
        $flashType = 'success';
        $tab = 'about';
    }

    if ($postTab === 'gallery_add') {
        [$imgPath, $imgErr] = wsUploadImage('gallery_image', $uploadDir, 'gallery');
        if ($imgErr) {
            $flashMsg = $imgErr;
            $flashType = 'error';
        } elseif ($imgPath === '') {
            $flashMsg = 'Pilih file gambar terlebih dahulu.';
            $flashType = 'error';
        } else {
            $stmt = $pdo->prepare("INSERT INTO website_gallery (image_path, caption, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$imgPath, trim($_POST['caption'] ?? ''), (int)($_POST['sort_order'] ?? 0)]);
            $flashMsg = 'Foto galeri berhasil ditambahkan.';
            $flashType = 'success';
        }
        $tab = 'gallery';
    }

    if ($postTab === 'gallery_delete') {
        $gid = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT image_path FROM website_gallery WHERE id = ?");
        $stmt->execute([$gid]);
        $old = $stmt->fetchColumn();
        if ($old && file_exists(__DIR__ . '/../../' . $old)) @unlink(__DIR__ . '/../../' . $old);
        $pdo->prepare("DELETE FROM website_gallery WHERE id = ?")->execute([$gid]);
        $flashMsg = 'Foto galeri dihapus.';
        $flashType = 'success';
        $tab = 'gallery';
    }

    if ($postTab === 'gallery_toggle') {
        $gid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE website_gallery SET is_active = 1 - is_active WHERE id = ?")->execute([$gid]);
        $tab = 'gallery';
    }

    if ($postTab === 'blog_save') {
        $blogId = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $isPublished = isset($_POST['is_published']) ? 1 : 0;

        if ($title === '') {
            $flashMsg = 'Judul artikel wajib diisi.';
            $flashType = 'error';
        } else {
            $slugBase = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            [$coverPath, $coverErr] = wsUploadImage('cover_image', $uploadDir, 'blog');
            if ($coverErr) {
                $flashMsg = $coverErr;
                $flashType = 'error';
            } else {
                if ($blogId > 0) {
                    if ($coverPath !== '') {
                        $pdo->prepare("UPDATE website_blog SET title=?, excerpt=?, content=?, is_published=?, cover_image=? WHERE id=?")
                            ->execute([$title, $excerpt, $content, $isPublished, $coverPath, $blogId]);
                    } else {
                        $pdo->prepare("UPDATE website_blog SET title=?, excerpt=?, content=?, is_published=? WHERE id=?")
                            ->execute([$title, $excerpt, $content, $isPublished, $blogId]);
                    }
                    $flashMsg = 'Artikel berhasil diperbarui.';
                } else {
                    $slug = $slugBase . '-' . substr(md5(uniqid()), 0, 6);
                    $pdo->prepare("INSERT INTO website_blog (title, slug, excerpt, content, cover_image, is_published) VALUES (?,?,?,?,?,?)")
                        ->execute([$title, $slug, $excerpt, $content, $coverPath, $isPublished]);
                    $flashMsg = 'Artikel baru berhasil ditambahkan.';
                }
                $flashType = 'success';
            }
        }
        $tab = 'blog';
    }

    if ($postTab === 'blog_delete') {
        $bid = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT cover_image FROM website_blog WHERE id = ?");
        $stmt->execute([$bid]);
        $old = $stmt->fetchColumn();
        if ($old && file_exists(__DIR__ . '/../../' . $old)) @unlink(__DIR__ . '/../../' . $old);
        $pdo->prepare("DELETE FROM website_blog WHERE id = ?")->execute([$bid]);
        $flashMsg = 'Artikel dihapus.';
        $flashType = 'success';
        $tab = 'blog';
    }

    if ($postTab === 'branding') {
        [$sysFaviconPath, $sysFaviconErr] = wsUploadImage('system_favicon', $uploadDir, 'favicon_system');
        [$webFaviconPath, $webFaviconErr] = wsUploadImage('website_favicon', $uploadDir, 'favicon_website');
        $brandingErr = trim($sysFaviconErr . ' ' . $webFaviconErr);
        if ($sysFaviconPath !== '') sunseaSetSetting($pdo, 'system_favicon', $sysFaviconPath);
        if ($webFaviconPath !== '') sunseaSetSetting($pdo, 'website_favicon', $webFaviconPath);
        if ($brandingErr) {
            $flashMsg = $brandingErr;
            $flashType = 'error';
        } else {
            $flashMsg = 'Pengaturan favicon berhasil disimpan.';
            $flashType = 'success';
        }
        $tab = 'branding';
    }
}

$heroTitle = sunseaSetting($pdo, 'website_hero_title', 'Jelajahi Keindahan Karimunjawa Bersama Kami');
$heroSubtitle = sunseaSetting($pdo, 'website_hero_subtitle', 'Paket wisata open trip & private trip, island hopping, penginapan, hingga transport laut/darat — kami urus, Anda tinggal menikmati liburan.');
$heroBg = sunseaSetting($pdo, 'website_hero_bg', '');
$aboutP1 = sunseaSetting($pdo, 'website_about_p1', '');
$aboutP2 = sunseaSetting($pdo, 'website_about_p2', '');
$aboutVisi = sunseaSetting($pdo, 'website_about_visi', 'Menjadi mitra wisata terpercaya bagi setiap tamu yang berkunjung ke Karimunjawa.');
$aboutMisi = sunseaSetting($pdo, 'website_about_misi', 'Memberikan pelayanan aman, nyaman, dan harga yang transparan untuk semua tamu.');
$aboutNilai = sunseaSetting($pdo, 'website_about_nilai', 'Kejujuran, keramahan, dan tanggung jawab dalam setiap perjalanan.');
$systemFavicon = sunseaSetting($pdo, 'system_favicon', '');
$websiteFavicon = sunseaSetting($pdo, 'website_favicon', '');

$galleryItems = $pdo->query("SELECT * FROM website_gallery ORDER BY sort_order ASC, id DESC")->fetchAll();
$blogItems = $pdo->query("SELECT * FROM website_blog ORDER BY created_at DESC")->fetchAll();
$editBlog = null;
if (($_GET['edit'] ?? '') !== '' && $tab === 'blog') {
    $stmt = $pdo->prepare("SELECT * FROM website_blog WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editBlog = $stmt->fetch();
}

$pageTitle = 'Setting Website';
$activePage = 'website_settings';
include 'layout-header.php';
?>

<?php if ($flashMsg): ?>
    <div style="padding:12px 16px;margin-bottom:16px;border-radius:6px;font-weight:500;
    background:<?php echo $flashType === 'success' ? '#e6f7f0' : '#fee'; ?>;
    border:1px solid <?php echo $flashType === 'success' ? '#34d399' : '#f88'; ?>;
    color:<?php echo $flashType === 'success' ? '#065f46' : '#c33'; ?>;">
        <?php echo htmlspecialchars($flashMsg); ?>
    </div>
<?php endif; ?>

<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #e0e7ef;flex-wrap:wrap;">
    <a href="?tab=hero" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'hero' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        🏠 Beranda
    </a>
    <a href="?tab=about" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'about' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        ℹ️ Tentang Kami
    </a>
    <a href="?tab=gallery" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'gallery' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        🖼️ Galeri
    </a>
    <a href="?tab=blog" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'blog' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        📝 Blog
    </a>
    <a href="?tab=branding" style="padding:10px 24px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;
        <?php echo $tab === 'branding' ? 'border-bottom-color:#0C4A6E;color:#0C4A6E;' : 'color:#666;'; ?>">
        🎨 Branding &amp; Favicon
    </a>
    <a href="settings.php" style="padding:10px 24px;font-weight:600;text-decoration:none;color:#666;">
        ⚙️ Pengaturan Sistem &rarr;
    </a>
</div>

<div style="background:#eef6fb;border:1px solid #bfdbfe;border-radius:8px;padding:10px 16px;margin-bottom:18px;font-size:13px;color:#0c4a6e;">
    Halaman ini mengatur konten yang tampil di website publik <strong>karimunjawaexplore.com</strong> (Beranda, Tentang Kami, Galeri, Blog). Paket Wisata dikelola di menu <a href="packages.php" style="color:#0c4a6e;">Paket Wisata</a>.
</div>

<?php if ($tab === 'hero'): ?>
    <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;max-width:720px;">
        <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">🏠 Hero Beranda</div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
            <input type="hidden" name="tab" value="hero">
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Judul Utama</label>
                <input type="text" name="hero_title" value="<?php echo htmlspecialchars($heroTitle); ?>"
                    style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Sub Judul / Deskripsi</label>
                <textarea name="hero_subtitle" rows="3"
                    style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($heroSubtitle); ?></textarea>
            </div>
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Background Header (gambar)</label>
                <?php if ($heroBg): ?>
                    <img src="<?php echo htmlspecialchars(sunseaAssetUrl($heroBg)); ?>" alt="" style="width:100%;max-height:140px;object-fit:cover;border-radius:6px;margin-bottom:8px;display:block;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#b91c1c;margin-bottom:8px;">
                        <input type="checkbox" name="remove_hero_bg" value="1"> Hapus background (kembali ke warna default)
                    </label>
                <?php endif; ?>
                <input type="file" name="hero_bg" accept="image/*" style="width:100%;font-size:13px;">
                <div style="font-size:11px;color:#888;margin-top:4px;">Kosongkan kalau tidak ingin ganti. Ukuran disarankan lebar &ge; 1600px.</div>
            </div>
            <div>
                <button type="submit" style="padding:10px 24px;background:#0C4A6E;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">💾 Simpan</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'branding'): ?>
    <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;max-width:720px;">
        <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">🎨 Favicon</div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:18px;">
            <input type="hidden" name="tab" value="branding">
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Favicon Sistem / Admin</label>
                <?php if ($systemFavicon): ?>
                    <img src="<?php echo htmlspecialchars(sunseaAssetUrl($systemFavicon)); ?>" alt="" style="width:32px;height:32px;object-fit:contain;margin-bottom:8px;display:block;">
                <?php endif; ?>
                <input type="file" name="system_favicon" accept="image/*,.ico" style="width:100%;font-size:13px;">
                <div style="font-size:11px;color:#888;margin-top:4px;">Tampil di tab browser saat mengakses sistem/admin (dashboard, booking, invoice, dll).</div>
            </div>
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Favicon Website Publik</label>
                <?php if ($websiteFavicon): ?>
                    <img src="<?php echo htmlspecialchars(sunseaAssetUrl($websiteFavicon)); ?>" alt="" style="width:32px;height:32px;object-fit:contain;margin-bottom:8px;display:block;">
                <?php endif; ?>
                <input type="file" name="website_favicon" accept="image/*,.ico" style="width:100%;font-size:13px;">
                <div style="font-size:11px;color:#888;margin-top:4px;">Tampil di tab browser saat mengakses karimunjawaexplore.com (Beranda, Paket Wisata, Blog, dll).</div>
            </div>
            <div>
                <button type="submit" style="padding:10px 24px;background:#0C4A6E;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">💾 Simpan</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'about'): ?>
    <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;max-width:720px;">
        <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">ℹ️ Tentang Kami</div>
        <form method="POST" style="display:flex;flex-direction:column;gap:14px;">
            <input type="hidden" name="tab" value="about">
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Paragraf 1</label>
                <textarea name="about_p1" rows="3" placeholder="Kosongkan untuk pakai teks default sistem."
                    style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($aboutP1); ?></textarea>
            </div>
            <div>
                <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Paragraf 2</label>
                <textarea name="about_p2" rows="3" placeholder="Kosongkan untuk pakai teks default sistem."
                    style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($aboutP2); ?></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">🎯 Visi</label>
                    <textarea name="about_visi" rows="3"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($aboutVisi); ?></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">🤝 Misi</label>
                    <textarea name="about_misi" rows="3"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($aboutMisi); ?></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">⭐ Nilai</label>
                    <textarea name="about_nilai" rows="3"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:13px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($aboutNilai); ?></textarea>
                </div>
            </div>
            <div>
                <button type="submit" style="padding:10px 24px;background:#0C4A6E;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">💾 Simpan</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'gallery'): ?>
    <div style="display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start;">
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">➕ Tambah Foto</div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="tab" value="gallery_add">
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">File Gambar</label>
                    <input type="file" name="gallery_image" accept="image/*" required style="width:100%;font-size:13px;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Keterangan</label>
                    <input type="text" name="caption" style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Urutan</label>
                    <input type="number" name="sort_order" value="0" style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <button type="submit" style="padding:10px 24px;background:#0C4A6E;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">💾 Tambah</button>
            </form>
        </div>
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">🖼️ Foto Galeri (<?php echo count($galleryItems); ?>)</div>
            <?php if (!$galleryItems): ?>
                <div style="color:#888;font-size:13px;">Belum ada foto. Tambahkan lewat form di samping.</div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;">
                    <?php foreach ($galleryItems as $g): ?>
                        <div style="border:1px solid #e0e7ef;border-radius:8px;overflow:hidden;<?php echo $g['is_active'] ? '' : 'opacity:.45;'; ?>">
                            <img src="<?php echo htmlspecialchars(sunseaAssetUrl($g['image_path'])); ?>" alt="" style="width:100%;height:100px;object-fit:cover;display:block;">
                            <div style="padding:8px;">
                                <div style="font-size:12px;color:#444;min-height:16px;"><?php echo htmlspecialchars($g['caption'] ?: '—'); ?></div>
                                <div style="display:flex;gap:6px;margin-top:6px;">
                                    <form method="POST" onsubmit="return confirm('Nonaktifkan/aktifkan foto ini?');">
                                        <input type="hidden" name="tab" value="gallery_toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                                        <button type="submit" style="font-size:11px;padding:4px 8px;border:1px solid #ccc;border-radius:4px;background:#f8fafc;cursor:pointer;">
                                            <?php echo $g['is_active'] ? 'Sembunyikan' : 'Tampilkan'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Hapus foto ini?');">
                                        <input type="hidden" name="tab" value="gallery_delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$g['id']; ?>">
                                        <button type="submit" style="font-size:11px;padding:4px 8px;border:1px solid #fca5a5;color:#b91c1c;border-radius:4px;background:#fff;cursor:pointer;">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($tab === 'blog'): ?>
    <div style="display:grid;grid-template-columns:360px 1fr;gap:18px;align-items:start;">
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">
                <?php echo $editBlog ? '✏️ Edit Artikel' : '➕ Tambah Artikel'; ?>
            </div>
            <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
                <input type="hidden" name="tab" value="blog_save">
                <input type="hidden" name="id" value="<?php echo (int)($editBlog['id'] ?? 0); ?>">
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Judul *</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($editBlog['title'] ?? ''); ?>" required
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Ringkasan</label>
                    <textarea name="excerpt" rows="2"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($editBlog['excerpt'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Isi Artikel</label>
                    <textarea name="content" rows="6"
                        style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-family:inherit;font-size:14px;box-sizing:border-box;resize:vertical;"><?php echo htmlspecialchars($editBlog['content'] ?? ''); ?></textarea>
                </div>
                <div>
                    <label style="display:block;margin-bottom:5px;font-weight:600;font-size:13px;">Cover Gambar<?php echo $editBlog ? ' (kosongkan jika tidak ganti)' : ''; ?></label>
                    <input type="file" name="cover_image" accept="image/*" style="width:100%;font-size:13px;">
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;">
                    <input type="checkbox" name="is_published" <?php echo (!$editBlog || $editBlog['is_published']) ? 'checked' : ''; ?>>
                    Tampilkan di website (Published)
                </label>
                <div style="display:flex;gap:8px;">
                    <button type="submit" style="padding:10px 24px;background:#0C4A6E;color:white;border:none;border-radius:5px;font-weight:700;cursor:pointer;font-size:14px;">💾 Simpan</button>
                    <?php if ($editBlog): ?>
                        <a href="?tab=blog" style="padding:10px 24px;border:1px solid #ccc;border-radius:5px;font-weight:600;font-size:14px;color:#444;text-decoration:none;">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div style="background:#fff;border:1px solid #dde5ef;border-radius:8px;padding:20px;">
            <div style="font-size:16px;font-weight:700;color:#0C4A6E;margin-bottom:16px;">📝 Daftar Artikel (<?php echo count($blogItems); ?>)</div>
            <?php if (!$blogItems): ?>
                <div style="color:#888;font-size:13px;">Belum ada artikel. Tambahkan lewat form di samping.</div>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid #e0e7ef;">
                            <th style="padding:8px 6px;">Judul</th>
                            <th style="padding:8px 6px;">Status</th>
                            <th style="padding:8px 6px;">Tanggal</th>
                            <th style="padding:8px 6px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blogItems as $b): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px 6px;font-weight:600;"><?php echo htmlspecialchars($b['title']); ?></td>
                                <td style="padding:8px 6px;">
                                    <?php echo $b['is_published']
                                        ? '<span style="color:#065f46;background:#e6f7f0;padding:2px 8px;border-radius:10px;font-size:11px;">Published</span>'
                                        : '<span style="color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:10px;font-size:11px;">Draft</span>'; ?>
                                </td>
                                <td style="padding:8px 6px;color:#666;"><?php echo date('d/m/Y', strtotime($b['created_at'])); ?></td>
                                <td style="padding:8px 6px;white-space:nowrap;">
                                    <a href="?tab=blog&edit=<?php echo (int)$b['id']; ?>" style="font-size:12px;color:#0C4A6E;text-decoration:none;margin-right:10px;">Edit</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus artikel ini?');">
                                        <input type="hidden" name="tab" value="blog_delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                                        <button type="submit" style="font-size:12px;color:#b91c1c;background:none;border:none;cursor:pointer;text-decoration:underline;padding:0;">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include 'layout-footer.php'; ?>