<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\NewProductDraftSeeder;
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
