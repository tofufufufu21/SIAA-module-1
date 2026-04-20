# IT&DS Module 1 — OOP Setup Guide

## ══ TERMINAL COMMANDS (VS Code terminal) ══

### STEP 1 — Copy project to XAMPP

**Windows (PowerShell):**
```powershell
# Run from the project root folder
xcopy /E /I . "C:\xampp\htdocs\itds_oop"
```

**macOS / Linux:**
```bash
cp -r . /Applications/XAMPP/htdocs/itds_oop
# OR for Linux XAMPP:
cp -r . /opt/lampp/htdocs/itds_oop
```

---

### STEP 2 — Create the database

**Windows:**
```cmd
"C:\xampp\mysql\bin\mysql" -u root -e "CREATE DATABASE itds_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"C:\xampp\mysql\bin\mysql" -u root itds_tracker < sql/module1_schema.sql
```

**macOS:**
```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE itds_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
/Applications/XAMPP/xamppfiles/bin/mysql -u root itds_tracker < sql/module1_schema.sql
```

**Or via phpMyAdmin:**
1. Go to http://localhost/phpmyadmin
2. Create database `itds_tracker` (utf8mb4)
3. Select it → Import → choose `sql/module1_schema.sql`

---

### STEP 3 — Edit DB credentials

Edit `config/database.php`:
```php
return [
    'host'   => 'localhost',
    'dbname' => 'itds_tracker',
    'user'   => 'root',
    'pass'   => '',          // blank for default XAMPP
    'charset'=> 'utf8mb4',
];
```

---

### STEP 4 — Set upload permissions (macOS/Linux only)

```bash
chmod -R 775 /Applications/XAMPP/htdocs/itds_oop/public/uploads
```

---

### STEP 5 — Open browser

```
http://localhost/itds_oop/public/
```

---

## ══ PROJECT STRUCTURE ══

```
itds_oop/
│
├── bootstrap/
│   └── app.php              ← PSR-4 autoloader + error handler
│
├── config/
│   ├── database.php         ← DB credentials (edit once)
│   └── app.php              ← Upload config, current user
│
├── routes/
│   └── api.php              ← All route registrations
│
├── app/
│   ├── core/
│   │   ├── Database.php     ← PDO singleton (reused by all modules)
│   │   ├── BaseRepository.php ← insert/update/paginate helpers
│   │   ├── BaseController.php ← JSON response helpers
│   │   ├── Router.php       ← URI dispatcher
│   │   └── Validator.php    ← Chainable input validation
│   │
│   ├── models/
│   │   ├── Asset.php        ← Asset DTO + status constants
│   │   └── StockItem.php    ← StockItem + StockTransaction DTOs
│   │
│   ├── repositories/
│   │   ├── AssetRepository.php      ← ALL DB logic for assets
│   │   ├── InventoryRepository.php  ← ALL DB logic for stock
│   │   └── LookupRepository.php     ← Shared reference data
│   │
│   ├── services/
│   │   ├── AssetService.php         ← Business logic for assets
│   │   └── InventoryService.php     ← Business logic for stock
│   │
│   ├── controllers/
│   │   ├── AssetController.php      ← THIN: HTTP → Service → JSON
│   │   ├── InventoryController.php  ← THIN: HTTP → Service → JSON
│   │   ├── AttachmentController.php ← File upload/download/delete
│   │   ├── LookupController.php     ← Reference data API
│   │   └── DashboardController.php  ← Dashboard stats aggregation
│   │
│   └── helpers/
│       └── FileUploader.php         ← Secure file upload handler
│
├── public/                  ← Web root (point Apache here)
│   ├── index.php            ← Single entry point
│   ├── index.html           ← SPA UI (exact mockup)
│   ├── .htaccess            ← URL rewriting
│   ├── uploads/assets/      ← Uploaded files
│   └── assets/
│       ├── css/main.css     ← All styles
│       └── js/
│           ├── app.js       ← ApiClient, TableRenderer, FormHelper, Toast, Modal
│           └── main.js      ← Page logic (Dashboard, Asset Master, Stock Room)
│
└── sql/
    └── module1_schema.sql   ← Full DB schema
```

---

## ══ API ENDPOINTS ══

All endpoints are served by `public/index.php`.

### Dashboard
```
GET  /dashboard/stats
```

### Assets
```
GET  /asset/list?page=&search=&status=&category_id=&department_id=&location_id=
GET  /asset/view?id=X
POST /asset/create       { asset_tag, category_id, status, model, ... }
POST /asset/update       { id, ...fields }
POST /asset/transfer     { asset_id, to_department_id, to_location_id, reason }
POST /asset/status       { asset_id, status, reason }
GET  /asset/history?id=X
GET  /asset/children?id=X
```

### Attachments
```
GET  /attachment/list?asset_id=X
POST /attachment/upload  (multipart: file, asset_id, label, uploaded_by)
POST /attachment/delete  { id }
GET  /attachment/download?id=X
```

### Inventory Items
```
GET  /item/list?page=&search=&category_id=
POST /item/create  { item_code, name, category_id, unit_of_measure, ... }
POST /item/update  { id, ...fields }
```

### Stock Transactions
```
GET  /stock/list?page=&search=
POST /stock/receive   { item_id, location_id, quantity, notes }
POST /stock/issue     { item_id, location_id, quantity, reason_code }
POST /stock/transfer  { item_id, from_location_id, to_location_id, quantity }
POST /stock/adjust    { item_id, location_id, new_quantity, reason_code }
GET  /stock/low
GET  /stock/transactions?item_id=&type=
```

### Lookups (shared reference data)
```
GET  /lookup/list?resource=categories|departments|sites|locations|vendors|users
GET  /lookup/view?resource=X&id=Y
POST /lookup/create?resource=X  { name, ... }
POST /lookup/update?resource=X&id=Y  { name, ... }
POST /lookup/delete?resource=X&id=Y
```

---

## ══ REUSABILITY FOR OTHER MODULES ══

Other modules add files to the SAME project and register routes in `routes/api.php`.

### Include core helpers:
```php
// In a new Module 2 service:
use App\Core\Database;
use App\Core\BaseRepository;
use App\Core\Validator;
use App\Repositories\InventoryRepository; // reuse stock from Module 1

// record_transaction() and adjustStockLevel() in InventoryRepository
// are already designed for Module 3 (WO parts) and Module 4 (GRN)
```

### Add new routes:
```php
// routes/api.php — add Module 2 routes:
$router->get( '/pm/list',    [PMController::class, 'list']);
$router->post('/pm/create',  [PMController::class, 'create']);
```

### JS — use the shared utilities:
```javascript
// In a new module JS file:
const r = await ApiClient.get('/pm/list?page=1');
TableRenderer.render('pm-tbody', r.data.items, pmRow, 7);
Toast.show('success', 'PM Created');
```

---

## ══ COMMON ISSUES ══

| Problem | Fix |
|---------|-----|
| `Database connection failed` | Check `config/database.php` credentials |
| Blank page | Open DevTools → Console; check PHP error log |
| 404 on all routes | Confirm `mod_rewrite` is enabled in Apache |
| File upload fails | `chmod 775 public/uploads/` |
| `Class not found` | Check namespace matches folder structure exactly |
