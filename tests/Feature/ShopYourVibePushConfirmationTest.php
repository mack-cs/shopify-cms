<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Exceptions\ShopYourVibeCollectionUnavailableException;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new class extends VibeShopifyFake
    {
        public ?Closure $beforeQuery = null;

        public ?Closure $afterQuery = null;

        public function graphql(string $query, array $variables = []): array
        {
            if ($this->beforeQuery) {
                ($this->beforeQuery)($query, $variables);
            }
            $result = parent::graphql($query, $variables);

            return $this->afterQuery ? ($this->afterQuery)($query, $variables, $result) : $result;
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    $user = User::factory()->create();
    $import = Import::create(['filename' => 'f1', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle'],
        ]));
    }
    Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/104', 'handle' => 'f1-product', 'title' => 'Product 104']));
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->draft = $this->workflow->loadProducts($this->draft->id, $this->draft->revision, 'gid://shopify/Metaobject/11');
    $this->draft->update(['refreshed_at' => now()->subDay()]);
    $this->confirmedAt = $this->draft->refreshed_at->toISOString();
});

function f1Edit($test, string $operation, array $input): void
{
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, $operation, $input);
}

function f1Reorder($test, string $gid = 'gid://shopify/Collection/2'): void
{
    f1Edit($test, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => array_reverse(array_column($test->draft->desired['collections'][$gid]['products'], 'id')),
    ]);
}

function f1Push($test): void
{
    $test->draft = $test->workflow->queue($test->draft->id, $test->draft->revision);
    $test->workflow->push($test->draft->id);
    $test->draft->refresh();
}

function f1AssertIncomplete($test): void
{
    expect($test->draft->status)->toBe('failed')
        ->and($test->draft->pending)->toBeTrue()
        ->and($test->draft->refreshed_at->toISOString())->toBe($test->confirmedAt)
        ->and($test->draft->last_error)->not->toBeEmpty();
}

it('F1 preserves an unavailable linked reorder and blocks all new mutations in preflight', function () {
    f1Reorder($this);
    f1Reorder($this, $this->draft->collection_gid);
    $desired = $this->draft->desired;
    $snapshot = $this->draft->snapshot;
    ShopYourVibeCollectionMapping::create([
        'parent_collection_id' => $this->draft->collection_gid, 'shopify_collection_id' => 'gid://shopify/Collection/2',
        'collection_name' => 'Pearl', 'collection_handle' => 'pearl', 'is_active' => true,
    ]);
    unset($this->fake->collections['gid://shopify/Collection/2']);

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired)->toBe($desired)->and($this->draft->snapshot)->toBe($snapshot)
        ->and($this->draft->last_error)->toContain('Push incomplete', 'gid://shopify/Collection/2', 'historical data were kept')
        ->and($this->fake->mutations())->toBe([])
        ->and(ShopYourVibeCollectionMapping::firstOrFail()->is_active)->toBeTrue();
});

it('F1 preserves unavailable linked membership edits', function (string $operation) {
    f1Edit($this, $operation, $operation === 'add_products'
        ? ['collection_gid' => 'gid://shopify/Collection/2', 'ids' => ['gid://shopify/Product/104']]
        : ['collection_gid' => 'gid://shopify/Collection/2', 'product_gid' => 'gid://shopify/Product/101']);
    $desired = $this->draft->desired;
    $snapshot = $this->draft->snapshot;
    unset($this->fake->collections['gid://shopify/Collection/2']);

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired)->toBe($desired)->and($this->draft->snapshot)->toBe($snapshot)
        ->and($this->fake->mutations())->toBe([]);
})->with(['add_products', 'remove_product']);

it('F1 retains confirmed collection checkpoints when another collection disappears at final readback and retries without repeating writes', function () {
    f1Reorder($this, $this->draft->collection_gid);
    f1Reorder($this);
    $wanted = $this->draft->desired;
    $reads = 0;
    $savedRemote = null;
    $fake = $this->fake;
    $this->fake->beforeQuery = function (string $query, array $variables) use (&$reads, &$savedRemote, $fake) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2' && ++$reads === 3) {
            $savedRemote = $fake->collections[$variables['id']];
            unset($fake->collections[$variables['id']]);
        }
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired['collections'])->toBe($wanted['collections'])
        ->and($this->draft->progress)->toContain('Products synced: Necklaces', 'Products synced: Pearl')
        ->and($this->draft->snapshot['collections']['gid://shopify/Collection/2']['products'])->toBe($wanted['collections']['gid://shopify/Collection/2']['products']);
    $mutations = count($this->fake->mutations());
    $this->fake->beforeQuery = null;
    $this->fake->collections['gid://shopify/Collection/2'] = $savedRemote;

    f1Push($this);

    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and(count($this->fake->mutations()))->toBe($mutations)
        ->and($this->draft->refreshed_at->toISOString())->not->toBe($this->confirmedAt);
});

it('F1 retries an initially unavailable collection using the preserved requested order', function () {
    f1Reorder($this);
    $wantedIds = array_column($this->draft->desired['collections']['gid://shopify/Collection/2']['products'], 'id');
    $savedRemote = $this->fake->collections['gid://shopify/Collection/2'];
    unset($this->fake->collections['gid://shopify/Collection/2']);
    f1Push($this);
    f1AssertIncomplete($this);
    $this->fake->collections['gid://shopify/Collection/2'] = $savedRemote;

    f1Push($this);

    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and(array_column($this->fake->collections['gid://shopify/Collection/2']['products']['nodes'], 'id'))->toBe($wantedIds)
        ->and(collect($this->fake->mutations())->filter(fn ($call) => str_contains($call[0], 'VibeReorderProducts')))->toHaveCount(1);
});

it('F1 keeps uncertain Shopify failures pending without calling them missing collections', function (string $message) {
    f1Reorder($this);
    $desired = $this->draft->desired;
    $snapshot = $this->draft->snapshot;
    $this->fake->beforeQuery = function (string $query, array $variables) use ($message) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2') {
            throw new RuntimeException($message);
        }
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired)->toBe($desired)->and($this->draft->snapshot)->toBe($snapshot)
        ->and($this->draft->last_error)->toBe($message)->not->toContain('no collection')
        ->and($this->fake->mutations())->toBe([]);
})->with(['Temporary Shopify API failure', 'Shopify API error: Access denied', 'Shopify connection timed out']);

it('F1 treats malformed collection responses as uncertain failures rather than deletion', function (string $shape) {
    f1Reorder($this);
    $desired = $this->draft->desired;
    $snapshot = $this->draft->snapshot;
    $this->fake->afterQuery = function (string $query, array $variables, array $result) use ($shape) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2') {
            if ($shape === 'missing root') {
                return [];
            }
            if ($shape === 'missing products') {
                unset($result['collection']['products']);
            } elseif ($shape === 'wrong identity') {
                $result['collection']['id'] = 'gid://shopify/Collection/999';
            } elseif ($shape === 'invalid page info') {
                $result['collection']['products']['pageInfo']['hasNextPage'] = 'false';
            } else {
                $result['collection']['products']['nodes'] = [null];
            }
        }

        return $result;
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired)->toBe($desired)->and($this->draft->snapshot)->toBe($snapshot)
        ->and($this->draft->last_error)->toContain('pending')->not->toContain('no collection')
        ->and($this->fake->mutations())->toBe([]);
    expect(fn () => app(ShopYourVibeShopify::class)->collection('gid://shopify/Collection/2'))
        ->toThrow(RuntimeException::class)->not->toThrow(ShopYourVibeCollectionUnavailableException::class);
})->with(['missing root', 'missing products', 'wrong identity', 'invalid page info', 'invalid product']);

it('F1 rejects a final readback that differs from the requested linked order', function () {
    f1Reorder($this);
    $desired = $this->draft->desired;
    $reads = 0;
    $this->fake->afterQuery = function (string $query, array $variables, array $result) use (&$reads) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2' && ++$reads === 3) {
            $result['collection']['products']['nodes'] = array_reverse($result['collection']['products']['nodes']);
        }

        return $result;
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired['collections'])->toBe($desired['collections'])
        ->and($this->draft->last_error)->toContain('not confirmed', 'Pearl');
});

it('F1 keeps a completed write pending when final verification is temporarily unavailable', function (string $failure) {
    f1Reorder($this);
    $wanted = $this->draft->desired;
    $reads = 0;
    $this->fake->afterQuery = function (string $query, array $variables, array $result) use (&$reads, $failure) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2' && ++$reads === 3) {
            if ($failure === 'malformed') {
                return [];
            }
            throw new RuntimeException('Temporary final verification failure');
        }

        return $result;
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->desired['collections'])->toBe($wanted['collections'])
        ->and($this->draft->last_error)->not->toContain('no collection');
    $mutations = count($this->fake->mutations());
    $this->fake->afterQuery = null;
    f1Push($this);
    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and(count($this->fake->mutations()))->toBe($mutations);
})->with(['malformed', 'temporary API failure']);

it('F1 rejects malformed parent confirmation without describing it as a missing collection', function () {
    f1Reorder($this);
    $this->fake->afterQuery = function (string $query, array $variables, array $result) {
        return str_contains($query, 'query VibeParent(') ? [] : $result;
    };

    f1Push($this);

    f1AssertIncomplete($this);
    expect($this->draft->last_error)->toContain('incomplete collection response')->not->toContain('no collection')
        ->and($this->fake->mutations())->toBe([]);
});

it('F1 does not let polling recovery clear unconfirmed work for a missing linked collection', function () {
    f1Reorder($this);
    $this->draft->update(['progress' => ['Previous partial push checkpoint']]);
    unset($this->fake->collections['gid://shopify/Collection/2']);

    expect(fn () => $this->workflow->confirmCompletedPush($this->draft->id))
        ->toThrow(ShopYourVibeCollectionUnavailableException::class);
    $this->draft->refresh();
    expect($this->draft->pending)->toBeTrue()->and($this->draft->status)->not->toBe('synced')
        ->and($this->draft->refreshed_at->toISOString())->toBe($this->confirmedAt);
});

it('F1 marks a push synced only after every requested collection outcome is confirmed', function () {
    f1Reorder($this, $this->draft->collection_gid);
    f1Edit($this, 'add_products', ['collection_gid' => 'gid://shopify/Collection/2', 'ids' => ['gid://shopify/Product/104']]);
    f1Reorder($this);
    $wanted = $this->draft->desired;

    f1Push($this);

    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and($this->draft->last_error)->toBeNull()
        ->and($this->draft->refreshed_at->toISOString())->not->toBe($this->confirmedAt);
    foreach ($wanted['collections'] as $gid => $collection) {
        expect(array_column($this->fake->collections[$gid]['products']['nodes'], 'id'))->toBe(array_column($collection['products'], 'id'));
    }
});
