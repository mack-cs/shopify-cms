<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewProductDraftSeeder;
use App\Services\StackCompareAtPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sets a new stack compare-at price from its component prices and refreshes it when a component price changes', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'stack-compare-at.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $first = Product::create([
        'import_id' => $import->id,
        'title' => 'Component 100',
        'handle' => 'component-100',
        'status' => 'active',
    ]);
    $second = Product::create([
        'import_id' => $import->id,
        'title' => 'Component 50',
        'handle' => 'component-50',
        'status' => 'active',
    ]);
    $stack = Product::create([
        'import_id' => $import->id,
        'title' => 'Stack',
        'handle' => 'stack-compare',
        'status' => 'active',
    ]);

    Variant::create(['product_id' => $first->id, 'sku' => 'COMP-100', 'price' => '100.00']);
    Variant::create(['product_id' => $second->id, 'sku' => 'COMP-50', 'price' => '49.50']);
    Variant::create(['product_id' => $stack->id, 'sku' => 'STACK-1', 'price' => '120.00', 'compare_at_price' => '10.00']);

    $firstDraft = NewProductDraft::create([
        'sku' => 'COMP-100',
        'handle' => 'component-100',
        'title' => 'Component 100',
        'status' => 'active',
        'variant_price' => '100.00',
    ]);
    NewProductDraft::create([
        'sku' => 'COMP-50',
        'handle' => 'component-50',
        'title' => 'Component 50',
        'status' => 'active',
        'variant_price' => '49.50',
    ]);
    $stackDraft = NewProductDraft::create([
        'sku' => 'STACK-1',
        'handle' => 'stack-compare',
        'title' => 'Stack',
        'status' => 'active',
        'variant_price' => '120.00',
        'variant_compare_at_price' => '10.00',
        'bundle_product_ids' => [$first->id, $second->id],
    ]);

    expect($stackDraft->fresh()->variant_compare_at_price)->toEqual('149.50')
        ->and($stack->variants()->first()->compare_at_price)->toEqual('149.50')
        ->and($stack->variants()->first()->price)->toEqual('120.00');

    $firstDraft->variant_price = '80.00';
    $firstDraft->save();

    expect($stackDraft->fresh()->variant_compare_at_price)->toEqual('129.50')
        ->and($stack->variants()->first()->fresh()->compare_at_price)->toEqual('129.50');

    $stackDraft->fresh()->forceFill(['title' => 'Stack renamed'])->save();

    expect($stackDraft->fresh()->variant_compare_at_price)->toEqual('129.50')
        ->and($stackDraft->fresh()->title)->toBe('Stack renamed');
});

it('writes an already calculated stack compare-at price onto the product and clears the clash', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'stack-compare-at.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $component = Product::create([
        'import_id' => $import->id,
        'title' => 'Component',
        'handle' => 'component-ready',
        'status' => 'active',
    ]);
    $stack = Product::create([
        'import_id' => $import->id,
        'title' => 'Stack',
        'handle' => 'stack-ready',
        'status' => 'active',
        'shopify_id' => 'gid://shopify/Product/stack-ready',
    ]);
    Variant::create(['product_id' => $component->id, 'sku' => 'COMP-READY', 'price' => '80.00']);
    $stackVariant = Variant::create([
        'product_id' => $stack->id,
        'sku' => 'STACK-READY',
        'shopify_id' => 'gid://shopify/ProductVariant/stack-ready',
        'price' => '50.00',
        'compare_at_price' => '10.00',
        'sync_state' => Variant::SYNC_STATE_CONFLICT,
    ]);

    $stackDraft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK-READY',
        'handle' => 'stack-ready',
        'shopify_id' => 'gid://shopify/Product/stack-ready',
        'title' => 'Stack',
        'status' => 'active',
        'variant_price' => '50.00',
        'variant_compare_at_price' => '80.00',
        'bundle_product_ids' => [$component->id],
        'shopify_sync_warnings' => [[
            'field' => 'variant_compare_at_price',
            'label' => 'Compare-at price',
            'draft_value' => '80.00',
            'shopify_value' => '10.00',
        ], [
            'field' => 'title',
            'label' => 'Title',
            'draft_value' => 'Stack',
            'shopify_value' => 'Old stack',
        ]],
    ]));

    $result = app(StackCompareAtPriceService::class)->applyToDraft($stackDraft);

    expect($result['updated'])->toBeTrue()
        ->and($stackDraft->fresh()->variant_compare_at_price)->toEqual('80.00')
        ->and($stackVariant->fresh()->compare_at_price)->toEqual('80.00')
        ->and($stackVariant->fresh()->price)->toEqual('50.00')
        ->and($stackVariant->fresh()->sync_state)->toEqual(Variant::SYNC_STATE_LOCAL_UPDATED)
        ->and(collect($stackDraft->fresh()->shopifySyncWarnings())->pluck('field')->all())->toBe(['title']);
});

it('does not record a compare-at clash when a stack is refreshed from its product', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'stack-compare-at.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $component = Product::create([
        'import_id' => $import->id,
        'title' => 'Component',
        'handle' => 'component-seed',
        'status' => 'active',
    ]);
    $stack = Product::create([
        'import_id' => $import->id,
        'title' => 'Stack',
        'handle' => 'stack-seed',
        'status' => 'active',
        'shopify_id' => 'gid://shopify/Product/stack-seed',
    ]);
    Variant::create(['product_id' => $component->id, 'sku' => 'COMP-SEED', 'price' => '80.00']);
    Variant::create([
        'product_id' => $stack->id,
        'sku' => 'STACK-SEED',
        'price' => '50.00',
        'compare_at_price' => '10.00',
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK-SEED',
        'handle' => 'stack-seed',
        'shopify_id' => 'gid://shopify/Product/stack-seed',
        'title' => 'Stack',
        'status' => 'active',
        'variant_price' => '50.00',
        'variant_compare_at_price' => '80.00',
        'bundle_product_ids' => [$component->id],
        'shopify_sync_warnings' => [[
            'field' => 'variant_compare_at_price',
            'label' => 'Compare-at price',
            'draft_value' => '80.00',
            'shopify_value' => '10.00',
        ]],
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($stack);

    expect($seeded->variant_compare_at_price)->toEqual('80.00')
        ->and(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->not->toContain('variant_compare_at_price');
});
