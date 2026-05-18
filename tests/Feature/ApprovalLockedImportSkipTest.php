<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyCollection;
use App\Models\CollectionApprovalRequest;
use App\Models\User;
use App\Services\NewProductDraftCsvImporter;
use App\Services\ShopifyCollectionSeoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips draft csv updates when the linked product is pending partial approval', function (): void {
    $user = User::factory()->create();
    $this->be($user);

    $product = Product::create([
        'title' => 'Kudu Sage Bracelet',
        'handle' => 'kudu-sage-bracelet',
        'shopify_id' => 'gid://shopify/Product/1001',
        'status' => 'active',
        'approval_version' => 3,
    ]);

    $draft = NewProductDraft::create([
        'title' => 'Kudu Sage Bracelet',
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'approval_version' => 1,
        'status' => 'active',
    ]);

    ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => $user->id,
        'request_batch_id' => 'draft-lock-import',
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => ['product'],
        'core_fields' => ['title'],
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-import-');
    file_put_contents($path, "handle,title\nkudu-sage-bracelet,Updated Kudu Sage Bracelet\n");

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    $draft->refresh();

    expect($result['skipped_pending_approval'])->toBe(1)
        ->and($result['pending_approval_handles'])->toContain('kudu-sage-bracelet')
        ->and($draft->title)->toBe('Kudu Sage Bracelet');

    @unlink($path);
});

it('skips collection seo csv updates when the collection is pending approval', function (): void {
    $user = User::factory()->create();

    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/2001',
        'handle' => 'all-products-collection',
        'title' => 'All Products Collection',
        'approval_version' => 2,
    ]);

    CollectionApprovalRequest::create([
        'collection_id' => $collection->id,
        'approval_version' => $collection->approval_version,
        'requested_by' => $user->id,
        'request_batch_id' => 'collection-lock-import',
        'status' => CollectionApprovalRequest::STATUS_PENDING,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'collection-import-');
    file_put_contents($path, "shopify_id,title\n{$collection->shopify_id},Updated Collection Title\n");

    $result = app(ShopifyCollectionSeoImporter::class)->importFromPath($import, $path);

    $collection->refresh();

    expect($result['skipped_pending_approval'])->toBe(1)
        ->and($result['pending_approval_handles'])->toContain('all-products-collection')
        ->and($collection->draft_title)->toBeNull();

    @unlink($path);
});
