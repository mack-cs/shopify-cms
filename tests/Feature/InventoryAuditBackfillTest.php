<?php

use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustmentRequestItem;
use App\Models\InventoryOperationAudit;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\User;
use App\Models\Variant;
use App\Services\Inventory\InventoryAuditBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('copies old inventory records into the audit log once and leaves stock records unchanged', function (): void {
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    [$variant] = backfillCatalog($user);
    $when = now()->subDays(10);
    $order = ProcurementSupplierOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'order_number' => 'LA2609',
        'created_by' => $user->id,
    ]);
    $line = ProcurementSupplierOrderLine::query()->create([
        'supplier_order_id' => $order->id,
        'variant_id' => $variant->id,
        'sku' => $variant->sku,
        'quantity_ordered' => 4,
    ]);
    $receipt = ProcurementSupplierReceipt::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_order_line_id' => $line->id,
        'quantity_received' => 4,
        'idempotency_key' => (string) Str::uuid(),
        'shopify_reference_uri' => 'logistics://shopify-editor/procurement-receipt/'.Str::uuid(),
        'status' => 'succeeded',
        'created_by' => $user->id,
        'shopify_adjusted_at' => $when,
    ]);
    ProcurementSupplierReceipt::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_order_line_id' => $line->id,
        'quantity_received' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'shopify_reference_uri' => 'logistics://shopify-editor/procurement-receipt/'.Str::uuid(),
        'status' => 'pending',
        'created_by' => $user->id,
    ]);
    backfillStackMovement($variant);
    $request = InventoryAdjustmentRequest::query()->create([
        'uuid' => (string) Str::uuid(),
        'status' => InventoryAdjustmentRequest::STATUS_APPLIED,
        'requester_id' => $user->id,
        'sync_batch_id' => 'batch-old',
        'applied_at' => $when,
    ]);
    InventoryAdjustmentRequestItem::query()->create([
        'inventory_adjustment_request_id' => $request->id,
        'variant_id' => $variant->id,
        'sku' => $variant->sku,
        'original_on_hand_quantity' => 12,
        'requested_on_hand_quantity' => 4,
        'reason' => 'Count correction',
    ]);
    InventoryAdjustmentRequest::query()->create([
        'uuid' => (string) Str::uuid(),
        'status' => InventoryAdjustmentRequest::STATUS_APPLIED,
        'requester_id' => $user->id,
        'applied_at' => $when,
    ]);

    $first = app(InventoryAuditBackfill::class)->run();
    $second = app(InventoryAuditBackfill::class)->run();
    $receiptAudit = InventoryOperationAudit::query()->where('correlation_key', $receipt->shopify_reference_uri)->first();
    $correction = InventoryOperationAudit::query()->where('workflow', 'inventory_correction')->first();

    expect($first['inserted'])->toBe(3)
        ->and($second['inserted'])->toBe(0)
        ->and(InventoryOperationAudit::query()->count())->toBe(3)
        ->and($receiptAudit->actor_user_id)->toBe($user->id)
        ->and($receiptAudit->occurred_at->toDateString())->toBe($when->toDateString())
        ->and($receiptAudit->delta)->toBe(4)
        ->and($correction->correlation_key)->toBe('inventory-set:batch-old:'.$variant->id)
        ->and($correction->delta)->toBe(-8)
        ->and($correction->explanation)->toBe('Count correction')
        ->and($receipt->fresh()->quantity_received)->toBe(4)
        ->and($receipt->fresh()->status)->toBe('succeeded');
});

function backfillCatalog(User $user): array
{
    $import = Import::query()->create([
        'filename' => 'LA0544.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'handle' => 'la0544',
        'title' => 'Pearls by the Baltic Necklace',
        'status' => 'active',
        'shopify_id' => 'gid://shopify/Product/9110745546888',
    ]));
    $variant = Variant::query()->create([
        'product_id' => $product->id,
        'sku' => 'LA0544',
        'shopify_id' => 'gid://shopify/ProductVariant/46429192257672',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/48539807416456',
    ]);

    return [$variant, $product];
}

function backfillStackMovement(Variant $variant): void
{
    $reservation = ShopifyStackInventoryReservation::query()->create([
        'shopify_order_id' => 'gid://shopify/Order/1',
        'shopify_order_name' => '#1001',
        'shopify_order_line_item_id' => 'gid://shopify/LineItem/1',
        'stack_quantity_ordered' => 1,
        'configured_component_product_id' => $variant->product_id,
        'component_product_id' => $variant->product_id,
        'component_variant_id' => $variant->id,
        'component_sku' => $variant->sku,
        'component_title' => 'Component',
        'stack_sku' => 'STACK-1',
        'stack_title' => 'Sample stack',
        'component_quantity_per_stack' => 1,
        'total_component_quantity_required' => 1,
        'shopify_inventory_item_id' => $variant->shopify_inventory_item_id,
        'ledger_document_uri' => 'leighavenue-cms://stack-reservations/1',
        'status' => 'reserved',
    ]);
    ShopifyStackInventoryMovement::query()->create([
        'reservation_id' => $reservation->id,
        'event_key' => (string) Str::uuid(),
        'action' => ShopifyStackInventoryMovement::ACTION_RESERVE,
        'quantity' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'status' => ShopifyStackInventoryMovement::STATUS_COMPLETED,
        'processed_at' => now()->subDays(10),
    ]);
}
