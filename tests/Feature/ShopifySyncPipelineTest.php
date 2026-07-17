<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifySyncRun;
use App\Models\SkuDailyDemand;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shopify\ShopifyBulkFileDownloader;
use App\Services\Shopify\ShopifyDemandCalculator;
use App\Services\Shopify\ShopifyInventoryUpsertService;
use App\Services\Shopify\ShopifyOrderJsonlProcessor;
use App\Services\Shopify\ShopifyOrderQueryBuilder;
use App\Services\Shopify\ShopifySyncWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates deterministic Johannesburg order windows', function (): void {
    $window = app(ShopifySyncWindowService::class)->forBusinessDate('2026-07-14');

    expect($window['business_date'])->toBe('2026-07-14')
        ->and($window['reporting_start']->format('Y-m-d H:i:s P'))->toBe('2026-07-14 00:00:00 +02:00')
        ->and($window['reporting_end']->format('Y-m-d H:i:s P'))->toBe('2026-07-15 00:00:00 +02:00')
        ->and($window['window_start']->format('Y-m-d H:i:s P'))->toBe('2026-07-12 00:00:00 +02:00')
        ->and($window['window_end']->format('Y-m-d H:i:s P'))->toBe('2026-07-15 00:00:00 +02:00');
});

it('generates full and filtered order bulk queries without invalid empty parentheses', function (): void {
    $builder = app(ShopifyOrderQueryBuilder::class);
    $window = app(ShopifySyncWindowService::class)->forBusinessDate('2026-07-14');

    expect($builder->full())
        ->toContain('orders {')
        ->not->toContain('orders()');

    expect($builder->updatedBetween($window['window_start'], $window['window_end']))
        ->toContain('orders(query:')
        ->toContain('updated_at:>=2026-07-12T00:00:00+02:00')
        ->toContain('updated_at:<2026-07-15T00:00:00+02:00');
});

it('fails early when the Shopify sync archive disk has no S3 bucket', function (): void {
    config([
        'shopify_sync.s3.disk' => 'empty_sync_s3',
        'filesystems.disks.empty_sync_s3' => [
            'driver' => 's3',
            'region' => 'us-east-1',
            'bucket' => '',
            'throw' => false,
        ],
    ]);

    $run = ShopifySyncRun::query()->create([
        'dataset' => ShopifySyncRun::DATASET_ORDERS,
        'sync_type' => ShopifySyncRun::SYNC_TYPE_FULL,
        'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
        'status' => ShopifySyncRun::STATUS_PROCESSING,
        'raw_s3_key' => 'raw/orders/full/run_id=1/orders.jsonl.gz',
    ]);

    app(ShopifyBulkFileDownloader::class)->archiveToLocalTemp($run);
})->throws(RuntimeException::class, "Shopify sync filesystem disk 'empty_sync_s3' is missing an S3 bucket.");

it('streams order JSONL idempotently and calculates demand from current line items once', function (): void {
    $user = User::factory()->create();
    createShopifySyncLocalVariant($user, 'LRB0001');
    $run = createOrdersSyncRun('2026-07-14');
    $path = writeShopifySyncGz([
        [
            'id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'createdAt' => '2026-07-14T08:30:00+02:00',
            'updatedAt' => '2026-07-14T09:00:00+02:00',
            'processedAt' => '2026-07-14T08:31:00+02:00',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'currencyCode' => 'ZAR',
            'totalPriceSet' => ['shopMoney' => ['amount' => '200.00', 'currencyCode' => 'ZAR']],
            'subtotalPriceSet' => ['shopMoney' => ['amount' => '200.00', 'currencyCode' => 'ZAR']],
            'totalDiscountsSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'ZAR']],
            'test' => false,
            'lineItems' => [
                'edges' => [[
                    'node' => [
                        'id' => 'gid://shopify/LineItem/5001',
                        'title' => 'Bracelet',
                        'quantity' => 2,
                        'sku' => 'LRB0001',
                        'originalUnitPriceSet' => ['shopMoney' => ['amount' => '100.00', 'currencyCode' => 'ZAR']],
                        'discountedTotalSet' => ['shopMoney' => ['amount' => '180.00', 'currencyCode' => 'ZAR']],
                        'totalDiscountSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'ZAR']],
                        'variant' => [
                            'id' => 'gid://shopify/ProductVariant/5001',
                            'sku' => 'LRB0001',
                            'price' => '100.00',
                            'inventoryQuantity' => 8,
                        ],
                        'product' => [
                            'id' => 'gid://shopify/Product/9001',
                            'title' => 'Bracelet',
                            'handle' => 'bracelet',
                            'status' => 'ACTIVE',
                        ],
                    ],
                ]],
            ],
            'refunds' => [[
                'id' => 'gid://shopify/Refund/7001',
                'createdAt' => '2026-07-14T10:00:00+02:00',
                'totalRefundedSet' => ['shopMoney' => ['amount' => '0.00', 'currencyCode' => 'ZAR']],
            ]],
        ],
    ]);

    app(ShopifyOrderJsonlProcessor::class)->process($path, $run);
    app(ShopifyOrderJsonlProcessor::class)->process($path, $run->fresh());
    app(ShopifyDemandCalculator::class)->recalculateForRun($run->fresh());

    expect(ShopifyOrder::query()->count())->toBe(1)
        ->and(ShopifyOrderItem::query()->count())->toBe(1)
        ->and(SkuDailyDemand::query()->count())->toBe(1);

    $demand = SkuDailyDemand::query()->firstOrFail();

    expect($demand->sku)->toBe('LRB0001')
        ->and($demand->demand_date->toDateString())->toBe('2026-07-14')
        ->and($demand->gross_units)->toBe(2)
        ->and($demand->net_units)->toBe(2)
        ->and((string) $demand->gross_revenue)->toBe('200.00')
        ->and((string) $demand->discount_amount)->toBe('20.00')
        ->and((string) $demand->net_revenue)->toBe('180.00');
});

it('updates current variant inventory only from newer snapshots', function (): void {
    $user = User::factory()->create();
    $variant = createShopifySyncLocalVariant($user, 'INV001', [
        'shopify_id' => 'gid://shopify/ProductVariant/91001',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/81001',
    ]);

    $newRun = createInventorySyncRun('2026-07-14', '2026-07-14 09:00:00');
    app(ShopifyInventoryUpsertService::class)->upsertSnapshot([
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/81001',
        'shopify_inventory_level_id' => 'gid://shopify/InventoryLevel/1',
        'shopify_variant_id' => 'gid://shopify/ProductVariant/91001',
        'shopify_location_id' => 'gid://shopify/Location/1',
        'sku' => 'INV001',
        'tracked' => true,
        'available' => 20,
        'on_hand' => 22,
    ], $newRun);
    app(ShopifyInventoryUpsertService::class)->updateCurrentStateForRun($newRun);

    $oldRun = createInventorySyncRun('2026-07-14', '2026-07-13 09:00:00');
    app(ShopifyInventoryUpsertService::class)->upsertSnapshot([
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/81001',
        'shopify_inventory_level_id' => 'gid://shopify/InventoryLevel/2',
        'shopify_variant_id' => 'gid://shopify/ProductVariant/91001',
        'shopify_location_id' => 'gid://shopify/Location/1',
        'sku' => 'INV001',
        'tracked' => true,
        'available' => 25,
        'on_hand' => 25,
    ], $oldRun);
    app(ShopifyInventoryUpsertService::class)->updateCurrentStateForRun($oldRun);

    $variant->refresh();

    expect(ShopifyInventorySnapshot::query()->count())->toBe(2)
        ->and($variant->inventory_qty)->toBe(20)
        ->and($variant->current_available_quantity)->toBe(20)
        ->and($variant->current_on_hand_quantity)->toBe(22)
        ->and($variant->inventory_last_synced_at->format('Y-m-d H:i:s'))->toBe('2026-07-14 09:00:00');
});

function createOrdersSyncRun(string $businessDate): ShopifySyncRun
{
    $window = app(ShopifySyncWindowService::class)->forBusinessDate($businessDate);

    return ShopifySyncRun::query()->create([
        'dataset' => ShopifySyncRun::DATASET_ORDERS,
        'sync_type' => ShopifySyncRun::SYNC_TYPE_DAILY,
        'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
        'business_date' => $window['business_date'],
        'business_timezone' => $window['timezone'],
        'window_start' => $window['window_start'],
        'window_end' => $window['window_end'],
        'lookback_days' => $window['lookback_days'],
        'status' => ShopifySyncRun::STATUS_PROCESSING,
    ]);
}

function createInventorySyncRun(string $businessDate, string $completedAt): ShopifySyncRun
{
    return ShopifySyncRun::query()->create([
        'dataset' => ShopifySyncRun::DATASET_INVENTORY,
        'sync_type' => ShopifySyncRun::SYNC_TYPE_SNAPSHOT,
        'run_mode' => ShopifySyncRun::RUN_MODE_MANUAL,
        'business_date' => $businessDate,
        'business_timezone' => 'Africa/Johannesburg',
        'status' => ShopifySyncRun::STATUS_PROCESSING,
        'started_at' => $completedAt,
        'shopify_completed_at' => $completedAt,
    ]);
}

function createShopifySyncLocalVariant(User $user, string $sku, array $variantOverrides = []): Variant
{
    $import = Import::query()->create([
        'filename' => 'shopify-sync-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/' . abs(crc32($sku)),
        'handle' => strtolower($sku),
        'title' => $sku,
        'status' => 'active',
        'approval_version' => 1,
    ]));

    return Variant::withoutEvents(fn (): Variant => Variant::query()->create(array_merge([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/' . abs(crc32($sku)),
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/' . abs(crc32('inventory-' . $sku)),
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => $sku,
        'price' => '100.00',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
    ], $variantOverrides)));
}

/**
 * @param array<int, array<string, mixed>> $records
 */
function writeShopifySyncGz(array $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'shopify-sync-jsonl-') . '.gz';
    $handle = gzopen($path, 'wb9');
    if ($handle === false) {
        throw new RuntimeException('Unable to create test gzip file.');
    }

    foreach ($records as $record) {
        gzwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
    }

    gzclose($handle);

    return $path;
}
