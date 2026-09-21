<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewProductDraftProductSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults new drafts to 46 grams and quantity 40 while allowing overrides', function (): void {
    $defaulted = NewProductDraft::create([
        'title' => 'Defaulted Draft',
        'status' => 'draft',
    ]);

    $overridden = NewProductDraft::create([
        'title' => 'Overridden Draft',
        'status' => 'draft',
        'variant_inventory_qty' => 12,
        'variant_weight' => '55.5',
        'variant_weight_unit' => 'kg',
        'material_cost' => '72.5',
    ]);

    $shopifySeeded = NewProductDraft::create([
        'title' => 'Shopify Seeded Draft',
        'status' => 'active',
        'origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED,
    ]);

    expect($defaulted->variant_inventory_qty)->toBe(40)
        ->and($defaulted->variant_weight)->toBe('46.000')
        ->and($defaulted->variant_weight_unit)->toBe('g')
        ->and($defaulted->material_cost)->toBe('63.00')
        ->and($overridden->variant_inventory_qty)->toBe(12)
        ->and($overridden->variant_weight)->toBe('55.500')
        ->and($overridden->variant_weight_unit)->toBe('kg')
        ->and($overridden->material_cost)->toBe('72.50')
        ->and($shopifySeeded->variant_inventory_qty)->toBeNull()
        ->and($shopifySeeded->variant_weight)->toBeNull()
        ->and($shopifySeeded->variant_weight_unit)->toBeNull()
        ->and($shopifySeeded->material_cost)->toBe('63.00');
});

it('mirrors new draft quantity and weight defaults to an existing product variant', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'draft-defaults.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'Defaulted Product',
        'handle' => 'defaulted-product',
        'status' => 'draft',
    ]);
    $variant = Variant::create([
        'product_id' => $product->id,
        'sku' => 'DEFAULT-001',
        'inventory_qty' => 0,
        'weight' => '1.000',
        'weight_unit' => 'g',
    ]);

    $draft = NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'sku' => $variant->sku,
        'status' => 'draft',
    ]);

    app(NewProductDraftProductSync::class)->syncToExistingProduct($draft);
    $variant->refresh();

    expect($variant->inventory_qty)->toBe(40)
        ->and($variant->weight)->toBe('46.000')
        ->and($variant->weight_unit)->toBe('g');
});
