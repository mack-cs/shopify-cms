<?php

use App\Services\DropdownCollectionCatalog;

it('resolves vendor names from collection contexts', function (): void {
    $catalog = app(DropdownCollectionCatalog::class);

    expect($catalog->vendorForCollection('Livi Road Bracelets'))->toBe('Livi Road');
    expect($catalog->vendorForCollection('Elevated Basics Bracelets'))->toBe('Elevated Basics');
    expect($catalog->vendorForCollection('Unknown Collection'))->toBeNull();
});
