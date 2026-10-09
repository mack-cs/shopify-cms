<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\RequiredField;
use App\Models\DropdownOption;
use App\Models\Import;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use Database\Seeders\RequiredFieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('lists complementary products as a required field by default', function (): void {
    app(RequiredFieldSeeder::class)->run();

    $field = RequiredField::query()
        ->where('source', 'row')
        ->where('attribute', HeaderStore::COMPLEMENTARY_PRODUCTS)
        ->first();

    expect($field)->not->toBeNull()
        ->and($field->label)->toBe('Complementary products')
        ->and($field->required)->toBeTrue();
});

it('resolves a bundle dropdown context when the parent collection tag is omitted', function (): void {
    $normalizer = app(Normalizer::class);
    $method = new ReflectionMethod($normalizer, 'resolveCollectionContext');

    $context = $method->invoke($normalizer, 'bundles, elevated-basics-bundles');

    expect($context['collection_style'])->toBe('Elevated Basics Bundles')
        ->and($context['tag_primary'])->toBe('elevated-basics')
        ->and($context['tag_secondary'])->toBe('elevated-basics-bundles');
});

it('accepts an approved bundle option using only its specific bundle tag', function (): void {
    DropdownOption::withoutEvents(fn () => DropdownOption::create([
        'header' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'value' => 'Japanese Miyuki beads',
        'collection_style' => 'Elevated Basics Bundles',
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => 'elevated-basics-bundles',
        'active' => true,
    ]));

    $options = DropdownOption::optionsForHeader(
        HeaderStore::MATERIALS_AND_DIMENSIONS,
        tags: ['bundles', 'elevated-basics-bundles']
    );

    expect($options->all())->toBe(['Japanese Miyuki beads']);
});

it('does not block approval for a controlled dropdown switched off in required fields', function (): void {
    RequiredField::create([
        'scope' => 'extra',
        'source' => 'row',
        'attribute' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'label' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'required' => false,
    ]);
    RequiredField::create([
        'scope' => 'extra',
        'source' => 'row',
        'attribute' => HeaderStore::JEWELRY_MATERIAL,
        'label' => HeaderStore::JEWELRY_MATERIAL,
        'required' => true,
    ]);

    $normalizer = app(Normalizer::class);
    $method = new ReflectionMethod($normalizer, 'requiredControlledDropdownHeaders');
    $headers = $method->invoke($normalizer);

    expect($headers)->not->toContain(HeaderStore::MATERIALS_AND_DIMENSIONS)
        ->and($headers)->toContain(HeaderStore::JEWELRY_MATERIAL);
});

it('ignores a new materials value on the draft when that required switch is off', function (): void {
    RequiredField::create([
        'scope' => 'extra',
        'source' => 'row',
        'attribute' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'label' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'required' => false,
    ]);
    RequiredField::create([
        'scope' => 'extra',
        'source' => 'row',
        'attribute' => HeaderStore::JEWELRY_MATERIAL,
        'label' => HeaderStore::JEWELRY_MATERIAL,
        'required' => true,
    ]);

    $method = new ReflectionMethod(NewProductDraftResource::class, 'invalidCollectionSelectionValues');

    $materials = $method->invoke(
        null,
        "• Premium UV-plated enamel on a metal alloy base\n• Elasticated with length of 18.5cm",
        ['Enamel UV plated beads for a premium finish' => 'Enamel UV plated beads for a premium finish'],
        HeaderStore::MATERIALS_AND_DIMENSIONS
    );
    $jewelry = $method->invoke(
        null,
        'not-a-real-material',
        ['gold' => 'gold'],
        HeaderStore::JEWELRY_MATERIAL
    );

    expect($materials)->toBe([])
        ->and($jewelry)->toBe(['not-a-real-material']);
});

it('captures oversized pending dropdown options during shopify import normalization', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-import.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $longMaterials = str_repeat('Japanese Miyuki beads, premium recycled stainless steel, e-coating for a premium finish. ', 8);
    $dropdownSeedPath = storage_path('app/public/template/dropdown-seed.csv');
    $originalDropdownSeed = is_file($dropdownSeedPath) ? file_get_contents($dropdownSeedPath) : null;

    try {
        File::ensureDirectoryExists(dirname($dropdownSeedPath));
        file_put_contents(
            $dropdownSeedPath,
            "Collection,Tags\nElevated Basics Bracelets,\"elevated-basics, elevated-basics-bracelets\"\n"
        );

        ShopifyRow::create([
            'import_id' => $import->id,
            'row_index' => 1,
            'handle' => 'long-materials-product',
            'row_type' => 'product_primary',
            'data' => [
                HeaderStore::TITLE => 'Long Materials Product',
                HeaderStore::TAGS => 'elevated-basics, elevated-basics-bracelets',
                HeaderStore::MATERIALS_AND_DIMENSIONS => $longMaterials,
            ],
        ]);

        app(Normalizer::class)->buildNormalizedTables($import);

        expect(DropdownOption::query()
            ->where('header', HeaderStore::MATERIALS_AND_DIMENSIONS)
            ->where('value', trim($longMaterials))
            ->exists())->toBeTrue();
    } finally {
        if ($originalDropdownSeed === null) {
            File::delete($dropdownSeedPath);
        } else {
            file_put_contents($dropdownSeedPath, $originalDropdownSeed);
        }
    }
});

it('captures bead colour finish values as pending dropdown options during normalization', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-import-bead-finish.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $dropdownSeedPath = storage_path('app/public/template/dropdown-seed.csv');
    $originalDropdownSeed = is_file($dropdownSeedPath) ? file_get_contents($dropdownSeedPath) : null;

    try {
        File::ensureDirectoryExists(dirname($dropdownSeedPath));
        file_put_contents(
            $dropdownSeedPath,
            "Collection,Tags\nLivi Road Bracelets,\"livi-road, livi-road-bracelets\"\n"
        );

        ShopifyRow::create([
            'import_id' => $import->id,
            'row_index' => 1,
            'handle' => 'bead-finish-product',
            'row_type' => 'product_primary',
            'data' => [
                HeaderStore::TITLE => 'Bead Finish Product',
                HeaderStore::TAGS => 'livi-road, livi-road-bracelets',
                HeaderStore::BEAD_COLOUR_FINISH => 'Metallic',
            ],
        ]);

        app(Normalizer::class)->buildNormalizedTables($import);

        expect(DropdownOption::query()
            ->where('header', HeaderStore::BEAD_COLOUR_FINISH)
            ->where('value', 'Metallic')
            ->where('collection_tag_primary', 'livi-road')
            ->where('collection_tag_secondary', 'livi-road-bracelets')
            ->where('active', false)
            ->exists())->toBeTrue();
    } finally {
        if ($originalDropdownSeed === null) {
            File::delete($dropdownSeedPath);
        } else {
            file_put_contents($dropdownSeedPath, $originalDropdownSeed);
        }
    }
});

it('does not create unmapped pending dropdown options when product tags have no known collection context', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-import-unknown-context.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => 'unknown-context-product',
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::TITLE => 'Unknown Context Product',
            HeaderStore::TAGS => 'all-products, new-arrivals',
            HeaderStore::BEAD_COLOUR_FINISH => 'Colourful',
        ],
    ]);

    app(Normalizer::class)->buildNormalizedTables($import);

    expect(DropdownOption::query()
        ->where('header', HeaderStore::BEAD_COLOUR_FINISH)
        ->where('value', 'Colourful')
        ->whereNull('collection_tag_primary')
        ->whereNull('collection_tag_secondary')
        ->exists())->toBeFalse();
});
