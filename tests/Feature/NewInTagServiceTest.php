<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\NewInTagService;
use App\Services\SkuListFilterService;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks drafts and linked products with all canonical new in tags without removing existing tags', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'new-in-tags.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'New Bracelet',
        'handle' => 'new-bracelet',
        'tags' => 'bracelets, new-in',
        'status' => 'active',
    ]);
    Variant::create([
        'product_id' => $product->id,
        'sku' => 'NEW-001',
    ]);
    $draft = NewProductDraft::create([
        'title' => $product->title,
        'handle' => $product->handle,
        'sku' => 'NEW-001',
        'tags' => 'bracelets, new-in',
        'status' => 'active',
        'origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED,
    ]);

    $service = app(NewInTagService::class);
    $result = $service->markDrafts(collect([$draft]));

    $draftTags = TagNormalizer::parseTokens($draft->fresh()->tags);
    $productTags = TagNormalizer::parseTokens($product->fresh()->tags);

    expect($result)->toBe(['updated' => 1, 'already_marked' => 0, 'failed' => 0])
        ->and($draftTags)->toContain('bracelets', 'new-arrivals', 'new-in', 'newbies')
        ->and($productTags)->toContain('bracelets', 'new-arrivals', 'new-in', 'newbies')
        ->and($service->markDrafts(collect([$draft->fresh()])))
        ->toBe(['updated' => 0, 'already_marked' => 1, 'failed' => 0]);
});

it('automatically adds collection-aware new in tags when normal products and stacks are created', function (): void {
    $normal = NewProductDraft::create([
        'title' => 'Pata Pata Sunset Bracelet',
        'type' => 'Bracelets',
        'tags' => 'pata-pata, bracelets',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $stack = NewProductDraft::create([
        'title' => 'Local Test',
        'type' => null,
        'tags' => 'livi-road-bundles, bundles, pata-pata-bracelet-stacks-new-in',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $normalTags = TagNormalizer::parseTokens($normal->tags);
    $stackTags = TagNormalizer::parseTokens($stack->tags);

    expect($normalTags)->toContain(
        'all-products',
        'all-products-collections',
        'exclude-from-the-sale',
        'new-arrivals',
        'new-in',
        'newbies',
        'pata-pata-new-in',
    )->and($normalTags)->not->toContain('pata-pata-bracelet-stacks-new-in')
        ->and($stackTags)->toContain(
            'all-products',
            'all-products-collections',
            'exclude-from-the-sale',
            'new-arrivals',
            'new-in',
            'newbies',
            'livi-road-bracelet-stacks-new-in',
        )->and($stackTags)->not->toContain(
            'livi-road-new-in',
            'pata-pata-new-in',
            'pata-pata-bracelet-stacks-new-in',
        );
});

it('filters drafts by a pasted case insensitive sku list including linked variants', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'sku-filter.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $directMatch = NewProductDraft::create([
        'title' => 'Direct SKU Match',
        'handle' => 'direct-sku-match',
        'sku' => 'NEW-101',
        'status' => 'draft',
    ]);
    NewProductDraft::create([
        'title' => 'Not Selected',
        'handle' => 'not-selected',
        'sku' => 'OTHER-001',
        'status' => 'draft',
    ]);
    $linkedProduct = Product::create([
        'import_id' => $import->id,
        'title' => 'Linked Variant Match',
        'handle' => 'linked-variant-match',
        'status' => 'active',
    ]);
    Variant::create([
        'product_id' => $linkedProduct->id,
        'sku' => 'LINKED-202',
    ]);
    $linkedMatch = NewProductDraft::create([
        'title' => $linkedProduct->title,
        'handle' => $linkedProduct->handle,
        'status' => 'active',
    ]);

    $service = app(SkuListFilterService::class);
    $matchedIds = $service
        ->applyToDrafts(NewProductDraft::query(), "new-101, linked-202\nNEW-101")
        ->orderBy('id')
        ->pluck('id')
        ->all();
    $matchedProductIds = $service
        ->applyToProducts(Product::query(), "missing-001\nLINKED-202")
        ->pluck('id')
        ->all();

    expect($service->parse("new-101, linked-202\nNEW-101"))
        ->toBe(['new-101', 'linked-202'])
        ->and($matchedIds)->toBe([$directMatch->id, $linkedMatch->id])
        ->and($matchedProductIds)->toBe([$linkedProduct->id]);
});
