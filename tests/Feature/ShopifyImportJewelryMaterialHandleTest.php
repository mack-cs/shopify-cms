<?php

use App\Services\HeaderStore;
use App\Services\ShopifyApiImporter;
use App\Services\ShopifyCsvImporter;
use App\Services\ShopifyTaxonomyValueNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('normalizes jewelry material values from Shopify CSV import rows', function (): void {
    $importer = app(ShopifyCsvImporter::class);
    $method = new ReflectionMethod($importer, 'normalizeRowToAllHeaders');

    $row = [
        HeaderStore::JEWELRY_MATERIAL => 'Fresh Water Pearls; Japanese Miyuki Beads; natural-stones',
        HeaderStore::TITLE => 'Bracelet',
    ];

    $data = $method->invoke($importer, $row, [HeaderStore::JEWELRY_MATERIAL, HeaderStore::TITLE]);

    expect($data[HeaderStore::JEWELRY_MATERIAL])
        ->toBe('fresh-water-pearls; japanese-miyuki-beads; natural-stones')
        ->and($data[HeaderStore::TITLE])
        ->toBe('Bracelet');
});

it('normalizes jewelry material values from Shopify API import rows', function (): void {
    $importer = app(ShopifyApiImporter::class);
    $method = new ReflectionMethod($importer, 'setIfHeaderExists');
    $row = [];
    $headers = [HeaderStore::JEWELRY_MATERIAL];

    $method->invokeArgs($importer, [
        &$row,
        $headers,
        HeaderStore::JEWELRY_MATERIAL,
        'Gold; Silver; Enamel 1',
    ]);

    expect($row[HeaderStore::JEWELRY_MATERIAL])
        ->toBe('gold; silver; enamel-1');
});

it('keeps Shopify material handles stable while normalizing labels', function (): void {
    $normalizer = app(ShopifyTaxonomyValueNormalizer::class);

    expect($normalizer->normalize(HeaderStore::JEWELRY_MATERIAL, 'fresh-water-pearls; japanese-miyuki-beads; natural-stones'))
        ->toBe('fresh-water-pearls; japanese-miyuki-beads; natural-stones')
        ->and($normalizer->normalize(HeaderStore::JEWELRY_MATERIAL, 'Fresh Water Pearls; Japanese Miyuki Beads; Natural Stones'))
        ->toBe('fresh-water-pearls; japanese-miyuki-beads; natural-stones');
});
