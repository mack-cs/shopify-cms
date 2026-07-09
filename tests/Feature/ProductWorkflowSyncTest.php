<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\NewProductDraftApproval;
use App\Models\Product;
use App\Models\Approval;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\StyleProfile;
use App\Models\User;
use App\Models\Variant;
use App\Services\HeaderStore;
use App\Services\NewProductDraftSeeder;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftShopifyCreator;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

it('keeps removed complementary products removed after draft sync and reseed', function (): void {
    $product = createWorkflowTestProduct([
        'approval_version' => 1,
    ]);

    $originalComplementary = implode('; ', [
        'gid://shopify/Product/2001',
        'gid://shopify/Product/2002',
        'gid://shopify/Product/2003',
        'gid://shopify/Product/2004',
    ]);
    $updatedComplementary = implode('; ', [
        'gid://shopify/Product/2001',
        'gid://shopify/Product/2002',
        'gid://shopify/Product/2003',
    ]);

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => $originalComplementary,
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => $originalComplementary,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => $updatedComplementary,
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    app(NewProductDraftProductSync::class)->syncToExistingProduct(
        $draft,
        ensureApprovalReset: false,
        attributes: ['complementary_products']
    );

    $metafield = ShopifyMetafield::query()
        ->where('import_id', $product->import_id)
        ->where('handle', $product->handle)
        ->where('namespace', 'shopify--discovery--product_recommendation')
        ->where('key', 'complementary_products')
        ->firstOrFail();

    expect($metafield->value)->toBe($updatedComplementary);

    $row = ShopifyRow::query()
        ->where('import_id', $product->import_id)
        ->where('handle', $product->handle)
        ->where('row_type', 'product_primary')
        ->firstOrFail();

    $row->set(HeaderStore::COMPLEMENTARY_PRODUCTS, $originalComplementary);
    $row->save();

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->complementary_products)->toBe($updatedComplementary);
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

it('records warnings for conflicting draft variant defaults when seeding from the linked product', function (): void {
    $product = createWorkflowTestProduct();
    createWorkflowTestVariant($product, [
        'sku' => 'SHOPIFY-SKU',
        'price' => '200.00',
        'compare_at_price' => '250.00',
        'inventory_qty' => 8,
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'sku' => 'DRAFT-SKU',
        'title' => $product->title,
        'variant_price' => '199.00',
        'variant_compare_at_price' => '249.00',
        'variant_inventory_qty' => 5,
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);
    $warningFields = collect($seeded->shopifySyncWarnings())->pluck('field')->all();

    expect($warningFields)->toContain('sku');
    expect($warningFields)->toContain('variant_price');
    expect($warningFields)->toContain('variant_compare_at_price');
    expect($warningFields)->toContain('variant_inventory_qty');
    expect($seeded->variant_price)->toBe('199.00');
});

it('does not record variant default warnings when only decimal formatting differs', function (): void {
    $product = createWorkflowTestProduct();
    createWorkflowTestVariant($product, [
        'price' => '200.00',
        'compare_at_price' => '250.00',
        'inventory_qty' => 8,
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'variant_price' => '200',
        'variant_compare_at_price' => '250.0',
        'variant_inventory_qty' => '8',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);
    $warningFields = collect($seeded->shopifySyncWarnings())->pluck('field')->all();

    expect($warningFields)->not->toContain('variant_price');
    expect($warningFields)->not->toContain('variant_compare_at_price');
    expect($warningFields)->not->toContain('variant_inventory_qty');
});

it('treats non-draft shopify status as authoritative for the draft without a warning', function (): void {
    $product = createWorkflowTestProduct([
        'status' => 'active',
        'approval_version' => 1,
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'status' => 'draft',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->status)->toBe('active');
    expect(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->not->toContain('status');
});

it('does not record sync warnings when published or status only differ by casing', function (): void {
    $product = createWorkflowTestProduct([
        'published' => 'false',
        'status' => 'ACTIVE',
        'approval_version' => 1,
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'published' => 'FALSE',
        'status' => 'active',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);
    $warningFields = collect($seeded->shopifySyncWarnings())->pluck('field')->all();

    expect($seeded->published)->toBe('false')
        ->and($seeded->status)->toBe('active')
        ->and($warningFields)->not->toContain('published')
        ->and($warningFields)->not->toContain('status');
});

it('hides existing boolean casing sync warnings', function (): void {
    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => 'boolean-warning-product',
        'shopify_id' => 'gid://shopify/Product/9001',
        'title' => 'Boolean Warning Product',
        'published' => 'FALSE',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'shopify_sync_warnings' => [[
            'field' => 'published',
            'label' => 'Published',
            'draft_value' => 'FALSE',
            'shopify_value' => 'false',
        ]],
    ]));

    expect($draft->shopifySyncWarnings())->toBe([])
        ->and($draft->shopifySyncWarningCount())->toBe(0);
});

it('does not flag uvp short paragraph conflicts when only rich text formatting and punctuation differ', function (): void {
    $product = createWorkflowTestProduct([
        'uvp_short_paragraph' => '<p><strong>A Night in Barcelona</strong> is vibrant playful sophistication. For women who embody <strong>passion</strong>, <strong>confidence</strong>, and radiant <strong>allure</strong>.</p>',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'uvp_short_paragraph' => '<p><strong>A Night in Barcelona</strong> is vibrant playful sophistication. For women who embody <strong>passion</strong>/<strong>confidence</strong>, and radiant <strong>allure</strong>. </p>',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->uvp_short_paragraph)->toBe($draft->uvp_short_paragraph);
    expect($seeded->shopifySyncWarnings())->toBe([]);
    expect($seeded->shopifySyncWarningCount())->toBe(0);
});

it('does not flag uvp short paragraph conflicts when equivalent html differs only by spacing and line breaks', function (): void {
    $product = createWorkflowTestProduct([
        'uvp_short_paragraph' => "<p>The Emberwing Bracelet is radiant freedom and endless elegance. For women who embody <strong>grace</strong>, <strong>light</strong>, <strong>power</strong>.</p>",
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'uvp_short_paragraph' => "<p>\n  The Emberwing Bracelet is radiant freedom and endless elegance.\n  For women who embody <strong>grace</strong>, <strong>light</strong>, <strong>power</strong>.\n</p>",
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->uvp_short_paragraph)->toBe($draft->uvp_short_paragraph);
    expect($seeded->shopifySyncWarnings())->toBe([]);
    expect($seeded->shopifySyncWarningCount())->toBe(0);
});

it('does not flag complementary product warnings when the draft contains the shopify refs plus local backups', function (): void {
    $product = createWorkflowTestProduct([
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => implode('; ', [
            'gid://shopify/Product/8516761714824',
            'gid://shopify/Product/8516761518216',
            'gid://shopify/Product/8516761780360',
            'gid://shopify/Product/8835930783880',
        ]),
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', [
                'gid://shopify/Product/8516761518216',
                'gid://shopify/Product/8516761780360',
                'gid://shopify/Product/8516761714824',
            ]),
        ],
    ]);

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->complementary_products)->toBe($draft->complementary_products);
    expect(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->not->toContain('complementary_products');
});

it('uses the imported complementary metafield before the row so local 4-vs-shopify-3 does not warn', function (): void {
    $product = createWorkflowTestProduct([
        'approval_version' => 1,
    ]);

    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/8745850798216',
        'handle' => 'comp-a',
        'title' => 'Comp A',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/8816597336200',
        'handle' => 'comp-b',
        'title' => 'Comp B',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/8816597467272',
        'handle' => 'comp-c',
        'title' => 'Comp C',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/8745860989064',
        'handle' => 'comp-d',
        'title' => 'Comp D',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => implode('; ', [
            'gid://shopify/Product/8745850798216',
            'gid://shopify/Product/8816597336200',
            'gid://shopify/Product/8816597467272',
            'gid://shopify/Product/8745860989064',
        ]),
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', [
                'gid://shopify/Product/8745850798216',
                'gid://shopify/Product/8816597336200',
                'gid://shopify/Product/8816597467272',
                'gid://shopify/Product/8745860989064',
            ]),
        ],
    ]);

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => implode('; ', [
            'gid://shopify/Product/8745850798216',
            'gid://shopify/Product/8816597336200',
            'gid://shopify/Product/8816597467272',
        ]),
    ]);

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->complementary_products)->toBe($draft->complementary_products);
    expect(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->not->toContain('complementary_products');
});

it('ignores imported shopify complementary refs beyond the first three when checking draft conflicts', function (): void {
    $product = createWorkflowTestProduct([
        'approval_version' => 1,
    ]);

    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9745850798216',
        'handle' => 'shopify-first-a',
        'title' => 'Shopify First A',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9816597336200',
        'handle' => 'shopify-first-b',
        'title' => 'Shopify First B',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9816597467272',
        'handle' => 'shopify-first-c',
        'title' => 'Shopify First C',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9745860989064',
        'handle' => 'shopify-extra-d',
        'title' => 'Shopify Extra D',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => implode('; ', [
            'gid://shopify/Product/9745850798216',
            'gid://shopify/Product/9816597336200',
            'gid://shopify/Product/9816597467272',
        ]),
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => implode('; ', [
            'gid://shopify/Product/9745850798216',
            'gid://shopify/Product/9816597336200',
            'gid://shopify/Product/9816597467272',
            'gid://shopify/Product/9745860989064',
        ]),
    ]);

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect($seeded->complementary_products)->toBe($draft->complementary_products);
    expect(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->not->toContain('complementary_products');
});

it('flags complementary product warnings when any compared complementary product is no longer active', function (): void {
    $product = createWorkflowTestProduct([
        'approval_version' => 1,
    ]);

    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9745850798216',
        'handle' => 'inactive-comp-a',
        'title' => 'Inactive Comp A',
        'status' => 'active',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9816597336200',
        'handle' => 'inactive-comp-b',
        'title' => 'Inactive Comp B',
        'status' => 'draft',
        'approval_version' => 1,
    ]);
    Product::create([
        'import_id' => $product->import_id,
        'shopify_id' => 'gid://shopify/Product/9816597467272',
        'handle' => 'inactive-comp-c',
        'title' => 'Inactive Comp C',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => implode('; ', [
            'gid://shopify/Product/9745850798216',
            'gid://shopify/Product/9816597336200',
            'gid://shopify/Product/9816597467272',
        ]),
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => implode('; ', [
            'gid://shopify/Product/9745850798216',
            'gid://shopify/Product/9816597336200',
            'gid://shopify/Product/9816597467272',
        ]),
    ]);

    $seeded = app(NewProductDraftSeeder::class)->upsertFromProduct($product);

    expect(collect($seeded->shopifySyncWarnings())->pluck('field')->all())->toContain('complementary_products');
    expect($draft->refresh()->shopifySyncWarnings()[0]['field'])->toBe('complementary_products');
    expect($draft->refresh()->shopifySyncWarnings()[0]['draft_value'])->toContain('Inactive Comp A');
    expect($draft->refresh()->shopifySyncWarnings()[0]['draft_value'])->toContain('Inactive Comp B');
    expect($draft->refresh()->shopifySyncWarnings()[0]['shopify_value'])->toContain('Inactive Comp B');
    expect($draft->refresh()->shopifySyncWarnings()[0]['draft_value'])->not->toContain('gid://shopify/Product/');
});

it('allows resolving shopify warnings one field at a time with different decisions', function (): void {
    $product = createWorkflowTestProduct([
        'vendor' => 'Shopify Vendor',
        'type' => 'Bracelets',
        'approval_version' => 1,
    ]);

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'vendor' => 'Draft Vendor',
        'type' => 'Draft Type',
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'shopify_sync_warnings' => [
            [
                'field' => 'vendor',
                'label' => 'Vendor',
                'draft_value' => 'Draft Vendor',
                'shopify_value' => 'Shopify Vendor',
            ],
            [
                'field' => 'type',
                'label' => 'Type',
                'draft_value' => 'Draft Type',
                'shopify_value' => 'Bracelets',
            ],
        ],
    ]));

    $keepResult = NewProductDraftResource::resolveSingleShopifyWarning($draft->fresh(), 'vendor', 'draft');

    $draft->refresh();
    $product->refresh();

    expect($keepResult['resolved'])->toBeTrue();
    expect($keepResult['synced'])->toBeTrue();
    expect($draft->vendor)->toBe('Draft Vendor');
    expect($product->vendor)->toBe('Draft Vendor');
    expect($draft->shopifySyncWarningCount())->toBe(1);
    expect($draft->shopifySyncWarnings()[0]['field'])->toBe('type');

    $shopifyResult = NewProductDraftResource::resolveSingleShopifyWarning($draft->fresh(), 'type', 'shopify');

    $draft->refresh();

    expect($shopifyResult['resolved'])->toBeTrue();
    expect($shopifyResult['synced'])->toBeFalse();
    expect($draft->type)->toBe('Bracelets');
    expect($draft->shopifySyncWarningCount())->toBe(0);
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

    fakeWorkflowShopifyGraphql(function (string $query, array $variables = []) use (&$capturedMetafields): array {
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

it('prefers linked draft complementary products over stale row data during approved shopify sync', function (): void {
    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1101',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COMPLEMENTARY_PRODUCTS => implode('; ', [
                'gid://shopify/Product/3001',
                'gid://shopify/Product/3002',
                'gid://shopify/Product/3003',
            ]),
        ],
    ]);

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'title' => $product->title,
        'complementary_products' => implode('; ', [
            'gid://shopify/Product/4001',
            'gid://shopify/Product/4002',
            'gid://shopify/Product/4003',
            'gid://shopify/Product/4004',
        ]),
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => '',
        'namespace' => 'shopify--discovery--product_recommendation',
        'key' => 'complementary_products',
        'type' => 'list.product_reference',
        'value' => '',
    ]);

    $capturedMetafields = null;

    fakeWorkflowShopifyGraphql(function (string $query, array $variables = []) use (&$capturedMetafields): array {
        if (str_contains($query, 'query ProductByIdDetails')) {
            return [
                'product' => [
                    'id' => 'gid://shopify/Product/1101',
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

    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product]),
        [ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS],
        [ProductShopifyUpdater::CORE_FIELD_COMPLEMENTARY_PRODUCTS]
    );

    expect($result['updated'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect(json_decode($capturedMetafields[0]['value'], true))->toBe([
        'gid://shopify/Product/4001',
        'gid://shopify/Product/4002',
        'gid://shopify/Product/4003',
    ]);
});

it('does not send archived product status to shopify during sync', function (): void {
    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1201',
        'status' => 'archived',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    fakeWorkflowShopifyGraphql(function (string $query): array {
        if (str_contains($query, 'mutation ProductUpdate')) {
            throw new RuntimeException('Archived status should not trigger a Shopify product status update.');
        }

        if (str_contains($query, 'query ProductByIdDetails')) {
            return [
                'product' => [
                    'id' => 'gid://shopify/Product/1201',
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
                    'images' => ['nodes' => []],
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

        throw new RuntimeException('Unexpected Shopify GraphQL call in archived status sync test.');
    });

    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product]),
        [ProductShopifyUpdater::SYNC_SCOPE_PRODUCT],
        [ProductShopifyUpdater::CORE_FIELD_STATUS]
    );

    expect($result['updated'])->toBe(1);
    expect($result['failed'])->toBe(0);
});

it('reuses the existing Shopify media when only an image filename changes', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/rename-only.png', 'rename-only-image');

    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1001',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    $image = \App\Models\Image::withoutEvents(fn () => \App\Models\Image::create([
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
    ]));

    $createMediaCalls = 0;

    fakeWorkflowShopifyGraphql(function (string $query) use (&$createMediaCalls): array {
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

        if (str_contains($query, 'query ProductByHandleDetails')) {
            return [
                'productByHandle' => [
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

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product]));

    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($createMediaCalls)->toBe(0);

    $image->refresh();

    expect($image->shopify_id)->toBe('gid://shopify/MediaImage/9001');
    expect($image->last_shopify_synced_filename)->toBe('renamed-product-01.png');
    expect($image->needs_shopify_image_sync)->toBeFalse();
});

it('sends every selected bundle image when creating a stack in Shopify', function (): void {
    $firstImage = 'https://cdn.shopify.com/s/files/1/test/stack-01.jpg';
    $secondImage = 'https://cdn.shopify.com/s/files/1/test/stack-02.jpg';

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'title' => 'Test Bracelet Stack',
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'draft',
        'image_url' => $firstImage,
        'bundle_image_urls' => [$firstImage, $secondImage],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    NewProductDraftApproval::create([
        'new_product_draft_id' => $draft->id,
        'user_id' => User::factory()->create()->id,
        'approval_version' => 1,
    ]);
    NewProductDraftApproval::create([
        'new_product_draft_id' => $draft->id,
        'user_id' => User::factory()->create()->id,
        'approval_version' => 1,
    ]);

    $mediaInput = null;

    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use (&$mediaInput) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = (array) ($payload['variables'] ?? []);

        if (str_contains($query, 'mutation ProductCreateMedia')) {
            $mediaInput = $variables['media'] ?? null;

            return Http::response([
                'data' => [
                    'productCreateMedia' => [
                        'media' => [
                            ['id' => 'gid://shopify/MediaImage/1401'],
                            ['id' => 'gid://shopify/MediaImage/1402'],
                        ],
                        'mediaUserErrors' => [],
                    ],
                ],
            ]);
        }

        if (str_contains($query, 'mutation ProductCreate')) {
            return Http::response([
                'data' => [
                    'productCreate' => [
                        'product' => [
                            'id' => 'gid://shopify/Product/1401',
                            'handle' => 'test-bracelet-stack',
                        ],
                        'userErrors' => [],
                    ],
                ],
            ]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call in stack image create test.');
    });

    $result = app(NewProductDraftShopifyCreator::class)->createApprovedDrafts(collect([$draft]));

    expect($result['created'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($mediaInput)->toBe([
        [
            'originalSource' => $firstImage,
            'mediaContentType' => 'IMAGE',
        ],
        [
            'originalSource' => $secondImage,
            'mediaContentType' => 'IMAGE',
        ],
    ]);
    expect($draft->fresh()->handle)->toBe('test-bracelet-stack');
});

it('moves videos behind the approved image order during Shopify image sync', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/media-order-01.png', 'first-image');
    Storage::disk('public')->put('product-images/test/media-order-02.png', 'second-image');

    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1301',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    \App\Models\Image::withoutEvents(fn () => \App\Models\Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/9101',
        'sync_state' => \App\Models\Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/s/files/1/test/media-order-01.png',
        'image_path' => 'product-images/test/media-order-01.png',
        'backup_status' => \App\Models\Image::BACKUP_STATUS_PENDING,
        'position' => 1,
        'approved_filename' => 'media-order-01.png',
        'last_shopify_synced_filename' => 'media-order-01.png',
        'needs_shopify_image_sync' => false,
    ]));

    \App\Models\Image::withoutEvents(fn () => \App\Models\Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/9102',
        'sync_state' => \App\Models\Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/s/files/1/test/media-order-02.png',
        'image_path' => 'product-images/test/media-order-02.png',
        'backup_status' => \App\Models\Image::BACKUP_STATUS_PENDING,
        'position' => 2,
        'approved_filename' => 'media-order-02.png',
        'last_shopify_synced_filename' => 'media-order-02.png',
        'needs_shopify_image_sync' => false,
    ]));

    $reorderMoves = null;
    $shopifyProduct = [
        'id' => 'gid://shopify/Product/1301',
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
            'nodes' => [
                [
                    'id' => 'gid://shopify/Video/7001',
                    'mediaContentType' => 'VIDEO',
                ],
                [
                    'id' => 'gid://shopify/MediaImage/9101',
                    'mediaContentType' => 'IMAGE',
                    'image' => [
                        'url' => 'https://cdn.shopify.com/s/files/1/test/media-order-01.png',
                    ],
                ],
                [
                    'id' => 'gid://shopify/MediaImage/9102',
                    'mediaContentType' => 'IMAGE',
                    'image' => [
                        'url' => 'https://cdn.shopify.com/s/files/1/test/media-order-02.png',
                    ],
                ],
            ],
        ],
        'images' => ['nodes' => []],
    ];

    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use ($shopifyProduct, &$reorderMoves) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = (array) ($payload['variables'] ?? []);

        if (str_contains($query, 'query ProductByIdDetails')) {
            return Http::response(['data' => ['product' => $shopifyProduct]]);
        }

        if (str_contains($query, 'query ProductByHandleDetails')) {
            return Http::response(['data' => ['productByHandle' => $shopifyProduct]]);
        }

        if (str_contains($query, 'mutation ProductCreateMedia')) {
            throw new RuntimeException('Existing media should be reused.');
        }

        if (str_contains($query, 'mutation ProductReorderMedia')) {
            $reorderMoves = $variables['moves'] ?? null;

            return Http::response([
                'data' => [
                    'productReorderMedia' => [
                        'job' => ['id' => 'gid://shopify/Job/1'],
                        'mediaUserErrors' => [],
                    ],
                ],
            ]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call in media order test.');
    });

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product]));

    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($reorderMoves)->toBe([
        ['id' => 'gid://shopify/MediaImage/9101', 'newPosition' => '0'],
        ['id' => 'gid://shopify/MediaImage/9102', 'newPosition' => '1'],
        ['id' => 'gid://shopify/Video/7001', 'newPosition' => '2'],
    ]);
});

it('uses media image ids for ordering when local images still store legacy Shopify image ids', function (): void {
    $product = createWorkflowTestProduct([
        'shopify_id' => 'gid://shopify/Product/1302',
        'approval_version' => 1,
    ]);

    approveWorkflowTestProduct($product);

    $image = \App\Models\Image::withoutEvents(fn () => \App\Models\Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductImage/9201',
        'sync_state' => \App\Models\Image::SYNC_STATE_LOCAL_UPDATED,
        'local_dirty' => true,
        'src' => 'https://cdn.shopify.com/s/files/1/test/legacy-stack-01.png',
        'image_path' => null,
        'backup_status' => \App\Models\Image::BACKUP_STATUS_PENDING,
        'position' => 1,
        'approved_filename' => 'legacy-stack-01.png',
        'needs_shopify_image_sync' => true,
    ]));

    $reorderMoves = null;
    $shopifyProduct = [
        'id' => 'gid://shopify/Product/1302',
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
            'nodes' => [
                [
                    'id' => 'gid://shopify/Video/8001',
                    'mediaContentType' => 'VIDEO',
                ],
                [
                    'id' => 'gid://shopify/MediaImage/9201',
                    'mediaContentType' => 'IMAGE',
                    'image' => [
                        'url' => 'https://cdn.shopify.com/s/files/1/test/legacy-stack-01.png',
                    ],
                ],
            ],
        ],
        'images' => [
            'nodes' => [
                [
                    'id' => 'gid://shopify/ProductImage/9201',
                    'url' => 'https://cdn.shopify.com/s/files/1/test/legacy-stack-01.png',
                ],
            ],
        ],
    ];

    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use ($shopifyProduct, &$reorderMoves) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = (array) ($payload['variables'] ?? []);

        if (str_contains($query, 'query ProductByIdDetails')) {
            return Http::response(['data' => ['product' => $shopifyProduct]]);
        }

        if (str_contains($query, 'query ProductByHandleDetails')) {
            return Http::response(['data' => ['productByHandle' => $shopifyProduct]]);
        }

        if (str_contains($query, 'mutation ProductCreateMedia')) {
            throw new RuntimeException('Image without a backup should not be republished.');
        }

        if (str_contains($query, 'mutation ProductReorderMedia')) {
            $reorderMoves = $variables['moves'] ?? null;

            return Http::response([
                'data' => [
                    'productReorderMedia' => [
                        'job' => ['id' => 'gid://shopify/Job/2'],
                        'mediaUserErrors' => [],
                    ],
                ],
            ]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call in legacy image media order test.');
    });

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product]));

    expect($result['synced'])->toBe(1);
    expect($result['failed'])->toBe(0);
    expect($reorderMoves)->toBe([
        ['id' => 'gid://shopify/MediaImage/9201', 'newPosition' => '0'],
        ['id' => 'gid://shopify/Video/8001', 'newPosition' => '1'],
    ]);
    expect(collect($result['warnings'])->pluck('warning')->first())
        ->toContain('could not be republished');
    expect($image->fresh()->needs_shopify_image_sync)->toBeTrue();
});

function fakeWorkflowShopifyGraphql(callable $handler): void
{
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use ($handler) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = (array) ($payload['variables'] ?? []);

        return Http::response([
            'data' => $handler($query, $variables),
        ]);
    });
}

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

function createWorkflowTestVariant(Product $product, array $overrides = []): Variant
{
    return Variant::withoutEvents(fn (): Variant => Variant::create(array_merge([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/1001',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'sku' => 'WORKFLOW-SKU',
        'barcode' => 'WORKFLOW-SKU',
        'price' => '200.00',
        'compare_at_price' => '250.00',
        'inventory_tracked' => true,
        'inventory_qty' => 8,
        'weight' => '1.000',
        'weight_unit' => 'g',
        'position' => 1,
    ], $overrides)));
}
