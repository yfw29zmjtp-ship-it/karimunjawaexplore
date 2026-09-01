# Developer Panel - Narayana Karimunjawa

## Overview
Developer panel untuk mengelola website booking Narayana Karimunjawa. Panel ini terpisah dari website public dan hanya bisa diakses oleh user dengan role Admin/Developer.

## Struktur File

```
developer/
├── includes/
│   ├── dev_auth.php          # Authentication handler
│   ├── header.php            # Layout header dengan sidebar
│   └── footer.php            # Layout footer
├── index.php                 # Dashboard utama
├── web-settings.php          # Pengaturan website (PERLU UPDATE)
├── login.php                 # Halam login
└── logout.php                # Logout handler
```

## Akses Developer Panel

### URL Akses
- **Local**: `http://localhost:8081/narayanakarimunjawa/developer/`
- **Production**: `https://narayanakarimunjawa.com/developer/`

### Login Credentials
Gunakan user dengan role **Admin** atau **Super Admin** dari database `adf_narayana_hotel`:
- Username: `admin` (sesuaikan dengan user di DB)
- Password: password user tersebut

## Fitur Tersedia

### ✅ Dashboard (`index.php`)
- Statistik room dan booking
- Recent online bookings
- Quick actions ke berbagai menu

### ✅ Web Settings (`web-settings.php`)
**NOTE**: File ini perlu update manual karena menggunakan Database class dari adf_system

Yang bisa di-setting:
1. **General** - Status website, nama, tagline, deskripsi
2. **Hero Section** - Text di homepage banner
3. **Contact** - WhatsApp, Instagram, Email, Alamat
4. **Room Descriptions** - Deskripsi untuk setiap tipe room
5. **SEO** - Meta title, description, keywords
6. **Appearance** - Warna primary dan accent
7. **Booking** - Max days, min stay, policy

## UPDATE YANG DIPERLUKAN untuk web-settings.php

File `web-settings.php` masih menggunakan `$db->query()` dan `$db->fetchAll()` dari class Database adf_system.

Perlu diganti semua dengan PDO native:

### **Pattern yang harus diganti:**

**Dari:**
```php
$db->query("INSERT INTO settings ...", [params]);
```

**Jadi:**
```php
$stmt = $pdo->prepare("INSERT INTO settings ...");
$stmt->execute([params]);
```

**Dari:**
```php
$rows = $db->fetchAll("SELECT ...", [params]);
```

**Jadi:**
```php
$stmt = $pdo->prepare("SELECT ...");
$stmt->execute([params]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### Section yang perlu diupdate:
- [x] Header & initialization (SUDAH)
- [ ] Line ~75-85: Load current values  
- [ ] Line ~90-100: save_general action
- [ ] Line ~105-115: save_hero action
- [ ] Line ~120-130: save_contact action
- [ ] Line ~135-145: save_rooms action
- [ ] Line ~150-160: save_seo action
- [ ] Line ~165-175: save_appearance action
- [ ] Line ~180-190: save_booking action
- [ ] Line ~195-210: Get hotel database stats

## Keamanan

### Authentication
- Menggunakan session PHP
- Password di-hash dengan `password_hash()` dan `password_verify()`
- Hanya role Admin/Developer yang bisa akses

### Best Practices
- Selalu gunakan prepared statements untuk query
- Validasi semua input dari user
- Sanitize output dengan `htmlspecialchars()`

## Database Schema

Panel ini menggunakan database **adf_narayana_hotel** untuk:
- `users` - User authentication
- `roles` - Role management
- `settings` - Website configuration
- `rooms`, `room_types` - Room data (read-only untuk stats)
- `bookings` - Booking data (read-only untuk stats)

## Deployment

### Local Development
1. Pastikan XAMPP running
2. Akses `http://localhost:8081/narayanakarimunjawa/developer/`
3. Login dengan user admin

### Production (cPanel)
1. Push code ke GitHub
2. Pull di hosting via Git Version Control
3. Akses `https://narayanakarimunjawa.com/developer/`
4. Pastikan file permission correct (755 untuk folder, 644 untuk file PHP)

## TODO

- [ ] Selesaikan update `web-settings.php` untuk menggunakan PDO native
- [ ] Tambahkan menu untuk manage fasilitas hotel
- [ ] Tambahkan menu untuk manage gallery/photos
- [ ] Integrasi dengan dual database (sistem + website DB)
- [ ] Tambahkan audit log untuk perubahan settings

## Support

Jika ada error, cek:
1. `error_log` di PHP (`..\logs\` atau sesuai `php.ini`)
2. Browser developer console untuk JavaScript errors
3. Database connection di `dev_auth.php`

---

**Created**: February 22, 2026  
**Version**: 1.0.0  
**Author**: Developer Team
