<?php

use App\Models\ProcurementPredictionRun;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;
use App\Services\EngravedBeads\EngravedBeadProcurementContract;
use App\Services\ProcurementPredictionIngestService;
use App\Services\Shopify\ShopifyAnalyticsExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';
uses(RefreshDatabase::class);
beforeEach(fn () => config(['services.shopify.inventory_location_id' => null]));

it('exports seven sources rather than 28 member SKUs as Python product inputs', function () {
    foreach (range(1, 7) as $i) {
        sharedBeadFamily('design-'.$i.'-bead-family', 60000 + 10 * $i, active: true);
    }
    $contract = app(EngravedBeadProcurementContract::class)->export();
    expect($contract['families'])->toHaveCount(7);
    $csv = app(ShopifyAnalyticsExportService::class)->mlProductsCsv()->getCallback();
    ob_start();
    $csv();
    $content = ob_get_clean();
    $rows = array_map('str_getcsv', explode("\n", trim($content)));
    expect($rows)->toHaveCount(8)->and($content)->toContain('Inventory Item ID');
    foreach ($contract['families'] as $source) {
        expect($content)->toContain($source['source_sku']);
    }
    expect($content)->not->toContain('2-engraved-beads')->not->toContain('3-engraved-beads')->not->toContain('4-engraved-beads');
});

it('rejects incomplete unapproved inactive and drifted families without changing their mappings', function ($defect) {
    $f = sharedBeadFamily(active: true);
    $identity = $f['family']->source_inventory_item_id;
    match ($defect) {
        'unapproved' => $f['family']->update(['approved_by' => null]),
        'pending' => $f['family']->update(['status' => 'pending_validation']),
        'missing SKU' => $f['members'][3]['variant']->update(['sku' => null]),
        'missing member tag' => $f['members'][4]['product']->update(['tags' => '4-engraved-beads']),
        'source SKU drift' => $f['members'][1]['variant']->update(['sku' => 'RENAMED']),
        'source no longer tracked' => $f['members'][1]['variant']->update(['inventory_tracked' => false]),
    };
    expect(app(EngravedBeadProcurementContract::class)->export()['families'])->toBe([])
        ->and($f['family']->fresh()->source_inventory_item_id)->toBe($identity);
})->with(['unapproved', 'pending', 'missing SKU', 'missing member tag', 'source SKU drift', 'source no longer tracked']);

it('blocks duplicate SKUs across families and ordinary catalog variants before unsafe activation', function () {
    $f = sharedBeadFamily();
    Variant::query()->create(['product_id' => $f['members'][2]['product']->id, 'shopify_id' => 'gid://shopify/ProductVariant/999999',
        'sku' => strtolower($f['members'][1]['variant']->sku), 'sync_state' => Variant::SYNC_STATE_SYNCED]);
    $groups = app(EngravedBeadFamilyDiscovery::class)->synchronize();
    expect(implode(' ', $groups[$f['family']->family_tag]['errors']))->toContain('not unique');
});

function sourceForecastReservation(array $f, ?string $orderedVariant = null): ShopifyStackInventoryReservation
{
    return ShopifyStackInventoryReservation::query()->create([
        'shopify_order_id' => 'gid://shopify/Order/'.Str::uuid(), 'shopify_order_line_item_id' => 'gid://shopify/LineItem/'.Str::uuid(),
        'shopify_order_name' => '#100', 'shopify_order_created_at' => now(), 'stack_product_id' => $f['members'][4]['product']->id,
        'stack_variant_id' => $f['members'][4]['variant']->id, 'configured_component_product_id' => $f['members'][1]['product']->id,
        'component_product_id' => $f['members'][1]['product']->id, 'component_variant_id' => $f['members'][1]['variant']->id,
        'shopify_stack_variant_id' => $orderedVariant ?? $f['members'][4]['variant']->shopify_id,
        'shopify_component_variant_id' => $f['family']->source_variant_id,
        'shopify_inventory_item_id' => $f['family']->source_inventory_item_id, 'shopify_location_id' => $f['family']->source_location_id,
        'component_sku' => $f['members'][1]['variant']->sku, 'component_quantity_per_stack' => 4,
        'stack_quantity_ordered' => 10, 'total_component_quantity_required' => 40, 'reserved_quantity' => 40,
        'consumed_quantity' => 40, 'bead_family_id' => $f['family']->id, 'bead_configuration_version' => 1,
        'ledger_document_uri' => 'leighavenue-cms://engraved-bead-reservations/test', 'status' => 'completed',
    ]);
}

function sourceForecastMovement(ShopifyStackInventoryReservation $reservation, string $action = 'consume', string $status = 'completed', int $quantity = 40): ShopifyStackInventoryMovement
{
    return ShopifyStackInventoryMovement::query()->create(['reservation_id' => $reservation->id,
        'event_key' => hash('sha256', Str::uuid()), 'idempotency_key' => (string) Str::uuid(), 'action' => $action,
        'status' => $status, 'quantity' => $quantity, 'processed_at' => now()]);
}

it('exports only confirmed physical consumption once and excludes native sales and non-demand adjustments', function () {
    $f = sharedBeadFamily(active: true);
    $reservation = sourceForecastReservation($f);
    $consumed = sourceForecastMovement($reservation);
    foreach (['reserve', 'release', 'restock', 'correction', 'transfer', 'receipt', 'safety_stock', 'reversal'] as $action) {
        sourceForecastMovement($reservation, $action);
    }
    sourceForecastMovement($reservation, 'consume', 'failed');
    sourceForecastMovement(sourceForecastReservation($f, $f['family']->source_variant_id));
    $rows = iterator_to_array(app(EngravedBeadProcurementContract::class)->consumption(now()->toDateString(), now()->toDateString()));
    expect($rows)->toHaveCount(1)->and($rows[0]['quantity'])->toBe(40)
        ->and($rows[0]['event_id'])->toBe('cms-bead-consume:'.$consumed->event_key)
        ->and($rows[0]['source_variant_id'])->toBe($f['family']->source_variant_id);
    $reservation->refresh();
    expect($reservation->shopify_inventory_item_id)->toBe($f['family']->source_inventory_item_id);
});

it('excludes consumption with a changed source identity or historical provenance', function () {
    $f = sharedBeadFamily(active: true);
    $reservation = sourceForecastReservation($f);
    sourceForecastMovement($reservation);
    $reservation->update(['shopify_inventory_item_id' => 'gid://shopify/InventoryItem/99999']);
    expect(iterator_to_array(app(EngravedBeadProcurementContract::class)->consumption(now()->toDateString(), now()->toDateString())))->toBe([]);
    $reservation->update(['shopify_inventory_item_id' => $f['family']->source_inventory_item_id, 'shopify_order_created_at' => '2020-01-01']);
    expect(iterator_to_array(app(EngravedBeadProcurementContract::class)->consumption(now()->toDateString(), now()->toDateString())))->toBe([]);
});

it('keeps consumption date boundaries in the business timezone used by the movement ledger', function () {
    $f = sharedBeadFamily(active: true);
    $r = sourceForecastReservation($f);
    sourceForecastMovement($r)->update(['processed_at' => '2026-10-29 23:30:00']);
    sourceForecastMovement($r)->update(['processed_at' => '2026-10-30 00:30:00']);
    sourceForecastMovement($r)->update(['processed_at' => '2026-10-28 23:30:00']);
    $rows = iterator_to_array(app(EngravedBeadProcurementContract::class)->consumption('2026-10-29', '2026-10-29'));
    expect($rows)->toHaveCount(1)->and($rows[0]['occurred_at'])->toBe('2026-10-29T23:30:00+02:00');
});

function ordinarySourceForecastPayload(array $f, int $quantity): array
{
    $run = ProcurementPredictionRun::query()->create(['run_uuid' => (string) Str::uuid(), 'calculation_date' => now()->addDays($quantity)->toDateString(),
        'status' => 'running', 'default_lead_time_days' => 56, 'attention_horizon_days' => 21]);

    return ['run_uuid' => $run->run_uuid, 'run' => ['model_version' => 'existing-model', 'default_lead_time_days' => 56,
        'attention_horizon_days' => 21, 'total_input_rows' => 1, 'total_excluded_rows' => 0, 'warning_count' => 0, 'error_count' => 0],
        'predictions' => [['shopify_product_id' => $f['members'][$quantity]['product']->shopify_id,
            'shopify_variant_id' => $f['members'][$quantity]['variant']->shopify_id, 'sku' => $f['members'][$quantity]['variant']->sku,
            'ignore' => false, 'attention_horizon_days' => 21, 'lead_time_days_used' => 56, 'lead_time_source' => 'GLOBAL_DEFAULT',
            'currently_on_sale' => false, 'action_status' => 'NO_ACTION', 'generated_at' => now()->toIso8601String()]]];
}

it('accepts an ordinary source forecast without weighted-family metadata but rejects member forecasts', function () {
    $f = sharedBeadFamily(active: true);
    $saved = app(ProcurementPredictionIngestService::class)->persist(ordinarySourceForecastPayload($f, 1));
    expect($saved->total_prediction_rows)->toBe(1);
    foreach ([2, 3, 4] as $q) {
        expect(fn () => app(ProcurementPredictionIngestService::class)->persist(ordinarySourceForecastPayload($f, $q)))
            ->toThrow(ValidationException::class);
    }
});
