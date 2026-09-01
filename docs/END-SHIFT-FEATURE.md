# End Shift Feature - Complete Documentation

## 📋 Overview

**End Shift** adalah fitur yang memungkinkan staff untuk secara otomatis:
1. ✅ Logout dari sistem
2. 📊 Menampilkan laporan harian transaksi (pemasukan & pengeluaran)
3. 📸 Menampilkan gambar nota dari semua PO yang dibuat hari itu
4. 📱 Mengirim laporan ke WhatsApp GM/Admin dengan satu klik

---

## 🚀 Quick Start

### 1. **Jalankan Database Migration**

Jalankan SQL queries di `database/migration-shift-logs.sql`:

```sql
CREATE TABLE IF NOT EXISTS shift_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE business_settings ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20);

CREATE TABLE IF NOT EXISTS po_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
);
```

### 2. **Configure End Shift Settings**

1. Login sebagai Admin
2. Pergi ke **Settings > End Shift Configuration**
3. Isi WhatsApp number GM/Admin (contoh: +62812345678)
4. Isi phone dan email Admin (optional)
5. Klik **Save Settings**

### 3. **Gunakan End Shift Feature**

Staff sekarang bisa:
1. Klik tombol **🌅 End Shift** di top-right header
2. Modal terbuka menampilkan:
   - Daily report dengan income, expense, net balance
   - Daftar semua PO yang dibuat hari ini dengan gambar
3. Pilih untuk:
   - 📱 **Kirim ke WhatsApp** - Buka WhatsApp dengan pesan siap kirim
   - ✓ **Logout & Selesai** - Logout dan tutup aplikasi

---

## 📁 File Structure

```
adf_system/
├── api/
│   ├── end-shift.php              # API untuk fetch daily report & PO data
│   └── send-whatsapp-report.php   # API untuk generate WhatsApp message
├── assets/
│   └── js/
│       └── end-shift.js           # Frontend logic & modal handler
├── modules/
│   └── settings/
│       └── end-shift.php          # Admin settings page
├── includes/
│   ├── header.php                 # Added End Shift button
│   └── footer.php                 # Added end-shift.js script
└── database/
    └── migration-shift-logs.sql    # Database tables
```

---

## 🔧 API Endpoints

### GET `/api/end-shift.php`

Mengambil data untuk End Shift report

**Response:**
```json
{
  "status": "success",
  "data": {
    "user": {
      "name": "John Doe",
      "phone": "+62812345678",
      "role": "staff"
    },
    "business": {
      "name": "Narayana Hotel",
      "phone": "+62812345678"
    },
    "daily_report": {
      "date": "2024-01-25",
      "total_income": 5000000,
      "total_expense": 2000000,
      "net_balance": 3000000,
      "transaction_count": 15,
      "transactions": [...]
    },
    "pos_data": {
      "count": 3,
      "list": [...]
    }
  }
}
```

### POST `/api/send-whatsapp-report.php`

Generate WhatsApp message dengan link

**Request Body:**
```json
{
  "total_income": 5000000,
  "total_expense": 2000000,
  "net_balance": 3000000,
  "user_name": "John Doe",
  "transaction_count": 15,
  "po_count": 3,
  "business_name": "Narayana Hotel",
  "admin_phone": "+62812345678"
}
```

**Response:**
```json
{
  "status": "success",
  "whatsapp_url": "https://wa.me/62812345678?text=...",
  "message": "Formatted WhatsApp message",
  "phone": "+62812345678"
}
```

---

## 🎯 Key Features

### 1. Daily Report Display
- ✅ Menampilkan total pemasukan
- ✅ Menampilkan total pengeluaran
- ✅ Menampilkan saldo bersih
- ✅ Menampilkan jumlah transaksi

### 2. PO Images Gallery
- ✅ Menampilkan thumbnail PO hari ini
- ✅ Support multiple images per PO
- ✅ Klik untuk melihat detail PO
- ✅ Automatic layout yang responsive

### 3. WhatsApp Integration
- ✅ Format pesan otomatis & professional
- ✅ Include semua data penting (income, expense, balance)
- ✅ Kirim via WhatsApp Web (tidak perlu API key)
- ✅ User dapat edit pesan sebelum kirim

### 4. Shift Logs
- ✅ Automatic logging setiap End Shift action
- ✅ Track WhatsApp send timestamp
- ✅ Store full JSON data untuk audit trail

---

## 📱 WhatsApp Message Format

Contoh message yang akan dikirim:

```
*📊 LAPORAN END SHIFT - Narayana Hotel*
📅 25 Jan 2024 17:30
👤 Shift Officer: John Doe

*💰 RINGKASAN TRANSAKSI:*
✅ Total Pemasukan: Rp 5.000.000
❌ Total Pengeluaran: Rp 2.000.000
📈 Saldo Bersih: Rp 3.000.000
🔢 Jumlah Transaksi: 15

*📦 PO HARI INI:*
🔗 Jumlah PO: 3
📸 Lihat detail PO di dashboard

_Laporan otomatis dari sistem_
```

---

## 🔐 Permissions

End Shift button tersedia untuk:
- ✅ Admin
- ✅ GM (General Manager)
- ✅ Staff (dengan akses dashboard)

Hanya **Admin** yang bisa mengatur End Shift Settings

---

## 🐛 Troubleshooting

### Tombol End Shift tidak muncul
1. Clear browser cache (Ctrl+Shift+Delete)
2. Login ulang
3. Pastikan user punya akses ke dashboard

### WhatsApp tidak membuka
1. Pastikan browser support pop-ups
2. Check nomor WhatsApp format (gunakan +62...)
3. Pastikan WhatsApp Web sudah login di browser

### Data transaksi tidak muncul
1. Pastikan ada transaksi hari ini di cashbook
2. Check nomor business_id di database
3. Verify user punya permission 'dashboard'

---

## 💡 Future Enhancements

Potential features untuk dikembangkan:
- [ ] Email report integration
- [ ] SMS notification support
- [ ] PDF attachment untuk email
- [ ] Schedule automatic reports
- [ ] WhatsApp Business API integration
- [ ] Telegram bot support
- [ ] Slack integration
- [ ] Custom report templates

---

## 👨‍💻 Developer Notes

### Adding Custom WhatsApp Message Format

Edit `assets/js/end-shift.js`, fungsi `showEndShiftModal()`:

```javascript
// Customize message format
const message = "*CUSTOM FORMAT*\n" + 
                "Data: " + JSON.stringify(data) + "\n" + 
                "... more customizations";
```

### Extending PO Image Display

Edit `assets/js/end-shift.js`, dalam template modal:

```javascript
// Add custom gallery code
${pos.list.map((p, idx) => `
  <!-- Custom HTML untuk setiap PO -->
`).join('')}
```

### Adding Additional Data Fields

Edit `api/end-shift.php` untuk query database:

```php
// Add more data queries
$customData = $db->fetchAll("SELECT ... ");

$response['data']['custom_section'] = $customData;
```

---

## 📞 Support

Untuk bantuan, hubungi developer atau check dokumentasi di:
- `/docs/END-SHIFT-GUIDE.md` (file ini)
- Admin Settings: `/modules/settings/end-shift.php`

---

**Last Updated:** January 25, 2024  
**Version:** 1.0.0
