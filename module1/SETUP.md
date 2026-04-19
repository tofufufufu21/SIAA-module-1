# IT&DS Asset & Inventory — Module 1 Setup Guide
# Run these commands in your VS Code terminal (Git Bash / PowerShell / CMD)

# ══════════════════════════════════════════════════════════════
# STEP 1 — PREREQUISITES
# ══════════════════════════════════════════════════════════════
# Install XAMPP: https://www.apachefriends.org/
# Make sure Apache and MySQL are running in XAMPP Control Panel

# ══════════════════════════════════════════════════════════════
# STEP 2 — COPY PROJECT TO XAMPP
# ══════════════════════════════════════════════════════════════

# Windows (run in PowerShell or CMD inside your project folder):
# xcopy /E /I . "C:\xampp\htdocs\itds"

# macOS / Linux:
# cp -r . /Applications/XAMPP/htdocs/itds
# OR: cp -r . /opt/lampp/htdocs/itds

# ══════════════════════════════════════════════════════════════
# STEP 3 — DATABASE SETUP
# ══════════════════════════════════════════════════════════════

# Open phpMyAdmin: http://localhost/phpmyadmin
# OR run in your terminal:

# Windows (XAMPP):
# "C:\xampp\mysql\bin\mysql" -u root -e "CREATE DATABASE itds_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# "C:\xampp\mysql\bin\mysql" -u root itds_tracker < sql/module1_schema.sql

# macOS/Linux (XAMPP):
# /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE itds_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# /Applications/XAMPP/xamppfiles/bin/mysql -u root itds_tracker < sql/module1_schema.sql

# ══════════════════════════════════════════════════════════════
# STEP 4 — CONFIGURE DATABASE
# ══════════════════════════════════════════════════════════════
# Edit config/database.php:
#   DB_HOST = 'localhost'
#   DB_NAME = 'itds_tracker'
#   DB_USER = 'root'
#   DB_PASS = ''          ← leave blank for default XAMPP

# ══════════════════════════════════════════════════════════════
# STEP 5 — SET UPLOAD FOLDER PERMISSIONS
# ══════════════════════════════════════════════════════════════

# Windows: No action needed (XAMPP runs as current user)

# macOS/Linux:
# chmod -R 775 /Applications/XAMPP/htdocs/itds/uploads
# chmod -R 775 /opt/lampp/htdocs/itds/uploads

# ══════════════════════════════════════════════════════════════
# STEP 6 — OPEN IN BROWSER
# ══════════════════════════════════════════════════════════════
# http://localhost/itds/

# ══════════════════════════════════════════════════════════════
# PROJECT STRUCTURE
# ══════════════════════════════════════════════════════════════
# itds/
# ├── index.html                  ← Main SPA entry point (matches mockup)
# ├── assets/
# │   ├── css/
# │   │   └── main.css            ← All styles (dark sidebar + light content)
# │   └── js/
# │       └── main.js             ← All frontend logic + API calls
# ├── api/
# │   ├── assets.php              ← Asset CRUD + lifecycle
# │   ├── attachments.php         ← File upload/download
# │   ├── stock_items.php         ← Consumables inventory
# │   ├── stock_transactions.php  ← GRN / Issue / Transfer / Adjust
# │   ├── cycle_counts.php        ← Physical inventory
# │   └── lookups.php             ← Shared reference data
# ├── config/
# │   └── database.php            ← DB credentials (shared by all modules)
# ├── includes/
# │   └── api_helpers.php         ← Shared PHP utilities (reusable by all modules)
# ├── sql/
# │   └── module1_schema.sql      ← Full database schema
# └── uploads/
#     └── assets/                 ← Uploaded files (auto-created)

# ══════════════════════════════════════════════════════════════
# PAGES IMPLEMENTED (Module 1 only)
# ══════════════════════════════════════════════════════════════
# Dashboard   — stat cards, quick actions, alert banners
# Asset Master — table, filters, Add/Edit/View/Transfer modals
# Stock Room   — table, stat cards, Add Item / Issue / GRN modals
# Cycle Counts — plan list, create plan, start, reconcile

# ══════════════════════════════════════════════════════════════
# REUSABILITY FOR OTHER MODULES
# ══════════════════════════════════════════════════════════════
# Other module PHP files include helpers like this:
#   require_once __DIR__ . '/../includes/api_helpers.php';
#   require_once __DIR__ . '/../config/database.php';
#
# Functions available to all modules:
#   db()                     — PDO singleton connection
#   respond_ok($data)        — JSON success response
#   respond_error($msg)      — JSON error response
#   paginate($pdo, ...)      — Paginated query helper
#   record_transaction(...)  — Stock ledger entry (Module 3, 4)
#   adjust_stock(...)        — Update stock level (Module 3, 4)
#   log_lifecycle(...)       — Asset history entry (Module 3, 5)

# ══════════════════════════════════════════════════════════════
# COMMON ISSUES
# ══════════════════════════════════════════════════════════════
# "Database connection failed" → Check DB_USER/DB_PASS in config/database.php
# "File upload failed"         → Check uploads/ folder permissions
# API returns 404              → Make sure .htaccess allows PHP, or check file paths
# Blank page                   → Open browser DevTools → Console for JS errors
