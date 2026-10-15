<?php

use App\Enums\RolesEnum;
use App\Filament\Resources\NewProductDraftResource;
use App\Filament\Resources\NewProductDraftResource\Pages\EditNewProductDraft;
use App\Filament\Resources\ProductResource;
use App\Models\DropdownOption;
use App\Models\NewProductDraft;
use App\Models\PrepopulationRule;
use App\Models\User;
use App\Services\PrepopulationRuleService;
use App\Services\TagNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @return array<int, string>
 */
function stackGenericProductTypeTags(): array
{
    return ['bracelet', 'bracelets', 'necklace', 'necklaces', 'bracelet-stack', 'necklace-stack'];
}

it('omits generic bracelet and necklace tags for every stack collection rule', function (string $name, string $handle, string $type, string $category, string $googleCategory, array $membershipTags): void {
    $rule = PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => $handle,
        'collection_name' => $name,
        'add_tags' => array_merge($membershipTags, [
            'bracelet',
            'bracelets',
            'necklace',
            'necklaces',
            'custom-gift',
            'gold-bracelets',
        ]),
        'remove_tags' => [],
        'auto_vendor' => str_contains($name, 'Elevated Basics') ? 'Elevated Basics' : $name,
        'auto_type' => $type,
        'auto_product_category' => $category,
        'auto_google_product_category' => $googleCategory,
    ]);

    $updates = app(PrepopulationRuleService::class)->applyCollectionRuleReplacingManagedTags(
        $rule,
        ['manual-tag', 'bracelet', 'bracelets', 'necklace', 'necklaces'],
        'Earrings'
    );

    expect($updates['type'])->toBe($type)
        ->and($updates['product_category'])->toBe($category)
        ->and($updates['google_product_category'])->toBe($googleCategory)
        ->and($updates['tags'])->toContain(...array_merge($membershipTags, ['manual-tag', 'custom-gift', 'gold-bracelets']))
        ->and($updates['tags'])->not->toContain(...stackGenericProductTypeTags());
})->with([
    'elevated basics stacks' => [
        'Elevated Basics Stacks',
        'elevated-basics-stacks',
        'Bracelets',
        'gid://shopify/TaxonomyCategory/aa-6-3',
        '191',
        ['bundles', 'elevated-basics-bundles', 'elevated-basics'],
    ],
    'livi road stacks' => [
        'Livi Road Stacks',
        'livi-road-stacks',
        'Bracelets',
        'gid://shopify/TaxonomyCategory/aa-6-3',
        '191',
        ['bundles', 'livi-road-bundles', 'livi-road'],
    ],
    'pata pata stacks' => [
        'Pata Pata Stacks',
        'pata-pata-stacks',
        'Bracelets',
        'gid://shopify/TaxonomyCategory/aa-6-3',
        '191',
        ['bundles', 'pata-pata-bundles', 'pata-pata'],
    ],
    'elevated basics necklace stacks' => [
        'Elevated Basics Necklace Stacks',
        'elevated-basics-necklace-stacks',
        'Necklaces',
        'gid://shopify/TaxonomyCategory/aa-6-8',
        '189',
        ['bundles', 'elevated-basics-necklace-bundles', 'elevated-basics'],
    ],
]);

it('keeps generic bracelet tags when a normal collection rule is applied', function (): void {
    $rule = PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'elevated-basics-bracelets',
        'collection_name' => 'Elevated Basics Bracelets',
        'add_tags' => ['elevated-basics', 'elevated-basics-bracelets', 'bracelet', 'bracelets'],
        'remove_tags' => [],
        'auto_type' => 'Bracelets',
        'auto_product_category' => 'gid://shopify/TaxonomyCategory/aa-6-3',
        'auto_google_product_category' => '191',
    ]);

    $updates = app(PrepopulationRuleService::class)->applyRule($rule, ['custom-gift'], null);

    expect($updates['type'])->toBe('Bracelets')
        ->and($updates['tags'])->toContain('bracelet', 'bracelets', 'elevated-basics-bracelets', 'custom-gift');
});

it('does not let a later shop your vibe rule reintroduce generic type tags on a stack', function (): void {
    $rule = PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_SHOP_YOUR_VIBE_DROPDOWN,
        'handle' => 'bright-bracelet-stacks',
        'collection_name' => 'Bright Bracelet Stacks',
        'add_tags' => ['bundles', 'brights-bundle-shop-your-vibe', 'brights-bracelets', 'bracelet', 'bracelets'],
        'remove_tags' => [],
        'auto_type' => 'Bracelets',
    ]);

    $updates = app(PrepopulationRuleService::class)->applyRule($rule, [
        'bundles',
        'elevated-basics-bundles',
        'custom-gift',
    ], 'Necklaces');

    expect($updates['tags'])->toContain(
        'bundles',
        'elevated-basics-bundles',
        'brights-bundle-shop-your-vibe',
        'brights-bracelets',
        'custom-gift'
    )->and($updates['tags'])->not->toContain(...stackGenericProductTypeTags());
});

it('prepopulates stack collection tags without generic bracelet or necklace tags', function (string $collection, string $primary, string $secondary): void {
    DropdownOption::query()->create([
        'header' => 'Collection',
        'value' => $collection,
        'collection_style' => $collection,
        'collection_tag_primary' => $primary,
        'collection_tag_secondary' => $secondary,
    ]);

    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection'))->invoke(
        null,
        ['custom-gift', 'bracelet', 'bracelets', 'necklace', 'necklaces', 'manual-tag'],
        $collection,
        'Necklaces',
        'Sunday Stack',
        false,
        false
    );

    expect($tags)->toContain('bundles', $secondary, 'custom-gift', 'manual-tag')
        ->and($tags)->not->toContain(...stackGenericProductTypeTags());
})->with([
    'elevated basics' => ['Elevated Basics Stacks', 'elevated-basics', 'elevated-basics-bundles'],
    'livi road' => ['Livi Road Stacks', 'livi-road', 'livi-road-bundles'],
    'pata pata' => ['Pata Pata Stacks', 'pata-pata', 'pata-pata-bundles'],
    'necklace stacks' => ['Livi Road Necklace Stacks', 'livi-road', 'livi-road-necklace-bundles'],
]);

it('still adds the bracelet tag when a normal collection and product type are selected', function (): void {
    DropdownOption::query()->create([
        'header' => 'Collection',
        'value' => 'Elevated Basics Bracelets',
        'collection_style' => 'Elevated Basics Bracelets',
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => 'elevated-basics-bracelets',
    ]);

    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'tagsForCollectionSelection'))->invoke(
        null,
        ['custom-gift'],
        'Elevated Basics Bracelets',
        'Bracelets',
        'Sunday Bracelet',
        false,
        false
    );

    expect($tags)->toContain('elevated-basics', 'elevated-basics-bracelets', 'bracelet', 'custom-gift')
        ->and($tags)->not->toContain('bundles');
});

it('changes a stack draft product type without adding generic bracelet or necklace tags', function (): void {
    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'defaultedDraftTags'))->invoke(
        null,
        ['bundles', 'elevated-basics-bundles', 'bracelet', 'bracelets', 'custom-gift', 'gold-bracelets'],
        'Necklaces',
        false,
        false
    );

    expect($tags)->toContain('bundles', 'elevated-basics-bundles', 'custom-gift', 'gold-bracelets')
        ->and($tags)->not->toContain(...stackGenericProductTypeTags());
});

it('keeps normal product type tags when the product type changes outside a stack', function (): void {
    $tags = (new ReflectionMethod(NewProductDraftResource::class, 'defaultedDraftTags'))->invoke(
        null,
        ['elevated-basics', 'elevated-basics-bracelets', 'bracelets', 'custom-gift'],
        'Necklaces',
        false,
        false
    );

    expect($tags)->toContain('necklace', 'bracelets', 'elevated-basics-bracelets', 'custom-gift')
        ->and($tags)->not->toContain('bracelet', 'bundles');
});

it('strips generic type tags when an existing stack draft is edited and saved', function (): void {
    $draft = NewProductDraft::query()->create([
        'title' => 'Existing Stack',
        'type' => 'Bracelets',
        'product_category' => 'gid://shopify/TaxonomyCategory/aa-6-3',
        'google_product_category' => '191',
        'tags' => 'bundles, elevated-basics-bundles, custom-gift',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $draft->type = 'Necklaces';
    $draft->product_category = 'gid://shopify/TaxonomyCategory/aa-6-8';
    $draft->google_product_category = '189';
    $draft->tags = 'bundles, elevated-basics-bundles, bracelet, bracelets, necklace, necklaces, custom-gift, gold-bracelets';
    $draft->save();

    $draft->refresh();
    $tags = TagNormalizer::parseTokens($draft->tags);

    expect($draft->type)->toBe('Necklaces')
        ->and($draft->product_category)->toBe('gid://shopify/TaxonomyCategory/aa-6-8')
        ->and($draft->google_product_category)->toBe('189')
        ->and($tags)->toContain('bundles', 'elevated-basics-bundles', 'custom-gift', 'gold-bracelets')
        ->and($tags)->not->toContain(...stackGenericProductTypeTags());
});

it('keeps generic type tags when a normal draft is saved', function (): void {
    $draft = NewProductDraft::query()->create([
        'title' => 'Normal Bracelet',
        'type' => 'Bracelets',
        'tags' => 'elevated-basics, elevated-basics-bracelets, bracelet, bracelets, custom-gift',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $tags = TagNormalizer::parseTokens($draft->tags);

    expect($draft->type)->toBe('Bracelets')
        ->and($tags)->toContain('bracelet', 'bracelets', 'elevated-basics-bracelets', 'custom-gift');
});

it('leaves normal product tags untouched and removes generic type tags from stack products when product type changes', function (): void {
    $method = new ReflectionMethod(ProductResource::class, 'tagsAfterProductTypeChange');

    expect($method->invoke(null, ['elevated-basics', 'bracelet', 'bracelets', 'custom-gift']))
        ->toBe(['elevated-basics', 'bracelet', 'bracelets', 'custom-gift'])
        ->and($method->invoke(null, [
            'bundles',
            'livi-road-bundles',
            'bracelet',
            'bracelets',
            'necklace',
            'necklaces',
            'custom-gift',
            'gold-bracelets',
        ]))->toBe(['bundles', 'livi-road-bundles', 'custom-gift', 'gold-bracelets']);
});

it('updates stack classification in the draft form without introducing generic type tags', function (): void {
    Role::findOrCreate(RolesEnum::Admin->value);
    $user = User::factory()->create();
    $user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($user);

    DropdownOption::query()->create([
        'header' => 'Collection',
        'value' => 'Elevated Basics Stacks',
        'collection_style' => 'Elevated Basics Stacks',
        'collection_tag_primary' => 'elevated-basics',
        'collection_tag_secondary' => 'elevated-basics-bundles',
    ]);
    PrepopulationRule::query()->create([
        'behavior' => PrepopulationRule::BEHAVIOR_AUTO_ON_COLLECTION_SELECTION,
        'handle' => 'elevated-basics-stacks',
        'collection_name' => 'Elevated Basics Stacks',
        'add_tags' => [
            'all-products',
            'bundles',
            'elevated-basics-bundles',
            'elevated-basics',
            'bracelet',
            'bracelets',
            'necklace',
            'necklaces',
        ],
        'remove_tags' => [],
        'auto_vendor' => 'Elevated Basics Bundles',
        'auto_type' => 'Bracelets',
        'auto_product_category' => 'gid://shopify/TaxonomyCategory/aa-6-3',
        'auto_google_product_category' => '191',
    ]);

    $draft = NewProductDraft::query()->create([
        'title' => 'Form Stack',
        'type' => 'Earrings',
        'tags' => 'custom-gift',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    Livewire::test(EditNewProductDraft::class, ['record' => $draft->id])
        ->set('data.collection_filter', 'Elevated Basics Stacks')
        ->assertSet('data.type', 'Bracelets')
        ->assertSet('data.product_category', 'gid://shopify/TaxonomyCategory/aa-6-3')
        ->assertSet('data.google_product_category', '191')
        ->assertSet('data.tags', function (mixed $tags): bool {
            $tokens = is_array($tags) ? $tags : TagNormalizer::parseTokens((string) $tags);

            return in_array('bundles', $tokens, true)
                && in_array('elevated-basics-bundles', $tokens, true)
                && in_array('custom-gift', $tokens, true)
                && array_intersect(stackGenericProductTypeTags(), $tokens) === [];
        })
        ->set('data.tags', ['bundles', 'elevated-basics-bundles', 'bracelet', 'bracelets', 'custom-gift'])
        ->set('data.type', 'Necklaces')
        ->assertSet('data.product_category', 'gid://shopify/TaxonomyCategory/aa-6-8')
        ->assertSet('data.google_product_category', '189')
        ->assertSet('data.type', 'Necklaces')
        ->assertSet('data.tags', function (mixed $tags): bool {
            $tokens = is_array($tags) ? $tags : TagNormalizer::parseTokens((string) $tags);

            return in_array('bundles', $tokens, true)
                && in_array('elevated-basics-bundles', $tokens, true)
                && in_array('custom-gift', $tokens, true)
                && array_intersect(stackGenericProductTypeTags(), $tokens) === [];
        });
});
