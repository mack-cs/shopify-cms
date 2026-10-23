<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Exceptions\ShopYourVibeCollectionUnavailableException;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Models\NewProductDraft;
use App\Services\ShopYourVibeAssignmentService;
use App\Filament\Pages\ShopYourVibe;
use App\Enums\RolesEnum;
use App\Jobs\RefreshShopYourVibeDraft;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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
    $import = Import::create(['filename' => 'f2', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id, 'is_current' => true, 'is_valid' => true]);
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
    $this->card = collect($this->draft->desired['cards'])->firstWhere('collection_gid', 'gid://shopify/Collection/2');
    $this->mapping = app(ShopYourVibeAssignmentService::class)->syncMapping($this->draft->collection_gid, $this->card, $this->draft->desired['collections']['gid://shopify/Collection/2']);
});

function f2DeleteAndRefresh($test, bool $discard = false): void
{
    unset($test->fake->collections['gid://shopify/Collection/2']);
    $test->draft = $test->workflow->refresh($test->draft->id, $test->draft->revision, $discard);
}

it('F2 deactivates verified missing mappings while retaining cards and historical products', function () {
    $history = $this->draft->snapshot['collections']['gid://shopify/Collection/2'];
    $cards = $this->draft->desired['cards'];
    $otherParentMapping = $this->mapping->replicate();
    $otherParentMapping->parent_collection_id = 'gid://shopify/Collection/99';
    $otherParentMapping->save();

    f2DeleteAndRefresh($this);

    expect($this->mapping->fresh()->is_active)->toBeFalse()->and($otherParentMapping->fresh()->is_active)->toBeFalse()
        ->and($this->draft->desired['missing_collection_ids'])->toContain('gid://shopify/Collection/2')
        ->and($this->draft->desired['cards'])->toBe($cards)
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['products'])->toBe($history['products'])
        ->and($this->draft->snapshot['collections']['gid://shopify/Collection/2']['products'])->toBe($history['products'])
        ->and(ShopifyCollection::where('shopify_id', 'gid://shopify/Collection/2')->exists())->toBeTrue()
        ->and(ShopYourVibeCollectionMapping::count())->toBe(2)
        ->and($this->fake->mutations())->toBe([]);
});

it('F2 shows the missing status and excludes the target from assignment options', function () {
    f2DeleteAndRefresh($this);
    $user = User::firstOrFail();
    Role::findOrCreate(RolesEnum::Admin->value);
    $user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($user);

    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid)->call('setActiveTab', 'vibes')
        ->assertSee('Collection no longer exists in Shopify')
        ->assertSeeHtml('data-collection-unavailable="true"');
    expect(collect($page->get('vibeMappings'))->pluck('shopify_collection_id'))->not->toContain('gid://shopify/Collection/2');
    $page->call('editCard', $this->card['key'])->assertSet('activeCard', null)->assertSee('Editing and Shopify writes are disabled');
});

it('F2 rejects assignment sorting and field changes for inactive collections without Shopify traffic', function () {
    f2DeleteAndRefresh($this);
    $this->fake->calls = [];
    $gid = 'gid://shopify/Collection/2';
    expect(fn () => $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', ['collection_gid' => $gid, 'ids' => []]))
        ->toThrow(RuntimeException::class, 'Collection no longer exists');
    expect(fn () => $this->workflow->loadProducts($this->draft->id, $this->draft->revision, $this->card['key']))
        ->toThrow(RuntimeException::class, 'Collection no longer exists');
    expect(fn () => $this->workflow->edit($this->draft->id, $this->draft->revision, 'edit_card', ['key' => $this->card['key']]))
        ->toThrow(RuntimeException::class, 'Collection no longer exists');
    expect(fn () => app(ShopYourVibeAssignmentService::class)->assign($this->draft->collection_gid, 'gid://shopify/Product/101', [$gid]))
        ->toThrow(RuntimeException::class, 'not configured');
    expect($this->fake->calls)->toBe([]);
});

it('F2 permits verified parent sorting when an unchanged missing collection is retained as history', function () {
    f2DeleteAndRefresh($this);
    $gid = $this->draft->collection_gid;
    $ids = array_reverse(array_column($this->draft->desired['collections'][$gid]['products'], 'id'));
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', ['collection_gid' => $gid, 'ids' => $ids]);
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $this->workflow->push($this->draft->id);
    $this->draft->refresh();

    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and(array_column($this->fake->collections[$gid]['products']['nodes'], 'id'))->toBe($ids)
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['unavailable'])->toBeTrue()
        ->and(ShopYourVibeCollectionMapping::isConfirmedMissing('gid://shopify/Collection/2'))->toBeTrue();
    foreach ($this->fake->mutations() as [$query, $variables]) {
        expect(json_encode($variables))->not->toContain('gid://shopify/Collection/2');
    }
});

it('F2 keeps pending edits to missing targets even during an explicitly requested discard refresh', function (string $operation) {
    $gid = 'gid://shopify/Collection/2';
    $input = match ($operation) {
        'reorder_products' => ['collection_gid' => $gid, 'ids' => array_reverse(array_column($this->draft->desired['collections'][$gid]['products'], 'id'))],
        'add_products' => ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104']],
        'remove_product' => ['collection_gid' => $gid, 'product_gid' => 'gid://shopify/Product/101'],
        'reorder_cards' => ['keys' => array_reverse(array_column($this->draft->desired['cards'], 'key'))],
        'remove_card' => ['key' => $this->card['key']],
        'edit_card_link' => ['key' => $this->card['key'], 'name' => 'Pending Test temp', 'image' => $this->card['image'], 'link' => 'https://leighavenue.co.za/collections/pastels'],
        default => ['key' => $this->card['key'], 'name' => 'Pending Test temp', 'image' => $this->card['image'], 'link' => $this->card['link']],
    };
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, $operation === 'edit_card_link' ? 'edit_card' : $operation, $input);
    $desired = $this->draft->desired;
    $snapshot = $this->draft->snapshot;

    f2DeleteAndRefresh($this, true);

    expect($this->draft->pending)->toBeTrue()->and($this->draft->status)->toBe('failed')
        ->and($this->draft->desired['collections'][$gid]['products'])->toBe(($desired['collections'][$gid] ?? $snapshot['collections'][$gid])['products'])
        ->and($this->draft->snapshot['collections'][$gid]['products'])->toBe($snapshot['collections'][$gid]['products'])
        ->and($this->draft->last_error)->toContain('Pending changes', 'cannot be pushed')
        ->and($this->mapping->fresh()->is_active)->toBeFalse()
        ->and($this->draft->refreshed_at->toISOString())->toBe($this->confirmedAt);
    if (in_array($operation, ['edit_card', 'edit_card_link', 'reorder_cards', 'remove_card'], true)) {
        expect(collect($this->draft->desired['cards'])->map(fn ($card) => array_diff_key($card, ['unavailable_collection_gid' => true]))->all())->toBe($desired['cards']);
    }
    if ($operation === 'edit_card_link') {
        expect(collect($this->draft->desired['cards'])->firstWhere('key', $this->card['key'])['unavailable_collection_gid'])->toBe($gid);
        expect(fn () => $this->workflow->loadProducts($this->draft->id, $this->draft->revision, $this->card['key']))
            ->toThrow(RuntimeException::class, 'Collection no longer exists');
    }
    expect(fn () => $this->workflow->queue($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'Collection no longer exists');
    // A job queued before refresh must also be blocked, independently of the UI.
    $this->draft->update(['status' => 'pushing']);
    $this->workflow->push($this->draft->id);
    expect($this->draft->fresh()->status)->toBe('failed')->and($this->draft->fresh()->pending)->toBeTrue()
        ->and($this->fake->mutations())->toBe([]);
})->with(['reorder_products', 'add_products', 'remove_product', 'edit_card', 'edit_card_link', 'reorder_cards', 'remove_card']);

it('F2 an empty existing Shopify collection stays active', function () {
    $this->fake->collections['gid://shopify/Collection/2']['products']['nodes'] = [];
    $this->fake->collections['gid://shopify/Collection/2']['productsCount']['count'] = 0;
    $this->draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect($this->mapping->fresh()->is_active)->toBeTrue()
        ->and($this->draft->desired['missing_collection_ids'])->toBe([])
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['products'])->toBe([]);
});

it('F2 retains history without reintroducing a card Shopify removed from the parent layout', function () {
    f2DeleteAndRefresh($this);
    $history = $this->draft->desired['collections']['gid://shopify/Collection/2'];
    $this->fake->references = array_values(array_diff($this->fake->references, [$this->card['id']]));
    $this->draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect($this->draft->pending)->toBeFalse()
        ->and(array_column($this->draft->desired['cards'], 'id'))->not->toContain($this->card['id'])
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['products'])->toBe($history['products'])
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['card_history']['snapshot'])->not->toBeEmpty();
    $gid = $this->draft->collection_gid;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', [
        'collection_gid' => $gid, 'ids' => array_reverse(array_column($this->draft->desired['collections'][$gid]['products'], 'id')),
    ]);
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $this->workflow->push($this->draft->id);
    $this->draft->refresh();
    expect($this->draft->status)->toBe('synced')
        ->and($this->fake->references)->not->toContain($this->card['id'])
        ->and($this->draft->snapshot['collections']['gid://shopify/Collection/2']['products'])->toBe($history['products']);
});

it('F2 resolves deleted linked identity from mapping history when catalogue rows are absent', function () {
    ShopifyCollection::withoutEvents(fn () => ShopifyCollection::where('shopify_id', 'gid://shopify/Collection/2')->delete());
    f2DeleteAndRefresh($this);
    expect($this->mapping->fresh()->is_active)->toBeFalse()
        ->and($this->draft->desired['missing_collection_ids'])->toContain('gid://shopify/Collection/2')
        ->and(collect($this->draft->desired['cards'])->firstWhere('key', $this->card['key'])['collection_gid'])->toBe('gid://shopify/Collection/2');
});

it('F2 never deactivates a mapping on uncertain lookups or interrupted pagination', function (string $failure) {
    $before = $this->draft->desired;
    $this->fake->afterQuery = function (string $query, array $variables, array $result) use ($failure) {
        if (! str_contains($query, 'query VibeProducts') || $variables['id'] !== 'gid://shopify/Collection/2') {
            return $result;
        }
        if (in_array($failure, ['API failure', 'permission denied', 'timeout'], true)) {
            throw new RuntimeException($failure);
        }
        if ($failure === 'malformed root') {
            return [];
        }
        if ($failure === 'malformed products') {
            unset($result['collection']['products']);
        } else {
            if ($failure === 'second page null' && $variables['after'] !== null) {
                return ['collection' => null];
            }
            $result['collection']['products']['pageInfo'] = ['hasNextPage' => true, 'endCursor' => $failure === 'missing cursor' ? null : 'same-cursor'];
        }

        return $result;
    };

    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class);
    expect($this->mapping->fresh()->is_active)->toBeTrue()->and($this->draft->fresh()->desired)->toBe($before)
        ->and(ShopYourVibeCollectionMapping::isConfirmedMissing('gid://shopify/Collection/2'))->toBeFalse();
})->with(['API failure', 'permission denied', 'timeout', 'malformed root', 'malformed products', 'second page null', 'missing cursor', 'repeated cursor']);

it('F2 publishes no deactivation when a different lookup makes the overall refresh fail', function () {
    unset($this->fake->collections['gid://shopify/Collection/2']);
    $this->fake->beforeQuery = function (string $query, array $variables) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/3') {
            throw new RuntimeException('Temporary failure for other collection');
        }
    };
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class);
    expect($this->mapping->fresh()->is_active)->toBeTrue()->and(ShopYourVibeCollectionMapping::confirmedMissingIds())->toBe([]);
});

it('F2 repeated refreshes and stale mapping discovery cannot reactivate confirmed deletion', function () {
    $staleCollection = $this->draft->desired['collections']['gid://shopify/Collection/2'];
    f2DeleteAndRefresh($this);
    $this->fake->collections['gid://shopify/Collection/2'] = [
        'id' => 'gid://shopify/Collection/2', 'title' => 'Stale resurrected title', 'handle' => 'pearl',
    ];
    $this->draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    $service = app(ShopYourVibeAssignmentService::class);
    $service->syncMapping($this->draft->collection_gid, $this->card, $staleCollection);
    $newParent = $service->syncMapping('gid://shopify/Collection/99', $this->card, $staleCollection);

    expect($this->mapping->fresh()->is_active)->toBeFalse()->and($newParent->is_active)->toBeFalse()
        ->and($this->draft->desired['collections']['gid://shopify/Collection/2']['unavailable'])->toBeTrue()
        ->and($service::configuredMappings([$this->draft->collection_gid, 'gid://shopify/Collection/99'])->pluck('shopify_collection_id'))->not->toContain('gid://shopify/Collection/2');
});

it('F2 a stale in-flight refresh cannot overwrite a newer confirmed deletion', function () {
    $workflow = $this->workflow;
    $draftId = $this->draft->id;
    $revision = $this->draft->revision;
    $fake = $this->fake;
    $fake->afterQuery = function (string $query, array $variables, array $result) use ($fake, $workflow, $draftId, $revision) {
        if (str_contains($query, 'query VibeProducts') && $variables['id'] === 'gid://shopify/Collection/2') {
            $fake->afterQuery = null;
            unset($fake->collections['gid://shopify/Collection/2']);
            $workflow->refresh($draftId, $revision);
        }

        return $result;
    };
    expect(fn () => $workflow->refresh($draftId, $revision))->toThrow(RuntimeException::class, 'another tab');
    expect($this->draft->fresh()->desired['collections']['gid://shopify/Collection/2']['unavailable'])->toBeTrue()
        ->and($this->mapping->fresh()->is_active)->toBeFalse();
});

it('F2 does not infer real product membership from a local draft tag', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['title' => 'Local only membership', 'handle' => 'f2-local', 'shopify_id' => 'gid://shopify/Product/104', 'tags' => 'necklaces']));
    $this->draft = $this->workflow->refresh($this->draft->id, $this->draft->revision);
    expect(array_column($this->draft->desired['collections'][$this->draft->collection_gid]['products'], 'id'))->not->toContain('gid://shopify/Product/104')
        ->and(array_column($this->draft->snapshot['collections'][$this->draft->collection_gid]['products'], 'id'))->not->toContain('gid://shopify/Product/104')
        ->and($this->fake->mutations())->toBe([]);
});

it('F2 a refresh job retains missing-target edits until verified retrieval completes', function () {
    $gid = 'gid://shopify/Collection/2';
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'remove_product', ['collection_gid' => $gid, 'product_gid' => 'gid://shopify/Product/101']);
    $user = User::firstOrFail();
    Role::findOrCreate(RolesEnum::Admin->value);
    $user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($user);
    Bus::fake([RefreshShopYourVibeDraft::class]);
    Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid)->call('refreshDraft', true);
    expect($this->draft->fresh()->pending)->toBeTrue();
    unset($this->fake->collections[$gid]);
    (new RefreshShopYourVibeDraft($this->draft->id, $this->draft->revision, true))->handle($this->workflow);
    expect($this->draft->fresh()->pending)->toBeTrue()->and($this->mapping->fresh()->is_active)->toBeFalse();
});

it('F2 background operation readback also deactivates newly discovered missing mappings', function () {
    unset($this->fake->collections['gid://shopify/Collection/2']);
    $this->draft = $this->workflow->queueCreateVibe($this->draft->id, $this->draft->revision, [
        'mode' => 'existing', 'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $this->workflow->runImmediate($this->draft->id, $this->draft->operation['attempt']);
    $this->draft->refresh();
    expect($this->draft->status)->toBe('synced')->and($this->mapping->fresh()->is_active)->toBeFalse()
        ->and($this->draft->desired['missing_collection_ids'])->toContain('gid://shopify/Collection/2');
    foreach ($this->fake->mutations() as [$query, $variables]) {
        expect(json_encode($variables))->not->toContain('gid://shopify/Collection/2');
    }
});


