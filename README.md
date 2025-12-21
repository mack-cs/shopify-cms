# Shopify Editor (Laravel + Filament) - Technical Documentation

This document describes how to reproduce, run, and operate the Shopify CSV editor end-to-end.

## What this app does

- Imports a Shopify product CSV and stores every row as raw data.
- Normalizes those rows into products, variants, and images for editing.
- Tracks approvals (2 approvals required per product version).
- Exports a Shopify-compatible CSV (all or approved-only).
- Provides a Filament admin panel for managing imports, products, categories, and colors.

Primary code paths:
- Import pipeline: `app/Services/ShopifyCsvImporter.php`, `app/Services/RowClassifier.php`, `app/Services/Normalizer.php`
- Export pipeline: `app/Services/ShopifyCsvExporter.php`
- Admin UI: `app/Filament/Resources/*.php`

## Requirements

- PHP 8.2+
- Composer
- Node.js + npm
- Database: MySQL/MariaDB recommended (see notes about SQLite below)
- Laravel CLI tooling (included via Composer)

## Quick start (local)

1) Install dependencies
```
composer install
npm install
```

2) Create `.env`
```
copy .env.example .env
php artisan key:generate
```

3) Configure database in `.env`
Recommended (MySQL):
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shopify_editor
DB_USERNAME=root
DB_PASSWORD=secret
```

SQLite (local-only) needs extra work; see "SQLite caveat" below.

4) Run migrations
```
php artisan migrate
```

5) Seed users (and optional reference data)
```
php artisan db:seed
```
Optional:
```
php artisan db:seed --class=CategorySeeder
php artisan db:seed --class=ColorSeeder
```

6) Link storage for public file access
```
php artisan storage:link
```

7) Run the app (dev)
```
composer run dev
```
This runs:
- `php artisan serve`
- `php artisan queue:listen --tries=1`
- `npm run dev`

Admin panel: `http://localhost:8000/admin`

## Default users (seeded)

From `database/seeders/UserSeeder.php`:
- `admin@leighavenue.co.za` / password: `password`
- `shonayimack@mackscs.com` / password: `#Mack#36#LA#Dev#`

These credentials are for local/dev only. Change them immediately after first login.

## Access control

Only emails that end with:
- `@mackscs.com`
- `@leighavenue.co.za`

are allowed to access Filament. This is enforced in `app/Models/User.php`.

## Data flow (import -> edit -> export)

1) Upload CSV in Filament "Imports"
- File upload stores to `storage/app/public/imports`.
- The Import record stores the filename, mode, status, and CSV headers.

2) Process Import
Processing uses `ShopifyCsvImporter`:
- Clears previous data for that Import (rows and products).
- Reads CSV headers and preserves column order.
- Classifies each row as:
  - `product_primary` (first row per handle)
  - `variant` (SKU/options present)
  - `image` (image src present)
  - `unknown`
- Stores every row in `shopify_rows` as JSON (exact header/value pairs).
- Builds normalized tables via `Normalizer`.

3) Normalization
`Normalizer`:
- Creates a Product per handle.
- Creates Variants per row of type `variant`.
- Creates Images for any row with image src.
- Syncs Categories and Colors lookup tables.
- Sets internal fields:
  - `batch` (based on import timestamp)
  - `is_bundle` (true if handle/title contains "trio" or "quad")

4) Editing
Products are edited in Filament "Products".
Changes:
- Are logged in `change_logs` via `ProductObserver`.
- Bump `approval_version` if a meaningful field changes (resets approvals).

5) Approvals
Each product version needs 2 distinct user approvals.
Approvals are stored in `approvals` with `approval_version`.

6) Export
Exports are written to:
- `storage/app/public/exports/products_YYYYMMDD_HHMMSS_all.csv`
- `storage/app/public/exports/products_YYYYMMDD_HHMMSS_approved.csv`

`Export (Approved)` only includes handles with 2 approvals for the current version.

## Import modes

In Filament "Imports":
- `overwrite`: deletes rows/products for all other imports before processing this one.
- `append`: keeps existing imports; only deletes rows/products for this import before reprocessing.

`is_current` is used to flag the active import. Only current imports can be processed or exported.

## CSV header expectations

Headers are stored exactly as uploaded and later reused for export.
The importer and exporter rely on these Shopify headers:
- `Handle`
- `Title`
- `Body (HTML)`
- `Vendor`
- `Tags`
- `Product Category`
- `Google Shopping / Google Product Category`
- `Color (product.metafields.shopify.color-pattern)`
- `Variant SKU`
- `Variant Price`
- `Variant Compare At Price`
- `Variant Barcode`
- `Option1 Name`, `Option1 Value`
- `Option2 Name`, `Option2 Value`
- `Option3 Name`, `Option3 Value`
- `Image Src`
- `Image Position`
- `Image Alt Text`
- `Status`
- `SEO Title`
- `SEO Description`

Defined in `app/Services/HeaderStore.php`. If your Shopify CSV uses different header names, update that file.

## Database schema overview

Core tables:
- `imports`: upload metadata, status, headers, is_current
- `shopify_rows`: every CSV row with full JSON payload
- `products`: normalized product data + approval_version
- `variants`: normalized variants
- `images`: normalized images
- `categories`: curated category list + Google category mapping
- `colors`: curated color list
- `approvals`: product approvals per user + version
- `change_logs`: audit trail for product edits
- `notifications`: Filament database notifications

Schema is defined in `database/migrations/`.

## SQLite caveat

`database/migrations/2025_12_20_140000_update_approvals_unique_index.php` uses:
```
SHOW INDEX FROM approvals
```
which is MySQL/MariaDB-specific. On SQLite this migration will fail.

If you want to use SQLite:
- Comment out or adjust this migration to use SQLite-compatible index checks, or
- Switch to MySQL/MariaDB (recommended).

## File storage

- Imports: `storage/app/public/imports`
- Exports: `storage/app/public/exports`

Run `php artisan storage:link` so `public/storage` serves files.

## Notifications and queues

Filament uses database notifications. Queue connection is set to `database` in `.env.example`.
Run the queue listener in dev (done by `composer run dev`).

## Key locations

- Import/Export services: `app/Services/`
- Filament resources: `app/Filament/Resources/`
- Product change logging: `app/Observers/ProductObserver.php`
- Panel config: `app/Providers/Filament/AdminPanelProvider.php`
- Seeds: `database/seeders/`
- Migrations: `database/migrations/`

## Repro checklist

- [ ] PHP 8.2+ and Node installed
- [ ] `composer install` and `npm install`
- [ ] `.env` created and `APP_KEY` generated
- [ ] Database configured (MySQL recommended)
- [ ] `php artisan migrate`
- [ ] `php artisan db:seed` (users)
- [ ] `php artisan storage:link`
- [ ] `composer run dev`

