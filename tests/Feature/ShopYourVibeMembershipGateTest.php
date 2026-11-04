<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeSorting;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new \Tests\Support\VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->admin = User::factory()->create(['name' => 'Super Admin', 'email' => 'membership-admin@example.com', 'is_active' => true]);
    $this->sorter = User::factory()->create(['name' => 'Sorter', 'email' => 'membership-sorter@example.com', 'is_active' => true]);
    $this->reviewer = User::factory()->create(['name' => 'Reviewer', 'email' => 'membership-reviewer@example.com', 'is_active' => true]);
    foreach ([$this->admin, $this->sorter, $this->reviewer] as $user) {
        $user->assignRole(RolesEnum::Admin->value);
    }
    $import = Import::create(['filename' => 'syv-membership-gate', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->admin->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle'],
        ]));
    }
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->sorting = app(ShopYourVibeSorting::class);
    $this->editing = app(ShopYourVibeEditing::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->main = 'gid://shopify/Collection/1';
    $this->vibe = 'gid://shopify/Collection/2';
    $this->heldTokens = [];
    $desired = $this->draft->desired;
    if (! isset($desired['collections'][$this->vibe])) {
        $copy = $desired['collections'][$this->main];
        $copy['gid'] = $this->vibe;
        $copy['title'] = 'Pearl';
        $desired['collections'][$this->vibe] = $copy;
        $snapshot = $this->draft->snapshot;
        $snapshot['collections'][$this->vibe] = $copy;
        $this->draft->update(['desired' => $desired, 'snapshot' => $snapshot]);
        $this->draft->refresh();
    }
});

function gateIds(ShopYourVibeDraft $draft, string $gid): array
{
    return array_column($draft->fresh()->desired['collections'][$gid]['products'], 'id');
}

function gateSort($test, User $user, string $gid, string $product): array
{
    $test->actingAs($user);
    $editing = app(ShopYourVibeEditing::class);
    $scope = ShopYourVibeEditing::sortScope($gid);
    $revision = (int) ($test->draft->fresh()->editing_state['locks'][$scope]['revision'] ?? 0);
    $editing->token = null;
    $test->draft = $editing->acquireScope($test->draft->id, $revision, $scope)->fresh();
    $ids = gateIds($test->draft, $gid);
    $from = array_search($product, $ids, true);
    $next = $ids;
    $item = array_splice($next, (int) $from, 1)[0];
    array_splice($next, min((int) $from + 1, count($next)), 0, [$item]);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => $next,
        'explicit_ids' => [$product],
        'scope_revision' => $revision,
    ])->fresh();
    $test->heldTokens[$gid] = app(ShopYourVibeEditing::class)->token;

    return $next;
}

it('lets sorting start when Shopify membership matches and reads only that collection', function () {
    $this->actingAs($this->admin);
    $this->fake->calls = [];
    $order = gateIds($this->draft, $this->main);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->assertSee('Check Shopify before sorting?')
        ->assertSee('needs a fresh product list from Shopify')
        ->assertSee('Sorting stays off until you confirm')
        ->assertSee('Start sorting')
        ->call('askToStartSorting', $this->main)
        ->assertDispatched('open-modal', id: 'confirm-start-sorting');
    $blocked = $page->instance()->reorderProducts($this->main, array_reverse($order), 1);
    expect($blocked['applied'])->toBeFalse()->and(gateIds($this->draft, $this->main))->toBe($order);
    $this->fake->calls = [];
    $page->call('startEditing');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $queries = array_values(array_filter($this->fake->calls, fn (array $call): bool => str_contains($call[0], 'query VibeMembership')));

    expect($page->instance()->membershipPrompt)->toBeNull()
        ->and($page->instance()->loadError)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'])->toBe($this->admin->id)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0][1]['id'])->toBe($this->main)
        ->and($queries[0][0])->not->toContain('variants')
        ->and($queries[0][0])->not->toContain('metafield');

    $page->assertSee('Drag products or enter a position number to reorder')
        ->assertDontSee('Sorting stays off until you confirm');
});

it('stops a new sorting session when Shopify membership is stale', function () {
    array_pop($this->fake->collections[$this->main]['products']['nodes']);
    $order = gateIds($this->draft, $this->main);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('startEditing');
    $scope = ShopYourVibeEditing::sortScope($this->main);

    $page->assertSee('Shopify membership for Necklaces no longer matches this draft')
        ->assertSee('Last successful membership verification')
        ->assertSee('Saved product count')
        ->assertSee('Current Shopify product count')
        ->assertSee('Products removed from Shopify')
        ->assertSee('Product 103')
        ->assertSee('Refresh membership & preserve sorting')
        ->assertSee('data-membership-prompt', false);

    expect($page->instance()->membershipPrompt['gid'])->toBe($this->main)
        ->and($page->instance()->membershipPrompt['saved_count'])->toBe(3)
        ->and($page->instance()->membershipPrompt['shopify_count'])->toBe(2)
        ->and($page->instance()->membershipPrompt['removed'][0]['id'])->toBe('gid://shopify/Product/103')
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'] ?? null)->toBeNull()
        ->and(gateIds($this->draft, $this->main))->toBe($order);

    $page->call('reorderProducts', $this->main, array_reverse($order), 1, 'stale-sort', 'gid://shopify/Product/101');
    expect(gateIds($this->draft, $this->main))->toBe($order)
        ->and($page->instance()->loadError)->toContain('Refresh membership before sorting');
});

it('names products Shopify added', function () {
    $this->fake->collections[$this->main]['products']['nodes'][] = $this->fake->product(104);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('beginCollectionSorting', $this->main);

    $page->assertSee('Products added in Shopify')
        ->assertSee('Product 104')
        ->assertSee('gid://shopify/Product/104');
    expect($page->instance()->membershipPrompt['added'][0]['id'])->toBe('gid://shopify/Product/104')
        ->and($page->instance()->membershipPrompt['shopify_count'])->toBe(4)
        ->and($page->instance()->membershipPrompt['removed'])->toBe([]);
});

it('names products Shopify removed', function () {
    array_shift($this->fake->collections[$this->main]['products']['nodes']);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('beginCollectionSorting', $this->main);

    expect($page->instance()->membershipPrompt['removed'][0])->toMatchArray([
        'id' => 'gid://shopify/Product/101',
        'title' => 'Product 101',
    ])->and($page->instance()->membershipPrompt['saved_count'])->toBe(3)
        ->and($page->instance()->membershipPrompt['shopify_count'])->toBe(2);
});

it('treats a different order with the same products as a match', function () {
    $nodes = $this->fake->collections[$this->main]['products']['nodes'];
    $this->fake->collections[$this->main]['products']['nodes'] = array_reverse($nodes);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('startEditing');
    $scope = ShopYourVibeEditing::sortScope($this->main);

    expect($page->instance()->membershipPrompt)->toBeNull()
        ->and($page->instance()->loadError)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'])->toBe($this->admin->id);
});

it('does not start sorting when Shopify pagination is incomplete', function () {
    $gid = $this->main;
    $this->fake->membershipPages = [
        '' => [
            'id' => $gid,
            'title' => 'Necklaces',
            'productsCount' => ['count' => 3],
            'products' => [
                'nodes' => [['id' => 'gid://shopify/Product/101', 'title' => 'Product 101']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'],
            ],
        ],
        'page-2' => [
            'id' => $gid,
            'title' => 'Necklaces',
            'productsCount' => ['count' => 3],
            'products' => [
                'nodes' => [['id' => 'gid://shopify/Product/102', 'title' => 'Product 102']],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'],
            ],
        ],
    ];
    $order = gateIds($this->draft, $gid);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $gid)->call('startEditing');

    $page->assertSee('incomplete pagination');
    expect($page->instance()->membershipPrompt)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($gid)]['owner_id'] ?? null)->toBeNull()
        ->and(gateIds($this->draft, $gid))->toBe($order);
});

it('shows a Shopify failure without leaving an editing lock', function () {
    $this->fake->fail = 'VibeMembership';
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('beginCollectionSorting', $this->main);

    $page->assertSee('Simulated Shopify failure: VibeMembership');
    expect($page->instance()->membershipPrompt)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'] ?? null)->toBeNull();
});

it('refreshes membership and keeps an explicit sort without marking the new product as moved', function () {
    $order = gateSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $this->fake->collections[$this->main]['products']['nodes'][] = $this->fake->product(104);
    $vibeBefore = gateIds($this->draft, $this->vibe);
    $this->actingAs($this->sorter);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('refreshMembershipPreservingSort', $this->main);

    $saved = gateIds($this->draft, $this->main);
    expect($saved)->toBe(array_merge($order, ['gid://shopify/Product/104']))
        ->and(gateIds($this->draft, $this->vibe))->toBe($vibeBefore)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->where('product_key', 'gid://shopify/Product/101')->where('explicit', true)->exists())->toBeTrue()
        ->and(SyvCollectionSortState::query()->where('product_key', 'gid://shopify/Product/104')->exists())->toBeFalse()
        ->and($page->instance()->loadError)->toBeNull();
});

it('inserts new Shopify products in the current Shopify order around the saved sort', function () {
    $gid = $this->main;
    $desired = $this->draft->desired;
    $byId = collect($desired['collections'][$gid]['products'])->keyBy('id');
    $desired['collections'][$gid]['products'] = [
        $byId['gid://shopify/Product/101'],
        $byId['gid://shopify/Product/103'],
        $byId['gid://shopify/Product/102'],
    ];
    $this->draft->update(['desired' => $desired]);
    $this->actingAs($this->admin);
    $live = [
        'title' => 'Necklaces',
        'retrieval_started_at' => now()->toISOString(),
        'products' => [
            ['id' => 'gid://shopify/Product/104', 'title' => 'Product 104'],
            ['id' => 'gid://shopify/Product/101', 'title' => 'Product 101'],
            ['id' => 'gid://shopify/Product/105', 'title' => 'Product 105'],
            ['id' => 'gid://shopify/Product/103', 'title' => 'Product 103'],
            ['id' => 'gid://shopify/Product/102', 'title' => 'Product 102'],
        ],
    ];

    expect($this->sorting->mergePreservingSort(
        ['gid://shopify/Product/101', 'gid://shopify/Product/103', 'gid://shopify/Product/102'],
        array_column($live['products'], 'id'),
    ))->toBe([
        'gid://shopify/Product/104',
        'gid://shopify/Product/101',
        'gid://shopify/Product/105',
        'gid://shopify/Product/103',
        'gid://shopify/Product/102',
    ]);

    $this->draft = $this->sorting->reconcileMembership($this->draft->id, $gid, $live, 0);
    expect(gateIds($this->draft, $gid))->toBe([
        'gid://shopify/Product/104',
        'gid://shopify/Product/101',
        'gid://shopify/Product/105',
        'gid://shopify/Product/103',
        'gid://shopify/Product/102',
    ])->and(SyvCollectionSortState::query()->where('explicit', true)->count())->toBe(0);
});

it('drops a product Shopify removed when a stale browser sends it back', function () {
    array_pop($this->fake->collections[$this->main]['products']['nodes']);
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)
        ->call('refreshMembershipPreservingSort', $this->main);
    $remaining = gateIds($this->draft, $this->main);
    $page->call('beginCollectionSorting', $this->main);
    $page->call('reorderProducts', $this->main, array_merge(['gid://shopify/Product/103'], $remaining), 1, 'stale-browser', 'gid://shopify/Product/103');

    expect(gateIds($this->draft, $this->main))->toBe($remaining)
        ->and($remaining)->not->toContain('gid://shopify/Product/103');
});

it('invalidates only the reconciled collection and leaves the other lock alone', function () {
    $vibeScope = ShopYourVibeEditing::sortScope($this->vibe);
    $state = $this->draft->editing_state ?? [];
    $vibeLock = [
        'revision' => 4,
        'owner_id' => $this->sorter->id,
        'owner_name' => 'Sorter',
        'token_hash' => hash('sha256', 'vibe-token'),
        'expires_at' => now()->addMinutes(10)->toISOString(),
    ];
    $state['locks'][$vibeScope] = $vibeLock;
    $this->draft->update(['editing_state' => $state]);
    foreach ([$this->main, $this->vibe] as $gid) {
        SyvCollectionSortState::query()->create([
            'draft_id' => $this->draft->id,
            'collection_gid' => $gid,
            'product_key' => 'gid://shopify/Product/101',
            'status' => SyvCollectionSortState::AWAITING,
            'explicit' => true,
            'highlight' => true,
            'from_position' => 1,
            'to_position' => 2,
            'approved_at' => now(),
            'approved_by' => $this->reviewer->id,
            'approved_revision' => 1,
            'reviewer_id' => $this->reviewer->id,
        ]);
    }
    $this->fake->collections[$this->main]['products']['nodes'][] = $this->fake->product(104);
    $vibeIds = gateIds($this->draft, $this->vibe);
    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('refreshMembershipPreservingSort', $this->main);

    $fresh = $this->draft->fresh();
    expect(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at)->toBeNull()
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->approved_at)->not->toBeNull()
        ->and($fresh->editing_state['locks'][$vibeScope]['revision'])->toBe(4)
        ->and($fresh->editing_state['locks'][$vibeScope]['owner_id'])->toBe($this->sorter->id)
        ->and($fresh->editing_state['locks'][$vibeScope]['token_hash'])->toBe(hash('sha256', 'vibe-token'))
        ->and(gateIds($fresh, $this->vibe))->toBe($vibeIds);
});

it('rejects a stale reconciliation after another session already refreshed', function () {
    $gid = $this->main;
    $first = [
        'title' => 'Necklaces',
        'retrieval_started_at' => now()->toISOString(),
        'products' => [
            ['id' => 'gid://shopify/Product/101', 'title' => 'Product 101'],
            ['id' => 'gid://shopify/Product/102', 'title' => 'Product 102'],
            ['id' => 'gid://shopify/Product/103', 'title' => 'Product 103'],
        ],
    ];
    $second = $first;
    $second['products'] = array_reverse($second['products']);
    $this->actingAs($this->admin);
    $this->draft = $this->sorting->reconcileMembership($this->draft->id, $gid, $first, 0);

    expect(fn () => $this->sorting->reconcileMembership($this->draft->id, $gid, $second, 0))
        ->toThrow(RuntimeException::class, 'another tab or session');
    expect(gateIds($this->draft, $gid))->toBe([
        'gid://shopify/Product/101',
        'gid://shopify/Product/102',
        'gid://shopify/Product/103',
    ]);
});

it('still refuses publication and names the membership difference without reordering Shopify', function () {
    $order = gateSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();
    $this->actingAs($this->reviewer);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $revision)->fresh();
    array_pop($this->fake->collections[$this->main]['products']['nodes']);
    $reorders = count(array_filter($this->fake->calls, fn (array $call): bool => str_contains($call[0], 'VibeReorderProducts')));

    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('approveAndPublishSorting', $this->main)
        ->assertSee('Shopify membership for Necklaces changed after approval')
        ->assertSee('Removed from Shopify')
        ->assertSee('Product 103')
        ->assertSee('Refresh membership & preserve sorting');

    expect(gateIds($this->draft, $this->main))->toBe($order)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at)->toBeNull()
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->shopify_confirmed_at)->toBeNull()
        ->and(count(array_filter($this->fake->calls, fn (array $call): bool => str_contains($call[0], 'VibeReorderProducts'))))->toBe($reorders)
        ->and($page->instance()->membershipPrompt['removed'][0]['id'])->toBe('gid://shopify/Product/103');
});

it('does not run a second membership check while one is already in progress', function () {
    $this->actingAs($this->admin);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $held = Cache::lock(ShopYourVibeSorting::membershipLockKey($this->draft->id, $this->main), 120);
    expect($held->get())->toBeTrue();

    $page->call('beginCollectionSorting', $this->main)->assertSee('already being checked');
    expect($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'] ?? null)->toBeNull();
    $held->release();
});
