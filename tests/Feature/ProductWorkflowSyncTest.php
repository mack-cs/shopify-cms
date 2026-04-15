<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\HeaderStore;
use App\Services\NewProductDraftSeeder;
use App\Services\NewProductDraftProductSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resets product approvals when a style profile seo draft updates product seo fields', function (): void {
    $product = createWorkflowTestProduct([
        'seo_title' => null,
        'seo_description' => null,
        'approval_version' => 1,
    ]);

    $profile = StyleProfile::create([
        'product_id' => $product->id,
        'handle' => $product->handle,
        'sku' => $product->handle,
        'draft_seo_title' => null,
        'draft_seo_description' => null,
        'seo_sync_status' => 'draft',
    ]);

    $profile->update([
        'draft_seo_title' => 'Fresh SEO Title',
        'draft_seo_description' => 'Fresh SEO description.',
    ]);

    $product->refresh();

    expect($product->seo_title)->toBe('Fresh SEO Title');
    expect($product->seo_description)->toBe('Fresh SEO description.');
    expect($product->approval_version)->toBe(2);
});

it('pushes changed draft fields into the linked product on save, including clears', function (): void {
    $product = createWorkflowTestProduct([
        'vendor' => 'Original Vendor',
        'title' => 'Original Title',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'vendor' => 'Original Vendor',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $draft->update([
        'vendor' => null,
    ]);

    $product->refresh();

    expect($product->vendor)->toBeNull();
    expect($product->title)->toBe('Original Title');
    expect($product->approval_version)->toBe(2);
});

it('backfills empty draft fields from shopify sync and records warnings for conflicting draft edits', function (): void {
    $product = createWorkflowTestProduct([
        'vendor' => 'Shopify Vendor',
        'type' => 'Bracelets',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => 'Draft Title',
        'vendor' => 'Draft Vendor',
        'type' => null,
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->vendor)->toBe('Draft Vendor');
    expect($seeded->type)->toBe('Bracelets');
    expect($seeded->shopifySyncWarningCount())->toBe(1);
    expect($seeded->shopifySyncWarnings()[0]['field'])->toBe('vendor');
    expect($seeded->shopifySyncWarnings()[0]['draft_value'])->toBe('Draft Vendor');
    expect($seeded->shopifySyncWarnings()[0]['shopify_value'])->toBe('Shopify Vendor');
});

it('keeps sibling option name exactly in sync with the draft title', function (): void {
    $draft = NewProductDraft::create([
        'handle' => 'workflow-test-product',
        'title' => 'Initial Draft Title',
        'siblings_collection_name' => 'Different Value',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    expect($draft->siblings_collection_name)->toBe('Initial Draft Title');

    $draft->update([
        'title' => 'Updated Draft Title',
        'siblings_collection_name' => 'Another Different Value',
    ]);

    expect($draft->fresh()->siblings_collection_name)->toBe('Updated Draft Title');
});

it('syncs sibling option name to the title when pushing a draft into the linked product row', function (): void {
    $product = createWorkflowTestProduct([
        'title' => 'Original Product Title',
        'approval_version' => 1,
    ]);

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::SIBLINGS_COLLECTION_NAME => 'Old Sibling Option Name',
        ],
    ]);

    $draft = NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => 'Synced Draft Title',
        'siblings_collection_name' => 'Should Be Ignored',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    app(NewProductDraftProductSync::class)->syncToExistingProduct(
        $draft,
        ensureApprovalReset: false,
        attributes: ['title']
    );

    $row = ShopifyRow::query()
        ->where('import_id', $product->import_id)
        ->where('handle', $product->handle)
        ->where('row_type', 'product_primary')
        ->firstOrFail();

    expect($row->get(HeaderStore::SIBLINGS_COLLECTION_NAME))->toBe('Synced Draft Title');
});

function createWorkflowTestProduct(array $overrides = []): Product
{
    $user = User::factory()->create();

    $import = Import::create([
        'filename' => 'workflow-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    return Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/1001',
        'handle' => 'workflow-test-product',
        'title' => 'Workflow Test Product',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
        'status' => 'draft',
        'approval_version' => 1,
    ], $overrides)));
}
