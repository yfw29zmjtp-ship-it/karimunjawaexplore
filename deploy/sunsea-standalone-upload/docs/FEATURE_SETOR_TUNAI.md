# 🏦 Fitur Setor Tunai (Cash Transfer Internal)

## Deskripsi
Fitur ini memungkinkan admin untuk mencatat **transfer internal** uang tunai dari kas cabang ke rekening bank operasional dengan:
- ✅ Mengurangi saldo akun kas tunai
- ✅ Menambah saldo akun rekening bank  
- ❌ **TIDAK masuk cash_book** (bukan income/expense)
- ❌ **TIDAK mengubah total nominal** - hanya transfer antar akun
- ✅ Mencatat timestamp (jam, tanggal) setiap transfer
- ✅ Menyimpan siapa yang input (created_by)
- ✅ Opsi arsip untuk organisasi data jangka panjang
- ✅ Ringkasan dengan filter dan laporan transfer

---

## Komponen Implementasi

### 1. Tombol "Setor Tunai" di Form Cashbook
**Lokasi:** `modules/cashbook/add.php` (Baris 813-838)

**Fungsi:**
- Tombol khusus dengan styling biru gradient
- Ketika diklik, auto-fill form dengan:
  - **Transaction Type:** Income (uang masuk ke bank)
  - **Source Type:** `cash_transfer` (transfer internal, tidak dihitung sebagai pendapatan)
  - **Cash Account:** Bank (Rekening Operasional)
  - **Category:** "Setor Tunai ke Rekening Bank"
  - **Payment Method:** Cash
  - **Description:** "Setor tunai dari kas cabang ke rekening operasional"

**Notifikasi:** Menampilkan pesan penjelasan saat tombol diklik

### 2. JavaScript Auto-fill Function
**Lokasi:** `modules/cashbook/add.php` (Baris 1154-1240)

**Fungsi `fillSetorTunai()`:**
```javascript
function fillSetorTunai() {
    // Set transaction type ke income
    // Set date & time otomatis
    // Set division, category, account
    // Set payment method ke cash
    // Set source_type ke 'cash_transfer'
    // Focus ke amount field untuk input nominal
}
```

**Notifikasi:** Menampilkan pemberitahuan dengan penjelasan transfer internal

### 3. Database Tables

#### Tabel: `cash_transfers` (Master DB)
Menyimpan tracking setiap transfer setor tunai:

```sql
CREATE TABLE cash_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_id INT NOT NULL,
  cash_account_id INT NOT NULL COMMENT 'Akun kas tunai (sumber)',
  bank_account_id INT NOT NULL COMMENT 'Rekening bank (tujuan)',
  amount DECIMAL(15,2) NOT NULL,
  transfer_date DATE NOT NULL,
  transfer_time TIME NOT NULL,
  reference_number VARCHAR(50) COMMENT 'Referensi ke cash_book ID',
  description TEXT,
  created_by INT NOT NULL,
  is_archived TINYINT(1) DEFAULT 0,
  archived_at DATETIME NULL,
  archived_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
```

**Kolom Penting:**
- `cash_account_id`: ID kas tunai (dikurangi)
- `bank_account_id`: ID rekening bank (ditambah)
- `is_archived`: Status arsip (1=arsipkan, 0=aktif)
- `archived_at`, `archived_by`: Tracking siapa yang mengarsip & kapan

### 4. POST Handler (Pure Transfer Logic)
**Lokasi:** `modules/cashbook/add.php` (Baris ~325-380)

**Logika Transfer Internal (TIDAK masuk cash_book):**

Ketika form disubmit dengan `source_type='cash_transfer'`:

**PENTING:** Setor tunai TIDAK insert ke `cash_book` - ini pure internal transfer:

1. **Debit dari Akun Kas Tunai:**
   ```sql
   UPDATE cash_accounts 
   SET current_balance = current_balance - {amount} 
   WHERE id = {cash_account_id}
   ```

2. **Credit ke Akun Bank:**
   ```sql
   UPDATE cash_accounts 
   SET current_balance = current_balance + {amount} 
   WHERE id = {bank_account_id}
   ```

3. **Record di cash_transfers table** (untuk tracking & arsip):
   ```sql
   INSERT INTO cash_transfers 
   (business_id, cash_account_id, bank_account_id, amount, 
    transfer_date, transfer_time, reference_number, description, 
    created_by, is_archived)
   ```

**Hasil:**
- ✅ Saldo kas tunai berkurang
- ✅ Saldo bank bertambah
- ✅ Transfer tercatat dalam cash_transfers dengan timestamp & operator
- ❌ Tidak masuk cash_book (bukan income/expense)
- ❌ Tidak mempengaruhi perhitungan P&L/revenue

### 5. Halaman Ringkasan Setor Tunai
**Lokasi:** `modules/cashbook/cash-transfers.php`

**Fitur:**

#### View Daftar Transfer:
- Kolom: Tanggal, Jam, Nominal, Dari Akun → Ke Akun, Created By
- Setiap transaksi ditampilkan dalam card/row dengan info detail

#### Summary:
- Total nominal setor
- Jumlah transaksi
- Status (Aktif / Arsipan)

#### Filter:
- **Dari Tanggal / Sampai Tanggal:** Filter by date range
- **Rekening:** Filter by cash account atau bank account
- **Status:** Tampilkan aktif atau arsipan

#### Action Buttons:
- **Arsipkan (📦):** Tandai sebagai arsip dengan timestamp & siapa yang arsipkan
- **Unarchive (↩️):** Batalkan status arsip
- **Reset Filters:** Hapus semua filter

#### Arsip Management:
- Kolom `is_archived=1` ditampilkan dengan label "ARSIPAN"
- Dapat di-unarchive kapan saja
- Tracking complete: siapa yang arsipkan & kapan

### 6. Setup Tool
**Lokasi:** `tools/setup-cash-transfers.php`

**Fungsi:** 
- Interface untuk membuat tabel `cash_transfers` di master database
- Developer-only access
- Tampil pesan sukses & instruksi setelah setup

**Cara Jalankan:**
```
1. Login sebagai admin
2. Buka: https://your-site/tools/setup-cash-transfers.php
3. Klik "Buat Tabel cash_transfers"
4. Tabel siap digunakan
```

---

## Workflow Penggunaan

### Skenario: Admin Setor Tunai Rp 1.000.000

**Step 1: Buka Form Cashbook**
```
Buku Kas → Tambah Transaksi
atau
Buku Kas → "Setor Tunai Baru" button di toolbar
```

**Step 2: Klik Tombol "🏦 Setor Tunai"**
- Form auto-filled:
  - Transaction Type: Income (💵)
  - Category: "Setor Tunai ke Rekening Bank"
  - Account: Bank/Rekening Operasional
  - Payment Method: Cash

**Step 3: Masukkan Nominal**
- Focus ke field Amount
- Input: 1.000.000
- Opsi: tambah deskripsi/keterangan (optional)

**Step 4: Klik Submit**
- System akan:
  1. ✅ Kurangi Kas Tunai: -1.000.000
  2. ✅ Tambah Rekening Bank: +1.000.000
  3. ✅ Catat di cash_transfers dengan timestamp & admin name
  4. ✅ Catat di cash_book dengan source_type='cash_transfer'

**Step 5: Verifikasi Ringkasan**
```
Buku Kas → "🏦 Ringkasan Setor Tunai"
- Lihat list semua transfer
- Total Setor: Rp X.XXX.XXX
- Filter by date range
- Opsi arsip untuk data lama
```

---

## Source Type Mapping

| source_type | Tipe | Masuk cash_book | P&L Impact | Use Case |
|---|---|---|---|---|
| `manual` | Income | ✅ Yes | ✅ Yes | Pemasukan normal (dari customer) |
| `invoice_payment` | Income | ✅ Yes | ✅ Yes | Pembayaran invoice |
| `owner_fund` | Income | ✅ Yes | ❌ No | Modal dari owner (Bu Sita) |
| `cash_transfer` | Transfer | ❌ No | ❌ No | **Setor tunai (pure internal)** |
| `owner_project` | Expense | ✅ Yes | ✅ Yes | Pengeluaran proyek dari modal |

**Kesimpulan:** 
- `cash_transfer` adalah **PURE TRANSFER** yang tidak masuk `cash_book` sama sekali
- Hanya update balance di `cash_accounts`
- Tidak dihitung dalam P&L atau revenue
- Konsep: uang hanya pindah lokasi (dari kas ke bank), bukan income/expense baru

---

## Balance Update Flow

### Setor Tunai (Pure Transfer) Workflow:

```
User Input: Setor Tunai Rp 500.000 dari Kas Tunai ke Bank

┌─────────────────────────────────────┐
│  POST /modules/cashbook/add.php      │
│  source_type='cash_transfer'        │
│  amount=500000                      │
│  cash_account_id=1 (Kas Tunai)      │
│  bank_account_id=2 (Bank)           │
└──────────────────┬──────────────────┘
                   │
         ┌─────────▼──────────────────┐
         │ EARLY RETURN - NOT NORMAL  │
         │ flow (NO cash_book entry)  │
         └─────────┬──────────────────┘
                   │
        ┌──────────┴──────────────────┐
        │                             │
    ┌───▼────┐                  ┌────▼────┐
    │ DEBIT  │                  │ CREDIT  │
    ├────────┤                  ├─────────┤
    │ Kas:   │                  │ Bank:   │
    │ -500k  │                  │ +500k   │
    └────────┘                  └─────────┘
        │                             │
        └──────────────┬──────────────┘
                       │
      ┌────────────────▼────────────┐
      │ cash_transfers table        │
      │ (tracking & archive only)   │
      └────────────────┬────────────┘
                       │
      ┌────────────────▼────────────┐
      │ SUCCESS - Redirect           │
      │ NOT stored in cash_book      │
      └────────────────────────────┘
```

**Perbedaan dari Normal Transaction:**
- Normal flow: Insert cash_book → then update cash_accounts
- Setor tunai: Check early for `source_type='cash_transfer'` → handle seperti function terpisah → return early tanpa cash_book

---

## Database Schema References

### cash_accounts (Master DB)
```sql
SELECT * FROM cash_accounts 
WHERE business_id = 1 
AND is_active = 1
ORDER BY account_type, account_name;
```

Akan menampilkan:
- Kas Tunai (account_type='cash')
- Rekening Operasional (account_type='bank')
- Kas Modal Owner (account_type='owner_capital')

### cash_account_transactions (Master DB)
Menyimpan setiap perubahan balance:
- Debit/credit dari cash_transfers
- Audit trail lengkap dengan created_by & timestamp

### cash_book (Business DB)
Tabel transaksi lokal business:
- Menyimpan satu record per setor tunai
- source_type='cash_transfer' untuk filtering

---

## Error Handling

### Scenario: No Bank Account Found
```
Error: "Tidak ada rekening bank untuk penerima transfer setor tunai"
Solution: Setup rekening bank dulu di Cash Accounts
```

### Scenario: Insufficient Cash Balance
```
Error: Cash balance becomes negative
Solution: Check current balance di ringkasan setor tunai
```

### Scenario: Setup Table Not Created
```
Error: cash_transfers table doesn't exist
Solution: Run /tools/setup-cash-transfers.php
```

---

## Testing Checklist

- [ ] Setup tabel cash_transfers dengan /tools/setup-cash-transfers.php
- [ ] Buka form cashbook add.php
- [ ] Klik tombol "Setor Tunai" - form harus auto-fill
- [ ] Input nominal (contoh: 500000)
- [ ] Submit - harus berhasil
- [ ] Check balances di cash_accounts:
  - [ ] Kas tunai berkurang
  - [ ] Bank bertambah
- [ ] Buka halaman cash-transfers.php
- [ ] Lihat transfer di list
- [ ] Test filter (by date, by account)
- [ ] Test arsip - status harus berubah jadi "ARSIPAN"
- [ ] Test unarchive - status kembali normal

---

## Files Modified

1. **modules/cashbook/add.php**
   - Tambah tombol "Setor Tunai" (Line 813-838)
   - Tambah fungsi JS fillSetorTunai() (Line 1154-1240)
   - Tambah handler cash_transfer di POST (Line ~380-440)

2. **modules/cashbook/index.php**
   - Tambah link ke cash-transfers.php di toolbar

3. **modules/cashbook/cash-transfers.php** (NEW)
   - Halaman ringkasan setor tunai
   - View, filter, arsip functionality

4. **tools/setup-cash-transfers.php** (NEW)
   - Setup tool untuk membuat tabel

---

## Maintenance Notes

### Regular Operations:
- Setor tunai tidak perlu approval, langsung tersimpan
- Arsip dilakukan manual ketika sudah selesai bulan/periode
- Laporan untuk audit dapat diekstrak dari halaman ringkasan

### Database Maintenance:
- cash_transfers table di master database (shared semua bisnis)
- Pastikan backup regular dilakukan
- foreign key constraints ke cash_accounts untuk data integrity

### Troubleshooting:
- Jika balance tidak update, check error_log file
- Jika cash_transfers tabel not found, jalankan setup tool
- Verifikasi cash_accounts.account_type enum values

---

## Future Enhancements

1. **Bulk Setor Tunai:** Import multiple transfers dari file
2. **Approval Workflow:** Optional approval sebelum tercatat
3. **Reconciliation:** Match dengan bank statement
4. **Automated Archiving:** Auto-archive after N days
5. **Reporting:** Excel export, PDF reports
6. **Email Notification:** Alert saat ada setor tunai besar
7. **Multi-currency:** Support forex rate tracking

---

**Dokumentasi dibuat:** 2024
**Last Updated:** Fitur Setor Tunai v1.0
**Status:** ✅ Production Ready
