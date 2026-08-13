<?php

use App\Models\Import;
use App\Models\ProcurementCollectionConfig;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementPredictionRun;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\GoogleSheets\GoogleServiceAccountTokenProvider;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\ProcurementSheetDatasetBuilder;
use App\Services\GoogleSheets\ProcurementSheetSchema;
use App\Services\GoogleSheets\ProcurementSheetSyncService;
use App\Services\OperationalProcurementCollectionResolver;
use App\Services\ProcurementIncomingStockService;
use App\Services\ProcurementPredictionIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes blank phases calculates totals audits changes and snapshots them', function (): void {
    [$import, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);

    $stock = $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => '60',
        'quantity_on_order_phase_2' => '',
        'quantity_on_order_phase_3' => null,
    ], 'livi-road', 7);

    expect($stock->quantity_on_order_phase_1)->toBe(60)
        ->and($stock->quantity_on_order_phase_2)->toBe(0)
        ->and($stock->total_quantity_on_order)->toBe(60)
        ->and($stock->changes()->count())->toBe(1);

    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    $input = $run->incomingStockInputs()->firstOrFail();
    expect($input->sku)->toBe('LRB0004')
        ->and($input->total_quantity_on_order)->toBe(60)
        ->and($run->fresh()?->incoming_stock_input_hash)->not->toBeNull();
});

it('serves the immutable incoming-stock snapshot through the protected analytics feed', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 60,
        'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    config(['shopify_sync.analytics_export_token' => 'incoming-token']);

    $response = $this->withToken('incoming-token')->get(
        '/api/analytics/incoming-stock.csv?run_uuid='.$run->run_uuid
    );

    $response->assertOk();
    expect($response->streamedContent())
        ->toContain('quantity_on_order_phase_1')
        ->toContain('total_quantity_on_order')
        ->toContain('LRB0004')
        ->toContain(',60,0,0,60,60,livi-road,');
});

it('matches headers independent of case whitespace and column position', function (): void {
    $schema = new ProcurementSheetSchema;
    $headers = array_reverse(array_values(ProcurementSheetSchema::FIELDS));
    $headers[0] = '  LAST   UPDATED ';
    $map = $schema->map($headers);

    expect($map['last_updated'])->toBe(0)
        ->and($headers[$map['sku']])->toBe('SKU');
});

it('reads sorted brand rows by SKU and shuffled headers', function (): void {
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
    $row = function (string $sku, int $phase) use ($headers, $map): array {
        $values = array_fill(0, count($headers), '');
        $values[$map['sku']] = $sku;
        $values[$map['quantity_on_order_phase_1']] = $phase;

        return $values;
    };
    Http::fake(fn () => Http::response([
        'values' => [$headers, $row('LRB0005', 30), $row('LRB0004', 60)],
    ]));

    procurementTestSheetSync()->pullHumanInputs();

    expect(ProcurementIncomingStock::query()->where('variant_id', $first->id)->value('total_quantity_on_order'))->toBe(60)
        ->and(ProcurementIncomingStock::query()->where('variant_id', $second->id)->value('total_quantity_on_order'))->toBe(30);
});

it('marks predictions stale after a human phase changes', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $stock = $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 60, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
    $run = procurementSheetRun();
    $service->snapshotForRun($run);
    $service->markRunUsed($run);
    expect($stock->fresh()?->isStaleFor($run->fresh()))->toBeFalse();

    $this->travel(1)->second();
    $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 0, 'quantity_on_order_phase_2' => 30,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');

    expect($stock->fresh()?->isStaleFor($run->fresh()))->toBeTrue();
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
    $row[6] = 60;
    Http::fake(function (Request $request) use ($headers, $row) {
        if (str_contains(urldecode($request->url()), "'livi-road'!A:X")) {
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

it('never writes human phase cells in an existing brand row but writes them to Master', function (): void {
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
        if ($request->method() === 'GET' && str_contains(urldecode($url), "'master-file'!A:X")) {
            return Http::response(['values' => [$headers]]);
        }
        if ($request->method() === 'GET' && str_contains(urldecode($url), "'livi-road'!A:X")) {
            $row = array_fill(0, count($headers), '');
            $row[0] = 'LRB0004';
            $row[6] = 60;

            return Http::response(['values' => [$headers, $row]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['sheets' => [['properties' => ['title' => 'livi-road', 'sheetId' => 1]]]]);
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
        $resolver, new ProcurementSheetDatasetBuilder($resolver),
    );

    $sync->publish();

    $ranges = collect(Http::recorded())
        ->flatMap(fn (array $pair) => collect((array) data_get($pair[0]->data(), 'data', []))->pluck('range'))
        ->filter()->values();
    expect($ranges)->toContain("'master-file'!A2:X2")
        ->and($ranges)->not->toContain("'livi-road'!G2")
        ->and($ranges)->not->toContain("'livi-road'!H2")
        ->and($ranges)->not->toContain("'livi-road'!I2");
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
        'quantity_on_order_phase_1' => 60, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
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
        'quantity_on_order_phase_1' => 4, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
    $run = procurementSheetRun();
    app(ProcurementIncomingStockService::class)->snapshotForRun($run);
    app(ProcurementPredictionIngestService::class)->persist(procurementSheetPredictionPayload($run));
    app(ProcurementIncomingStockService::class)->markRunUsed($run->fresh());

    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 15,
        'current_inventory_quantity' => 15,
        'current_available_quantity' => 15,
    ])->save());

    $record = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'LRB0004');

    expect($record['current_inventory'])->toBe(15)
        ->and($record['projected_inventory_position'])->toBe(19);
});

it('persists the complete ML incoming-stock prediction contract', function (): void {
    $run = procurementSheetRun();
    $payload = procurementSheetPredictionPayload($run);
    $payload['predictions'][0] = array_merge($payload['predictions'][0], [
        'quantity_on_order_phase_1' => 60,
        'quantity_on_order_phase_2' => 30,
        'quantity_on_order_phase_3' => 0,
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
        ->and($prediction->projected_inventory_position)->toBe(90)
        ->and($prediction->recommended_order_before_incoming_stock)->toBe(124)
        ->and($prediction->additional_order_required)->toBe(34);
});

it('does not double count an order phase after stock is received into Shopify inventory', function (): void {
    [, $variant] = procurementSheetVariant('LRB0004', 'livi-road');
    $service = app(ProcurementIncomingStockService::class);
    $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 60, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
    $beforeReceipt = procurementSheetRun();
    $service->snapshotForRun($beforeReceipt);
    expect($beforeReceipt->incomingStockInputs()->value('total_quantity_on_order'))->toBe(60)
        ->and(($variant->fresh()?->current_inventory_quantity ?? 0) + 60)->toBe(60);

    $service->updateFromSheet($variant, [
        'quantity_on_order_phase_1' => 0, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ], 'livi-road');
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
        new ProcurementSheetDatasetBuilder($resolver),
    );
}
