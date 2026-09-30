<?php

use App\Models\DropdownOption;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Services\HeaderStore;
use App\Services\ShopYourVibeTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function syvTagImport(): Import
{
    return Import::create([
        'filename' => 'syv-tags.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'is_current' => true,
        'is_valid' => true,
        'created_by' => User::factory()->create()->id,
    ]);
}

it('loads collection-specific tag options and keeps existing values selected', function () {
    $import = syvTagImport();
    $product = Product::withoutEvents(fn () => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/501',
        'handle' => 'test-bracelet-stack',
        'title' => 'Test Bracelet Stack',
        'vendor' => 'Livi Road Bundles',
        'type' => 'Bracelets',
        'tags' => 'livi-road, livi-road-bracelet-stacks-new-in',
        'color_string' => 'Gold; Blue',
    ]));

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COLOR_METAFIELD => 'Gold; Blue',
            HeaderStore::JEWELRY_MATERIAL => 'gold; silver',
            HeaderStore::MATERIALS_AND_DIMENSIONS => 'Japanese Miyuki beads',
            HeaderStore::BEAD_COLOUR_FINISH => 'polished',
        ],
    ]);

    DropdownOption::withoutEvents(fn () => DropdownOption::create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'Gold',
        'vendor' => 'Livi Road Bundles',
        'product_type' => 'Bracelets',
        'collection_tag_secondary' => 'livi-road-bracelet-stacks-new-in',
        'active' => true,
    ]));
    DropdownOption::withoutEvents(fn () => DropdownOption::create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'Red',
        'vendor' => 'Elevated Basics Bundles',
        'product_type' => 'Bracelets',
        'collection_tag_secondary' => 'elevated-basics-bracelet-stacks-new-in',
        'active' => true,
    ]));

    $service = app(ShopYourVibeTagService::class);
    $state = $service->stateForProduct($product);
    $options = $service->optionsForProduct($product, $state);

    expect($state['color_string'])->toBe(['Gold', 'Blue'])
        ->and($options['color_string'])->toHaveKey('Gold')
        ->and($options['color_string'])->toHaveKey('Blue')
        ->and($options['color_string'])->not->toHaveKey('Red')
        ->and($state['materials_and_dimensions'])->toBe('Japanese Miyuki beads')
        ->and($state['bead_colour_finish'])->toBe('polished');
});

it('does not locally clear existing tag fields when Shopify cannot be safely cleared', function () {
    $import = syvTagImport();
    $product = Product::withoutEvents(fn () => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/502',
        'handle' => 'mirror-bracelet-stack',
        'title' => 'Mirror Bracelet Stack',
        'vendor' => 'Livi Road Bundles',
        'type' => 'Bracelets',
        'tags' => 'livi-road, livi-road-bracelet-stacks-new-in',
        'color_string' => 'Gold',
    ]));

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COLOR_METAFIELD => 'Gold',
            HeaderStore::JEWELRY_MATERIAL => 'gold',
            HeaderStore::MATERIALS_AND_DIMENSIONS => 'Old material',
            HeaderStore::BEAD_COLOUR_FINISH => 'polished',
        ],
    ]);

    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'shopify_id' => $product->shopify_id,
        'handle' => $product->handle,
        'sku' => 'MIRROR1',
        'title' => $product->title,
        'color_string' => 'Gold',
        'jewelry_material' => 'gold',
        'materials_and_dimensions' => 'Old material',
        'bead_colour_finish' => 'polished',
        'shopify_sync_warnings' => [
            ['field' => 'color_string', 'message' => 'old'],
            ['field' => 'variant_price', 'message' => 'keep'],
        ],
    ]));

    expect(fn () => app(ShopYourVibeTagService::class)->save($product->shopify_id, [
        'color_string' => [],
        'jewelry_material' => ['gold', 'silver'],
        'materials_and_dimensions' => 'New material',
        'bead_colour_finish' => 'matte',
    ]))->toThrow(RuntimeException::class, 'Colour cannot be cleared');

    $row = ShopifyRow::query()->where('handle', $product->handle)->firstOrFail();
    $draft->refresh();

    expect($row->get(HeaderStore::COLOR_METAFIELD))->toBe('Gold')
        ->and($row->get(HeaderStore::JEWELRY_MATERIAL))->toBe('gold')
        ->and($row->get(HeaderStore::MATERIALS_AND_DIMENSIONS))->toBe('Old material')
        ->and($row->get(HeaderStore::BEAD_COLOUR_FINISH))->toBe('polished')
        ->and($product->fresh()->color_string)->toBe('Gold')
        ->and($draft->color_string)->toBe('Gold')
        ->and($draft->jewelry_material)->toBe('gold')
        ->and($draft->materials_and_dimensions)->toBe('Old material')
        ->and($draft->bead_colour_finish)->toBe('polished');
});
