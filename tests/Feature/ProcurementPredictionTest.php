<?php

use App\Filament\Exports\ProcurementPredictionExporter;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Filament\Resources\ProcurementPredictionResource\Pages\ListProcurementPredictions;
use App\Jobs\RunProcurementPipelineJob;
use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use App\Models\ProductMovementReportRow;
use App\Models\ProductMovementReportRun;
use App\Models\User;
use App\Services\ProcurementPipelineService;
use App\Services\ProcurementPredictionIngestService;
use App\Services\ProcurementPythonRunner;
use App\Services\ProductMovementReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('serves only eligible rows from a completed daily Product Movement snapshot in the stable ML shape', function (): void {
    config(['shopify_sync.analytics_export_token' => 'movement-token']);
    $run = movementSnapshot();
    movementRow($run, ['sku' => 'FAST1', 'movement_classification' => 'fast_moving']);
    movementRow($run, ['sku' => 'ARCH1', 'product_status' => 'archived']);
    movementRow($run, ['sku' => 'STACK1', 'movement_product_kind' => 'stack']);

    $response = $this->withToken('movement-token')->get('/api/analytics/product-movement.csv?run_id=' . $run->id);

    $response->assertOk();
    $csv = $response->streamedContent();
    expect($csv)
        ->toContain('shopify_variant_id')
        ->toContain('analysis_days')
        ->toContain('FAST_MOVING')
        ->toContain('FAST1')
        ->not->toContain('ARCH1')
        ->not->toContain('STACK1');
    expect(data_get($run->fresh()?->settings, 'ml_export.eligible_rows'))->toBe(1)
        ->and(data_get($run->fresh()?->settings, 'ml_export.excluded_rows'))->toBe(2);
});

it('creates only one daily movement and procurement run when the same date is queued again', function (): void {
    Queue::fake();
    $service = app(ProcurementPipelineService::class);

    $first = $service->queue('2026-08-04');
    $second = $service->queue('2026-08-04');

    expect($first['queued'])->toBeTrue()
        ->and($second['queued'])->toBeFalse()
        ->and($second['run']->id)->toBe($first['run']->id)
        ->and(ProductMovementReportRun::query()->whereDate('calculation_date', '2026-08-04')->count())->toBe(1)
        ->and(ProcurementPredictionRun::query()->whereDate('calculation_date', '2026-08-04')->count())->toBe(1);
    Queue::assertPushed(RunProcurementPipelineJob::class, 1);
});

it('can explicitly requeue a completed daily procurement run', function (): void {
    Queue::fake();
    $service = app(ProcurementPipelineService::class);
    $first = $service->queue('2026-08-04');
    $first['run']->forceFill([
        'status' => ProcurementPredictionRun::STATUS_COMPLETED,
        'completed_at' => now(),
    ])->save();

    $recalculation = $service->queue('2026-08-04', forceRecalculation: true);

    expect($recalculation['queued'])->toBeTrue()
        ->and($recalculation['run']->id)->toBe($first['run']->id)
        ->and($recalculation['run']->status)->toBe(ProcurementPredictionRun::STATUS_QUEUED)
        ->and($recalculation['run']->completed_at)->toBeNull();
    Queue::assertPushed(RunProcurementPipelineJob::class, 2);
});

it('persists a complete prediction run atomically and treats a repeated successful submission as idempotent', function (): void {
    $run = predictionRun();
    $payload = predictionPayload($run);

    $saved = app(ProcurementPredictionIngestService::class)->persist($payload);
    $again = app(ProcurementPredictionIngestService::class)->persist($payload);

    expect($saved->status)->toBe(ProcurementPredictionRun::STATUS_COMPLETED)
        ->and($again->id)->toBe($saved->id)
        ->and($saved->total_prediction_rows)->toBe(1)
        ->and($saved->model_version)->toBe('ridge_regression-phase1')
        ->and($saved->product_movement_source_version)->toBe('product-movement-v2')
        ->and(data_get($saved->metadata, 'stack_demand_source'))->toBe('PYTHON_ORDER_LINE_EXPANSION')
        ->and(ProcurementPrediction::query()->where('procurement_prediction_run_id', $run->id)->count())->toBe(1);

    $prediction = ProcurementPrediction::query()
        ->where('procurement_prediction_run_id', $run->id)
        ->firstOrFail();
    expect($prediction->product_name)->toBe('Product One')
        ->and($prediction->vendor)->toBe('Vendor')
        ->and((float) $prediction->average_weekly_demand)->toBe(2.5)
        ->and($prediction->current_inventory)->toBe(5)
        ->and($prediction->predicted_runout_date?->toDateString())->toBe('2026-08-16')
        ->and($prediction->preliminary_order_quantity)->toBe(25)
        ->and($prediction->action_reason)->toBe('Stock does not cover lead time.');
});

it('rolls back prediction replacement when persistence fails', function (): void {
    $run = predictionRun();
    ProcurementPrediction::query()->create(array_merge(predictionPayload($run)['predictions'][0], [
        'procurement_prediction_run_id' => $run->id,
    ]));
    $trigger = DB::getDriverName() === 'mysql'
        ? "CREATE TRIGGER fail_procurement_prediction_insert AFTER DELETE ON procurement_predictions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced persistence failure'"
        : "CREATE TRIGGER fail_procurement_prediction_insert AFTER DELETE ON procurement_predictions BEGIN SELECT RAISE(ABORT, 'forced persistence failure'); END";
    DB::statement($trigger);
    $payload = predictionPayload($run);
    $expectedException = DB::getDriverName() === 'mysql'
        ? \PDOException::class
        : \Illuminate\Database\QueryException::class;

    try {
        expect(fn () => app(ProcurementPredictionIngestService::class)->persist($payload))
            ->toThrow($expectedException);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS fail_procurement_prediction_insert');
    }

    expect(ProcurementPrediction::query()->where('procurement_prediction_run_id', $run->id)->count())->toBe(1)
        ->and($run->fresh()?->status)->toBe(ProcurementPredictionRun::STATUS_RUNNING);
});

it('does not allow a newer failed run to replace the latest successful report', function (): void {
    $successful = predictionRun('2026-08-03', ProcurementPredictionRun::STATUS_COMPLETED);
    predictionRun('2026-08-04', ProcurementPredictionRun::STATUS_FAILED);

    $latest = ProcurementPredictionRun::query()
        ->where('status', ProcurementPredictionRun::STATUS_COMPLETED)
        ->latest('calculation_date')
        ->first();

    expect($latest?->id)->toBe($successful->id);
});

it('completes the queued orchestration only after the Python publisher atomically stores rows', function (): void {
    $movement = movementSnapshot();
    $run = predictionRun();
    $run->forceFill(['product_movement_report_run_id' => $movement->id])->save();
    $runner = Mockery::mock(ProcurementPythonRunner::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(function (ProcurementPredictionRun $active): void {
        app(ProcurementPredictionIngestService::class)->persist(predictionPayload($active));
    });

    (new RunProcurementPipelineJob($run->id))->handle($runner);

    expect($run->fresh()?->status)->toBe(ProcurementPredictionRun::STATUS_COMPLETED)
        ->and($run->fresh()?->total_prediction_rows)->toBe(1);
});

it('records a bounded useful message when the Python process produces very large error output', function (): void {
    $movement = movementSnapshot();
    $run = predictionRun();
    $run->forceFill(['product_movement_report_run_id' => $movement->id])->save();
    $runner = Mockery::mock(ProcurementPythonRunner::class);
    $runner->shouldReceive('run')->once()->andThrow(new RuntimeException(
        str_repeat('warning output ', 6000) . "\nKeyError: predicted_runout_date"
    ));

    try {
        (new RunProcurementPipelineJob($run->id))->handle($runner);
        $this->fail('The pipeline job should rethrow the Python failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('KeyError: predicted_runout_date');
    }

    $failed = $run->fresh();
    expect($failed?->status)->toBe(ProcurementPredictionRun::STATUS_FAILED)
        ->and($failed?->error_message)->toContain('output truncated')
        ->and($failed?->error_message)->toContain('KeyError: predicted_runout_date')
        ->and(mb_strlen((string) $failed?->error_message))->toBeLessThanOrEqual(12000);
});

it('exports the business report fields identifiers demand methods and run metadata', function (): void {
    $columns = collect(ProcurementPredictionExporter::getColumns())->map(fn ($column) => $column->getName());

    expect($columns)->toContain(
        'sku',
        'preliminary_order_quantity',
        'shopify_variant_id',
        'selected_prediction_method',
        'lead_time_days_used',
        'attention_horizon_days',
        'data_quality_warning',
        'run.run_uuid',
        'run.product_movement_generated_at',
    );
});

it('lets managers filter the latest successful procurement report by action status', function (): void {
    Role::findOrCreate(RolesEnum::Admin->value);
    $user = User::factory()->create();
    $user->assignRole(RolesEnum::Admin->value);
    $user->givePermissionTo(PermissionEnum::ManagerReportAccess->value);
    $this->actingAs($user);

    $run = predictionRun('2026-08-04', ProcurementPredictionRun::STATUS_COMPLETED);
    $first = ProcurementPrediction::query()->create(array_merge(predictionPayload($run)['predictions'][0], [
        'procurement_prediction_run_id' => $run->id,
    ]));
    $second = $first->replicate()->forceFill([
        'shopify_variant_id' => 'gid://shopify/ProductVariant/2',
        'sku' => 'SKU2',
        'action_status' => 'NO_ACTION',
    ]);
    $second->save();
    $run->forceFill(['total_prediction_rows' => 2, 'model_version' => 'ridge-phase1'])->save();

    Livewire::test(ListProcurementPredictions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$first, $second])
        ->filterTable('action_status', 'ORDER_NOW')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);
});

function movementSnapshot(): ProductMovementReportRun
{
    return ProductMovementReportRun::query()->create([
        'calculation_date' => '2026-08-04',
        'analysis_start_date' => '2026-02-05',
        'analysis_end_date' => '2026-08-04',
        'months_analysed' => 6,
        'status' => ProductMovementReportRun::STATUS_COMPLETED,
        'row_count' => 3,
        'completed_at' => '2026-08-04 06:00:00',
        'source_data_timestamp' => '2026-08-04 05:59:00',
        'duration_ms' => 1000,
        'source_version' => 'product-movement-v2',
        'settings' => [],
    ]);
}

function movementRow(ProductMovementReportRun $run, array $overrides = []): ProductMovementReportRow
{
    $sku = $overrides['sku'] ?? 'SKU1';
    return ProductMovementReportRow::query()->create(array_merge([
        'product_movement_report_run_id' => $run->id,
        'shopify_product_id' => 'gid://shopify/Product/' . $sku,
        'shopify_variant_id' => 'gid://shopify/ProductVariant/' . $sku,
        'product_title' => "Product {$sku}",
        'variant_title' => 'Default',
        'sku' => $sku,
        'vendor' => 'Vendor',
        'product_type' => 'Bracelet',
        'product_status' => 'active',
        'variant_status' => 'active',
        'movement_product_kind' => 'standard',
        'analysis_start_date' => '2026-02-05',
        'analysis_end_date' => '2026-08-04',
        'months_analysed' => 6,
        'gross_units_sold' => 12,
        'refunded_units' => 1,
        'net_units_sold' => 11,
        'direct_net_units_sold' => 11,
        'average_units_per_30_days' => 2,
        'movement_score' => 80,
        'movement_classification' => 'medium_moving',
        'inventory_tracked' => true,
        'current_inventory' => 5,
        'currently_on_sale' => false,
    ], $overrides));
}

function predictionRun(
    string $date = '2026-08-04',
    string $status = ProcurementPredictionRun::STATUS_RUNNING,
): ProcurementPredictionRun {
    return ProcurementPredictionRun::query()->create([
        'run_uuid' => (string) Str::uuid(),
        'calculation_date' => $date,
        'status' => $status,
        'default_lead_time_days' => 56,
        'attention_horizon_days' => 21,
        'started_at' => now(),
        'completed_at' => $status === ProcurementPredictionRun::STATUS_COMPLETED ? now() : null,
    ]);
}

function predictionPayload(ProcurementPredictionRun $run): array
{
    return [
        'run_uuid' => $run->run_uuid,
        'run' => [
            'product_movement_generated_at' => '2026-08-04T06:00:00+02:00',
            'product_movement_source_version' => 'product-movement-v2',
            'model_version' => 'ridge_regression-phase1',
            'selected_model_information' => ['selected_forecast_method' => 'weighted_recent_demand'],
            'default_lead_time_days' => 56,
            'attention_horizon_days' => 21,
            'total_input_rows' => 1,
            'total_excluded_rows' => 0,
            'warning_count' => 0,
            'error_count' => 0,
            'metadata' => ['stack_demand_source' => 'PYTHON_ORDER_LINE_EXPANSION'],
        ],
        'predictions' => [[
            'shopify_product_id' => 'gid://shopify/Product/1',
            'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
            'sku' => 'SKU1',
            'product_name' => 'Product One',
            'variant_name' => 'Default',
            'vendor' => 'Vendor',
            'cms_movement_classification' => 'FAST_MOVING',
            'net_units_sold' => 10,
            'average_weekly_demand' => 2.5,
            'predicted_weekly_demand' => 3,
            'selected_prediction_method' => 'weighted_recent_demand',
            'current_inventory' => 5,
            'ignore' => false,
            'attention_horizon_days' => 21,
            'lead_time_days_used' => 56,
            'lead_time_source' => 'GLOBAL_DEFAULT',
            'estimated_days_of_stock_remaining' => 12,
            'predicted_runout_date' => '2026-08-16',
            'stock_required_for_attention_horizon' => 9,
            'stock_required_for_lead_time' => 24,
            'preliminary_order_quantity' => 25,
            'currently_on_sale' => false,
            'action_status' => 'ORDER_NOW',
            'action_reason' => 'Stock does not cover lead time.',
            'data_quality_status' => 'OK',
            'data_quality_warning' => null,
            'generated_at' => '2026-08-04T07:00:00+02:00',
        ]],
    ];
}
