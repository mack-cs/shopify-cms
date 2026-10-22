<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Exceptions\ShopYourVibeBusyException;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\ApplyShopYourVibeOperation;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->user = User::factory()->create();
    $this->import = Import::create(['filename' => 'vibe-test', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->user->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $this->import->id,
            'shopify_id' => $collection['id'],
            'title' => $collection['title'],
            'handle' => $collection['handle'],
        ]));
    }
    foreach ([101, 102, 103, 104] as $id) {
        Product::withoutEvents(fn () => Product::create([
            'import_id' => $this->import->id,
            'shopify_id' => 'gid://shopify/Product/'.$id,
            'handle' => 'product-'.$id,
            'title' => 'Product '.$id,
        ]));
    }
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->productsBefore = Product::orderBy('id')->get()->toArray();
    $this->inventoryBefore = $this->fake->products;
});

function queueAndRunCreate($test, array $input): ShopYourVibeDraft
{
    $test->draft = $test->workflow->queueCreateVibe($test->draft->id, $test->draft->revision, $input);
    $test->workflow->runImmediate($test->draft->id, $test->draft->operation['attempt']);
    $test->draft->refresh();

    return $test->draft;
}

function queueAndRunDelete($test, string $key, string $mode): ShopYourVibeDraft
{
    $test->draft = $test->workflow->queueDeleteVibe($test->draft->id, $test->draft->revision, ['key' => $key, 'mode' => $mode]);
    $test->workflow->runImmediate($test->draft->id, $test->draft->operation['attempt']);
    $test->draft->refresh();

    return $test->draft;
}

it('creates a vibe on a synchronized parent without a pending draft', function () {
    $revision = $this->draft->revision;
    $this->draft->update(['revision' => $revision + 4]);

    $draft = queueAndRunCreate($this, [
        'mode' => 'new',
        'vibe_name' => 'Gold',
    ]);

    expect($draft->status)->toBe('synced')
        ->and($draft->pending)->toBeFalse()
        ->and($draft->operation)->toBeNull()
        ->and($draft->revision)->toBeGreaterThan($revision + 4)
        ->and(collect($draft->desired['cards'])->pluck('name'))->toContain('GOLD');
    expect(Product::orderBy('id')->pluck('shopify_id')->all())->toBe(array_column($this->productsBefore, 'shopify_id'))
        ->and($this->fake->products)->toBe($this->inventoryBefore);
    foreach ($this->fake->mutations() as [$query]) {
        expect($query)->not->toContain('productDelete');
    }
});

it('creates a vibe with a new shopify collection and publishes it only after the card is attached', function () {
    $draft = queueAndRunCreate($this, ['mode' => 'new', 'vibe_name' => 'Summer Gold']);
    $card = collect($draft->desired['cards'])->firstWhere('name', 'SUMMER GOLD');
    $created = $this->fake->collections[$card['collection_gid']];

    expect($created['handle'])->toBe('necklaces-summer-gold')
        ->and($created['ruleSet']['rules'][0]['condition'])->toBe('necklaces-summer-gold')
        ->and($this->fake->published[$created['id']])->toBe('gid://shopify/Publication/1')
        ->and($this->fake->references)->toContain($card['id'])
        ->and(ShopifyCollection::where('shopify_id', $created['id'])->value('handle'))->toBe('necklaces-summer-gold');
    $mutations = collect($this->fake->mutations())->pluck(0)->values();
    expect($mutations->search(fn ($query) => str_contains($query, 'VibeCollectionCreate')))
        ->toBeLessThan($mutations->search(fn ($query) => str_contains($query, 'VibeCreate')))
        ->and($mutations->search(fn ($query) => str_contains($query, 'VibeReferences')))
        ->toBeLessThan($mutations->search(fn ($query) => str_contains($query, 'VibePublishCollection')));
});

it('creates a vibe from an existing collection without creating or publishing another collection', function () {
    $before = count($this->fake->collections);
    $draft = queueAndRunCreate($this, ['mode' => 'existing', 'collection_gid' => 'gid://shopify/Collection/4']);
    $card = collect($draft->desired['cards'])->firstWhere('name', 'Empty');

    expect($card['collection_gid'])->toBe('gid://shopify/Collection/4')
        ->and($this->fake->collections)->toHaveCount($before)
        ->and($this->fake->published)->toBe([])
        ->and($this->fake->references)->toContain($card['id']);
    foreach ($this->fake->mutations() as [$query]) {
        expect($query)->not->toContain('VibeCollectionCreate', 'VibePublishCollection', 'productDelete');
    }
});

it('removes only the preview reference and keeps the card, collection, and products', function () {
    $draft = queueAndRunDelete($this, 'gid://shopify/Metaobject/11', 'layout');

    expect($draft->status)->toBe('synced')->and($draft->pending)->toBeFalse()
        ->and(collect($draft->desired['cards'])->pluck('id'))->not->toContain('gid://shopify/Metaobject/11')
        ->and($this->fake->cards)->toHaveKey('gid://shopify/Metaobject/11')
        ->and($this->fake->collections)->toHaveKey('gid://shopify/Collection/2')
        ->and($this->fake->references)->not->toContain('gid://shopify/Metaobject/11')
        ->and($this->fake->products)->toBe($this->inventoryBefore);
});

it('deletes a shopify preview card and keeps its collection and products', function () {
    $draft = queueAndRunDelete($this, 'gid://shopify/Metaobject/11', 'permanent');

    expect($draft->status)->toBe('synced')
        ->and($this->fake->cards)->not->toHaveKey('gid://shopify/Metaobject/11')
        ->and($this->fake->collections)->toHaveKey('gid://shopify/Collection/2')
        ->and(Product::orderBy('id')->pluck('shopify_id')->all())->toBe(array_column($this->productsBefore, 'shopify_id'));
    foreach ($this->fake->mutations() as [$query]) {
        expect($query)->not->toContain('productDelete');
    }
});

it('deletes a card and its collection without deleting products', function () {
    $draft = queueAndRunDelete($this, 'gid://shopify/Metaobject/11', 'collection');

    expect($draft->status)->toBe('synced')->and($draft->last_error)->toBeNull()
        ->and($this->fake->collections)->not->toHaveKey('gid://shopify/Collection/2')
        ->and($this->fake->cards)->not->toHaveKey('gid://shopify/Metaobject/11')
        ->and($this->fake->products)->toBe($this->inventoryBefore)
        ->and(ShopifyCollection::where('shopify_id', 'gid://shopify/Collection/2')->exists())->toBeFalse();
    foreach ($this->fake->mutations() as [$query]) {
        expect($query)->not->toContain('productDelete');
    }
});

it('refuses to delete a collection shared by another vibe or used as another parent', function () {
    $state = $this->draft->snapshot;
    $extra = collect($state['cards'])->firstWhere('id', 'gid://shopify/Metaobject/11');
    $extra['key'] = 'gid://shopify/Metaobject/99';
    $extra['id'] = 'gid://shopify/Metaobject/99';
    $state['cards'][] = $extra;
    $state['reference_ids'][] = 'gid://shopify/Metaobject/99';
    $this->draft->update(['snapshot' => $state, 'desired' => $state]);

    expect(fn () => $this->workflow->queueDeleteVibe($this->draft->id, $this->draft->revision, [
        'key' => 'gid://shopify/Metaobject/11',
        'mode' => 'collection',
    ]))->toThrow(RuntimeException::class, 'Another vibe in this layout');

    $this->draft->refresh();
    ShopYourVibeDraft::create([
        'collection_gid' => 'gid://shopify/Collection/2',
        'snapshot' => $this->draft->snapshot,
        'desired' => $this->draft->desired,
    ]);
    $state['cards'] = array_values(array_filter($state['cards'], fn ($card) => $card['id'] !== 'gid://shopify/Metaobject/99'));
    $state['reference_ids'] = array_values(array_filter($state['reference_ids'], fn ($id) => $id !== 'gid://shopify/Metaobject/99'));
    $this->draft->update(['snapshot' => $state, 'desired' => $state, 'revision' => $this->draft->revision + 1]);

    expect(fn () => $this->workflow->queueDeleteVibe($this->draft->id, $this->draft->fresh()->revision, [
        'key' => 'gid://shopify/Metaobject/11',
        'mode' => 'collection',
    ]))->toThrow(RuntimeException::class, 'another workflow');
    expect($this->fake->collections)->toHaveKey('gid://shopify/Collection/2')
        ->and($this->fake->products)->toBe($this->inventoryBefore);
});

it('accepts a stale frontend revision when the draft is already synced', function () {
    $stale = $this->draft->revision;
    $this->draft->update(['revision' => $stale + 3, 'status' => 'synced', 'pending' => false]);

    $draft = $this->workflow->edit($this->draft->id, $stale, 'reorder_cards', [
        'keys' => array_reverse(array_column($this->draft->fresh()->desired['cards'], 'key')),
    ]);

    expect($draft->pending)->toBeTrue()->and($draft->revision)->toBe($stale + 4);
});

it('still rejects a stale revision when another session has unsaved edits', function () {
    $stale = $this->draft->revision;
    $this->workflow->edit($this->draft->id, $stale, 'reorder_cards', [
        'keys' => array_reverse(array_column($this->draft->desired['cards'], 'key')),
    ]);

    expect(fn () => $this->workflow->edit($this->draft->id, $stale, 'remove_card', [
        'key' => 'gid://shopify/Metaobject/11',
    ]))->toThrow(RuntimeException::class, 'another tab');
});

it('retries a partially created vibe without duplicating the collection or card', function () {
    $this->fake->fail = 'mutation VibePublishCollection';
    $draft = queueAndRunCreate($this, ['mode' => 'new', 'vibe_name' => 'Retry Me']);

    expect($draft->status)->toBe('failed')->and($draft->pending)->toBeFalse()
        ->and($draft->operation['checkpoints']['collection_gid'])->not->toBeNull()
        ->and($draft->operation['checkpoints']['attached'])->toBeTrue()
        ->and($draft->operation['checkpoints']['published'])->toBeFalse()
        ->and($this->fake->collections)->toHaveCount(5)
        ->and($this->fake->cards)->toHaveCount(3);

    $this->fake->fail = null;
    $draft = $this->workflow->resumeOperation($draft->id);
    $this->workflow->runImmediate($draft->id, $draft->operation['attempt']);
    $draft->refresh();

    expect($draft->status)->toBe('synced')->and($draft->operation)->toBeNull()
        ->and($this->fake->collections)->toHaveCount(5)
        ->and($this->fake->cards)->toHaveCount(3)
        ->and($this->fake->published)->toHaveCount(1);
});

it('does not create a second card when the first shopify response was lost', function () {
    $draft = $this->workflow->queueCreateVibe($this->draft->id, $this->draft->revision, [
        'mode' => 'existing',
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $handle = $draft->operation['payload']['card_handle'];
    $this->fake->cards['gid://shopify/Metaobject/555'] = [
        'id' => 'gid://shopify/Metaobject/555',
        'type' => 'shop_your_vibe_card_preview',
        'handle' => $handle,
        'fields' => [
            ['key' => 'name', 'value' => 'Empty', 'reference' => null],
            ['key' => 'image', 'value' => '', 'reference' => null],
            ['key' => 'link', 'value' => 'https://leighavenue.co.za/collections/empty', 'reference' => null],
        ],
        'capabilities' => ['publishable' => ['status' => 'ACTIVE']],
    ];

    $this->workflow->runImmediate($draft->id, $draft->operation['attempt']);
    $draft->refresh();

    expect($draft->status)->toBe('synced')
        ->and($this->fake->cards)->toHaveCount(3)
        ->and(collect($draft->desired['cards'])->pluck('id'))->toContain('gid://shopify/Metaobject/555');
});

it('records a shopify api failure and keeps the uploaded image for retry', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    Http::fake(['*' => Http::response('', 201)]);
    $path = 'shop-your-vibe/retry-image.jpg';
    \Illuminate\Support\Facades\Storage::disk('public')->put($path, 'image');
    $this->fake->fail = 'mutation VibeCollectionCreate';

    $draft = queueAndRunCreate($this, [
        'mode' => 'new',
        'vibe_name' => 'Image Kept',
        'image_path' => $path,
        'image_url' => 'https://leighavenue.co.za/storage/'.$path,
    ]);

    expect($draft->status)->toBe('failed')
        ->and($draft->last_error)->toContain('VibeCollectionCreate')
        ->and($draft->operation['payload']['image_path'])->toBe($path)
        ->and(\Illuminate\Support\Facades\Storage::disk('public')->exists($path))->toBeTrue()
        ->and($this->fake->collections)->toHaveCount(4)
        ->and($this->fake->published)->toBe([]);
});

it('retries a failed immediate operation from its checkpoint after the job is resumed', function () {
    $this->fake->fail = 'mutation VibeCreate';
    $draft = queueAndRunCreate($this, ['mode' => 'existing', 'collection_gid' => 'gid://shopify/Collection/4']);
    expect($draft->status)->toBe('failed')->and($this->fake->references)->toHaveCount(2);

    $this->fake->fail = null;
    $resumed = $this->workflow->resumeOperation($draft->id);
    (new ApplyShopYourVibeOperation($resumed->id, $resumed->operation['attempt']))
        ->handle(app(ShopYourVibeWorkflow::class));
    $resumed->refresh();

    expect($resumed->status)->toBe('synced')->and($resumed->pending)->toBeFalse()
        ->and($this->fake->references)->toHaveCount(3);
});

it('does not let an immediate vibe replace pending batch changes', function () {
    $reordered = array_reverse(array_column($this->draft->desired['cards'], 'key'));
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => $reordered]);

    expect(fn () => $this->workflow->queueCreateVibe($this->draft->id, $this->draft->revision, [
        'mode' => 'existing',
        'collection_gid' => 'gid://shopify/Collection/4',
    ]))->toThrow(RuntimeException::class, 'pending changes');

    expect($this->draft->fresh()->pending)->toBeTrue()
        ->and(array_column($this->draft->fresh()->desired['cards'], 'key'))->toBe($reordered)
        ->and($this->fake->mutations())->toBe([]);
});

it('shows the created vibe after the queued operation completes', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    Bus::fake([ApplyShopYourVibeOperation::class]);

    $page = Livewire::test(ShopYourVibe::class)
        ->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)
        ->set('selectedCollectionGid', 'gid://shopify/Collection/4')
        ->call('confirmCollectionSelection')
        ->assertSee('Add Vibe to Shopify?')
        ->call('confirmAddVibe')
        ->assertSee('Creating Vibe')
        ->assertDontSee('Up to date with Shopify')
        ->call('back')
        ->assertSee('Creating Vibe')
        ->call('manage', 'gid://shopify/Collection/1');

    $draft = ShopYourVibeDraft::where('collection_gid', 'gid://shopify/Collection/1')->firstOrFail();
    expect($draft->status)->toBe('creating')->and($draft->pending)->toBeFalse();
    Bus::assertDispatched(ApplyShopYourVibeOperation::class);

    app(ShopYourVibeWorkflow::class)->runImmediate($draft->id, $draft->operation['attempt']);
    $page->call('setActiveTab', 'vibes')->call('pollPush')->assertDontSee('syv-indeterminate')->assertSee('Empty');
    expect($draft->fresh()->status)->toBe('synced')
        ->and(collect($draft->fresh()->desired['cards'])->pluck('name'))->toContain('Empty');
});

it('ignores shopify link normalization and local image paths when pushing a new vibe', function () {
    $state = $this->draft->desired;
    foreach ($state['cards'] as &$card) {
        $card['image_path'] = 'shop-your-vibe/local-only.jpg';
        $card['link'] = rtrim($card['link'], '/');
    }
    unset($card);
    $state['collections'] = $this->draft->desired['collections'];
    $this->draft->update(['snapshot' => $state, 'desired' => $state, 'pending' => false, 'status' => 'synced']);
    $this->fake->cards['gid://shopify/Metaobject/11']['fields'][2]['value'] = 'https://LeighAvenue.co.za/collections/pearl/';
    $this->draft->refresh();

    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'add_card', [
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $this->workflow->push($this->draft->id);
    $this->draft->refresh();

    expect($this->draft->status)->toBe('synced')->and($this->draft->last_error)->toBeNull()
        ->and($this->draft->desired['cards'])->toHaveCount(3);
});

it('names the preview card and field when shopify really changed it', function () {
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'add_card', [
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $this->fake->cards['gid://shopify/Metaobject/11']['fields'][0]['value'] = 'Changed in Shopify';
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $this->workflow->push($this->draft->id);
    $this->draft->refresh();

    expect($this->draft->status)->toBe('failed')
        ->and($this->draft->last_error)->toContain('Card "Pearl"')
        ->and($this->draft->last_error)->toContain('differs in: name')
        ->and($this->fake->mutations())->toBe([]);
});

function plantAnotherUsersVibe($test, string $id = 'gid://shopify/Metaobject/77'): void
{
    $test->fake->cards[$id] = [
        'id' => $id,
        'type' => 'shop_your_vibe_card_preview',
        'handle' => 'other-user',
        'fields' => [
            ['key' => 'name', 'value' => 'Other User', 'reference' => null],
            ['key' => 'image', 'value' => 'gid://shopify/MediaImage/1', 'reference' => ['image' => ['url' => 'https://cdn.shopify.com/image.jpg']]],
            ['key' => 'link', 'value' => 'https://leighavenue.co.za/collections/empty', 'reference' => null],
        ],
    ];
    $test->fake->references[] = $id;
}

it('adds a vibe from a stale page without dropping a vibe created in Shopify', function () {
    plantAnotherUsersVibe($this);
    $stale = $this->draft->revision;
    $this->draft->update(['revision' => $stale + 4, 'status' => 'synced', 'pending' => false]);
    expect($this->draft->fresh()->snapshot['reference_ids'])->not->toContain('gid://shopify/Metaobject/77');

    $draft = $this->workflow->queueCreateVibe($this->draft->id, $stale, [
        'mode' => 'existing',
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $this->workflow->runImmediate($draft->id, $draft->operation['attempt']);
    $draft->refresh();

    expect($draft->status)->toBe('synced')->and($draft->last_error)->toBeNull()
        ->and($this->fake->references)->toBe([
            'gid://shopify/Metaobject/12',
            'gid://shopify/Metaobject/11',
            'gid://shopify/Metaobject/77',
            $this->fake->references[3],
        ])
        ->and($draft->desired['reference_ids'])->toBe($this->fake->references)
        ->and(collect($draft->desired['cards'])->pluck('id'))->toContain('gid://shopify/Metaobject/77');
});

it('deletes only the selected vibe when Shopify has a newer vibe the page has not loaded', function () {
    plantAnotherUsersVibe($this);
    $stale = $this->draft->revision;
    $this->draft->update(['revision' => $stale + 4, 'status' => 'synced', 'pending' => false]);

    $draft = $this->workflow->queueDeleteVibe($this->draft->id, $stale, [
        'key' => 'gid://shopify/Metaobject/11',
        'mode' => 'permanent',
    ]);
    $this->workflow->runImmediate($draft->id, $draft->operation['attempt']);
    $draft->refresh();

    expect($draft->status)->toBe('synced')->and($draft->last_error)->toBeNull()
        ->and($this->fake->references)->toBe([
            'gid://shopify/Metaobject/12',
            'gid://shopify/Metaobject/77',
        ])
        ->and($this->fake->cards)->not->toHaveKey('gid://shopify/Metaobject/11')
        ->and($this->fake->cards)->toHaveKey('gid://shopify/Metaobject/77')
        ->and($this->fake->collections)->toHaveKey('gid://shopify/Collection/2')
        ->and($draft->desired['reference_ids'])->toBe($this->fake->references);
});

it('does not write a stale snapshot back when Shopify removed a vibe', function () {
    $this->fake->references = ['gid://shopify/Metaobject/12'];
    $stale = $this->draft->revision;
    $this->draft->update(['revision' => $stale + 2, 'status' => 'synced', 'pending' => false]);

    $draft = $this->workflow->queueCreateVibe($this->draft->id, $stale, [
        'mode' => 'existing',
        'collection_gid' => 'gid://shopify/Collection/4',
    ]);
    $this->workflow->runImmediate($draft->id, $draft->operation['attempt']);
    $draft->refresh();

    expect($draft->status)->toBe('failed')
        ->and($draft->last_error)->toContain('changed in Shopify')
        ->and($this->fake->references)->toBe(['gid://shopify/Metaobject/12'])
        ->and($draft->snapshot['reference_ids'])->toContain('gid://shopify/Metaobject/11');
    foreach ($this->fake->mutations() as [$query]) {
        expect($query)->not->toContain('mutation VibeReferences');
    }
});

it('does not fail a draft when another collection already holds the operation lock', function () {
    \Illuminate\Support\Facades\Cache::put('shop-your-vibe-op:'.$this->draft->collection_gid, 'other-worker', 180);
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', [
        'keys' => array_reverse(array_column($this->draft->desired['cards'], 'key')),
    ]);
    $this->draft = $this->workflow->queue($this->draft->id, $this->draft->revision);

    expect(fn () => $this->workflow->push($this->draft->id))->toThrow(ShopYourVibeBusyException::class);
    expect($this->draft->fresh()->status)->toBe('pushing')->and($this->draft->fresh()->pending)->toBeTrue();
});
