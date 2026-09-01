# MONTHLY BILLS SYSTEM - DOCUMENTATION

## 📋 Setup Instructions

### 1. Execute Database Migration
Jalankan SQL schema ke database:

```bash
# Via terminal (Windows):
mysql -u root -p adf_narayana_hotel < sql/setup-monthly-bills.sql

# Via PHPMyAdmin:
# Copy-paste isi sql/setup-monthly-bills.sql ke SQL tab
```

### 2. Verify Tables Created
```sql
SHOW TABLES LIKE 'monthly%';
-- Should show: monthly_bills, bill_payments
```

---

## 📡 API Endpoints

### 1. CREATE BILL
**Endpoint:** `POST /api/add-monthly-bill.php`

**Parameters (POST):**
```json
{
  "bill_name": "Listrik",
  "bill_month": "2026-04",
  "amount": "500000",
  "due_date": "2026-04-15",          // optional
  "division_id": 1,                   // optional
  "category_id": 5,                   // optional
  "is_recurring": 1,                  // 0/1 - untuk auto-generate tiap bulan
  "notes": "Tagihan bulanan PLN"      // optional
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Tagihan berhasil dibuat",
  "bill_code": "BL-202604-127",
  "bill_id": 42
}
```

---

### 2. RECORD PAYMENT + AUTO-SYNC CASHBOOK ⭐
**Endpoint:** `POST /api/pay-monthly-bill.php`

**Parameters (POST):**
```json
{
  "bill_id": 42,
  "amount": "500000",
  "payment_method": "transfer",      // cash, transfer, card, other
  "cash_account_id": 2,               // FK: cash_accounts.id (Kas Operasional, Bank Utama, etc)
  "reference_number": "TRF-001234",   // optional
  "notes": "Pembayaran via Transfer Dana" // optional
}
```

**What Happens:**
1. ✅ Validates payment amount (tidak boleh > sisa tagihan)
2. ✅ Creates entry di `bill_payments` table
3. ✅ Updates `monthly_bills.paid_amount` + `status`
4. ✅ **AUTO-INSERT ke `cash_book`** dengan:
   - Division + Category (auto-detect atau default)
   - Amount yang dibayar
   - Description: "Listrik (BL-202604-127) - [CICILAN]" atau "[LUNAS]"
   - Payment method & cash account source
5. ✅ Marks `synced_to_cashbook = 1` untuk prevent duplikasi

**Response (Success):**
```json
{
  "success": true,
  "message": "Pembayaran Rp 500.000 berhasil dicatat",
  "bill_status": "paid",
  "total_paid": 500000,
  "remaining": 0,
  "cashbook_id": 8901
}
```

**Use Case (Contoh):**
```
User: Saya bayar listrik 500rb via transfer dana dari Bank Utama
Action: POST /api/pay-monthly-bill.php dengan cash_account_id = 2 (Bank Utama)
Result:
  - Tagihan Listrik marked as PAID
  - Kas Bank Utama berkurang 500rb
  - Cash book entry auto-created
  - User bisa track history pembayaran
```

---

### 3. GET BILLS LIST + FILTERS
**Endpoint:** `GET /api/get-monthly-bills.php`

**Query Parameters:**
```
?month=2026-04                      // Required
&status=partial                     // Optional: pending, partial, paid, cancelled
&division_id=1                      // Optional
&limit=100                          // Optional (default 100)
&offset=0                           // Optional for pagination
```

**Response (Success):**
```json
{
  "success": true,
  "bills": [
    {
      "id": 42,
      "bill_code": "BL-202604-127",
      "bill_name": "Listrik",
      "bill_month": "2026-04-01",
      "amount": 500000,
      "paid_amount": 250000,
      "remaining": 250000,
      "status": "partial",
      "division_name": "Operasional",
      "category_name": "Biaya Utilitas",
      "due_date": "2026-04-15",
      "is_recurring": 1,
      "payment_count": 2,            // Sudah berapa kali dibayar
      "notes": "Tagihan bulanan PLN"
    }
  ],
  "total": 5,
  "month": "2026-04"
}
```

---

### 4. EDIT BILL
**Endpoint:** `POST /api/edit-monthly-bill.php`

**Parameters (POST):**
```json
{
  "bill_id": 42,
  "bill_name": "Listrik Bulan Mei",        // optional
  "amount": 520000,                         // optional
  "due_date": "2026-04-20",                 // optional
  "notes": "Tarif naik 4%"                  // optional
}
```

**Restrictions:**
- Hanya bisa edit bill yang belum paid (status !== 'paid' & paid_amount == 0)
- Bill dengan status 'cancelled' tidak bisa di-edit lagi

---

### 5. DELETE BILL
**Endpoint:** `POST /api/delete-monthly-bill.php`

**Parameters (POST):**
```json
{
  "bill_id": 42
}
```

**Restrictions:**
- Hanya bisa delete bill yang **belum dibayar sama sekali** (`paid_amount = 0`)
- Jika sudah dibayar, user harus update status jadi 'cancelled' dulu (soft delete)

---

### 6. GET MONTHLY REPORT
**Endpoint:** `GET /api/get-monthly-bills-report.php`

**Query Parameters:**
```
?month=2026-04    // Required
```

**Response (Success):**
```json
{
  "success": true,
  "month": "2026-04",
  "summary": {
    "total_bills": 5,
    "total_amount": 2500000,
    "total_paid": 1200000,
    "total_remaining": 1300000,
    "percentage_paid": 48.0          // Berapa % sudah dibayar
  },
  "by_status": [
    {
      "status": "partial",
      "count": 2,
      "total_amount": 1200000,
      "total_paid": 600000
    },
    {
      "status": "pending",
      "count": 3,
      "total_amount": 1300000,
      "total_paid": 0
    }
  ],
  "by_category": [
    {
      "category_name": "Biaya Utilitas",
      "count": 2,
      "total_amount": 1000000,
      "total_paid": 500000
    }
  ],
  "payment_methods": [
    {
      "payment_method": "transfer",
      "count": 3,
      "total_amount": 1200000
    }
  ],
  "bills": [
    {
      "id": 42,
      "bill_code": "BL-202604-127",
      "bill_name": "Listrik",
      "category_name": "Biaya Utilitas",
      "amount": 500000,
      "paid_amount": 250000,
      "remaining": 250000,
      "status": "partial",
      "payment_count": 2,
      "is_recurring": 1
    }
  ]
}
```

---

## 🔧 Database Schema

### Table: `monthly_bills`
| Field | Type | Notes |
|-------|------|-------|
| id | INT | Primary Key, Auto-increment |
| bill_code | VARCHAR(50) | Format: BL-YYYYMMDD-XXX (UNIQUE) |
| division_id | INT | Foreign Key ke divisions (optional) |
| category_id | INT | Foreign Key ke categories (optional) |
| bill_name | VARCHAR(100) | e.g., "Listrik", "Air", "Gaji" |
| bill_month | DATE | First day of month (2026-04-01) |
| amount | DECIMAL(12,2) | Total tagihan (Rp) |
| due_date | DATE | Deadline pembayaran (optional) |
| status | ENUM | pending, partial, paid, cancelled |
| paid_amount | DECIMAL(12,2) | Sudah dibayar berapa (default 0) |
| notes | TEXT | Catatan khusus |
| is_recurring | TINYINT | 1=auto-generate tiap bulan, 0=one-time |
| created_by | INT | User ID yang buat |
| created_at | TIMESTAMP | Auto |
| updated_at | TIMESTAMP | Auto on update |

### Table: `bill_payments`
| Field | Type | Notes |
|-------|------|-------|
| id | INT | Primary Key, Auto-increment |
| bill_id | INT | Foreign Key ke monthly_bills |
| payment_date | DATETIME | Kapan dibayar |
| amount | DECIMAL(12,2) | Jumlah pembayaran |
| payment_method | VARCHAR(50) | cash, transfer, card, other |
| cash_account_id | INT | Foreign Key ke cash_accounts (mana rekening yang dipakai) |
| reference_number | VARCHAR(100) | Nomor bukti (e.g., TRF-001234) |
| synced_to_cashbook | TINYINT | 0=belum sync, 1=sudah sync |
| cashbook_id | INT | Foreign Key ke cash_book (setelah sync) |
| created_by | INT | User ID yang catat |
| created_at | TIMESTAMP | Auto |

---

## 📝 Usage Example (Workflow)

### Scenario: Bayar Listrik Cicilan
```
Tanggal 1 April 2026:
User buat tagihan Listrik April:
  POST /api/add-monthly-bill.php
  {
    "bill_name": "Listrik",
    "bill_month": "2026-04",
    "amount": "500000",
    "due_date": "2026-04-15",
    "is_recurring": 1
  }
  Response: bill_id = 42

Tanggal 10 April:
User bayar cicilan Rp 250rb dari Kas Operasional:
  POST /api/pay-monthly-bill.php
  {
    "bill_id": 42,
    "amount": "250000",
    "payment_method": "cash",
    "cash_account_id": 1           // Kas Operasional
  }
  ✅ Status: partial (Rp 250rb dari Rp 500rb)
  ✅ Cash book auto-entry: -Rp 250rb dari Kas Operasional

Tanggal 20 April:
User bayar sisa Rp 250rb dari Bank Utama (transfer):
  POST /api/pay-monthly-bill.php
  {
    "bill_id": 42,
    "amount": "250000",
    "payment_method": "transfer",
    "cash_account_id": 2,          // Bank Utama
    "reference_number": "TRF-20260420-001"
  }
  ✅ Status: paid (Rp 500rb total) [LUNAS]
  ✅ Cash book auto-entry: -Rp 250rb dari Bank Utama

Lihat laporan bulan April:
  GET /api/get-monthly-bills-report.php?month=2026-04
  Summary: 5 tagihan, total Rp 2.5jt, sudah bayar Rp 1.2jt (48%)
```

---

## 🔐 Permissions

Semua endpoint **REQUIRE** user login dan permission: **`finance`**

Jika tidak authorize → Response:
```json
{
  "success": false,
  "message": "Unauthorized"
}
```

---

## 🚨 Important Notes

1. **Cash Account Selection:** 
   - Saat bayar tagihan, WAJIB pilih `cash_account_id` (Kas Tunai, Bank, etc)
   - Ini penting untuk tracking cash flow yang akurat

2. **Auto-Sync to Cashbook:**
   - Setiap pembayaran OTOMATIS masuk ke cash_book
   - Tidak perlu manual input lagi ke buku kas

3. **Recurring Bills:**
   - Set `is_recurring = 1` untuk tagihan yang bulanan (listrik, gaji, sewa)
   - Nanti bisa di-auto-generate tanpa input manual

4. **Payment Validation:**
   - Sistem cegah overpayment (pembayaran > sisa tagihan)
   - Error: "Pembayaran melebihi jumlah tagihan. Sisa: Rp XXX"

5. **Tracking:**
   - Tiap pembayaran tercatat di `bill_payments`
   - Bisa lihat history: berapa kali dibayar, berapa amount per transaksi

---

## ✅ Next Steps (Frontend UI)

Setelah API siap, buat UI module:
1. `modules/bills/index.php` - List bills + filters
2. Form create bill
3. Form payment dengan cash account selector
4. Monthly report view
5. Payment history detail

---

## 📞 Testing

Gunakan Postman atau cURL:

```bash
# Test create bill
curl -X POST http://localhost/adf_system/api/add-monthly-bill.php \
  -d "bill_name=Listrik&bill_month=2026-04&amount=500000&due_date=2026-04-15&is_recurring=1"

# Test payment
curl -X POST http://localhost/adf_system/api/pay-monthly-bill.php \
  -d "bill_id=42&amount=250000&payment_method=cash&cash_account_id=1"

# Test get bills
curl "http://localhost/adf_system/api/get-monthly-bills.php?month=2026-04"

# Test report
curl "http://localhost/adf_system/api/get-monthly-bills-report.php?month=2026-04"
```

---

**Created:** April 17, 2026
**Version:** 1.0
**Last Updated:** [timestamp]
