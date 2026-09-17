<?php

use App\Models\Import;
use App\Models\ManualStockTransaction;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;
use App\Models\User;
use App\Services\ManualStockTransactionService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function manualStockProduct(Import $import, string $title, string $sku, int $remoteId): Product
{
    $product = Product::create(['import_id' => $import->id, 'shopify_id' => "gid://shopify/Product/{$remoteId}",
        'handle' => str($title)->slug(), 'title' => $title, 'status' => 'active']);
    Variant::create(['product_id' => $product->id, 'sku' => $sku, 'shopify_inventory_item_id' => "gid://shopify/InventoryItem/{$remoteId}",
        'sync_state' => Variant::SYNC_STATE_SYNCED, 'inventory_tracked' => true]);
    return $product;
}

function historicalTransaction(array $lines): ManualStockTransaction
{
    $transaction = ManualStockTransaction::create(['transaction_type' => 'direct_order', 'processing_mode' => ManualStockTransaction::MODE_HISTORICAL,
        'recipient_name' => 'Test recipient', 'reference_number' => 'TEST-1', 'transaction_date' => now(), 'is_historical' => true]);
    foreach ($lines as [$product, $quantity]) $transaction->items()->create(['product_id' => $product->id, 'product_title' => $product->title, 'quantity' => $quantity]);
    return $transaction;
}

function manualStockImport(string $filename): Import
{
    return Import::create(['filename' => $filename, 'mode' => 'overwrite', 'status' => 'ready', 'is_current' => true,
        'is_valid' => true, 'created_by' => User::factory()->create()->id]);
}

it('records historical transactions without calling Shopify', function (): void {
    $import = manualStockImport('manual-stock');
    $product = manualStockProduct($import, 'Direct Bracelet', 'DB1', 101);
    $shopify = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $shopify->shouldNotReceive('currentQuantities', 'decreaseOnHand');
    $service = new ManualStockTransactionService($shopify);

    $transaction = $service->prepare(historicalTransaction([[$product, 3]]));
    $result = $service->process($transaction);

    expect($result->status)->toBe('completed')
        ->and($result->inventory_update_status)->toBe('historical_no_adjustment')
        ->and($result->impacts)->toHaveCount(1)
        ->and($result->impacts->first()->quantity_required)->toBe(3);
});

it('expands stacks and aggregates shared component quantities', function (): void {
    $import = manualStockImport('manual-stack');
    $component = manualStockProduct($import, 'Shared Component', 'COMP1', 201);
    $stackA = manualStockProduct($import, 'Stack A', 'STACKA', 202);
    $stackB = manualStockProduct($import, 'Stack B', 'STACKB', 203);
    foreach ([[$stackA, 2], [$stackB, 3]] as [$stack, $each]) {
        NewProductDraft::create(['title' => $stack->title, 'handle' => $stack->handle, 'shopify_id' => $stack->shopify_id, 'sku' => $stack->variants()->value('sku'),
            'bundle_product_ids' => [$component->id], 'bundle_component_quantities' => [['product_id' => $component->id, 'quantity' => $each]]]);
    }
    $service = new ManualStockTransactionService(Mockery::mock(ShopifyInventoryAdjustmentService::class));
    $result = $service->prepare(historicalTransaction([[$stackA, 4], [$stackB, 2]]));

    expect($result->impacts)->toHaveCount(1)
        ->and($result->impacts->first()->sku)->toBe('COMP1')
        ->and($result->impacts->first()->quantity_required)->toBe(14)
        ->and($result->impacts->first()->sources)->toHaveCount(2);
});

it('is idempotent when a completed historical transaction is processed twice', function (): void {
    $import = manualStockImport('manual-idempotent');
    $product = manualStockProduct($import, 'Gift', 'GIFT1', 301);
    $service = new ManualStockTransactionService(Mockery::mock(ShopifyInventoryAdjustmentService::class));
    $transaction = $service->process($service->prepare(historicalTransaction([[$product, 1]])));
    $again = $service->process($transaction);
    expect($again->status)->toBe('completed')->and($again->impacts)->toHaveCount(1);
});

it('preflights available stock and deducts Shopify on hand exactly once', function (): void {
    $product = manualStockProduct(manualStockImport('manual-live'), 'Live Gift', 'LIVE1', 401);
    $transaction = ManualStockTransaction::create(['transaction_type' => 'complimentary', 'processing_mode' => ManualStockTransaction::MODE_PROCESS,
        'recipient_name' => 'Recipient', 'transaction_date' => now()]);
    $transaction->items()->create(['product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 2]);
    $shopify = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $shopify->shouldReceive('currentQuantities')->times(3)->andReturn(
        ['location_id' => 'gid://shopify/Location/1', 'available' => 5, 'on_hand' => 7, 'committed' => 2],
        ['location_id' => 'gid://shopify/Location/1', 'available' => 5, 'on_hand' => 7, 'committed' => 2],
        ['location_id' => 'gid://shopify/Location/1', 'available' => 3, 'on_hand' => 5, 'committed' => 2],
    );
    $shopify->shouldReceive('decreaseOnHand')->once()->withArgs(fn ($variant, $qty) => $variant->sku === 'LIVE1' && $qty === 2)->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = new ManualStockTransactionService($shopify);
    $result = $service->process($service->prepare($transaction));
    $again = $service->process($result);

    expect($result->status)->toBe('completed')->and($again->status)->toBe('completed')
        ->and($result->impacts->first()->on_hand_after)->toBe(5)
        ->and($product->variants()->first()->fresh()->current_on_hand_quantity)->toBe(5);
});

it('blocks the entire transaction when current Shopify available stock is insufficient', function (): void {
    $product = manualStockProduct(manualStockImport('manual-short'), 'Short Product', 'SHORT1', 501);
    $transaction = ManualStockTransaction::create(['transaction_type' => 'other', 'processing_mode' => ManualStockTransaction::MODE_PROCESS,
        'recipient_name' => 'Test', 'transaction_date' => now()]);
    $transaction->items()->create(['product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 4]);
    $shopify = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $shopify->shouldReceive('currentQuantities')->once()->andReturn(['location_id' => 'loc-1', 'available' => 2, 'on_hand' => 2, 'committed' => 0]);
    $shopify->shouldNotReceive('decreaseOnHand');
    $result = (new ManualStockTransactionService($shopify))->prepare($transaction);
    expect($result->status)->toBe('needs_attention')->and($result->last_error)->toContain('SHORT1 needs 4; 2 available');
});

it('records a Shopify failure per impact without marking the transaction completed', function (): void {
    $import = manualStockImport('manual-partial');
    $first = manualStockProduct($import, 'First Product', 'FIRST1', 601);
    $second = manualStockProduct($import, 'Second Product', 'SECOND1', 602);
    $transaction = ManualStockTransaction::create(['transaction_type' => 'direct_order', 'processing_mode' => ManualStockTransaction::MODE_PROCESS,
        'recipient_name' => 'Test', 'transaction_date' => now()]);
    foreach ([[$first, 1], [$second, 1]] as [$product, $quantity]) $transaction->items()->create(['product_id' => $product->id, 'product_title' => $product->title, 'quantity' => $quantity]);
    $shopify = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $shopify->shouldReceive('currentQuantities')->andReturn(['location_id' => 'loc-1', 'available' => 10, 'on_hand' => 10, 'committed' => 0]);
    $shopify->shouldReceive('decreaseOnHand')->withArgs(fn ($variant) => $variant->sku === 'FIRST1')->once()->andReturn(['createdAt' => 'now']);
    $shopify->shouldReceive('decreaseOnHand')->withArgs(fn ($variant) => $variant->sku === 'SECOND1')->once()->andThrow(new RuntimeException('Shopify unavailable'));
    $result = (new ManualStockTransactionService($shopify))->process((new ManualStockTransactionService($shopify))->prepare($transaction));
    expect($result->status)->toBe('partially_failed')
        ->and($result->impacts->where('status', 'completed'))->toHaveCount(1)
        ->and($result->impacts->where('status', 'failed'))->toHaveCount(1);
});
