<?php

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\NewProductDraftResource\Pages\EditNewProductDraft;
use App\Models\DropdownOption;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeCollectionMapping;
use App\Models\User;
use App\Services\DraftShopYourVibeSelection;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Cache::forget('shop-your-vibe-parents:'.config('services.shopify.shop'));
    $this->user = User::factory()->create();
    $import = Import::create(['filename' => 'vibes', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->user->id]);
    foreach ([1 => ['Elevated Basics Bracelets', 'elevated-basics-bracelets', 'eb-pearl'],
        2 => ['Elevated Basics Stacks', 'elevated-basics-bundles', 'eb-stack'],
        3 => ['Pata Pata', 'pata-pata', 'pata-red']] as $id => [$title, $handle, $tag]) {
        ShopifyCollection::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Collection/'.$id, 'title' => $title, 'handle' => $handle]);
        DropdownOption::create(['header' => 'Collection', 'value' => $title, 'collection_style' => $title,
            'collection_tag_primary' => $id === 3 ? 'pata-pata' : 'elevated-basics', 'collection_tag_secondary' => $handle]);
        ShopYourVibeCollectionMapping::create(['parent_collection_id' => 'gid://shopify/Collection/'.$id,
            'shopify_collection_id' => 'gid://shopify/Collection/'.($id + 10), 'collection_name' => $tag,
            'collection_handle' => $tag, 'membership_tag' => $tag, 'is_active' => true]);
    }
    $this->selection = app(DraftShopYourVibeSelection::class);
});

afterEach(function () {
    \Illuminate\Support\Facades\Cache::forget('shop-your-vibe-parents:'.config('services.shopify.shop'));
});

it('uses parents discovered by the management page even without an imported collection', function () {
    ShopifyCollection::where('shopify_id', 'gid://shopify/Collection/2')->delete();
    \Illuminate\Support\Facades\Cache::put('shop-your-vibe-parents:'.config('services.shopify.shop'), [
        ['gid' => 'gid://shopify/Collection/2', 'title' => 'Elevated Basics Stacks', 'handle' => 'elevated-basics-stacks'],
    ]);
    expect(array_keys($this->selection->options('Elevated Basics Bundles', null)))
        ->toBe(['gid://shopify/Collection/12']);
});

it('shows unconfigured vibes but prevents assigning them without a membership tag', function () {
    ShopYourVibeCollectionMapping::where('shopify_collection_id', 'gid://shopify/Collection/12')->update(['membership_tag' => null]);
    expect($this->selection->options('Elevated Basics Stacks', null)['gid://shopify/Collection/12'])
        ->toContain('membership tag needs configuration');
    expect($this->selection->unavailable('Elevated Basics Stacks', null))->toBe(['gid://shopify/Collection/12']);
    expect(fn () => $this->selection->apply([], ['gid://shopify/Collection/12'], 'Elevated Basics Stacks', null))
        ->toThrow(ValidationException::class);
});

it('reuses saved Shop Your Vibe configuration for the same collection across parents without a refresh', function () {
    ShopYourVibeCollectionMapping::where('shopify_collection_id', 'gid://shopify/Collection/12')->update(['membership_tag' => null]);
    $configured = ShopYourVibeCollectionMapping::create([
        'parent_collection_id' => '__global__', 'shopify_collection_id' => 'gid://shopify/Collection/12',
        'collection_name' => 'Configured stack vibe', 'collection_handle' => 'configured-stack',
        'membership_tag' => 'saved-stack-tag', 'design_value' => 'tila', 'colour_style_value' => 'solid', 'is_active' => true,
    ]);
    expect($this->selection->unavailable('Elevated Basics Stacks', null))->toBe([]);
    expect($this->selection->apply([], ['gid://shopify/Collection/12'], 'Elevated Basics Stacks', null))
        ->toContain('saved-stack-tag');
    expect($this->selection->mappings('Elevated Basics Stacks', null)->first()->design_value)->toBe('tila');
    $configured->update(['membership_tag' => 'updated-stack-tag']);
    expect($this->selection->apply([], ['gid://shopify/Collection/12'], 'Elevated Basics Stacks', null))
        ->toContain('updated-stack-tag')->not->toContain('saved-stack-tag');
    expect($this->selection->options('Elevated Basics Bracelets', null))->not->toHaveKey('gid://shopify/Collection/12');
});

it('refreshes mappings from Shopify without writing to Shopify', function () {
    $parents = [['gid' => 'gid://shopify/Collection/2', 'title' => 'Elevated Basics Stacks', 'handle' => 'elevated-basics-stacks']];
    $parents[] = ['gid' => 'gid://shopify/Collection/3', 'title' => 'Pata Pata', 'handle' => 'pata-pata'];
    $shopify = $this->mock(\App\Services\ShopYourVibeShopify::class);
    $shopify->shouldReceive('parents')->once()->andReturn($parents);
    $shopify->shouldReceive('parent')->once()->with('gid://shopify/Collection/2')->andReturn([
        'cards' => [['collection_gid' => 'gid://shopify/Collection/12', 'name' => 'Stack vibe']],
    ]);
    $shopify->shouldReceive('collectionMapping')->once()->with('gid://shopify/Collection/12')->andReturn([
        'gid' => 'gid://shopify/Collection/12', 'title' => 'Stack vibe', 'handle' => 'stack-vibe', 'detected_membership_tag' => 'eb-stack',
    ]);
    $this->selection->refresh('Elevated Basics Bundles', 'Elevated Basics Bundles');
    expect(\Illuminate\Support\Facades\Cache::get('shop-your-vibe-parents:'.config('services.shopify.shop')))->toBe($parents);
});

it('filters vibe collections by selected collection and falls back to vendor without a collection', function () {
    expect(array_keys($this->selection->options('Elevated Basics Bracelets', 'Pata Pata')))->toBe(['gid://shopify/Collection/11']);
    expect(array_keys($this->selection->options(null, 'Pata Pata')))->toBe(['gid://shopify/Collection/13']);
    expect($this->selection->options(null, null))->toBe([]);
});

it('adds selected membership tags and removes stale or deselected tags while preserving unrelated tags', function () {
    $tags = $this->selection->apply(['custom-gift', 'eb-stack', 'pata-red', 'new-in'],
        ['gid://shopify/Collection/11'], 'Elevated Basics Bracelets', 'Elevated Basics', ['elevated-basics-bracelets']);
    expect($tags)->toContain('eb-pearl', 'custom-gift', 'new-in', 'elevated-basics-bracelets')
        ->not->toContain('eb-stack', 'pata-red');
    expect($this->selection->selected($tags, 'Elevated Basics Bracelets', 'Elevated Basics'))->toBe(['gid://shopify/Collection/11']);
    expect($this->selection->apply($tags, [], 'Elevated Basics Bracelets', 'Elevated Basics'))->not->toContain('eb-pearl');
    expect(fn () => $this->selection->apply([], ['gid://shopify/Collection/12'], 'Elevated Basics Bracelets', null))->toThrow(ValidationException::class);
});

it('matches draft bundles to storefront stacks for both collection and vendor selection', function () {
    ShopifyCollection::where('shopify_id', 'gid://shopify/Collection/2')->update([
        'title' => 'Elevated Basics Stacks', 'handle' => 'elevated-basics-stacks',
    ]);
    DropdownOption::where('collection_style', 'Elevated Basics Stacks')->update([
        'collection_style' => 'Elevated Basics Bundles',
        'collection_tag_primary' => 'bundles',
        'collection_tag_secondary' => 'elevated-basics-bundles',
    ]);

    expect(array_keys($this->selection->options('Elevated Basics Bundles', 'Elevated Basics Bundles')))
        ->toBe(['gid://shopify/Collection/12']);
    expect(array_keys($this->selection->options(null, 'Elevated Basics Bundles')))
        ->toBe(['gid://shopify/Collection/12']);
    expect($this->selection->selected(['eb-stack'], 'Elevated Basics Bundles', null))
        ->toBe(['gid://shopify/Collection/12']);
    expect($this->selection->apply(['custom-gift'], ['gid://shopify/Collection/12'], 'Elevated Basics Bundles', null))
        ->toContain('eb-stack', 'custom-gift');
    expect(array_keys($this->selection->options('Elevated Basics Bracelets', 'Elevated Basics Bundles')))
        ->toBe(['gid://shopify/Collection/11']);
});

it('clears old stack collection tags even when the title and old type still say stacks', function () {
    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection'))->invoke(null,
        ['bundles', 'elevated-basics-bundles', 'elevated-basics-bracelet-stacks-new-in', 'pata-pata', 'pata-pata-sale', 'custom-gift'],
        'Elevated Basics Bracelets', 'Stacks', 'My Elevated Stacks', false);
    expect($tags)->toContain('elevated-basics', 'elevated-basics-bracelets', 'bracelet', 'custom-gift')
        ->not->toContain('bundles', 'elevated-basics-bundles', 'elevated-basics-bracelet-stacks-new-in', 'pata-pata', 'pata-pata-sale');
    $draft = NewProductDraft::create(['title' => 'Existing item', 'type' => 'Bracelets', 'tags' => 'bundles, elevated-basics-bundles']);
    $data = NewProductDraftResource::mutateDraftFormData(['title' => 'My Elevated Stacks', 'type' => 'Bracelets', 'tags' => $tags, 'extra_shopify_fields' => []], $draft);
    $draft->fill($data)->save();
    expect(TagNormalizer::parseTokens($draft->fresh()->tags))->not->toContain('bundles', 'elevated-basics-bundles', 'elevated-basics-bracelet-stacks-new-in');
});

it('updates the draft form selector and membership tags immediately when the collection changes', function () {
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $draft = NewProductDraft::create(['title' => 'Elevated Stacks', 'type' => 'Bracelets', 'vendor' => 'Elevated Basics',
        'tags' => 'bundles, elevated-basics-bundles, eb-stack, custom-gift']);
    $page = Livewire::test(EditNewProductDraft::class, ['record' => $draft->id]);
    $page->assertSet('data.shop_your_vibe_collections', ['gid://shopify/Collection/12'])
        ->set('data.collection_filter', 'Elevated Basics Bracelets')
        ->assertSet('data.shop_your_vibe_collections', [])
        ->assertSet('data.tags', fn ($tags) => in_array('elevated-basics-bracelets', $tags) && ! in_array('eb-stack', $tags) && ! in_array('bundles', $tags))
        ->set('data.shop_your_vibe_collections', ['gid://shopify/Collection/11'])
        ->assertSet('data.tags', fn ($tags) => in_array('eb-pearl', $tags) && in_array('custom-gift', $tags));
});
