# IT&DS Tracker — Module 1: Asset & Inventory Master
**Team Assignment:** Module 1 Backend  
**Stack:** PHP + MySQL + HTML/CSS/Vanilla JS

---

## 📁 Project Structure

```
module1/
├── index.html                    ← Temporary UI (replace with proper mockups)
├── sql/
│   └── module1_schema.sql        ← Full MySQL schema (run this first)
├── config/
│   └── database.php              ← DB credentials (edit once, all modules share)
├── includes/
│   └── api_helpers.php           ← Shared API utilities (reusable by Modules 2–6)
└── api/
    ├── assets.php                ← Serialized asset CRUD + lifecycle actions
    ├── stock_items.php           ← Consumables inventory
    ├── stock_transactions.php    ← GRN / Issue / Transfer / Adjustment
    ├── cycle_counts.php          ← Physical inventory / cycle count
    └── lookups.php               ← Categories, Departments, Sites, Locations, Vendors, Users
```

---

## ⚙️ Setup

### 1. Database
```sql
CREATE DATABASE itds_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE itds_tracker;
SOURCE module1/sql/module1_schema.sql;
```

### 2. Config
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'itds_tracker');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

### 3. Web Server
Place the `module1/` folder inside your Apache/Nginx document root (e.g., `htdocs/module1/`).  
Open `http://localhost/module1/` in your browser.

---

## 🔌 API Reference

All endpoints return JSON: `{ success: bool, message: string, data: any }`

### Assets (`api/assets.php`)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `?page=&search=&status=&category_id=` | List assets (paginated) |
| GET | `?id=X` | Get single asset with log + attachments |
| POST | (body) | Create asset |
| PUT | `?id=X` (body) | Update asset fields |
| DELETE | `?id=X` | Soft-delete / retire |
| POST | `?action=assign` | Assign / transfer custody |
| POST | `?action=checkout` | Check out (shared asset) |
| POST | `?action=checkin` | Check in |
| POST | `?action=retire` | Retire asset |
| POST | `?action=dispose` | Dispose asset |
| GET | `?action=history&id=X` | Lifecycle log |
| GET | `?action=children&id=X` | Child assets |

**Create/Update body fields:**  
`asset_tag*`, `category_id*`, `serial_number`, `barcode`, `make`, `model`,  
`cpu`, `ram`, `storage`, `os`, `firmware_version`,  
`vendor_id`, `po_number`, `invoice_number`, `purchase_cost`, `date_acquired`,  
`warranty_start`, `warranty_end`, `sla_tier`, `support_contract_ref`,  
`status` (In-Use/In-Stock/Under Repair/Retired/Disposed/Lost),  
`assigned_user_id`, `department_id`, `location_id`, `cost_center`,  
`parent_asset_id`, `installed_at`, `connected_to`, `notes`, `performed_by*`

---

### Stock Items (`api/stock_items.php`)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `?page=&search=` | List with total qty |
| GET | `?id=X` | Item + stock levels + batches |
| POST | (body) | Create item |
| PUT | `?id=X` | Update item |
| DELETE | `?id=X` | Deactivate |
| GET | `?action=low_stock` | Items below reorder point |
| GET | `?action=transactions&id=X` | Ledger for item |

---

### Stock Transactions (`api/stock_transactions.php`)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `?page=&type=` | List all transactions |
| POST | `?action=grn` | Goods received |
| POST | `?action=issue` | Issue stock |
| POST | `?action=transfer` | Transfer between locations |
| POST | `?action=adjust` | Manual adjustment |

**GRN body:** `item_id*, location_id*, quantity*, performed_by*, reference_type, reference_id, notes, batch_number, lot_number, expiry_date`  
**Issue body:** `item_id*, location_id*, quantity*, performed_by*, reason_code, reference_type, reference_id, notes`  
**Transfer body:** `item_id*, from_location_id*, to_location_id*, quantity*, performed_by*, reason_code, notes`  
**Adjust body:** `item_id*, location_id*, new_quantity*, performed_by*, reason_code, notes`

---

### Cycle Counts (`api/cycle_counts.php`)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `?page=&status=` | List plans |
| GET | `?id=X` | Plan + entries |
| POST | (body) | Create plan (auto-populates from stock if location given) |
| POST | `?action=start&id=X` | Start count |
| POST | `?action=entry` | Submit count entry |
| POST | `?action=approve` | Approve/reject entry variance |
| POST | `?action=reconcile&id=X` | Apply approved variances to stock |

---

### Lookups (`api/lookups.php`)

| Method | URL | Description |
|--------|-----|-------------|
| GET | `?resource=categories` | All categories |
| GET | `?resource=departments` | All departments |
| GET | `?resource=sites` | All sites |
| GET | `?resource=locations[&site_id=X]` | Locations (optional filter by site) |
| GET | `?resource=vendors` | All vendors |
| GET | `?resource=users[&search=]` | Users |
| POST | `?resource=X` | Create record |
| PUT | `?resource=X&id=Y` | Update record |
| DELETE | `?resource=X&id=Y` | Soft delete |

---

## ♻️ Reusability for Other Modules

The following functions/files are designed for reuse:

| File / Function | Used By |
|-----------------|---------|
| `config/database.php` → `db()` | **All modules** |
| `includes/api_helpers.php` → `respond_ok/error`, `paginate`, `validate_*` | **All modules** |
| `api/lookups.php` | **All modules** (reference data) |
| `api/stock_transactions.php` → `record_transaction()`, `adjust_stock()` | **Module 3** (parts from work orders), **Module 4** (GRN from PO) |
| `api/assets.php` → `log_lifecycle()` | **Module 3** (WO updates asset status), **Module 5** (repair/disposal) |
| `sql/module1_schema.sql` tables: `assets`, `stock_items`, `stock_levels`, `stock_transactions` | **Modules 3, 4, 5, 6** |

### How other modules include helpers:
```php
// In any Module 2–6 API file:
require_once __DIR__ . '/../module1/includes/api_helpers.php';
require_once __DIR__ . '/../module1/config/database.php';

// Or if in same directory structure:
require_once '../includes/api_helpers.php';
```

---

## 🗄️ Database Tables (Module 1)

| Table | Purpose |
|-------|---------|
| `categories` | Asset/item categories |
| `departments` | Org departments |
| `sites` | Physical sites/branches |
| `locations` | Rooms/racks/storerooms within sites |
| `vendors` | Vendor directory |
| `users` | System users |
| `assets` | Serialized asset master |
| `asset_lifecycle_log` | All asset state changes / transfers |
| `asset_checkouts` | Shared asset check-out/in log |
| `asset_attachments` | Files linked to assets |
| `stock_items` | Consumable item catalog |
| `stock_levels` | Qty on hand per item per location |
| `stock_batches` | Batch/lot/expiry tracking |
| `stock_transactions` | Full stock ledger |
| `cycle_count_plans` | Physical inventory plans |
| `cycle_count_entries` | Count entries + variances |

---

## 📌 Notes for UI Team

The `index.html` is a **temporary functional UI only**. All API calls are in the `<script>` block using the `apiFetch()` wrapper. When you replace the UI, keep the same API endpoints — no backend changes needed.

Key JS helpers available:
- `apiFetch(url, opts)` — wraps fetch with JSON headers
- `getLookup(resource)` — cached lookup data
- `populateSelect(selectId, resource)` — fills a `<select>` from API
