<?php

use App\Services\SaleTagService;

it('resolves the known sale collections from product and generated sale tags', function (): void {
    $service = app(SaleTagService::class);

    expect($service->collectionNamesForExport(
        'all-products, elevated-basics, elevated-basics-necklaces, untamed-sale, elements-of-desire'
    ))->toBe('Elevated Basics; Untamed; Elements of Desire')
        ->and($service->collectionNamesForExport(
            'livi-road, pata-pata-sale'
        ))->toBe('Livi Road; Pata Pata');
});
