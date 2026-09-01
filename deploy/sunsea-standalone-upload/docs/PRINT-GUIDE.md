# Panduan Print PDF Elegan untuk Sistem Multi-Bisnis

## Fitur
✅ **Elegan & Profesional** - Header dengan logo, company name, dan timestamp
✅ **Compact** - Layout form dioptimalkan, hanya summary cards yang penting  
✅ **Berlogo** - Otomatis ambil logo dari settings (company_logo)
✅ **Multi-Bisnis** - Bekerja untuk semua business tanpa perlu konfigurasi
✅ **A4 Format** - Siap cetak ke PDF dengan ukuran A4
✅ **Signature Lines** - Footer dengan tempat tanda tangan

## Cara Menggunakan

### 1. Include Print Helper
```php
require_once '../../includes/print-helper.php';
```

### 2. Tambah Print CSS
Di dalam halaman sebelum header:
```php
echo getPrintCSS();
```

### 3. Buat Print Section
```php
<div style="display: none;" id="printSection">
    <?php 
    echo printHeader($db, $displayCompanyName, BUSINESS_ICON, BUSINESS_TYPE, 'TITLE', 'Period Info');
    ?>
    <!-- Your content here -->
    <?php echo printFooter(); ?>
</div>
```

### 4. Add Print Link/Button
```php
<a href="index.php?print=1" class="btn btn-secondary">
    <i data-feather="printer"></i> Cetak PDF
</a>
```

### 5. Add JavaScript Handler
```php
<script>
    const isPrint = new URLSearchParams(window.location.search).has('print');
    if (isPrint) {
        document.getElementById('screenSection').innerHTML = document.getElementById('printSection').innerHTML;
        document.getElementById('printSection').remove();
        setTimeout(() => window.print(), 500);
    } else {
        document.getElementById('printSection').style.display = 'none';
    }
</script>
```

## Modul yang Sudah Diterapkan
- ✅ Buku Kas (modules/cashbook/index.php)

## Template Standar
```html
<!-- Header -->
[Logo] [Company Name + Title] [Print Date]

<!-- Content -->
[Summary Cards] | [Table Data]

<!-- Footer -->
[Checked By] [Approved By] [Approval Date]

<!-- Notes -->
[Document verification note]
```

## Kustomisasi

### Custom Summary Cards
```php
<div class="print-summary">
    <div class="print-summary-card">
        <div class="print-summary-label">Your Label</div>
        <div class="print-summary-value your-class"><?php echo $value; ?></div>
    </div>
</div>
```

Available value classes: `.income`, `.expense`, `.balance`

### Custom Header
Gunakan `printHeader()` dengan parameter:
- `$db` - Database instance
- `$displayCompanyName` - Nama perusahaan
- `$businessIcon` - Icon/emoji
- `$businessType` - Tipe bisnis (hotel, cafe, dll)
- `$title` - Judul dokumen
- `$period` - Info periode (opsional)

### Custom Footer
Gunakan `printFooter($userName)` untuk footer dengan nama user custom

## Media Print CSS Classes
- `.print-header` - Header container
- `.print-summary` - Summary cards grid
- `.print-summary-card` - Individual card
- `.print-title` - Document title
- `.print-company-name` - Company name
- `.print-footer` - Footer with signatures
- `.print-notes` - Document notes

## Browser Support
✅ Chrome/Chromium
✅ Firefox
✅ Edge
✅ Safari

## Tips
1. Test print dengan Print Preview (Ctrl+P) terlebih dahulu
2. Pastikan logo tersimpan di path yang benar
3. Gunakan formatCurrency() untuk format rupiah
4. Ganti "Pimpinan" di footer dengan nama yang sesuai jika perlu
