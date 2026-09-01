# ADF SYSTEM 2.0 - DATABASE ARCHITECTURE DOCUMENTATION

## Overview
ADF System menggunakan **Master Database** + **Multiple Business Databases** architecture untuk mendukung multi-business management dengan clean separation of concerns.

---

## DATABASE STRUCTURE

### 1. MASTER DATABASE (`adf_system`)
**Tujuan:** Central control center untuk semua system configuration, user management, dan business setup.

#### Tables:

##### `roles`
Master roles definition untuk seluruh system
```sql
- id (PK)
- role_name (Developer Admin, Owner, Staff)
- role_code (developer, owner, staff)
- description
- is_system_role (flag untuk system roles)
```

**Roles:**
- `developer` - Akses penuh ke developer panel
- `owner` - Manager bisnis masing-masing
- `staff` - User biasa dengan permission terbatas

---

##### `users`
Semua user di system
```sql
- id (PK)
- username (unique)
- email (unique)
- password (hashed)
- full_name
- phone
- role_id (FK → roles)
- is_active
- last_login
- created_at, updated_at
- created_by (FK → users, who created this user)
```

**Indexing:** username, email, role_id, is_active

---

##### `businesses`
Master data semua bisnis yang terdaftar
```sql
- id (PK)
- business_code (unique) - e.g., "HOTEL_01", "CAFE_01"
- business_name - e.g., "Narayana Hotel", "Ben's Cafe"
- business_type - enum (hotel, restaurant, retail, manufacture, tourism, other)
- database_name (unique) - e.g., "adf_hotel_narayana", "adf_cafe_bens"
- owner_id (FK → users) - User yang own bisnis ini
- description
- logo_url
- is_active
- created_at, updated_at
```

---

##### `menu_items`
Master definition semua menu di system
```sql
- id (PK)
- menu_code (unique) - e.g., "dashboard", "cashbook", "inventory"
- menu_name - Display name
- menu_icon - Bootstrap icon class
- menu_url - Relative URL
- menu_order - Sorting order
- is_active
- description
```

**Default Menus:**
- dashboard (Dashboard)
- cashbook (Cashbook)
- inventory (Inventory)
- sales (Sales)
- users (User Management)
- reports (Reports)
- settings (Settings)

---

##### `business_menu_config`
Menentukan menu mana yang enabled untuk bisnis mana
```sql
- id (PK)
- business_id (FK → businesses)
- menu_id (FK → menu_items)
- is_enabled (bisa bisnis enable/disable menu tertentu)
- created_at, updated_at
```

**Unique Constraint:** (business_id, menu_id)

---

##### `user_business_assignment`
Assign user ke business (user bisa manage di beberapa bisnis)
```sql
- id (PK)
- user_id (FK → users)
- business_id (FK → businesses)
- assigned_at
```

**Unique Constraint:** (user_id, business_id)

---

##### `user_menu_permissions`
Fine-grained permissions per user per menu per business
```sql
- id (PK)
- user_id (FK → users)
- business_id (FK → businesses)
- menu_id (FK → menu_items)
- can_view (read access)
- can_create (write access)
- can_edit (update access)
- can_delete (delete access)
- granted_at
- granted_by (FK → users, who granted permission)
```

**Unique Constraint:** (user_id, menu_id, business_id)

**Contoh:**
- Developer bisa view/create/edit/delete semua
- Owner bisnis bisa manage staff permissions
- Staff hanya bisa akses menu yang di-granted dengan permissions spesifik

---

##### `settings`
System-wide settings configuration
```sql
- id (PK)
- setting_key (unique) - e.g., "app_name", "currency", "timezone"
- setting_value
- setting_type - enum (string, number, boolean, json)
- description
- created_at, updated_at
```

---

##### `audit_logs`
Track semua perubahan untuk audit trail
```sql
- id (PK)
- user_id (FK → users)
- action - verb (create, update, delete, login, logout)
- entity_type - table name
- entity_id - record id changed
- old_value - previous value
- new_value - new value
- ip_address
- created_at
```

---

## 2. BUSINESS DATABASES (Per-Business)
**Naming Convention:** `adf_{type}_{name}` (lowercase, underscore-separated)

**Examples:**
- `adf_hotel_narayana`
- `adf_cafe_bens`
- `adf_retail_furniture`
- `adf_manufacture_kapal`

### Tables:

#### `divisions`
Business divisions (e.g., Hotel Rooms, Restaurant, Laundry)
```sql
- id (PK)
- division_code (unique, e.g., "DIV001")
- division_name
- division_type - enum (income, expense, asset, both)
- description
- is_active
```

---

#### `categories`
Transaction categories within divisions
```sql
- id (PK)
- division_id (FK)
- category_name
- category_type - enum (income, expense)
- category_code
- description
- is_active
```

**Example:**
- Division: "Main Operation" → Category: "Room Rental Income"
- Division: "Support" → Category: "Utilities"

---

#### `accounts`
Chart of Accounts (Accounting standard)
```sql
- id (PK)
- account_code (unique, e.g., "1001", "4001")
- account_name
- account_type - enum (asset, liability, equity, income, expense)
- description
- is_active
```

---

#### `cash_book`
Main transaction ledger
```sql
- id (PK)
- transaction_date
- transaction_time
- transaction_number (unique)
- division_id (FK)
- category_id (FK)
- account_id (FK)
- transaction_type - enum (income, expense)
- amount
- description
- reference_number
- payment_method - enum (cash, bank_transfer, card, check, other)
- status - enum (draft, posted, cancelled)
- created_by
- created_at, updated_at
```

---

#### `bank_accounts`
Bank account management
```sql
- id (PK)
- bank_name
- account_number
- account_holder
- account_type - enum (savings, checking, other)
- balance
- is_active
```

---

#### `inventory`
Inventory items
```sql
- id (PK)
- item_code (unique)
- item_name
- category_id (FK)
- unit - enum (pcs, kg, liter, box, bundle, other)
- quantity (current stock)
- reorder_level (minimum stock alert)
- unit_price
- supplier_name
- is_active
```

---

#### `inventory_movements`
Track all inventory in/out
```sql
- id (PK)
- inventory_id (FK)
- movement_date
- movement_type - enum (in, out, adjustment)
- quantity
- reference_number
- notes
- created_by
```

---

#### `customers`
Customer/member list
```sql
- id (PK)
- customer_code (unique)
- customer_name
- customer_type - enum (individual, company, member)
- email
- phone
- address
- city, province, postal_code
- is_active
```

---

#### `suppliers`
Supplier information
```sql
- id (PK)
- supplier_code (unique)
- supplier_name
- contact_person
- email, phone
- address
- bank_name, bank_account
- is_active
```

---

#### `sales`
Sales transactions
```sql
- id (PK)
- sales_number (unique)
- sales_date
- customer_id (FK)
- division_id (FK)
- total_amount
- discount
- tax
- net_amount
- payment_method
- status - enum (draft, completed, cancelled)
- created_by
```

---

#### `purchases`
Purchase transactions
```sql
- id (PK)
- purchase_number (unique)
- purchase_date
- supplier_id (FK)
- total_amount
- discount
- tax
- net_amount
- payment_status - enum (unpaid, partial, paid)
- status - enum (draft, confirmed, received, cancelled)
- created_by
```

---

#### `daily_shifts`
Daily shift summary
```sql
- id (PK)
- shift_date (unique)
- opening_cash
- total_income
- total_expense
- closing_cash
- notes
- created_by
```

---

#### `system_logs`
Local activity logs for this business
```sql
- id (PK)
- user_id
- action
- entity_type
- entity_id
- details
- ip_address
- created_at
```

---

## DATABASE RELATIONSHIPS

```
Master Database (adf_system)
├── roles
│   └── has many → users
│
├── users
│   ├── has many → user_business_assignment
│   ├── has many → user_menu_permissions
│   └── has many → audit_logs (as creator)
│
├── businesses
│   ├── has many → user_business_assignment
│   ├── has many → business_menu_config
│   ├── belongs to → owner user
│   └── → (links to) external business database
│
├── menu_items
│   ├── has many → business_menu_config
│   └── has many → user_menu_permissions
│
└── Audit & Logs

Business Database (per-business)
├── divisions
│   ├── has many → categories
│   ├── has many → cash_book
│   └── has many → sales
│
├── categories
│   ├── has many → cash_book
│   └── has many → inventory
│
├── accounts
│   └── has many → cash_book
│
├── customers
│   └── has many → sales
│
├── suppliers
│   └── has many → purchases
│
├── inventory
│   ├── has many → inventory_movements
│   └── belongs to → categories
│
└── Transactions (Sales, Purchases, Cash Book)
```

---

## WORKFLOW EXAMPLES

### 1. Create New User
1. Developer login → Developer Panel
2. Click "Add User"
3. Input: username, email, password, full_name, phone, role
4. System inserts into `users` table
5. Optionally assign business access via `user_business_assignment`
6. Optionally grant menu permissions via `user_menu_permissions`

**SQL:**
```sql
INSERT INTO users (username, email, password, full_name, phone, role_id) 
VALUES ('john', 'john@email.com', hash, 'John Staff', '08123', 3);
```

---

### 2. Create New Business
1. Developer login → Developer Panel
2. Click "Add Business"
3. Input: business_name, business_type, owner_user
4. System automatically:
   - Creates database: `adf_hotel_narayana`
   - Imports business_template.sql
   - Inserts into `businesses` table
   - Creates `business_menu_config` (enable default menus)
   - Assigns `owner_id`

**Code Flow:**
```php
$dbMgr = new DatabaseManager();
$dbMgr->createBusinessDatabase('adf_hotel_narayana');

// Insert into master database
$db = Database::getInstance();
$db->insert('businesses', [
    'business_code' => 'HOTEL_01',
    'business_name' => 'Narayana Hotel',
    'business_type' => 'hotel',
    'database_name' => 'adf_hotel_narayana',
    'owner_id' => 5
]);
```

---

### 3. Assign User to Business
1. Owner login → User Management
2. Click "Assign Staff to Business"
3. Select user
4. System inserts into `user_business_assignment`

---

### 4. Grant Menu Permission
1. Owner login → Permission Management
2. Select user
3. Check menus user dapat akses
4. Check permissions (view, create, edit, delete)
5. System inserts into `user_menu_permissions`

---

### 5. User Login & Menu Access
1. User login
2. System checks `users` table
3. System gets `user_business_assignment` for user
4. System gets `user_menu_permissions` for selected business
5. Display menus and set capabilities based on permissions

---

## INITIALIZATION STEPS

### First Time Setup

```bash
# 1. Create master database
php -r "
require 'config/config.php';
require 'includes/DatabaseManager.php';
\$mgr = new DatabaseManager();
\$mgr->initializeMasterDatabase('adf_system', 'database/adf_system_master.sql');
echo 'Master database created!';
"

# 2. Create first business
php -r "
require 'config/config.php';
require 'includes/DatabaseManager.php';
\$mgr = new DatabaseManager();
\$mgr->createBusinessDatabase('adf_hotel_narayana');
echo 'Business database created!';
"
```

---

## MIGRATION FROM OLD SYSTEM

If migrating from old single-database system:

1. **Export old data** → CSV/JSON
2. **Create new master database** → `adf_system`
3. **Create new business databases** → `adf_{type}_{name}`
4. **Map old data:**
   - Users → `master.users`
   - Business data → respective business databases
   - Permissions → design from scratch
5. **Test thoroughly** before going live

---

## BACKUP & RESTORE

Use DatabaseManager methods:
```php
$mgr = new DatabaseManager();

// Backup
$backupFile = $mgr->backupDatabase('adf_system', '/backups/');

// Restore
$mgr->restoreDatabase('adf_system', '/backups/backup_adf_system_2026-02-07.sql');
```

---

## PERFORMANCE CONSIDERATIONS

1. **Indexing:** All FK and frequently searched columns are indexed
2. **Partitioning:** Consider partitioning `cash_book` and `audit_logs` by date for large businesses
3. **Archiving:** Archive old transactions periodically
4. **Connection pooling:** Use connection pooling for multiple concurrent users

---

## SECURITY NOTES

1. **Passwords:** Always hash with `password_hash()`
2. **SQL Injection:** Use prepared statements (PDO with parameterized queries)
3. **Access Control:** Always check permissions before operations
4. **Audit Trail:** All changes logged in `audit_logs`
5. **Database User:** Create separate DB user with limited privileges for production

---

## Next Steps

1. ✅ Database schema created
2. ⏳ Create PHP update manager & API endpoints
3. ⏳ Create Developer Dashboard
4. ⏳ Create Permission Management UI
5. ⏳ Migrate existing data
6. ⏳ Testing & QA

---

**Last Updated:** 2026-02-07
**Version:** 2.0.0
**Architect:** ADF System Development Team
