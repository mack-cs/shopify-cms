<?php

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Models\Variant;
use App\Services\NewProductDraftCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a roundtrip update when the sku belongs to the linked product variant', function (): void {
    $product = Product::create([
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
        . "{$draft->id},{$draft->handle},{$draft->shopify_id},LAP041,Bold Acrylic Bracelet,Updated SEO description\n"
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
        'title' => 'Festival Lights Bracelet',
        'handle' => 'festival-lights-bracelet',
        'shopify_id' => 'gid://shopify/Product/8996433625224',
        'status' => 'active',
    ]);

    $otherProduct = Product::create([
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
        . "{$draft->id},{$draft->handle},{$draft->shopify_id},LAP041,Bold Acrylic Bracelet\n"
    );

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path);

    expect($result['updated'])->toBe(0)
        ->and($result['skipped_duplicate_sku'])->toBe(1)
        ->and(StyleProfile::query()->where('handle', $draft->handle)->exists())->toBeFalse();

    @unlink($path);
});
