<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewProductDraftStackAssociationImporter;
use App\Services\StackBundleSellabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports stack association rows by stack sku and bracelet skus', function (): void {
    $import = createStackAssociationImport();

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'LRBU21',
        'handle' => 'coastal-stillness-bracelet-stack',
        'title' => 'Coastal Stillness Bracelet Stack',
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'draft',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $kudu = createStackAssociationProduct($import, 'kudu-sage', 'Kudu Sage', 'LRB0131');
    $reed = createStackAssociationProduct($import, 'reed-river', 'Reed River', 'LRB0135');

    $path = tempnam(sys_get_temp_dir(), 'stack-associations-');
    file_put_contents($path, implode("\n", [
        "Stack SKU\tStack Name\tBracelet 1\tSKU 1\tBracelet 2\tSKU 2\tBracelet 3\tSKU 3",
        "LRBU21\tCoastal Stillness Bracelet Stack\tKudu Sage\tLRB0131\tReed River\tLRB0135\tMissing\tNOPE",
        "0\tAfrican Aura Bracelet Stack\tWildflower\tLRB0111",
    ]));

    $result = app(NewProductDraftStackAssociationImporter::class)->importFromPath($path);

    $draft->refresh();

    expect($result['total'])->toBe(2);
    expect($result['updated'])->toBe(1);
    expect($result['skipped_missing_stack_sku'])->toBe(1);
    expect($result['component_skus_resolved'])->toBe(2);
    expect($result['component_skus_not_found'])->toBe(1);
    expect($draft->bundle_product_ids)->toBe([$kudu->id, $reed->id]);
    expect($draft->approval_version)->toBe(1);

    config(['shopify_sync.analytics_export_token' => 'stack-export-token']);
    $export = $this->withToken('stack-export-token')->get('/api/analytics/stack-components.csv');
    $export->assertOk();
    expect($export->streamedContent())
        ->toContain('LRBU21')
        ->toContain('LRB0131')
        ->toContain('LRB0135');
});

it('forces associated stacks unsellable when any component is unsellable', function (): void {
    $user = User::factory()->create();
    $import = createStackAssociationImport($user);

    $stackProduct = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9001',
        'handle' => 'desert-echo-bracelet-stack',
        'title' => 'Desert Echo Bracelet Stack',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stackProduct->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9901',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'LRBU34',
        'inventory_tracked' => true,
        'inventory_qty' => 6,
        'inventory_local_dirty' => false,
    ]));

    $sellable = createStackAssociationProduct($import, 'ravens-ember', "Raven's Ember", 'LRB0104', 4);
    $unsellable = createStackAssociationProduct($import, 'giraffe-blotches', 'Giraffe Blotches', 'LRB0032', 0);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'LRBU34',
        'shopify_id' => $stackProduct->shopify_id,
        'handle' => $stackProduct->handle,
        'title' => $stackProduct->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => 6,
        'bundle_product_ids' => [$sellable->id, $unsellable->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $result = app(StackBundleSellabilityService::class)->enforce($user->id);

    $draft->refresh();
    $stackVariant->refresh();
    $stackProduct->refresh();

    expect($result['checked'])->toBe(1);
    expect($result['with_associations'])->toBe(1);
    expect($result['forced_unsellable'])->toBe(1);
    expect($draft->variant_inventory_qty)->toBe(0);
    expect($draft->approval_version)->toBe(1);
    expect($stackVariant->inventory_tracked)->toBeTrue();
    expect($stackVariant->inventory_qty)->toBe(0);
    expect($stackVariant->inventory_local_dirty)->toBeTrue();
    expect($stackProduct->approval_version)->toBe(1);

    $snapshot = ProductInventorySnapshot::query()->firstOrFail();
    expect($snapshot->source)->toBe(ProductInventorySnapshot::SOURCE_BUNDLE_COMPONENT_RULE);
    expect($snapshot->product_id)->toBe($stackProduct->id);
    expect($snapshot->observed_by)->toBe($user->id);
});

it('test only enforcement skips non test stacks and non test components', function (): void {
    $import = createStackAssociationImport();

    $nonTestStackProduct = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9101',
        'handle' => 'ordinary-stack',
        'title' => 'Ordinary Stack',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $nonTestStackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $nonTestStackProduct->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9101',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'ORDINARY-STACK',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
        'inventory_local_dirty' => false,
    ]));

    $testComponent = createStackAssociationProduct($import, 'test-component', 'Test Component', 'TEST-COMPONENT', 0);
    $realComponent = createStackAssociationProduct($import, 'real-component', 'Real Component', 'REAL-COMPONENT', 0);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'ORDINARY-STACK',
        'shopify_id' => $nonTestStackProduct->shopify_id,
        'handle' => $nonTestStackProduct->handle,
        'title' => $nonTestStackProduct->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => 5,
        'bundle_product_ids' => [$testComponent->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $testStackProduct = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9102',
        'handle' => 'test-stack',
        'title' => 'Test Stack',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $testStackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $testStackProduct->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9102',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-STACK',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
        'inventory_local_dirty' => false,
    ]));

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'TEST-STACK',
        'shopify_id' => $testStackProduct->shopify_id,
        'handle' => $testStackProduct->handle,
        'title' => $testStackProduct->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => 5,
        'bundle_product_ids' => [$testComponent->id, $realComponent->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $result = app(StackBundleSellabilityService::class)->enforce(null, false, [
        'test_only' => true,
        'test_token' => 'test',
        'require_test_components' => true,
    ]);

    $nonTestStackVariant->refresh();
    $testStackVariant->refresh();

    expect($result['checked'])->toBe(2);
    expect($result['skipped_non_test_stack'])->toBe(1);
    expect($result['skipped_non_test_components'])->toBe(1);
    expect($result['forced_unsellable'])->toBe(0);
    expect($nonTestStackVariant->inventory_qty)->toBe(5);
    expect($testStackVariant->inventory_qty)->toBe(5);
    expect(ProductInventorySnapshot::query()->count())->toBe(0);
});

it('test only enforcement can use real associated products while only changing the test stack', function (): void {
    $import = createStackAssociationImport();

    $realComponent = createStackAssociationProduct($import, 'real-sold-out-component', 'Real Sold Out Component', 'REAL-SOLD-OUT', 0);

    $testStackProduct = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9202',
        'handle' => 'test-bracelet-stack',
        'title' => 'Test Bracelet Stack',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $testStackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $testStackProduct->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9202',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-BRACELET-STACK',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
        'inventory_local_dirty' => false,
    ]));

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'TEST-BRACELET-STACK',
        'shopify_id' => $testStackProduct->shopify_id,
        'handle' => $testStackProduct->handle,
        'title' => $testStackProduct->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => 5,
        'bundle_product_ids' => [$realComponent->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $result = app(StackBundleSellabilityService::class)->enforce(null, false, [
        'test_only' => true,
        'test_token' => 'test',
    ]);

    $draft->refresh();
    $testStackVariant->refresh();

    expect($result['checked'])->toBe(1);
    expect($result['with_associations'])->toBe(1);
    expect($result['skipped_non_test_components'])->toBe(0);
    expect($result['forced_unsellable'])->toBe(1);
    expect($draft->variant_inventory_qty)->toBe(0);
    expect($testStackVariant->inventory_qty)->toBe(0);
});

function createStackAssociationImport(?User $user = null): Import
{
    $user ??= User::factory()->create();

    return Import::create([
        'filename' => 'stack-associations.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);
}

function createStackAssociationProduct(
    Import $import,
    string $handle,
    string $title,
    string $sku,
    int $inventoryQty = 5,
): Product {
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/'.abs(crc32($handle)),
        'handle' => $handle,
        'title' => $title,
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => false,
        'approval_version' => 1,
    ]));

    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/'.abs(crc32($sku)),
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => $sku,
        'inventory_tracked' => true,
        'inventory_qty' => $inventoryQty,
        'inventory_local_dirty' => false,
    ]));

    return $product;
}
