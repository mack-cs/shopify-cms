<?php

use App\Jobs\InventorySyncJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\ProductInventoryCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a pending single SKU inventory approval without changing Shopify', function (): void {
    Bus::fake();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $variant = inventoryApprovalVariant('APPROVAL-SINGLE', 4);

    $request = app(InventoryAdjustmentApprovalService::class)->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Cycle count correction',
    ]], $requester->id, $approver->id);

    expect($request->status)->toBe(InventoryAdjustmentRequest::STATUS_PENDING_APPROVAL)
        ->and($request->items)->toHaveCount(1)
        ->and($request->items->first()->original_on_hand_quantity)->toBe(4)
        ->and($request->items->first()->requested_on_hand_quantity)->toBe(9)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(4);
    Bus::assertNotDispatched(InventorySyncJob::class);
});

it('requires a reason and approver and blocks self approval', function (): void {
    $user = User::factory()->create();
    $variant = inventoryApprovalVariant('APPROVAL-VALIDATION', 4);

    expect(fn () => app(InventoryAdjustmentApprovalService::class)->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => '',
    ]], $user->id, User::factory()->create()->id))->toThrow(ValidationException::class);

    expect(fn () => app(InventoryAdjustmentApprovalService::class)->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Correction',
    ]], $user->id, $user->id))->toThrow(ValidationException::class, 'Requester cannot approve their own inventory adjustment.');
});

it('approves and rejects inventory requests without duplicate requester approval', function (): void {
    Bus::fake();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $variant = inventoryApprovalVariant('APPROVAL-APPROVE', 4);
    $service = app(InventoryAdjustmentApprovalService::class);
    $request = $service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 12,
        'reason' => 'Stock take',
    ]], $requester->id, $approver->id);

    expect(fn () => $service->approve($request, $requester->id))->toThrow(ValidationException::class);

    $approved = $service->approve($request, $approver->id);
    expect($approved->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(12);
    Bus::assertDispatched(InventorySyncJob::class);

    $rejectRequest = $service->submit([[
        'variant' => $variant->fresh(),
        'inventory_tracked' => true,
        'on_hand_quantity' => 2,
        'reason' => 'Bad count',
    ]], $requester->id, $approver->id);
    $service->reject($rejectRequest, $approver->id, 'No evidence');
    expect($rejectRequest->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_REJECTED)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(12);
});

it('creates a file approval request with SKU-level reasons', function (): void {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    inventoryApprovalVariant('APPROVAL-FILE-1', 1);
    inventoryApprovalVariant('APPROVAL-FILE-2', 2);
    $path = tempnam(sys_get_temp_dir(), 'inventory-approval-');
    file_put_contents($path, "SKU,On Hand,Reason\nAPPROVAL-FILE-1,5,Count one\nAPPROVAL-FILE-2,6,Count two\n");

    $request = app(ProductInventoryCsvImporter::class)->createApprovalFromPath($path, $requester->id, $approver->id, 'inventory.csv');
    @unlink($path);

    expect($request->type)->toBe('file')
        ->and($request->items)->toHaveCount(2)
        ->and($request->items->pluck('reason')->all())->toBe(['Count one', 'Count two']);
});

function inventoryApprovalVariant(string $sku, int $quantity): Variant
{
    $user = User::factory()->create();
    $import = Import::query()->create(['filename' => 'inventory.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/'.$sku,
        'handle' => strtolower($sku),
        'title' => 'Product '.$sku,
        'vendor' => 'Leigh Avenue',
        'type' => 'Jewellery',
        'status' => 'active',
        'tags' => 'livi-road',
        'approval_version' => 1,
    ]));

    return Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/'.$sku,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$sku,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => true,
        'current_inventory_quantity' => $quantity,
        'current_available_quantity' => $quantity,
        'current_on_hand_quantity' => $quantity,
        'inventory_qty' => $quantity,
        'price' => 100,
    ]));
}
