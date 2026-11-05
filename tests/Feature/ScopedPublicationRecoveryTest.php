<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

function scopedRecoveryFixture($test, VibeShopifyFake $fake, int $count = 3): array
{
    $gid = 'gid://shopify/Collection/1';
    $fake->collections[$gid]['products']['nodes'] = array_map(fn ($id) => $fake->product($id), range(101, 100 + $count));
    $fake->collections[$gid]['productsCount']['count'] = $count;
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole(RolesEnum::Admin->value);
    $test->actingAs($actor);
    $editing = app(ShopYourVibeEditing::class);
    $workflow = app(ShopYourVibeWorkflow::class);
    $draft = $workflow->open($gid);
    $scope = $editing::sortScope($gid);
    $draft = $editing->acquireScope($draft->id, 0, $scope);
    $draft = $workflow->edit($draft->id, $draft->revision, 'reorder_products', ['collection_gid' => $gid,
        'scope_revision' => 0, 'ids' => array_reverse(array_column($draft->desired['collections'][$gid]['products'], 'id')),
        'explicit_ids' => ['gid://shopify/Product/101']]);
    SyvCollectionSortState::query()->update(['status' => SyvCollectionSortState::AWAITING,
        'reviewer_id' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(), 'approved_revision' => 1]);
    [$draft, $ticket] = $workflow->queueCollection($draft->id, 1, $gid);
    return compact('gid', 'scope', 'draft', 'ticket', 'workflow', 'editing', 'actor');
}

class DeferredScopedReorderFake extends VibeShopifyFake
{
    public array $pendingOrders = [];
    public array $polls = [];
    public int $reorders = 0;
    public bool $loseResponse = false;
    public function graphql(string $query, array $variables = []): array
    {
        if (str_contains($query, 'mutation VibeReorderProducts')) {
            if ($this->pendingOrders !== []) {
                throw new LogicException('A dependent chunk was sent before its earlier job finished.');
            }
            $before = $this->collections[$variables['id']]['products']['nodes'];
            parent::graphql($query, $variables);
            $job = 'gid://shopify/Job/'.++$this->reorders;
            $this->pendingOrders[$job] = [$variables['id'], $this->collections[$variables['id']]['products']['nodes']];
            $this->collections[$variables['id']]['products']['nodes'] = $before;
            if ($this->loseResponse) {
                throw new RuntimeException('Lost accepted reorder response');
            }
            return ['collectionReorderProducts' => ['job' => ['id' => $job], 'userErrors' => []]];
        }
        if (str_contains($query, 'query VibeJob')) {
            $id = $variables['id'];
            $done = ($this->polls[$id] = ($this->polls[$id] ?? 0) + 1) >= 2;
            if ($done && isset($this->pendingOrders[$id])) {
                [$gid, $products] = $this->pendingOrders[$id];
                $this->collections[$gid]['products']['nodes'] = $products;
                unset($this->pendingOrders[$id]);
            }
            return ['job' => ['id' => $id, 'done' => $done]];
        }
        return parent::graphql($query, $variables);
    }
}

it('waits for delayed jobs between dependent chunks and verifies exact final order', function () {
    $fake = new DeferredScopedReorderFake;
    extract(scopedRecoveryFixture($this, $fake, 501));
    $wanted = array_column($draft->desired['collections'][$gid]['products'], 'id');
    $workflow->push($draft->id, $ticket);
    expect($fake->reorders)->toBeGreaterThan(1)->and($fake->pendingOrders)->toBeEmpty()
        ->and($draft->fresh()->pending)->toBeFalse()->and($draft->fresh()->remote_jobs)->toBeEmpty()
        ->and(array_column($fake->collections[$gid]['products']['nodes'], 'id'))->toBe($wanted)
        ->and(SyvCollectionSortState::first()->status)->toBe(SyvCollectionSortState::PUBLISHED);
});

it('retains uncertain accepted writes and never blindly replays a lost response', function () {
    $fake = new DeferredScopedReorderFake;
    $fake->loseResponse = true;
    extract(scopedRecoveryFixture($this, $fake));
    $workflow->push($draft->id, $ticket);
    expect($draft->fresh()->remote_jobs[$gid])->toStartWith('unconfirmed:');
    [$draft, $retry] = $workflow->queueCollection($draft->id, 1, $gid);
    $workflow->push($draft->id, $retry);
    expect($fake->reorders)->toBe(1)->and($draft->fresh()->pending)->toBeTrue()
        ->and(SyvCollectionSortState::first()->shopify_confirmed_at)->toBeNull();
    // An unresolved remote order only blocks that collection, not another scope.
    $other = 'gid://shopify/Collection/2';
    $draft->refresh();
    $desired = $draft->desired;
    $snapshot = $draft->snapshot;
    $desired['collections'][$other] = $snapshot['collections'][$other] = app(\App\Services\ShopYourVibeShopify::class)->collection($other);
    $draft->update(['desired' => $desired, 'snapshot' => $snapshot]);
    $otherScope = $editing::sortScope($other);
    $editing->token = null;
    $draft = $editing->acquireScope($draft->id, 0, $otherScope);
    $draft = $workflow->edit($draft->id, $draft->revision, 'reorder_products', ['collection_gid' => $other,
        'scope_revision' => 0, 'ids' => ['gid://shopify/Product/103', 'gid://shopify/Product/102', 'gid://shopify/Product/101'],
        'explicit_ids' => ['gid://shopify/Product/101']]);
    expect($draft->remote_jobs[$gid])->toStartWith('unconfirmed:');
    expect(fn () => $editing->discardScope($draft->id, $gid, 1, 'Cannot discard unresolved remote work'))
        ->toThrow(RuntimeException::class, 'outstanding Shopify');
});

it('recovers a killed worker after its execution lease while retaining approval', function () {
    extract(scopedRecoveryFixture($this, new VibeShopifyFake));
    Cache::lock(ShopYourVibeEditing::scopePushLockKey($draft->id, $scope), 900)->get();
    $this->travel(841)->seconds();
    expect(fn () => $editing->acquireScope($draft->id, 1, $scope))->toThrow(RuntimeException::class);
    $this->travel(60)->seconds();
    $draft = $editing->acquireScope($draft->id, 1, $scope);
    expect($draft->editing_state['locks'][$scope]['status'])->toBeNull()
        ->and(SyvCollectionSortState::first()->approved_revision)->toBe(1);
});

it('cleans a permission-revoked ticket without sending new mutations', function () {
    $fake = new VibeShopifyFake;
    extract(scopedRecoveryFixture($this, $fake));
    $actor->update(['is_active' => false]);
    $before = count($fake->mutations());
    $workflow->push($draft->id, $ticket);
    expect(count($fake->mutations()))->toBe($before)
        ->and($draft->fresh()->editing_state['locks'][$scope]['status'])->toBeNull()
        ->and(SyvCollectionSortState::first()->approved_revision)->toBe(1);
});
