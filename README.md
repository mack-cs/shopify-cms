# Shopify Editor (Laravel + Filament) - Technical Documentation

This document describes how to reproduce, run, and operate the Shopify CSV editor end-to-end.

## Change Log

### 2026-04-24

Draft deletion workflow was changed to protect Shopify-backed records and improve audit logging.

- Drafts with a `handle` can no longer be deleted directly.
- Users must use `Request Delete` to open a deletion request for handled drafts.
- A second user must use `Approve Delete` to reach `2/2` approval.
- After the second approval, deletion is queued:
  - the Shopify product is deleted
  - linked local Shopify row and metafield data is cleaned up
  - the linked local product mirror is deleted
  - the draft record is deleted
- All handled-draft deletion request and approval events are logged in `change_logs`.
- Drafts without a `handle` are treated as local-only drafts.
- Local-only drafts can be deleted immediately without approval.
- Local-only draft deletion is still logged in `change_logs` with who deleted it and when.
- The drafts table now shows a `Delete Request` status badge so users can see whether a record is `None`, `Pending 1/2`, `Pending 2/2`, `Processing`, or `Local Only`.
- Bulk draft deletion was replaced with three safer bulk actions:
  - `Request Delete`
  - `Approve Delete`
  - `Delete Local`
- Delete approvals now send cross-user alerts and show a blocking popup for eligible approvers.
- Approvers can now `Approve`, `Reject`, or `Ignore` from the popup:
  - `Approve` records the second approval and queues deletion at `2/2`
  - `Reject` closes the delete request, logs the rejection, and notifies the requester
  - `Ignore` dismisses the popup only and leaves the request pending
- A dedicated **Audit & History -> Deletion Requests** page was added for tracking all delete requests and outcomes.
- A dedicated **Audit & History -> Shopify Missing Products** page was added to track products that existed in a previous Shopify sync but are missing from the latest one.
- After Shopify sync, matching drafts are now flagged as missing from Shopify and blocked from automatic re-sync.
- The **New Products** page now shows a warning banner when blocked recovery drafts exist.
- Missing-from-Shopify drafts now support three explicit actions:
  - `Clean Local`
  - `Investigate`
  - `Enable Recovery`
- Until `Enable Recovery` is used, blocked drafts cannot silently recreate products or be pushed back to Shopify.

## Quick Start (Collections)

1) Sync collections (collections-only)
```
Filament → Catalog → Collections → Sync Collections
```

2) Import SEO CSV (optional)
```
Filament → Catalog → Collections → Import SEO CSV
```

CSV template (headers only):
```csv
Handle,Title,Description HTML,SEO Title,SEO Description
```

3) Approve SEO (2 people)
- Use **Bulk Approve** in Collections (needs two distinct users).

4) Push approved to Shopify
- Row or bulk **Push to Shopify** (only approved records).

## What this app does

- Imports a Shopify product CSV and stores every row as raw data.
- Normalizes those rows into products, variants, and images for editing.
- Tracks approvals (2 approvals required per product version).
- Exports a Shopify-compatible CSV (all or approved-only).
- Provides a Filament admin panel for managing imports, products, categories, and colors.

Primary code paths:
- Import pipeline: `app/Services/ShopifyCsvImporter.php`, `app/Services/RowClassifier.php`, `app/Services/Normalizer.php`
- API sync pipeline: `app/Services/ShopifyApiImporter.php`, `app/Services/ShopifyApiClient.php`
- Export pipeline: `app/Services/ShopifyCsvExporter.php`
- Admin UI: `app/Filament/Resources/*.php`

## Requirements

- PHP 8.2+
- Composer
- Node.js + npm
- Database: MySQL/MariaDB
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

## Queue worker timeouts (production)

Shopify sync runs as a queued job and can take longer than 300s. Make sure your
queue worker timeout is high enough in production.

Example Supervisor command:
```
command=php /path/to/artisan queue:work --timeout=1800 --sleep=3 --tries=1
```

## UAT checklist

1. Run `php artisan migrate`.
If `image_assets` was left behind by the earlier failed migration, this migration now reconciles that partial table and adds the missing indexes.

2. Run `php artisan storage:link` and confirm the queue worker is running.

3. Open one product that has images and has not yet had its first full `2/2` approval.

4. Approve it with user 1, then approve it with user 2.

5. Confirm the first `2/2` approval:
- populates `images.approved_filename`
- populates `products.approved_handle`
- sets `products.first_handle_auto_lock_completed_at`
- sets `products.first_image_auto_rename_completed_at`
- does not auto-queue outbound Shopify image sync

6. Confirm the generated filenames follow the approved title plus position pattern, for example `pot-of-wisdom-bracelets-01.jpg`.

7. Confirm the generated approved handle follows the approved title slug, for example `pot-of-wisdom-bracelets`.

8. Run `Sync Approved to Shopify` for that product and confirm Shopify accepts the new handle.
After a successful product sync, confirm the local live `handle` is promoted to match `approved_handle`.
Confirm a pending redirect record is created from `/products/old-handle` to `/products/new-handle`.

9. Open the Images relation manager, select one or more images, and run `Sync Selected Images to Shopify`.
Confirm only the selected images are synced.

10. Edit the product again without changing the title, reset approvals, and approve it to `2/2` again.
Confirm image filenames are not auto-renamed a second time.
Confirm the approved handle is not auto-regenerated a second time.

11. Replace one existing image locally, select it in the Images relation manager, and run `Sync Selected Images to Shopify`.
Confirm the replacement image is republished into the correct position and unselected Shopify images remain on the product.

12. Click `Rename Images`, then select those renamed images and run `Sync Selected Images to Shopify`.
Confirm the new manual filenames are used and the stale previous Shopify images for those selected slots are removed from the product.

13. Confirm the selected-image sync bulk action is hidden for products that are not `2/2` approved or have no handle.

14. Open **Audit & History -> URL Redirects**.
Confirm the pending redirect can be exported and manually queued to Shopify.

15. After redirect sync completes, confirm the redirect record is marked `synced` and stores the Shopify redirect ID.


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

### Shopify sync snapshot history

Every `Sync from Shopify` run now also writes a downloadable CSV snapshot of the raw inbound Shopify state for that import.

- The raw row history remains in `shopify_rows`.
- A downloadable copy is stored in `storage/app/public/shopify-sync-snapshots`.
- Snapshot metadata is stored in `shopify_sync_snapshots`.
- Users can open **Product Feed** and use the `Shopify Snapshot` action on any historical import row to download the state of Shopify for that sync.
- If the snapshot file is missing, the action regenerates it from the stored `shopify_rows` for that import without changing products or the current workflow.

### Delete request workflow

- Shopify-backed deletions require `2/2` approval.
- The user who creates the delete request becomes approval `1/2`.
- A second eligible user must approve it to reach `2/2`.
- For handled drafts, products, and collections, deletion is queue-backed and audited.
- Request lifecycle:
  - `pending`
  - `processing`
  - `completed`
  - `rejected`
  - `failed`
- Approval popup behavior:
  - `Approve` continues the delete workflow
  - `Reject` closes the request and notifies the requester
  - `Ignore` dismisses the popup only
- Tracking:
  - request records are stored in `deletion_requests`
  - approvals are stored in `deletion_request_approvals`
  - timeline events are logged in `change_logs`
- Admins can review this history in **Audit & History -> Deletion Requests**.

### Missing from Shopify recovery workflow

- Each Shopify API sync now compares the latest import with the previous Shopify API import.
- If a product existed in the previous sync but is missing in the latest one, it is recorded in **Audit & History -> Shopify Missing Products**.
- If a matching draft still exists locally, that draft is treated as a recovery record and is automatically flagged as missing from Shopify.
- Flagged drafts are blocked from automatic re-sync so they do not silently slip back into Products or get pushed back to Shopify.
- The **New Products** page shows a warning banner when blocked recovery drafts exist.
- Recovery-draft actions:
  - `Clean Local`: remove the local Product record but keep the draft as a recovery record
  - `Investigate`: mark the draft for investigation and keep it blocked
  - `Enable Recovery`: explicitly allow the draft to sync back into Products again
- All of these state changes are logged in `change_logs`.
- Operational rule:
  - keep the draft blocked while you investigate a Shopify-side removal
  - only use `Enable Recovery` when you intentionally want that draft to repopulate Products / Shopify

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

### Product handle / URL workflow

- The editable `handle` field is treated as the current live Shopify handle.
- The first time a product reaches `2/2`, the app generates and locks an `approved_handle` from the approved title.
- That `approved_handle` is generated only once per product and is not auto-regenerated on later approval cycles.
- The live `handle` is not changed immediately at approval time. This is intentional so existing row linkage, image sync, and inbound Shopify sync keep working until the product is actually pushed.
- On the next successful `Sync Approved to Shopify`, the product sync sends the locked `approved_handle` to Shopify as the target handle.
- Only after Shopify accepts that product update does the app promote the local live `handle` to the approved one and update local handle-keyed records (`shopify_rows`, `shopify_metafields`, drafts, and style profiles).
- This gives a staged model:
  - `handle`: current live Shopify URL slug
  - `approved_handle`: locked SEO target slug waiting to become live

### Partial product sync workflow

- The existing `Sync Approved to Shopify` bulk action still performs a full outbound product sync for selected approved products.
- There is now a second bulk action on the Products table: `Sync Selected Fields to Shopify`.
- That action is manual and queue-backed. It only runs for products that already have `2/2` approval.
- Users choose one or more field groups to push:
  - product core fields
  - SEO title and description
  - metafields
  - variants and inventory
  - images
- If `Product core fields` is selected, users can then choose the exact core columns to push:
  - title
  - vendor
  - type
  - body HTML
  - tags
  - status
  - handle
  - category
- The picker also includes the product-level Shopify metafield fields that are edited on the product form, such as colors, target gender, age group, jewelry material, jewelry type, bracelet design, pattern category, product metals, materials and dimensions, and SEO deindex.
- Internal-only fields remain excluded from this sync picker, including `Bundle`, `Batch`, and `You Save`.
- Example: choose only `SEO title and description` to push just the SEO payload to Shopify without sending title, body, tags, variants, images, or other product fields.
- Example: choose `Product core fields` and then tick only `Title` and `Body HTML` to send those two fields without vendor, tags, status, handle, or category.
- Handle promotion only happens during the `product core fields` scope, never during SEO-only sync.
- Handle promotion only happens when the `Handle` core field is explicitly included.

### Product URL redirect workflow

- Redirects are created only when a successful Shopify product sync actually promotes a live product handle from the old value to the locked `approved_handle`.
- When that promotion happens, the app creates a pending redirect record automatically:
  - `path`: `/products/old-handle`
  - `target`: `/products/new-handle`
- Redirect creation in Shopify is manual. The app does not auto-push redirects.
- Users manage redirects from **Audit & History -> URL Redirects**.
- Available actions:
  - add a redirect manually for handles that were already changed before this workflow existed
  - export pending or selected redirects to CSV
  - queue pending or selected redirects for Shopify sync
  - ignore redirects that should not be pushed
- Shopify redirect sync runs in the background through a queue job.
- Successful sync stores the Shopify redirect ID and marks the record `synced`.
- Failed sync stores the Shopify error on the redirect record so users can retry.

### Product image workflow

- Product images are backed up into `image_assets` and can be republished from backup when Shopify loses the original image.
- Shopify-origin images are now backed up when they are first seen or when their remote source changes.
- The app preserves remotely removed Shopify images as recoverable local records instead of deleting them silently.
- The first time a product reaches `2/2` approval, image filenames are generated once from the approved product title plus image position.
- That first automatic rename only happens once per product. Later approval cycles do not auto-rename again.
- After the first full approval, any later image rename is manual via the product actions.
- Replacing an image locally remains the normal editing workflow. A local replacement marks that image for backup rebuild and Shopify image re-sync, but does not auto-rename it again.
- First full approval does not auto-sync images to Shopify. Outbound image sync is manual only.

Example filename pattern:
```text
pot-of-wisdom-bracelets-01.jpg
pot-of-wisdom-bracelets-02.jpg
```

### Image backup and recovery policy

This app treats Shopify image deletion as a recoverable event.

- Local image edits are the normal workflow.
- Shopify-side image deletion or replacement is treated as an exception path that must remain recoverable.
- The app keeps its own backup assets in `image_assets`.
- Recovery is explicit and admin-driven. The app does not automatically republish remotely removed images during ordinary sync.

Backup triggers:

- A newly imported Shopify image is queued for backup when it is first seen.
- An existing Shopify image is queued for backup again when its inbound Shopify source URL changes.
- A local image replacement still marks that image for backup rebuild through the normal image observer workflow.
- Manual product-level `Queue Image Backup` remains available as an operational fallback.
- The nightly reconcile job remains available as a safety net for pending, failed, missing-source, or remote-deleted image records.

Operational intent:

- Unchanged images should keep the existing backup asset and should not be needlessly re-backed up.
- New or changed Shopify-origin images should be backed up before a later Shopify-side removal can make them unrecoverable.
- This closes the gap where a merchant could add an image directly in Shopify and then delete it before the app ever stored a safe copy.

### Remote-deleted Shopify images

When Shopify sync detects that an image previously known to this app no longer exists remotely, the app does not delete the local image record.

- The image record is preserved locally.
- Its `sync_state` is set to `remote_deleted`.
- The row remains visible in the product Images manager because that view now uses all images, not only active images.
- Remote-deleted images are excluded from the normal active image set so they do not silently flow back into ordinary sync work.

This gives the super admin a traceable recovery list for accidental Shopify removals.

### Restore workflow for remote-deleted images

Remote-deleted images are restored only on demand.

- Only super admins can restore remote-deleted Shopify images.
- Restore can be done per image or in bulk from the Images relation manager.
- Restore first runs the image backup path so the app reuses an existing backup asset or attempts to create one if needed.
- Restore then republishes only the selected remote-deleted images to Shopify.
- Normal `Sync Selected Images to Shopify` refuses remote-deleted images and instructs the user to use the restore action instead.

Important recovery rule:

- If an image was already backed up before it was removed from Shopify, restore is reliable from the stored asset.
- If an image was never backed up and Shopify has already invalidated the original remote URL, the row may still exist locally but the binary file may no longer be recoverable.
- That is why backup-on-first-seen and backup-on-change are part of the protection model.

### Sync Images To Shopify

Products sync images to Shopify from the Images relation manager using a selected-images bulk action.

- It only syncs selected images, not title, description, variants, tags, or other product fields.
- It is only enabled for approved products (`2/2`) with a handle.
- It first rebuilds/reuses backups for the selected images, then republishes only those selected images to Shopify.
- Selected image sync preserves unselected Shopify product images.
- If selected image content changes locally, that image is republished.
- If the approved/manual filename changes, that image is republished and the stale previous Shopify image for that selected slot is removed from the product.
- It does not restore `remote_deleted` images. That is a separate super-admin recovery action.

This action is available from the product Images relation manager.

6) Export
Exports are written to:
- `storage/app/public/exports/products_YYYYMMDD_HHMMSS_all.csv`
- `storage/app/public/exports/products_YYYYMMDD_HHMMSS_approved.csv`

`Export (Approved)` only includes handles with 2 approvals for the current version.
If a product has a locked `approved_handle`, exports write that approved handle into the Shopify CSV even before the live local `handle` has been promoted.

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

## Shopify API sync

The app can now sync products directly from Shopify Admin API, while preserving the same CSV-style header structure.

## Shopify Collections (SEO + URL updates)

This app also syncs Shopify **collections** and allows pushing updates back to Shopify for SEO/title/description, with safe URL handling.

### Separate workflow (collections-only)

Collections are **independent of products**. They have their own:
- Sync flow (Collections-only job)
- Local storage
- Approvals (2 users required)
- CSV import for SEO/page title

### What is stored locally (minimal fields)

Collections are stored in the `collections` table with:
- `shopify_id` (Shopify GID, required for updates)
- `handle` (URL slug)
- `title` (on-page title)
- `description_html` (on-page description)
- `seo_title`, `seo_description`
- `approval_version` (for 2‑person approval gating)

Schema: `database/migrations/2026_02_11_000000_create_collections_table.php`  
Approval columns: `database/migrations/2026_02_11_010000_add_approval_version_to_collections_table.php`  
Approvals table: `database/migrations/2026_02_11_010100_create_collection_approvals_table.php`  
Model: `app/Models/ShopifyCollection.php`
Approvals model: `app/Models/CollectionApproval.php`

### How collection sync works

Collections are fetched during Shopify API sync:
- Importer: `app/Services/ShopifyApiImporter.php`
- Query: GraphQL `collections` (id, handle, title, descriptionHtml, seo)
- Stored via `ShopifyCollection::updateOrCreate()` keyed by (`import_id`, `shopify_id`)

This keeps collection records **deduplicated per import**.

### Avoiding duplicates (Collections)

Collections are scoped to the **current import** in the admin UI:
- Resource: `app/Filament/Resources/ShopifyCollectionResource.php`
- Query: `getEloquentQuery()` filters by `imports.is_current = true`

Old imports won’t show duplicates in the Collections list.

### Collections‑only sync (separate workflow)

Collections now have a dedicated sync button and job that **do not touch products**.

UI:
- Filament → **Catalog → Collections** → **Sync Collections**

Code:
- Job: `app/Jobs/ShopifyCollectionsSyncJob.php`
- Importer: `app/Services/ShopifyCollectionsImporter.php`
- Client: `app/Services/ShopifyApiClient.php`

GraphQL:
- `customCollections` (manual collections)
- `smartCollections` (automated collections)

Both are merged and de‑duplicated by Shopify ID.

### Pushing collections back to Shopify (SEO + title/description)

The UI supports **single** and **bulk** push to Shopify:
- Resource: `app/Filament/Resources/ShopifyCollectionResource.php`
- Mutation service: `app/Services/ShopifyCollectionUpdater.php`

Supported fields:
- `title`
- `description_html`
- `seo_title`
- `seo_description`

### Safe URL changes (Handle)

To avoid changing URLs locally:
- The **handle field is read-only** in the edit form.
- The push action includes a **Handle override** field.
  - This updates the Shopify URL **without changing the local record**.
  - The local handle will update after the **next sync** from Shopify.
- Bulk push **does not** allow handle changes.

Code paths:
- UI: `app/Filament/Resources/ShopifyCollectionResource.php`
- Mutation: `app/Services/ShopifyCollectionUpdater.php`

### Collection approvals (2 people)

Collections use the same approval concept as products:
- Each collection has an `approval_version`
- Any edit bumps `approval_version` (previous approvals expire)
- A collection is “approved” only if **2 distinct users** approved the current version

Code:
- Observer: `app/Observers/ShopifyCollectionObserver.php`
- Model helpers: `ShopifyCollection::approvalsForCurrentVersionCount()` and `isApprovedByTwo()`
- Approvals: `collection_approvals` table

### Sync is blocked unless approved

- Row **Push to Shopify** requires 2 approvals.
- Bulk push **skips unapproved** records and reports a count.

### Missing SEO filter

Collections list includes a filter to find records missing:
- `seo_title` or `seo_description`

### CSV Import for collection SEO (bulk)

You can import SEO/title/description in bulk using a CSV file.

UI:
- Filament → **Catalog → Collections** → **Import SEO CSV**

Match & update:
- Matches by `handle`
- Updates only existing collections in the latest collections import

CSV headers (case‑insensitive):
- `Handle` (required)
- `Title` or `Page Title` (updates `title`)
- `Description HTML` or `Description` or `Body HTML` (updates `description_html`)
- `SEO Title` or `Meta Title` (updates `seo_title`)
- `SEO Description` or `Meta Description` (updates `seo_description`)

Example:
```
Handle,Title,Description HTML,SEO Title,SEO Description
spring-collection,Spring Collection,<p>Fresh picks for spring.</p>,Spring Collection | Brand,Shop the Spring Collection for fresh styles.
```

### Repro steps (Collections)

1) Run migrations
```
php artisan migrate
```

2) Run collections-only sync
- Filament → **Catalog → Collections** → **Sync Collections**

3) Import SEO CSV (optional)
- Filament → **Catalog → Collections** → **Import SEO CSV**

4) Approve SEO
- Use **Bulk Approve** in the Collections list (2 distinct users required).

5) Sync approved collections to Shopify
- Row action **Push to Shopify** (approved only)
- Bulk action **Push to Shopify** (approved only)

6) Edit collections in Filament → **Catalog → Collections**
- Update SEO/title/description locally.
- Use **Push to Shopify** to apply changes.

### Shopify app creation + OAuth access token (step-by-step)

Use these steps to create a Shopify app, connect it, and obtain an Admin API access token.

Steps
1) Create App in Dev Dashboard
- Go to `dev.shopify.com/dashboard/`
- Create a new app

2) Configure App Scopes
- Navigate to Admin API section
- Choose your permissions carefully (select only what you need)
- Note the scopes you've selected

3) Set Up Redirect URL
- Open `webhook.site`
- Copy the webhook URL provided (keep it handy)
- In your app settings, add this URL to the Redirect URLs section

4) Release App Version
- Click Release and name the version (e.g., "version1")
- Click on the version you just created
- Verify you can see:
  - The scopes you set
  - The redirect URL you configured

5) Get App Credentials
- Go to App Settings
- Copy your Client ID and Client Secret (keep these secure)

6) Build Authorization URL
- Replace the placeholders in the URL below:
  - STORE: your Shopify store name (without `.myshopify.com`)
  - SCOPE: your selected scopes (comma-separated, URL-encoded)
  - REDIRECT_URI: your webhook.site URL (URL-encoded)
  - CLIENT_ID: your app's Client ID
```
https://STORE.myshopify.com/admin/oauth/authorize?client_id=CLIENT_ID&scope=SCOPE&redirect_uri=REDIRECT_URI
```

7) Install App on Store
- Open the authorization URL in a new browser tab
- Install the app on your Shopify store
- After installation, you'll be redirected to webhook.site

8) Get Authorization Code
- On webhook.site, you'll see the authorization code in the query parameters
- Copy the `code` parameter value

9) Exchange Code for Access Token
- Use the following curl command (replace STORE, CLIENT_ID, SECRET, and CODE):
```
curl -X POST https://STORE.myshopify.com/admin/oauth/access_token \
  -d "client_id=CLIENT_ID" \
  -d "client_secret=SECRET" \
  -d "code=CODE"
```
- The response will contain your `access_token`.

10) Test Your Access Token
- Test the access token with a GraphQL query:
```
curl -X POST \
https://STORE.myshopify.com/admin/api/2026-01/graphql.json \
-H 'Content-Type: application/json' \
-H 'X-Shopify-Access-Token: YOUR_ACCESS_TOKEN' \
-d '{
  "query": "query GetProducts { products(first: 10) { nodes { id title } } }"
}'
```
- Replace `2026-01` with the API version you're using.

Security Notes
- Never commit your `client_secret` or `access_token` to version control
- Use environment variables to store sensitive credentials
- The access token has the permissions of the scopes you selected - choose them carefully

Troubleshooting
- Invalid redirect URI: Make sure the redirect URI in your app settings exactly matches the one in your authorization URL
- Invalid code: Authorization codes expire quickly - use the code immediately after receiving it
- 403 Forbidden: Check that your scopes include the necessary permissions for the API calls you're making

### Reproducible setup (end-to-end)

This section documents the exact steps and code path used to pull Shopify data into this app (instead of duplicating data manually).

1) Create a Shopify Admin API access token
- Create a Custom App in your Shopify Admin (Settings -> Apps and sales channels -> Develop apps).
- Grant **read** access to the data this app fetches:
  - Products (includes product fields, tags, vendor, type, status, SEO).
  - Variants (SKU, price, compare at, barcode, options).
  - Inventory item cost (unit cost lives under inventory items).
  - Images.
  - Metafields (product metafields).
  - Metaobjects (only required if your metafields reference metaobjects).
- Install the app to your store and copy the Admin API access token.

2) Configure Shopify credentials in `.env`:
```
SHOPIFY_SHOP=your-store.myshopify.com
SHOPIFY_ADMIN_ACCESS_TOKEN=shpat_...
SHOPIFY_API_VERSION=2026-01
```

3) (Recommended) Provide a CSV template for field mapping
- Place your latest Shopify export template at:
  - `storage/app/public/template/products_export*.csv`
- The API importer uses the **latest** file in that folder to decide which headers to populate.
- If no template exists, it falls back to `HeaderStore::knownHeaders()` in `app/Services/HeaderStore.php`.

4) Run the queue worker
- Sync runs as a queued job (`App\Jobs\ShopifySyncJob`) with a 1800s timeout.
- Ensure `QUEUE_CONNECTION=database` (default in `.env.example`) and run:
```
php artisan queue:work --timeout=1800 --sleep=3 --tries=1
```

5) Trigger the sync in Filament
- Go to Filament -> **Product Feed** -> **Sync from Shopify**.
- The UI checks `SHOPIFY_SHOP` + `SHOPIFY_ADMIN_ACCESS_TOKEN` before dispatching.
- A background job pulls data and sets the Import status to `ready` when complete.

### How the API sync works (exact code flow)

**Entry point**
- Filament action: `app/Filament/Resources/ImportResource.php` (`syncFromShopify`)
- Job: `app/Jobs/ShopifySyncJob.php`
- Importer: `app/Services/ShopifyApiImporter.php`
- HTTP client: `app/Services/ShopifyApiClient.php`

**Credentials + request**
- The client reads `SHOPIFY_SHOP`, `SHOPIFY_ADMIN_ACCESS_TOKEN`, and `SHOPIFY_API_VERSION` from `config/services.php`.
- It sends POST requests to:
  - `https://{shop}/admin/api/{version}/graphql.json`
- Header used:
  - `X-Shopify-Access-Token: {token}`

**GraphQL data pulled**
- Products are paginated 100 at a time.
- For each product, the query includes:
  - Core product fields (handle, title, body HTML, vendor, type, status, tags, SEO, product category).
  - Metafields (namespace, key, value, type) up to 250 per product.
  - Variants (SKU, price, compare at, barcode, options, inventory unit cost) up to 250 per product.
  - Images (url, alt text) up to 250 per product.

**Row construction (CSV-shaped output)**
- The importer builds a "blank row" for every header (from template or `HeaderStore`).
- Each product becomes:
  - 1 `product_primary` row (first variant + first image merged into this row).
  - 0+ `variant` rows (remaining variants).
  - 0+ `image` rows (remaining images).
- Rows are written to `shopify_rows` with:
  - `row_type` (`product_primary`, `variant`, `image`)
  - `variant_key` / `image_key` (computed from row contents)
  - `data` (full JSON header/value map)

**Metafields mapping**
- Headers that match `(...product.metafields.{namespace}.{key})` are populated.
- Values are normalized:
  - `list.*` types -> semicolon-separated values.
  - `boolean` -> `true` / `false` strings.
  - Metaobject references are resolved to display labels when possible.
- All metafields (not just templated ones) are also stored in `shopify_metafields`.

**Normalization**
- After rows are created, `Normalizer` builds products/variants/images.
- Status is updated to `ready` and Filament sends a notification.

### Avoiding duplication (how re-syncing stays clean)

- Sync creates or reuses the **current Import**:
  - `ShopifyApiImporter::createOrReuseCurrentImport()`
- Before each sync, data for that Import is cleared:
  - `shopify_rows`, `products`, and `shopify_metafields` for the current `import_id`.
- This means re-syncing **replaces** the current import's data instead of duplicating it.
- If you want a full clean slate, delete old imports or set the current import to the one you want to overwrite.

Notes:
- Metafields are populated based on the headers in your latest CSV template.
- Variants and images are imported into their own rows for proper normalization.

## Database schema overview

Core tables:
- `imports`: upload metadata, status, headers, is_current
- `shopify_rows`: every CSV row with full JSON payload
- `shopify_metafields`: all Shopify metafields per product handle (full set, not just template headers)
- `products`: normalized product data + approval_version
- `variants`: normalized variants
- `images`: normalized images
- `categories`: curated category list + Google category mapping
- `colors`: curated color list
- `approvals`: product approvals per user + version
- `change_logs`: audit trail for product edits
- `notifications`: Filament database notifications

Schema is defined in `database/migrations/`.

## File storage

- Imports: `storage/app/public/imports`
- Exports: `storage/app/public/exports`
- Shopify sync snapshots: `storage/app/public/shopify-sync-snapshots`

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
