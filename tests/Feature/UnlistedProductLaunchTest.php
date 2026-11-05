<?php

use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\NewProductDraftCsvImporter;
use App\Services\NewProductDraftProductSync;
use App\Services\NewProductDraftShopifyCreator;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('imports the complimentary bracelet file as an unlisted launch without the normal new-product tags', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'unlisted-launch');
    file_put_contents($path, <<<'CSV'
SKU,Tags,Name,Description,UVP,SEO Title,SEO Description,Price,Inventory
LABN1,the-extraordinary-few,The Pearl Affair,"A luminous bracelet.",Short UVP,Short title,Short description that is outside the normal SEO length.,0,200
CSV);

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path, unlistedLaunch: true);

    expect($result['created'])->toBe(1)
        ->and($result['seo_drafts_upserted'])->toBe(1);

    $draft = NewProductDraft::query()->where('sku', 'LABN1')->first();
    expect($draft)->not->toBeNull()
        ->and($draft->isUnlistedLaunch())->toBeTrue()
        ->and($draft->status)->toBe('unlisted')
        ->and($draft->published)->toBe('false')
        ->and($draft->title)->toBe('The Pearl Affair')
        ->and($draft->body_html)->toBe('A luminous bracelet.')
        ->and($draft->uvp_short_paragraph)->toBe('Short UVP')
        ->and((float) $draft->variant_price)->toBe(0.0)
        ->and($draft->variant_inventory_qty)->toBe(200)
        ->and($draft->tags)->toBe('the-extraordinary-few');

    $profile = StyleProfile::query()->where('sku', 'LABN1')->first();
    expect($profile?->draft_seo_title)->toBe('Short title')
        ->and($profile?->draft_seo_description)->toBe('Short description that is outside the normal SEO length.');
});

it('imports every complimentary bracelet from the launch file', function (): void {
    $path = 'C:\\Users\\shona\\Downloads\\LA New Products Template - Complimentary bracelets For CMS (1).csv';
    if (! is_file($path)) {
        $this->markTestSkipped('Launch CSV is not on this machine.');
    }

    $result = app(NewProductDraftCsvImporter::class)->importFromPath($path, unlistedLaunch: true);

    expect($result['created'])->toBe(13)
        ->and($result['failed_skus'])->toBe([]);

    $drafts = NewProductDraft::query()->whereIn('sku', [
        'LABN1', 'LABN2', 'LABN3', 'LABN4', 'LABN5', 'LABN6', 'LABN7',
        'LABN8', 'LABN9', 'LABN10', 'LABN11', 'LABN12', 'LABN13',
    ])->get();

    expect($drafts)->toHaveCount(13);
    expect($drafts->every(fn (NewProductDraft $draft): bool => $draft->isUnlistedLaunch()
        && $draft->status === 'unlisted'
        && $draft->tags === 'the-extraordinary-few'
        && (float) $draft->variant_price === 0.0
        && (int) $draft->variant_inventory_qty > 0
        && trim((string) $draft->title) !== ''
        && trim((string) $draft->body_html) !== ''
        && trim((string) $draft->uvp_short_paragraph) !== ''))->toBeTrue();
    expect($drafts->firstWhere('sku', 'LABN1')?->variant_inventory_qty)->toBe(200);
    expect($drafts->firstWhere('sku', 'LABN2')?->variant_inventory_qty)->toBe(15);
    expect(StyleProfile::query()->whereIn('sku', $drafts->pluck('sku'))->count())->toBe(13);
});

it('keeps a normal csv import on the approval rules when the unlisted launch is off', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'normal-launch');
    file_put_contents($path, <<<'CSV'
SKU,Tags,Name,Description,Price,Inventory
LABN2,summer,Normal Bracelet,A normal bracelet.,189.00,15
CSV);

    app(NewProductDraftCsvImporter::class)->importFromPath($path);

    $draft = NewProductDraft::query()->where('sku', 'LABN2')->first();
    expect($draft->isUnlistedLaunch())->toBeFalse()
        ->and($draft->status)->not->toBe('unlisted')
        ->and($draft->title)->toBe('Normal Bracelet')
        ->and($draft->tags)->toContain('new-in')
        ->and($draft->tags)->toContain('all-products');
});

it('sends an unlisted launch to Shopify with its price inventory seo and image', function (): void {
    $draft = NewProductDraft::create([
        'sku' => 'LABN3',
        'title' => 'Amethyst Allure',
        'body_html' => 'A purple bracelet.',
        'uvp_short_paragraph' => 'Rich colour.',
        'tags' => 'the-extraordinary-few, new-in, all-products',
        'status' => 'active',
        'published' => 'false',
        'variant_price' => 0,
        'variant_inventory_qty' => 15,
        'image_url' => 'https://cdn.example.com/amethyst.jpg',
        'unlisted_launch' => true,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);
    StyleProfile::create([
        'handle' => 'amethyst-allure',
        'sku' => 'LABN3',
        'draft_seo_title' => 'Amethyst Allure Bracelet | Leigh Avenue',
        'draft_seo_description' => 'A limited-edition purple bracelet.',
        'draft_image_alt_text' => 'Amethyst Allure bracelet on a linen cloth',
        'seo_sync_status' => 'draft',
    ]);

    $draft->refresh();
    expect($draft->status)->toBe('unlisted')
        ->and($draft->tags)->toBe('the-extraordinary-few');

    $input = null;
    $channelInput = null;
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.inventory_location_id', 'gid://shopify/Location/7');

    Http::fake(function ($request) use (&$input, &$channelInput, $draft) {
        $query = (string) ($request->data()['query'] ?? '');
        $variables = (array) ($request->data()['variables'] ?? []);

        if (str_contains($query, 'metafieldDefinitions')) {
            return Http::response(['data' => ['metafieldDefinitions' => ['nodes' => [[
                'id' => 'gid://shopify/MetafieldDefinition/1',
                'type' => ['name' => 'id'],
            ]]]]]);
        }
        if (str_contains($query, 'productByIdentifier')) {
            return Http::response(['data' => ['productByIdentifier' => null]]);
        }
        if (str_contains($query, 'query VariantsBySku')) {
            return Http::response(['data' => ['productVariants' => ['nodes' => []]]]);
        }
        if (str_contains($query, 'query SalesChannels')) {
            return Http::response(['data' => ['publications' => [
                'nodes' => [
                    ['id' => 'gid://shopify/Publication/1'],
                    ['id' => 'gid://shopify/Publication/2'],
                ],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }
        if (str_contains($query, 'mutation PublishUnlistedProduct')) {
            $channelInput = $variables['input'] ?? null;

            return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
        }
        if (str_contains($query, 'mutation ProductSetCreate')) {
            $input = $variables['input'] ?? null;

            return Http::response(['data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/501',
                    'handle' => 'amethyst-allure',
                    'status' => 'UNLISTED',
                    'metafield' => ['value' => (string) $draft->id],
                    'variants' => ['nodes' => [[
                        'id' => 'gid://shopify/ProductVariant/501',
                        'sku' => 'LABN3',
                        'barcode' => 'LABN3',
                        'price' => '0.00',
                        'compareAtPrice' => null,
                        'inventoryItem' => [
                            'id' => 'gid://shopify/InventoryItem/501',
                            'tracked' => true,
                            'sku' => 'LABN3',
                            'inventoryLevel' => ['quantities' => [[
                                'name' => 'on_hand',
                                'quantity' => 15,
                            ]]],
                        ],
                    ]]],
                ],
                'userErrors' => [],
            ]]]);
        }

        throw new RuntimeException($query);
    });

    $result = app(NewProductDraftShopifyCreator::class)->createApprovedDrafts(collect([$draft]));

    expect($result['created'])->toBe(1)
        ->and($result['skipped_not_approved'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and($input['status'])->toBe('UNLISTED')
        ->and($input['tags'])->toBe(['the-extraordinary-few'])
        ->and($input['descriptionHtml'])->toBe('A purple bracelet.')
        ->and($input['variants'][0]['price'])->toBe('0.00')
        ->and($input['variants'][0]['inventoryQuantities'][0]['quantity'])->toBe(15)
        ->and($input['seo']['title'])->toBe('Amethyst Allure Bracelet | Leigh Avenue')
        ->and($input['files'][0]['originalSource'])->toBe('https://cdn.example.com/amethyst.jpg')
        ->and($input['files'][0]['alt'])->toBe('Amethyst Allure bracelet on a linen cloth')
        ->and($channelInput)->toBe([
            ['publicationId' => 'gid://shopify/Publication/1'],
            ['publicationId' => 'gid://shopify/Publication/2'],
        ])
        ->and($draft->fresh()->handle)->toBe('amethyst-allure')
        ->and($draft->fresh()->published)->toBe('true');
});

it('still blocks a normal draft that has a zero price and no approvals', function (): void {
    $draft = NewProductDraft::create([
        'sku' => 'NORMAL-0',
        'title' => 'Normal Bracelet',
        'tags' => 'bracelet',
        'status' => 'draft',
        'variant_price' => 0,
        'variant_inventory_qty' => 15,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $result = app(NewProductDraftShopifyCreator::class)->createApprovedDrafts(collect([$draft]));

    expect($result['created'])->toBe(0)
        ->and($result['skipped_not_approved'])->toBe(1)
        ->and($draft->fresh()->handle)->toBeNull();
});

it('syncs an unlisted launch into the local product list and allows a partial update', function (): void {
    $user = User::factory()->create();
    Import::create([
        'filename' => 'current.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    $draft = NewProductDraft::create([
        'sku' => 'LABN4',
        'title' => 'Aquamarine Dreams',
        'handle' => 'aquamarine-dreams',
        'shopify_id' => 'gid://shopify/Product/504',
        'tags' => 'the-extraordinary-few',
        'status' => 'unlisted',
        'variant_price' => 0,
        'variant_inventory_qty' => 15,
        'image_url' => 'https://cdn.example.com/aquamarine.jpg',
        'unlisted_launch' => true,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);
    StyleProfile::create([
        'handle' => 'aquamarine-dreams',
        'sku' => 'LABN4',
        'draft_seo_title' => 'Aquamarine Dreams Bracelet',
        'draft_seo_description' => 'A limited-edition blue bracelet.',
        'seo_sync_status' => 'draft',
    ]);

    $summary = app(NewProductDraftProductSync::class)->syncApprovedDrafts(collect([$draft]));
    $product = Product::query()->where('handle', 'aquamarine-dreams')->first();

    expect($summary['created'])->toBe(1)
        ->and($summary['skipped_unapproved'])->toBe(0)
        ->and($product)->not->toBeNull()
        ->and($product->status)->toBe('unlisted')
        ->and($product->seo_title)->toBe('Aquamarine Dreams Bracelet')
        ->and(app(ProductPartialApprovalService::class)->isPartialApprovalEligibleProduct($product))->toBeTrue();

    $mapStatus = new ReflectionMethod(ProductShopifyUpdater::class, 'mapStatus');
    $updater = app(ProductShopifyUpdater::class);
    expect($mapStatus->invoke($updater, 'unlisted'))->toBe('UNLISTED')
        ->and($mapStatus->invoke($updater, 'active'))->toBe('ACTIVE')
        ->and($mapStatus->invoke($updater, 'draft'))->toBe('DRAFT');
});

it('updates an unlisted product already in Shopify without changing the handle or stock quantity', function (): void {
    $draft = NewProductDraft::create([
        'sku' => 'LABN5',
        'title' => 'The Crystal Edition',
        'body_html' => 'A clear bracelet.',
        'handle' => 'the-crystal-edition',
        'shopify_id' => 'gid://shopify/Product/505',
        'tags' => 'the-extraordinary-few',
        'status' => 'unlisted',
        'variant_price' => 0,
        'variant_inventory_qty' => 15,
        'image_url' => 'https://cdn.example.com/crystal.jpg',
        'unlisted_launch' => true,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $input = null;
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use (&$input) {
        $query = (string) ($request->data()['query'] ?? '');
        $variables = (array) ($request->data()['variables'] ?? []);

        if (str_contains($query, 'query ProductForUnlistedUpdate')) {
            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/505',
                'handle' => 'the-crystal-edition',
                'variants' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/505',
                    'sku' => 'LABN5',
                ]]],
            ]]]);
        }
        if (str_contains($query, 'mutation ProductSetCreate')) {
            $input = $variables['input'] ?? null;

            return Http::response(['data' => ['productSet' => [
                'product' => [
                    'id' => 'gid://shopify/Product/505',
                    'handle' => 'the-crystal-edition',
                ],
                'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'query SalesChannels')) {
            return Http::response(['data' => ['publications' => [
                'nodes' => [['id' => 'gid://shopify/Publication/1']],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }
        if (str_contains($query, 'mutation PublishUnlistedProduct')) {
            return Http::response(['data' => ['publishablePublish' => ['userErrors' => []]]]);
        }

        throw new RuntimeException($query);
    });

    $result = app(NewProductDraftShopifyCreator::class)->updateUnlistedDrafts(collect([$draft]));

    expect($result['updated'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($input['id'])->toBe('gid://shopify/Product/505')
        ->and($input['status'])->toBe('UNLISTED')
        ->and($input)->not->toHaveKey('handle')
        ->and($input['variants'][0])->not->toHaveKey('inventoryQuantities')
        ->and($input['variants'][0]['id'])->toBe('gid://shopify/ProductVariant/505')
        ->and($input['files'][0]['originalSource'])->toBe('https://cdn.example.com/crystal.jpg')
        ->and($draft->fresh()->handle)->toBe('the-crystal-edition')
        ->and($draft->fresh()->published)->toBe('true');
});
