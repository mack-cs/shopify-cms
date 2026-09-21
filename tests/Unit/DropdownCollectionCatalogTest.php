<?php

use App\Services\DropdownCollectionCatalog;

it('resolves vendor names from collection contexts', function (): void {
    $catalog = app(DropdownCollectionCatalog::class);

    expect($catalog->vendorForCollection('Livi Road Bracelets'))->toBe('Livi Road');
    expect($catalog->vendorForCollection('Elevated Basics Bracelets'))->toBe('Elevated Basics');
    expect($catalog->vendorForCollection('Unknown Collection'))->toBeNull();
});

it('provides selectable collections with their configured tags', function (): void {
    $catalog = app(DropdownCollectionCatalog::class);

    expect($catalog->collectionOptions())->toHaveKey('Elevated Basics Bracelets')
        ->and($catalog->contextForCollection('Elevated Basics Bracelets'))->toBe([
            'collection_style' => 'Elevated Basics Bracelets',
            'tag_primary' => 'elevated-basics',
            'tag_secondary' => 'bracelets',
        ])
        ->and($catalog->contextForCollection('Unknown Collection'))->toBeNull();
});

it('resolves a main collection from Shopify product tags', function (): void {
    $catalog = app(DropdownCollectionCatalog::class);

    expect($catalog->collectionForTags(['elevated-basics', 'bracelets', 'gold']))
        ->toBe('Elevated Basics Bracelets');
});
