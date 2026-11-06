<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Services\ShopYourVibeShopify;
use App\Services\ProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';
require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';

it('rejects the original truncated full collection reproduction', function () {
    $fake = new VibeShopifyFake;
    $gid = 'gid://shopify/Collection/1';
    $fake->collections[$gid]['products']['nodes'] = [$fake->product(101)];
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    expect(fn () => app(ShopYourVibeShopify::class)->collection($gid))->toThrow(RuntimeException::class, 'incomplete pagination');
});

it('blocks the original generic engraved tracking reproduction with both flags disabled', function () {
    config()->set('engraved_beads.inventory_writes_enabled', false);
    config()->set('engraved_beads.store_contract_verified', false);
    config()->set('services.shopify.shop', 'audit-test.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    $fixture = sharedBeadFamily('audit-disabled-bead-family', 88000);
    $variant = $fixture['members'][2]['variant'];
    $variant->updateQuietly(['inventory_tracked' => true, 'inventory_local_dirty' => true, 'current_on_hand_quantity' => 9]);
    $mutations = [];
    Http::fake(function ($request) use ($variant, &$mutations) {
        $query = $request->data()['query'] ?? '';
        if (str_contains($query, 'mutation')) {
            $mutations[] = $query;
            return Http::response(['data' => []]);
        }
        if (str_contains($query, 'query ProductInventoryById')) {
            return Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id,
                'variants' => ['nodes' => [['id' => $variant->shopify_id, 'sku' => $variant->sku,
                    'inventoryItem' => ['id' => $variant->shopify_inventory_item_id]]]]]]]);
        }
        return Http::response(['data' => []]);
    });
    $result = app(ProductInventorySyncService::class)->syncVariants(collect([$variant]), inventoryAuthorizedUser()->id);
    expect($mutations)->toBeEmpty()->and($result['failed'])->toBe(1)
        ->and($variant->fresh()->inventory_local_dirty)->toBeTrue();
});

it('confirms scoped asynchronous jobs and recovers expired tickets without losing approval', function () {
    $fake = new VibeShopifyFake;
    $fake->jobDone = false;
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    $user = \App\Models\User::factory()->create(['is_active' => true]);
    \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\RolesEnum::Admin->value);
    $user->assignRole(\App\Enums\RolesEnum::Admin->value);
    $this->actingAs($user);
    $workflow = app(\App\Services\ShopYourVibeWorkflow::class);
    $editing = app(\App\Services\ShopYourVibeEditing::class);
    $gid = 'gid://shopify/Collection/1';
    $scope = $editing::sortScope($gid);
    $draft = $workflow->open($gid);
    $draft = $editing->acquireScope($draft->id, 0, $scope);
    $draft = $workflow->edit($draft->id, $draft->revision, 'reorder_products', [
        'collection_gid' => $gid, 'scope_revision' => 0,
        'ids' => ['gid://shopify/Product/102', 'gid://shopify/Product/101', 'gid://shopify/Product/103'],
        'explicit_ids' => ['gid://shopify/Product/101'],
    ]);
    // Isolate the publication layer with an approved-row fixture; approval authorization is covered by the main suite.
    \App\Models\SyvCollectionSortState::query()->update(['status' => 'awaiting_approval',
        'reviewer_id' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'approved_revision' => 1]);
    [$draft, $ticket] = $workflow->queueCollection($draft->id, 1, $gid);
    $fake->calls = [];
    $workflow->push($draft->id, $ticket);
    $jobQueries = array_filter($fake->calls, fn ($call) => str_contains($call[0], 'query VibeJob'));
    expect(count($jobQueries))->toBeGreaterThan(0)->and($draft->fresh()->remote_jobs)->not->toBeEmpty()
        ->and($draft->fresh()->pending)->toBeTrue();
    expect(fn () => $workflow->edit($draft->id, $draft->revision, 'reorder_products', [
        'collection_gid' => $gid, 'scope_revision' => 1, 'ids' => []]))->toThrow(RuntimeException::class, 'still processing');
    $fake->jobDone = true;
    [$draft, $retry] = $workflow->queueCollection($draft->id, 1, $gid);
    $workflow->push($draft->id, $retry);
    expect($draft->fresh()->pending)->toBeFalse()->and($draft->fresh()->remote_jobs)->toBeEmpty();
    $draft = $editing->acquireScope($draft->id, 1, $scope);
    $draft = $workflow->edit($draft->id, $draft->revision, 'reorder_products', [
        'collection_gid' => $gid, 'scope_revision' => 1,
        'ids' => ['gid://shopify/Product/101', 'gid://shopify/Product/102', 'gid://shopify/Product/103'],
        'explicit_ids' => ['gid://shopify/Product/101'],
    ]);
    \App\Models\SyvCollectionSortState::query()->update(['status' => 'awaiting_approval',
        'reviewer_id' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'approved_revision' => 2]);
    [$draft, $expiredTicket] = $workflow->queueCollection($draft->id, 2, $gid);
    $this->travel(841)->seconds();
    $job = new \App\Jobs\PushShopYourVibe($draft->id, $expiredTicket);
    $beforeExpired = count($fake->calls);
    $job->handle($workflow);
    $job->failed(new RuntimeException('Worker timeout'));
    expect(count($fake->calls))->toBe($beforeExpired);
    expect($draft->fresh()->editing_state['locks'][$scope]['status'])->toBeNull();
    $draft = $editing->acquireScope($draft->id, 2, $scope);
    expect(\App\Models\SyvCollectionSortState::first()->approved_revision)->toBe(2);
    [$draft, $replacement] = $workflow->queueCollection($draft->id, 2, $gid);
    $job->failed(new RuntimeException('Old worker timeout'));
    expect($draft->fresh()->editing_state['locks'][$scope]['push']['id'])->toBe($replacement['id']);
});
