# Procurement Google Sheets integration

Laravel is the only orchestrator. Shopify and Google Sheets write state into Laravel; Python reads authenticated Laravel feeds and publishes predictions back to Laravel.

## Google Cloud setup

1. Enable the Google Sheets API in the Google Cloud project.
2. Create a service account and JSON key.
3. Share the procurement workbook with the JSON key's `client_email` as Editor.
4. Permit that service account to edit the workbook's protected system ranges.
5. Keep human users restricted to `Ignore` plus each phase's Quantity, Order ID, and ETA columns in brand tabs.
6. Store the key outside the repository and configure its absolute path or base64 value.

```dotenv
GOOGLE_SHEETS_ENABLED=false
GOOGLE_SHEETS_SPREADSHEET_ID=
GOOGLE_SHEETS_MASTER_TAB=master-file
GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON=
GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON_BASE64=
GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON_PATH=
GOOGLE_SHEETS_TIMEOUT_SECONDS=60
GOOGLE_SHEETS_LOCK_SECONDS=14400
```

Only one of the three service-account JSON settings is required. Do not commit the key.

## Operational collection configuration

Run this after Shopify collections have been imported into Laravel:

```powershell
php artisan procurement:configure-collection livi-road
php artisan procurement:configure-collection untamed
php artisan procurement:configure-collection elevated-basics
php artisan procurement:configure-collection elements-of-desire
php artisan procurement:configure-collection pata-pata
```

Use `--tab=sheet-tab-name` when a tab differs from its collection handle. The mapping persists across later Shopify catalogue imports and retains the latest known immutable Shopify collection ID.

## Deployment and verification

```powershell
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan queue:restart
php artisan procurement:sheets-pull
php artisan procurement:sheets-publish
php artisan procurement:run
```

Keep `GOOGLE_SHEETS_ENABLED=false` through migration and collection configuration. Enable it immediately before the pull/publish smoke test.

The scheduled `procurement:run` sequence is:

1. Pull human-owned phases from brand tabs.
2. Store and audit changes in Laravel.
3. Freeze the incoming-stock input snapshot for the run.
4. Generate Product Movement and launch Python.
5. Python downloads `/api/analytics/incoming-stock.csv?run_uuid=...`.
6. Python atomically publishes predictions to Laravel.
7. Laravel publishes Master and brand system-owned cells.

## Supplier-order workflow

The user-owned fields are `Ignore` and the Quantity, Order ID, and ETA for each of the three phases. Laravel always preserves the raw values. A phase contributes incoming stock to predictions only when quantity is greater than zero and both Order ID and ETA are present. Existing historical quantities therefore remain visible but count as zero confirmed incoming until users complete those fields.

`Ignore = TRUE` marks an end-of-life SKU. Inventory remains visible and sellable, but the procurement pipeline forces its recommendation to `NO_ACTION` with zero additional order quantity. Archiving remains a separate established CMS workflow.

The report layout is 32 columns (`A:AF`). `cms_movement_classification` is column H, `Ignore` is column I, and `Action Required` is column AB. `Sale Percentage` follows `Currently on Sale`; each supplier phase is grouped Quantity, Order ID, ETA; and `Last Updated` remains the final column. Existing workbook layouts are backed up and upgraded by header name before synchronization, so manually sorted rows remain matched by SKU.

A Google write failure is stored on the completed prediction run and does not remove the successful report. Retry output with `php artisan procurement:sheets-publish`.

## LRB0004 lifecycle check

1. Set Shopify inventory to `0`, Phase 1 quantity to `60`, a supplier Order ID, and an ETA in the appropriate brand tab.
2. Run `php artisan procurement:run` and process the `procurement` queue.
3. Confirm Laravel and the authenticated incoming-stock CSV retain raw phases `60,0,0` and confirmed total `60`.
4. Confirm the prediction and both Sheet views use projected inventory `60`.
5. Before receiving inventory, set Phase 1 to `0`; then receive `60` into Shopify.
6. Run the Shopify inventory synchronization and procurement cycle again.
7. Confirm current inventory `60`, total on order `0`, and projected inventory `60`.

## Rollback

Set `GOOGLE_SHEETS_ENABLED=false` to stop all Sheet reads and writes. Existing Shopify analytics feeds and prediction publishing remain active. Python still accepts a legacy local `raw/incoming_stock.csv` when it is run without a Laravel run UUID.
