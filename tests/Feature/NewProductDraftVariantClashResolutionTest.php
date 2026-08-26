<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Services\HeaderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses the latest shopify variant values to clear a draft variant clash', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'variant-conflict.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'Conflict Bracelet',
        'handle' => 'conflict-bracelet',
        'status' => 'active',
    ]);
    $variant = Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/501',
        'sku' => 'LOCAL-501',
        'price' => '199.00',
        'inventory_tracked' => true,
        'inventory_qty' => 12,
        'weight' => '30.000',
        'weight_unit' => 'g',
    ]);
    $draft = NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'sku' => 'DRAFT-501',
        'variant_price' => '250.00',
        'variant_inventory_qty' => 40,
        'variant_weight' => '46.000',
        'variant_weight_unit' => 'g',
        'status' => 'active',
    ]);

    Variant::withoutEvents(function () use ($variant): void {
        $variant->forceFill([
            'sku' => 'LOCAL-501',
            'price' => '199.00',
            'inventory_tracked' => true,
            'inventory_qty' => 12,
            'weight' => '30.000',
            'weight_unit' => 'g',
            'sync_state' => Variant::SYNC_STATE_CONFLICT,
            'local_dirty' => true,
        ])->save();
    });

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'variant',
        'variant_key' => 'SHOPIFY-501',
        'data' => [
            HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID => $variant->shopify_id,
            HeaderStore::VARIANT_SKU => 'SHOPIFY-501',
            HeaderStore::VARIANT_PRICE => '175.00',
            HeaderStore::VARIANT_COMPARE_AT => '225.00',
            HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED => 'true',
            HeaderStore::VARIANT_INVENTORY_QTY => '25',
            HeaderStore::VARIANT_GRAMS => '46',
            HeaderStore::VARIANT_WEIGHT_UNIT => 'g',
        ],
    ]);

    $result = NewProductDraftResource::resolveVariantClashUsingShopify($draft->fresh());

    $variant->refresh();
    $draft->refresh();

    expect($result['resolved'])->toBeTrue()
        ->and($variant->sync_state)->toBe(Variant::SYNC_STATE_SYNCED)
        ->and($variant->local_dirty)->toBeFalse()
        ->and($variant->sku)->toBe('SHOPIFY-501')
        ->and($variant->price)->toBe('175.00')
        ->and($variant->inventory_qty)->toBe(25)
        ->and($draft->sku)->toBe('SHOPIFY-501')
        ->and($draft->variant_price)->toBe('175.00')
        ->and($draft->variant_compare_at_price)->toBe('225.00')
        ->and($draft->variant_inventory_qty)->toBe(25)
        ->and($draft->variant_weight)->toBe('46.000')
        ->and($draft->variant_weight_unit)->toBe('g');
});

it('does not show a clash when a new product import has only blank or zero variant placeholders', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'new-product-placeholders.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'New Bracelet',
        'handle' => 'new-bracelet',
        'status' => 'draft',
    ]);
    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/502',
        'sku' => null,
        'price' => '0.00',
        'inventory_tracked' => true,
        'inventory_qty' => null,
        'weight' => null,
        'weight_unit' => null,
    ]));
    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'sku' => 'NEW-502',
        'variant_price' => '600.00',
        'variant_inventory_qty' => 15,
        'variant_weight' => '46.000',
        'variant_weight_unit' => 'g',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $method = new ReflectionMethod(NewProductDraftResource::class, 'draftVariantClashes');

    expect($method->invoke(null, $draft->fresh()))->toBe([]);
});

it('does not expose stored blank or zero new product placeholders as shopify warnings', function (): void {
    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'title' => 'Stored Placeholder Bracelet',
        'handle' => 'stored-placeholder-bracelet',
        'sku' => 'NEW-503',
        'variant_price' => '600.00',
        'variant_inventory_qty' => 15,
        'variant_weight' => '46.000',
        'variant_weight_unit' => 'g',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'shopify_sync_warnings' => [
            [
                'field' => 'variant_price',
                'label' => 'Price',
                'draft_value' => '600.00',
                'shopify_value' => '0.00',
            ],
            [
                'field' => 'sku',
                'label' => 'SKU',
                'draft_value' => 'NEW-503',
                'shopify_value' => '',
            ],
            [
                'field' => 'vendor',
                'label' => 'Vendor',
                'draft_value' => 'Draft Vendor',
                'shopify_value' => 'Shopify Vendor',
            ],
        ],
    ]));

    expect(collect($draft->shopifySyncWarnings())->pluck('field')->all())
        ->toBe(['vendor'])
        ->and($draft->shopifySyncWarningCount())->toBe(1);

    $draft->forceFill(['origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED]);

    expect(collect($draft->shopifySyncWarnings())->pluck('field')->all())
        ->toContain('variant_price', 'sku', 'vendor')
        ->and($draft->shopifySyncWarningCount())->toBe(3);
});
