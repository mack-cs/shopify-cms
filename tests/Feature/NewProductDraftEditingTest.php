<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Models\DropdownOption;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a user acquire refresh and release a draft edit lock', function (): void {
    $user = User::factory()->create();

    $draft = NewProductDraft::create([
        'title' => 'Lock Test Draft',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    expect($draft->acquireEditLock($user->id, 15))->toBeTrue();

    $draft->refresh();

    expect((int) $draft->editing_user_id)->toBe($user->id);
    expect($draft->editing_started_at)->not->toBeNull();
    expect($draft->editing_expires_at)->not->toBeNull();
    expect($draft->isActivelyEditedByAnotherUser($user->id, 15))->toBeFalse();

    expect($draft->refreshEditLock($user->id, 15))->toBeTrue();
    expect($draft->releaseEditLock($user->id))->toBeTrue();

    $draft->refresh();

    expect($draft->editing_user_id)->toBeNull();
    expect($draft->editing_started_at)->toBeNull();
    expect($draft->editing_expires_at)->toBeNull();
});

it('prevents another user from acquiring an active draft edit lock until it expires', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $draft = NewProductDraft::create([
        'title' => 'Locked Draft',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    expect($draft->acquireEditLock($firstUser->id, 15))->toBeTrue();

    $draft->refresh();

    expect($draft->isActivelyEditedByAnotherUser($secondUser->id, 15))->toBeTrue();
    expect($draft->acquireEditLock($secondUser->id, 15))->toBeFalse();

    $draft->forceFill([
        'editing_expires_at' => now()->subMinute(),
    ])->save();

    $draft->refresh();

    expect($draft->acquireEditLock($secondUser->id, 15))->toBeTrue();
});

it('allows draft form data mutation without forcing required product-completeness fields', function (): void {
    $data = NewProductDraftResource::mutateDraftFormData([
        'title' => '',
        'status' => null,
        'published' => null,
        'extra_shopify_fields' => [],
    ]);

    expect($data['title'])->toBe('');
    expect($data['status'] ?? null)->toBeNull();
    expect($data['published'] ?? null)->toBeNull();
    expect($data['siblings_collection_name'])->toBeNull();
    expect($data['payload'])->toBeNull();
});

it('limits sibling collection options to the selected collection', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'sibling-collection-filter.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    $relevant = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/1001',
        'handle' => 'livi-road-bracelets',
        'title' => 'Livi Road Bracelets',
    ]);
    $sibling = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/1003',
        'handle' => 'livi-road-slims-siblings',
        'title' => 'Livi Road Slims Siblings',
    ]);
    ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/1002',
        'handle' => 'pata-pata-bracelets',
        'title' => 'Pata Pata Bracelets',
    ]);

    $method = new ReflectionMethod(NewProductDraftResource::class, 'siblingCollectionOptions');
    $options = $method->invoke(null, 'Livi Road Bracelets', null);
    $templateHeaders = (new ReflectionMethod(NewProductDraftResource::class, 'templateHeaders'))->invoke(null);

    expect($options)->toHaveCount(3)
        ->and($options)->toHaveKey(NewProductDraft::NO_SIBLING_COLLECTION)
        ->and($options[NewProductDraft::NO_SIBLING_COLLECTION])->toBe('No sibling collection')
        ->and($options)->toHaveKey($relevant->shopify_id)
        ->and($options)->toHaveKey($sibling->shopify_id)
        ->and($options[$relevant->shopify_id])->toBe('Livi Road Bracelets (livi-road-bracelets)')
        ->and(collect($templateHeaders)->contains(function (string $header): bool {
            $lines = preg_split('/\R/u', str_replace("\r", '', $header)) ?: [$header];

            return strcasecmp(trim((string) ($lines[0] ?? '')), 'Siblings') === 0;
        }))->toBeFalse();
});

it('replaces stale bundle tags immediately when collection selection changes', function (): void {
    DropdownOption::create([
        'header' => 'Collection',
        'value' => 'Elevated Basics Bracelets',
        'collection_style' => 'Elevated Basics Bracelets',
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => 'elevated-basics-bracelets',
    ]);

    $method = new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection');
    $tags = $method->invoke(
        null,
        [
            'all-products',
            'all-products-collection',
            'bundles',
            'elevated-basics-bundles',
            'elevated-basics-bracelet-stacks-new-in',
            'exclude-from-the-sale',
            'new-arrivals',
            'new-in',
            'newbies',
        ],
        'Elevated Basics Bracelets',
        null,
        'Local Test',
        false
    );

    expect($tags)
        ->toContain('all-products', 'all-products-collections', 'elevated-basics', 'elevated-basics-bracelets', 'elevated-basics-new-in')
        ->not->toContain('all-products-collection', 'bundles', 'elevated-basics-bundles', 'elevated-basics-bracelet-stacks-new-in');
});

it('infers type and category fields from the selected collection', function (): void {
    DropdownOption::create([
        'header' => 'Collection',
        'value' => 'Livi Road Bracelets',
        'collection_style' => 'Livi Road Bracelets',
        'collection_tag_primary' => 'livi-road',
        'collection_tag_secondary' => 'bracelets',
    ]);

    $method = new ReflectionMethod(NewProductDraftResource::class, 'categoryMappingForCollection');
    $mapping = $method->invoke(null, 'Livi Road Bracelets');

    expect($mapping)
        ->not->toBeNull()
        ->and($mapping['type'])->toBe('Bracelets')
        ->and($mapping['category'])->toBe('Apparel & Accessories > Jewelry > Bracelets')
        ->and($mapping['shopify_taxonomy_gid'])->toBe('gid://shopify/TaxonomyCategory/aa-6-3')
        ->and($mapping['google_product_category'])->toBe('191');
});

it('stores an explicit no sibling collection choice separately from an unanswered field', function (): void {
    $draft = NewProductDraft::create([
        'title' => 'No Sibling Product',
        'status' => 'draft',
        'sibling_collection' => 'No sibling collection',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    expect($draft->sibling_collection)->toBe(NewProductDraft::NO_SIBLING_COLLECTION);
});

it('adds the bracelet stack new in tag when the bundle collection is selected before type', function (): void {
    $data = NewProductDraftResource::mutateDraftFormData([
        'title' => 'Local Test',
        'type' => null,
        'tags' => ['livi-road-bundles', 'bundles'],
        'is_on_sale' => false,
        'extra_shopify_fields' => [],
    ]);

    $tags = TagNormalizer::parseTokens($data['tags'] ?? null);

    expect($tags)->toContain(
        'livi-road-bundles',
        'bundles',
        'new-arrivals',
        'new-in',
        'newbies',
        'livi-road-bracelet-stacks-new-in',
    )->and($tags)->not->toContain('livi-road-new-in');
});
