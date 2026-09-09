<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\PushShopYourVibe;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
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
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create(['import_id' => $this->import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle']]));
    }
    foreach ([101, 102, 103, 104] as $id) {
        Product::withoutEvents(fn () => Product::create(['import_id' => $this->import->id, 'shopify_id' => 'gid://shopify/Product/'.$id, 'handle' => 'product-'.$id, 'title' => 'Product '.$id]));
    }
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
});

function vibeEdit($test, string $operation, array $input): ShopYourVibeDraft
{
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, $operation, $input);

    return $test->draft;
}

function vibeLoadProducts($test): void
{
    $test->draft = $test->workflow->loadProducts($test->draft->id, $test->draft->revision, 'gid://shopify/Metaobject/11');
}

function vibePush($test): void
{
    $test->draft = $test->workflow->queue($test->draft->id, $test->draft->revision);
    $test->workflow->push($test->draft->id);
    $test->draft->refresh();
}

it('loads only preview data and preserves the exact Shopify reference order', function () {
    expect(array_column($this->draft->desired['cards'], 'id'))->toBe($this->fake->references)
        ->and($this->draft->pending)->toBeFalse()
        ->and($this->draft->desired['cards'][0]['collection_gid'])->toBe('gid://shopify/Collection/3')
        ->and($this->fake->mutations())->toBe([]);
    foreach ($this->fake->calls as [$query, $variables]) {
        expect($query.json_encode($variables))->not->toMatch('/\bshop_your_vibe\b/');
    }
});

it('distinguishes configured from unconfigured parent collections', function () {
    $parents = app(ShopYourVibeShopify::class)->parents();
    expect(array_column($parents, 'gid'))->toBe(['gid://shopify/Collection/1'])
        ->and($parents[0]['count'])->toBe(2);
    $empty = $this->workflow->open('gid://shopify/Collection/4');
    expect($empty->desired['cards'])->toBe([])->and($empty->pending)->toBeFalse();
});

it('stages new vibes locally with stable handles and no remote objects', function () {
    $this->fake->calls = [];
    vibeEdit($this, 'add_card', ['collection_gid' => 'gid://shopify/Collection/2']);
    $card = $this->draft->desired['cards'][2];
    expect($card['id'])->toBeNull()->and($card['handle'])->toStartWith('cms-vibe-')
        ->and($this->draft->pending)->toBeTrue()->and($this->fake->calls)->toBe([]);
});

it('stages removal as detachment without deleting the metaobject', function () {
    vibeEdit($this, 'remove_card', ['key' => 'gid://shopify/Metaobject/11']);
    expect($this->draft->pending)->toBeTrue()->and($this->fake->mutations())->toBe([]);
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->fake->references)->toBe(['gid://shopify/Metaobject/12'])
        ->and($this->fake->cards)->toHaveKey('gid://shopify/Metaobject/11');
});

it('persists drag order without any Shopify calls until an explicit push', function () {
    $this->fake->calls = [];
    vibeEdit($this, 'reorder_cards', ['keys' => ['gid://shopify/Metaobject/11', 'gid://shopify/Metaobject/12']]);
    expect($this->draft->fresh()->pending)->toBeTrue()->and($this->fake->calls)->toBe([]);
    vibePush($this);
    expect($this->draft->status)->toBe('synced')->and($this->draft->pending)->toBeFalse()
        ->and($this->fake->references)->toBe(['gid://shopify/Metaobject/11', 'gid://shopify/Metaobject/12']);
});

it('edits real card fields locally and clears pending only after confirmation', function () {
    $this->fake->calls = [];
    vibeEdit($this, 'edit_card', ['key' => 'gid://shopify/Metaobject/11', 'name' => 'New Pearl', 'image' => 'gid://shopify/MediaImage/9',
        'image_url' => 'https://cdn.shopify.com/new.jpg', 'link' => 'https://leighavenue.co.za/collections/pastels']);
    expect($this->draft->pending)->toBeTrue()->and($this->fake->calls)->toBe([]);
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->draft->desired['cards'][1]['name'])->toBe('New Pearl');
});

it('keeps failed pushes pending and preserves successful metaobject creation for retry', function () {
    vibeEdit($this, 'add_card', ['collection_gid' => 'gid://shopify/Collection/2']);
    $this->fake->fail = 'mutation VibeReferences';
    vibePush($this);
    expect($this->draft->pending)->toBeTrue()->and($this->draft->status)->toBe('failed')
        ->and($this->draft->desired['cards'][2]['id'])->not->toBeNull()->and($this->draft->progress)->not->toBeEmpty();
    $this->fake->fail = null;
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->fake->cards)->toHaveCount(3);
    expect(collect($this->fake->mutations())->filter(fn ($call) => str_contains($call[0], 'VibeCreate')))->toHaveCount(1);
});

it('detects manual sorting off and rejects drag without implicitly enabling it', function () {
    $this->fake->collections['gid://shopify/Collection/2']['sortOrder'] = 'BEST_SELLING';
    vibeLoadProducts($this);
    $this->fake->calls = [];
    expect($this->draft->desired['collections']['gid://shopify/Collection/2']['sort'])->toBe('BEST_SELLING');
    expect(fn () => vibeEdit($this, 'reorder_products', ['collection_gid' => 'gid://shopify/Collection/2', 'ids' => ['gid://shopify/Product/103', 'gid://shopify/Product/102', 'gid://shopify/Product/101']]))
        ->toThrow(RuntimeException::class, 'Explicitly enable');
    expect($this->fake->calls)->toBe([])->and($this->draft->fresh()->pending)->toBeFalse();
});

it('requires explicit confirmation to enable manual sorting and defers it until push', function () {
    $gid = 'gid://shopify/Collection/2';
    $this->fake->collections[$gid]['sortOrder'] = 'BEST_SELLING';
    vibeLoadProducts($this);
    expect(fn () => vibeEdit($this, 'enable_manual', ['collection_gid' => $gid]))->toThrow(RuntimeException::class, 'explicit confirmation');
    $this->fake->calls = [];
    vibeEdit($this, 'enable_manual', ['collection_gid' => $gid, 'confirmed' => true]);
    vibeEdit($this, 'reorder_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/103', 'gid://shopify/Product/101', 'gid://shopify/Product/102']]);
    expect($this->fake->calls)->toBe([])->and($this->fake->collections[$gid]['sortOrder'])->toBe('BEST_SELLING');
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->fake->collections[$gid]['sortOrder'])->toBe('MANUAL')
        ->and(array_column($this->fake->collections[$gid]['products']['nodes'], 'id'))->toBe(['gid://shopify/Product/103', 'gid://shopify/Product/101', 'gid://shopify/Product/102']);
});

it('disables unsupported manual sorting', function () {
    $this->fake->collections['gid://shopify/Collection/2']['sortOrder'] = 'UNSUPPORTED';
    vibeLoadProducts($this);
    expect($this->draft->desired['collections']['gid://shopify/Collection/2']['manual_supported'])->toBeFalse();
    expect(fn () => vibeEdit($this, 'enable_manual', ['collection_gid' => 'gid://shopify/Collection/2', 'confirmed' => true]))->toThrow(RuntimeException::class);
});

it('allows ordering automated collections but rejects manual membership changes', function () {
    $gid = 'gid://shopify/Collection/2';
    $this->fake->collections[$gid]['ruleSet'] = ['appliedDisjunctively' => false];
    vibeLoadProducts($this);
    expect(fn () => vibeEdit($this, 'add_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104']]))->toThrow(RuntimeException::class, 'rules control');
    expect(fn () => vibeEdit($this, 'remove_product', ['collection_gid' => $gid, 'product_gid' => 'gid://shopify/Product/101']))->toThrow(RuntimeException::class, 'rules control');
    vibeEdit($this, 'reorder_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/103', 'gid://shopify/Product/102', 'gid://shopify/Product/101']]);
    vibePush($this);
    expect($this->draft->pending)->toBeFalse();
});

it('stages product additions removals and ordering, prevents duplicates, and never deletes products', function () {
    $gid = 'gid://shopify/Collection/2';
    vibeLoadProducts($this);
    $this->fake->calls = [];
    vibeEdit($this, 'add_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104', 'gid://shopify/Product/104', 'gid://shopify/Product/101']]);
    vibeEdit($this, 'remove_product', ['collection_gid' => $gid, 'product_gid' => 'gid://shopify/Product/102']);
    vibeEdit($this, 'reorder_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104', 'gid://shopify/Product/103', 'gid://shopify/Product/101']]);
    expect($this->fake->calls)->toBe([])->and($this->draft->pending)->toBeTrue();
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and(Product::count())->toBe(4)
        ->and(array_column($this->fake->collections[$gid]['products']['nodes'], 'id'))->toBe(['gid://shopify/Product/104', 'gid://shopify/Product/103', 'gid://shopify/Product/101']);
    foreach ($this->fake->calls as [$query]) {
        expect($query)->not->toContain('productDelete', 'collectionDelete', 'metaobjectDelete');
    }
});

it('retains failed product ordering after successful membership changes and retries only unfinished work', function () {
    $gid = 'gid://shopify/Collection/2';
    vibeLoadProducts($this);
    vibeEdit($this, 'add_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104']]);
    vibeEdit($this, 'reorder_products', ['collection_gid' => $gid, 'ids' => ['gid://shopify/Product/104', 'gid://shopify/Product/101', 'gid://shopify/Product/102', 'gid://shopify/Product/103']]);
    $this->fake->fail = 'mutation VibeReorderProducts';
    vibePush($this);
    expect($this->draft->pending)->toBeTrue()->and($this->draft->status)->toBe('failed')->and($this->draft->progress)->toContain('Product membership confirmed: Pearl');
    $this->fake->fail = null;
    vibePush($this);
    expect($this->draft->pending)->toBeFalse();
    expect(collect($this->fake->mutations())->filter(fn ($call) => str_contains($call[0], 'VibeAddProducts')))->toHaveCount(1);
});

it('does not claim success for unfinished asynchronous Shopify jobs', function () {
    vibeLoadProducts($this);
    vibeEdit($this, 'reorder_products', ['collection_gid' => 'gid://shopify/Collection/2', 'ids' => ['gid://shopify/Product/103', 'gid://shopify/Product/102', 'gid://shopify/Product/101']]);
    $this->fake->jobDone = false;
    vibePush($this);
    expect($this->draft->pending)->toBeTrue()->and($this->draft->remote_jobs)->not->toBeEmpty();
    $this->fake->jobDone = true;
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->draft->remote_jobs)->toBe([]);
    expect(collect($this->fake->mutations())->filter(fn ($call) => str_contains($call[0], 'VibeReorderProducts')))->toHaveCount(1);
});

it('requires confirmation to refresh pending changes and only reads Shopify during refresh', function () {
    vibeEdit($this, 'remove_card', ['key' => 'gid://shopify/Metaobject/11']);
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class, 'Confirm refresh');
    $this->fake->calls = [];
    $this->draft = $this->workflow->refresh($this->draft->id, $this->draft->revision, true);
    expect($this->draft->pending)->toBeFalse()->and($this->draft->desired['cards'])->toHaveCount(2)
        ->and($this->draft->desired['collections'])->toHaveCount(2)->and($this->fake->mutations())->toBe([]);
});

it('rejects stale revisions and duplicate or incomplete drag lists', function () {
    $revision = $this->draft->revision;
    vibeEdit($this, 'add_card', ['collection_gid' => 'gid://shopify/Collection/2']);
    expect(fn () => $this->workflow->edit($this->draft->id, $revision, 'remove_card', ['key' => 'gid://shopify/Metaobject/11']))->toThrow(RuntimeException::class, 'another tab');
    expect(fn () => vibeEdit($this, 'reorder_cards', ['keys' => ['gid://shopify/Metaobject/11', 'gid://shopify/Metaobject/11']]))->toThrow(RuntimeException::class, 'exactly once');
});

it('stops when Shopify changed since the snapshot without overwriting remote edits', function () {
    vibeEdit($this, 'remove_card', ['key' => 'gid://shopify/Metaobject/11']);
    $this->fake->references = ['gid://shopify/Metaobject/11'];
    vibePush($this);
    expect($this->draft->pending)->toBeTrue()->and($this->draft->last_error)->toContain('changed in Shopify')
        ->and($this->fake->mutations())->toBe([]);
});

it('drops staged product changes when the last referencing card is detached', function () {
    vibeLoadProducts($this);
    vibeEdit($this, 'remove_product', ['collection_gid' => 'gid://shopify/Collection/2', 'product_gid' => 'gid://shopify/Product/101']);
    vibeEdit($this, 'remove_card', ['key' => 'gid://shopify/Metaobject/11']);
    vibePush($this);
    expect($this->fake->collections['gid://shopify/Collection/2']['products']['nodes'])->toHaveCount(3);
});

it('renders the workflow and only dispatches a push after the explicit action', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    Bus::fake([PushShopYourVibe::class]);
    $page = Livewire::test(ShopYourVibe::class)->assertSee('Add Shop Your Vibe');
    // Exercise the rendered button's argument, not just the PHP method in isolation.
    $html = new DOMDocument;
    @$html->loadHTML($page->html());
    $action = null;
    foreach ($html->getElementsByTagName('button') as $button) {
        if (str_starts_with($button->getAttribute('wire:click'), 'manage(')) {
            $action = $button->getAttribute('wire:click');
            break;
        }
    }
    expect($action)->toBe("manage('gid:\\/\\/shopify\\/Collection\\/1')");
    preg_match("/^manage\\('([^']+)'\\)$/", $action, $match);
    $gid = json_decode('"'.$match[1].'"', true, flags: JSON_THROW_ON_ERROR);
    $page->call('manage', $gid)->assertSee('Necklaces')->assertSee('Vibes')
        ->assertSee('Up to date with Shopify')->assertDispatched('vibe-editor-opened');
    expect($page->html())->not->toContain('@js(');
    $this->fake->calls = [];
    $page->call('reorderCards', ['gid://shopify/Metaobject/11', 'gid://shopify/Metaobject/12'])
        ->assertSee('Pending changes')->assertSee('Refresh from Shopify');
    expect($this->fake->calls)->toBe([]);
    Bus::assertNothingDispatched();
    $page->call('reviewPush')->assertSee('Ready to push')->call('pushChanges')->assertSee('Pushing to Shopify');
    Bus::assertDispatched(PushShopYourVibe::class);
});

it('prevents unauthorized users from opening the workflow', function () {
    $this->actingAs($this->user);
    Livewire::test(ShopYourVibe::class)->assertForbidden();
});

it('saves field edits from the rendered page without making Shopify requests', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('editCard', 'gid://shopify/Metaobject/11')->assertSee('Manual sorting: ON')->assertSee('Product 101');
    expect($page->html())->not->toContain('@js(');
    $this->fake->calls = [];
    $page->set('cardForm.name', 'Pearl edit')->call('saveCard')->assertSee('Pending changes');
    expect($this->fake->calls)->toBe([])
        ->and($this->draft->fresh()->desired['cards'][1]['name'])->toBe('Pearl edit');
});

it('consolidates repeated drags into one final Shopify reference mutation', function () {
    $original = $this->fake->references;
    $this->fake->calls = [];
    for ($i = 0; $i < 21; $i++) {
        vibeEdit($this, 'reorder_cards', ['keys' => $i % 2 === 0 ? array_reverse($original) : $original]);
    }
    expect($this->fake->calls)->toBe([]);
    vibePush($this);
    expect($this->fake->mutations())->toHaveCount(1)->and($this->draft->pending)->toBeFalse();
});

it('retains pending asynchronous state when an edit could otherwise clear it', function () {
    $this->draft->update(['pending' => true, 'status' => 'failed', 'remote_jobs' => ['gid://shopify/Collection/2' => 'gid://shopify/Job/order']]);
    expect(fn () => vibeEdit($this, 'reorder_cards', ['keys' => $this->fake->references]))->toThrow(RuntimeException::class, 'still processing');
    expect($this->draft->fresh()->pending)->toBeTrue();
});

it('does not push hidden product edits after a card link moves to another collection', function () {
    vibeLoadProducts($this);
    vibeEdit($this, 'remove_product', ['collection_gid' => 'gid://shopify/Collection/2', 'product_gid' => 'gid://shopify/Product/101']);
    vibeEdit($this, 'edit_card', ['key' => 'gid://shopify/Metaobject/11', 'name' => 'New collection', 'image' => 'gid://shopify/MediaImage/1',
        'link' => 'https://leighavenue.co.za/collections/empty']);
    vibePush($this);
    expect($this->draft->pending)->toBeFalse()->and($this->fake->collections['gid://shopify/Collection/2']['products']['nodes'])->toHaveCount(3);
});

it('rejects a stale collection link before loading or mutating the wrong products', function () {
    $this->fake->collections['gid://shopify/Collection/2']['handle'] = 'renamed-pearl';
    expect(fn () => vibeLoadProducts($this))->toThrow(RuntimeException::class, 'collection link changed');
    expect($this->fake->mutations())->toBe([]);
});

it('normalizes relative collection links into valid Shopify URL field values locally', function () {
    $this->fake->calls = [];
    vibeEdit($this, 'edit_card', ['key' => 'gid://shopify/Metaobject/11', 'name' => 'Pearl', 'image' => '', 'link' => '/collections/pearl']);
    expect($this->draft->desired['cards'][1]['link'])->toBe('https://leighavenue.co.za/collections/pearl')
        ->and($this->fake->calls)->toBe([]);
});

it('renders Filament sortable grids and saves the final drop position for cards and products', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    vibeEdit($this, 'add_card', ['collection_gid' => 'gid://shopify/Collection/4']);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1');
    $html = new DOMDocument;
    @$html->loadHTML($page->html());
    $xpath = new DOMXPath($html);
    $grid = $xpath->query('//*[@data-order-grid]')->item(0);
    expect($grid->hasAttribute('x-sortable'))->toBeTrue()
        ->and($grid->getAttribute('x-on:end.stop'))->toContain("saveOrder($".'el, \'cards\')');
    $keys = array_map(fn ($item) => $item->getAttribute('x-sortable-item'), iterator_to_array($xpath->query('./*[@x-sortable-item]', $grid)));
    $this->fake->calls = [];
    // Sortable supplies the resulting DOM order, including moving the first card to the very end.
    $keys[] = array_shift($keys);
    $page->call('reorderCards', $keys)->assertSee('Pending changes');
    expect(array_column($this->draft->fresh()->desired['cards'], 'key'))->toBe($keys)
        ->and($this->fake->calls)->toBe([]);
    array_unshift($keys, array_pop($keys));
    $page->call('reorderCards', $keys);
    expect(array_column($this->draft->fresh()->desired['cards'], 'key'))->toBe($keys);
    $page->call('editCard', 'gid://shopify/Metaobject/11');
    @$html->loadHTML($page->html());
    $xpath = new DOMXPath($html);
    $productGrid = $xpath->query('//*[@data-order-grid]')->item(1);
    expect($productGrid->hasAttribute('x-sortable'))->toBeTrue()
        ->and($productGrid->getAttribute('x-on:end.stop'))->toContain("'products'")
        ->and($page->html())->not->toContain('x-on:drop=', 'x-on:dragstart=');
    $this->fake->calls = [];
    $ids = ['gid://shopify/Product/102', 'gid://shopify/Product/103', 'gid://shopify/Product/101'];
    $page->call('reorderProducts', 'gid://shopify/Collection/2', $ids);
    expect(array_column($this->draft->fresh()->desired['collections']['gid://shopify/Collection/2']['products'], 'id'))->toBe($ids)
        ->and($this->fake->calls)->toBe([]);
});

it('only mounts the product sortable grid after manual sorting is explicitly requested', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $this->fake->collections['gid://shopify/Collection/2']['sortOrder'] = 'BEST_SELLING';
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')->call('editCard', 'gid://shopify/Metaobject/11');
    $html = new DOMDocument;
    @$html->loadHTML($page->html());
    $grid = (new DOMXPath($html))->query('//*[@data-order-grid]')->item(1);
    expect($grid->hasAttribute('x-sortable'))->toBeFalse();
    $oldKey = $grid->getAttribute('wire:key');
    $this->fake->calls = [];
    $page->call('enableManual', 'gid://shopify/Collection/2');
    @$html->loadHTML($page->html());
    $grid = (new DOMXPath($html))->query('//*[@data-order-grid]')->item(1);
    expect($grid->hasAttribute('x-sortable'))->toBeTrue()
        ->and($grid->getAttribute('wire:key'))->not->toBe($oldKey)
        ->and($this->fake->calls)->toBe([]);
});

it('opens a searchable collection modal and closes it after selecting a parent', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('openCollectionPicker')
        ->assertSet('addingParent', true)->assertSet('addingCard', false)
        ->assertDispatched('open-modal', id: 'shop-your-vibe-collections');
    $html = new DOMDocument;
    @$html->loadHTML($page->html());
    $xpath = new DOMXPath($html);
    $modal = $xpath->query('//*[@data-fi-modal-id="shop-your-vibe-collections"]')->item(0);
    expect($modal->getAttribute('role'))->toBe('dialog');
    $results = $page->instance()->getFormSelectSearchResults('selectedCollectionGid', 'Empty');
    expect(array_column($results, 'value'))->toBe(['gid://shopify/Collection/4']);
    expect($page->instance()->getFormSelectSearchResults('selectedCollectionGid', 'no-such-collection'))->toBe([]);
    $page->set('selectedCollectionGid', 'gid://shopify/Collection/4')->call('confirmCollectionSelection')
        ->assertSet('addingParent', false)->assertDispatched('close-modal', id: 'shop-your-vibe-collections')
        ->assertSee('Up to date with Shopify');
    expect($this->fake->mutations())->toBe([]);
});

it('uses the modal for adding a vibe and retains it on a failed selection', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)->assertSet('addingCard', true)
        ->assertDispatched('open-modal', id: 'shop-your-vibe-collections');
    $this->fake->calls = [];
    $page->call('addCard', 'invalid')->assertSet('addingCard', true)->assertNotDispatched('close-modal');
    $page->set('selectedCollectionGid', 'gid://shopify/Collection/4')->call('confirmCollectionSelection')->assertSet('addingCard', false)
        ->assertDispatched('close-modal', id: 'shop-your-vibe-collections')->assertSee('Pending changes');
    expect($this->fake->calls)->toBe([])->and($this->draft->fresh()->desired['cards'])->toHaveCount(3);
    $page->call('openCollectionPicker', true)->set('selectedCollectionGid', 'gid://shopify/Collection/2')->call('closeCollectionPicker')
        ->assertSet('addingCard', false)->assertSet('addingParent', false)->assertSet('selectedCollectionGid', '');
});

it('explains preview scope and why automated collection membership cannot be removed', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $this->fake->collections['gid://shopify/Collection/2']['ruleSet'] = ['appliedDisjunctively' => false];
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('editCard', 'gid://shopify/Metaobject/11')->assertSee('cannot remove them individually')
        ->assertSee('Manual sorting does not change these membership rules.');
    $page->call('reorderCards', array_reverse($this->fake->references))->call('reviewPush')
        ->assertSee('update the preview only')->assertSee('can affect the live storefront');
});

it('keeps repeated collection imports from hiding other modal dropdown options', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    for ($i = 0; $i < 65; $i++) {
        $import = $this->import->replicate();
        $import->save();
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Collection/4', 'title' => 'Empty', 'handle' => 'empty',
        ]));
    }
    $page = Livewire::test(ShopYourVibe::class)->call('openCollectionPicker');
    $results = $page->instance()->getFormSelectSearchResults('selectedCollectionGid', '');
    expect($results)->toHaveCount(3)
        ->and(array_column($results, 'value'))->toContain('gid://shopify/Collection/2')
        ->not->toContain('gid://shopify/Collection/1');
});

it('searches modal collections by title and handle beyond the initial results', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    for ($i = 0; $i < 65; $i++) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $this->import->id, 'shopify_id' => 'gid://shopify/Collection/'.(1000 + $i),
            'title' => 'Catalogue '.$i, 'handle' => 'catalogue-'.$i,
        ]));
    }
    ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
        'import_id' => $this->import->id, 'shopify_id' => 'gid://shopify/Collection/2000',
        'title' => 'Zebra Jewellery', 'handle' => 'striped-accessories',
    ]));
    $page = Livewire::test(ShopYourVibe::class)->call('openCollectionPicker');
    $search = fn (string $term) => array_column($page->instance()->getFormSelectSearchResults('selectedCollectionGid', $term), 'value');
    expect($search(''))->toHaveCount(60)->not->toContain('gid://shopify/Collection/2000');
    expect($search('Zebra'))->toBe(['gid://shopify/Collection/2000']);
    expect($search('striped-accessories'))->toBe(['gid://shopify/Collection/2000']);
});

it('creates a new collection draft from the modal and generates an editable handle', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)->set('collectionMode', 'new')
        ->set('newCollection.title', 'Golden Summer')->assertSet('newCollection.handle', 'golden-summer')
        ->set('newCollection.handle', 'summer-gold')->set('newCollection.title', 'Golden Summer Vibes')
        ->assertSet('newCollection.handle', 'summer-gold');
    $this->fake->calls = [];
    $page->call('createCollectionVibe')->assertHasNoErrors()->assertSet('addingCard', false)
        ->assertDispatched('close-modal', id: 'shop-your-vibe-collections')->assertSee('Golden Summer Vibes');
    $card = $this->draft->fresh()->desired['cards'][2];
    $page->call('editCard', $card['key'])->assertSee('New collection')->assertSee('Add Products');
    expect($card['link'])->toBe('https://leighavenue.co.za/collections/summer-gold')
        ->and($this->fake->calls)->toBe([]);
});

it('creates and publishes a new vibe collection with its image and product order only on push', function () {
    $this->fake->calls = [];
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer',
        'image' => 'gid://shopify/MediaImage/1', 'image_url' => 'https://cdn.shopify.com/image.jpg']);
    $card = $this->draft->desired['cards'][2];
    vibeEdit($this, 'add_products', ['collection_gid' => $card['collection_gid'], 'ids' => ['gid://shopify/Product/102', 'gid://shopify/Product/101']]);
    expect($this->fake->calls)->toBe([])->and($this->workflow->summary($this->draft)['New collections to publish'])->toBe(1);
    vibePush($this);
    expect($this->draft->status)->toBe('synced')->and($this->draft->last_error)->toBeNull();
    $card = $this->draft->desired['cards'][2];
    $created = $this->fake->collections[$card['collection_gid']];
    expect($created['handle'])->toBe('golden-summer')->and($created['image']['url'])->toBe('https://cdn.shopify.com/image.jpg')
        ->and(array_column($created['products']['nodes'], 'id'))->toBe(['gid://shopify/Product/102', 'gid://shopify/Product/101'])
        ->and($this->fake->published[$created['id']])->toBe('gid://shopify/Publication/1')
        ->and(ShopifyCollection::where('shopify_id', $created['id'])->value('handle'))->toBe('golden-summer');
});

it('resumes a lost new collection creation response without duplicating the collection', function () {
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer']);
    $this->fake->loseCreateResponse = true;
    vibePush($this);
    expect($this->draft->status)->toBe('failed')->and($this->fake->collections)->toHaveCount(5);
    vibePush($this);
    expect($this->draft->status)->toBe('synced')->and($this->fake->collections)->toHaveCount(5)
        ->and($this->fake->published)->toHaveCount(1);
});

it('keeps a new collection pending when publication fails and retries without recreating it', function () {
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer']);
    $this->fake->fail = 'mutation VibePublishCollection';
    vibePush($this);
    expect($this->draft->status)->toBe('failed')->and($this->draft->pending)->toBeTrue()->and($this->fake->collections)->toHaveCount(5);
    $this->fake->fail = null;
    vibePush($this);
    expect($this->draft->status)->toBe('synced')->and($this->fake->collections)->toHaveCount(5);
});

it('rejects existing handles locally and conflicting handles in Shopify', function () {
    expect(fn () => vibeEdit($this, 'create_collection', ['title' => 'Pearl', 'handle' => 'pearl']))->toThrow(RuntimeException::class, 'handle already exists');
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer']);
    $this->fake->collections['gid://shopify/Collection/4']['handle'] = 'golden-summer';
    $this->fake->calls = [];
    vibePush($this);
    expect($this->draft->status)->toBe('failed')->and($this->draft->last_error)->toContain('already used in Shopify')
        ->and($this->fake->mutations())->toBe([]);
});

it('drops an unpushed new collection when its vibe is removed', function () {
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer']);
    vibeEdit($this, 'remove_card', ['key' => $this->draft->desired['cards'][2]['key']]);
    expect($this->draft->desired['new_collections'])->toBe([])->and($this->draft->pending)->toBeFalse();
});

it('uploads a new collection image to Shopify and reuses it when processing is delayed', function () {
    Storage::fake('public');
    Http::fake(['uploads.shopify.test/*' => Http::response('', 201)]);
    $path = UploadedFile::fake()->image('summer.jpg')->store('shop-your-vibe', 'public');
    vibeEdit($this, 'create_collection', ['title' => 'Golden Summer', 'handle' => 'golden-summer', 'image_path' => $path]);
    $this->fake->fileStatus = 'PROCESSING';
    vibePush($this);
    expect($this->draft->status)->toBe('failed')->and($this->fake->files)->toHaveCount(1)->and($this->fake->collections)->toHaveCount(4);
    $this->fake->fileStatus = 'READY';
    vibePush($this);
    expect($this->draft->status)->toBe('synced')->and($this->fake->files)->toHaveCount(1)
        ->and($this->draft->desired['cards'][2]['image'])->toBe('gid://shopify/MediaImage/500');
    Http::assertSentCount(1);
});

it('accepts an uploaded image in the new collection modal without publishing it', function () {
    Storage::fake('public');
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)->set('collectionMode', 'new')
        ->fillForm(['title' => 'Summer Gold', 'handle' => 'summer-gold', 'image_mode' => 'upload',
            'upload' => UploadedFile::fake()->image('summer.jpg')], 'newCollectionForm');
    $this->fake->calls = [];
    $page->call('createCollectionVibe')->assertHasNoErrors()->assertSet('loadError', null)->assertSet('addingCard', false);
    $creation = array_values($this->draft->fresh()->desired['new_collections'])[0];
    Storage::disk('public')->assertExists($creation['image_path']);
    expect($this->fake->calls)->toBe([]);
});

it('allows choosing an existing Shopify image for a new collection', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)->set('collectionMode', 'new')
        ->fillForm(['title' => 'Summer Gold', 'handle' => 'summer-gold', 'image_mode' => 'existing',
            'image_gid' => 'gid://shopify/MediaImage/1'], 'newCollectionForm');
    $page->call('createCollectionVibe')->assertHasNoErrors()->assertSet('addingCard', false);
    expect($this->draft->fresh()->desired['cards'][2]['image'])->toBe('gid://shopify/MediaImage/1')
        ->and($this->fake->mutations())->toBe([]);
});

it('keeps an incomplete publication pending after further draft edits', function () {
    vibeEdit($this, 'create_collection', ['title' => 'Summer Gold', 'handle' => 'summer-gold']);
    $this->fake->fail = 'mutation VibePublishCollection';
    vibePush($this);
    vibeEdit($this, 'reorder_cards', ['keys' => array_column($this->draft->desired['cards'], 'key')]);
    expect($this->draft->pending)->toBeTrue();
    $this->fake->fail = null;
    vibePush($this);
    expect($this->draft->status)->toBe('synced');
});

it('validates new collection details and requires the chosen upload before saving', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1')
        ->call('openCollectionPicker', true)->set('collectionMode', 'new');
    $page->call('createCollectionVibe')->assertHasErrors(['newCollection.title', 'newCollection.handle'])
        ->assertSet('addingCard', true);
    $page->fillForm(['title' => 'Summer', 'handle' => 'Invalid Handle!', 'image_mode' => 'upload'], 'newCollectionForm')
        ->call('createCollectionVibe')->assertHasErrors(['newCollection.handle', 'newCollection.upload']);
    expect($this->draft->fresh()->desired['cards'])->toHaveCount(2)->and($this->fake->mutations())->toBe([]);
});
