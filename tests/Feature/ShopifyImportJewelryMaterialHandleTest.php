<?php

use App\Services\HeaderStore;
use App\Models\Import;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Services\Normalizer;
use App\Services\ShopifyApiImporter;
use App\Services\ShopifyCsvImporter;
use App\Services\ShopifyTaxonomyValueNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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

it('updates imported products without deleting procurement referenced variants', function (): void {
    $user = User::factory()->create();
    $oldImport = Import::create([
        'filename' => 'old-shopify-api',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => false,
        'is_valid' => true,
    ]);
    $newImport = Import::create([
        'filename' => 'shopify-api',
        'mode' => 'overwrite',
        'status' => 'processing',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $product = Product::create([
        'import_id' => $oldImport->id,
        'handle' => 'stable-product',
        'title' => 'Old title',
        'status' => 'active',
    ]);
    $variant = Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/100',
        'sku' => 'STABLE-SKU',
        'price' => '10.00',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);
    $order = ProcurementSupplierOrder::create([
        'uuid' => (string) Str::uuid(),
        'order_number' => 'PO-STABLE',
        'source' => 'cms',
    ]);
    ProcurementSupplierOrderLine::create([
        'supplier_order_id' => $order->id,
        'variant_id' => $variant->id,
        'sku' => 'STABLE-SKU',
        'quantity_ordered' => 5,
        'status' => 'open',
        'source' => 'cms',
    ]);

    ShopifyRow::create([
        'import_id' => $newImport->id,
        'row_index' => 1,
        'handle' => 'stable-product',
        'row_type' => 'product_primary',
        'variant_key' => 'STABLE-SKU',
        'data' => [
            HeaderStore::HANDLE => 'stable-product',
            HeaderStore::TITLE => 'New title',
            HeaderStore::STATUS => 'active',
            HeaderStore::VARIANT_SKU => 'STABLE-SKU',
            HeaderStore::VARIANT_PRICE => '12.00',
            HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID => 'gid://shopify/ProductVariant/100',
        ],
    ]);

    app(Normalizer::class)->buildNormalizedTables($newImport);

    expect(Product::query()->whereKey($product->id)->value('title'))->toBe('New title')
        ->and(Product::query()->whereKey($product->id)->value('import_id'))->toBe($newImport->id)
        ->and(Variant::query()->whereKey($variant->id)->exists())->toBeTrue()
        ->and(Variant::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and(ProcurementSupplierOrderLine::query()->where('variant_id', $variant->id)->exists())->toBeTrue();
});
