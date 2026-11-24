<?php

use App\Jobs\SyncMarkForArchiveMetafieldJob;
use App\Models\Import;
use App\Models\ProcurementCollectionConfig;
use App\Models\ProcurementIncomingStock;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\GoogleSheets\GoogleServiceAccountTokenProvider;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\ProcurementSheetDatasetBuilder;
use App\Services\GoogleSheets\ProcurementSheetSchema;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\Procurement\ProcurementActionPolicy;
use App\Services\Procurement\ProcurementArchiveFlagSyncService;
use App\Services\ProcurementIncomingStockService;
use App\Services\ProductShopifyUpdater;
use App\Services\SalePercentageCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.shopify.shop' => 'example.myshopify.com',
        'services.shopify.admin_access_token' => 'fake-shopify-token',
        'procurement.mark_for_archive_sync_enabled' => true,
        'procurement.queue_connection' => 'database',
        'procurement.queue' => 'procurement',
        'google_sheets.enabled' => true,
        'google_sheets.spreadsheet_id' => 'sheet-1',
    ]);
});

it('sets Shopify mark_for_archive true when Ignore becomes TRUE', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-TRUE');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: true);

    $result = app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product);
    expect($result['queued'])->toBeTrue();

    (new SyncMarkForArchiveMetafieldJob(
        (int) $variant->product_id,
        (int) $variant->product->fresh()->mark_for_archive_desired_version,
    ))->handle(app(ProcurementArchiveFlagSyncService::class));

    $product = $variant->product->fresh();
    expect((bool) $product->mark_for_archive)->toBeTrue()
        ->and($product->mark_for_archive_sync_status)->toBe('synced')
        ->and((bool) $product->mark_for_archive_synced_value)->toBeTrue()
        ->and($product->status)->toBe('active')
        ->and(collect($mutations)->contains(fn (array $m): bool => ($m['value'] ?? null) === 'true'))->toBeTrue();
});

it('sets Shopify mark_for_archive false when Ignore becomes FALSE', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-FALSE');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => false,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: false);

    app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product);
    (new SyncMarkForArchiveMetafieldJob(
        (int) $variant->product_id,
        (int) $variant->product->fresh()->mark_for_archive_desired_version,
    ))->handle(app(ProcurementArchiveFlagSyncService::class));

    expect((bool) $variant->product->fresh()->mark_for_archive_synced_value)->toBeFalse()
        ->and(collect($mutations)->contains(fn (array $m): bool => ($m['value'] ?? null) === 'false'))->toBeTrue();
});

it('syncs TRUE to FALSE and FALSE to TRUE transitions', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-FLIP');
    $service = app(ProcurementIncomingStockService::class);
    $sync = app(ProcurementArchiveFlagSyncService::class);
    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: true);

    $service->updateFromSheet($variant, ['ignore' => true, 'quantity_to_order' => 0], 'test', 1);
    $sync->refreshProduct($variant->product, queue: false);
    $sync->syncProduct($variant->product->fresh(), (int) $variant->product->fresh()->mark_for_archive_desired_version);
    expect((bool) $variant->product->fresh()->mark_for_archive_synced_value)->toBeTrue();

    $service->updateFromSheet($variant, ['ignore' => false, 'quantity_to_order' => 0], 'test', 2);
    $sync->refreshProduct($variant->product->fresh(), queue: false);
    $sync->syncProduct($variant->product->fresh(), (int) $variant->product->fresh()->mark_for_archive_desired_version);
    expect((bool) $variant->product->fresh()->mark_for_archive_synced_value)->toBeFalse()
        ->and(collect($mutations)->pluck('value')->all())->toBe(['true', 'false']);
});

it('avoids redundant Shopify writes when Ignore is unchanged and already synced', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-NOOP');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: true);
    $sync = app(ProcurementArchiveFlagSyncService::class);
    $sync->refreshProduct($variant->product, queue: false);
    $sync->syncProduct($variant->product->fresh(), (int) $variant->product->fresh()->mark_for_archive_desired_version);
    expect($mutations)->toHaveCount(1);

    DB::table('jobs')->delete();
    $mutations = [];
    $result = $sync->refreshProduct($variant->product->fresh());
    expect($result['queued'])->toBeFalse()
        ->and($mutations)->toHaveCount(0)
        ->and(DB::table('jobs')->where('queue', 'procurement')->count())->toBe(0);
});

it('still syncs archive flags for products ignored by the procurement action policy', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-EOL');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $action = app(ProcurementActionPolicy::class)->resolve(
        currentAction: null,
        additionalOrderRequired: 50,
        predictedRunoutDate: null,
        attentionHorizonDays: 21,
        ignore: true,
    );
    expect($action)->toBe('NO_ACTION');

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: true);
    app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product);
    (new SyncMarkForArchiveMetafieldJob((int) $variant->product_id, (int) $variant->product->fresh()->mark_for_archive_desired_version))
        ->handle(app(ProcurementArchiveFlagSyncService::class));

    expect((bool) $variant->product->fresh()->mark_for_archive_synced_value)->toBeTrue();
});

it('marks products without Shopify IDs as unmapped without calling Shopify', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-LOCAL', shopifyId: null);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    Http::fake(fn () => throw new RuntimeException('Shopify must not be called for unmapped products.'));
    $result = app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product);

    expect($result['queued'])->toBeFalse()
        ->and($result['status'])->toBe('unmapped')
        ->and((bool) $variant->product->fresh()->mark_for_archive)->toBeTrue();
});

it('preserves the last valid Ignore value when the sheet cell is blank', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-BLANK');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road',
        'is_active' => true,
        'google_sheet_tab_name' => 'livi-road',
    ]);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 5,
    ], 'seed', 1);

    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $blank = array_fill(0, count($headers), '');
    $blank[$map['sku']] = $variant->sku;
    $blank[$map['ignore']] = '';
    $blank[$map['quantity_to_order']] = '8';

    Http::fake(fn () => Http::response(['values' => [$headers, $blank]]));
    $stats = archiveFlagSheetSync()->pullHumanInputs();
    expect((bool) ProcurementIncomingStock::query()->where('variant_id', $variant->id)->value('ignore'))->toBeTrue()
        ->and(ProcurementIncomingStock::query()->where('variant_id', $variant->id)->value('quantity_to_order'))->toBe(8)
        ->and($stats['ignore_warnings'])->toBeGreaterThan(0);
});

it('preserves the last valid Ignore value when the sheet cell is invalid', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-INVALID');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road',
        'is_active' => true,
        'google_sheet_tab_name' => 'livi-road',
    ]);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 5,
    ], 'seed', 1);

    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $invalid = array_fill(0, count($headers), '');
    $invalid[$map['sku']] = $variant->sku;
    $invalid[$map['ignore']] = 'MAYBE';
    $invalid[$map['quantity_to_order']] = '9';

    Http::fake(fn () => Http::response(['values' => [$headers, $invalid]]));
    $stats = archiveFlagSheetSync()->pullHumanInputs();
    expect((bool) ProcurementIncomingStock::query()->where('variant_id', $variant->id)->value('ignore'))->toBeTrue()
        ->and(ProcurementIncomingStock::query()->where('variant_id', $variant->id)->value('quantity_to_order'))->toBe(9)
        ->and($stats['ignore_warnings'])->toBeGreaterThan(0);
});

it('does not clear archive state when a product is missing from a later procurement file', function (): void {
    [, $kept] = archiveFlagVariant('ARCH-KEEP');
    [, $other] = archiveFlagVariant('ARCH-OTHER');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road',
        'is_active' => true,
        'google_sheet_tab_name' => 'livi-road',
    ]);
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($kept, ['ignore' => true, 'quantity_to_order' => 0], 'seed', 1);
    $service->updateFromSheet($other, ['ignore' => false, 'quantity_to_order' => 0], 'seed', 2);
    app(ProcurementArchiveFlagSyncService::class)->refreshProduct($kept->product, queue: false);
    expect((bool) $kept->product->fresh()->mark_for_archive)->toBeTrue();

    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $row = array_fill(0, count($headers), '');
    $row[$map['sku']] = $other->sku;
    $row[$map['ignore']] = 'FALSE';
    $row[$map['quantity_to_order']] = '0';
    Http::fake(fn () => Http::response(['values' => [$headers, $row]]));

    archiveFlagSheetSync()->pullHumanInputs();

    expect((bool) ProcurementIncomingStock::query()->where('variant_id', $kept->id)->value('ignore'))->toBeTrue()
        ->and((bool) $kept->product->fresh()->mark_for_archive)->toBeTrue();
});

it('records failed sync attempts and allows retry without another upload', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-FAIL');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $fail = true;
    $mutations = [];
    $current = 'true';
    Http::fake(function (Request $request) use (&$fail, &$mutations, &$current) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');
        $variables = (array) ($body['variables'] ?? []);
        if ($fail && str_contains($query, 'mutation MetafieldsSet')) {
            return Http::response(['data' => ['metafieldsSet' => [
                'metafields' => [],
                'userErrors' => [['field' => ['metafields', '0'], 'message' => 'Definition missing', 'code' => 'INVALID']],
            ]]]);
        }
        if (str_contains($query, 'mutation MetafieldsSet')) {
            $metafield = $variables['metafields'][0] ?? [];
            $mutations[] = $metafield;
            $current = (string) ($metafield['value'] ?? $current);

            return Http::response(['data' => ['metafieldsSet' => [
                'metafields' => [[
                    'id' => 'gid://shopify/Metafield/1',
                    'namespace' => 'custom',
                    'key' => 'mark_for_archive',
                    'type' => 'boolean',
                    'value' => $current,
                ]],
                'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'markForArchive')) {
            return Http::response(['data' => ['product' => [
                'id' => $variables['id'] ?? 'gid://shopify/Product/1',
                'markForArchive' => ['value' => $current, 'type' => 'boolean'],
            ]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call: '.$query);
    });

    app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product, queue: false);
    $result = app(ProcurementArchiveFlagSyncService::class)->syncProduct(
        $variant->product->fresh(),
        (int) $variant->product->fresh()->mark_for_archive_desired_version,
    );

    expect($result['status'])->toBe('failed')
        ->and((bool) $variant->product->fresh()->mark_for_archive)->toBeTrue()
        ->and($variant->product->fresh()->mark_for_archive_sync_status)->toBe('failed')
        ->and($variant->product->fresh()->mark_for_archive_sync_error)->toContain('Definition missing');

    $fail = false;
    $retryResult = app(ProcurementArchiveFlagSyncService::class)->syncProduct(
        $variant->product->fresh(),
        (int) $variant->product->fresh()->mark_for_archive_desired_version,
        force: true,
    );
    expect($retryResult['status'])->toBe('synced')
        ->and($variant->product->fresh()->mark_for_archive_sync_status)->toBe('synced')
        ->and($mutations)->not->toBeEmpty();

    $variant->product->fresh()->forceFill([
        'mark_for_archive_sync_status' => 'failed',
        'mark_for_archive_sync_error' => 'transient',
    ])->save();
    $queued = app(ProcurementArchiveFlagSyncService::class)->retryPending(queue: true);
    expect($queued['queued'])->toBe(1);
});

it('ignores stale jobs when a newer procurement Ignore version exists', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-STALE');
    $service = app(ProcurementIncomingStockService::class);
    $sync = app(ProcurementArchiveFlagSyncService::class);

    $service->updateFromSheet($variant, ['ignore' => true, 'quantity_to_order' => 0], 'test', 1);
    $sync->refreshProduct($variant->product, queue: false);
    $oldVersion = (int) $variant->product->fresh()->mark_for_archive_desired_version;

    $service->updateFromSheet($variant, ['ignore' => false, 'quantity_to_order' => 0], 'test', 2);
    $sync->refreshProduct($variant->product->fresh(), queue: false);
    $newVersion = (int) $variant->product->fresh()->mark_for_archive_desired_version;
    expect($newVersion)->toBeGreaterThan($oldVersion);

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: false);
    $stale = $sync->syncProduct($variant->product->fresh(), $oldVersion);
    expect($stale['skipped'])->toBeTrue()
        ->and($mutations)->toHaveCount(0);

    $fresh = $sync->syncProduct($variant->product->fresh(), $newVersion);
    expect($fresh['status'])->toBe('synced')
        ->and($fresh['value'])->toBeFalse()
        ->and($mutations)->toHaveCount(1);
});

it('applies OR semantics for conflicting Ignore values on the same product', function (): void {
    [, $first] = archiveFlagVariant('ARCH-MULTI-A');
    $second = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $first->product_id,
        'shopify_id' => 'gid://shopify/ProductVariant/ARCH-MULTI-B',
        'sku' => 'ARCH-MULTI-B',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => true,
        'current_inventory_quantity' => 0,
        'price' => 100,
    ]));
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($first, ['ignore' => false, 'quantity_to_order' => 0], 'test', 1);
    $service->updateFromSheet($second, ['ignore' => true, 'quantity_to_order' => 0], 'test', 2);

    $desired = app(ProcurementArchiveFlagSyncService::class)->desiredFlagForProduct($first->product->fresh());
    $conflict = app(ProcurementArchiveFlagSyncService::class)->conflictingIgnoreValues($first->product->fresh());

    expect($desired)->toBeTrue()
        ->and($conflict)->not->toBeNull();
});

it('queues archive sync from procurement sheet pulls for Ignore TRUE rows', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-PULL');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road',
        'is_active' => true,
        'google_sheet_tab_name' => 'livi-road',
    ]);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $row = array_fill(0, count($headers), '');
    $row[$map['sku']] = $variant->sku;
    $row[$map['ignore']] = 'TRUE';
    $row[$map['quantity_to_order']] = '0';
    Http::fake(fn () => Http::response(['values' => [$headers, $row]]));

    $stats = archiveFlagSheetSync()->pullHumanInputs();

    expect($stats['archive_flag_queued'])->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'procurement')->count())->toBe(1)
        ->and((bool) $variant->product->fresh()->mark_for_archive)->toBeTrue()
        ->and($variant->product->fresh()->mark_for_archive_sync_status)->toBe('pending');
});

it('supports reconciliation dry-run and live execution with mocked Shopify', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-RECON');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $dry = app(ProcurementArchiveFlagSyncService::class)->reconcile(dryRun: true);
    expect($dry['examined'])->toBe(1)
        ->and($dry['would_update'])->toBe(1)
        ->and($dry['updated'])->toBe(0)
        ->and($variant->product->fresh()->mark_for_archive_sync_status)->toBe('pending');

    $mutations = [];
    fakeMarkForArchiveShopify($mutations, desired: true);
    $live = app(ProcurementArchiveFlagSyncService::class)->reconcile(dryRun: false, force: true);
    expect($live['updated'])->toBe(1)
        ->and($variant->product->fresh()->mark_for_archive_sync_status)->toBe('synced')
        ->and($mutations)->not->toBeEmpty();
});

it('does not mutate product status inventory or publication during archive metafield sync', function (): void {
    [, $variant] = archiveFlagVariant('ARCH-SAFE');
    $variant->forceFill([
        'current_inventory_quantity' => 12,
        'current_available_quantity' => 12,
        'current_on_hand_quantity' => 12,
    ])->save();
    $variant->product->forceFill([
        'status' => 'active',
        'published' => 'true',
    ])->save();
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 0,
    ], 'test', 1);

    $queries = [];
    Http::fake(function (Request $request) use (&$queries) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');
        $queries[] = $query;
        if (str_contains($query, 'mutation MetafieldsSet')) {
            return Http::response(['data' => ['metafieldsSet' => [
                'metafields' => [[
                    'id' => 'gid://shopify/Metafield/1',
                    'namespace' => 'custom',
                    'key' => 'mark_for_archive',
                    'type' => 'boolean',
                    'value' => 'true',
                ]],
                'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'ReadMarkForArchive')) {
            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/ARCH-SAFE',
                'markForArchive' => ['value' => 'true', 'type' => 'boolean'],
            ]]]);
        }

        throw new RuntimeException('Unexpected Shopify call: '.$query);
    });

    app(ProcurementArchiveFlagSyncService::class)->refreshProduct($variant->product, queue: false);
    app(ProcurementArchiveFlagSyncService::class)->syncProduct(
        $variant->product->fresh(),
        (int) $variant->product->fresh()->mark_for_archive_desired_version,
    );

    $product = $variant->product->fresh();
    $freshVariant = $variant->fresh();
    expect($product->status)->toBe('active')
        ->and($product->published)->toBe('true')
        ->and((int) $freshVariant->current_inventory_quantity)->toBe(12)
        ->and(collect($queries)->every(fn (string $q): bool => str_contains($q, 'metafieldsSet') || str_contains($q, 'ReadMarkForArchive')))->toBeTrue()
        ->and(collect($queries)->contains(fn (string $q): bool => str_contains(strtolower($q), 'productupdate') || str_contains(strtolower($q), 'inventory')))->toBeFalse();
});

/** @return array{0:Import,1:Variant} */
function archiveFlagVariant(string $sku, ?string $shopifyId = 'auto'): array
{
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'archive-flag.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $productShopifyId = $shopifyId === 'auto' ? 'gid://shopify/Product/'.$sku : $shopifyId;
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => $productShopifyId,
        'handle' => strtolower($sku),
        'title' => 'Product '.$sku,
        'vendor' => 'Leigh Avenue',
        'type' => 'Jewellery',
        'status' => 'active',
        'published' => 'true',
        'tags' => 'livi-road',
        'approval_version' => 1,
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/'.$sku,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => true,
        'current_inventory_quantity' => 0,
        'price' => 100,
    ]));

    return [$import, $variant];
}

function fakeMarkForArchiveShopify(array &$mutations, bool $desired): void
{
    $current = $desired ? 'true' : 'false';
    Http::fake(function (Request $request) use (&$mutations, &$current) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');
        $variables = (array) ($body['variables'] ?? []);
        if (str_contains($query, 'mutation MetafieldsSet')) {
            $metafield = $variables['metafields'][0] ?? [];
            $mutations[] = $metafield;
            $current = (string) ($metafield['value'] ?? $current);
            expect($metafield['namespace'] ?? null)->toBe('custom')
                ->and($metafield['key'] ?? null)->toBe('mark_for_archive')
                ->and($metafield['type'] ?? null)->toBe('boolean')
                ->and($metafield['ownerId'] ?? null)->toStartWith('gid://shopify/Product/');

            return Http::response(['data' => ['metafieldsSet' => [
                'metafields' => [[
                    'id' => 'gid://shopify/Metafield/1',
                    'namespace' => 'custom',
                    'key' => 'mark_for_archive',
                    'type' => 'boolean',
                    'value' => $current,
                ]],
                'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'ReadMarkForArchive') || str_contains($query, 'markForArchive')) {
            return Http::response(['data' => ['product' => [
                'id' => $variables['id'] ?? 'gid://shopify/Product/1',
                'markForArchive' => ['value' => $current, 'type' => 'boolean'],
            ]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call: '.$query);
    });
}

function archiveFlagSheetSync(): ProcurementSheetSyncService
{
    $tokens = new class extends GoogleServiceAccountTokenProvider
    {
        public function token(): string
        {
            return 'fake-google-token';
        }
    };
    $resolver = new OperationalProcurementCollectionResolver;

    return new ProcurementSheetSyncService(
        new GoogleSheetsClient($tokens),
        new ProcurementSheetSchema,
        app(ProcurementIncomingStockService::class),
        $resolver,
        new ProcurementSheetDatasetBuilder(
            $resolver,
            app(SalePercentageCalculator::class),
            app(ProcurementActionPolicy::class),
            app(\App\Services\SiblingCollectionResolver::class),
        ),
        app(ProcurementArchiveFlagSyncService::class),
    );
}
