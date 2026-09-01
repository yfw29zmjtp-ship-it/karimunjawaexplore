# Fitur Check-In & Check-Out System

## 📋 Overview
Sistem check-in dan check-out otomatis untuk hotel dengan tracking waktu aktual dan manajemen status tamu.

## ✨ Fitur Utama

### 1. **Check-In Guest**
- ✅ Check-in langsung dari Calendar Booking
- ✅ Update status booking menjadi `checked_in`
- ✅ Record waktu aktual check-in
- ✅ Update status room menjadi `occupied`
- ✅ Track user yang melakukan check-in
- ✅ Activity log untuk audit trail

### 2. **Check-Out Guest**
- ✅ Check-out dari halaman Tamu In House atau Calendar
- ✅ Update status booking menjadi `checked_out`
- ✅ Record waktu aktual check-out
- ✅ Update status room menjadi `available`
- ✅ Track user yang melakukan check-out
- ✅ Activity log untuk audit trail

### 3. **Menu Tamu In House**
- ✅ Daftar semua tamu yang sedang check-in
- ✅ Display informasi lengkap: nama, phone, room, tanggal
- ✅ Statistik: Total In House, Lunas, Belum Bayar, Revenue
- ✅ Quick check-out button
- ✅ Link ke detail booking
- ✅ Real-time nights stayed & remaining

### 4. **Dashboard Integration**
- ✅ In House counter berdasarkan status `checked_in`
- ✅ Clickable stat card mengarah ke Tamu In House
- ✅ Quick access button di header
- ✅ Pie chart occupancy yang akurat

## 🗄️ Database Changes

### Tabel: `bookings`
```sql
ALTER TABLE bookings ADD COLUMN:
- actual_checkin_time DATETIME NULL
- actual_checkout_time DATETIME NULL
- checked_in_by INT NULL
- checked_out_by INT NULL
```

### Tabel: `rooms`
```sql
ALTER TABLE rooms ADD COLUMN:
- current_guest_id INT NULL (foreign key to guests.id)
```

### Tabel: `activity_logs` (new)
```sql
CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  description TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
```

## 📁 File Structure

```
api/
├── checkin-guest.php          # API untuk check-in
└── checkout-guest.php         # API untuk check-out

modules/frontdesk/
├── calendar.php               # Updated: Popup check-in button
├── dashboard.php              # Updated: Link to in-house page
└── in-house.php              # NEW: Halaman Tamu In House

database/
└── migration-checkin-checkout.sql   # Database migration

includes/
└── header.php                # Updated: Menu "Tamu In House"
```

## 🔄 Workflow

### Check-In Process:
1. User buka Calendar Booking
2. Klik pada booking reservation
3. Popup detail muncul dengan tombol "Check-In"
4. Klik Check-In → Konfirmasi
5. API `checkin-guest.php` dipanggil
6. Database diupdate:
   - `bookings.status` = 'checked_in'
   - `bookings.actual_checkin_time` = NOW()
   - `bookings.checked_in_by` = current_user_id
   - `rooms.status` = 'occupied'
   - `rooms.current_guest_id` = guest_id
   - Activity log created
7. Page reload → Status berubah, tombol Check-Out muncul

### Check-Out Process:
1. User buka "Tamu In House" atau Calendar
2. Klik tombol "Check-Out" pada guest card
3. Konfirmasi check-out
4. API `checkout-guest.php` dipanggil
5. Database diupdate:
   - `bookings.status` = 'checked_out'
   - `bookings.actual_checkout_time` = NOW()
   - `bookings.checked_out_by` = current_user_id
   - `rooms.status` = 'available'
   - `rooms.current_guest_id` = NULL
   - Activity log created
6. Guest dihapus dari list In House

## 🎯 Usage

### Akses Menu Tamu In House:
- **Dashboard**: Klik stat card "In-House Guests"
- **Dashboard Header**: Klik button "🏨 Tamu In House"
- **Sidebar Menu**: Front Desk → Tamu In House

### Check-In Guest:
1. Buka **Calendar View**
2. Klik pada booking block
3. Popup muncul dengan detail booking
4. Klik tombol **"Check-In"**
5. Konfirmasi → Guest check-in berhasil

### Check-Out Guest:
1. Buka **Tamu In House**
2. Pilih guest yang akan check-out
3. Klik tombol **"Check-out"**
4. Konfirmasi → Guest check-out berhasil

## 📊 Statistics Tracking

Dashboard menampilkan:
- **In-House Guests**: Total tamu yang checked_in
- **Check-out Today**: Tamu dengan check_out_date hari ini
- **Arrival Today**: Booking dengan check_in_date hari ini
- **Occupancy Rate**: Persentase room yang terisi

Halaman Tamu In House menampilkan:
- **Total In House**: Jumlah semua tamu checked_in
- **Lunas**: Tamu dengan payment_status = 'paid'
- **Belum Bayar**: Tamu dengan payment_status != 'paid'
- **Total Revenue**: Sum dari final_price semua in-house bookings

## 🔐 Security

- ✅ Authentication required (Auth middleware)
- ✅ Permission check: `frontdesk` permission
- ✅ Transaction safety (BEGIN/COMMIT/ROLLBACK)
- ✅ SQL injection protection (Prepared statements)
- ✅ Activity logging untuk audit
- ✅ User tracking (who checked in/out)

## 🚀 Next Steps

### Possible Enhancements:
- [ ] Notifikasi otomatis saat check-in/check-out
- [ ] Print receipt check-in/check-out
- [ ] Room assignment automation
- [ ] ID verification upload saat check-in
- [ ] Guest signature digital
- [ ] WhatsApp notification
- [ ] Email confirmation
- [ ] Late check-out handling
- [ ] Early check-in handling
- [ ] Housekeeping integration

## 📝 Notes

- Status booking: `pending` → `confirmed` → `checked_in` → `checked_out`
- Status room: `available` → `occupied` → `available`
- Gunakan `actual_checkin_time` dan `actual_checkout_time` untuk laporan akurat
- Activity logs dapat digunakan untuk audit dan reporting

## 👨‍💻 Developer
**Arief_adfsystem management © 2026**

---
Last Updated: January 31, 2026
