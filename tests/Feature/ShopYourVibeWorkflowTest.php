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
