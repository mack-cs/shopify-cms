<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Services\ProductInventoryCsvExporter;
use App\Services\ProductInventoryCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Csv\Reader;
use League\Csv\Writer;

uses(RefreshDatabase::class);

it('imports stock by product id and records an inventory snapshot', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'single-variant-product',
        'shopify_id' => 'gid://shopify/Product/1001',
        'title' => 'Single Variant Product',
        'status' => 'active',
        'approval_version' => 1,
    ]));

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/2001',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'sku' => 'SVP-1',
        'inventory_tracked' => true,
        'inventory_qty' => 2,
        'inventory_local_dirty' => false,
    ]));

    $path = tempnam(sys_get_temp_dir(), 'inventory-import-');
    file_put_contents($path, "product_id,stock\n{$product->id},7\n");

    $result = app(ProductInventoryCsvImporter::class)->importFromPath($path, $user->id);

    $variant->refresh();
    $product->refresh();

    expect($result['total'])->toBe(1);
    expect($result['updated'])->toBe(1);
    expect($result['unchanged'])->toBe(0);
    expect($result['snapshots'])->toBe(1);
    expect($variant->inventory_qty)->toBe(7);
    expect($variant->inventory_tracked)->toBeTrue();
    expect($variant->inventory_local_dirty)->toBeTrue();
    expect($variant->local_dirty)->toBeTrue();
    expect($product->approval_version)->toBe(1);

    $snapshot = ProductInventorySnapshot::query()->firstOrFail();
    expect($snapshot->source)->toBe(ProductInventorySnapshot::SOURCE_STOCK_IMPORT);
    expect($snapshot->product_id)->toBe($product->id);
    expect($snapshot->total_inventory_qty)->toBe(7);
    expect($snapshot->observed_by)->toBe($user->id);
});

it('skips product id imports when the product has multiple variants and no sku', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'multi-variant-product',
        'shopify_id' => 'gid://shopify/Product/1002',
        'title' => 'Multi Variant Product',
        'status' => 'active',
        'approval_version' => 1,
    ]));

    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/2002',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'MVP-1',
        'inventory_tracked' => true,
        'inventory_qty' => 2,
        'inventory_local_dirty' => false,
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/2003',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'MVP-2',
        'inventory_tracked' => true,
        'inventory_qty' => 3,
        'inventory_local_dirty' => false,
    ]));

    $path = tempnam(sys_get_temp_dir(), 'inventory-import-');
    file_put_contents($path, "product_id,stock\n{$product->id},9\n");

    $result = app(ProductInventoryCsvImporter::class)->importFromPath($path, $user->id);

    expect($result['total'])->toBe(1);
    expect($result['updated'])->toBe(0);
    expect($result['skipped_ambiguous'])->toBe(1);
    expect($result['snapshots'])->toBe(0);
    expect(ProductInventorySnapshot::query()->count())->toBe(0);
    expect(Variant::query()->where('product_id', $product->id)->pluck('inventory_qty')->all())->toBe([2, 3]);
});

it('exports local stock in a csv that can be edited and imported back', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'roundtrip-product',
        'shopify_id' => 'gid://shopify/Product/1003',
        'title' => 'Roundtrip Product',
        'status' => 'active',
        'approval_version' => 1,
    ]));

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/2004',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'sku' => 'ROUND-1',
        'inventory_tracked' => true,
        'inventory_qty' => 4,
        'inventory_local_dirty' => false,
    ]));

    $csv = app(ProductInventoryCsvExporter::class)->exportToString();
    $reader = Reader::createFromString($csv);
    $reader->setHeaderOffset(0);
    $rows = array_values(iterator_to_array($reader->getRecords()));

    expect($rows)->toHaveCount(1);
    expect($rows[0]['product_id'])->toBe((string) $product->id);
    expect($rows[0]['shopify_product_id'])->toBe('gid://shopify/Product/1003');
    expect($rows[0]['variant_id'])->toBe((string) $variant->id);
    expect($rows[0]['shopify_variant_id'])->toBe('gid://shopify/ProductVariant/2004');
    expect($rows[0]['sku'])->toBe('ROUND-1');
    expect($rows[0]['inventory_tracked'])->toBe('true');
    expect($rows[0]['stock'])->toBe('4');

    $rows[0]['stock'] = '12';

    $path = tempnam(sys_get_temp_dir(), 'inventory-roundtrip-');
    $writer = Writer::createFromPath($path, 'w+');
    $writer->insertOne(array_keys($rows[0]));
    $writer->insertOne(array_values($rows[0]));

    $result = app(ProductInventoryCsvImporter::class)->importFromPath($path, $user->id);

    $variant->refresh();

    expect($result['updated'])->toBe(1);
    expect($variant->inventory_qty)->toBe(12);
    expect($variant->inventory_local_dirty)->toBeTrue();
});
