<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Approval;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\HeaderStore;
use App\Services\NewProductDraftSeeder;
use App\Services\NewProductDraftProductSync;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;

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

it('syncs only the first three complementary products to Shopify while keeping extra local selections', function (): void {
    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1001',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    $selectedComplementary = [
        'gid://shopify/Product/2001',
        'gid://shopify/Product/2002',
        'gid://shopify/Product/2003',
        'gid://shopify/Product/2004',
        'gid://shopify/Product/2005',
    ];

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', $selectedComplementary),
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => '',
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => '',
    ]);

    $capturedMetafields = null;

    $client = Mockery::mock(ShopifyApiClient::class);
    $client->shouldReceive('graphql')
        ->andReturnUsing(function (string $query, array $variables = []) use (&$capturedMetafields): array {
            if (str_contains($query, 'query ProductByIdDetails')) {
                return [
                    'product' => [
                        'id' => 'gid://shopify/Product/1001',
                        'options' => [],
                        'category' => [
                            'id' => 'gid://shopify/TaxonomyCategory/aa-6-6',
                            'name' => 'Jewelry',
                        ],
                        'productCategory' => [
                            'productTaxonomyNode' => [
                                'id' => 'gid://shopify/TaxonomyCategory/aa-6-6',
                                'fullName' => 'Apparel & Accessories > Jewelry',
                            ],
                        ],
                        'variants' => ['nodes' => []],
                        'media' => ['nodes' => []],
                    ],
                ];
            }

            if (str_contains($query, 'query ProductByIdMetafields')) {
                return [
                    'product' => [
                        'metafields' => [
                            'nodes' => [],
                        ],
                    ],
                ];
            }

            if (str_contains($query, 'mutation MetafieldsSet')) {
                $capturedMetafields = $variables['metafields'] ?? null;

                return [
                    'metafieldsSet' => [
                        'metafields' => [['id' => 'gid://shopify/Metafield/1']],
                        'userErrors' => [],
                    ],
                ];
            }

            throw new RuntimeException('Unexpected Shopify GraphQL call in test.');
        });

    app()->instance(ShopifyApiClient::class, $client);

    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product]),
        [ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS],
        [ProductShopifyUpdater::CORE_FIELD_COMPLEMENTARY_PRODUCTS]
    );

    expect($result['updated'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($capturedMetafields)->toHaveCount(1);
    expect($capturedMetafields[0]['namespace'])->toBe('shopify--discovery--product_recommendation');
    expect($capturedMetafields[0]['key'])->toBe('complementary_products');
    expect(json_decode($capturedMetafields[0]['value'], true))->toBe(array_slice($selectedComplementary, 0, 3));

    $row = ShopifyRow::query()
        ->where('import_id', $product->import_id)
        ->where('handle', $product->handle)
        ->where('row_type', 'product_primary')
        ->firstOrFail();

    expect($row->get(HeaderStore::COMPLEMENTARY_PRODUCTS))->toBe(implode('; ', $selectedComplementary));
});

it('reuses the existing Shopify media when only an image filename changes', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/rename-only.png', 'rename-only-image');

    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1001',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    $image = \App\Models\Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/9001',
        'sync_state' => \App\Models\Image::SYNC_STATE_LOCAL_UPDATED,
        'local_dirty' => true,
        'src' => 'https://cdn.shopify.com/s/files/1/test/existing-image.png',
        'image_path' => 'product-images/test/rename-only.png',
        'backup_status' => \App\Models\Image::BACKUP_STATUS_PENDING,
        'position' => 1,
        'approved_filename' => 'renamed-product-01.png',
        'last_shopify_synced_filename' => 'old-product-01.png',
        'needs_shopify_image_sync' => false,
    ]);

    $createMediaCalls = 0;

    $client = Mockery::mock(ShopifyApiClient::class);
    $client->shouldReceive('graphql')
        ->andReturnUsing(function (string $query, array $variables = []) use (&$createMediaCalls): array {
            if (str_contains($query, 'query ProductByIdDetails')) {
                return [
                    'product' => [
                        'id' => 'gid://shopify/Product/1001',
                        'options' => [],
                        'category' => [
                            'id' => 'gid://shopify/TaxonomyCategory/aa-6-6',
                            'name' => 'Jewelry',
                        ],
                        'productCategory' => [
                            'productTaxonomyNode' => [
                                'id' => 'gid://shopify/TaxonomyCategory/aa-6-6',
                                'fullName' => 'Apparel & Accessories > Jewelry',
                            ],
                        ],
                        'variants' => ['nodes' => []],
                        'media' => [
                            'nodes' => [[
                                'id' => 'gid://shopify/MediaImage/9001',
                                'image' => [
                                    'url' => 'https://cdn.shopify.com/s/files/1/test/existing-image.png',
                                ],
                            ]],
                        ],
                        'images' => [
                            'nodes' => [],
                        ],
                    ],
                ];
            }

            if (str_contains($query, 'mutation ProductCreateMedia')) {
                $createMediaCalls++;

                return [
                    'productCreateMedia' => [
                        'media' => [['id' => 'gid://shopify/MediaImage/9999']],
                        'mediaUserErrors' => [],
                    ],
                ];
            }

            if (str_contains($query, 'mutation ProductReorderMedia')) {
                return [
                    'productReorderMedia' => [
                        'job' => ['id' => 'gid://shopify/Job/1'],
                        'mediaUserErrors' => [],
                    ],
                ];
            }

            throw new RuntimeException('Unexpected Shopify GraphQL call in image rename test.');
        });

    app()->instance(ShopifyApiClient::class, $client);

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product]));

    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($createMediaCalls)->toBe(0);

    $image->refresh();

    expect($image->shopify_id)->toBe('gid://shopify/MediaImage/9001');
    expect($image->last_shopify_synced_filename)->toBe('renamed-product-01.png');
    expect($image->needs_shopify_image_sync)->toBeFalse();
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

function approveWorkflowTestProduct(Product $product): void
{
    $firstApprover = User::factory()->create();
    $secondApprover = User::factory()->create();

    Approval::create([
        'product_id' => $product->id,
        'user_id' => $firstApprover->id,
        'approval_version' => $product->approval_version,
    ]);

    Approval::create([
        'product_id' => $product->id,
        'user_id' => $secondApprover->id,
        'approval_version' => $product->approval_version,
    ]);
}
