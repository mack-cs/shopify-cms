<?php

use App\Services\HeaderStore;

it('treats bundle tags as bracelet design collections', function (): void {
    expect(HeaderStore::designHeaderForTypeAndTags(null, ['livi-road', 'livi-road-bundles']))
        ->toBe(HeaderStore::BRACELET_DESIGN);

    expect(HeaderStore::designHeaderForTypeAndTags('Bundles', null))
        ->toBe(HeaderStore::BRACELET_DESIGN);
});

it('uses the stack product type for design attributes without generic type tags', function (): void {
    expect(HeaderStore::designHeaderForTypeAndTags('Necklaces', ['bundles', 'elevated-basics-bundles']))
        ->toBe(HeaderStore::NECKLACE_DESIGN)
        ->and(HeaderStore::designHeaderForTypeAndTags('Earrings', ['bundles', 'livi-road-bundles']))
        ->toBe(HeaderStore::EARRING_DESIGN)
        ->and(HeaderStore::designHeaderForTypeAndTags('Bracelets', ['bundles', 'pata-pata-bundles']))
        ->toBe(HeaderStore::BRACELET_DESIGN);
});
