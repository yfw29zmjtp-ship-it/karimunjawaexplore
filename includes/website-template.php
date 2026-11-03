<?php

/**
 * Mesin template website publik.
 * Satu kode untuk semua tampilan: template dipilih oleh developer (setting `web_template`),
 * klien tidak bisa menggantinya sendiri. Tiap template = folder di website-templates/<slug>/ berisi
 *   template.json   nama, deskripsi
 *   theme.css       variabel warna/font/sudut + gaya khas (dimuat setelah assets/website/style.css)
 *   layout-header.php / layout-footer.php   (opsional) menggantikan header/footer bawaan
 * Bagian yang tidak ada di template memakai bawaan, jadi template baru bisa dibuat bertahap.
 */

const WE_TEMPLATE_DEFAULT = 'ocean';

function weTemplateDir(): string
{
    return dirname(__DIR__) . '/website-templates';
}

/** Daftar template yang tersedia: slug => [name, description]. */
function weTemplateList(): array
{
    $out = [];
    foreach (glob(weTemplateDir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) continue;
        $meta = is_file("$dir/template.json") ? (json_decode((string)file_get_contents("$dir/template.json"), true) ?: []) : [];
        $out[$slug] = ['name' => (string)($meta['name'] ?? ucfirst($slug)), 'description' => (string)($meta['description'] ?? '')];
    }
    ksort($out);
    return $out;
}

/** Slug template aktif (setting web_template, kembali ke bawaan bila tidak valid). */
function weTemplate(PDO $pdo): string
{
    static $slug = null;
    if ($slug === null) {
        $v = function_exists('sunseaSetting') ? sunseaSetting($pdo, 'web_template', WE_TEMPLATE_DEFAULT) : WE_TEMPLATE_DEFAULT;
        $slug = is_dir(weTemplateDir() . '/' . $v) && preg_match('/^[a-z0-9-]+$/', $v) ? $v : WE_TEMPLATE_DEFAULT;
    }
    return $slug;
}

/** Path file milik template aktif, atau null bila template tidak menyediakannya. */
function weTemplateFile(PDO $pdo, string $file): ?string
{
    $p = weTemplateDir() . '/' . weTemplate($pdo) . '/' . $file;
    return is_file($p) ? $p : null;
}

/** URL theme.css template aktif (sunseaAssetUrl menambah versi). */
function weTemplateCssUrl(PDO $pdo): string
{
    $slug = weTemplate($pdo);
    $p = weTemplateDir() . "/$slug/theme.css";
    return is_file($p) ? 'website-templates/' . $slug . '/theme.css' : '';
}
