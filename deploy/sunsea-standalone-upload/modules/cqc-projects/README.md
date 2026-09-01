# CQC Solar Projects Management System

## 📋 Pengenalan

Sistem manajemen proyek khusus untuk **CQC Enjiniring** - perusahaan instalator panel surya. Sistem ini dirancang untuk melacak proyek-proyek instalasi panel surya dengan detail pengeluaran, progress tracking, dan dashboard analytics.

---

## ✨ Fitur Utama

### 1. **📊 Dashboard Proyek**
- **Grafik distribusi status** - Visualisasi jumlah proyek per status (Planning, Procurement, Installation, Testing, Completed, On Hold)
- **Budget vs Pengeluaran** - Bar chart perbandingan budget total dengan pengeluaran aktual
- **Progress Overview** - Doughnut chart progress rata-rata
- **Quick Stats**:
  - Total Proyek
  - Proyek Sedang Berjalan
  - Rata-rata Progress
  - Total Pengeluaran
- **Tabel Proyek Berjalan** - List 5 proyek dengan status active, lengkap dengan progress bar dan budget tracking

### 2. **📋 Manajemen Proyek**
#### Buat Proyek Baru
- Informasi dasar: nama, kode, lokasi, deskripsi
- Info klien: nama, telepon, email
- Spesifikasi panel surya:
  - Kapasitas (KWp)
  - Jumlah panel
  - Tipe panel
  - Tipe inverter
- Budget & jadwal:
  - Budget total (Rp)
  - Progress slider (0-100%)
  - Tanggal mulai, selesai, estimasi

#### Edit Proyek
- Update semua informasi proyek yang telah dibuat
- Validasi data otomatis

### 3. **📝 Tracking Pengeluaran Detail**
Setiap proyek dapat mencatat pengeluaran dengan kategori:
- ☀️ **Panel Surya** - Pembelian panel surya
- ⚡ **Inverter & Controller** - Peralatan inverter dan controller
- 🔌 **Kabel & Konektor** - Material kabel dan konektor
- 👷 **Instalasi & Labor** - Biaya tenaga kerja
- 🏗️ **Struktur & Mounting** - Rangka dan sistem mounting
- 📋 **Perizinan & Desain** - Biaya perizinan dan design
- 🔧 **Testing & Commissioning** - Testing dan commissioning
- 🚚 **Transportasi & Logistik** - Pengiriman dan logistik
- 📚 **Konsultasi & Training** - Konsultasi dan pelatihan
- 📌 **Lainnya** - Kategori lain

### 4. **💰 Budget Management**
- Budget total per proyek
- Tracking pengeluaran otomatis
- Perhitungan sisa/remaining budget
- Progress bar pengunaan budget
- Alert jika pengeluaran >= 90% budget

### 5. **⏳ Status Tracking**
- Planning → Procurement → Installation → Testing → Completed
- Opsi On Hold untuk proyek yang ditunda
- Progress percentage tracking (0-100%)
- Timeline tracking (start date, end date, estimated completion)

---

## 🚀 Quick Start

### Step 1: Setup Database
Akses setup script untuk membuat tables:

```
http://localhost/adf_system/modules/cqc-projects/setup.php
```

Klik tombol **"🚀 Mulai Setup"** untuk membuat:
- `cqc_projects` - Data proyek
- `cqc_project_expenses` - Pengeluaran
- `cqc_expense_categories` - Kategori pengeluaran
- `cqc_project_balances` - Summary budget

### Step 2: Akses Dashboard
```
http://localhost/adf_system/modules/cqc-projects/dashboard.php
```

Atau dari menu CQC:
```
http://localhost/adf_system/cqc-menu.php
```
Kemudian klik "Project Management" → Daftar Proyek

### Step 3: Buat Proyek Pertama
1. Klik tombol **"➕ Proyek Baru"** di dashboard
2. Isi informasi dasar proyek:
   - Nama proyek
   - Kode proyek (unik)
   - Lokasi
   - Info klien
   - Spesifikasi panel surya
3. Atur budget & jadwal
4. Klik **"➕ Buat Proyek"**

### Step 4: Tambah Pengeluaran
1. Buka detail proyek
2. Scroll ke bagian "Pengeluaran Terbaru"
3. Klik tombol **"+ Tambah"**
4. Isi form:
   - Kategori pengeluaran
   - Tanggal & waktu
   - Jumlah (Rp)
   - Metode pembayaran
   - Deskripsi
5. Klik **"✅ Simpan Pengeluaran"**

---

## 📁 File Structure

```
adf_system/
├── database/
│   └── migration-cqc-projects.sql    ← Database schema
│
├── modules/
│   └── cqc-projects/
│       ├── index.php                  ← Redirect ke dashboard
│       ├── setup.php                  ← Setup wizard
│       ├── dashboard.php              ← Dashboard utama (768 lines)
│       ├── add.php                    ← Form tambah/edit proyek (400+ lines)
│       ├── detail.php                 ← Detail proyek + expenses (600+ lines)
│       └── README.md                  ← This file
│
└── cqc-menu.php                       ← Menu utama CQC
```

---

## 🎨 Design & Colors

### Color Scheme
- **Primary Blue**: `#0066CC` - Header, buttons, titles
- **Accent Yellow**: `#FFD700` - Highlights, progress bars
- **White**: `#FFFFFF` - Card backgrounds
- **Gray**: `#f5f7fa` - Page background

### Status Badge Colors
- **Planning**: Light Blue (#e3f2fd)
- **Procurement**: Light Yellow (#fff3cd)  
- **Installation**: Light Cyan (#d1ecff)
- **Testing**: Light Green (#c8e6c9)
- **Completed**: Medium Green (#a5d6a7)
- **On Hold**: Light Orange (#ffccbc)

---

## 📊 Database Schema

### `cqc_projects`
```sql
- id (INT, PK)
- project_name (VARCHAR 200) - Nama proyek
- project_code (VARCHAR 50, UNIQUE) - Kode unik
- location (VARCHAR 300) - Lokasi proyek
- client_name (VARCHAR 150) - Nama klien
- client_phone, client_email
- solar_capacity_kwp (DECIMAL) - Kapasitas KWp
- panel_count, panel_type, inverter_type
- budget_idr (DECIMAL 15,2) - Budget total
- spent_idr (DECIMAL 15,2) - Sudah terpakai
- status (ENUM) - Status proyek
- progress_percentage (INT 0-100)
- start_date, end_date, estimated_completion
- created_by (FK users.id)
- created_at, updated_at (TIMESTAMP)
```

### `cqc_project_expenses`
```sql
- id (INT, PK)
- project_id (FK cqc_projects)
- expense_category_id (FK cqc_expense_categories)
- expense_date (DATE)
- expense_time (TIME)
- amount_idr (DECIMAL 15,2)
- description (TEXT)
- payment_method (ENUM: cash, bank_transfer, check, credit)
- status (ENUM: draft, submitted, approved, rejected, paid)
- created_by, approved_by
- created_at, updated_at
```

### `cqc_expense_categories`
```sql
- id (INT, PK)
- category_name (VARCHAR 100)
- category_code (VARCHAR 50, UNIQUE)
- icon (VARCHAR 50) - Emoji/icon
- is_active (TINYINT)
- sort_order (INT)
```

### `cqc_project_balances`
```sql
- id (INT, PK)
- project_id (INT, UNIQUE FK)
- total_expenses_idr (DECIMAL 15,2)
- remaining_budget_idr (DECIMAL 15,2)
- last_updated (TIMESTAMP)
```

---

## 🔧 Technical Details

### Dependencies
- **PHP**: 7.4+
- **MySQL**: 5.7+
- **PDO Extension**: Untuk database connection
- **Chart.js**: v3.9.1 (CDN) - Untuk grafik dashboard

### Browser Support
- Chrome/Edge 90+
- Firefox 88+
- Safari 14+
- Mobile browsers (responsive design)

### Security Features
- Password hashing untuk user
- SQL injection prevention via prepared statements
- XSS protection via htmlspecialchars()
- CSRF token (dapat ditambahkan)
- Session-based authentication

---

## 📱 Responsive Design

Semua halaman fully responsive untuk:
- Desktop (1200px+)
- Tablet (768px - 1199px)
- Mobile (< 768px)

Breakpoints menggunakan CSS media queries.

---

## 🎯 Use Cases

### Use Case 1: Proyek Panel Surya Rumah Residence
- Kapasitas: 3.5 KWp
- Budget: Rp 50.000.000
- Track pengeluaran materials, labor, installation
- Monitor progress dari procurement hingga testing
- Generate report pengeluaran per kategori

### Use Case 2: Proyek Komersial Skala Besar  
- Kapasitas: 100+ KWp
- Budget: Rp 1.000.000.000+
- Multi-kategori pengeluaran
- Detailed timeline tracking
- Budget variance analysis

### Use Case 3: Multi-Project Management
- Dashboard overview 10+ projects
- Status distribution overview
- Budget aggregate reporting
- Performance metrics

---

## ⚠️ Limitations & Future Enhancements

### Current Limitations
- Tidak ada upload file dokumentasi (bisa ditambah)
- Tidak ada approval workflow untuk expenses
- Tidak ada notification system
- Tidak ada export to PDF/Excel

### Potential Enhancements
- [ ] Document upload (proposal, permits, certificates)
- [ ] Approval workflow untuk expenses >= Rp 10 juta
- [ ] Email notifications untuk milestone
- [ ] Monthly financial reports
- [ ] Resource allocation (workers, equipment)
- [ ] Gantt chart timeline
- [ ] Mobile app integration
- [ ] Real-time budget alerts
- [ ] Vendor management
- [ ] Quality check log

---

## 🔗 Integration dengan Menu CQC

Menu CQC sudah ter-setup untuk link ke projects:
```
http://localhost/adf_system/cqc-menu.php
```

Section: **"📋 Project Management"**
- Daftar Proyek → `modules/projects/list.php`
- Task Management → `modules/projects/tasks.php`
- Budget Tracking → `modules/projects/budget.php`
- Progress Report → `modules/projects/progress.php`

---

## 📞 Support & Documentation

Pertanyaan atau issues?
1. Check database logs di phpMyAdmin
2. Verify user permissions (user_business_assignment)
3. Check file permissions untuk modules/cqc-projects/
4. Ensure adf_cqc database exists

---

## ✅ Checklist untuk Production

- [ ] Database backup sebelum setup
- [ ] Test di local environment dulu
- [ ] Upload files ke hosting via FTP/Git
- [ ] Run setup script di production
- [ ] Create sample project untuk testing
- [ ] Verify PDF export (jika ada)
- [ ] Test di berbagai browsers
- [ ] Create user manual untuk team
- [ ] Backup database secara regular

---

## 📝 Version Information

- **Version**: 1.0
- **Release Date**: February 2026
- **Status**: Production Ready
- **Last Updated**: 2026-02-27

---

## 🎓 Training Materials Needed

1. Quick start guide (ini)
2. Video tutorial setup
3. User manual lengkap
4. Admin guide untuk configuration
5. FAQ troubleshooting

---

**Selamat menggunakan CQC Solar Projects Management System! ☀️**

Untuk dukungan lebih lanjut, hubungi tim technical support.
