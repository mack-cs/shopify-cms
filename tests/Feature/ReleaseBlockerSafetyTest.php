<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Services\EngravedBeads\EngravedBeadMutationGuard;
use App\Services\ShopYourVibeShopify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';

it('rejects malformed full collection snapshots', function ($fault) {
    $fake = new VibeShopifyFake;
    $gid = 'gid://shopify/Collection/1';
    switch ($fault) {
        case 'duplicate': $fake->collections[$gid]['products']['nodes'][1] = $fake->product(101); break;
        case 'missing count': unset($fake->collections[$gid]['productsCount']); break;
        case 'string count': $fake->collections[$gid]['productsCount']['count'] = '3'; break;
        case 'negative count': $fake->collections[$gid]['productsCount']['count'] = -1; break;
        case 'zero count': $fake->collections[$gid]['productsCount']['count'] = 0; break;
        case 'truncated': array_pop($fake->collections[$gid]['products']['nodes']); break;
        case 'missing terminal': $fake->collections[$gid]['products']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => null]; break;
    }
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    expect(fn () => app(ShopYourVibeShopify::class)->collection($gid))->toThrow(RuntimeException::class);
})->with(['duplicate', 'missing count', 'string count', 'negative count', 'zero count', 'truncated', 'missing terminal']);

it('reads exact pagination boundaries and retries a count change from the beginning', function ($size) {
    $fake = new class extends VibeShopifyFake {
        public array $pages = [];
        public function graphql(string $query, array $variables = []): array {
            if (str_contains($query, 'query VibeProducts')) {
                $this->calls[] = [$query, $variables];
                return ['collection' => array_shift($this->pages)];
            }
            return parent::graphql($query, $variables);
        }
    };
    $gid = 'gid://shopify/Collection/1';
    $template = $fake->collections[$gid];
    $pages = [];
    foreach (array_chunk(range(1, $size), 100) as $index => $ids) {
        $page = $template;
        $page['productsCount']['count'] = $size;
        $page['products']['nodes'] = array_map(fn ($id) => $fake->product(1000 + $id), $ids);
        $more = ($index + 1) * 100 < $size;
        $page['products']['pageInfo'] = ['hasNextPage' => $more, 'endCursor' => $more ? 'cursor-'.$index : null];
        $pages[] = $page;
    }
    $fake->pages = $pages;
    if ($size > 100) {
        $changed = $pages[1]; $changed['productsCount']['count']++;
        $fake->pages = array_merge([$pages[0], $changed], $pages);
    }
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    $result = app(ShopYourVibeShopify::class)->collection($gid);
    expect($result['products'])->toHaveCount($size);
    if ($size > 100) {
        expect($fake->calls[2][1]['after'])->toBeNull();
    }
})->with([100, 101, 200, 201]);

it('guards all shared mutation forms for inactive bead identities while permitting reads', function ($identity, $flags) {
    config(['engraved_beads.inventory_writes_enabled' => $flags[0], 'engraved_beads.store_contract_verified' => $flags[1]]);
    $family = sharedBeadFamily('guard-matrix-bead-family', 99000);
    $member = $family['members'][2];
    // Mappings protect even when catalogue tags and denormalized configuration are gone.
    if ($identity === 'mapping') {
        $member['product']->updateQuietly(['tags' => '', 'engraved_bead_configuration' => null, 'engraved_bead_quantity' => null]);
    }
    $gid = match ($identity) {
        'product' => $member['product']->shopify_id,
        'variant' => $member['variant']->shopify_id,
        default => $member['variant']->shopify_inventory_item_id,
    };
    $guard = app(EngravedBeadMutationGuard::class);
    $guard->graphql('query Read($id: ID!) { node(id: $id) { id } }', ['id' => $gid]);
    foreach (['inventoryItemUpdate', 'inventorySetQuantities', 'inventoryAdjustQuantities', 'inventoryMoveQuantities',
        'productUpdate', 'productVariantsBulkUpdate', 'publishablePublish', 'productCreate'] as $mutation) {
        expect(fn () => $guard->graphql('mutation Update($input: Input!) { '.$mutation.'(input: $input) { userErrors { message } } }',
            ['input' => ['id' => $gid]]))->toThrow(RuntimeException::class, 'disabled');
    }
    expect(fn () => $guard->rest('PUT', 'products/99002.json', []))->toThrow(RuntimeException::class, 'disabled');
    expect(fn () => app(\App\Services\ShopifyApiClient::class)->rest('PUT', 'products/99002.json', []))
        ->toThrow(RuntimeException::class, 'disabled');
    expect(fn () => app(\App\Services\Shopify\ShopifyGraphqlClient::class)->query('mutation Update { productUpdate { id } }', ['id' => $gid]))
        ->toThrow(RuntimeException::class, 'disabled');
    expect(fn () => $guard->graphql('mutation { inventoryItemUpdate(id: "'.$gid.'") { id } }', []))->toThrow(RuntimeException::class, 'disabled');
})->with(['product', 'variant', 'inventory', 'mapping'])->with([[[false, false]], [[false, true]], [[true, false]]]);

it('protects undiscovered bead products and permits unrelated stack identities', function () {
    config(['engraved_beads.inventory_writes_enabled' => false, 'engraved_beads.store_contract_verified' => false]);
    $family = sharedBeadFamily('undiscovered-bead-family', 97000);
    // Simulate a catalogue identity that has not been discovered, without deleting audit history.
    $family['family']->members()->where('shopify_variant_id', $family['members'][2]['variant']->shopify_id)->update(['shopify_product_id' => 'gid://shopify/Product/999999',
        'shopify_variant_id' => 'gid://shopify/ProductVariant/999999', 'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/999999']);
    $guard = app(EngravedBeadMutationGuard::class);
    expect(fn () => $guard->graphql('mutation Inventory { inventoryItemUpdate { id } }',
        ['inventoryItemId' => $family['members'][2]['variant']->shopify_inventory_item_id]))->toThrow(RuntimeException::class);
    $guard->graphql('mutation Inventory { inventoryItemUpdate { id } }', ['inventoryItemId' => 'gid://shopify/InventoryItem/123456789']);
    $family['family']->members()->where('required_beads', 3)->update(['shopify_inventory_item_id' => 'gid://shopify/InventoryItem/777']);
    \App\Models\Product::withoutEvents(fn () => \App\Models\Product::create([
        'import_id' => $family['members'][1]['product']->import_id, 'shopify_id' => 'gid://shopify/Product/777',
        'handle' => 'ordinary-stack-777', 'title' => 'Ordinary stack', 'tags' => 'bundles', 'is_bundle' => true,
    ]));
    $guard->graphql('mutation Update { productUpdate { id } }', ['productId' => 'gid://shopify/Product/777']);
    expect(true)->toBeTrue();
});

it('keeps the last confirmed snapshot when a full refresh is truncated', function () {
    $fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $fake);
    $workflow = app(\App\Services\ShopYourVibeWorkflow::class);
    $draft = $workflow->open('gid://shopify/Collection/1');
    $snapshot = $draft->snapshot;
    $fake->collections[$draft->collection_gid]['products']['nodes'] = [];
    expect(fn () => $workflow->refresh($draft->id, $draft->revision))->toThrow(RuntimeException::class, 'incomplete pagination');
    expect($draft->fresh()->snapshot)->toBe($snapshot)->and($draft->fresh()->desired)->toBe($snapshot);
});
