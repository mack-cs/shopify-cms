<?php

use App\Models\InventoryPipelineAlert;
use App\Models\InventoryPipelineIncident;
use App\Models\ShopifyFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Real commits are needed here. The shared TestCase enforces sqlite::memory:.
    // Avoid unrelated legacy down() migrations: rebuild only this ephemeral database.
    $this->artisan('migrate:fresh')->assertExitCode(0);
    config(['inventory_monitor.enabled' => true]);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('retains committed failure and recovery transitions between health scans', function () {
    $fulfillment = DB::transaction(fn () => ShopifyFulfillment::create([
        'shopify_fulfillment_id' => '7001', 'shopify_order_id' => '5855', 'shopify_status' => 'success',
        'processing_status' => 'failed', 'payload' => [], 'error_message' => 'Temporary failure',
    ]));
    DB::transaction(fn () => $fulfillment->update(['processing_status' => 'completed', 'error_message' => null]));
    expect(InventoryPipelineIncident::sole()->resolved_at)->not->toBeNull()
        ->and(InventoryPipelineAlert::orderBy('id')->pluck('type')->all())->toBe(['failure', 'recovery']);
});

it('does not report a transition rolled back with its pipeline transaction', function () {
    try {
        DB::transaction(function () {
            ShopifyFulfillment::create(['shopify_fulfillment_id' => '7002', 'shopify_order_id' => '5855',
                'shopify_status' => 'success', 'processing_status' => 'failed', 'payload' => [], 'error_message' => 'Rolled back']);
            throw new RuntimeException('Abort');
        });
    } catch (RuntimeException) {
    }
    expect(InventoryPipelineIncident::count())->toBe(0)->and(ShopifyFulfillment::count())->toBe(0);
});
