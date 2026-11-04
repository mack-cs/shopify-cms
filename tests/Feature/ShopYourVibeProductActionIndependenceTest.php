<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\PushShopYourVibeProductSiblings;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\SyvCollectionSortEvent;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeAssignmentService;
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
    $this->cait = User::factory()->create(['name' => 'Cait', 'email' => 'cait@example.com', 'is_active' => true]);
    $this->leanne = User::factory()->create(['name' => 'Leanne', 'email' => 'leanne@example.com', 'is_active' => true]);
    $this->shona = User::factory()->create(['name' => 'Shona', 'email' => 'shona@example.com', 'is_active' => true]);
    foreach ([$this->cait, $this->leanne, $this->shona] as $user) {
        $user->assignRole(RolesEnum::Admin->value);
    }
    $import = Import::create(['filename' => 'syv-independence', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->cait->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle'],
        ]));
    }
    foreach ([101, 102, 103, 104] as $id) {
        Product::withoutEvents(fn () => Product::create([
            'import_id' => $import->id,
            'shopify_id' => 'gid://shopify/Product/'.$id,
            'handle' => 'product-'.$id,
            'title' => 'Product '.$id,
            'type' => 'Bracelets',
            'tags' => '',
        ]));
    }
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->sorting = app(ShopYourVibeSorting::class);
    $this->editing = app(ShopYourVibeEditing::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->main = 'gid://shopify/Collection/1';
    $this->vibe = 'gid://shopify/Collection/2';
    $desired = $this->draft->desired;
    $copy = $desired['collections'][$this->main];
    $copy['gid'] = $this->vibe;
    $copy['title'] = 'Bold Soul';
    $desired['collections'][$this->vibe] = $copy;
    $snapshot = $this->draft->snapshot;
    $snapshot['collections'][$this->vibe] = $copy;
    $this->draft->update(['desired' => $desired, 'snapshot' => $snapshot, 'pending' => false]);
    $this->draft->refresh();
    ShopYourVibeCollectionMapping::create([
        'shopify_collection_id' => $this->vibe,
        'parent_collection_id' => $this->main,
        'collection_name' => 'Bold Soul',
        'collection_handle' => 'pearl',
        'membership_tag' => 'bold-soul',
        'is_active' => true,
    ]);
});

function independenceOrder($draft, string $gid): array
{
    return array_column($draft->fresh()->desired['collections'][$gid]['products'], 'id');
}

function independenceSort($test, User $user, string $gid, string $product): array
{
    $test->actingAs($user);
    $editing = app(ShopYourVibeEditing::class);
    $scope = ShopYourVibeEditing::sortScope($gid);
    $revision = (int) ($test->draft->fresh()->editing_state['locks'][$scope]['revision'] ?? 0);
    $editing->token = $test->scopeTokens[$gid] ?? null;
    $test->draft = $editing->acquireScope($test->draft->id, $revision, $scope)->fresh();
    $ids = independenceOrder($test->draft, $gid);
    $from = array_search($product, $ids, true);
    $next = $ids;
    $item = array_splice($next, $from, 1)[0];
    array_splice($next, min($from + 1, count($next)), 0, [$item]);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => $next,
        'explicit_ids' => [$product],
        'scope_revision' => $revision,
    ])->fresh();
    $test->scopeTokens[$gid] = app(ShopYourVibeEditing::class)->token;

    return $next;
}

it('lets Leanne change a product vibe while Cait is sorting Livi Road', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $vibe = $this->draft->desired;
    $vibe['collections'][$this->vibe]['products'] = array_values(array_filter(
        $vibe['collections'][$this->vibe]['products'],
        fn ($product) => $product['id'] !== 'gid://shopify/Product/101'
    ));
    $snapshot = $this->draft->snapshot;
    $snapshot['collections'][$this->vibe]['products'] = $vibe['collections'][$this->vibe]['products'];
    $this->draft->update(['desired' => $vibe, 'snapshot' => $snapshot]);

    $this->actingAs($this->leanne);
    $reorderBefore = count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts')));
    app(ShopYourVibeAssignmentService::class)->assign($this->main, 'gid://shopify/Product/101', [$this->vibe]);
    NewProductDraft::create([
        'handle' => 'planned-only',
        'title' => 'Planned',
        'tags' => 'bold-soul',
        'status' => 'draft',
    ]);

    $draft = $this->draft->fresh();
    expect(independenceOrder($draft, $this->main))->toBe($order)
        ->and(independenceOrder($draft, $this->vibe))->toContain('gid://shopify/Product/101')
        ->and($draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->cait->id)
        ->and(count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts'))))->toBe($reorderBefore);
});

it('adds a collection member while Cait holds that sorting lock', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'add_products', [
        'collection_gid' => $this->main,
        'ids' => ['gid://shopify/Product/104'],
    ])->fresh();

    expect(independenceOrder($this->draft, $this->main))->toBe([...$order, 'gid://shopify/Product/104'])
        ->and($this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->cait->id)
        ->and($this->draft->revision)->toBe($this->workflow->open($this->main)->revision);
});

it('removes a collection member while Cait holds that sorting lock', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'remove_product', [
        'collection_gid' => $this->main,
        'product_gid' => 'gid://shopify/Product/103',
    ])->fresh();

    expect(independenceOrder($this->draft, $this->main))->toBe(array_values(array_filter($order, fn ($id) => $id !== 'gid://shopify/Product/103')))
        ->and($this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->cait->id);
});

it('keeps a membership change when Cait next drags a stale grid', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'add_products', [
        'collection_gid' => $this->main,
        'ids' => ['gid://shopify/Product/104'],
    ])->fresh();
    $stale = array_values(array_filter($order, fn ($id) => $id !== 'gid://shopify/Product/101'));
    array_unshift($stale, 'gid://shopify/Product/101');
    $this->actingAs($this->cait);
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->main];
    $scopeRevision = (int) $this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['revision'];
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', [
        'collection_gid' => $this->main,
        'ids' => $stale,
        'explicit_ids' => ['gid://shopify/Product/101'],
        'scope_revision' => $scopeRevision,
    ])->fresh();

    $saved = independenceOrder($this->draft, $this->main);
    expect($saved)->toContain('gid://shopify/Product/104')
        ->and(array_values(array_filter($saved, fn ($id) => $id !== 'gid://shopify/Product/104')))->toBe($stale);
});

it('requires re-review when membership changes after approval', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    $approvedEvents = SyvCollectionSortEvent::query()->where('type', 'approved')->count();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'remove_product', [
        'collection_gid' => $this->main,
        'product_gid' => 'gid://shopify/Product/102',
    ])->fresh();
    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first();

    expect($row->approved_at)->toBeNull()
        ->and($row->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(independenceOrder($this->draft, $this->main))->not->toContain('gid://shopify/Product/102')
        ->and(SyvCollectionSortEvent::query()->where('type', 'approved')->count())->toBe($approvedEvents)
        ->and(SyvCollectionSortEvent::query()->where('type', 'approval_invalidated')->where('collection_gid', $this->main)->exists())->toBeTrue()
        ->and(independenceOrder($this->draft, $this->vibe))->toContain('gid://shopify/Product/102');
});

it('leaves an approval intact when an unrelated product is edited', function () {
    independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $scopeRevision = (int) $this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['revision'];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    $approvedAt = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at;
    $card = collect($this->draft->desired['cards'])->first();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'edit_card', [
        'key' => $card['key'],
        'name' => $card['name'].' notes',
        'image' => $card['image'] ?? '',
        'link' => $card['link'],
    ])->fresh();

    expect(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at?->equalTo($approvedAt))->toBeTrue();
});

it('marks one collection reviewed without clearing another approval', function () {
    independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    independenceSort($this, $this->shona, $this->vibe, 'gid://shopify/Product/102');
    $mainRevision = (int) $this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['revision'];
    $vibeRevision = (int) $this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->vibe)]['revision'];
    $this->actingAs($this->cait);
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $mainRevision)->fresh();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->vibe];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->vibe, $vibeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $mainRevision)->fresh();
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->vibe, $vibeRevision)->fresh();
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->sorting->markReviewed($this->draft->id, $this->draft->revision, $this->vibe, 'gid://shopify/Product/103', $vibeRevision);

    expect(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at)->not->toBeNull()
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->where('product_key', 'gid://shopify/Product/103')->first()->approved_at)->toBeNull();
});

it('renews the sorting scope from the editor heartbeat', function () {
    $this->actingAs($this->cait);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->main)->call('startEditing');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $before = $this->draft->fresh()->editing_state['locks'][$scope]['expires_at'];
    $this->travel(2)->minutes();
    $page->call('editingHeartbeat');
    $draft = $this->draft->fresh();

    expect($draft->editing_state['locks'][$scope]['expires_at'])->not->toBe($before)
        ->and($draft->editing_state['owner_id'] ?? null)->toBeNull()
        ->and($draft->editing_state['locks'][$scope]['owner_id'])->toBe($this->cait->id);
});

it('edits product metadata while a sorting lock is active', function () {
    independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    Bus::fake([PushShopYourVibeProductSiblings::class]);
    $this->actingAs($this->leanne);
    $page = Livewire::test(ShopYourVibe::class)
        ->call('manage', $this->main)
        ->set('managingSiblingProductGid', 'gid://shopify/Product/101')
        ->set('selectedSiblings', [])
        ->call('saveProductSiblings');

    expect($page->instance()->loadError)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['owner_id'])->toBe($this->cait->id);
    Bus::assertDispatched(PushShopYourVibeProductSiblings::class);
});

it('creates an unrelated vibe while sorting is still pending', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->queueCreateVibe($this->draft->id, $this->draft->revision, [
        'mode' => 'existing',
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    expect($this->draft->pending)->toBeTrue()->and($this->draft->status)->toBe('creating');
    $this->workflow->runImmediate($this->draft->id, $this->draft->operation['attempt']);
    $this->draft->refresh();

    expect($this->draft->status)->toBe('synced')
        ->and($this->draft->pending)->toBeTrue()
        ->and(independenceOrder($this->draft, $this->main))->toBe($order)
        ->and(collect($this->draft->desired['cards'])->pluck('collection_gid'))->toContain('gid://shopify/Collection/4');
});

it('discards one collection without discarding another user\'s sorting', function () {
    $main = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $vibe = independenceSort($this, $this->shona, $this->vibe, 'gid://shopify/Product/102');
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => app(ShopYourVibeEditing::class)->discard(
        $this->draft->id,
        $this->draft->revision,
        'Leanne cannot discard while Cait and Shona are sorting'
    ))->toThrow(RuntimeException::class, 'active editing lock');

    $this->actingAs($this->cait);
    $revision = (int) $this->draft->fresh()->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['revision'];
    $this->draft = app(ShopYourVibeEditing::class)->discardScope(
        $this->draft->id,
        $this->main,
        $revision,
        'Cait dropped the Livi Road order only'
    )->fresh();

    expect(independenceOrder($this->draft, $this->vibe))->toBe($vibe)
        ->and(independenceOrder($this->draft, $this->main))->not->toBe($main)
        ->and(SyvCollectionSortEvent::query()->where('collection_gid', $this->vibe)->where('type', 'discarded')->exists())->toBeFalse();
});

it('keeps an approved sort out of a parent card push', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $scopeRevision = (int) $this->draft->editing_state['locks'][ShopYourVibeEditing::sortScope($this->main)]['revision'];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    $card = collect($this->draft->desired['cards'])->first();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'edit_card', [
        'key' => $card['key'],
        'name' => $card['name'].' published',
        'image' => $card['image'] ?? '',
        'link' => $card['link'],
    ])->fresh();
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision)->fresh();
    $this->workflow->push($this->draft->id, $this->draft->editing_state['push']);
    $this->draft->refresh();
    $reorderIds = collect($this->fake->calls)
        ->filter(fn ($call) => str_contains($call[0], 'VibeReorderProducts'))
        ->map(fn ($call) => $call[1]['id'] ?? null)
        ->all();

    expect($reorderIds)->not->toContain($this->main)
        ->and(independenceOrder($this->draft, $this->main))->toBe($order)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->shopify_confirmed_at)->toBeNull();
});

it('rejects a scoped sort push when Shopify membership changed', function () {
    independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $order = independenceOrder($this->draft, $this->main);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $scope)->fresh();
    array_pop($this->fake->collections[$this->main]['products']['nodes']);
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $scopeRevision, $this->main);
    $before = count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts')));
    $this->workflow->push($queued->id, $ticket);
    $draft = $this->draft->fresh();

    expect($draft->last_error)->toContain('Shopify membership')
        ->and(independenceOrder($draft, $this->main))->toBe($order)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at)->toBeNull()
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->shopify_confirmed_at)->toBeNull()
        ->and(count(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts'))))->toBe($before);
});

it('keeps approval and the pending order when Shopify rejects the reorder', function () {
    $order = independenceSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $scope = ShopYourVibeEditing::sortScope($this->main);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $scope)->fresh();
    $this->fake->failProductReorder = true;
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $scopeRevision, $this->main);
    $this->workflow->push($queued->id, $ticket);
    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first();

    expect($this->draft->fresh()->pending)->toBeTrue()
        ->and($this->draft->fresh()->status)->toBe('pending')
        ->and(independenceOrder($this->draft, $this->main))->toBe($order)
        ->and($row->approved_at)->not->toBeNull()
        ->and($row->shopify_confirmed_at)->toBeNull()
        ->and($row->status)->toBe(SyvCollectionSortState::AWAITING);
});

it('lets the original sorter continue in the same browser tab after request changes', function () {
    $gid = $this->main;
    $this->actingAs($this->cait);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $gid)->call('startEditing');
    $ids = independenceOrder($this->draft, $gid);
    $moved = array_splice($ids, 0, 1)[0];
    array_splice($ids, 1, 0, [$moved]);
    $page->call('reorderProducts', $gid, $ids, 1, 'cait-tab', $moved);
    $page->set('sortReviewerId', $this->leanne->id)->call('submitSorting');
    expect($page->instance()->loadError)->toBeNull();

    $this->actingAs($this->leanne);
    Livewire::test(ShopYourVibe::class)->call('manage', $gid)->call('reviewSorting')
        ->set('sortChangeFeedback', 'Move Product 101 back one place.')
        ->call('requestSortingChanges');

    $this->actingAs($this->cait);
    $again = $ids;
    $product = array_splice($again, 1, 1)[0];
    array_unshift($again, $product);
    $page->call('reorderProducts', $gid, $again, 2, 'cait-tab', $product);

    expect($page->instance()->loadError)->toBeNull()
        ->and(independenceOrder($this->draft, $gid))->toBe($again);
});
