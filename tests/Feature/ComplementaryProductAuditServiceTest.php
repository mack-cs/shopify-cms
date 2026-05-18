<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyAudit;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Filament\Resources\NewProductDraftResource;
use App\Services\ComplementaryProductAuditService;
use App\Services\HeaderStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flags shopify complementary products when a live shopify ref is invalid and chooses a valid local backup', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/100',
        'handle' => 'main-product',
        'title' => 'Main Product',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryCandidate($import->id, 201, 'comp-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 202, 'comp-b', 'active', 0);
    $c = createComplementaryCandidate($import->id, 203, 'comp-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 204, 'comp-d', 'active', 5);

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

    $analysis = app(ComplementaryProductAuditService::class)->analyzeProduct($main);

    expect($analysis['local_good'])->toBeTrue()
        ->and($analysis['shopify_good'])->toBeFalse()
        ->and($analysis['shopify_eligible'])->toBe(2)
        ->and($analysis['desired_shopify_gids'])->toBe([
            $a->shopify_id,
            $c->shopify_id,
            $d->shopify_id,
        ]);
});

it('matches draft filters using local and live complementary status', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/300',
        'handle' => 'draft-linked',
        'title' => 'Draft Linked',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryCandidate($import->id, 301, 'draft-comp-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 302, 'draft-comp-b', 'active', 0);
    $c = createComplementaryCandidate($import->id, 303, 'draft-comp-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 304, 'draft-comp-d', 'active', 5);

    $draft = NewProductDraft::create([
        'handle' => $main->handle,
        'shopify_id' => $main->shopify_id,
        'title' => 'Draft Linked',
        'status' => 'active',
        'complementary_products' => implode('; ', [$a->handle, $b->handle, $c->handle, $d->handle]),
        'approval_version' => 1,
    ]);

    ShopifyMetafield::create([
        'import_id' => $import->id,
        'handle' => $main->handle,
        'namespace' => ComplementaryProductAuditService::STANDARD_NAMESPACE,
        'key' => ComplementaryProductAuditService::STANDARD_KEY,
        'type' => 'list.product_reference',
        'value' => json_encode([$a->shopify_id, $b->shopify_id, $c->shopify_id], JSON_UNESCAPED_SLASHES),
    ]);

    $service = app(ComplementaryProductAuditService::class);

    expect($service->draftIdsMatchingLocalStatus('good'))->toContain($draft->id)
        ->and($service->draftIdsMatchingShopifyStatus('flagged'))->toContain($draft->id);
});

it('treats fewer live shopify complementary products as healthy when they already exist locally', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/350',
        'handle' => 'subset-healthy',
        'title' => 'Subset Healthy',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryCandidate($import->id, 351, 'subset-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 352, 'subset-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 353, 'subset-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 354, 'subset-d', 'active', 5);

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
        'value' => json_encode([$a->shopify_id, $c->shopify_id], JSON_UNESCAPED_SLASHES),
    ]);

    $analysis = app(ComplementaryProductAuditService::class)->analyzeProduct($main);

    expect($analysis['shopify_total'])->toBe(2)
        ->and($analysis['shopify_eligible'])->toBe(2)
        ->and($analysis['shopify_missing_local_ids'])->toBe([])
        ->and($analysis['shopify_good'])->toBeTrue();
});

it('flags shopify complementary products when a live shopify ref is missing from the local list', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/400',
        'handle' => 'missing-local-live',
        'title' => 'Missing Local Live',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryCandidate($import->id, 401, 'many-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 402, 'many-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 403, 'many-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 404, 'many-d', 'active', 5);

    ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => 1,
        'handle' => $main->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', [$a->handle, $b->handle, $c->handle]),
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $import->id,
        'handle' => $main->handle,
        'namespace' => ComplementaryProductAuditService::STANDARD_NAMESPACE,
        'key' => ComplementaryProductAuditService::STANDARD_KEY,
        'type' => 'list.product_reference',
        'value' => json_encode([$a->shopify_id, $b->shopify_id, $c->shopify_id, $d->shopify_id], JSON_UNESCAPED_SLASHES),
    ]);

    $analysis = app(ComplementaryProductAuditService::class)->analyzeProduct($main);

    expect($analysis['shopify_total'])->toBe(4)
        ->and($analysis['shopify_eligible'])->toBe(4)
        ->and($analysis['shopify_missing_local_ids'])->toBe([$d->id])
        ->and($analysis['shopify_good'])->toBeFalse();
});

it('flags shopify complementary products when shopify uses the backup instead of the local primary trio', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/450',
        'handle' => 'primary-vs-backup',
        'title' => 'Primary vs Backup',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    Variant::create([
        'product_id' => $main->id,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);

    $a = createComplementaryCandidate($import->id, 451, 'pvb-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 452, 'pvb-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 453, 'pvb-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 454, 'pvb-d', 'active', 5);

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
        'value' => json_encode([$a->shopify_id, $b->shopify_id, $d->shopify_id], JSON_UNESCAPED_SLASHES),
    ]);

    $analysis = app(ComplementaryProductAuditService::class)->analyzeProduct($main);

    expect($analysis['local_primary_ids'])->toBe([$a->id, $b->id, $c->id])
        ->and($analysis['shopify_missing_local_ids'])->toBe([$d->id])
        ->and($analysis['shopify_good'])->toBeFalse();
});

it('uses the draft local primary trio when showing draft complementary audit status', function (): void {
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
        'handle' => 'draft-audit-status',
        'title' => 'Draft Audit Status',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $a = createComplementaryCandidate($import->id, 501, 'das-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 502, 'das-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 503, 'das-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 504, 'das-d', 'active', 5);

    $draft = NewProductDraft::create([
        'handle' => $main->handle,
        'shopify_id' => $main->shopify_id,
        'title' => $main->title,
        'status' => 'active',
        'complementary_products' => implode('; ', [$a->handle, $b->handle, $d->handle, $c->handle]),
        'approval_version' => 1,
    ]);

    ShopifyAudit::create([
        'product_id' => $main->id,
        'audit_type' => ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS,
        'status' => ShopifyAudit::STATUS_FLAGGED,
        'needs_attention' => true,
        'details' => [
            'shopify_ids' => [$a->id, $b->id, $d->id],
            'shopify_ineligible' => [],
        ],
        'last_checked_at' => now(),
    ]);

    $label = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditStatusLabel($record),
        null,
        NewProductDraftResource::class
    )($draft);

    expect($label)->toBe('Healthy');
});

it('shows a warning draft complementary audit when only the local 4th backup is missing', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/550',
        'handle' => 'draft-warning-only',
        'title' => 'Draft Warning Only',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $a = createComplementaryCandidate($import->id, 551, 'dwo-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 552, 'dwo-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 553, 'dwo-c', 'active', 5);

    $draft = NewProductDraft::create([
        'handle' => $main->handle,
        'shopify_id' => $main->shopify_id,
        'title' => $main->title,
        'status' => 'active',
        'complementary_products' => implode('; ', [$a->handle, $b->handle, $c->handle]),
        'approval_version' => 1,
    ]);

    ShopifyAudit::create([
        'product_id' => $main->id,
        'audit_type' => ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS,
        'status' => ShopifyAudit::STATUS_HEALTHY,
        'needs_attention' => false,
        'details' => [
            'shopify_ids' => [$a->id, $b->id, $c->id],
            'shopify_ineligible' => [],
        ],
        'last_checked_at' => now(),
    ]);

    $label = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditStatusLabel($record),
        null,
        NewProductDraftResource::class
    )($draft);
    $color = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditStatusColor($record),
        null,
        NewProductDraftResource::class
    )($draft);
    $issues = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditIssuesSummary($record),
        null,
        NewProductDraftResource::class
    )($draft);

    expect($label)->toBe('Needs Audit')
        ->and($color)->toBe('warning')
        ->and($issues)->toContain('Local draft needs 1 more complementary backup product(s) to reach 4.')
        ->and($issues)->not->toContain('Shopify has fewer than 3 complementary products.');
});

it('shows a danger draft complementary audit when shopify is short on complementary products', function (): void {
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
        'shopify_id' => 'gid://shopify/Product/560',
        'handle' => 'draft-danger-short-shopify',
        'title' => 'Draft Danger Short Shopify',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $a = createComplementaryCandidate($import->id, 561, 'ddss-a', 'active', 5);
    $b = createComplementaryCandidate($import->id, 562, 'ddss-b', 'active', 5);
    $c = createComplementaryCandidate($import->id, 563, 'ddss-c', 'active', 5);
    $d = createComplementaryCandidate($import->id, 564, 'ddss-d', 'active', 5);

    $draft = NewProductDraft::create([
        'handle' => $main->handle,
        'shopify_id' => $main->shopify_id,
        'title' => $main->title,
        'status' => 'active',
        'complementary_products' => implode('; ', [$a->handle, $b->handle, $c->handle, $d->handle]),
        'approval_version' => 1,
    ]);

    ShopifyAudit::create([
        'product_id' => $main->id,
        'audit_type' => ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS,
        'status' => ShopifyAudit::STATUS_HEALTHY,
        'needs_attention' => false,
        'details' => [
            'shopify_ids' => [$a->id, $b->id],
            'shopify_ineligible' => [],
        ],
        'last_checked_at' => now(),
    ]);

    $label = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditStatusLabel($record),
        null,
        NewProductDraftResource::class
    )($draft);
    $color = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditStatusColor($record),
        null,
        NewProductDraftResource::class
    )($draft);
    $issues = \Closure::bind(
        static fn (NewProductDraft $record): string => NewProductDraftResource::draftComplementaryAuditIssuesSummary($record),
        null,
        NewProductDraftResource::class
    )($draft);

    expect($label)->toBe('Needs Audit')
        ->and($color)->toBe('danger')
        ->and($issues)->toContain('Shopify has fewer than 3 complementary products.')
        ->and($issues)->not->toContain('Local draft needs 1 more complementary backup product(s) to reach 4.');
});

function createComplementaryCandidate(int $importId, int $shopifyNumericId, string $handle, string $status, int $inventoryQty): Product
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
