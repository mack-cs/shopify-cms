<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Services\ComplementaryProductAuditService;
use App\Services\HeaderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to imported complementary metafields when the row column is blank', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $main = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900',
        'handle' => 'metafield-fallback-main',
        'title' => 'Metafield Fallback Main',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryFallbackCandidate($import->id, 901, 'metafield-fallback-a');
    $b = createComplementaryFallbackCandidate($import->id, 902, 'metafield-fallback-b');
    $c = createComplementaryFallbackCandidate($import->id, 903, 'metafield-fallback-c');

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $main->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => '',
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $import->id,
        'handle' => $main->handle,
        'namespace' => ComplementaryProductAuditService::STANDARD_NAMESPACE,
        'key' => ComplementaryProductAuditService::STANDARD_KEY,
        'type' => 'list.product_reference',
        'value' => implode('; ', [$a->shopify_id, $b->shopify_id, $c->shopify_id]),
    ]);

    $analysis = app(ComplementaryProductAuditService::class)->analyzeProduct($main);

    expect($analysis['local_total'])->toBe(3)
        ->and($analysis['local_ids'])->toBe([$a->id, $b->id, $c->id])
        ->and($analysis['local_primary_ids'])->toBe([$a->id, $b->id, $c->id]);
});

function createComplementaryFallbackCandidate(int $importId, int $shopifyNumericId, string $handle): Product
{
    $product = Product::create([
        'import_id' => $importId,
        'shopify_id' => "gid://shopify/Product/{$shopifyNumericId}",
        'handle' => $handle,
        'title' => ucfirst(str_replace('-', ' ', $handle)),
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $product->id,
        'inventory_qty' => 5,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    return $product;
}
