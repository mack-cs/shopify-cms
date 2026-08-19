<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewProductDraftCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->draftCsvImport = Import::create([
        'filename' => 'new-product-draft-import.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
});

it('allows a roundtrip update when the sku belongs to the linked product variant', function (): void {
    $product = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Festival Lights Bracelet',
        'handle' => 'festival-lights-bracelet',
        'shopify_id' => 'gid://shopify/Product/8996433625224',
        'status' => 'active',
    ]);

    Variant::create([
        'product_id' => $product->id,
        'sku' => 'LAP041',
    ]);

    $draft = NewProductDraft::create([
        'title' => 'Festival Lights Bracelet',
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'sku' => 'LAP041',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-import-');
    file_put_contents(
        $path,
        "Draft ID,Handle,Shopify ID,SKU,SEO Title,SEO Description\n"
        ."{$draft->id},{$draft->handle},{$draft->shopify_id},LAP041,Bold Acrylic Bracelet,Updated SEO description\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    expect($result['updated'])->toBe(1)
        ->and($result['skipped_duplicate_sku'])->toBe(0);

    $profile = StyleProfile::query()->where('handle', $draft->handle)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->draft_seo_title)->toBe('Bold Acrylic Bracelet')
        ->and($profile->draft_seo_description)->toBe('Updated SEO description');

    @unlink($path);
});

it('still rejects an update when another product owns the same sku', function (): void {
    $product = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Festival Lights Bracelet',
        'handle' => 'festival-lights-bracelet',
        'shopify_id' => 'gid://shopify/Product/8996433625224',
        'status' => 'active',
    ]);

    $otherProduct = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Other Bracelet',
        'handle' => 'other-bracelet',
        'shopify_id' => 'gid://shopify/Product/999',
        'status' => 'active',
    ]);

    Variant::create([
        'product_id' => $product->id,
        'sku' => 'LAP041',
    ]);

    Variant::create([
        'product_id' => $otherProduct->id,
        'sku' => 'LAP041',
    ]);

    $draft = NewProductDraft::create([
        'title' => 'Festival Lights Bracelet',
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'sku' => 'LAP041',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-import-');
    file_put_contents(
        $path,
        "Draft ID,Handle,Shopify ID,SKU,SEO Title\n"
        ."{$draft->id},{$draft->handle},{$draft->shopify_id},LAP041,Bold Acrylic Bracelet\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    expect($result['updated'])->toBe(0)
        ->and($result['skipped_duplicate_sku'])->toBe(1)
        ->and(StyleProfile::query()->where('handle', $draft->handle)->exists())->toBeFalse();

    @unlink($path);
});

it('matches pricing updates by sku first and protects product name and handle', function (): void {
    $product = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Protected Bracelet',
        'handle' => 'protected-bracelet',
        'shopify_id' => 'gid://shopify/Product/1001',
        'status' => 'active',
        'batch' => 'import_original',
    ]);

    $variant = Variant::create([
        'product_id' => $product->id,
        'sku' => 'PRICE-001',
        'price' => '300.00',
        'compare_at_price' => '350.00',
    ]);

    $draft = NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'sku' => 'PRICE-001',
        'status' => 'active',
        'batch' => 'import_original',
        'variant_price' => '300.00',
        'variant_compare_at_price' => '350.00',
        'material_cost' => '50.00',
    ]);

    $otherDraft = NewProductDraft::create([
        'title' => 'Other Product',
        'handle' => 'other-product',
        'shopify_id' => 'gid://shopify/Product/2002',
        'sku' => 'OTHER-002',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-pricing-import-');
    file_put_contents(
        $path,
        "Draft ID,Handle,Shopify ID,Batch,SKU,Product Name,Price,Compare-at Price,Material Cost\n"
        ."{$otherDraft->id},changed-handle,{$draft->shopify_id},import_original,price-001,Changed Product Name,450,,71.7\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    $draft->refresh();
    $product->refresh();
    $variant->refresh();

    expect($result['updated'])->toBe(1)
        ->and($result['created'])->toBe(0)
        ->and($result['protected_conflict_count'])->toBe(2)
        ->and($result['protected_conflicts'][0])->toContain('Product name protected')
        ->and($result['protected_conflicts'][1])->toContain('Handle protected')
        ->and($result['pricing_batch'])->toStartWith('pricing_')
        ->and($draft->title)->toBe('Protected Bracelet')
        ->and($draft->handle)->toBe('protected-bracelet')
        ->and($draft->variant_price)->toBe('450.00')
        ->and($draft->variant_compare_at_price)->toBeNull()
        ->and($draft->material_cost)->toBe('71.70')
        ->and($draft->batch)->toBe($result['pricing_batch'])
        ->and($product->title)->toBe('Protected Bracelet')
        ->and($product->handle)->toBe('protected-bracelet')
        ->and($product->batch)->toBe($result['pricing_batch'])
        ->and($variant->price)->toBe('450.00')
        ->and($variant->compare_at_price)->toBeNull()
        ->and($otherDraft->fresh()->sku)->toBe('OTHER-002');

    @unlink($path);
});

it('falls back from sku to shopify gid and then handle when matching drafts', function (): void {
    $gidProduct = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'GID Product',
        'handle' => 'gid-product',
        'shopify_id' => 'gid://shopify/Product/3003',
        'status' => 'active',
    ]);
    Variant::create(['product_id' => $gidProduct->id, 'sku' => 'OLD-GID-SKU', 'price' => '100.00']);
    $gidDraft = NewProductDraft::create([
        'title' => $gidProduct->title,
        'handle' => $gidProduct->handle,
        'shopify_id' => $gidProduct->shopify_id,
        'sku' => 'OLD-GID-SKU',
        'status' => 'active',
    ]);

    $handleProduct = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Handle Product',
        'handle' => 'handle-product',
        'status' => 'active',
    ]);
    Variant::create(['product_id' => $handleProduct->id, 'sku' => 'HANDLE-SKU', 'price' => '100.00']);
    $handleDraft = NewProductDraft::create([
        'title' => $handleProduct->title,
        'handle' => $handleProduct->handle,
        'sku' => 'HANDLE-SKU',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-match-import-');
    file_put_contents(
        $path,
        "Handle,Shopify ID,SKU,Price\n"
        ."gid-product,{$gidDraft->shopify_id},NEW-GID-SKU,210\n"
        ."handle-product,,,220\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    expect($result['updated'])->toBe(2)
        ->and($gidDraft->fresh()->sku)->toBe('NEW-GID-SKU')
        ->and($gidDraft->fresh()->variant_price)->toBe('210.00')
        ->and($handleDraft->fresh()->variant_price)->toBe('220.00');

    @unlink($path);
});

it('applies the cms category selections when product type comes from the csv', function (): void {
    $product = Product::create([
        'import_id' => $this->draftCsvImport->id,
        'title' => 'Mapped Bracelet',
        'handle' => 'mapped-bracelet',
        'shopify_id' => 'gid://shopify/Product/4004',
        'type' => 'Necklaces',
        'product_category' => 'gid://shopify/TaxonomyCategory/aa-6-8',
        'google_product_category' => '189',
        'status' => 'active',
    ]);
    Variant::create([
        'product_id' => $product->id,
        'sku' => 'MAP-001',
    ]);
    $draft = NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'shopify_id' => $product->shopify_id,
        'sku' => 'MAP-001',
        'type' => 'Necklaces',
        'product_category' => 'gid://shopify/TaxonomyCategory/aa-6-8',
        'google_product_category' => '189',
        'status' => 'active',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-category-import-');
    file_put_contents(
        $path,
        "SKU,Product Type,Product Category,Google Product Category\n"
        ."MAP-001,bracelet,Incorrect Category,999\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    $draft->refresh();
    $product->refresh();

    expect($result['updated'])->toBe(1)
        ->and($draft->type)->toBe('Bracelets')
        ->and($draft->product_category)->toBe('gid://shopify/TaxonomyCategory/aa-6-3')
        ->and($draft->google_product_category)->toBe('191')
        ->and($product->type)->toBe('Bracelets')
        ->and($product->product_category)->toBe('gid://shopify/TaxonomyCategory/aa-6-3')
        ->and($product->google_product_category)->toBe('191');

    @unlink($path);
});

it('keeps an existing material cost when the csv material cost is blank', function (): void {
    $draft = NewProductDraft::create([
        'title' => 'Cost Protected Draft',
        'handle' => 'cost-protected-draft',
        'sku' => 'COST-001',
        'material_cost' => '81.25',
        'status' => 'draft',
    ]);

    $path = tempnam(sys_get_temp_dir(), 'draft-blank-cost-import-');
    file_put_contents(
        $path,
        "SKU,Material Cost\n"
        ."COST-001,\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    expect($result['updated'])->toBe(1)
        ->and($draft->fresh()->material_cost)->toBe('81.25');

    @unlink($path);
});
