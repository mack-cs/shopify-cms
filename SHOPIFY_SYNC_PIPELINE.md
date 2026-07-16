# Shopify Orders, Inventory, and Demand Pipeline

This Laravel pipeline imports Shopify orders and inventory through Admin GraphQL bulk operations, archives raw JSONL to S3, and calculates SKU daily demand from deduplicated current order line items.

## Commands

```bash
php artisan shopify:run-daily-pipeline
php artisan shopify:orders-import-history --force
php artisan shopify:orders-backfill 2026-07-14 --lookback=3
php artisan shopify:orders-backfill 2026-07-14 --capture-current-inventory
php artisan shopify:inventory-snapshot
```

The scheduler queues the daily pipeline at `02:00 Africa/Johannesburg`:

```php
Schedule::command('shopify:run-daily-pipeline --scheduled')
    ->dailyAt('02:00')
    ->timezone('Africa/Johannesburg')
    ->withoutOverlapping();
```

## Business Window

For business date `2026-07-14`, the reporting window is:

```text
2026-07-14 00:00:00 +02:00
to
2026-07-15 00:00:00 +02:00
```

The three-day order update lookback is:

```text
2026-07-12 00:00:00 +02:00
to
2026-07-15 00:00:00 +02:00
```

The same business date always creates the same deterministic window.

## S3 Archive

Raw files are immutable and written after Shopify bulk download:

```text
raw/orders/full/run_id={run_id}/orders.jsonl.gz
raw/orders/daily/business_date={YYYY-MM-DD}/run_id={run_id}/orders.jsonl.gz
raw/inventory/daily/business_date={YYYY-MM-DD}/run_id={run_id}/inventory.jsonl.gz
```

Each archive folder also receives `metadata.json` and `_SUCCESS`. Temporary Shopify signed URLs are not stored in sync-run records or archive metadata.

## Configuration

Config lives in `config/shopify_sync.php`.

Relevant environment variables:

```text
SHOPIFY_SYNC_TIMEZONE
SHOPIFY_SYNC_SHOP
SHOPIFY_SYNC_ADMIN_ACCESS_TOKEN
AWS_SHOPIFY_SYNC_SECRET_ID
AWS_SHOPIFY_SYNC_SECRET_CACHE_KEY
SHOPIFY_SYNC_FALLBACK_TO_DEFAULT_TOKEN
SHOPIFY_SYNC_ORDER_LOOKBACK_DAYS
SHOPIFY_SYNC_ORDER_POLL_DELAY_SECONDS
SHOPIFY_SYNC_ORDER_FIRST_POLL_DELAY_SECONDS
SHOPIFY_SYNC_ORDER_MAX_POLL_ATTEMPTS
SHOPIFY_SYNC_ORDER_BATCH_SIZE
SHOPIFY_SYNC_INVENTORY_POLL_DELAY_SECONDS
SHOPIFY_SYNC_INVENTORY_FIRST_POLL_DELAY_SECONDS
SHOPIFY_SYNC_INVENTORY_MAX_POLL_ATTEMPTS
SHOPIFY_SYNC_INVENTORY_BATCH_SIZE
SHOPIFY_SYNC_INVENTORY_QUANTITY_NAMES
SHOPIFY_SYNC_S3_DISK
SHOPIFY_SYNC_RAW_ORDERS_PREFIX
SHOPIFY_SYNC_RAW_INVENTORY_PREFIX
```

The pipeline reuses the existing Shopify credentials in `config/services.php` and the existing Laravel S3 disk/IAM setup.

If the normal CMS token does not have enough scopes for bulk order/inventory reads, set `SHOPIFY_SYNC_ADMIN_ACCESS_TOKEN` to a broader Shopify Admin API token. Alternatively set `AWS_SHOPIFY_SYNC_SECRET_ID` and store the token in AWS Secrets Manager. If neither is configured, the pipeline falls back to the existing `SHOPIFY_ADMIN_ACCESS_TOKEN`/AWS Shopify secret while `SHOPIFY_SYNC_FALLBACK_TO_DEFAULT_TOKEN=true`.

## Admin

Super Admins can monitor and operate the pipeline under `Shopify Sync`:

- `Sync Runs`: start manual runs, poll, reprocess raw files, rerun a business date, and inspect issues.
- `Order Data`: read current deduplicated Shopify order state.
- `Inventory`: inspect immutable inventory snapshots.
- `SKU Demand`: review derived SKU/day demand.

## Idempotency

Reprocessing a raw file is safe:

- orders upsert by `shopify_order_id`
- line items upsert by `shopify_line_item_id`
- refunds upsert by `shopify_refund_id`
- discounts upsert by deterministic `discount_key`
- inventory snapshots upsert by `sync_run_id + inventory_item_id + location_id`
- demand rows upsert by `sku + demand_date`

Current inventory updates only when the incoming snapshot timestamp is newer than the variant's `inventory_last_synced_at`.
