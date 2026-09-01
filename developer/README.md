# Developer Panel - ADF System

## Quick Start

1. **Jalankan Setup:**
   - Buka: `http://localhost/adf_system/developer/setup.php`
   - Klik "Run Setup" untuk membuat database

2. **Login:**
   - URL: `http://localhost/adf_system/developer/`
   - Username: `developer`
   - Password: `developer123`

## Features

### 👥 User Management
- Create/Edit/Delete users
- Assign roles (Developer, Owner, Staff)
- Activate/Deactivate users

### 🏢 Business Management
- Add new business → **Auto-create database**
- Configure business type (hotel, restaurant, retail, etc.)
- Assign owner to business
- Enable/disable menus per business

### 📋 Menu Configuration
- Add/Edit menu items
- Set menu icons and order
- Parent-child menu structure
- Enable/disable menus

### 🔐 User Permissions
- Assign users to businesses
- Granular permissions per menu:
  - **View** - Can see the menu/data
  - **Create** - Can add new records
  - **Edit** - Can modify existing records
  - **Delete** - Can remove records

### 🗄️ Database Management
- Initialize master database
- Backup business databases
- Reset database (with confirmation)
- View table structure

### 📝 Audit Logs
- Track all system activities
- Filter by action type, user, date
- View changes with JSON data

### ⚙️ System Settings
- Configure system parameters
- View server information

## Database Structure

```
adf_system (Master Database)
├── roles              - User roles
├── users              - All users
├── businesses         - Business registry
├── menu_items         - Menu configuration
├── business_menu_config   - Menu enabled per business
├── user_business_assignment - User-business mapping
├── user_menu_permissions  - Granular permissions
├── settings           - System settings
└── audit_logs         - Activity tracking

adf_[business_code] (Per-Business Database)
├── divisions          - Business divisions
├── categories         - Account categories
├── accounts           - Chart of accounts
├── cash_book          - Cash transactions
├── bank_accounts      - Bank accounts
├── inventory          - Stock items
├── customers          - Customer data
├── suppliers          - Supplier data
├── sales              - Sales records
├── purchases          - Purchase records
├── daily_shifts       - Shift management
└── system_logs        - Business logs
```

## File Structure

```
developer/
├── index.php          - Dashboard
├── login.php          - Login page
├── logout.php         - Logout handler
├── setup.php          - Quick database setup
├── users.php          - User management
├── businesses.php     - Business management
├── menus.php          - Menu configuration
├── permissions.php    - User permissions
├── database.php       - Database tools
├── audit.php          - Audit logs
├── settings.php       - System settings
└── includes/
    ├── dev_auth.php   - Authentication class
    ├── header.php     - Sidebar & navigation
    └── footer.php     - Scripts & footer
```

## Security Notes

- Developer panel requires `developer` role login
- All actions are logged in audit_logs
- Session expires after 8 hours
- Passwords are hashed with bcrypt

## Theme

- Dark theme with purple accents
- Bootstrap 5.3 + Bootstrap Icons
- Responsive sidebar navigation
