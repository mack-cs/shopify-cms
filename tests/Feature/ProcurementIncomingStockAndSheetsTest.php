<?php

use App\Models\ChangeLog;
use App\Models\Import;
use App\Models\ProcurementCollectionConfig;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementPredictionRun;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Jobs\ApplyProcurementPriceUpdatesJob;
use App\Models\NewProductDraft;
use App\Services\GoogleSheets\GoogleServiceAccountTokenProvider;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\ProcurementSheetDatasetBuilder;
use App\Services\GoogleSheets\ProcurementSheetSchema;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\HeaderStore;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\Procurement\ProcurementActionPolicy;
use App\Services\Procurement\ProcurementPriceUpdateService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\ProcurementIncomingStockService;
use App\Services\ProcurementPredictionIngestService;
use App\Services\SalePercentageCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes working quantities without treating them as incoming stock', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);

    $stock = $service->updateFromSheet($variant, [
        'quantity_to_order' => '60',
    ], 'livi-road', 7);

    expect($stock->quantity_to_order)->toBe(60)
        ->and($stock->total_quantity_on_order)->toBe(0)
        ->and($stock->changes()->count())->toBe(1);

    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    $input = $run->incomingStockInputs()->firstOrFail();
    expect($input->sku)->toBe('LRB0004')
        ->and($input->total_quantity_on_order)->toBe(0)
        ->and($run->fresh()?->incoming_stock_input_hash)->not->toBeNull();
});

it('attributes procurement field changes to the CMS user and source', function (): void {
    [, $variant] = procurementSheetVariant('AUDIT-001', 'livi-road');
    $user = User::factory()->create();

    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true,
        'quantity_to_order' => 12,
    ], 'cms:supplier-orders', null, $user->id);

    $logs = ChangeLog::query()
        ->where('model_type', ProcurementIncomingStock::class)
        ->where('product_id', $variant->product_id)
        ->get();

    expect($logs)->not->toBeEmpty()
        ->and($logs->pluck('field'))->toContain('ignore', 'quantity_to_order')
        ->and($logs->pluck('changed_by')->unique()->all())->toBe([$user->id])
        ->and($logs->pluck('source')->unique()->all())->toBe(['cms:supplier-orders']);
});

it('does not count a planned quantity as a WIP supplier order', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $stock = app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => false,
        'quantity_to_order' => 100,
    ], 'livi-road');

    expect($stock->quantity_to_order)->toBe(100)
        ->and($stock->total_quantity_on_order)->toBe(0)
        ->and($stock->number_of_wip_orders)->toBe(0)
        ->and(collect(app(ProcurementSheetDatasetBuilder::class)->records())
            ->firstWhere('sku', 'LRB0004')['total_quantity_on_order'])->toBe(0);
});

it('snapshots ignore and CMS-owned order totals without phase details', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => 'TRUE',
        'quantity_to_order' => 100,
    ], 'livi-road');
    $run = procurementSheetRun();
    app(ProcurementIncomingStockService::class)->snapshotForRun($run);
    $input = $run->incomingStockInputs()->where('variant_id', $variant->id)->firstOrFail();

    expect($input->ignore)->toBeTrue()
        ->and($input->total_quantity_on_order)->toBe(0)
        ->and($input->procurement_actioned)->toBeFalse();
});

it('normalizes day month year ETA values returned by formatted Google Sheets', function (): void {
    $service = app(ProcurementIncomingStockService::class);

    expect($service->normalizeEtaDate('28/08/2026', 'eta_date_phase_1'))->toBe('2026-08-28')
        ->and($service->normalizeEtaDate('04/09/2026', 'eta_date_phase_2'))->toBe('2026-09-04');
});

it('serves the immutable incoming-stock snapshot through the protected analytics feed', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($variant, [
        'quantity_to_order' => 60,
    ], 'livi-road');
    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    config(['shopify_sync.analytics_export_token' => 'incoming-token']);

    $response = $this->withToken('incoming-token')->get(
        '/api/analytics/incoming-stock.csv?run_uuid='.$run->run_uuid
    );

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('total_quantity_on_order')
        ->toContain('LRB0004')
        ->not->toContain('quantity_on_order_phase_1');
});

it('matches headers independent of case whitespace and column position', function (): void {
    $schema = new ProcurementSheetSchema;
    $headers = array_reverse(array_values(ProcurementSheetSchema::FIELDS));
    $headers[0] = '  LAST   UPDATED ';
    $map = $schema->map($headers);

    expect($map['last_updated'])->toBe(0)
        ->and($headers[$map['sku']])->toBe('SKU');
});

it('repairs duplicate trailing CMS columns but rejects duplicate human inputs', function (): void {
    $schema = new ProcurementSheetSchema;
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $values = [$headers, array_fill(0, count($headers), '')];
    $values[0][] = 'Recommended Order Before Incoming Stock';
    $values[1][] = 999;

    $upgraded = $schema->upgradeLayout($values);
    expect($upgraded[0])->toBe($headers)
        ->and($upgraded[1])->toHaveCount(count($headers));

    $values[0][] = 'Quantity To Order';
    expect(fn () => $schema->upgradeLayout($values))
        ->toThrow(RuntimeException::class, 'Duplicate Google Sheet header [Quantity To Order]');
});

it('keeps the required procurement column groups in the exact report order', function (): void {
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    expect($headers)->toBe([
        'SKU', 'Product', 'Vendor', 'Product Type', 'Sibling Shapes', 'Bead Color Finish',
        'Current Cost', 'New Cost', 'Current Price', 'New Price', 'Currently on Sale', 'Sale Percentage',
        'Available', 'On Hand', 'cms_movement_classification', 'Ignore',
        'Predicted Weekly Demand', 'Estimated Days of Stock Remaining', 'Predicted Runout Date',
        'Next Order ID', 'Replenishment Date', 'Stock Gap Status',
        'Second Order ID', 'Second ETA', 'Predicted Runout Date After Replenishment',
        'Projected Stock Before Second ETA', 'Between Orders Stock Gap Status',
        'Total Quantity On Order', 'Number of WIP Orders', 'Projected Inventory Position',
        'Lead Time Days', 'Stock Required for Lead Time',
        'Recommended Order Before Incoming Stock', 'Additional Order Required',
        'Quantity To Order', 'Action Required', 'Action Reason', 'Last Updated',
    ])->not->toContain(
        'Committed', 'Reserved', 'Next ETA',
        'Stockout Before Incoming Arrival', 'Incoming Stock Covers Requirement',
    );
});

it('clears only the columns removed from the previous Google Sheet layout', function (): void {
    config(['google_sheets.spreadsheet_id' => 'sheet-1']);
    Http::fake(fn () => Http::response(['ok' => true]));
    $tokens = new class extends GoogleServiceAccountTokenProvider
    {
        public function token(): string
        {
            return 'fake-google-token';
        }
    };

    (new GoogleSheetsClient($tokens))->replaceAll(
        'pata-pata', [array_fill(0, count(ProcurementSheetSchema::FIELDS), 'value')], 1, count(ProcurementSheetSchema::FIELDS) + 2
    );

    $requests = collect(Http::recorded())->pluck(0);
    expect($requests)->toHaveCount(2)
        ->and($requests->first()->url())->toContain('/values:batchUpdate')
        ->and(urldecode($requests->last()->url()))->toContain("'pata-pata'!AM1:AN1");
});

it('upgrades legacy Current Inventory into Available without losing values', function (): void {
    $schema = new ProcurementSheetSchema;
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $availableIndex = array_search('Available', $headers, true);
    $headers[$availableIndex] = 'Current Inventory';
    $headers = array_values(array_filter($headers, fn (string $header): bool => $header !== 'On Hand'));
    $row = array_fill(0, count($headers), '');
    $row[array_search('Current Inventory', $headers, true)] = 17;

    $upgraded = $schema->upgradeLayout([$headers, $row]);
    $map = $schema->map($upgraded[0]);

    expect($upgraded[1][$map['current_inventory']])->toBe(17)
        ->and($upgraded[1][$map['current_on_hand_inventory']])->toBe('');
});

it('pulls Ignore and Quantity To Order but does not import CMS order totals from brand Sheets', function (): void {
    [$import, $first] = procurementSheetVariant('LRB0004', 'livi-road');
    [, $second] = procurementSheetVariant('LRB0005', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $schema = new ProcurementSheetSchema;
    $headers = array_reverse(array_values(ProcurementSheetSchema::FIELDS));
    $map = $schema->map($headers);
    $row = function (string $sku, int $quantity) use ($headers, $map): array {
        $values = array_fill(0, count($headers), '');
        $values[$map['sku']] = $sku;
        $values[$map['quantity_to_order']] = $quantity;
        $values[$map['ignore']] = 'TRUE';

        return $values;
    };
    Http::fake(fn () => Http::response([
        'values' => [$headers, $row('LRB0005', 30), $row('LRB0004', 60)],
    ]));

    procurementTestSheetSync()->pullHumanInputs();

    expect(ProcurementIncomingStock::query()->where('variant_id', $first->id)->value('total_quantity_on_order'))->toBe(0)
        ->and(ProcurementIncomingStock::query()->where('variant_id', $first->id)->value('quantity_to_order'))->toBe(60)
        ->and(ProcurementIncomingStock::query()->where('variant_id', $first->id)->value('ignore'))->toBeTrue()
        ->and(ProcurementIncomingStock::query()->where('variant_id', $second->id)->value('total_quantity_on_order'))->toBe(0)
        ->and(ProcurementIncomingStock::query()->where('variant_id', $second->id)->value('quantity_to_order'))->toBe(30)
        ->and(ProcurementIncomingStock::query()->where('variant_id', $second->id)->value('ignore'))->toBeTrue();
});

it('reads populated New Price values and queues one batch without changing the sheet immediately', function (): void {
    Queue::fake();
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $row = array_fill(0, count($headers), '');
    $row[$map['sku']] = $variant->sku;
    $row[$map['current_price']] = '100.00';
    $row[$map['new_price']] = '125.50';
    Http::fake(fn () => Http::response(['values' => [$headers, $row]]));

    $result = (new ProcurementPriceUpdateService(procurementTestSheetSync()))->queueFromSheet(7);

    expect($result)->toBe([
        'queued' => 1,
        'skipped_unchanged' => 0,
        'skipped_unready' => 0,
        'unready_skus' => [],
    ]);
    Queue::assertPushed(ApplyProcurementPriceUpdatesJob::class, function (ApplyProcurementPriceUpdatesJob $job) use ($variant): bool {
        return $job->userId === 7
            && $job->updates === [['sku' => $variant->sku, 'variant_id' => $variant->id, 'new_price' => '125.50']];
    });
});

it('reads populated New Cost values and queues them with price updates', function (): void {
    Queue::fake();
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ShopifyRow::query()->create([
        'import_id' => $variant->product->import_id,
        'row_index' => 1,
        'handle' => $variant->product->handle,
        'row_type' => 'product_primary',
        'data' => [HeaderStore::COST_PER_ITEM => '63.00'],
    ]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $row = array_fill(0, count($headers), '');
    $row[$map['sku']] = $variant->sku;
    $row[$map['new_cost']] = '75.25';
    $row[$map['new_price']] = '125.50';
    Http::fake(fn () => Http::response(['values' => [$headers, $row]]));

    $result = (new ProcurementPriceUpdateService(procurementTestSheetSync()))->queueFromSheet(7);

    expect($result['queued'])->toBe(1)
        ->and($result['skipped_unready'])->toBe(0);
    Queue::assertPushed(ApplyProcurementPriceUpdatesJob::class, function (ApplyProcurementPriceUpdatesJob $job) use ($variant): bool {
        return $job->updates === [[
            'sku' => $variant->sku,
            'variant_id' => $variant->id,
            'new_price' => '125.50',
            'new_cost' => '75.25',
        ]];
    });
});

it('queues active catalog price updates and skips unmatched draft SKUs', function (): void {
    Queue::fake();
    [, $active] = procurementSheetVariant('LRB0004', 'livi-road');
    [, $draft] = procurementSheetVariant('LTN0227/62', 'livi-road');
    $draft->product->forceFill(['status' => 'draft'])->save();
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $activeRow = array_fill(0, count($headers), '');
    $activeRow[$map['sku']] = $active->sku;
    $activeRow[$map['new_price']] = '125.50';
    $draftRow = array_fill(0, count($headers), '');
    $draftRow[$map['sku']] = $draft->sku;
    $draftRow[$map['new_price']] = '80.00';
    Http::fake(fn () => Http::response(['values' => [$headers, $activeRow, $draftRow]]));

    $result = (new ProcurementPriceUpdateService(procurementTestSheetSync()))->queueFromSheet(7);

    expect($result['queued'])->toBe(1)
        ->and($result['skipped_unready'])->toBe(1)
        ->and($result['unready_skus'])->toBe(['LTN0227/62']);
    Queue::assertPushed(ApplyProcurementPriceUpdatesJob::class, function (ApplyProcurementPriceUpdatesJob $job) use ($active): bool {
        return $job->updates === [['sku' => $active->sku, 'variant_id' => $active->id, 'new_price' => '125.50']];
    });
});

it('queues valid Shopify price updates and skips SKUs with no Shopify variant ID', function (): void {
    Queue::fake();
    [, $active] = procurementSheetVariant('LRB0004', 'livi-road');
    [, $localOnly] = procurementSheetVariant('LRBLOCAL1', 'livi-road');
    $localOnly->forceFill(['shopify_id' => ''])->save();
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $map = (new ProcurementSheetSchema)->map($headers);
    $activeRow = array_fill(0, count($headers), '');
    $activeRow[$map['sku']] = $active->sku;
    $activeRow[$map['new_price']] = '125.50';
    $localRow = array_fill(0, count($headers), '');
    $localRow[$map['sku']] = $localOnly->sku;
    $localRow[$map['new_price']] = '80.00';
    Http::fake(fn () => Http::response(['values' => [$headers, $activeRow, $localRow]]));

    $result = (new ProcurementPriceUpdateService(procurementTestSheetSync()))->queueFromSheet(7);

    expect($result['queued'])->toBe(1)
        ->and($result['skipped_unready'])->toBe(1)
        ->and($result['unready_skus'])->toBe(['LRBLOCAL1']);
    Queue::assertPushed(ApplyProcurementPriceUpdatesJob::class, function (ApplyProcurementPriceUpdatesJob $job) use ($active): bool {
        return $job->updates === [['sku' => $active->sku, 'variant_id' => $active->id, 'new_price' => '125.50']];
    });
});

it('keeps New Price human owned during normal row construction', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', $variant->sku);

    expect($record['current_price'])->toBe('100.00')
        ->and($record['current_cost'])->toBeNull()
        ->and($record['new_cost'])->toBeNull()
        ->and($record['new_price'])->toBeNull();
});

it('excludes local test products from procurement sheets unless explicitly enabled', function (): void {
    config(['procurement.include_test_products' => false]);
    [, $variant] = procurementSheetVariant('TEST-LOCAL-001', 'livi-road');

    expect(collect(app(ProcurementSheetDatasetBuilder::class)->records())->pluck('sku'))
        ->not->toContain($variant->sku);

    config(['procurement.include_test_products' => true]);

    expect(collect(app(ProcurementSheetDatasetBuilder::class)->records())->pluck('sku'))
        ->toContain($variant->sku);
});

it('records confirmed procurement price updates only after Shopify success', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $user = User::factory()->create();
    $draft = NewProductDraft::query()->create([
        'handle' => $variant->product->handle,
        'shopify_id' => $variant->product->shopify_id,
        'sku' => $variant->sku,
        'title' => $variant->product->title,
        'variant_price' => '100.00',
    ]);
    config([
        'services.shopify.shop' => 'example.myshopify.com',
        'services.shopify.admin_access_token' => 'fake-shopify-token',
    ]);
    Http::fake(fn () => Http::response([
        'data' => ['productVariantsBulkUpdate' => ['userErrors' => []]],
    ]));
    $updater = new \App\Services\ProductShopifyUpdater(
        app(\App\Services\ShopifyApiClient::class),
        app(\App\Services\ProductHandleService::class),
        app(\App\Services\ProductPartialApprovalService::class),
        app(\App\Services\SaleTagService::class),
        app(\App\Services\ComplementaryProductAuditService::class),
    );

    (new ApplyProcurementPriceUpdatesJob([
        ['sku' => $variant->sku, 'variant_id' => $variant->id, 'new_price' => '125.50'],
    ], $user->id))->handle($updater, procurementTestSheetSync());

    expect($variant->fresh()->price)->toBe('125.50')
        ->and($draft->fresh()->variant_price)->toBe('125.50')
        ->and(ChangeLog::query()->where('source', 'PROCUREMENT_PRICE_COST_UPDATE')->where('model_id', $variant->id)->exists())->toBeTrue();
});

it('records confirmed procurement cost updates across Shopify rows and drafts', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $user = User::factory()->create();
    ShopifyRow::query()->create([
        'import_id' => $variant->product->import_id,
        'row_index' => 1,
        'handle' => $variant->product->handle,
        'row_type' => 'product_primary',
        'data' => [HeaderStore::COST_PER_ITEM => '63.00'],
    ]);
    ShopifyRow::query()->create([
        'import_id' => $variant->product->import_id,
        'row_index' => 2,
        'handle' => $variant->product->handle,
        'row_type' => 'variant',
        'data' => [
            HeaderStore::VARIANT_SKU => $variant->sku,
            HeaderStore::COST_PER_ITEM => '63.00',
        ],
    ]);
    $draft = NewProductDraft::query()->create([
        'handle' => $variant->product->handle,
        'shopify_id' => $variant->product->shopify_id,
        'sku' => $variant->sku,
        'title' => $variant->product->title,
        'material_cost' => '63.00',
    ]);
    config([
        'services.shopify.shop' => 'example.myshopify.com',
        'services.shopify.admin_access_token' => 'fake-shopify-token',
    ]);
    Http::fake(fn () => Http::response([
        'data' => ['productVariantsBulkUpdate' => ['userErrors' => []]],
    ]));
    $updater = new \App\Services\ProductShopifyUpdater(
        app(\App\Services\ShopifyApiClient::class),
        app(\App\Services\ProductHandleService::class),
        app(\App\Services\ProductPartialApprovalService::class),
        app(\App\Services\SaleTagService::class),
        app(\App\Services\ComplementaryProductAuditService::class),
    );

    (new ApplyProcurementPriceUpdatesJob([
        ['sku' => $variant->sku, 'variant_id' => $variant->id, 'new_cost' => '75.25'],
    ], $user->id))->handle($updater, procurementTestSheetSync());

    expect(ShopifyRow::query()->where('row_type', 'product_primary')->firstOrFail()->get(HeaderStore::COST_PER_ITEM))->toBe('75.25')
        ->and(ShopifyRow::query()->where('row_type', 'variant')->firstOrFail()->get(HeaderStore::COST_PER_ITEM))->toBe('75.25')
        ->and($draft->fresh()->material_cost)->toBe('75.25')
        ->and(ChangeLog::query()->where('source', 'PROCUREMENT_PRICE_COST_UPDATE')->where('field', 'cost_per_item')->exists())->toBeTrue();
});

it('does not mark predictions stale for a working Quantity To Order change', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $stock = $service->updateFromSheet($variant, [
        'quantity_to_order' => 60,
    ], 'livi-road');
    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    $service->markRunUsed($run);
    expect($stock->fresh()?->isStaleFor($run->fresh()))->toBeFalse();

    $this->travel(1)->second();
    $service->updateFromSheet($variant, [
        'quantity_to_order' => 30,
    ], 'livi-road');

    expect($stock->fresh()?->isStaleFor($run->fresh()))->toBeFalse();
});

it('does not partially persist phases when a later Google tab read fails', function (): void {
    procurementSheetVariant('LRB0004', 'livi-road');
    foreach (['livi-road', 'untamed'] as $position => $handle) {
        ProcurementCollectionConfig::query()->create([
            'shopify_collection_id' => 'gid://shopify/Collection/'.($position + 1),
            'collection_handle' => $handle, 'collection_title' => $handle,
            'google_sheet_tab_name' => $handle, 'is_active' => true,
        ]);
    }
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    $row = array_fill(0, count($headers), '');
    $row[0] = 'LRB0004';
    $row[array_search('Quantity To Order', $headers, true)] = 60;
    Http::fake(function (Request $request) use ($headers, $row) {
        if (str_contains(urldecode($request->url()), "'livi-road'!A:AN")) {
            return Http::response(['values' => [$headers, $row]]);
        }

        return Http::response(['error' => 'temporary failure'], 503);
    });

    expect(fn () => procurementTestSheetSync()->pullHumanInputs())->toThrow(RuntimeException::class, 'read failed')
        ->and(ProcurementIncomingStock::query()->count())->toBe(0);
});

it('fails operational collection resolution for zero or multiple brand mappings', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road, untamed');
    $resolver = new OperationalProcurementCollectionResolver;
    expect(fn () => $resolver->resolve($variant->product))->toThrow(DomainException::class, 'no configured');

    foreach (['livi-road', 'untamed'] as $position => $handle) {
        ProcurementCollectionConfig::query()->create([
            'shopify_collection_id' => 'gid://shopify/Collection/'.($position + 1),
            'collection_handle' => $handle, 'collection_title' => $handle,
            'google_sheet_tab_name' => $handle, 'is_active' => true,
        ]);
    }
    $resolver = new OperationalProcurementCollectionResolver;
    expect(fn () => $resolver->resolve($variant->product))->toThrow(DomainException::class, 'multiple configured');
});

it('preserves human inputs but writes CMS-owned summary cells in brand rows and Master', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 60,
        'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
    $currentRun = procurementSheetRun();
    app(ProcurementIncomingStockService::class)->snapshotForRun($currentRun);
    app(ProcurementPredictionIngestService::class)->persist(procurementSheetPredictionPayload($currentRun));
    app(ProcurementIncomingStockService::class)->markRunUsed($currentRun->fresh());
    config([
        'google_sheets.enabled' => true,
        'google_sheets.spreadsheet_id' => 'sheet-1',
        'google_sheets.master_tab' => 'master-file',
    ]);
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    Http::fake(function (Request $request) use ($headers) {
        $url = $request->url();
        if ($request->method() === 'GET' && str_contains(urldecode($url), "'master-file'!A:AN")) {
            return Http::response(['values' => [$headers]]);
        }
        if ($request->method() === 'GET' && str_contains(urldecode($url), "'livi-road'!A:AN")) {
            $row = array_fill(0, count($headers), '');
            $row[0] = 'LRB0004';
            $row[array_search('Quantity To Order', $headers, true)] = 60;

            return Http::response(['values' => [$headers, $row]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [
                ['properties' => ['title' => 'master-file', 'sheetId' => 1]],
                ['properties' => ['title' => 'livi-road', 'sheetId' => 2]],
            ]]);
        }

        return Http::response(['ok' => true]);
    });
    $tokens = new class extends GoogleServiceAccountTokenProvider
    {
        public function token(): string
        {
            return 'fake-google-token';
        }
    };
    $client = new GoogleSheetsClient($tokens);
    $resolver = new OperationalProcurementCollectionResolver;
    $sync = new ProcurementSheetSyncService(
        $client, new ProcurementSheetSchema, app(ProcurementIncomingStockService::class),
        $resolver, new ProcurementSheetDatasetBuilder(
            $resolver, app(SalePercentageCalculator::class), app(ProcurementActionPolicy::class),
            app(\App\Services\SiblingCollectionResolver::class)
        ),
    );

    $sync->publish();

    $ranges = collect(Http::recorded())
        ->flatMap(fn (array $pair) => collect((array) data_get($pair[0]->data(), 'data', []))->pluck('range'))
        ->filter()->values();
    expect($ranges->contains(fn (string $range): bool => str_starts_with($range, "'master-file'!A2:")))->toBeTrue()
        ->and($ranges)->not->toContain("'livi-road'!H2")
        ->and($ranges)->not->toContain("'livi-road'!J2")
        ->and($ranges)->not->toContain("'livi-road'!P2")
        ->and($ranges)->not->toContain("'livi-road'!AI2")
        ->and($ranges)->toContain("'livi-road'!I2")
        ->and($ranges)->toContain("'livi-road'!X2")
        ->and($ranges)->toContain("'livi-road'!Y2")
        ->and($ranges)->toContain("'livi-road'!W2");
});

it('publishes operational inventory and CMS orders without changing ML or Ignore cells', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'ignore' => true, 'quantity_to_order' => 9,
    ], 'google_sheets:livi-road');
    $variant->procurementIncomingStock()->update([
        'total_quantity_on_order' => 9,
        'total_confirmed_quantity_on_order' => 9,
        'number_of_wip_orders' => 1,
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1', 'google_sheets.master_tab' => 'master-file']);
    $this->app->instance(GoogleServiceAccountTokenProvider::class, new class extends GoogleServiceAccountTokenProvider
    {
        public function token(): string
        {
            return 'fake-google-token';
        }
    });
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    Http::fake(function (Request $request) use ($headers) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/values/')) {
            $row = array_fill(0, count($headers), '');
            $row[0] = 'LRB0004';

            return Http::response(['values' => [$headers, $row]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [
                ['properties' => ['title' => 'master-file', 'sheetId' => 1]],
                ['properties' => ['title' => 'livi-road', 'sheetId' => 2]],
            ]]);
        }

        return Http::response(['ok' => true]);
    });

    procurementTestSheetSync()->publishOperational([$variant->id]);
    $ranges = collect(Http::recorded())->flatMap(
        fn (array $pair) => collect((array) data_get($pair[0]->data(), 'data', []))->pluck('range')
    )->filter()->values();

    expect($ranges)->toContain("'master-file'!E2")
        ->and($ranges)->toContain("'master-file'!F2")
        ->and($ranges)->toContain("'master-file'!G2")
        ->and($ranges)->not->toContain("'master-file'!H2")
        ->and($ranges)->not->toContain("'master-file'!J2")
        ->and($ranges)->toContain("'master-file'!I2")
        ->and($ranges)->toContain("'master-file'!M2")
        ->and($ranges)->not->toContain("'master-file'!P2")
        ->and($ranges)->toContain("'master-file'!S2")
        ->and($ranges)->toContain("'master-file'!U2")
        ->and($ranges)->toContain("'master-file'!V2")
        ->and($ranges)->toContain("'master-file'!W2")
        ->and($ranges)->toContain("'master-file'!X2")
        ->and($ranges)->toContain("'master-file'!Y2")
        ->and($ranges)->toContain("'master-file'!Z2")
        ->and($ranges)->toContain("'master-file'!AA2")
        ->and($ranges)->toContain("'master-file'!AB2")
        ->and($ranges)->toContain("'master-file'!AC2")
        ->and($ranges)->toContain("'master-file'!AD2")
        ->and($ranges)->toContain("'master-file'!AH2")
        ->and($ranges)->toContain("'master-file'!AJ2")
        ->and($ranges)->toContain("'master-file'!AK2")
        ->and($ranges)->toContain("'livi-road'!AL2")
        ->and($ranges)->not->toContain("'master-file'!AI2");
});

it('can append a missing targeted operational row for local Sheet testing', function (): void {
    [, $variant] = procurementSheetVariant('LOCAL-APPEND', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1', 'google_sheets.master_tab' => 'master-file']);
    $this->app->instance(ProcurementSheetSyncService::class, procurementTestSheetSync());
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    Http::fake(function (Request $request) use ($headers) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/values/')) {
            return Http::response(['values' => [$headers]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [
                ['properties' => ['title' => 'master-file', 'sheetId' => 1]],
                ['properties' => ['title' => 'livi-road', 'sheetId' => 2]],
            ]]);
        }

        return Http::response(['ok' => true]);
    });

    $result = procurementTestSheetSync()->publishOperational([$variant->id], appendMissing: true);
    $appendRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/values/') && str_contains($request->url(), ':append'))
        ->values();

    expect($result)->toBe(['rows' => 2, 'tabs' => 2])
        ->and($appendRequests)->toHaveCount(2)
        ->and($appendRequests->map(fn (Request $request): mixed => data_get($request->data(), 'values.0.0'))->all())
        ->toBe(['LOCAL-APPEND', 'LOCAL-APPEND']);
});

it('includes prelaunch drafts in procurement records while excluding test drafts by default', function (): void {
    config(['procurement.include_test_products' => false]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    NewProductDraft::query()->create([
        'sku' => 'DRAFT-001', 'title' => 'Prelaunch Bracelet', 'handle' => 'prelaunch-bracelet',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 15,
        'bead_colour_finish' => 'Colourful', 'siblings' => 'Slims',
    ]);
    NewProductDraft::query()->create([
        'sku' => 'TEST1P324', 'title' => 'Siblings Template Test', 'handle' => 'siblings-template-test',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 10,
    ]);

    $records = collect(app(ProcurementSheetDatasetBuilder::class)->records());

    expect($records->pluck('sku'))->toContain('DRAFT-001')
        ->not->toContain('TEST1P324');

    $draft = $records->firstWhere('sku', 'DRAFT-001');
    expect($draft['current_price'])->toBe('540.00')
        ->and($draft['current_inventory'])->toBe(15)
        ->and($draft['bead_color_finish'])->toBe('Colourful')
        ->and($draft['sibling_shapes'])->toBe('')
        ->and($draft['action_reason'])->toContain('Prelaunch draft');
});

it('allows test drafts in procurement records when the local test-products flag is enabled', function (): void {
    config(['procurement.include_test_products' => true]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    NewProductDraft::query()->create([
        'sku' => 'TEST1P324', 'title' => 'Siblings Template Test', 'handle' => 'siblings-template-test',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 10,
    ]);

    expect(collect(app(ProcurementSheetDatasetBuilder::class)->records())->pluck('sku'))
        ->toContain('TEST1P324');
});

it('does not publish raw sibling product gids from draft rows', function (): void {
    config(['procurement.include_test_products' => true]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    NewProductDraft::query()->create([
        'sku' => 'TEST-GIDS', 'title' => 'Test Product Gids', 'handle' => 'test-product-gids',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 10,
        'siblings' => 'gid://shopify/Product/8516761911432; gid://shopify/Product/8517414158472',
    ]);

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'TEST-GIDS');

    expect($record['sibling_shapes'])->toBe('');
});

it('can append a missing targeted draft operational row for local Sheet testing', function (): void {
    config(['procurement.include_test_products' => true]);
    $draft = NewProductDraft::query()->create([
        'sku' => 'TEST1P324', 'title' => 'Siblings Template Test', 'handle' => 'siblings-template-test',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 10,
    ]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1', 'google_sheets.master_tab' => 'master-file']);
    $this->app->instance(ProcurementSheetSyncService::class, procurementTestSheetSync());
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    Http::fake(function (Request $request) use ($headers) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/values/')) {
            return Http::response(['values' => [$headers]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [
                ['properties' => ['title' => 'master-file', 'sheetId' => 1]],
                ['properties' => ['title' => 'livi-road', 'sheetId' => 2]],
            ]]);
        }

        return Http::response(['ok' => true]);
    });

    $result = procurementTestSheetSync()->publishOperational(appendMissing: true, draftIds: [$draft->id]);
    $appendRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/values/') && str_contains($request->url(), ':append'))
        ->values();

    expect($result)->toBe(['rows' => 2, 'tabs' => 2])
        ->and($appendRequests->map(fn (Request $request): mixed => data_get($request->data(), 'values.0.0'))->all())
        ->toBe(['TEST1P324', 'TEST1P324']);
});

it('falls back to a new product draft when a matched local variant is procurement excluded', function (): void {
    config(['procurement.include_test_products' => true]);
    [, $variant] = procurementSheetVariant('TEST1P324', 'livi-road');
    Product::withoutEvents(fn () => $variant->product->update(['status' => 'draft']));
    NewProductDraft::query()->create([
        'sku' => 'TEST1P324', 'title' => 'Siblings Template Test', 'handle' => 'siblings-template-test',
        'status' => 'draft', 'vendor' => 'Livi Road', 'type' => 'Bracelets',
        'tags' => 'livi-road', 'variant_price' => 540, 'variant_inventory_qty' => 10,
    ]);
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1', 'collection_handle' => 'livi-road',
        'collection_title' => 'Livi Road', 'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1', 'google_sheets.master_tab' => 'master-file']);
    $this->app->instance(ProcurementSheetSyncService::class, procurementTestSheetSync());
    $headers = array_values(ProcurementSheetSchema::FIELDS);
    Http::fake(function (Request $request) use ($headers) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/values/')) {
            return Http::response(['values' => [$headers]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [
                ['properties' => ['title' => 'master-file', 'sheetId' => 1]],
                ['properties' => ['title' => 'livi-road', 'sheetId' => 2]],
            ]]);
        }

        return Http::response(['ok' => true]);
    });

    $this->artisan('procurement:sheets-operational', [
        '--append-missing' => true,
        '--sku' => ['TEST1P324'],
    ])->expectsOutputToContain('Published 2 operational row update(s) across 2 tab(s).')
        ->assertSuccessful();
});

it('refuses to publish stale incoming-stock inputs before making a Google write', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    config(['google_sheets.enabled' => true, 'google_sheets.spreadsheet_id' => 'sheet-1']);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'quantity_to_order' => 60,
    ], 'livi-road');
    Http::fake();

    expect(fn () => procurementTestSheetSync()->publish())
        ->toThrow(RuntimeException::class, 'Refusing to publish')
        ->and(Http::recorded())->toHaveCount(0);
});

it('builds current and projected sheet inventory from refreshed Shopify truth', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    app(ProcurementIncomingStockService::class)->updateFromSheet($variant, [
        'quantity_to_order' => 0,
    ], 'livi-road');
    $variant->procurementIncomingStock()->update([
        'total_quantity_on_order' => 4,
        'total_confirmed_quantity_on_order' => 4,
        'number_of_wip_orders' => 1,
    ]);
    $run = procurementSheetRun();
    app(ProcurementIncomingStockService::class)->snapshotForRun($run);
    app(ProcurementPredictionIngestService::class)->persist(procurementSheetPredictionPayload($run));
    $run->predictions()->update(['predicted_runout_date' => '2026-08-16']);
    app(ProcurementIncomingStockService::class)->markRunUsed($run->fresh());

    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 15,
        'current_inventory_quantity' => 15,
        'current_available_quantity' => 15,
        'current_committed_quantity' => 4,
        'current_reserved_quantity' => 3,
        'current_on_hand_quantity' => 19,
        'inventory_last_synced_at' => '2026-10-02 14:35:00',
    ])->save());

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');

    expect($record['current_inventory'])->toBe(15)
        ->and($record['current_committed_inventory'])->toBe(4)
        ->and($record['current_reserved_inventory'])->toBe(3)
        ->and($record['current_on_hand_inventory'])->toBe(19)
        ->and($record['projected_inventory_position'])->toBe(19)
        ->and($record['number_of_wip_orders'])->toBe(1)
        ->and($record['predicted_runout_date'])->toBe('16/08/2026')
        ->and($record['last_updated'])->toStartWith('02/10/2026 ');
});

it('shows the two earliest complete pending orders and stock gap health', function (): void {
    $this->travelTo('2026-09-03 00:00:00');
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 10, 'current_inventory_quantity' => 10,
    ])->save());
    $run = procurementSheetRun();
    app(ProcurementPredictionIngestService::class)->persist(procurementSheetPredictionPayload($run));
    $run->predictions()->update([
        'predicted_runout_date' => '2026-09-10',
        'predicted_weekly_demand' => 7,
        'additional_order_required' => 30,
        'preliminary_order_quantity' => 30,
    ]);

    foreach ([
        ['PO-LATE', '2026-09-15'],
        ['PO-NEXT', '2026-09-08'],
        ['PO-SECOND', '2026-09-12'],
    ] as [$orderNumber, $eta]) {
        $order = ProcurementSupplierOrder::query()->create([
            'uuid' => (string) Str::uuid(), 'order_number' => $orderNumber,
        ]);
        ProcurementSupplierOrderLine::query()->create([
            'supplier_order_id' => $order->id, 'variant_id' => $variant->id,
            'sku' => $variant->sku, 'quantity_ordered' => 10,
            'eta_date' => $eta, 'status' => 'open',
        ]);
    }
    ProcurementIncomingStock::query()->updateOrCreate(['variant_id' => $variant->id], [
        'sku' => $variant->sku,
        'total_quantity_on_order' => 30,
        'total_confirmed_quantity_on_order' => 30,
        'number_of_wip_orders' => 3,
    ]);

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');

    expect($record['next_order_id'])->toBe('PO-NEXT')
        ->and($record['next_eta'])->toBe('08/09/2026')
        ->and($record['second_order_id'])->toBe('PO-SECOND')
        ->and($record['second_eta'])->toBe('12/09/2026')
        ->and($record['predicted_runout_date_after_replenishment'])->toBe('23/09/2026')
        ->and($record['projected_stock_before_second_eta'])->toBe(11.0)
        ->and($record['between_orders_stock_gap_status'])->toBe('HEALTHY')
        ->and($record['replenishment_date'])->toBe('08/09/2026')
        ->and($record['stock_gap_status'])->toBe('HEALTHY')
        ->and($record['action_required'])->not->toBe('ORDER_NOW');

    $run->predictions()->update(['additional_order_required' => 31]);
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');
    expect($record['action_required'])->toBe('ORDER_NOW');

    $run->predictions()->update([
        'predicted_runout_date' => '2026-09-01',
        'additional_order_required' => 31,
    ]);
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');
    expect($record['stock_gap_status'])->toBe('UNHEALTHY')
        ->and($record['stockout_before_incoming_arrival'])->toBeTrue()
        ->and($record['incoming_stock_covers_requirement'])->toBeFalse();

    $run->predictions()->update(['additional_order_required' => 0]);
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');
    expect($record['additional_order_required'])->toBe(30)
        ->and($record['incoming_stock_covers_requirement'])->toBeFalse()
        ->and($record['action_required'])->toBe('ORDER_NOW');

    $run->predictions()->update([
        'predicted_runout_date' => '2026-09-10',
        'predicted_weekly_demand' => 21,
    ]);
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');
    expect($record['projected_stock_before_second_eta'])->toBe(-2.0)
        ->and($record['predicted_runout_date_after_replenishment'])->toBe('11/09/2026')
        ->and($record['between_orders_stock_gap_status'])->toBe('UNHEALTHY')
        ->and($record['action_required'])->not->toBe('NO_ACTION');
});

it('keeps gross and additional requirements aligned with timely WIP orders', function (): void {
    $this->travelTo('2026-09-01 00:00:00');
    $runNumber = 0;

    $makePrediction = function (string $sku, int $inventory, string $runout) use (&$runNumber): array {
        [, $variant] = procurementSheetVariant($sku, 'livi-road');
        Variant::withoutEvents(fn () => $variant->forceFill([
            'inventory_qty' => $inventory,
            'current_inventory_quantity' => $inventory,
            'current_on_hand_quantity' => $inventory,
        ])->save());
        $run = ProcurementPredictionRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'calculation_date' => now()->subDays(10 - $runNumber++)->toDateString(),
            'status' => ProcurementPredictionRun::STATUS_RUNNING,
            'default_lead_time_days' => 56,
            'attention_horizon_days' => 21,
        ]);
        $payload = procurementSheetPredictionPayload($run);
        $payload['predictions'][0] = array_merge($payload['predictions'][0], [
            'shopify_product_id' => 'gid://shopify/Product/'.$sku,
            'shopify_variant_id' => 'gid://shopify/ProductVariant/'.$sku,
            'sku' => $sku,
            'current_inventory' => $inventory,
            'predicted_weekly_demand' => 7,
            'predicted_runout_date' => $runout,
            'recommended_order_before_incoming_stock' => 100,
            'additional_order_required' => 100,
        ]);
        app(ProcurementPredictionIngestService::class)->persist($payload);

        return [$variant, $run];
    };

    [$noOrder] = $makePrediction('GAP-A', 0, '2026-09-01');
    $caseA = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'GAP-A');
    expect($caseA['recommended_order_before_incoming_stock'])->toBe(100)
        ->and($caseA['total_quantity_on_order'])->toBe(0)
        ->and($caseA['number_of_wip_orders'])->toBe(0)
        ->and($caseA['additional_order_required'])->toBe(100)
        ->and($caseA['action_required'])->toBe('ORDER_NOW');

    [$timely] = $makePrediction('GAP-B', 20, '2026-09-30');
    app(SupplierOrderService::class)
        ->createForVariant($timely, 'PO-GAP-B', 60, '2026-09-10');
    $caseB = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'GAP-B');
    expect($caseB['recommended_order_before_incoming_stock'])->toBe(100)
        ->and($caseB['total_quantity_on_order'])->toBe(60)
        ->and($caseB['additional_order_required'])->toBe(40)
        ->and($caseB['stock_gap_status'])->toBe('HEALTHY');

    [$late] = $makePrediction('GAP-C', 20, '2026-09-10');
    app(SupplierOrderService::class)
        ->createForVariant($late, 'PO-GAP-C', 100, '2026-09-30');
    $caseC = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'GAP-C');
    expect($caseC['recommended_order_before_incoming_stock'])->toBe(100)
        ->and($caseC['total_quantity_on_order'])->toBe(100)
        ->and($caseC['additional_order_required'])->toBe(100)
        ->and($caseC['stock_gap_status'])->toBe('UNHEALTHY')
        ->and($caseC['action_required'])->toBe('ORDER_NOW');
});

it('makes blank forecasts explicit for missing and intentionally insufficient predictions', function (): void {
    [, $outOfStock] = procurementSheetVariant('BLANK-OOS', 'livi-road');
    [, $inStock] = procurementSheetVariant('BLANK-STOCK', 'livi-road');
    Variant::withoutEvents(fn () => $inStock->forceFill([
        'inventory_qty' => 12,
        'current_inventory_quantity' => 12,
        'current_on_hand_quantity' => 12,
    ])->save());
    [, $insufficient] = procurementSheetVariant('BLANK-INTENTIONAL', 'livi-road');

    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0] = array_merge($payload['predictions'][0], [
        'shopify_product_id' => $insufficient->product->shopify_id,
        'shopify_variant_id' => $insufficient->shopify_id,
        'sku' => $insufficient->sku,
        'predicted_weekly_demand' => null,
        'estimated_days_of_stock_remaining' => null,
        'predicted_runout_date' => null,
        'preliminary_order_quantity' => null,
        'additional_order_required' => null,
        'recommended_order_before_incoming_stock' => null,
        'action_status' => 'INSUFFICIENT_DATA',
        'action_reason' => 'Insufficient sales history to produce a defensible demand forecast.',
        'data_quality_status' => 'INSUFFICIENT_DATA',
    ]);
    app(ProcurementPredictionIngestService::class)->persist($payload);

    $records = collect(app(ProcurementSheetDatasetBuilder::class)->records())->keyBy('sku');
    foreach ([$outOfStock->sku, $inStock->sku] as $sku) {
        expect($records[$sku]['predicted_weekly_demand'])->toBeNull()
            ->and($records[$sku]['additional_order_required'])->toBeNull()
            ->and($records[$sku]['action_required'])->toBe('INSUFFICIENT_DATA')
            ->and($records[$sku]['action_reason'])->toContain('did not produce a forecast');
    }
    expect($records[$outOfStock->sku]['predicted_runout_date'])->toBe('OUT_OF_STOCK')
        ->and($records[$outOfStock->sku]['stock_gap_status'])->toBe('NO_PENDING_ORDER')
        ->and($records[$insufficient->sku]['predicted_weekly_demand'])->toBeNull()
        ->and($records[$insufficient->sku]['action_required'])->toBe('INSUFFICIENT_DATA')
        ->and($records[$insufficient->sku]['action_reason'])->toContain('Insufficient sales history');
});

it('keeps overdue WIP visible but excludes it from timely incoming stock', function (): void {
    $this->travelTo('2026-09-10 00:00:00');
    [, $variant] = procurementSheetVariant('OVERDUE-WIP', 'livi-road');
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 20,
        'current_inventory_quantity' => 20,
        'current_on_hand_quantity' => 20,
    ])->save());
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0] = array_merge($payload['predictions'][0], [
        'shopify_product_id' => $variant->product->shopify_id,
        'shopify_variant_id' => $variant->shopify_id,
        'sku' => $variant->sku,
        'current_inventory' => 20,
        'predicted_weekly_demand' => 7,
        'predicted_runout_date' => '2026-09-30',
        'recommended_order_before_incoming_stock' => 100,
        'additional_order_required' => 100,
    ]);
    app(ProcurementPredictionIngestService::class)->persist($payload);
    app(SupplierOrderService::class)
        ->createForVariant($variant, 'PO-OVERDUE', 100, '2026-09-09');

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'OVERDUE-WIP');
    expect($record['total_quantity_on_order'])->toBe(100)
        ->and($record['replenishment_date'])->toBe('09/09/2026')
        ->and($record['stock_gap_status'])->toBe('UNHEALTHY')
        ->and($record['additional_order_required'])->toBe(100)
        ->and($record['action_required'])->toBe('ORDER_NOW')
        ->and($record['action_reason'])->toContain('overdue')
        ->and($record['incoming_stock_covers_requirement'])->toBeFalse();
});

it('replaces the stale arrival timing action reason for timely WIP', function (): void {
    $this->travelTo('2026-09-01 00:00:00');
    [, $variant] = procurementSheetVariant('TIMELY-REASON', 'livi-road');
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 20,
        'current_inventory_quantity' => 20,
        'current_on_hand_quantity' => 20,
    ])->save());
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0] = array_merge($payload['predictions'][0], [
        'shopify_product_id' => $variant->product->shopify_id,
        'shopify_variant_id' => $variant->shopify_id,
        'sku' => $variant->sku,
        'current_inventory' => 20,
        'predicted_weekly_demand' => 7,
        'predicted_runout_date' => '2026-09-30',
        'recommended_order_before_incoming_stock' => 60,
        'additional_order_required' => 60,
        'action_reason' => 'Existing incoming stock covers the quantity requirement; arrival timing is not tracked in this workflow.',
    ]);
    app(ProcurementPredictionIngestService::class)->persist($payload);
    app(SupplierOrderService::class)
        ->createForVariant($variant, 'PO-TIMELY-REASON', 60, '2026-09-10');

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'TIMELY-REASON');
    expect($record['stock_gap_status'])->toBe('HEALTHY')
        ->and($record['additional_order_required'])->toBe(0)
        ->and($record['action_required'])->toBe('NO_ACTION')
        ->and($record['action_reason'])->toBe('Existing incoming stock arriving in time covers the forecast requirement.');
});

it('sanitizes legacy arrival timing wording during prediction ingestion', function (): void {
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0]['action_reason'] = 'Existing incoming stock covers the quantity requirement; arrival timing is not tracked in this workflow.';

    $prediction = app(ProcurementPredictionIngestService::class)->persist($payload)
        ->predictions()->firstOrFail();

    expect($prediction->action_reason)
        ->not->toContain('arrival timing is not tracked')
        ->toContain('arrival timing is evaluated by the CMS');
});

it('projects the post-replenishment runout with one order and reports no second order', function (): void {
    $this->travelTo('2026-09-03 00:00:00');
    [, $variant] = procurementSheetVariant('ONE-ORDER', 'livi-road');
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 2,
        'current_inventory_quantity' => 2,
        'current_on_hand_quantity' => 10,
    ])->save());
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0]['shopify_product_id'] = 'gid://shopify/Product/ONE-ORDER';
    $payload['predictions'][0]['shopify_variant_id'] = 'gid://shopify/ProductVariant/ONE-ORDER';
    $payload['predictions'][0]['sku'] = 'ONE-ORDER';
    app(ProcurementPredictionIngestService::class)->persist($payload);
    $run->predictions()->update(['predicted_weekly_demand' => 7]);

    $order = ProcurementSupplierOrder::query()->create([
        'uuid' => (string) Str::uuid(), 'order_number' => 'PO-ONLY',
    ]);
    ProcurementSupplierOrderLine::query()->create([
        'supplier_order_id' => $order->id, 'variant_id' => $variant->id,
        'sku' => $variant->sku, 'quantity_ordered' => 10,
        'eta_date' => '2026-09-08', 'status' => 'open',
    ]);

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'ONE-ORDER');

    expect($record['predicted_runout_date_after_replenishment'])->toBe('23/09/2026')
        ->and($record['projected_stock_before_second_eta'])->toBeNull()
        ->and($record['between_orders_stock_gap_status'])->toBe('NO_SECOND_ORDER');
});

it('marks out of stock and missing replenishment explicitly', function (): void {
    [, $variant] = procurementSheetVariant('NO-ORDER', 'livi-road');
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0]['shopify_product_id'] = 'gid://shopify/Product/NO-ORDER';
    $payload['predictions'][0]['shopify_variant_id'] = 'gid://shopify/ProductVariant/NO-ORDER';
    $payload['predictions'][0]['sku'] = 'NO-ORDER';
    app(ProcurementPredictionIngestService::class)->persist($payload);

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'NO-ORDER');

    expect($record['predicted_runout_date'])->toBe('OUT_OF_STOCK')
        ->and($record['replenishment_date'])->toBeNull()
        ->and($record['stock_gap_status'])->toBe('NO_PENDING_ORDER')
        ->and($record['predicted_runout_date_after_replenishment'])->toBeNull()
        ->and($record['projected_stock_before_second_eta'])->toBeNull()
        ->and($record['between_orders_stock_gap_status'])->toBe('NO_SECOND_ORDER')
        ->and($record['stockout_before_incoming_arrival'])->toBeFalse()
        ->and($record['incoming_stock_covers_requirement'])->toBeFalse();
});

it('excludes stack products from procurement sheets and prediction inputs', function (): void {
    [, $stack] = procurementSheetVariant('STACK-001', 'livi-road');
    Product::withoutEvents(fn () => $stack->product->update(['is_bundle' => true]));

    expect(collect(app(ProcurementSheetDatasetBuilder::class)->records())->pluck('sku'))
        ->not->toContain('STACK-001');

    $run = procurementSheetRun();
    app(ProcurementIncomingStockService::class)->snapshotForRun($run);

    expect($run->incomingStockInputs()->where('sku', 'STACK-001')->exists())->toBeFalse();
});

it('orders immediately when available inventory is out of stock even below the threshold', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $run = procurementSheetRun();
    app(ProcurementPredictionIngestService::class)->persist(procurementSheetPredictionPayload($run));
    $run->predictions()->update([
        'additional_order_required' => 1,
        'preliminary_order_quantity' => 1,
    ]);

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', $variant->sku);

    expect($record['current_inventory'])->toBe(0)
        ->and($record['additional_order_required'])->toBe(1)
        ->and($record['action_required'])->toBe('ORDER_NOW');
});

it('uses the shared CMS sale percentage calculation in procurement sheets', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    ProcurementCollectionConfig::query()->create([
        'shopify_collection_id' => 'gid://shopify/Collection/1',
        'collection_handle' => 'livi-road', 'collection_title' => 'Livi Road',
        'is_active' => true, 'google_sheet_tab_name' => 'livi-road',
    ]);
    Variant::withoutEvents(fn () => $variant->forceFill([
        'price' => 75, 'compare_at_price' => 100,
    ])->save());

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'LRB0004');
    expect($record['currently_on_sale'])->toBeTrue()
        ->and($record['sale_percentage'])->toBe(25.0);

    Variant::withoutEvents(fn () => $variant->forceFill(['compare_at_price' => null])->save());
    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())->firstWhere('sku', 'LRB0004');
    expect($record['currently_on_sale'])->toBeFalse()
        ->and($record['sale_percentage'])->toBeNull();
});

it('persists the complete ML incoming-stock prediction contract', function (): void {
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0] = array_merge($payload['predictions'][0], [
        'total_quantity_on_order' => 90,
        'projected_inventory_position' => 90,
        'recommended_order_before_incoming_stock' => 124,
        'additional_order_required' => 34,
        'incoming_stock_covers_requirement' => false,
        'stockout_before_incoming_arrival' => false,
    ]);

    $saved = app(ProcurementPredictionIngestService::class)->persist($payload);
    $prediction = $saved->predictions()->firstOrFail();
    expect($prediction->total_quantity_on_order)->toBe(90)
        ->and($prediction->total_confirmed_quantity_on_order)->toBe(90)
        ->and($prediction->procurement_actioned)->toBeTrue()
        ->and($prediction->projected_inventory_position)->toBe(90)
        ->and($prediction->recommended_order_before_incoming_stock)->toBe(124)
        ->and($prediction->additional_order_required)->toBe(34);
});

it('enforces zero procurement recommendations for ignored prediction rows', function (): void {
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0]['ignore'] = true;
    $payload['predictions'][0]['recommended_order_before_incoming_stock'] = 100;
    $payload['predictions'][0]['additional_order_required'] = 100;

    $prediction = app(ProcurementPredictionIngestService::class)->persist($payload)
        ->predictions()->firstOrFail();

    expect($prediction->ignore)->toBeTrue()
        ->and($prediction->current_inventory)->toBeNull()
        ->and($prediction->action_status)->toBe('NO_ACTION')
        ->and($prediction->additional_order_required)->toBe(0)
        ->and($prediction->recommended_order_before_incoming_stock)->toBe(0);
});

it('does not double count CMS incoming stock after it is received into Shopify inventory', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($variant, ['quantity_to_order' => 0], 'livi-road');
    $variant->procurementIncomingStock()->update([
        'total_quantity_on_order' => 60,
        'total_confirmed_quantity_on_order' => 60,
        'number_of_wip_orders' => 1,
    ]);
    $beforeReceipt = procurementSheetRun();
    $service->snapshotForRun($beforeReceipt);
    expect($beforeReceipt->incomingStockInputs()->value('total_quantity_on_order'))->toBe(60)
        ->and(($variant->fresh()?->current_inventory_quantity ?? 0) + 60)->toBe(60);

    $variant->procurementIncomingStock()->update([
        'total_quantity_on_order' => 0,
        'total_confirmed_quantity_on_order' => 0,
        'number_of_wip_orders' => 0,
    ]);
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 60, 'current_inventory_quantity' => 60,
    ])->save());
    $afterReceipt = ProcurementPredictionRun::query()->create([
        'run_uuid' => (string) Str::uuid(), 'calculation_date' => '2026-08-12',
        'status' => ProcurementPredictionRun::STATUS_RUNNING,
        'default_lead_time_days' => 56, 'attention_horizon_days' => 21,
    ]);
    $service->snapshotForRun($afterReceipt);
    $currentInventory = (int) $variant->fresh()?->current_inventory_quantity;
    $incoming = (int) $afterReceipt->incomingStockInputs()->value('total_quantity_on_order');

    expect($currentInventory)->toBe(60)
        ->and($incoming)->toBe(0)
        ->and($currentInventory + $incoming)->toBe(60);
});

/** @return array{Import,Variant} */
function procurementSheetVariant(string $sku, string $collectionTag): array
{
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'procurement.csv', 'mode' => 'overwrite',
        'status' => 'ready', 'created_by' => $user->id,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/'.$sku,
        'handle' => strtolower($sku), 'title' => 'Product '.$sku,
        'vendor' => 'Leigh Avenue', 'type' => 'Jewellery', 'status' => 'active',
        'tags' => $collectionTag, 'approval_version' => 1,
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/'.$sku,
        'sku' => $sku, 'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => true, 'current_inventory_quantity' => 0,
        'price' => 100,
    ]));

    return [$import, $variant];
}

function procurementSheetRun(): ProcurementPredictionRun
{
    return ProcurementPredictionRun::query()->create([
        'run_uuid' => (string) Str::uuid(),
        'calculation_date' => '2026-08-11',
        'status' => ProcurementPredictionRun::STATUS_RUNNING,
        'default_lead_time_days' => 56, 'attention_horizon_days' => 21,
    ]);
}

function procurementSheetPredictionPayload(ProcurementPredictionRun $run): array
{
    return [
        'run_uuid' => $run->run_uuid,
        'run' => [
            'model_version' => 'ridge-phase1', 'default_lead_time_days' => 56,
            'attention_horizon_days' => 21, 'total_input_rows' => 1,
            'total_excluded_rows' => 0, 'warning_count' => 0, 'error_count' => 0,
        ],
        'predictions' => [[
            'shopify_product_id' => 'gid://shopify/Product/LRB0004',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/LRB0004',
            'sku' => 'LRB0004', 'attention_horizon_days' => 21,
            'lead_time_days_used' => 56, 'lead_time_source' => 'GLOBAL_DEFAULT',
            'ignore' => false,
            'preliminary_order_quantity' => 34, 'currently_on_sale' => false,
            'action_status' => 'ORDER_NOW', 'generated_at' => now()->toIso8601String(),
        ]],
    ];
}

function procurementTestSheetSync(): ProcurementSheetSyncService
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
        new GoogleSheetsClient($tokens), new ProcurementSheetSchema,
        app(ProcurementIncomingStockService::class), $resolver,
        new ProcurementSheetDatasetBuilder(
            $resolver, app(SalePercentageCalculator::class), app(ProcurementActionPolicy::class), app(\App\Services\SiblingCollectionResolver::class)
        ),
    );
}
