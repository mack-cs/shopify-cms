<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\PushShopYourVibeProductSiblings;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeSorting;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->admin = User::factory()->create(['name' => 'Super Admin', 'email' => 'super-admin@example.com', 'is_active' => true]);
    $this->sorter = User::factory()->create(['name' => 'Sorter', 'email' => 'sorter@example.com', 'is_active' => true]);
    $this->reviewer = User::factory()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.com', 'is_active' => true]);
    foreach ([$this->admin, $this->sorter, $this->reviewer] as $user) {
        $user->assignRole(RolesEnum::Admin->value);
    }
    $import = Import::create(['filename' => 'syv-manual-fix', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->admin->id, 'is_current' => true, 'is_valid' => true]);
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

function manualOrder($draft, string $gid): array
{
    return array_column($draft->fresh()->desired['collections'][$gid]['products'], 'id');
}

function manualShift(array $ids, string $product): array
{
    $from = array_search($product, $ids, true);
    $next = $ids;
    $item = array_splice($next, $from, 1)[0];
    array_splice($next, min($from + 1, count($next)), 0, [$item]);

    return $next;
}

function manualSort($test, User $user, string $gid, string $product): array
{
    $test->actingAs($user);
    $editing = app(ShopYourVibeEditing::class);
    $scope = ShopYourVibeEditing::sortScope($gid);
    $revision = (int) ($test->draft->fresh()->editing_state['locks'][$scope]['revision'] ?? 0);
    $editing->token = null;
    $test->draft = $editing->acquireScope($test->draft->id, $revision, $scope)->fresh();
    $order = manualShift(manualOrder($test->draft, $gid), $product);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => $order,
        'explicit_ids' => [$product],
        'scope_revision' => $revision,
    ])->fresh();
    $test->heldTokens[$gid] = app(ShopYourVibeEditing::class)->token;

    return $order;
}

it('returns the stale sorting heartbeat as a visible lock error instead of HTTP 500', function () {
    $this->actingAs($this->admin);
    $gid = $this->main;
    $scope = ShopYourVibeEditing::sortScope($gid);
    $order = manualOrder($this->draft, $gid);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $gid)->call('startEditing');
    $state = $this->draft->fresh()->editing_state;
    $state['locks'][$scope]['token_hash'] = hash('sha256', 'obsolete-browser-token');
    $this->draft->update(['editing_state' => $state]);

    $page->call('editingHeartbeat')
        ->assertSee('Super Admin owns this editing lock')
        ->assertSee('Sorting is blocked')
        ->assertSee('Product assignments, tags, membership and other product actions stay available.')
        ->assertSee('Collection: Necklaces');

    expect(manualOrder($this->draft, $gid))->toBe($order)
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'])->toBe($this->admin->id)
        ->and($page->instance()->lockFeedback['owner'])->toBe('Super Admin');

    $page->call('editingStatus')->assertSee('Super Admin owns this editing lock');
    $this->travel(15)->minutes();
    $page->call('editingStatus')->assertDontSee('Super Admin owns this editing lock');
});

it('shows feedback for a rejected sort and still accepts the next attempt', function () {
    manualSort($this, $this->admin, $this->main, 'gid://shopify/Product/101');
    $order = manualOrder($this->draft, $this->main);
    $this->actingAs($this->sorter);
    $ids = manualShift($order, 'gid://shopify/Product/102');
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('beginCollectionSorting', $this->main);
    expect($page->instance()->loadError)->toContain('currently editing')
        ->and($page->html())->toContain('Sorting is blocked');
    $page->call('reorderProducts', $this->main, $ids, 2, 'sorter-session', 'gid://shopify/Product/102');

    expect(manualOrder($this->draft, $this->main))->toBe($order);
});

it('opens an approval request without acquiring the sorting lock', function () {
    $order = manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();

    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->assertSee('Sorting approval request — Necklaces')
        ->assertSee('Approve & Publish Sorting')
        ->assertSee('Review / Edit');

    expect($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'] ?? null)->toBeNull()
        ->and($page->instance()->scopeTokens[$this->main] ?? null)->toBeNull()
        ->and(manualOrder($this->draft, $this->main))->toBe($order);
});

it('lets the reviewer approve an unchanged revision without holding an editing lock', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();

    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('approveSorting', $this->main);

    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first();
    expect($row->approved_at)->not->toBeNull()
        ->and($row->shopify_confirmed_at)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['owner_id'] ?? null)->toBeNull()
        ->and($page->instance()->loadError)->toBeNull();
});

it('lets the reviewer correct a collection and publish it without a second submission', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();

    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('reviewSorting', $this->main);
    $corrected = manualShift(manualOrder($this->draft, $this->main), 'gid://shopify/Product/101');
    $page->call('reorderProducts', $this->main, $corrected, 1, 'reviewer-session', 'gid://shopify/Product/101');
    expect($page->instance()->loadError)->toBeNull();
    $submitted = \App\Models\SyvCollectionSortEvent::query()->where('type', 'submitted')->count();
    $page->call('approveAndPublishSorting', $this->main);

    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->where('product_key', 'gid://shopify/Product/101')->first();
    expect($page->instance()->loadError)->toBeNull()
        ->and($row->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and($row->shopify_confirmed_at)->not->toBeNull()
        ->and(\App\Models\SyvCollectionSortEvent::query()->where('type', 'submitted')->count())->toBe($submitted);
    $page->assertSee('Done. Sorting published to Shopify')
        ->assertSee('Read more')
        ->assertSee('x-show="publishDetails"', false)
        ->assertSee('x-cloak', false)
        ->assertSee('data-sort-publish-details', false)
        ->assertSee('Product 101')
        ->assertSee('Shopify now has this order');

    $publishedLock = $this->draft->fresh()->editing_state['locks'][$scope];
    expect($publishedLock['pending_owner_id'] ?? null)->toBeNull()
        ->and($publishedLock['owner_id'] ?? null)->toBeNull();

    $stale = $this->draft->fresh();
    $staleState = $stale->editing_state;
    $staleState['locks'][$scope]['pending_owner_id'] = $this->reviewer->id;
    $staleState['locks'][$scope]['pending_owner_name'] = $this->reviewer->name;
    $staleState['locks'][$scope]['owner_id'] = $this->reviewer->id;
    $staleState['locks'][$scope]['owner_name'] = $this->reviewer->name;
    $staleState['locks'][$scope]['expires_at'] = now()->subDay()->toISOString();
    $stale->editing_state = $staleState;
    $stale->save();

    $this->actingAs($this->admin);
    app(ShopYourVibeEditing::class)->token = null;
    $view = app(ShopYourVibeEditing::class)->viewScope($stale->fresh(), $scope);
    $acquired = app(ShopYourVibeEditing::class)->acquireScope($stale->id, (int) $staleState['locks'][$scope]['revision'], $scope);
    expect($view['blocked'])->toBeFalse()
        ->and($view['owner'])->toBeNull()
        ->and($view['pending'])->toBeFalse()
        ->and($acquired->editing_state['locks'][$scope]['pending_owner_id'] ?? null)->toBeNull()
        ->and($acquired->editing_state['locks'][$scope]['owner_id'])->toBe($this->admin->id);
});

it('shows the sorting panel error when Approve and Publish is refused', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $order = manualOrder($this->draft, $this->main);
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();
    array_pop($this->fake->collections[$this->main]['products']['nodes']);
    $before = count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts')));

    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->call('approveAndPublishSorting', $this->main)
        ->assertSee('Shopify membership for Necklaces changed after approval')
        ->assertSee('data-sort-publish-error', false)
        ->assertSee('Approve & Publish Sorting')
        ->assertSee('Sorting approval request — Necklaces');

    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first();
    expect($page->instance()->loadError)->toContain('Shopify membership for Necklaces')
        ->and($page->instance()->sortPublishError['gid'])->toBe($this->main)
        ->and($this->draft->fresh()->status)->toBe('pending')
        ->and($this->draft->fresh()->last_error)->toContain('Shopify membership for Necklaces')
        ->and(manualOrder($this->draft, $this->main))->toBe($order)
        ->and($row->approved_at)->toBeNull()
        ->and($row->shopify_confirmed_at)->toBeNull()
        ->and(count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts'))))->toBe($before);
});

it('shows independent approval actions for the main collection and an inner vibe', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    manualSort($this, $this->admin, $this->vibe, 'gid://shopify/Product/102');
    foreach ([$this->main => $this->sorter, $this->vibe => $this->admin] as $gid => $user) {
        $scope = ShopYourVibeEditing::sortScope($gid);
        $revision = (int) $this->draft->fresh()->editing_state['locks'][$scope]['revision'];
        $this->actingAs($user);
        app(ShopYourVibeEditing::class)->token = $this->heldTokens[$gid];
        $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $gid, $revision)->fresh();
    }

    $this->actingAs($this->reviewer);
    $card = collect($this->draft->desired['cards'])->firstWhere('collection_gid', $this->vibe);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->assertSee('data-sorting-collection="'.$this->main.'"', false)
        ->assertSee('Sorting approval request — Necklaces');
    $page->call('editCard', $card['key'])
        ->assertSee('data-sorting-collection="'.$this->vibe.'"', false)
        ->assertSee('Sorting approval request — Pearl')
        ->assertSee('configOpen: false')
        ->assertSee('wire:model="cardForm.name"', false);
});

it('sorts the main collection and an inner vibe at the same time', function () {
    $main = manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $vibe = manualSort($this, $this->admin, $this->vibe, 'gid://shopify/Product/102');

    expect(manualOrder($this->draft, $this->main))->toBe($main)
        ->and(manualOrder($this->draft, $this->vibe))->toBe($vibe)
        ->and($this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->sorter->id)
        ->and($this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->vibe)]['owner_id'])->toBe($this->admin->id);
});

it('keeps a parent push from publishing approved or legacy sorting', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->main, $revision)->fresh();
    $this->actingAs($this->reviewer);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $revision)->fresh();
    $shopifyBefore = array_column($this->fake->collections[$this->main]['products']['nodes'], 'id');

    $this->actingAs($this->admin);
    app(ShopYourVibeEditing::class)->token = null;
    $card = collect($this->draft->desired['cards'])->first();
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'edit_card', [
        'key' => $card['key'],
        'name' => $card['name'].' renamed',
        'image' => $card['image'] ?? '',
        'link' => $card['link'],
    ])->fresh();
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $this->workflow->push($this->draft->id);
    $this->draft->refresh();

    $reorders = array_values(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts') && ($call[1]['id'] ?? null) === $this->main));
    expect($reorders)->toBe([])
        ->and(array_column($this->fake->collections[$this->main]['products']['nodes'], 'id'))->toBe($shopifyBefore)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->shopify_confirmed_at)->toBeNull();
});

it('does not offer Push Other Changes when sorting is the only pending change', function () {
    $this->actingAs($this->sorter);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('startEditing');
    $order = manualOrder($this->draft, $this->main);
    $moved = $order[count($order) - 1];
    $page->call('moveProductToPosition', $this->main, $moved, 1, 1, 'sorter-position');

    expect($this->draft->fresh()->pending)->toBeTrue();
    $page->call('reviewPush')
        ->assertSet('confirmingPush', false)
        ->assertSee('Push Other Changes is unavailable')
        ->assertDontSee('Ready to push');
});

it('keeps a legacy draft without an owner out of the sorting locks', function () {
    $this->draft->update(['pending' => true, 'editing_state' => ['changes' => [], 'pending_owner_name' => 'Unknown legacy editor']]);
    $this->actingAs($this->sorter);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main);
    $page->assertSee('the CMS did not record who made them')
        ->assertDontSee('Unknown legacy editor');
    $page->call('startEditing');

    expect($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->sorter->id)
        ->and($this->draft->fresh()->editing_state['pending_owner_id'] ?? null)->toBeNull();
});

it('keeps vibe settings collapsed without dropping unsaved field values', function () {
    ShopYourVibeCollectionMapping::create([
        'parent_collection_id' => $this->main,
        'shopify_collection_id' => $this->vibe,
        'collection_name' => 'Pearl',
        'collection_handle' => 'pearl',
        'membership_tag' => 'pearl-tag',
        'design_value' => 'Beaded',
        'colour_style_value' => 'Solid',
        'is_active' => true,
    ]);
    $this->actingAs($this->sorter);
    $card = collect($this->draft->desired['cards'])->firstWhere('collection_gid', $this->vibe);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('editCard', $card['key']);
    $page->assertSee('configOpen: false')
        ->assertSee('wire:model="cardForm.name"', false)
        ->assertSee('wire:model="mappingForm.membership_tag"', false)
        ->assertSee('wire:model="mappingForm.design_value"', false)
        ->assertSee('wire:model="mappingForm.colour_style_value"', false);
    $page->set('cardForm.name', 'Kept Pearl');
    $page->set('mappingForm.membership_tag', 'kept-tag');

    expect($page->instance()->cardForm['name'])->toBe('Kept Pearl')
        ->and($page->instance()->mappingForm['membership_tag'])->toBe('kept-tag')
        ->and($page->html())->toContain('configOpen: false');
});

it('still returns a sorting request to the original sorter', function () {
    manualSort($this, $this->sorter, $this->vibe, 'gid://shopify/Product/102');
    $scope = ShopYourVibeEditing::sortScope($this->vibe);
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = $this->heldTokens[$this->vibe];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->reviewer->id, $this->vibe, $revision)->fresh();

    $this->actingAs($this->reviewer);
    $card = collect($this->draft->desired['cards'])->firstWhere('collection_gid', $this->vibe);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('editCard', $card['key']);
    $page->set('sortChangeFeedback', 'Move the pearl piece one position later.')
        ->call('requestSortingChanges', $this->vibe);

    $lock = $this->draft->fresh()->editing_state['locks'][$scope];
    expect($lock['pending_owner_id'])->toBe($this->sorter->id)
        ->and($lock['owner_id'] ?? null)->toBeNull()
        ->and($lock['review']['reviewer_id'])->toBe($this->reviewer->id)
        ->and($page->instance()->loadError)->toBeNull();
});

it('lets a product action run while another person holds the sorting lock', function () {
    manualSort($this, $this->sorter, $this->main, 'gid://shopify/Product/101');
    Bus::fake([PushShopYourVibeProductSiblings::class]);
    $this->actingAs($this->reviewer);
    $page = Livewire::test(ShopYourVibe::class)
        ->call('manage', $this->main)
        ->set('managingSiblingProductGid', 'gid://shopify/Product/101')
        ->set('selectedSiblings', [])
        ->call('saveProductSiblings');

    expect($page->instance()->loadError)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->sorter->id);
    Bus::assertDispatched(PushShopYourVibeProductSiblings::class);
});
