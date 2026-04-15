<?php

use App\Services\HeaderStore;

it('treats bundle tags as bracelet design collections', function (): void {
    expect(HeaderStore::designHeaderForTypeAndTags(null, ['livi-road', 'livi-road-bundles']))
        ->toBe(HeaderStore::BRACELET_DESIGN);

    expect(HeaderStore::designHeaderForTypeAndTags('Bundles', null))
        ->toBe(HeaderStore::BRACELET_DESIGN);
});
