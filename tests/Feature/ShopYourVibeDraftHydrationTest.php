<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\HydrateShopYourVibeDraft;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    config()->set('queue.connections.shop-your-vibe.after_commit', false);
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->user = User::factory()->create();
    $this->import = Import::create([
        'filename' => 'vibe-hydration',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $this->user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $this->import->id,
            'shopify_id' => $collection['id'],
            'title' => $collection['title'],
            'handle' => $collection['handle'],
        ]));
    }
    foreach ([101, 102, 103] as $id) {
        Product::withoutEvents(fn () => Product::create([
            'import_id' => $this->import->id,
            'shopify_id' => 'gid://shopify/Product/'.$id,
            'handle' => 'product-'.$id,
            'title' => 'Product '.$id,
        ]));
    }
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
});

function hydrationShell(string $gid = 'gid://shopify/Collection/1', string $title = 'All Products Collections', array $overrides = []): ShopYourVibeDraft
{
    $state = [
        'cards' => [],
        'digest' => null,
        'parent' => ['gid' => $gid, 'image' => null, 'title' => $title, 'handle' => 'all-products-collection'],
        'collections' => [],
        'reference_ids' => [],
    ];

    return ShopYourVibeDraft::updateOrCreate(['collection_gid' => $gid], array_merge([
        'snapshot' => $state,
        'desired' => $state,
        'revision' => 0,
        'pending' => false,
        'status' => 'synced',
        'refreshed_at' => '2026-09-09 11:15:43',
    ], $overrides));
}

function confirmedDraft(string $gid, array $products): ShopYourVibeDraft
{
    $state = [
        'parent' => ['gid' => $gid, 'title' => 'Confirmed', 'handle' => 'confirmed', 'product_count' => count($products)],
        'cards' => [],
        'reference_ids' => [],
        'collections' => [
            $gid => [
                'gid' => $gid,
                'title' => 'Confirmed',
                'handle' => 'confirmed',
                'sort' => 'MANUAL',
                'manual_supported' => true,
                'membership_supported' => true,
                'enable_manual' => false,
                'product_count' => count($products),
                'products' => $products,
            ],
        ],
    ];

    return ShopYourVibeDraft::updateOrCreate(['collection_gid' => $gid], [
        'snapshot' => $state,
        'desired' => $state,
        'revision' => 3,
        'pending' => false,
        'status' => 'synced',
        'refreshed_at' => now(),
    ]);
}

it('queues hydration for a legacy draft that never loaded its collection', function () {
    $shell = hydrationShell();
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', 'gid://shopify/Collection/1')
        ->assertSet('hydratingCollection', true)
        ->assertSee('All Products Collections')
        ->assertSee('Products (Loading…)')
        ->assertSee('Shop Your Vibes (Loading…)')
        ->assertSee('Loading collection products')
        ->assertSee(ShopYourVibeWorkflow::LOADING_PRODUCTS_MESSAGE)
        ->assertSee('Products have not been loaded from Shopify yet.')
        ->assertDontSee('No products belong to this Shopify collection.')
        ->assertDontSee('Up to date with Shopify');

    expect($this->fake->calls)->toBe([])
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(1)
        ->and(substr_count($page->html(), '<p data-status-message'))->toBe(1)
        ->and(substr_count($page->html(), 'You can continue using the CMS while this runs.'))->toBe(1)
        ->and(substr_count($page->html(), 'syv-indeterminate'))->toBe(1)
        ->and($page->html())->not->toContain('x-text="statusProgress"')
        ->and($page->html())->toContain('data-products-loaded="0"')
        ->and($page->html())->toContain('data-poll="1"')
        ->and(ShopYourVibeWorkflow::productsAreLoaded($shell->fresh()->desired, 'gid://shopify/Collection/1'))->toBeFalse();

    $waiting = $page->instance()->statusSnapshot();
    expect($waiting['polling'])->toBeTrue()->and($waiting['changed'])->toBeFalse();
    $page->call('pollPush')->assertDontSee('Product 101');
});

it('replaces the incomplete editor with products when hydration finishes', function () {
    hydrationShell();
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1');

    (new HydrateShopYourVibeDraft('gid://shopify/Collection/1', $this->user->id))
        ->handle(app(ShopYourVibeWorkflow::class));

    $ready = $page->instance()->statusSnapshot();
    expect($ready['changed'])->toBeTrue()->and($ready['polling'])->toBeFalse();
    $page->call('pollPush')
        ->assertSet('hydratingCollection', false)
        ->assertSee('Products (3)')
        ->assertSee('Product 101')
        ->assertSee('Manual sorting enabled')
        ->assertDontSee('Loading collection products')
        ->assertDontSee('syv-indeterminate')
        ->assertDontSee('Collection synchronization completed successfully.');

    $draft = ShopYourVibeDraft::query()->where('collection_gid', 'gid://shopify/Collection/1')->first();
    expect(ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid))->toBeTrue()
        ->and($page->html())->toContain('data-products-loaded="1"')
        ->and($page->html())->toContain('data-poll="0"');
});

it('opens a confirmed empty collection without calling Shopify', function () {
    $gid = 'gid://shopify/Collection/4';
    confirmedDraft($gid, []);
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', $gid)
        ->assertSet('hydratingCollection', false)
        ->assertSee('Products (0)')
        ->assertSee('No products belong to this Shopify collection.')
        ->assertDontSee('syv-indeterminate')
        ->assertDontSee('Loading collection products');

    expect($this->fake->calls)->toBe([])
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(0);
});

it('opens a complete collection from local products without calling Shopify', function () {
    $gid = 'gid://shopify/Collection/1';
    confirmedDraft($gid, [[
        'id' => 'gid://shopify/Product/101',
        'title' => 'Product 101',
        'sku' => 'SKU-101',
        'tags' => [],
        'image' => null,
    ]]);
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', $gid)
        ->assertSet('hydratingCollection', false)
        ->assertSee('Products (1)')
        ->assertSee('Product 101')
        ->assertDontSee('syv-indeterminate')
        ->assertDontSee('Loading collection products');

    expect($this->fake->calls)->toBe([])
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(0);
});

it('shows the loading shell for a missing draft and does not call Shopify', function () {
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', 'gid://shopify/Collection/1')
        ->assertSet('hydratingCollection', true)
        ->assertSet('draftId', null)
        ->assertSee('Loading collection products')
        ->assertDontSee('No products belong to this Shopify collection.')
        ->assertDontSee('Up to date with Shopify');

    expect($this->fake->calls)->toBe([])
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(1);
});

it('does not dispatch a second hydration while one is already running', function () {
    hydrationShell();

    Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1');
    Livewire::test(ShopYourVibe::class)
        ->call('manage', 'gid://shopify/Collection/1')
        ->assertSet('hydratingCollection', true)
        ->assertSee('Loading collection products');

    expect(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(1)
        ->and(Cache::get(ShopYourVibeWorkflow::progressKey('gid://shopify/Collection/1'))['state'])->toBe('running');
});

it('shows a failed hydration with a retry that can queue again after the uniqueness lock is released', function () {
    hydrationShell();
    Cache::put(ShopYourVibeWorkflow::progressKey('gid://shopify/Collection/1'), [
        'state' => 'failed',
        'done' => null,
        'total' => null,
        'message' => ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE,
    ], 600);
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', 'gid://shopify/Collection/1')
        ->assertSee("Could not load collection products")
        ->assertSee(ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE)
        ->assertSee('Retry')
        ->assertSee('Products (Not loaded)')
        ->assertDontSee('syv-indeterminate')
        ->assertDontSee('No products belong to this Shopify collection.')
        ->assertDontSee('Up to date with Shopify');

    expect(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(0)
        ->and($this->fake->calls)->toBe([]);

    HydrateShopYourVibeDraft::dispatch('gid://shopify/Collection/1', $this->user->id);
    HydrateShopYourVibeDraft::dispatch('gid://shopify/Collection/1', $this->user->id);
    expect(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(1);

    HydrateShopYourVibeDraft::releaseUniqueLock('gid://shopify/Collection/1');
    $page->call('retryHydration')
        ->assertSee('Loading collection products')
        ->assertDontSee("Could not load collection products");

    expect(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(2);
});

it('does not overwrite pending edits on an incomplete draft', function () {
    $state = hydrationShell()->desired;
    $state['cards'] = [[
        'key' => 'local-card',
        'name' => 'Kept local card',
        'collection_gid' => null,
        'link' => '',
    ]];
    hydrationShell(overrides: [
        'desired' => $state,
        'snapshot' => $state,
        'pending' => true,
        'revision' => 4,
    ]);
    $page = Livewire::test(ShopYourVibe::class);
    $this->fake->calls = [];
    $page->call('manage', 'gid://shopify/Collection/1')
        ->assertSet('hydratingCollection', false)
        ->assertSee('Pending changes - not pushed to Shopify')
        ->assertSee('Pending edits were kept')
        ->assertSee('Products (Not loaded)')
        ->assertDontSee('Up to date with Shopify')
        ->assertDontSee('No products belong to this Shopify collection.');

    (new HydrateShopYourVibeDraft('gid://shopify/Collection/1', $this->user->id))
        ->handle(app(ShopYourVibeWorkflow::class));

    $draft = ShopYourVibeDraft::query()->where('collection_gid', 'gid://shopify/Collection/1')->first();
    expect($draft->pending)->toBeTrue()
        ->and($draft->revision)->toBe(4)
        ->and($draft->desired['cards'][0]['name'])->toBe('Kept local card')
        ->and(ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid))->toBeFalse()
        ->and($this->fake->calls)->toBe([])
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(0);
});

it('does not let a hydration refresh overwrite a newer revision', function () {
    $shell = hydrationShell(overrides: ['revision' => 1]);
    $shell->update(['revision' => 6]);
    $this->fake->calls = [];

    expect(fn () => $this->workflow->refresh($shell->id, 1, false, true))
        ->toThrow(RuntimeException::class, 'another tab');
    expect($shell->fresh()->revision)->toBe(6)
        ->and(ShopYourVibeWorkflow::productsAreLoaded($shell->fresh()->desired, $shell->collection_gid))->toBeFalse()
        ->and($this->fake->calls)->toBe([]);
});

it('keeps a newer revision when Shopify responds after the draft has changed', function () {
    $shell = hydrationShell(overrides: ['revision' => 1]);
    $inner = $this->fake;
    app()->instance(ShopifyGraphqlGateway::class, new class($inner, $shell->id) implements ShopifyGraphqlGateway
    {
        public function __construct(private VibeShopifyFake $inner, private int $draftId) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'query VibeProducts')) {
                ShopYourVibeDraft::query()->whereKey($this->draftId)->update(['revision' => 8]);
            }

            return $this->inner->graphql($query, $variables);
        }
    });
    app()->forgetInstance(ShopYourVibeShopify::class);
    app()->forgetInstance(ShopYourVibeWorkflow::class);

    expect(fn () => (new HydrateShopYourVibeDraft('gid://shopify/Collection/1', $this->user->id))
        ->handle(app(ShopYourVibeWorkflow::class)))->toThrow(RuntimeException::class);

    $draft = $shell->fresh();
    expect($draft->revision)->toBe(8)
        ->and(ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid))->toBeFalse()
        ->and(Cache::get(ShopYourVibeWorkflow::progressKey($draft->collection_gid))['state'])->toBe('failed')
        ->and(Cache::get(ShopYourVibeWorkflow::progressKey($draft->collection_gid))['message'])->toBe(ShopYourVibeWorkflow::HYDRATION_FAILED_MESSAGE);
});

it('records a failed hydration instead of treating the collection as empty', function () {
    hydrationShell();
    $this->fake->fail = 'VibeProducts';

    expect(fn () => (new HydrateShopYourVibeDraft('gid://shopify/Collection/1', $this->user->id))
        ->handle($this->workflow))->toThrow(RuntimeException::class);

    $draft = ShopYourVibeDraft::query()->where('collection_gid', 'gid://shopify/Collection/1')->first();
    expect(ShopYourVibeWorkflow::productsAreLoaded($draft->desired, $draft->collection_gid))->toBeFalse()
        ->and(Cache::get(ShopYourVibeWorkflow::progressKey($draft->collection_gid))['state'])->toBe('failed');

    Livewire::test(ShopYourVibe::class)
        ->call('manage', 'gid://shopify/Collection/1')
        ->assertSee("Could not load collection products")
        ->assertDontSee('No products belong to this Shopify collection.')
        ->assertDontSee('syv-indeterminate')
        ->assertDontSee('Up to date with Shopify');
});

it('names the collection being loaded so a linked total is not the final total', function () {
    $parent = 'gid://shopify/Collection/1';
    $linked = 'gid://shopify/Collection/9';

    expect(ShopYourVibeWorkflow::productProgressMessage($parent, $parent, 400, 505, 'All Products Collections'))
        ->toBe('Loading All Products Collections: 400 of 505 products — 79%')
        ->and(ShopYourVibeWorkflow::productProgressMessage($parent, $linked, 63, 63, 'Charms'))
        ->toBe('Loading linked collection Charms: 63 of 63 products — 100%')
        ->and(ShopYourVibeWorkflow::productProgressMessage($parent, $linked, 10, 0))
        ->toBe('Loading a linked collection');
});

it('does not keep a loader when products are already loaded and a progress cache remains', function () {
    $gid = 'gid://shopify/Collection/1';
    confirmedDraft($gid, [[
        'id' => 'gid://shopify/Product/101',
        'title' => 'Product 101',
        'sku' => 'SKU-101',
        'tags' => [],
        'image' => null,
    ]]);
    Cache::put(ShopYourVibeWorkflow::progressKey($gid), [
        'state' => 'success',
        'done' => null,
        'total' => null,
        'message' => ShopYourVibeWorkflow::HYDRATION_SUCCESS_MESSAGE,
    ], 120);

    $page = Livewire::test(ShopYourVibe::class)->call('manage', $gid);
    $status = $page->instance()->statusSnapshot();

    expect($status['polling'])->toBeFalse()
        ->and($status['state'])->toBe('success')
        ->and($page->html())->not->toContain('syv-indeterminate');
    $page->assertSee('Products (1)')
        ->assertDontSee('Loading collection products')
        ->assertDontSee('Collection synchronization completed successfully.');

    Cache::put(ShopYourVibeWorkflow::progressKey($gid), [
        'state' => 'running',
        'done' => 400,
        'total' => 505,
        'message' => 'Loading All Products Collections: 400 of 505 products — 79%',
    ], 600);

    $again = Livewire::test(ShopYourVibe::class)->call('manage', $gid);
    expect($again->instance()->statusSnapshot()['polling'])->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(0);
    $again->assertDontSee('Loading collection products')
        ->assertDontSee('syv-indeterminate');
});
