<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\SyvCollectionSortEvent;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeSorting;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $import = Import::create(['filename' => 'syv-scope', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->cait->id, 'is_current' => true, 'is_valid' => true]);
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
        $copy['title'] = 'Bold Soul';
        $desired['collections'][$this->vibe] = $copy;
        $snapshot = $this->draft->snapshot;
        $snapshot['collections'][$this->vibe] = $copy;
        $this->draft->update(['desired' => $desired, 'snapshot' => $snapshot]);
        $this->draft->refresh();
    }
});

function isolationOrder($draft, string $gid): array
{
    return array_column($draft->fresh()->desired['collections'][$gid]['products'], 'id');
}

function isolationShift(array $ids, string $product): array
{
    $from = array_search($product, $ids, true);
    $next = $ids;
    $item = array_splice($next, $from, 1)[0];
    array_splice($next, min($from + 1, count($next)), 0, [$item]);

    return $next;
}

function isolationSort($test, User $user, string $gid, string $product): array
{
    $test->actingAs($user);
    $editing = app(ShopYourVibeEditing::class);
    $scope = ShopYourVibeEditing::sortScope($gid);
    $revision = (int) ($test->draft->fresh()->editing_state['locks'][$scope]['revision'] ?? 0);
    $editing->token = null;
    $test->draft = $editing->acquireScope($test->draft->id, $revision, $scope)->fresh();
    $order = isolationShift(isolationOrder($test->draft, $gid), $product);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => $order,
        'explicit_ids' => [$product],
        'scope_revision' => $revision,
    ])->fresh();
    $test->scopeTokens[$gid] = app(ShopYourVibeEditing::class)->token;

    return $order;
}

it('keeps main collection sorting independent from vibe sorting', function () {
    $mainOrder = isolationSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $mainToken = app(ShopYourVibeEditing::class)->token;
    $vibeOrder = isolationSort($this, $this->shona, $this->vibe, 'gid://shopify/Product/102');
    $mainScope = ShopYourVibeEditing::sortScope($this->main);
    $vibeScope = ShopYourVibeEditing::sortScope($this->vibe);
    $draft = $this->draft->fresh();

    expect(isolationOrder($draft, $this->main))->toBe($mainOrder)
        ->and(isolationOrder($draft, $this->vibe))->toBe($vibeOrder)
        ->and($draft->editing_state['locks'][$mainScope]['owner_id'])->toBe($this->cait->id)
        ->and($draft->editing_state['locks'][$vibeScope]['owner_id'])->toBe($this->shona->id);

    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => app(ShopYourVibeEditing::class)->acquireScope($draft->id, (int) $draft->editing_state['locks'][$mainScope]['revision'], $mainScope))
        ->toThrow(RuntimeException::class, '14 minutes');

    $this->actingAs($this->cait);
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => app(ShopYourVibeEditing::class)->acquireScope($draft->id, (int) $draft->editing_state['locks'][$mainScope]['revision'], $mainScope))
        ->toThrow(RuntimeException::class, 'another tab');
    app(ShopYourVibeEditing::class)->token = $mainToken;
    $stale = isolationShift($mainOrder, 'gid://shopify/Product/103');
    expect(fn () => $this->workflow->edit($draft->id, $draft->revision, 'reorder_products', [
        'collection_gid' => $this->main,
        'ids' => $stale,
        'explicit_ids' => ['gid://shopify/Product/103'],
        'scope_revision' => 0,
    ]))->toThrow(RuntimeException::class, 'another tab');
    expect(isolationOrder($this->draft, $this->main))->toBe($mainOrder);

    $card = collect($draft->desired['cards'])->first();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($draft->id, $draft->revision, 'edit_card', [
        'key' => $card['key'],
        'name' => $card['name'].' updated',
        'image' => $card['image'] ?? '',
        'link' => $card['link'],
    ])->fresh();
    expect(isolationOrder($this->draft, $this->main))->toBe($mainOrder)
        ->and(collect($this->draft->desired['cards'])->firstWhere('key', $card['key'])['name'])->toBe($card['name'].' updated');

    $this->actingAs($this->cait);
    app(ShopYourVibeEditing::class)->token = $mainToken;
    $scopeRevision = (int) $this->draft->editing_state['locks'][$mainScope]['revision'];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    expect($this->draft->editing_state['locks'][$vibeScope]['owner_id'])->toBe($this->shona->id)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED);

    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    expect(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_by)->toBe($this->leanne->id)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->approved_at)->toBeNull();

    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $mainScope)->fresh();
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $scopeRevision, $this->main);
    $before = count($this->fake->calls);
    $this->workflow->push($queued->id, $ticket);
    $reorderCalls = array_values(array_filter($this->fake->calls, fn ($call) => str_contains($call[0], 'VibeReorderProducts')));
    expect($reorderCalls)->not->toBeEmpty()
        ->and(collect($reorderCalls)->pluck(1)->pluck('id')->unique()->all())->toBe([$this->main])
        ->and(isolationOrder($this->draft, $this->vibe))->toBe($vibeOrder)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and(count($this->fake->calls))->toBeGreaterThan($before);

    $orderBeforeRefresh = isolationOrder($this->draft, $this->vibe);
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->fresh()->revision))
        ->toThrow(RuntimeException::class);
    expect(isolationOrder($this->draft, $this->vibe))->toBe($orderBeforeRefresh)
        ->and(isolationOrder($this->draft, $this->main))->toBe($mainOrder);

    $this->actingAs($this->shona);
    $vibeRevision = (int) $this->draft->fresh()->editing_state['locks'][$vibeScope]['revision'];
    $mainStill = isolationOrder($this->draft, $this->main);
    $reason = 'Bold Soul sorting is not part of this discard.';
    $this->draft = app(ShopYourVibeEditing::class)->discardScope($this->draft->id, $this->vibe, $vibeRevision, $reason)->fresh();
    expect(isolationOrder($this->draft, $this->main))->toBe($mainStill)
        ->and(SyvCollectionSortEvent::query()->where('collection_gid', $this->vibe)->where('type', 'discarded')->count())->toBeGreaterThan(0)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::PUBLISHED);
});

it('adds a vibe and edits its card without replacing pending main sorting or its approval', function () {
    $mainOrder = isolationSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $mainScope = ShopYourVibeEditing::sortScope($this->main);
    $beforeCards = count($this->draft->desired['cards']);
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'add_card', [
        'collection_gid' => 'gid://shopify/Collection/4',
    ])->fresh();
    $layoutToken = app(ShopYourVibeEditing::class)->token;
    expect(isolationOrder($this->draft, $this->main))->toBe($mainOrder)
        ->and($this->draft->desired['cards'])->toHaveCount($beforeCards + 1)
        ->and($this->draft->editing_state['locks'][$mainScope]['owner_id'])->toBe($this->cait->id);

    $this->actingAs($this->cait);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$mainScope]['revision'];
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->main, $scopeRevision)->fresh();
    $approvedAt = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at;
    $card = collect($this->draft->desired['cards'])->first();
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = $layoutToken;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'edit_card', [
        'key' => $card['key'],
        'name' => $card['name'].' renamed',
        'image' => $card['image'] ?? '',
        'link' => $card['link'],
    ])->fresh();
    $row = SyvCollectionSortState::query()->where('collection_gid', $this->main)->first();
    expect($row->approved_at)->toEqual($approvedAt)
        ->and($row->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(isolationOrder($this->draft, $this->main))->toBe($mainOrder);

    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $mainScope)->fresh();
    $replacement = isolationShift($mainOrder, 'gid://shopify/Product/102');
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', [
        'collection_gid' => $this->main,
        'ids' => $replacement,
        'explicit_ids' => ['gid://shopify/Product/102'],
        'scope_revision' => $scopeRevision,
    ])->fresh();
    expect(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->approved_at)->toBeNull()
        ->and(isolationOrder($this->draft, $this->main))->toBe($replacement);
});

it('pushes an approved vibe order without publishing pending main collection sorting', function () {
    $mainOrder = isolationSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $vibeOrder = isolationSort($this, $this->shona, $this->vibe, 'gid://shopify/Product/102');
    $vibeScope = ShopYourVibeEditing::sortScope($this->vibe);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$vibeScope]['revision'];
    $this->actingAs($this->shona);
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->vibe];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->vibe, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->vibe, $scopeRevision)->fresh();
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $vibeScope)->fresh();
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $scopeRevision, $this->vibe);
    $this->fake->calls = [];
    $this->workflow->push($queued->id, $ticket);
    $reorderIds = collect($this->fake->calls)
        ->filter(fn ($call) => str_contains($call[0], 'VibeReorderProducts'))
        ->pluck(1)
        ->pluck('id')
        ->unique()
        ->all();

    expect($reorderIds)->toBe([$this->vibe])
        ->and(isolationOrder($this->draft, $this->main))->toBe($mainOrder)
        ->and(isolationOrder($this->draft, $this->vibe))->toBe($vibeOrder)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and($this->draft->fresh()->pending)->toBeTrue();
});

it('returns only the collection whose sorting changes were requested', function () {
    $mainOrder = isolationSort($this, $this->cait, $this->main, 'gid://shopify/Product/101');
    $vibeOrder = isolationSort($this, $this->shona, $this->vibe, 'gid://shopify/Product/102');
    $mainScope = ShopYourVibeEditing::sortScope($this->main);
    $vibeScope = ShopYourVibeEditing::sortScope($this->vibe);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$mainScope]['revision'];
    $this->actingAs($this->cait);
    app(ShopYourVibeEditing::class)->token = $this->scopeTokens[$this->main];
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $this->main, $scopeRevision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->requestScopeChanges($this->draft->id, $this->main, $scopeRevision, 'Move Product 101 back.')->fresh();
    $locks = $this->draft->editing_state['locks'];

    expect($locks[$mainScope]['pending_owner_id'])->toBe($this->cait->id)
        ->and($locks[$mainScope]['owner_id'] ?? null)->toBeNull()
        ->and($locks[$vibeScope]['owner_id'])->toBe($this->shona->id)
        ->and(app(ShopYourVibeEditing::class)->active($locks[$vibeScope]))->toBeTrue()
        ->and(isolationOrder($this->draft, $this->main))->toBe($mainOrder)
        ->and(isolationOrder($this->draft, $this->vibe))->toBe($vibeOrder)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->main)->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->vibe)->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and(SyvCollectionSortEvent::query()->where('collection_gid', $this->main)->where('type', 'changes_requested')->count())->toBeGreaterThan(0)
        ->and(SyvCollectionSortEvent::query()->where('collection_gid', $this->vibe)->where('type', 'changes_requested')->count())->toBe(0);
});
