<?php

use App\Models\RequiredField;
use App\Models\DropdownOption;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a bundle dropdown context when the parent collection tag is omitted', function (): void {
    $normalizer = app(Normalizer::class);
    $method = new ReflectionMethod($normalizer, 'resolveCollectionContext');

    $context = $method->invoke($normalizer, 'bundles, elevated-basics-bundles');

    expect($context['collection_style'])->toBe('Elevated Basics Bundles')
        ->and($context['tag_primary'])->toBe('elevated-basics')
        ->and($context['tag_secondary'])->toBe('elevated-basics-bundles');
});

it('accepts an approved bundle option using only its specific bundle tag', function (): void {
    DropdownOption::create([
        'header' => HeaderStore::MATERIALS_AND_DIMENSIONS,
        'value' => 'Japanese Miyuki beads',
        'collection_style' => 'Elevated Basics Bundles',
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => 'elevated-basics-bundles',
        'active' => true,
    ]);

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
