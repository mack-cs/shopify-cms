<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Services\HeaderStore;
use App\Jobs\HydrateShopYourVibeDraft;
use App\Services\ShopYourVibeProductMirror;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new class extends VibeShopifyFake {
        public ?Closure $afterQuery = null;

        public function graphql(string $query, array $variables = []): array
        {
            $data = parent::graphql($query, $variables);

            return $this->afterQuery ? ($this->afterQuery)($query, $variables, $data) : $data;
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    $user = User::factory()->create();
    $import = Import::create(['filename' => 'source-precedence', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create(['import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle']]));
    }
    $this->product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/101', 'handle' => 'product-101', 'title' => 'Original catalogue title']));
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
});

function sourceFreshRemote($test): void
{
    foreach ($test->fake->collections as &$collection) {
        $collection['products']['nodes'][0]['title'] = 'Fresh Shopify title';
        $collection['products']['nodes'][0]['tags'] = ['fresh-remote'];
        $collection['products']['nodes'] = array_reverse($collection['products']['nodes']);
    }
}

it('keeps fresh remote identity values and ordering despite a matching historical local draft', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => 'gid://shopify/Product/101', 'title' => 'Local title', 'tags' => 'necklaces']));
    sourceFreshRemote($this);
    $draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    foreach (['snapshot', 'desired'] as $side) {
        $products = $draft->{$side}['collections'][$draft->collection_gid]['products'];
        expect(array_column($products, 'id'))->toBe(['gid://shopify/Product/103', 'gid://shopify/Product/102', 'gid://shopify/Product/101'])
            ->and($products[2]['title'])->toBe('Fresh Shopify title')
            ->and($products[2]['tags'])->toBe(['fresh-remote']);
    }
    expect($this->product->fresh()->title)->toBe('Fresh Shopify title')->and($this->fake->mutations())->toBe([]);
});

it('does not mirror unavailable historical cards over a fresh duplicate', function () {
    app(ShopYourVibeProductMirror::class)->mirrorFromDraftState(['collections' => [
        ['products' => [['id' => $this->product->shopify_id, 'title' => 'Fresh Shopify title', 'tags' => [], 'status' => 'ACTIVE', 'vendor' => 'Remote', 'type' => 'Bracelets']]],
        ['unavailable' => true, 'products' => [['id' => $this->product->shopify_id, 'title' => 'Historical title', 'tags' => [], 'status' => 'ACTIVE', 'vendor' => 'Old', 'type' => 'Bracelets']]],
    ]]);
    expect($this->product->fresh()->title)->toBe('Fresh Shopify title');
});

it('does not invent the original audit membership from local tags', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => 'gid://shopify/Product/104', 'title' => 'Unverified member', 'tags' => 'necklaces']));
    $draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    foreach (['snapshot', 'desired'] as $side) {
        expect(array_column($draft->{$side}['collections'][$draft->collection_gid]['products'], 'id'))->not->toContain('gid://shopify/Product/104');
    }
    expect($this->fake->mutations())->toBe([]);
});

it('preserves missing collection history without mirroring it during refresh', function () {
    $draft = $this->workflow->loadProducts($this->draft->id, $this->draft->revision, 'gid://shopify/Metaobject/11');
    sourceFreshRemote($this);
    unset($this->fake->collections['gid://shopify/Collection/2']);
    $draft = $this->workflow->refresh($draft->id, $draft->revision);
    expect($this->product->fresh()->title)->toBe('Fresh Shopify title')
        ->and($draft->snapshot['collections']['gid://shopify/Collection/2']['unavailable'])->toBeTrue()
        ->and($draft->snapshot['collections']['gid://shopify/Collection/2']['products'][0]['title'])->toBe('Product 101');
});

it('keeps pending local edits separate and blocks an unapproved refresh without requests', function () {
    $gid = $this->draft->collection_gid;
    $draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', ['collection_gid' => $gid, 'ids' => array_reverse(array_column($this->draft->desired['collections'][$gid]['products'], 'id'))]);
    $before = $draft->fresh()->getAttributes();
    $this->fake->calls = [];
    expect(fn () => $this->workflow->refresh($draft->id, $draft->revision))->toThrow(RuntimeException::class, 'pending changes');
    (new HydrateShopYourVibeDraft($gid))->handle($this->workflow);
    expect($draft->fresh()->getAttributes())->toBe($before)->and($this->fake->calls)->toBe([]);
});

it('does not restore missing remote products using historical cards or matching local tags', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => 'gid://shopify/Product/101', 'title' => 'Historical member', 'tags' => 'necklaces']));
    foreach ($this->fake->collections as &$collection) {
        array_shift($collection['products']['nodes']);
        $collection['productsCount']['count'] = 2;
    }
    $draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect(array_column($draft->snapshot['collections'][$draft->collection_gid]['products'], 'id'))->not->toContain('gid://shopify/Product/101')
        ->and($this->product->fresh()->title)->toBe('Original catalogue title');
});

it('keeps the entire last confirmed state after incomplete product fields', function (string $field) {
    $before = $this->draft->fresh()->getAttributes();
    $this->fake->afterQuery = function ($query, $variables, $data) use ($field) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2') {
            unset($data['collection']['products']['nodes'][0][$field]);
        }

        return $data;
    };
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class);
    expect($this->draft->fresh()->getAttributes())->toBe($before)
        ->and($this->product->fresh()->title)->toBe('Original catalogue title');
})->with(['tags', 'vendor', 'colorPattern']);

it('keeps confirmed state after a failed lookup and safely retries', function () {
    $before = $this->draft->fresh()->getAttributes();
    $this->fake->fail = 'query VibeProducts';
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class);
    expect($this->draft->fresh()->getAttributes())->toBe($before);
    $this->fake->fail = null;
    sourceFreshRemote($this);
    $draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    $snapshot = $draft->snapshot;
    $draft = $this->workflow->refresh($draft->id, $draft->revision);
    unset($snapshot['collections']['gid://shopify/Collection/1']['retrieval_started_at']);
    foreach ($snapshot['collections'] as $gid => &$collection) {
        unset($collection['retrieval_started_at']);
    }
    unset($collection);
    $actual = $draft->snapshot;
    foreach ($actual['collections'] as &$collection) {
        unset($collection['retrieval_started_at']);
    }
    unset($collection);
    expect($actual)->toBe($snapshot)->and($draft->pending)->toBeFalse()
        ->and($this->product->fresh()->title)->toBe('Fresh Shopify title')->and($this->fake->mutations())->toBe([]);
});

it('rejects an old refresh after a newer confirmed refresh without catalogue side effects', function () {
    $this->fake->afterQuery = function ($query, $variables, $data) {
        if (str_contains($query, 'query VibeProducts')) {
            $this->fake->afterQuery = null;
            sourceFreshRemote($this);
            $this->workflow->refresh($this->draft->id, $this->draft->revision);
        }

        return $data;
    };
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class, 'changed in another');
    expect($this->product->fresh()->title)->toBe('Fresh Shopify title')
        ->and($this->draft->fresh()->snapshot['collections'][$this->draft->collection_gid]['products'][2]['title'])->toBe('Fresh Shopify title');
});

it('rejects an old lazy load after another job confirms newer products', function () {
    $this->fake->afterQuery = function ($query, $variables, $data) {
        if (str_contains($query, 'query VibeProducts')) {
            $this->fake->afterQuery = null;
            sourceFreshRemote($this);
            $this->workflow->refresh($this->draft->id, $this->draft->revision);
        }

        return $data;
    };
    expect(fn () => $this->workflow->loadProducts($this->draft->id, $this->draft->revision, 'gid://shopify/Metaobject/11'))->toThrow(RuntimeException::class, 'changed in another');
    expect($this->draft->fresh()->snapshot['collections']['gid://shopify/Collection/2']['products'][2]['title'])->toBe('Fresh Shopify title');
});

it('protects a pending edit made while a lazy load is in flight', function () {
    $this->fake->afterQuery = function ($query, $variables, $data) {
        if (str_contains($query, 'query VibeProducts')) {
            $this->fake->afterQuery = null;
            $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => array_reverse($this->fake->references)]);
        }

        return $data;
    };
    expect(fn () => $this->workflow->loadProducts($this->draft->id, $this->draft->revision, 'gid://shopify/Metaobject/11'))->toThrow(RuntimeException::class, 'changed in another');
    expect($this->draft->fresh()->pending)->toBeTrue()
        ->and(array_column($this->draft->fresh()->desired['cards'], 'id'))->toBe(array_reverse($this->fake->references));
});

it('preserves local product draft metafield edits while updating the confirmed catalogue', function () {
    ShopifyRow::create(['import_id' => $this->product->import_id, 'row_index' => 1, 'handle' => $this->product->handle, 'row_type' => 'product_primary', 'data' => [HeaderStore::MATERIALS_AND_DIMENSIONS => 'Old confirmed']]);
    $local = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => $this->product->shopify_id, 'title' => 'Local title', 'tags' => 'necklaces', 'materials_and_dimensions' => 'Pending local material']));
    foreach ($this->fake->collections as &$collection) {
        $collection['products']['nodes'][0]['materialsAndDimensions'] = ['type' => 'multi_line_text_field', 'value' => 'Fresh confirmed material'];
    }
    $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect($local->fresh()->materials_and_dimensions)->toBe('Pending local material')
        ->and(ShopifyRow::firstOrFail()->get(HeaderStore::MATERIALS_AND_DIMENSIONS))->toBe('Fresh confirmed material');
});

it('keeps a published prelaunch position pending separately from confirmed Shopify order', function () {
    $local = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['title' => 'Prepared title', 'tags' => 'necklaces']));
    $draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    $gid = $draft->collection_gid;
    $ids = array_column($draft->desired['collections'][$gid]['products'], 'id');
    $ids = array_values(array_diff($ids, ['draft:'.$local->id]));
    array_splice($ids, 1, 0, ['draft:'.$local->id]);
    $draft = $this->workflow->edit($draft->id, $draft->revision, 'reorder_products', ['collection_gid' => $gid, 'ids' => $ids]);
    NewProductDraft::withoutEvents(fn () => $local->update(['shopify_id' => 'gid://shopify/Product/104']));
    $this->fake->collections[$gid]['products']['nodes'][] = $this->fake->product(104);
    $this->fake->collections[$gid]['productsCount']['count'] = 4;
    $draft = $this->workflow->refresh($draft->id, $draft->revision, true);
    expect(array_column($draft->snapshot['collections'][$gid]['products'], 'id'))->toBe(['gid://shopify/Product/101', 'gid://shopify/Product/102', 'gid://shopify/Product/103', 'gid://shopify/Product/104'])
        ->and(array_column($draft->desired['collections'][$gid]['products'], 'id'))->toBe(['gid://shopify/Product/101', 'gid://shopify/Product/104', 'gid://shopify/Product/102', 'gid://shopify/Product/103'])
        ->and($draft->desired['collections'][$gid]['products'][1]['title'])->toBe('Product 104')
        ->and($draft->pending)->toBeTrue()->and($draft->status)->not->toBe('synced')
        ->and($this->fake->mutations())->toBe([]);
});

it('does not overwrite a newer shared product observation confirmed by another parent', function () {
    $this->travelTo(now()->startOfSecond());
    $old = app(App\Services\ShopYourVibeShopify::class)->collection($this->draft->collection_gid);
    $this->travel(1)->seconds();
    sourceFreshRemote($this);
    $new = app(App\Services\ShopYourVibeShopify::class)->collection('gid://shopify/Collection/3');
    ShopYourVibeDraft::create(['collection_gid' => 'gid://shopify/Collection/99', 'snapshot' => ['collections' => ['gid://shopify/Collection/3' => $new]], 'desired' => []]);
    Product::withoutEvents(fn () => $this->product->update(['title' => 'Fresh Shopify title']));
    app(ShopYourVibeProductMirror::class)->mirrorFromDraftState(['parent' => ['gid' => $this->draft->collection_gid], 'collections' => [$old]]);
    expect($this->product->fresh()->title)->toBe('Fresh Shopify title');
});

it('mirrors explicit Shopify metafield removal without overwriting a pending local replacement', function () {
    ShopifyRow::create(['import_id' => $this->product->import_id, 'row_index' => 1, 'handle' => $this->product->handle, 'row_type' => 'product_primary', 'data' => [HeaderStore::MATERIALS_AND_DIMENSIONS => 'Old confirmed']]);
    $local = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => $this->product->shopify_id, 'materials_and_dimensions' => 'Pending replacement']));
    $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect(ShopifyRow::firstOrFail()->get(HeaderStore::MATERIALS_AND_DIMENSIONS))->toBe('')
        ->and($local->fresh()->materials_and_dimensions)->toBe('Pending replacement');
});

it('rejects a partial metafield object instead of treating it as a verified clear', function () {
    $this->fake->collections[$this->draft->collection_gid]['products']['nodes'][0]['materialsAndDimensions'] = [];
    $before = $this->draft->fresh()->getAttributes();
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class, 'incomplete product metafields');
    expect($this->draft->fresh()->getAttributes())->toBe($before);
});

it('keeps a newly saved pending edit when a discard refresh was already in flight', function () {
    $this->fake->afterQuery = function ($query, $variables, $data) {
        if (str_contains($query, 'query VibeProducts')) {
            $this->fake->afterQuery = null;
            $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => array_reverse($this->fake->references)]);
        }

        return $data;
    };
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision, true))->toThrow(RuntimeException::class, 'changed in another');
    expect($this->draft->fresh()->pending)->toBeTrue()
        ->and($this->product->fresh()->title)->toBe('Original catalogue title');
});

it('retries loaded hydration without Shopify requests or revision changes', function () {
    $this->fake->calls = [];
    $before = $this->draft->fresh()->getAttributes();
    $job = new HydrateShopYourVibeDraft($this->draft->collection_gid);
    $job->handle($this->workflow);
    $job->handle($this->workflow);
    expect($this->draft->fresh()->getAttributes())->toBe($before)->and($this->fake->calls)->toBe([]);
});

it('does not mirror into a different product draft merely because its historical handle matches', function () {
    $local = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['handle' => $this->product->handle, 'shopify_id' => 'gid://shopify/Product/999', 'materials_and_dimensions' => null]));
    foreach ($this->fake->collections as &$collection) {
        $collection['products']['nodes'][0]['materialsAndDimensions'] = ['type' => 'multi_line_text_field', 'value' => 'Confirmed for product 101'];
    }
    $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect($local->fresh()->materials_and_dimensions)->toBeNull();
});

