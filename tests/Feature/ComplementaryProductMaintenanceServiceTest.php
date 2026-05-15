<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Services\ComplementaryProductAuditService;
use App\Services\ComplementaryProductMaintenanceService;
use App\Services\HeaderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records complementary audit results with last checked time and no shopify sync dependency', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/500',
        'handle' => 'audit-record-main',
        'title' => 'Audit Record Main',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createMaintenanceCandidate($import->id, 501, 'audit-a', 'active', 5);
    $b = createMaintenanceCandidate($import->id, 502, 'audit-b', 'active', 0);
    $c = createMaintenanceCandidate($import->id, 503, 'audit-c', 'active', 5);
    $d = createMaintenanceCandidate($import->id, 504, 'audit-d', 'active', 5);

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $main->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', [$a->handle, $b->handle, $c->handle, $d->handle]),
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $import->id,
        'handle' => $main->handle,
        'namespace' => ComplementaryProductAuditService::STANDARD_NAMESPACE,
        'key' => ComplementaryProductAuditService::STANDARD_KEY,
        'type' => 'list.product_reference',
        'value' => json_encode([$a->shopify_id, $b->shopify_id, $c->shopify_id], JSON_UNESCAPED_SLASHES),
    ]);

    $summary = app(ComplementaryProductMaintenanceService::class)->runDailyCheck();

    $audit = ShopifyAudit::query()
        ->where('product_id', $main->id)
        ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
        ->first();

    expect($summary['checked'])->toBeGreaterThan(0)
        ->and($summary['recorded'])->toBeGreaterThan(0)
        ->and($audit)->not->toBeNull()
        ->and($audit?->status)->toBe(ShopifyAudit::STATUS_FLAGGED)
        ->and($audit?->shopify_current_count)->toBe(3)
        ->and($audit?->shopify_valid_count)->toBe(2)
        ->and($audit?->last_checked_at)->not->toBeNull();
});

function createMaintenanceCandidate(int $importId, int $shopifyNumericId, string $handle, string $status, int $inventoryQty): Product
{
    $product = Product::create([
        'import_id' => $importId,
        'shopify_id' => "gid://shopify/Product/{$shopifyNumericId}",
        'handle' => $handle,
        'title' => ucfirst($handle),
        'status' => $status,
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $product->id,
        'inventory_qty' => $inventoryQty,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    return $product;
}
