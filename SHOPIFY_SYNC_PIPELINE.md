# Shopify Orders, Inventory, and Demand Pipeline

This Laravel pipeline imports Shopify orders, payment transactions, order-level refunds, bulk-compatible current line quantities, and inventory through Admin GraphQL bulk operations, archives raw JSONL to S3, and calculates SKU daily demand from deduplicated current order line items.

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
- `Order Lines`: search an individual SKU, see exactly when it sold, and open its parent order.
- `Inventory`: inspect immutable inventory snapshots.
- `SKU Demand`: review derived SKU/day demand.

The `Order Data` screen also provides:

- `Payment platform CSV`: successful collections, refunds, net collections, failures, order counts, and average order value by gateway.
- `ML order lines CSV`: one row per Shopify line item with stable order and line-item IDs.
- `ML products CSV`: the current product/variant, inventory-tracking, inventory-quantity, policy, and status data expected by the procurement pipeline.
- `Stack components CSV`: generated from `New Product Drafts > Associated products` (`bundle_product_ids`).

The `Inventory` screen provides:

- `Sale inventory CSV`, which joins sale SKUs to the nearest opening inventory snapshot, SKU demand, and current availability. Missing opening snapshots are explicitly flagged instead of being reconstructed.
- `ML inventory events CSV`, which provides the stock availability events used to avoid treating stockout days as zero-demand days.

The stack/component association is maintained only in New Product Drafts. The ML export is generated from that relationship, so `raw/stack_components.csv` no longer needs a separately maintained mapping.

## Secure analytics feeds

Set a long random bearer token:

```text
SHOPIFY_ANALYTICS_EXPORT_TOKEN=...
```

The following fail-closed API endpoints are then available:

```text
GET /api/analytics/order-lines.csv?from=2023-01-01&to=2026-07-24
GET /api/analytics/products.csv
GET /api/analytics/inventory-snapshots.csv?from=2026-07-01&to=2026-07-24
GET /api/analytics/inventory-events.csv?from=2023-01-01&to=2026-07-24
GET /api/analytics/stack-components.csv
Authorization: Bearer {SHOPIFY_ANALYTICS_EXPORT_TOKEN}
```

The order-line, product, inventory-event, and stack-component feeds are directly compatible with:

```text
leigh_ml_procurement_v1/raw/orders.csv
leigh_ml_procurement_v1/raw/products.csv
leigh_ml_procurement_v1/raw/inventory_events.csv
leigh_ml_procurement_v1/raw/stack_components.csv
```

## Idempotency

Reprocessing a raw file is safe:

- orders upsert by `shopify_order_id`
- line items upsert by `shopify_line_item_id`
- refunds upsert by `shopify_refund_id`
- payment transactions upsert by `shopify_transaction_id`
- discounts upsert by deterministic `discount_key`
- inventory snapshots upsert by `sync_run_id + inventory_item_id + location_id`
- demand rows upsert by `sku + demand_date`

Net units and net product revenue use Shopify line-item `currentQuantity`. The stored `refunded_units` metric therefore represents units removed by refunds or order edits; the CMS labels it `Refunded/removed units`. Cancelled orders and test orders are excluded from net demand. Only the configured financial statuses in `SHOPIFY_SYNC_DEMAND_FINANCIAL_STATUSES` are included.

After deploying a schema/query change that adds Shopify fields, run a new full historical import. Reprocessing an older raw archive cannot create fields that were not present in the original Shopify bulk query:

```bash
php artisan migrate --force
php artisan shopify:orders-import-history --force
```

Current inventory updates only when the incoming snapshot timestamp is newer than the variant's `inventory_last_synced_at`.

---

# Deduct Stack Components on Shopify Fulfillment — Implementation Plan

This section is an implementation handoff only. No PHP, route, migration, configuration, or JavaScript change described below has been applied yet.

## 1. Current System Analysis

### Application and Shopify architecture

- The application is Laravel 12, PHP 8.2+, Filament 3, Pest 4, and uses the Laravel database queue by default (`composer.json`, `config/queue.php`, `.env.example`). Production workers must have a `retry_after` longer than the job timeout; `.env.example` currently uses `DB_QUEUE_RETRY_AFTER=15000`.
- `config/services.php` is the canonical Shopify configuration. It supplies `SHOPIFY_SHOP`, `SHOPIFY_ADMIN_ACCESS_TOKEN`, `SHOPIFY_API_VERSION` (default `2026-01`), `SHOPIFY_INVENTORY_LOCATION_ID`, `SHOPIFY_WEBHOOK_SECRET`, and `SHOPIFY_WEBHOOK_VERIFY`.
- `App\Services\AwsSecretService` resolves the Admin API token used by `App\Services\ShopifyApiClient`. `App\Providers\AppServiceProvider::register()` binds `App\Contracts\ShopifyGraphqlGateway` to that client.
- `App\Services\ShopifyApiClient::graphql()` is the application's main synchronous Admin GraphQL gateway. It uses the configured version, throws on non-2xx responses and top-level GraphQL errors, and returns the `data` object. It does not automatically retry requests. That is desirable for non-idempotent mutations; this workflow will retry at the queued-job layer with the same Shopify idempotency key.
- `App\Services\Shopify\ShopifyGraphqlClient` is used by the bulk analytics pipeline with `ShopifySyncTokenResolver`. The fulfillment workflow must use `ShopifyApiClient`/`ShopifyGraphqlGateway`, matching operational product and procurement writes, rather than creating a third client.
- `App\Services\Shopify\ShopifyInventoryAdjustmentService` already wraps `inventoryAdjustQuantities`. Its current `increaseAvailable()` method receives stock with reason `received`. It also has `resolveLocationId()`, which checks the configured location, a recent `shopify_inventory_snapshots` row, then the first Shopify location. The fulfillment workflow should extend this class with a decrement method and must pass the fulfillment location explicitly; it must not use the current first-location fallback for a fulfillment.
- `App\Services\ProductInventorySyncService` uses `inventorySetQuantities` for user-entered absolute stock corrections. That operation is not suitable here: fulfilling a stack is a delta relative to Shopify's current inventory, so the existing `inventoryAdjustQuantities` service is the correct reuse point.

### Existing webhook and queue conventions

- `routes/web.php` exposes `POST /webhooks/shopify/inventory-levels-update` and `POST /webhooks/shopify/products-update`, excludes `ValidateCsrfToken`, and names both routes.
- `ShopifyInventoryLevelWebhookController` and `ShopifyProductUpdateWebhookController` verify the base64 HMAC-SHA256 against the raw request body, validate `X-Shopify-Topic`, validate the JSON payload, dispatch a queue job, log structured context, and return `202`.
- Verification can be disabled only in `local` or `testing`; production still verifies even if the flag is false. The fulfillment controller must preserve that fail-closed behavior.
- The two current controllers duplicate HMAC code. Phase 1 should follow that established convention and avoid refactoring unrelated webhook controllers in the same release.
- Existing webhook jobs implement `ShouldQueue`; inventory/product update jobs also implement short-lived `ShouldBeUnique`. A 60-second uniqueness lock is not sufficient for inventory money-like side effects, so fulfillment correctness must come from database uniqueness plus Shopify mutation idempotency, not `ShouldBeUnique`.
- Existing jobs set explicit timeouts. Scheduler definitions and inline Artisan commands live in `routes/console.php`; dedicated command classes also exist in `app/Console/Commands` and are auto-discovered by Laravel.
- Logging uses Laravel's default channel (`config/logging.php`) and structured arrays. Failed queue jobs are stored in `failed_jobs`.

### Products, variants, stacks, and quantities

- `products.shopify_id` stores the Shopify product identifier; operational code accepts either numeric legacy IDs or GraphQL GIDs. `products.is_bundle` and bundle/stack tags identify bundle-like catalog products.
- `variants.shopify_id` stores the Shopify variant identifier. `variants.sku` is indexed, and `variants.shopify_inventory_item_id` was added by `database/migrations/2026_06_30_000000_add_shopify_inventory_item_id_to_variants_table.php`.
- A product has active variants through `Product::variants()`; a component association currently points to a `Product`, not a specific `Variant`. Therefore Phase 1 must fail safely if a component product has zero or more than one active variant. It must never guess which variant to decrement.
- Stack composition is maintained in `new_product_drafts.bundle_product_ids`, a nullable JSON array added by `database/migrations/2026_06_11_120000_add_sale_and_bundle_fields_to_new_product_drafts_table.php` and cast to `array` by `NewProductDraft`.
- `NewProductDraftResource` renders `bundle_product_ids` as the `Associated products` multi-select. `normalizeBundleProductIds()` calls `array_unique`, so a component cannot be repeated to express quantity.
- `NewProductDraftStackAssociationImporter` also removes duplicate product IDs. `StackBundleSellabilityService`, `StackComponentCsvExporter`, stack image code, and procurement exports all read the same JSON membership list.
- **Confirmed gap:** the repository stores membership but does not store quantity per component. The requirement's `Component B × 2` cannot currently be represented. Phase 1 must add `new_product_drafts.bundle_component_quantities` as a JSON list of `{product_id, quantity}` rows while retaining `bundle_product_ids` as the canonical membership list used by existing features. Existing associations are backfilled to quantity `1`. This extends, rather than replaces or duplicates, the current relationship: membership remains in the existing field; the new field supplies only quantity metadata.
- `StackBundleSellabilityService` can continue using membership only. `StackComponentCsvExporter` can continue exporting its existing procurement format. `NewProductDraftStackAssociationImporter` must initialize quantity `1` for newly imported members and preserve quantities for retained members.

### Existing order, fulfillment, inventory, and admin data

- `shopify_orders` and `shopify_order_items` are imported through bulk GraphQL by the jobs under `app/Jobs/Shopify` and services under `app/Services/Shopify`.
- `shopify_order_items` is unique on `shopify_line_item_id` and stores ordered/current/refundable quantities, product/variant IDs, and SKU. It does not store fulfillment rows or fulfilled quantities. It cannot be used as the fulfillment trigger.
- `ShopifyOrderResource` exposes imported orders in Filament under `Shopify Sync > Order Data`. It is Super Admin read-only. A new read-only adjustment resource in the same navigation group is the least surprising place for operational visibility.
- `shopify_inventory_snapshots` stores inventory item/location pairs and quantities from the daily bulk snapshot. It is useful for diagnostics but is not authoritative enough to infer the location of a new fulfillment.
- `DailyShopifyInventoryRefreshJob` and the daily bulk pipeline provide eventual local refresh. The existing `inventory_levels/update` webhook will also refresh affected component truth after an adjustment. The fulfillment job must not directly decrement `variants.inventory_qty`; Shopify remains the source of truth.
- No fulfillment webhook, fulfillment persistence table, webhook-registration command, or fulfillment reconciliation command currently exists.

### Reuse / extend / create summary

| Category | Decision |
|---|---|
| Reuse | `ShopifyApiClient`, `ShopifyGraphqlGateway`, `AwsSecretService`, `Variant.shopify_inventory_item_id`, `Product`/`Variant`/`NewProductDraft`, database queue, structured logging, Filament Super Admin authorization, existing HMAC convention |
| Extend | `ShopifyInventoryAdjustmentService` with an idempotent negative-delta method; `NewProductDraft` with component quantity helpers; `NewProductDraftResource` and stack association importer with quantity metadata; `routes/web.php`; `.env.example`/configuration |
| Create | fulfillment webhook controller, webhook-event and adjustment models/tables, queued job, processor/resolver services, registration command, read-only Filament adjustment resource, reconciliation command, feature tests |

## 2. Proposed End-to-End Flow

```text
Shopify fulfillments/create
  -> POST /webhooks/shopify/fulfillments-create
  -> ShopifyFulfillmentWebhookController verifies raw-body HMAC and topic
  -> transaction inserts shopify_webhook_events (unique webhook ID)
  -> ProcessShopifyFulfillmentWebhookJob dispatches after commit
  -> controller returns 202 (duplicate delivery returns 200)
  -> job acquires fulfillment cache lock
  -> StackFulfillmentProcessor reads payload line_items[*].quantity
  -> StackFulfillmentResolver matches local variant/product and stack draft
  -> NewProductDraft::stackComponentDefinitions() supplies component quantity
  -> processor inserts one pending shopify_stack_inventory_adjustments row
     per fulfillment line item + component + location (unique SHA-256 key)
  -> each row is marked processing and committed before the API call
  -> ShopifyInventoryAdjustmentService::decreaseAvailable() calls
     inventoryAdjustQuantities with a negative delta and stored UUID through
     @idempotent
  -> successful rows are immutable/succeeded; failed rows retain their UUID
  -> retry processes only non-succeeded rows
  -> webhook event becomes completed, partial, or failed
  -> existing inventory_levels/update webhook refreshes local component truth
  -> Filament audit screen exposes the durable records
```

The original order quantity and `shopify_order_items.quantity/current_quantity` are never used to calculate the deduction. Only `line_items[*].quantity` from the fulfillment payload (or `FulfillmentLineItem.quantity` during reconciliation) is used.

## 3. Existing Code to Reuse

1. `app/Services/ShopifyApiClient.php` — credential resolution, versioned Admin GraphQL endpoint, transport/top-level error handling.
2. `app/Contracts/ShopifyGraphqlGateway.php` and its binding in `AppServiceProvider` — injectable/fakeable boundary for tests.
3. `app/Services/Shopify/ShopifyInventoryAdjustmentService.php` — existing delta-adjustment class and `inventoryAdjustQuantities` convention.
4. `app/Http/Controllers/ShopifyInventoryLevelWebhookController.php` — exact HMAC, environment, header, JSON-validation, logging, and response conventions.
5. `routes/web.php` — public CSRF-exempt webhook routing convention.
6. `app/Models/Product.php`, `Variant.php`, and `NewProductDraft.php` — local catalog, Shopify identities, inventory item ID, and stack membership.
7. `app/Services/NewProductDraftStackAssociationImporter.php` — existing association import path; extend its saved metadata instead of adding another importer.
8. `app/Services/StackBundleSellabilityService.php` and the inventory webhook job — no direct call is required. The Shopify inventory webhook caused by the deduction will drive the existing local refresh/sellability flow.
9. `app/Filament/Resources/ShopifyOrderResource.php` — authorization and navigation convention for Shopify operational data.
10. `routes/console.php` scheduler convention and Laravel command auto-discovery — use for the optional reconciliation schedule.

## 4. Database Changes

### Migration — new file

Create `database/migrations/2026_08_20_000000_create_shopify_stack_fulfillment_tables.php` with the complete code below. The migration is additive. Its rollback removes only the new tables/quantity metadata; it cannot reverse inventory already changed in Shopify.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->json('bundle_component_quantities')
                ->nullable()
                ->after('bundle_product_ids');
        });

        DB::table('new_product_drafts')
            ->whereNotNull('bundle_product_ids')
            ->orderBy('id')
            ->chunkById(250, function ($drafts): void {
                foreach ($drafts as $draft) {
                    $ids = json_decode((string) $draft->bundle_product_ids, true);
                    if (! is_array($ids)) {
                        continue;
                    }

                    $rows = collect($ids)
                        ->map(fn ($id): int => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->unique()
                        ->map(fn (int $id): array => [
                            'product_id' => $id,
                            'quantity' => 1,
                        ])
                        ->values()
                        ->all();

                    DB::table('new_product_drafts')
                        ->where('id', $draft->id)
                        ->update([
                            'bundle_component_quantities' => $rows === []
                                ? null
                                : json_encode($rows, JSON_THROW_ON_ERROR),
                        ]);
                }
            });

        Schema::create('shopify_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('webhook_id', 128)->unique();
            $table->string('event_id', 128)->nullable()->index();
            $table->string('topic', 128)->index();
            $table->string('shop_domain', 191)->index();
            $table->string('api_version', 32)->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->string('shopify_order_id', 128)->nullable()->index();
            $table->string('shopify_fulfillment_id', 128)->nullable()->index();
            $table->string('shopify_location_id', 128)->nullable()->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('payload');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'topic', 'shopify_fulfillment_id'], 'shopify_webhook_fulfillment_lookup');
        });

        Schema::create('shopify_stack_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webhook_event_id')->constrained('shopify_webhook_events')->cascadeOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('direction', 16)->default('deduction')->index();
            $table->string('shop_domain', 191)->index();
            $table->string('shopify_order_id', 128)->index();
            $table->string('shopify_fulfillment_id', 128)->index();
            $table->string('shopify_line_item_id', 128)->index();
            $table->string('shopify_stack_product_id', 128)->nullable();
            $table->string('shopify_stack_variant_id', 128)->nullable();
            $table->string('stack_sku')->nullable()->index();
            $table->foreignId('stack_draft_id')->nullable()->constrained('new_product_drafts')->nullOnDelete();
            $table->unsignedInteger('fulfilled_stack_quantity');
            $table->foreignId('component_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('component_variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->string('component_sku')->nullable()->index();
            $table->unsignedInteger('component_quantity_per_stack');
            $table->integer('quantity_delta');
            $table->string('shopify_inventory_item_id', 128)->index();
            $table->string('shopify_location_id', 128)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('shopify_response')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('shopify_stack_inventory_adjustments')->nullOnDelete();
            $table->timestamps();

            $table->index(['shopify_fulfillment_id', 'shopify_line_item_id'], 'stack_adjustment_fulfillment_line_lookup');
            $table->index(['webhook_event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_stack_inventory_adjustments');
        Schema::dropIfExists('shopify_webhook_events');

        Schema::table('new_product_drafts', function (Blueprint $table): void {
            $table->dropColumn('bundle_component_quantities');
        });
    }
};
```

### Webhook event model — new file

Create `app/Models/ShopifyWebhookEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyWebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'webhook_id', 'event_id', 'topic', 'shop_domain', 'api_version',
        'triggered_at', 'shopify_order_id', 'shopify_fulfillment_id',
        'shopify_location_id', 'status', 'attempt_count', 'payload',
        'processing_started_at', 'processed_at', 'failed_at', 'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'triggered_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function adjustments(): HasMany
    {
        return $this->hasMany(ShopifyStackInventoryAdjustment::class, 'webhook_event_id');
    }
}
```

### Adjustment model — new file

Create `app/Models/ShopifyStackInventoryAdjustment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyStackInventoryAdjustment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'webhook_event_id', 'dedupe_key', 'idempotency_key', 'direction',
        'shop_domain', 'shopify_order_id', 'shopify_fulfillment_id',
        'shopify_line_item_id', 'shopify_stack_product_id',
        'shopify_stack_variant_id', 'stack_sku', 'stack_draft_id',
        'fulfilled_stack_quantity', 'component_product_id',
        'component_variant_id', 'component_sku',
        'component_quantity_per_stack', 'quantity_delta',
        'shopify_inventory_item_id', 'shopify_location_id', 'status',
        'attempt_count', 'processing_started_at', 'processed_at',
        'failed_at', 'last_error', 'shopify_response', 'reversal_of_id',
    ];

    protected $casts = [
        'shopify_response' => 'array',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(ShopifyWebhookEvent::class, 'webhook_event_id');
    }

    public function stackDraft(): BelongsTo
    {
        return $this->belongsTo(NewProductDraft::class, 'stack_draft_id');
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
```

### `NewProductDraft` quantity metadata — existing file

In `app/Models/NewProductDraft.php`, add `bundle_component_quantities` to `$fillable`, add `'bundle_component_quantities' => 'array'` to `$casts`, and add this method before the final class brace:

```php
/**
 * @return array<int, array{product_id:int,quantity:int}>
 */
public function stackComponentDefinitions(): array
{
    $memberIds = collect((array) $this->bundle_product_ids)
        ->map(fn ($id): int => (int) $id)
        ->filter(fn (int $id): bool => $id > 0)
        ->unique()
        ->values();

    $quantities = collect((array) $this->bundle_component_quantities)
        ->filter(fn ($row): bool => is_array($row))
        ->mapWithKeys(function (array $row): array {
            $productId = (int) ($row['product_id'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);

            return $productId > 0 && $quantity > 0
                ? [$productId => $quantity]
                : [];
        });

    return $memberIds
        ->map(fn (int $productId): array => [
            'product_id' => $productId,
            'quantity' => max(1, (int) $quantities->get($productId, 1)),
        ])
        ->all();
}
```

The fallback to `1` protects deployments during the migration window, but the UI and deployment verification must ensure every stack has explicit quantity rows before enabling the feature.

## 5. Shopify Configuration

### API version and operations

- The repository default is Admin GraphQL `2026-01`; do not silently upgrade it as part of this feature.
- Register GraphQL topic `FULFILLMENTS_CREATE`, delivered as `fulfillments/create`. In 2026-01 it fires whenever a fulfillment is created and requires `read_fulfillments` (or the marketplace alternative): [Shopify 2026-01 topic reference](https://shopify.dev/docs/api/admin-graphql/2026-01/enums/WebhookSubscriptionTopic).
- The 2026-01 payload includes fulfillment `id`, `order_id`, `location_id`, and `line_items[*]` containing order line-item `id`, `variant_id`, `product_id`, `sku`, and the fulfillment-specific `quantity`: [Shopify 2026-01 webhook payload](https://shopify.dev/docs/api/webhooks/2026-01).
- Deduct with `inventoryAdjustQuantities`, `name: available`, `reason: correction`, and a negative `delta`. It requires `write_inventory`: [Shopify 2026-01 mutation reference](https://shopify.dev/docs/api/admin-graphql/2026-01/mutations/inventoryAdjustQuantities).
- Although `@idempotent` is optional in 2026-01, use it now. Shopify documents support from 2026-01 and requires it from 2026-04 onward. This makes a future API-version bump safe.
- Required token scopes for Phase 1: existing product/inventory scopes, plus `read_fulfillments` and `write_inventory`. The optional reconciliation query also requires `read_orders`. Confirm actual granted scopes with Shopify before deployment; the repository does not declare or install scopes in code.

### New configuration file

Create `config/shopify_stack_fulfillment.php`:

```php
<?php

return [
    'enabled' => filter_var(env('SHOPIFY_STACK_FULFILLMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'queue' => env('SHOPIFY_STACK_FULFILLMENT_QUEUE', 'default'),
    'lock_seconds' => (int) env('SHOPIFY_STACK_FULFILLMENT_LOCK_SECONDS', 900),
    'reconciliation_days' => (int) env('SHOPIFY_STACK_FULFILLMENT_RECONCILIATION_DAYS', 3),
];
```

Append to `.env.example` immediately after the existing Shopify Admin API values:

```dotenv
SHOPIFY_INVENTORY_LOCATION_ID=
SHOPIFY_WEBHOOK_SECRET=
SHOPIFY_WEBHOOK_VERIFY=true
SHOPIFY_STACK_FULFILLMENT_ENABLED=false
SHOPIFY_STACK_FULFILLMENT_QUEUE=default
SHOPIFY_STACK_FULFILLMENT_LOCK_SECONDS=900
SHOPIFY_STACK_FULFILLMENT_RECONCILIATION_DAYS=3
```

`SHOPIFY_WEBHOOK_SECRET` is the app client secret used to sign webhooks, not the Admin API access token. `APP_URL` must be the public HTTPS CMS origin so `route()` generates the production callback URL.

### Location rule

1. If payload `location_id` is present, convert it to `gid://shopify/Location/{id}` and use it.
2. If it is absent, use `SHOPIFY_INVENTORY_LOCATION_ID` only when deliberately configured.
3. If neither exists, fail the adjustment and expose the error. Do not fall back to the first location or an old snapshot.
4. A component not stocked at the fulfillment location will produce a Shopify user error. Keep the row failed for investigation/retry; do not deduct at another location automatically.

### Webhook registration command — new file

There is no webhook registration mechanism in this repository. Create one narrowly scoped command at `app/Console/Commands/RegisterShopifyFulfillmentWebhook.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\ShopifyApiClient;
use Illuminate\Console\Command;

class RegisterShopifyFulfillmentWebhook extends Command
{
    protected $signature = 'shopify:register-fulfillment-webhook
        {--callback-url= : Override the URL generated from APP_URL}';

    protected $description = 'Register the Shopify fulfillments/create webhook used for stack deductions';

    public function handle(ShopifyApiClient $client): int
    {
        $callbackUrl = trim((string) ($this->option('callback-url')
            ?: route('webhooks.shopify.fulfillments-create')));

        if (! str_starts_with($callbackUrl, 'https://')) {
            $this->error('The Shopify webhook callback must use public HTTPS.');

            return self::FAILURE;
        }

        $existing = $client->graphql(<<<'GRAPHQL'
query ExistingFulfillmentWebhooks {
  webhookSubscriptions(first: 100, topics: [FULFILLMENTS_CREATE]) {
    nodes { id topic uri }
  }
}
GRAPHQL);

        $subscriptions = collect(data_get($existing, 'webhookSubscriptions.nodes', []));
        if ($subscriptions->contains(fn ($row): bool =>
            is_array($row) && rtrim((string) ($row['uri'] ?? ''), '/') === rtrim($callbackUrl, '/')
        )) {
            $this->info("Fulfillment webhook is already registered at {$callbackUrl}.");

            return self::SUCCESS;
        }

        if ($subscriptions->isNotEmpty()) {
            $this->error('A FULFILLMENTS_CREATE subscription already exists at another URI.');
            foreach ($subscriptions as $subscription) {
                $this->line('- '.($subscription['id'] ?? '?').' '.($subscription['uri'] ?? '?'));
            }
            $this->line('Review and remove/update the obsolete subscription deliberately before registering another.');

            return self::FAILURE;
        }

        $result = $client->graphql(<<<'GRAPHQL'
mutation RegisterFulfillmentWebhook($subscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: FULFILLMENTS_CREATE, webhookSubscription: $subscription) {
    webhookSubscription { id topic uri }
    userErrors { field message }
  }
}
GRAPHQL, [
            'subscription' => [
                'uri' => $callbackUrl,
                'format' => 'JSON',
            ],
        ]);

        $errors = data_get($result, 'webhookSubscriptionCreate.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            foreach ($errors as $error) {
                $field = is_array($error['field'] ?? null)
                    ? implode('.', $error['field'])
                    : (string) ($error['field'] ?? 'subscription');
                $this->error($field.': '.($error['message'] ?? 'Unknown error'));
            }

            return self::FAILURE;
        }

        $subscription = data_get($result, 'webhookSubscriptionCreate.webhookSubscription');
        if (! is_array($subscription) || empty($subscription['id'])) {
            $this->error('Shopify returned no webhook subscription.');

            return self::FAILURE;
        }

        $this->info('Registered '.($subscription['topic'] ?? 'FULFILLMENTS_CREATE').' at '.($subscription['uri'] ?? $callbackUrl).'.');

        return self::SUCCESS;
    }
}
```

Laravel 12 auto-discovers this command. Registration is intentionally idempotent for the same URI and refuses to create a competing subscription silently.

## 6. Webhook Endpoint

### Route — existing file

In `routes/web.php`, add the import and route beside the two existing Shopify webhook routes:

```php
use App\Http\Controllers\ShopifyFulfillmentWebhookController;

Route::post('/webhooks/shopify/fulfillments-create', ShopifyFulfillmentWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.shopify.fulfillments-create');
```

### Controller — new file

Create `app/Http/Controllers/ShopifyFulfillmentWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyFulfillmentWebhookJob;
use App\Models\ShopifyWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyFulfillmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->shouldVerifyWebhook()) {
            $secret = trim((string) config('services.shopify.webhook_secret'));
            if ($secret === '') {
                Log::error('Shopify fulfillment webhook rejected because SHOPIFY_WEBHOOK_SECRET is not configured.');

                return response()->json(['message' => 'Webhook secret is not configured.'], 500);
            }

            if (! $this->hasValidHmac($request, $secret)) {
                Log::warning('Shopify fulfillment webhook rejected because HMAC signature did not match.', [
                    'topic' => $request->header('X-Shopify-Topic'),
                    'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                    'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
                    'body_length' => strlen($request->getContent()),
                ]);

                return response()->json(['message' => 'Invalid webhook signature.'], 401);
            }
        } else {
            Log::warning('Shopify fulfillment webhook HMAC verification skipped for local testing.', [
                'environment' => app()->environment(),
                'topic' => $request->header('X-Shopify-Topic'),
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
            ]);
        }

        $topic = strtolower(trim((string) $request->header('X-Shopify-Topic')));
        if ($topic !== 'fulfillments/create') {
            return response()->json(['message' => 'Unsupported webhook topic.'], 400);
        }

        if (! (bool) config('shopify_stack_fulfillment.enabled')) {
            Log::notice('Shopify fulfillment webhook acknowledged while stack deductions are disabled.', [
                'webhook_id' => $request->header('X-Shopify-Webhook-Id'),
                'shop_domain' => $request->header('X-Shopify-Shop-Domain'),
            ]);

            return response()->json(['status' => 'disabled'], 200);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id'));
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain')));
        $fulfillmentId = trim((string) ($payload['id'] ?? ''));
        $orderId = trim((string) ($payload['order_id'] ?? ''));

        if ($webhookId === '' || $shopDomain === '' || $fulfillmentId === '' || $orderId === '') {
            Log::warning('Shopify fulfillment webhook is missing a required identity.', [
                'webhook_id_present' => $webhookId !== '',
                'shop_domain_present' => $shopDomain !== '',
                'fulfillment_id_present' => $fulfillmentId !== '',
                'order_id_present' => $orderId !== '',
            ]);

            return response()->json(['message' => 'Missing webhook, shop, fulfillment, or order identity.'], 422);
        }

        if (! is_array($payload['line_items'] ?? null)) {
            return response()->json(['message' => 'Missing fulfillment line items.'], 422);
        }

        [$event, $created] = DB::transaction(function () use (
            $request, $payload, $webhookId, $shopDomain, $fulfillmentId, $orderId, $topic
        ): array {
            $event = ShopifyWebhookEvent::query()->firstOrCreate(
                ['webhook_id' => $webhookId],
                [
                    'event_id' => trim((string) $request->header('X-Shopify-Event-Id')) ?: null,
                    'topic' => $topic,
                    'shop_domain' => $shopDomain,
                    'api_version' => trim((string) $request->header('X-Shopify-API-Version')) ?: null,
                    'triggered_at' => trim((string) $request->header('X-Shopify-Triggered-At')) ?: null,
                    'shopify_order_id' => $orderId,
                    'shopify_fulfillment_id' => $fulfillmentId,
                    'shopify_location_id' => $this->locationGid($payload['location_id'] ?? null),
                    'status' => ShopifyWebhookEvent::STATUS_PENDING,
                    'payload' => $payload,
                ]
            );

            if ($event->wasRecentlyCreated) {
                ProcessShopifyFulfillmentWebhookJob::dispatch($event->id)
                    ->onQueue((string) config('shopify_stack_fulfillment.queue', 'default'))
                    ->afterCommit();
            }

            return [$event, $event->wasRecentlyCreated];
        });

        Log::info($created
            ? 'Shopify fulfillment webhook persisted and queued.'
            : 'Duplicate Shopify fulfillment webhook acknowledged.', [
                'event_db_id' => $event->id,
                'webhook_id' => $webhookId,
                'shop_domain' => $shopDomain,
                'shopify_order_id' => $orderId,
                'shopify_fulfillment_id' => $fulfillmentId,
            ]);

        return response()->json(
            ['status' => $created ? 'queued' : 'duplicate'],
            $created ? 202 : 200,
        );
    }

    private function hasValidHmac(Request $request, string $secret): bool
    {
        $header = trim((string) $request->header('X-Shopify-Hmac-Sha256'));
        if ($header === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        return hash_equals($calculated, $header);
    }

    private function shouldVerifyWebhook(): bool
    {
        if ((bool) config('services.shopify.verify_webhooks', true)) {
            return true;
        }

        return ! app()->environment(['local', 'testing']);
    }

    private function locationGid(mixed $value): ?string
    {
        $id = trim((string) ($value ?? ''));
        if ($id === '') {
            return null;
        }

        return str_starts_with($id, 'gid://shopify/Location/')
            ? $id
            : 'gid://shopify/Location/'.$id;
    }
}
```

The endpoint performs no Shopify call. It verifies, durably stores, queues, and responds quickly. Invalid HMAC is never persisted. Duplicate `X-Shopify-Webhook-Id` deliveries do not dispatch another job.

## 7. Webhook Processing Job/Service

### Job — new file

Create `app/Jobs/ProcessShopifyFulfillmentWebhookJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ShopifyWebhookEvent;
use App\Services\StackFulfillmentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessShopifyFulfillmentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;
    public int $maxExceptions = 8;
    public int $timeout = 300;

    public function __construct(public int $webhookEventId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600, 7200, 14400];
    }

    public function handle(StackFulfillmentProcessor $processor): void
    {
        if (! (bool) config('shopify_stack_fulfillment.enabled')) {
            return;
        }

        $processor->process($this->webhookEventId);
    }

    public function failed(Throwable $exception): void
    {
        ShopifyWebhookEvent::query()->whereKey($this->webhookEventId)->update([
            'status' => ShopifyWebhookEvent::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => $exception->getMessage(),
        ]);
    }
}
```

### Processor — new file

Create `app/Services/StackFulfillmentProcessor.php`:

```php
<?php

namespace App\Services;

use App\Models\ShopifyStackInventoryAdjustment;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StackFulfillmentProcessor
{
    public function __construct(
        private readonly StackFulfillmentResolver $resolver,
        private readonly ShopifyInventoryAdjustmentService $inventory,
    ) {}

    public function process(int $eventId): void
    {
        $event = ShopifyWebhookEvent::query()->findOrFail($eventId);
        $lock = Cache::lock(
            'shopify-stack-fulfillment:'.$event->shop_domain.':'.$event->shopify_fulfillment_id,
            (int) config('shopify_stack_fulfillment.lock_seconds', 900),
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $this->processLocked($event->fresh());
        } finally {
            $lock->release();
        }
    }

    private function processLocked(ShopifyWebhookEvent $event): void
    {
        if ($event->status === ShopifyWebhookEvent::STATUS_COMPLETED) {
            return;
        }

        $event->forceFill([
            'status' => ShopifyWebhookEvent::STATUS_PROCESSING,
            'attempt_count' => (int) $event->attempt_count + 1,
            'processing_started_at' => now(),
            'failed_at' => null,
            'last_error' => null,
        ])->save();

        try {
            $adjustmentIds = ShopifyStackInventoryAdjustment::query()
                ->where('shop_domain', $event->shop_domain)
                ->where('shopify_fulfillment_id', $event->shopify_fulfillment_id)
                ->where('direction', 'deduction')
                ->pluck('id')
                ->all();

            if ($adjustmentIds === []) {
                $definitions = $this->resolver->resolve($event);

                $adjustmentIds = DB::transaction(function () use ($definitions, $event): array {
                    $ids = [];

                    foreach ($definitions as $definition) {
                        $dedupeKey = hash('sha256', implode('|', [
                            $event->shop_domain,
                            $event->shopify_fulfillment_id,
                            $definition['shopify_line_item_id'],
                            $definition['shopify_inventory_item_id'],
                            $definition['shopify_location_id'],
                            'deduction',
                        ]));

                        $adjustment = ShopifyStackInventoryAdjustment::query()->firstOrCreate(
                            ['dedupe_key' => $dedupeKey],
                            array_merge($definition, [
                                'webhook_event_id' => $event->id,
                                'idempotency_key' => (string) Str::uuid(),
                                'direction' => 'deduction',
                                'shop_domain' => $event->shop_domain,
                                'shopify_order_id' => $event->shopify_order_id,
                                'shopify_fulfillment_id' => $event->shopify_fulfillment_id,
                                'status' => ShopifyStackInventoryAdjustment::STATUS_PENDING,
                            ])
                        );

                        $ids[] = $adjustment->id;
                    }

                    return $ids;
                });
            }

            $failures = [];

            foreach (ShopifyStackInventoryAdjustment::query()->whereKey($adjustmentIds)->get() as $adjustment) {
                if ($adjustment->status === ShopifyStackInventoryAdjustment::STATUS_SUCCEEDED) {
                    continue;
                }

                $adjustment->forceFill([
                    'status' => ShopifyStackInventoryAdjustment::STATUS_PROCESSING,
                    'attempt_count' => (int) $adjustment->attempt_count + 1,
                    'processing_started_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ])->save();

                try {
                    $response = $this->inventory->decreaseAvailable(
                        inventoryItemId: $adjustment->shopify_inventory_item_id,
                        quantity: abs((int) $adjustment->quantity_delta),
                        locationId: $adjustment->shopify_location_id,
                        referenceUri: 'gid://shopify-editor/StackFulfillmentAdjustment/'.$adjustment->id,
                        idempotencyKey: $adjustment->idempotency_key,
                    );

                    $adjustment->forceFill([
                        'status' => ShopifyStackInventoryAdjustment::STATUS_SUCCEEDED,
                        'shopify_response' => $response,
                        'processed_at' => now(),
                        'failed_at' => null,
                        'last_error' => null,
                    ])->save();

                    Log::info('Shopify stack component inventory deducted.', $this->logContext($adjustment));
                } catch (Throwable $exception) {
                    $adjustment->forceFill([
                        'status' => ShopifyStackInventoryAdjustment::STATUS_FAILED,
                        'failed_at' => now(),
                        'last_error' => $exception->getMessage(),
                    ])->save();

                    $failures[] = "Adjustment {$adjustment->id}: {$exception->getMessage()}";
                    Log::error('Shopify stack component inventory deduction failed.', array_merge(
                        $this->logContext($adjustment),
                        ['error' => $exception->getMessage()],
                    ));
                }
            }

            if ($failures !== []) {
                $succeeded = ShopifyStackInventoryAdjustment::query()
                    ->whereKey($adjustmentIds)
                    ->where('status', ShopifyStackInventoryAdjustment::STATUS_SUCCEEDED)
                    ->exists();

                $event->forceFill([
                    'status' => $succeeded
                        ? ShopifyWebhookEvent::STATUS_PARTIAL
                        : ShopifyWebhookEvent::STATUS_FAILED,
                    'failed_at' => now(),
                    'last_error' => implode(' | ', $failures),
                ])->save();

                throw new RuntimeException($event->last_error);
            }

            $event->forceFill([
                'status' => ShopifyWebhookEvent::STATUS_COMPLETED,
                'processed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            if ($event->status !== ShopifyWebhookEvent::STATUS_PARTIAL) {
                $event->forceFill([
                    'status' => ShopifyWebhookEvent::STATUS_FAILED,
                    'failed_at' => now(),
                    'last_error' => $exception->getMessage(),
                ])->save();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function logContext(ShopifyStackInventoryAdjustment $adjustment): array
    {
        return [
            'adjustment_id' => $adjustment->id,
            'webhook_event_id' => $adjustment->webhook_event_id,
            'shop_domain' => $adjustment->shop_domain,
            'shopify_order_id' => $adjustment->shopify_order_id,
            'shopify_fulfillment_id' => $adjustment->shopify_fulfillment_id,
            'shopify_line_item_id' => $adjustment->shopify_line_item_id,
            'stack_sku' => $adjustment->stack_sku,
            'component_sku' => $adjustment->component_sku,
            'quantity_delta' => $adjustment->quantity_delta,
            'shopify_location_id' => $adjustment->shopify_location_id,
        ];
    }
}
```

## 8. Stack Component Calculation

### Resolver — new file

Create `app/Services/StackFulfillmentResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyWebhookEvent;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class StackFulfillmentResolver
{
    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function resolve(ShopifyWebhookEvent $event): array
    {
        $payload = (array) $event->payload;
        $locationId = $this->fulfillmentLocationId($event);
        $resolved = [];

        foreach ((array) ($payload['line_items'] ?? []) as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $fulfilledQuantity = (int) ($lineItem['quantity'] ?? 0);
            if ($fulfilledQuantity <= 0) {
                continue;
            }

            $variant = $this->resolveLocalVariant($lineItem);
            if (! $variant instanceof Variant || ! $variant->product instanceof Product) {
                $orphanedStackDraft = $this->resolveStackDraftFromLineItem($lineItem);
                if ($orphanedStackDraft instanceof NewProductDraft) {
                    throw new RuntimeException(
                        "Stack draft {$orphanedStackDraft->id} matched the fulfillment but its local variant could not be resolved."
                    );
                }

                continue; // A normal/unmanaged Shopify line is not a stack error.
            }

            $product = $variant->product;
            $draft = $this->resolveStackDraft($product, $variant);
            $looksLikeStack = (bool) $product->is_bundle
                || $this->hasStackToken((string) $product->tags)
                || $draft instanceof NewProductDraft;

            if (! $looksLikeStack) {
                continue;
            }

            if (! $draft instanceof NewProductDraft) {
                throw new RuntimeException("Stack SKU '{$variant->sku}' has no linked new product draft.");
            }

            $components = $draft->stackComponentDefinitions();
            if ($components === []) {
                throw new RuntimeException("Stack draft {$draft->id} has no components.");
            }

            foreach ($components as $componentDefinition) {
                $component = Product::query()
                    ->with(['variants' => fn ($query) => $query->orderBy('id')])
                    ->find((int) $componentDefinition['product_id']);

                if (! $component instanceof Product) {
                    throw new RuntimeException(
                        "Stack draft {$draft->id} references missing component product {$componentDefinition['product_id']}."
                    );
                }

                if ($component->variants->count() !== 1) {
                    throw new RuntimeException(
                        "Component product {$component->id} must have exactly one active variant; found {$component->variants->count()}."
                    );
                }

                /** @var Variant $componentVariant */
                $componentVariant = $component->variants->first();
                $inventoryItemId = trim((string) $componentVariant->shopify_inventory_item_id);
                if ($inventoryItemId === '') {
                    throw new RuntimeException("Component variant {$componentVariant->id} has no Shopify inventory item ID.");
                }

                $perStack = (int) $componentDefinition['quantity'];

                $resolved[] = [
                    'shopify_line_item_id' => $this->lineItemId($lineItem),
                    'shopify_stack_product_id' => trim((string) ($product->shopify_id ?? '')) ?: null,
                    'shopify_stack_variant_id' => trim((string) ($variant->shopify_id ?? '')) ?: null,
                    'stack_sku' => trim((string) ($variant->sku ?? '')) ?: null,
                    'stack_draft_id' => (int) $draft->id,
                    'fulfilled_stack_quantity' => $fulfilledQuantity,
                    'component_product_id' => (int) $component->id,
                    'component_variant_id' => (int) $componentVariant->id,
                    'component_sku' => trim((string) ($componentVariant->sku ?? '')) ?: null,
                    'component_quantity_per_stack' => $perStack,
                    'quantity_delta' => -($fulfilledQuantity * $perStack),
                    'shopify_inventory_item_id' => $inventoryItemId,
                    'shopify_location_id' => $locationId,
                ];
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $lineItem */
    private function resolveLocalVariant(array $lineItem): ?Variant
    {
        $variantId = trim((string) ($lineItem['variant_id'] ?? ''));
        if ($variantId !== '') {
            $variant = Variant::query()
                ->active()
                ->with('product')
                ->whereIn('shopify_id', $this->gidCandidates('ProductVariant', $variantId))
                ->first();
            if ($variant instanceof Variant) {
                return $variant;
            }
        }

        $sku = trim((string) ($lineItem['sku'] ?? ''));
        if ($sku === '') {
            return null;
        }

        $matches = Variant::query()->active()->with('product')->where('sku', $sku)->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException("Shopify fulfillment SKU '{$sku}' is ambiguous locally.");
        }

        return $matches->first();
    }

    private function resolveStackDraft(Product $product, Variant $variant): ?NewProductDraft
    {
        return NewProductDraft::query()
            ->where(function (Builder $query) use ($product, $variant): void {
                $shopifyId = trim((string) $product->shopify_id);
                $handle = trim((string) $product->handle);
                $sku = trim((string) $variant->sku);

                if ($shopifyId !== '') {
                    $query->orWhereIn('shopify_id', $this->gidCandidates('Product', $shopifyId));
                }
                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
                if ($sku !== '') {
                    $query->orWhere('sku', $sku);
                }
            })
            ->whereNotNull('bundle_product_ids')
            ->orderByDesc('updated_at')
            ->first();
    }

    /** @param array<string, mixed> $lineItem */
    private function resolveStackDraftFromLineItem(array $lineItem): ?NewProductDraft
    {
        $productId = trim((string) ($lineItem['product_id'] ?? ''));
        $sku = trim((string) ($lineItem['sku'] ?? ''));
        if ($productId === '' && $sku === '') {
            return null;
        }

        return NewProductDraft::query()
            ->where(function (Builder $query) use ($productId, $sku): void {
                if ($productId !== '') {
                    $query->orWhereIn('shopify_id', $this->gidCandidates('Product', $productId));
                }
                if ($sku !== '') {
                    $query->orWhere('sku', $sku);
                }
            })
            ->whereNotNull('bundle_product_ids')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function fulfillmentLocationId(ShopifyWebhookEvent $event): string
    {
        $id = trim((string) $event->shopify_location_id);
        if ($id === '') {
            $id = trim((string) config('services.shopify.inventory_location_id'));
        }
        if ($id === '') {
            throw new RuntimeException('Fulfillment has no location and SHOPIFY_INVENTORY_LOCATION_ID is not configured.');
        }

        return str_starts_with($id, 'gid://shopify/Location/')
            ? $id
            : 'gid://shopify/Location/'.$id;
    }

    /** @param array<string, mixed> $lineItem */
    private function lineItemId(array $lineItem): string
    {
        $id = trim((string) ($lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Fulfillment line item is missing its Shopify line-item ID.');
        }

        return $id;
    }

    /** @return array<int, string> */
    private function gidCandidates(string $type, string $id): array
    {
        $id = trim($id);
        if (preg_match('/^gid:\/\/shopify\/'.preg_quote($type, '/').'\/(\d+)$/', $id, $matches) === 1) {
            return [$id, $matches[1]];
        }

        return preg_match('/^\d+$/', $id) === 1
            ? [$id, "gid://shopify/{$type}/{$id}"]
            : [$id];
    }

    private function hasStackToken(string $tags): bool
    {
        return collect(TagNormalizer::parseTokens($tags))->contains(function (string $tag): bool {
            return in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)
                || str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks');
        });
    }
}
```

### Quantity editing — existing files

In `app/Filament/Resources/NewProductDraftResource.php`, add this normalizer next to `normalizeBundleProductIds()`:

```php
/**
 * @param array<int, int> $productIds
 * @return array<int, array{product_id:int,quantity:int}>
 */
private static function normalizeBundleComponentQuantities(mixed $state, array $productIds): array
{
    $existing = collect(is_array($state) ? $state : [])
        ->filter(fn ($row): bool => is_array($row))
        ->mapWithKeys(function (array $row): array {
            $productId = (int) ($row['product_id'] ?? 0);
            $quantity = max(1, (int) ($row['quantity'] ?? 1));

            return $productId > 0 ? [$productId => $quantity] : [];
        });

    return collect($productIds)
        ->map(fn (int $productId): array => [
            'product_id' => $productId,
            'quantity' => (int) $existing->get($productId, 1),
        ])
        ->values()
        ->all();
}
```

In the existing `Select::make('bundle_product_ids')`, make its `afterStateUpdated` callback begin with this code before its existing image-selection logic:

```php
$productIds = self::normalizeBundleProductIds($state);
$set('bundle_component_quantities', self::normalizeBundleComponentQuantities(
    $get('bundle_component_quantities'),
    $productIds,
));
```

Immediately after that Select, add the following field and import `Filament\Forms\Components\Repeater`:

```php
Repeater::make('bundle_component_quantities')
    ->label('Component quantities per stack')
    ->helperText('Each quantity is the number of this component consumed by one fulfilled stack.')
    ->schema([
        Select::make('product_id')
            ->label('Component')
            ->options(fn (): array => self::bundleProductOptions())
            ->disabled()
            ->dehydrated()
            ->required(),
        TextInput::make('quantity')
            ->integer()
            ->minValue(1)
            ->default(1)
            ->required(),
    ])
    ->columns(2)
    ->addable(false)
    ->deletable(false)
    ->reorderable(false)
    ->visible(fn (Get $get, ?NewProductDraft $record): bool =>
        self::shouldShowBundleAssociationField($get, $record)
        && self::normalizeBundleProductIds($get('bundle_product_ids')) !== []
    )
    ->columnSpanFull(),
```

In `mutateDraftFormData()`, immediately after normalizing `bundle_product_ids`, normalize the quantity rows:

```php
$data['bundle_component_quantities'] = self::nullableArray(
    self::normalizeBundleComponentQuantities(
        $data['bundle_component_quantities'] ?? null,
        (array) ($data['bundle_product_ids'] ?? []),
    )
);
```

When the draft is not a stack, also set `$data['bundle_component_quantities'] = null;` beside the existing clearing of `bundle_product_ids`.

In `app/Services/NewProductDraftStackAssociationImporter.php`, replace the current association save block with this quantity-preserving version:

```php
$current = $this->normalizeProductIds($draft->bundle_product_ids);
$currentQuantities = collect((array) $draft->bundle_component_quantities)
    ->filter(fn ($row): bool => is_array($row))
    ->mapWithKeys(fn (array $row): array => [
        (int) ($row['product_id'] ?? 0) => max(1, (int) ($row['quantity'] ?? 1)),
    ]);

$quantities = collect($productIds)
    ->map(fn (int $productId): array => [
        'product_id' => $productId,
        'quantity' => (int) $currentQuantities->get($productId, 1),
    ])
    ->values()
    ->all();

if ($current === $productIds && $draft->stackComponentDefinitions() === $quantities) {
    $result['unchanged']++;
    continue;
}

NewProductDraft::withoutEvents(function () use ($draft, $productIds, $quantities): void {
    $draft->forceFill([
        'bundle_product_ids' => $productIds,
        'bundle_component_quantities' => $quantities,
    ])->save();
});

$result['updated']++;
```

## 9. Shopify Inventory Adjustment

Replace `app/Services/Shopify/ShopifyInventoryAdjustmentService.php` with this complete version. It retains the procurement method and adds the fulfillment-specific method:

```php
<?php

namespace App\Services\Shopify;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\ShopifyInventorySnapshot;
use App\Models\Variant;
use InvalidArgumentException;
use RuntimeException;

class ShopifyInventoryAdjustmentService
{
    public function __construct(private readonly ShopifyGraphqlGateway $client) {}

    public function resolveLocationId(Variant $variant): string
    {
        $locationId = trim((string) config('services.shopify.inventory_location_id'));
        if ($locationId !== '') {
            return $this->locationGid($locationId);
        }

        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId !== '') {
            $locationId = trim((string) ShopifyInventorySnapshot::query()
                ->where('shopify_inventory_item_id', $inventoryItemId)
                ->whereNotNull('shopify_location_id')
                ->latest('id')
                ->value('shopify_location_id'));
        }
        if ($locationId === '') {
            $locations = $this->client->graphql('query ProcurementLocation { locations(first: 1) { nodes { id } } }');
            $locationId = trim((string) data_get($locations, 'locations.nodes.0.id'));
        }
        if ($locationId === '') {
            throw new RuntimeException('No Shopify inventory location could be resolved.');
        }

        return $this->locationGid($locationId);
    }

    public function increaseAvailable(
        Variant $variant,
        int $quantity,
        string $referenceUri,
        ?string $locationId = null,
    ): void {
        $inventoryItemId = trim((string) $variant->shopify_inventory_item_id);
        if ($inventoryItemId === '') {
            throw new RuntimeException("Variant {$variant->id} has no Shopify inventory item ID.");
        }

        $data = $this->adjustAvailable(
            inventoryItemId: $inventoryItemId,
            delta: $quantity,
            locationId: $locationId ?? $this->resolveLocationId($variant),
            reason: 'received',
            referenceUri: $referenceUri,
        );

        if (! data_get($data, 'inventoryAdjustQuantities.inventoryAdjustmentGroup')) {
            throw new RuntimeException('Shopify did not return an inventory adjustment confirmation.');
        }
    }

    /** @return array<string, mixed> */
    public function decreaseAvailable(
        string $inventoryItemId,
        int $quantity,
        string $locationId,
        string $referenceUri,
        string $idempotencyKey,
    ): array {
        $inventoryItemId = trim($inventoryItemId);
        $locationId = trim($locationId);
        if ($inventoryItemId === '' || $locationId === '') {
            throw new InvalidArgumentException('Inventory item and location IDs are required.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Deduction quantity must be greater than zero.');
        }

        $data = $this->adjustAvailable(
            inventoryItemId: $inventoryItemId,
            delta: -$quantity,
            locationId: $locationId,
            reason: 'correction',
            referenceUri: $referenceUri,
            idempotencyKey: $idempotencyKey,
        );

        $group = data_get($data, 'inventoryAdjustQuantities.inventoryAdjustmentGroup');
        if (! is_array($group)) {
            throw new RuntimeException('Shopify did not return an inventory adjustment confirmation.');
        }

        return $group;
    }

    /** @return array<string, mixed> */
    private function adjustAvailable(
        string $inventoryItemId,
        int $delta,
        string $locationId,
        string $reason,
        string $referenceUri,
        ?string $idempotencyKey = null,
    ): array {
        $directive = $idempotencyKey === null ? '' : ' @idempotent(key: $idempotencyKey)';
        $declaration = $idempotencyKey === null
            ? '($input: InventoryAdjustQuantitiesInput!)'
            : '($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!)';

        $query = <<<'GRAPHQL'
mutation AdjustInventory__DECLARATION__ {
  inventoryAdjustQuantities(input: $input)__DIRECTIVE__ {
    inventoryAdjustmentGroup {
      createdAt
      reason
      referenceDocumentUri
      changes { name delta }
    }
    userErrors { field message code }
  }
}
GRAPHQL;
        $query = str_replace(
            ['__DECLARATION__', '__DIRECTIVE__'],
            [$declaration, $directive],
            $query,
        );

        $variables = [
            'input' => [
                'name' => 'available',
                'reason' => $reason,
                'referenceDocumentUri' => $referenceUri,
                'changes' => [[
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $this->locationGid($locationId),
                    'delta' => $delta,
                ]],
            ],
        ];
        if ($idempotencyKey !== null) {
            $variables['idempotencyKey'] = $idempotencyKey;
        }

        $data = $this->client->graphql($query, $variables);
        $errors = data_get($data, 'inventoryAdjustQuantities.userErrors', []);
        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->map(function (array $error): string {
                $field = is_array($error['field'] ?? null)
                    ? implode('.', $error['field'])
                    : (string) ($error['field'] ?? 'input');
                $code = trim((string) ($error['code'] ?? ''));

                return $field.': '.($error['message'] ?? 'Unknown error').($code !== '' ? " [{$code}]" : '');
            })->implode('; ');

            throw new RuntimeException('Shopify inventory adjustment failed: '.$message);
        }

        return $data;
    }

    private function locationGid(string $id): string
    {
        $id = trim($id);

        return str_starts_with($id, 'gid://shopify/Location/')
            ? $id
            : 'gid://shopify/Location/'.$id;
    }
}
```

The mutation deliberately sends one component adjustment per call. This makes the response attributable to one audit row and avoids ambiguous partial success inside a multi-change mutation.

## 10. Idempotency

Idempotency has four independent layers:

1. `shopify_webhook_events.webhook_id` is unique, preventing the normal duplicate delivery from dispatching twice.
2. `shopify_stack_inventory_adjustments.dedupe_key` is a SHA-256 of shop + fulfillment + fulfilled order line + Shopify inventory item + location + direction. A duplicate arriving with a different delivery header still resolves to the same semantic operation.
3. Each adjustment receives a UUID once, before the external call. Every retry uses that same `idempotency_key` in Shopify's `@idempotent` directive.
4. A succeeded row is never called again. Failed/processing rows are retried with the original UUID. If Shopify succeeded but the worker died before the local success update, Shopify returns the idempotent result instead of applying the delta twice.

The first attempt inserts the entire calculated adjustment set in one database transaction. Later attempts load that frozen set by shop + fulfillment instead of recalculating current stack composition. Editing a stack after fulfillment therefore cannot change what a retry deducts.

Do not use only order ID: a single order legitimately has multiple fulfillment IDs. Do not use only fulfillment ID: one fulfillment has multiple order line items and each stack has multiple components.

The 2026-01 `fulfillments/create` JSON supplies the order line-item ID inside the fulfillment, not a separate GraphQL `FulfillmentLineItem` GID. The semantic fulfilled-line identity is therefore `(shop_domain, fulfillment_id, line_item_id)`, with component inventory item and location added for each adjustment.

## 11. Partial Fulfillment Handling

For an order containing `STACK-A × 5`, a first webhook with `line_items[STACK-A].quantity = 2` produces component deltas based on `2`. A later fulfillment has a different fulfillment ID and its own line-item quantity `3`, so its dedupe keys are distinct and it produces only the remaining deltas. The ordered quantity `5`, current quantity, fulfillment status, and `fulfillable_quantity` are not calculation inputs.

Multiple stack lines are processed independently. Normal-product lines resolve to a non-stack product and are skipped. If two stacks share a component, each fulfillment-line/component pair has its own row and safe mutation; their deltas add naturally in Shopify.

## 12. Error Handling and Retries

| Failure | Behavior |
|---|---|
| Invalid/missing HMAC | `401`; no event or job |
| Missing webhook secret in verified mode | `500`; Shopify retries delivery |
| Invalid topic/JSON/identity | `400`/`422`; structured log; no job |
| Stack-looking product has no draft | event failed; no component mutation |
| Stack has no components | event failed; no component mutation |
| Component product missing | event failed with exact draft/component ID |
| Component has zero/multiple active variants | event failed; no guessing |
| Missing inventory item ID | event failed before mutation |
| Missing fulfillment/configured location | event failed before mutation |
| Shopify 429/5xx/unavailable | `ShopifyApiClient` throws; row fails; queued backoff retries with same UUID |
| GraphQL/user error | exact field/message/code stored in `last_error`; retry preserves UUID |
| Some components succeed | successful rows remain succeeded; event is partial; only failed rows retry |
| Worker dies after Shopify success | row remains processing; retry uses same Shopify idempotency UUID |

After eight job attempts, Laravel writes the job to `failed_jobs`; `failed()` leaves the event failed and visible. Operational recovery is `php artisan queue:retry {failed-job-uuid}` after correcting catalog/location/scope issues. Never manually change a succeeded adjustment back to pending.

## 13. Logging / Audit Trail

The two new tables are the authoritative audit trail. Application logs are secondary and must contain IDs/SKUs/deltas/status, never the webhook secret or Admin token. `ShopifyApiClient` already logs outgoing GraphQL payloads; the new mutation includes inventory IDs and quantities but no credential.

Create `app/Filament/Resources/ShopifyStackInventoryAdjustmentResource.php`:

```php
<?php

namespace App\Filament\Resources;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyStackInventoryAdjustmentResource\Pages;
use App\Models\ShopifyStackInventoryAdjustment;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ShopifyStackInventoryAdjustmentResource extends Resource
{
    protected static ?string $model = ShopifyStackInventoryAdjustment::class;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';
    protected static ?string $navigationGroup = 'Shopify Sync';
    protected static ?string $navigationLabel = 'Stack Fulfillment Deductions';
    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('shopify_order_id')->label('Order')->searchable(),
                TextColumn::make('shopify_fulfillment_id')->label('Fulfillment')->searchable(),
                TextColumn::make('stack_sku')->label('Stack')->searchable(),
                TextColumn::make('fulfilled_stack_quantity')->label('Stack qty'),
                TextColumn::make('component_sku')->label('Component')->searchable(),
                TextColumn::make('component_quantity_per_stack')->label('Per stack'),
                TextColumn::make('quantity_delta')->label('Shopify delta'),
                TextColumn::make('shopify_location_id')->label('Location')->limit(28)->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempt_count')->label('Attempts')->sortable(),
                TextColumn::make('last_error')->limit(80)->wrap()->toggleable(),
                TextColumn::make('processed_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'succeeded' => 'Succeeded',
                    'failed' => 'Failed',
                ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getPages(): array
    {
        return ['index' => Pages\ListShopifyStackInventoryAdjustments::route('/')];
    }
}
```

Create `app/Filament/Resources/ShopifyStackInventoryAdjustmentResource/Pages/ListShopifyStackInventoryAdjustments.php`:

```php
<?php

namespace App\Filament\Resources\ShopifyStackInventoryAdjustmentResource\Pages;

use App\Filament\Resources\ShopifyStackInventoryAdjustmentResource;
use Filament\Resources\Pages\ListRecords;

class ListShopifyStackInventoryAdjustments extends ListRecords
{
    protected static string $resource = ShopifyStackInventoryAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
```

## 14. Returns / Restocking Design

### Phase 1 — required for initial implementation

Only negative component adjustments caused by `fulfillments/create` are in scope. Do not reverse a deduction merely because an order is cancelled or refunded: Shopify can refund without restocking, and a cancellation after fulfillment does not prove physical stock returned.

The Phase 1 table already includes `direction` and nullable `reversal_of_id`. Those are the required forward-compatible decisions. Deduction rows are immutable after success.

### Phase 2 — return/restock reversal

Use `refunds/create` as the primary candidate because Shopify refund line items include the affected order line item, quantity, restock decision/type, and location. Evaluate `returns/process` as an additional signal for stores using Shopify's Returns workflow, but never let both events reverse the same units.

The Phase 2 service should:

1. Verify/persist the return/refund webhook through `shopify_webhook_events`.
2. Consider only explicitly restocked stack quantities; a financial refund without restock produces no component movement.
3. Match the refund/return line back to succeeded Phase 1 deduction rows by order line item and fulfillment allocation.
4. Cap cumulative reversal quantity at the original succeeded deduction quantity.
5. Insert positive-delta rows with `direction = reversal`, `reversal_of_id` pointing to the original deduction, a new semantic dedupe key, and a stored Shopify idempotency UUID.
6. Use the actual restock location. If it differs from the fulfillment location, record and use the restock location.
7. Call the same `inventoryAdjustQuantities` boundary with a positive delta and a return-specific reference URI.

Do not implement Phase 2 in the first release. Before starting it, verify the production store's exact refund/return operational path and sample payloads.

## 15. Reconciliation

The fulfillment webhook remains primary. Reconciliation must detect missing fulfillment IDs and must not blindly apply inventory changes.

Create `app/Console/Commands/ReconcileShopifyStackFulfillments.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\ShopifyWebhookEvent;
use App\Services\ShopifyApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileShopifyStackFulfillments extends Command
{
    protected $signature = 'shopify:reconcile-stack-fulfillments
        {--hours= : Lookback in hours; defaults to configured days}';

    protected $description = 'Report Shopify fulfillments missing from the stack deduction audit';

    public function handle(ShopifyApiClient $client): int
    {
        $defaultHours = max(1, (int) config('shopify_stack_fulfillment.reconciliation_days', 3) * 24);
        $hours = max(1, (int) ($this->option('hours') ?: $defaultHours));
        $since = CarbonImmutable::now('UTC')->subHours($hours);
        $after = null;
        $missing = [];

        do {
            $data = $client->graphql(<<<'GRAPHQL'
query RecentFulfillments($after: String, $query: String!) {
  orders(first: 100, after: $after, sortKey: UPDATED_AT, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
      fulfillments(first: 100) {
        id
        legacyResourceId
        status
        createdAt
        location { id }
        fulfillmentLineItems(first: 250) {
          nodes {
            id
            quantity
            lineItem {
              id
              sku
              variant { id product { id } }
            }
          }
        }
      }
    }
  }
}
GRAPHQL, [
                'after' => $after,
                'query' => "updated_at:>='{$since->toIso8601String()}'",
            ]);

            foreach ((array) data_get($data, 'orders.nodes', []) as $order) {
                foreach ((array) ($order['fulfillments'] ?? []) as $fulfillment) {
                    $legacyId = trim((string) ($fulfillment['legacyResourceId'] ?? ''));
                    $gid = trim((string) ($fulfillment['id'] ?? ''));
                    $status = strtoupper(trim((string) ($fulfillment['status'] ?? '')));
                    if ($status === 'CANCELLED' || ($legacyId === '' && $gid === '')) {
                        continue;
                    }

                    $exists = ShopifyWebhookEvent::query()
                        ->where('topic', 'fulfillments/create')
                        ->whereIn('shopify_fulfillment_id', array_values(array_filter([$legacyId, $gid])))
                        ->where('status', ShopifyWebhookEvent::STATUS_COMPLETED)
                        ->exists();

                    if (! $exists) {
                        $missing[] = [
                            'order' => (string) ($order['name'] ?? $order['id'] ?? ''),
                            'fulfillment' => $legacyId !== '' ? $legacyId : $gid,
                            'status' => $status,
                            'created_at' => (string) ($fulfillment['createdAt'] ?? ''),
                            'location' => (string) data_get($fulfillment, 'location.id', ''),
                        ];
                    }
                }
            }

            $hasNextPage = (bool) data_get($data, 'orders.pageInfo.hasNextPage', false);
            $after = data_get($data, 'orders.pageInfo.endCursor');
        } while ($hasNextPage && is_string($after) && $after !== '');

        if ($missing === []) {
            $this->info("No missing fulfillments found in the last {$hours} hours.");

            return self::SUCCESS;
        }

        $this->table(['Order', 'Fulfillment', 'Status', 'Created', 'Location'], $missing);
        Log::warning('Shopify fulfillment reconciliation found unprocessed fulfillments.', [
            'hours' => $hours,
            'count' => count($missing),
            'fulfillments' => array_column($missing, 'fulfillment'),
        ]);
        $this->warn('No inventory was changed. Investigate and redeliver/replay each verified fulfillment deliberately.');

        return self::FAILURE;
    }
}
```

Optionally add this schedule to `routes/console.php` after the daily Shopify pipeline schedule:

```php
Schedule::command('shopify:reconcile-stack-fulfillments')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('shopify-stack-fulfillment-reconciliation');
```

This command needs `read_orders`. It is intentionally detection-only. A future replay command must fetch the exact fulfillment, persist a canonical payload, and pass through the same dedupe tables; it must never execute an unrecorded direct adjustment.

## 16. Automated Tests

Create `tests/Feature/ShopifyStackFulfillmentDeductionTest.php`. The following is a complete test suite for the endpoint, calculations, multiple/partial fulfillments, idempotency, failures, and retry after partial success. It follows the repository's Pest/`RefreshDatabase` conventions.

```php
<?php

use App\Jobs\ProcessShopifyFulfillmentWebhookJob;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyStackInventoryAdjustment;
use App\Models\ShopifyWebhookEvent;
use App\Models\User;
use App\Models\Variant;
use App\Services\StackFulfillmentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.shopify.shop' => 'stack-test.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
        'services.shopify.webhook_secret' => 'webhook-secret',
        'services.shopify.verify_webhooks' => true,
        'shopify_stack_fulfillment.enabled' => true,
    ]);
});

it('verifies persists and queues a fulfillment webhook', function (): void {
    Queue::fake();
    $payload = stackFulfillmentPayload('5001', '6001', []);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/shopify/fulfillments-create', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $json, 'webhook-secret', true)),
        'HTTP_X_SHOPIFY_TOPIC' => 'fulfillments/create',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-5001',
        'HTTP_X_SHOPIFY_EVENT_ID' => 'event-5001',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'stack-test.myshopify.com',
        'HTTP_X_SHOPIFY_API_VERSION' => '2026-01',
    ], $json);

    $response->assertAccepted();
    expect(ShopifyWebhookEvent::query()->count())->toBe(1);

    $duplicate = $this->call('POST', '/webhooks/shopify/fulfillments-create', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $json, 'webhook-secret', true)),
        'HTTP_X_SHOPIFY_TOPIC' => 'fulfillments/create',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-5001',
        'HTTP_X_SHOPIFY_EVENT_ID' => 'event-5001',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'stack-test.myshopify.com',
        'HTTP_X_SHOPIFY_API_VERSION' => '2026-01',
    ], $json);

    $duplicate->assertOk()->assertJson(['status' => 'duplicate']);
    expect(ShopifyWebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessShopifyFulfillmentWebhookJob::class, 1);
});

it('rejects an invalid fulfillment webhook signature', function (): void {
    $this->withHeaders([
        'X-Shopify-Hmac-Sha256' => 'invalid',
        'X-Shopify-Topic' => 'fulfillments/create',
    ])->postJson('/webhooks/shopify/fulfillments-create', [])->assertUnauthorized();

    expect(ShopifyWebhookEvent::query()->count())->toBe(0);
});

it('deducts components for stack quantity one', function (): void {
    $stack = stackFulfillmentCatalog('ONE', [1]);
    $event = stackFulfillmentEvent('7001', '8001', [stackFulfillmentLine($stack, 1)]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($event->id);

    expect(ShopifyStackInventoryAdjustment::query()->value('quantity_delta'))->toBe(-1)
        ->and($event->fresh()->status)->toBe(ShopifyWebhookEvent::STATUS_COMPLETED);
});

it('uses fulfilled stack quantity greater than one', function (): void {
    $stack = stackFulfillmentCatalog('MULTI', [1]);
    $event = stackFulfillmentEvent('7002', '8002', [stackFulfillmentLine($stack, 3)]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($event->id);

    expect(ShopifyStackInventoryAdjustment::query()->value('quantity_delta'))->toBe(-3);
});

it('multiplies the per-stack component quantity', function (): void {
    $stack = stackFulfillmentCatalog('PER-STACK', [2]);
    $event = stackFulfillmentEvent('7003', '8003', [stackFulfillmentLine($stack, 3)]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($event->id);

    $row = ShopifyStackInventoryAdjustment::query()->firstOrFail();
    expect($row->component_quantity_per_stack)->toBe(2)
        ->and($row->fulfilled_stack_quantity)->toBe(3)
        ->and($row->quantity_delta)->toBe(-6);
});

it('processes multiple stacks and skips a normal product', function (): void {
    $first = stackFulfillmentCatalog('FIRST', [1]);
    $second = stackFulfillmentCatalog('SECOND', [1, 2]);
    $normal = stackFulfillmentNormalProduct('NORMAL');
    $event = stackFulfillmentEvent('7004', '8004', [
        stackFulfillmentLine($first, 2),
        stackFulfillmentLine($normal, 4),
        stackFulfillmentLine($second, 1),
    ]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($event->id);

    expect(ShopifyStackInventoryAdjustment::query()->count())->toBe(3)
        ->and(ShopifyStackInventoryAdjustment::query()->sum('quantity_delta'))->toBe(-5)
        ->and(ShopifyStackInventoryAdjustment::query()->where('stack_sku', 'NORMAL')->exists())->toBeFalse();
});

it('handles a partial fulfillment and a later fulfillment independently', function (): void {
    $stack = stackFulfillmentCatalog('PARTIAL', [2]);
    $first = stackFulfillmentEvent('7005', '8005', [stackFulfillmentLine($stack, 2)]);
    $second = stackFulfillmentEvent('7005', '8006', [stackFulfillmentLine($stack, 3)]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($first->id);
    app(StackFulfillmentProcessor::class)->process($second->id);

    expect(ShopifyStackInventoryAdjustment::query()->orderBy('id')->pluck('quantity_delta')->all())
        ->toBe([-4, -6]);
});

it('does not duplicate an already processed fulfillment', function (): void {
    $stack = stackFulfillmentCatalog('DUPLICATE', [1]);
    $event = stackFulfillmentEvent('7006', '8007', [stackFulfillmentLine($stack, 2)]);
    fakeSuccessfulStackAdjustments();

    app(StackFulfillmentProcessor::class)->process($event->id);
    app(StackFulfillmentProcessor::class)->process($event->id);

    expect(ShopifyStackInventoryAdjustment::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('fails safely when a referenced component is missing', function (): void {
    $stack = stackFulfillmentCatalog('MISSING', [1]);
    $stack['draft']->forceFill([
        'bundle_product_ids' => [999999],
        'bundle_component_quantities' => [['product_id' => 999999, 'quantity' => 1]],
    ])->save();
    $event = stackFulfillmentEvent('7007', '8008', [stackFulfillmentLine($stack, 1)]);

    expect(fn () => app(StackFulfillmentProcessor::class)->process($event->id))
        ->toThrow(RuntimeException::class, 'missing component');
    expect($event->fresh()->status)->toBe(ShopifyWebhookEvent::STATUS_FAILED);
    Http::assertNothingSent();
});

it('records a failed Shopify adjustment', function (): void {
    $stack = stackFulfillmentCatalog('API-FAIL', [1]);
    $event = stackFulfillmentEvent('7008', '8009', [stackFulfillmentLine($stack, 1)]);
    Http::fake([ '*' => Http::response([
        'data' => ['inventoryAdjustQuantities' => [
            'inventoryAdjustmentGroup' => null,
            'userErrors' => [['field' => ['input'], 'message' => 'Inventory item is not stocked', 'code' => 'INVALID']]
        ]],
    ])]);

    expect(fn () => app(StackFulfillmentProcessor::class)->process($event->id))
        ->toThrow(RuntimeException::class, 'not stocked');

    $row = ShopifyStackInventoryAdjustment::query()->firstOrFail();
    expect($row->status)->toBe(ShopifyStackInventoryAdjustment::STATUS_FAILED)
        ->and($row->last_error)->toContain('not stocked');
});

it('retries only the failed component after partial API success', function (): void {
    $stack = stackFulfillmentCatalog('PARTIAL-FAIL', [1, 1]);
    $event = stackFulfillmentEvent('7009', '8010', [stackFulfillmentLine($stack, 1)]);

    Http::fakeSequence()
        ->push(stackAdjustmentSuccessResponse())
        ->push(['data' => ['inventoryAdjustQuantities' => [
            'inventoryAdjustmentGroup' => null,
            'userErrors' => [['field' => ['input'], 'message' => 'Temporary failure', 'code' => 'INTERNAL_ERROR']],
        ]]])
        ->push(stackAdjustmentSuccessResponse());

    expect(fn () => app(StackFulfillmentProcessor::class)->process($event->id))
        ->toThrow(RuntimeException::class, 'Temporary failure');

    $firstKeys = ShopifyStackInventoryAdjustment::query()->orderBy('id')->pluck('idempotency_key')->all();
    expect(ShopifyStackInventoryAdjustment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and($event->fresh()->status)->toBe(ShopifyWebhookEvent::STATUS_PARTIAL);

    app(StackFulfillmentProcessor::class)->process($event->id);

    expect(ShopifyStackInventoryAdjustment::query()->where('status', 'succeeded')->count())->toBe(2)
        ->and(ShopifyStackInventoryAdjustment::query()->orderBy('id')->pluck('idempotency_key')->all())->toBe($firstKeys)
        ->and($event->fresh()->status)->toBe(ShopifyWebhookEvent::STATUS_COMPLETED);
    Http::assertSentCount(3);
});

/** @return array<string, mixed> */
function stackFulfillmentCatalog(string $suffix, array $componentQuantities): array
{
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => "stack-{$suffix}.csv", 'mode' => 'overwrite', 'status' => 'ready',
        'created_by' => $user->id, 'is_current' => true,
    ]);
    $number = random_int(100000, 999999);
    $stack = Product::withoutEvents(fn () => Product::query()->create([
        'import_id' => $import->id, 'shopify_id' => "gid://shopify/Product/1{$number}",
        'handle' => strtolower("stack-{$suffix}-{$number}"), 'title' => "Stack {$suffix}",
        'tags' => 'bundles', 'status' => 'active', 'is_bundle' => true,
    ]));
    $stackVariant = Variant::withoutEvents(fn () => Variant::query()->create([
        'product_id' => $stack->id, 'shopify_id' => "gid://shopify/ProductVariant/2{$number}",
        'shopify_inventory_item_id' => "gid://shopify/InventoryItem/3{$number}",
        'sku' => "STACK-{$suffix}", 'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));
    $components = collect($componentQuantities)->map(function (int $quantity, int $index) use ($import, $number, $suffix): array {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'import_id' => $import->id, 'shopify_id' => "gid://shopify/Product/4{$number}{$index}",
            'handle' => strtolower("component-{$suffix}-{$index}-{$number}"),
            'title' => "Component {$suffix} {$index}", 'status' => 'active', 'is_bundle' => false,
        ]));
        $variant = Variant::withoutEvents(fn () => Variant::query()->create([
            'product_id' => $product->id, 'shopify_id' => "gid://shopify/ProductVariant/5{$number}{$index}",
            'shopify_inventory_item_id' => "gid://shopify/InventoryItem/6{$number}{$index}",
            'sku' => "COMP-{$suffix}-{$index}", 'sync_state' => Variant::SYNC_STATE_SYNCED,
        ]));

        return compact('product', 'variant', 'quantity');
    });
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::query()->create([
        'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'sku' => $stackVariant->sku,
        'title' => $stack->title, 'tags' => 'bundles', 'status' => 'active',
        'bundle_product_ids' => $components->pluck('product.id')->all(),
        'bundle_component_quantities' => $components->map(fn (array $row): array => [
            'product_id' => $row['product']->id, 'quantity' => $row['quantity'],
        ])->all(),
    ]));

    return compact('stack', 'stackVariant', 'components', 'draft');
}

/** @return array<string, mixed> */
function stackFulfillmentNormalProduct(string $suffix): array
{
    $catalog = stackFulfillmentCatalog($suffix, [1]);
    $catalog['stack']->forceFill(['is_bundle' => false, 'tags' => 'bracelets'])->save();
    $catalog['draft']->delete();

    return $catalog;
}

/** @return array<string, mixed> */
function stackFulfillmentLine(array $catalog, int $quantity): array
{
    return [
        'id' => (string) random_int(10000000, 99999999),
        'admin_graphql_api_id' => 'gid://shopify/LineItem/'.random_int(10000000, 99999999),
        'product_id' => preg_replace('/\D/', '', $catalog['stack']->shopify_id),
        'variant_id' => preg_replace('/\D/', '', $catalog['stackVariant']->shopify_id),
        'sku' => $catalog['stackVariant']->sku,
        'quantity' => $quantity,
    ];
}

function stackFulfillmentEvent(string $orderId, string $fulfillmentId, array $lines): ShopifyWebhookEvent
{
    return ShopifyWebhookEvent::query()->create([
        'webhook_id' => 'test-webhook-'.$fulfillmentId,
        'topic' => 'fulfillments/create',
        'shop_domain' => 'stack-test.myshopify.com',
        'shopify_order_id' => $orderId,
        'shopify_fulfillment_id' => $fulfillmentId,
        'shopify_location_id' => 'gid://shopify/Location/999',
        'status' => ShopifyWebhookEvent::STATUS_PENDING,
        'payload' => stackFulfillmentPayload($orderId, $fulfillmentId, $lines),
    ]);
}

function stackFulfillmentPayload(string $orderId, string $fulfillmentId, array $lines): array
{
    return ['id' => $fulfillmentId, 'order_id' => $orderId, 'location_id' => 999, 'line_items' => $lines];
}

function fakeSuccessfulStackAdjustments(): void
{
    Http::fake(['*' => Http::response(stackAdjustmentSuccessResponse())]);
}

function stackAdjustmentSuccessResponse(): array
{
    return ['data' => ['inventoryAdjustQuantities' => [
        'inventoryAdjustmentGroup' => [
            'createdAt' => now()->toIso8601String(), 'reason' => 'correction',
            'referenceDocumentUri' => 'gid://shopify-editor/StackFulfillmentAdjustment/1',
            'changes' => [['name' => 'available', 'delta' => -1]],
        ],
        'userErrors' => [],
    ]]];
}
```

Run the full existing suite because the draft form and association importer are shared by stack images, sellability, and procurement exports.

## 17. Manual Test Procedure

1. In a Shopify development store connected with the same API version/scopes, create or choose one stack product and two single-variant component products.
2. Stock both components at the exact Shopify location that will fulfill the test order. Record starting `available` quantities.
3. In `New Products`, link the two components to the stack and set per-stack quantities to `1` and `2`. Save and reopen the draft to confirm persistence.
4. Keep `SHOPIFY_STACK_FULFILLMENT_ENABLED=false`; register the HTTPS webhook and use Shopify's test delivery to confirm HMAC acceptance without deductions.
5. Enable the flag, restart workers, and place an order for one stack. Confirm this workflow makes no component adjustment at order creation.
6. Fulfill one stack from the chosen location. Confirm audit rows show deltas `-1` and `-2`, status `succeeded`, and the matching location.
7. Confirm Shopify component inventory decreased by exactly `1` and `2`; confirm the stack's own normal Shopify inventory behavior is unchanged.
8. Order three stacks and fulfill all three. Confirm deltas `-3` and `-6`.
9. Order five stacks, fulfill two, then fulfill three separately. Confirm two fulfillment IDs and deltas `(-2, -4)` then `(-3, -6)`.
10. Place an order containing two different stacks plus a normal product. Confirm only components of both stacks are adjusted.
11. Redeliver the same webhook from Shopify or replay the exact payload/headers. Confirm response `duplicate` and no additional Shopify adjustment/audit row.
12. Temporarily invalidate one component inventory item/location in the development store. Confirm partial/failed status, correct error, and no re-call for already succeeded components after retry.
13. Run `php artisan shopify:reconcile-stack-fulfillments --hours=24`; confirm it reports no missed fulfillment after normal processing.
14. Inspect the existing inventory webhook/daily refresh behavior and confirm local `variants.inventory_qty` eventually matches Shopify.

## 18. Deployment Steps

1. Deploy code with `SHOPIFY_STACK_FULFILLMENT_ENABLED=false`.
2. Update the Shopify custom app scopes to include `read_fulfillments`, `write_inventory`, and `read_orders` if reconciliation is enabled. Reinstall/re-authorize as Shopify requires, then update the stored Admin token/AWS secret.
3. Set `SHOPIFY_WEBHOOK_SECRET`, `SHOPIFY_WEBHOOK_VERIFY=true`, correct `APP_URL`, queue name, and an intentional fallback `SHOPIFY_INVENTORY_LOCATION_ID` only if the store needs it.
4. Run `php artisan migrate --force`.
5. Run `php artisan optimize:clear` followed by the production's normal `php artisan config:cache` and `php artisan route:cache` process.
6. Review every stack in the CMS and confirm `bundle_component_quantities` is explicit and correct. The migration sets existing members to `1`; quantities greater than one require human correction before enablement.
7. Run `php artisan shopify:register-fulfillment-webhook` and verify the printed URI/topic in Shopify.
8. Ensure the scheduler is running if reconciliation was added.
9. Restart queue workers (`php artisan queue:restart`) and ensure workers consume `SHOPIFY_STACK_FULFILLMENT_QUEUE`. Worker timeout must be at least 300 seconds and queue `retry_after` must exceed it.
10. Send a signed test delivery and confirm the disabled endpoint returns `200 {"status":"disabled"}` without creating an event or changing inventory.
11. Test one real development/test order end to end with the feature still disabled, then enable `SHOPIFY_STACK_FULFILLMENT_ENABLED=true`, rebuild config cache, restart workers, and perform the controlled fulfillment test.
12. Monitor failed jobs, `shopify_webhook_events`, `shopify_stack_inventory_adjustments`, Laravel logs, and Shopify inventory history through at least one normal fulfillment cycle.

## 19. Rollback Procedure

1. Set `SHOPIFY_STACK_FULFILLMENT_ENABLED=false`, rebuild config cache, and restart queue workers. The controller will acknowledge new deliveries without mutating inventory, and queued jobs also check the flag.
2. Stop workers for the dedicated queue if one is used. Do not flush unrelated queue rows.
3. Leave the webhook subscription registered during short incident response so Shopify delivery health remains stable; remove it deliberately only for a long-term rollback.
4. Preserve both audit tables and failed jobs for reconciliation. Do not run the migration `down()` during an inventory incident.
5. Compare succeeded audit deltas against Shopify inventory adjustment history. Correct bad inventory through an explicitly reviewed compensating adjustment; never change audit status or replay succeeded rows.
6. Run reconciliation to list fulfillments received while disabled/missed. After a fix, replay them only through the same persistence/idempotency workflow.
7. Database rollback removes schema only. It does **not** and cannot undo inventory already deducted in Shopify.

## 20. Implementation Checklist

- [ ] Add the additive migration and run it locally.
- [ ] Add webhook-event and stack-adjustment models.
- [ ] Add component quantity metadata/helpers to `NewProductDraft`.
- [ ] Add quantity editing to `NewProductDraftResource`.
- [ ] Extend the stack association importer while preserving existing quantities.
- [ ] Add stack fulfillment configuration and `.env.example` values, default disabled.
- [ ] Extend `ShopifyInventoryAdjustmentService` with idempotent negative deltas.
- [ ] Add `StackFulfillmentResolver` and unit tests for identity/quantity/location resolution.
- [ ] Add `StackFulfillmentProcessor` and partial-success/idempotency tests.
- [ ] Add the queued job with retry/backoff/failure handling.
- [ ] Add the verified webhook controller and CSRF-exempt route.
- [ ] Add endpoint tests for valid, invalid, and duplicate deliveries.
- [ ] Add the read-only Filament audit resource.
- [ ] Add the webhook registration command and tests.
- [ ] Add the detection-only reconciliation command and optional schedule.
- [ ] Run Pint on changed PHP files and run the focused Pest tests.
- [ ] Run the complete test suite, especially existing stack association, stack sellability, inventory webhook, image, procurement, and Shopify sync tests.
- [ ] Deploy disabled, migrate, clear/cache configuration and routes, restart workers.
- [ ] Verify scopes and register `FULFILLMENTS_CREATE` at the production HTTPS URI.
- [ ] Audit/backfill every component quantity greater than one.
- [ ] Execute the manual development-store test matrix.
- [ ] Enable the feature, restart workers, and monitor audit/failed-job records.
- [ ] Keep Phase 2 return/restock reversal disabled until its separate implementation and tests are approved.

## Codex Implementation Handoff

When instructed to implement this feature in a future task, Codex should follow this exact order:

1. Re-read this plan and re-inspect the named files for changes made since 2026-08-19.
2. Confirm the worktree and preserve unrelated user changes.
3. Add the migration and models; migrate the test database.
4. Add quantity metadata/model helpers, draft UI synchronization, and importer preservation.
5. Add configuration with the feature disabled by default.
6. Extend the existing inventory adjustment service; do not create another Shopify client.
7. Add resolver, processor, and queue job.
8. Add webhook controller and route using the existing raw-body HMAC convention.
9. Add the Filament audit resource.
10. Add registration and reconciliation commands; keep reconciliation detection-only.
11. Add the complete automated tests, then run focused tests and fix failures.
12. Run the full test suite and Pint; inspect the final diff for accidental non-feature changes.
13. Provide deployment commands and explicitly state that production must start disabled.
14. Stop. Do not change Shopify scopes, register production webhooks, run production migrations, enable the flag, replay events, or adjust real inventory without explicit deployment authorization.
