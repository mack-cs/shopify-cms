<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\DropdownOption;
use App\Models\NewProductDraft;
use App\Models\PrepopulationRule;
use App\Services\ElevatedBasicsStackCatalog;
use App\Services\HeaderStore;
use App\Services\NewInTagService;
use App\Services\PrepopulationRuleService;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function elevatedBasicsBundleOption(string $header, string $value): void
{
    DropdownOption::query()->create([
        'header' => $header,
        'value' => $value,
        'collection_style' => ElevatedBasicsStackCatalog::BASE_COLLECTION,
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
        'active' => true,
    ]);
}

it('replaces the elevated basics bundles dropdown option with bracelet and necklace stacks', function (): void {
    elevatedBasicsBundleOption(HeaderStore::COLOR_METAFIELD, 'Pearl');
    DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'Coral',
        'collection_style' => 'Livi Road Bundles',
        'collection_tag_primary' => 'livi-road',
        'collection_tag_secondary' => 'livi-road-bundles',
        'active' => true,
    ]);

    $options = (new ReflectionMethod(NewProductDraftResource::class, 'draftCollectionOptions'))->invoke(null);

    expect($options)->toHaveKey(ElevatedBasicsStackCatalog::BRACELET_OPTION)
        ->and($options)->toHaveKey(ElevatedBasicsStackCatalog::NECKLACE_OPTION)
        ->and($options)->not->toHaveKey(ElevatedBasicsStackCatalog::BASE_COLLECTION)
        ->and($options)->toHaveKey('Livi Road Bundles');
});

it('does not offer the original bundles option for an existing product that has no single stack type', function (): void {
    elevatedBasicsBundleOption(HeaderStore::COLOR_METAFIELD, 'Pearl');
    $draft = NewProductDraft::query()->create([
        'title' => 'Existing Bundle',
        'type' => 'Bracelets',
        'tags' => 'bundles, elevated-basics-bundles, custom-gift',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED,
    ]);

    $options = (new ReflectionMethod(NewProductDraftResource::class, 'draftCollectionOptions'))->invoke(null);
    $selected = (new ReflectionMethod(NewProductDraftResource::class, 'collectionFromTags'))->invoke(null, $draft->tags);

    expect($selected)->toBe(ElevatedBasicsStackCatalog::BASE_COLLECTION)
        ->and(ElevatedBasicsStackCatalog::selectableCollection($selected))->toBeNull()
        ->and($options)->not->toHaveKey(ElevatedBasicsStackCatalog::BASE_COLLECTION)
        ->and($options)->toHaveKey(ElevatedBasicsStackCatalog::BRACELET_OPTION)
        ->and($options)->toHaveKey(ElevatedBasicsStackCatalog::NECKLACE_OPTION);
});

it('populates bracelet and necklace stacks from the shared bundles configuration', function (string $option, string $type, string $category, string $googleCategory, string $identification): void {
    elevatedBasicsBundleOption(HeaderStore::COLOR_METAFIELD, 'Pearl');
    elevatedBasicsBundleOption(HeaderStore::JEWELRY_MATERIAL, 'Gold plated');
    elevatedBasicsBundleOption(HeaderStore::MATERIALS_AND_DIMENSIONS, '18cm');
    DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'Coral',
        'collection_style' => 'Livi Road Bundles',
        'collection_tag_primary' => 'livi-road',
        'collection_tag_secondary' => 'livi-road-bundles',
        'active' => true,
    ]);
    PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'elevated-basics-stacks',
        'collection_name' => 'Elevated Basics Stacks',
        'add_tags' => [
            'bundles',
            'elevated-basics-bundles',
            'elevated-basics',
            'bracelet',
            'bracelets',
            'elevated-basics-bracelet-stacks-new-in',
        ],
        'auto_vendor' => 'Elevated Basics',
        'auto_type' => 'Bracelets',
        'auto_product_category' => 'gid://shopify/TaxonomyCategory/aa-6-3',
        'auto_google_product_category' => '191',
        'auto_design' => 'beaded',
    ]);

    $mapping = (new ReflectionMethod(NewProductDraftResource::class, 'categoryMappingForCollection'))->invoke(null, $option);
    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection'))->invoke(
        null,
        ['custom-gift', 'bracelet', 'bracelets', 'necklace', 'necklaces', 'elevated-basics-bracelet-stacks-new-in', 'elevated-basics-bracelet-stacks', 'elevated-basics-necklace-stacks'],
        $option,
        'Earrings',
        'Sunday Stack',
        false,
        true
    );
    $lookupTags = (new ReflectionMethod(NewProductDraftResource::class, 'collectionTags'))->invoke(null, $option, false);
    $colors = DropdownOption::optionsForHeader(HeaderStore::COLOR_METAFIELD, tags: $lookupTags)->all();
    $materials = DropdownOption::optionsForHeader(HeaderStore::JEWELRY_MATERIAL, tags: $lookupTags)->all();
    $dimensions = DropdownOption::optionsForHeader(HeaderStore::MATERIALS_AND_DIMENSIONS, tags: $lookupTags)->all();
    $rule = app(PrepopulationRuleService::class)->ruleForCollection(
        ElevatedBasicsStackCatalog::sourceCollection($option)
    );
    $updates = app(PrepopulationRuleService::class)->applyCollectionRuleReplacingManagedTags($rule, $tags, $type);

    expect($mapping['type'])->toBe($type)
        ->and($mapping['shopify_taxonomy_gid'])->toBe($category)
        ->and($mapping['google_product_category'])->toBe($googleCategory)
        ->and(ElevatedBasicsStackCatalog::sourceCollection($option))->toBe(ElevatedBasicsStackCatalog::BASE_COLLECTION)
        ->and($updates['vendor'])->toBe(ElevatedBasicsStackCatalog::VENDOR)
        ->and($updates['vendor'])->not->toBe($option)
        ->and($tags)->toContain(
            'bundles',
            ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
            ElevatedBasicsStackCatalog::NEW_IN_TAG,
            $identification,
            'custom-gift'
        )
        ->and($tags)->not->toContain(
            'bracelet',
            'bracelets',
            'necklace',
            'necklaces',
            ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG,
            $identification === ElevatedBasicsStackCatalog::BRACELET_TAG
                ? ElevatedBasicsStackCatalog::NECKLACE_TAG
                : ElevatedBasicsStackCatalog::BRACELET_TAG
        )
        ->and($colors)->toContain('Pearl')
        ->and($colors)->not->toContain('Coral')
        ->and($materials)->toContain('Gold plated')
        ->and($dimensions)->toContain('18cm');
})->with([
    'bracelet stacks' => [
        ElevatedBasicsStackCatalog::BRACELET_OPTION,
        'Bracelets',
        'gid://shopify/TaxonomyCategory/aa-6-3',
        '191',
        ElevatedBasicsStackCatalog::BRACELET_TAG,
    ],
    'necklace stacks' => [
        ElevatedBasicsStackCatalog::NECKLACE_OPTION,
        'Necklaces',
        'gid://shopify/TaxonomyCategory/aa-6-8',
        '189',
        ElevatedBasicsStackCatalog::NECKLACE_TAG,
    ],
]);

it('does not change livi road stack tags while splitting elevated basics', function (): void {
    DropdownOption::query()->create([
        'header' => HeaderStore::COLOR_METAFIELD,
        'value' => 'Coral',
        'collection_style' => 'Livi Road Stacks',
        'collection_tag_primary' => 'livi-road',
        'collection_tag_secondary' => 'livi-road-bundles',
        'active' => true,
    ]);

    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection'))->invoke(
        null,
        ['custom-gift'],
        'Livi Road Stacks',
        'Bracelets',
        'Livi Stack',
        false,
        true
    );

    expect($tags)->toContain('livi-road-bundles', 'bundles')
        ->and($tags)->not->toContain(
            ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
            ElevatedBasicsStackCatalog::NEW_IN_TAG,
            ElevatedBasicsStackCatalog::BRACELET_TAG,
            ElevatedBasicsStackCatalog::NECKLACE_TAG
        );
});

it('keeps an existing elevated basics stack on its legacy new in tag until a new option is selected', function (): void {
    $tags = app(NewInTagService::class)->tagsForNewProduct([
        'bundles',
        ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
        ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG,
        'custom-gift',
    ], 'Bracelets', 'Existing Stack');

    expect($tags)->toContain(ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG, 'custom-gift')
        ->and($tags)->not->toContain(ElevatedBasicsStackCatalog::NEW_IN_TAG);
});

it('uses the shared new in tag for a new elevated basics stack and does not add the legacy tag', function (): void {
    $tags = app(NewInTagService::class)->tagsForNewProduct([
        'bundles',
        ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
        ElevatedBasicsStackCatalog::NECKLACE_TAG,
    ], 'Necklaces', 'New Necklace Stack');

    expect($tags)->toContain(ElevatedBasicsStackCatalog::NEW_IN_TAG, ElevatedBasicsStackCatalog::NECKLACE_TAG)
        ->and($tags)->not->toContain(
            ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG,
            'necklace',
            'necklaces',
            'bracelet',
            'bracelets'
        );
});

it('does not guess or retag an existing product that has both stack identification tags', function (): void {
    $draft = NewProductDraft::query()->create([
        'title' => 'Ambiguous Stack',
        'type' => 'Bracelets',
        'vendor' => ElevatedBasicsStackCatalog::VENDOR,
        'tags' => implode(', ', [
            'bundles',
            ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
            ElevatedBasicsStackCatalog::BRACELET_TAG,
            ElevatedBasicsStackCatalog::NECKLACE_TAG,
            ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG,
            'custom-gift',
        ]),
        'status' => 'active',
        'origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED,
    ]);

    $draft->title = 'Ambiguous Stack Updated';
    $draft->save();
    $draft->refresh();
    $tags = TagNormalizer::parseTokens($draft->tags);
    $selected = (new ReflectionMethod(NewProductDraftResource::class, 'collectionFromTags'))->invoke(null, $draft->tags);

    expect($selected)->toBe(ElevatedBasicsStackCatalog::BASE_COLLECTION)
        ->and(ElevatedBasicsStackCatalog::hasAmbiguousIdentification($tags))->toBeTrue()
        ->and($draft->vendor)->toBe(ElevatedBasicsStackCatalog::VENDOR)
        ->and($tags)->toContain(
            ElevatedBasicsStackCatalog::MEMBERSHIP_TAG,
            ElevatedBasicsStackCatalog::BRACELET_TAG,
            ElevatedBasicsStackCatalog::NECKLACE_TAG,
            ElevatedBasicsStackCatalog::LEGACY_NEW_IN_TAG,
            'custom-gift'
        );
});
