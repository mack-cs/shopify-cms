<?php

use App\Jobs\ExpireNewInTagsJob;
use App\Jobs\MirrorExpiredNewInTagsLocallyJob;
use App\Models\Import;
use App\Models\NewInExpirationOperation;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\NewInExpirationLock;
use App\Services\NewInTagClassifier;
use App\Services\NewInTagExpirationService;
use App\Services\NewInTagService;
use App\Services\NewProductDraftShopifyCreator;
use App\Services\ProductShopifyUpdater;
use App\Services\TagNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.timezone' => 'Africa/Johannesburg',
        'services.shopify.shop' => 'example.myshopify.com',
        'services.shopify.admin_access_token' => 'fake-shopify-token',
        'new_in_expiration.enabled' => false,
        'new_in_expiration.enforcement_enabled' => true,
        'new_in_expiration.recovery_enabled' => false,
        'new_in_expiration.days' => 75,
        'new_in_expiration.timezone' => 'Africa/Johannesburg',
        'new_in_expiration.schedule_time' => '03:15',
        'new_in_expiration.page_size' => 50,
        'new_in_expiration.lock_seconds' => 7200,
        'cache.default' => 'array',
    ]);
    Storage::fake('local');
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function newInImport(): Import
{
    $user = User::factory()->create();

    return Import::create([
        'filename' => 'new-in-expiration.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
}

/**
 * @param  array<int, string>  $discoveredTags
 * @param  array<string, array<string, mixed>>  $productsByTag
 * @param  array<int, array<string, mixed>>  $mutations
 */
function fakeNewInExpirationShopify(
    array $discoveredTags,
    array $productsByTag = [],
    array &$mutations = [],
    bool &$failMutation = false,
    bool $paginatedDiscovery = false,
    bool $omitTagsInMutationResponse = false,
    bool $wrongProductIdInMutation = false,
    ?callable $pageInfoOverride = null,
    array &$createdAtLookups = [],
): void {
    $discoveryPages = $paginatedDiscovery
        ? [array_slice($discoveredTags, 0, 1), array_slice($discoveredTags, 1)]
        : [$discoveredTags];
    $discoveryIndex = 0;
    // Capture by reference so tagsRemove mutations persist for later product reads.
    $catalog = $productsByTag;

    Http::fake(function (Request $request) use (
        &$discoveryIndex,
        $discoveryPages,
        &$catalog,
        &$mutations,
        &$failMutation,
        $omitTagsInMutationResponse,
        $wrongProductIdInMutation,
        $pageInfoOverride,
        &$createdAtLookups,
    ) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');
        $variables = (array) ($body['variables'] ?? []);

        if (str_contains($query, 'NewInProductCreatedAt')) {
            $id = (string) ($variables['id'] ?? '');
            $createdAtLookups[] = $id;
            foreach ($catalog as $product) {
                if (($product['id'] ?? null) === $id) {
                    return Http::response(['data' => [
                        'product' => [
                            'id' => $id,
                            'createdAt' => $product['createdAt'],
                        ],
                    ]]);
                }
            }

            return Http::response(['data' => ['product' => null]]);
        }

        if (str_contains($query, 'DiscoverProductTags')) {
            $nodes = $discoveryPages[$discoveryIndex] ?? [];
            $discoveryIndex++;
            $hasNext = $discoveryIndex < count($discoveryPages);
            $pageInfo = [
                'hasNextPage' => $hasNext,
                'endCursor' => $hasNext ? 'cursor-'.$discoveryIndex : null,
            ];
            if ($pageInfoOverride !== null) {
                $pageInfo = $pageInfoOverride($pageInfo, 'productTags', $discoveryIndex);
            }

            return Http::response(['data' => [
                'productTags' => [
                    'nodes' => $nodes,
                    'pageInfo' => $pageInfo,
                ],
            ]]);
        }

        if (str_contains($query, 'ExpireNewInCandidates')) {
            $search = (string) ($variables['query'] ?? '');
            expect($search)->toContain('status:active')
                ->and($search)->toContain('status:draft')
                ->and($search)->toContain('status:archived')
                ->and($search)->toContain('status:unlisted');

            $matched = [];
            foreach ($catalog as $tag => $product) {
                if (str_contains($search, "tag:'{$tag}'") || str_contains($search, 'tag:'.$tag)) {
                    $matched[$product['id'] ?? ('gid://shopify/Product/'.md5($tag))] = [
                        'id' => $product['id'] ?? ('gid://shopify/Product/'.md5($tag)),
                        'title' => $product['title'] ?? 'Product '.$tag,
                        'handle' => $product['handle'] ?? 'handle-'.md5($tag),
                        'status' => $product['status'] ?? 'ACTIVE',
                        'createdAt' => $product['createdAt'],
                        'tags' => $product['tags'],
                        'variants' => ['nodes' => [['sku' => $product['sku'] ?? 'SKU-1']]],
                    ];
                }
            }

            $pageInfo = ['hasNextPage' => false, 'endCursor' => null];
            if ($pageInfoOverride !== null) {
                $pageInfo = $pageInfoOverride($pageInfo, 'products', 1);
            }

            return Http::response(['data' => [
                'products' => [
                    'nodes' => array_values($matched),
                    'pageInfo' => $pageInfo,
                ],
            ]]);
        }

        if (str_contains($query, 'ReadProductForNewInExpiration')) {
            $id = (string) ($variables['id'] ?? '');
            foreach ($catalog as $product) {
                $pid = $product['id'] ?? null;
                if ($pid === $id) {
                    return Http::response(['data' => [
                        'product' => [
                            'id' => $pid,
                            'title' => $product['title'] ?? 'Product',
                            'handle' => $product['handle'] ?? 'handle',
                            'status' => $product['status'] ?? 'ACTIVE',
                            'createdAt' => $product['createdAt'],
                            'tags' => $product['tags'],
                            'variants' => ['nodes' => [['sku' => $product['sku'] ?? 'SKU-1']]],
                        ],
                    ]]);
                }
            }

            return Http::response(['data' => ['product' => null]]);
        }

        if (str_contains($query, 'RemoveNewInTags')) {
            if ($failMutation) {
                return Http::response(['data' => [
                    'tagsRemove' => [
                        'node' => null,
                        'userErrors' => [['field' => ['tags'], 'message' => 'Mutation blocked']],
                    ],
                ]]);
            }

            $id = (string) ($variables['id'] ?? '');
            $remove = array_values((array) ($variables['tags'] ?? []));
            $mutations[] = ['id' => $id, 'tags' => $remove];

            $currentTags = [];
            $createdAt = '2026-01-01T00:00:00Z';
            foreach ($catalog as $key => $product) {
                if (($product['id'] ?? null) === $id) {
                    $catalog[$key]['tags'] = array_values(array_diff($product['tags'], $remove));
                    $currentTags = $catalog[$key]['tags'];
                    $createdAt = $product['createdAt'];
                    break;
                }
            }

            if ($omitTagsInMutationResponse) {
                return Http::response(['data' => [
                    'tagsRemove' => [
                        'node' => [
                            'id' => $wrongProductIdInMutation ? 'gid://shopify/Product/WRONG' : $id,
                            'createdAt' => $createdAt,
                        ],
                        'userErrors' => [],
                    ],
                ]]);
            }

            return Http::response(['data' => [
                'tagsRemove' => [
                    'node' => [
                        'id' => $wrongProductIdInMutation ? 'gid://shopify/Product/WRONG' : $id,
                        'tags' => $currentTags,
                        'createdAt' => $createdAt,
                    ],
                    'userErrors' => [],
                ],
            ]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call: '.$query);
    });
}

it('classifies all ten discovered live New In tags and the legacy elevated basics bracelet stack tag', function (): void {
    $classifier = app(NewInTagClassifier::class);
    foreach ([
        'new-in', 'new-arrivals', 'newbies', 'livi-road-new-in', 'pata-pata-new-in',
        'elevated-basics-new-in', 'livi-road-bracelet-stacks-new-in',
        'pata-pata-bracelet-stacks-new-in', 'elevated-basics-stacks-new-in',
        'elevated-basics-necklace-stacks-new-in', 'elevated-basics-bracelet-stacks-new-in',
    ] as $tag) {
        expect($classifier->classify($tag))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE);
    }
});

it('rejects the Codex false-positive tags and keeps exact original spellings for removal', function (): void {
    $classifier = app(NewInTagClassifier::class);

    foreach (['new-inventory', 'new-inspiration', 'new-arrival-notification', 'renew-in-store'] as $tag) {
        expect($classifier->isAutoRemove($tag))->toBeFalse();
    }

    expect($classifier->classify('new-inventory'))->toBe(NewInTagClassifier::CLASS_AMBIGUOUS)
        ->and($classifier->classify('new-inspiration'))->toBe(NewInTagClassifier::CLASS_AMBIGUOUS)
        ->and($classifier->classify('new-arrival-notification'))->toBe(NewInTagClassifier::CLASS_AMBIGUOUS)
        ->and($classifier->classify('renew-in-store'))->toBe(NewInTagClassifier::CLASS_UNRELATED);

    $classified = $classifier->classifyMany(['Special New In', 'bracelets', 'new-inventory']);
    expect($classified['auto_remove'])->toBe(['Special New In'])
        ->and($classified['ambiguous'])->toBe(['new-inventory']);
});

it('classifies capitalization, separator, and previously unknown collection-specific New In tags', function (): void {
    $classifier = app(NewInTagClassifier::class);

    expect($classifier->classify('New In'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE)
        ->and($classifier->classify('NEW_IN'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE)
        ->and($classifier->classify('New--Arrivals'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE)
        ->and($classifier->classify('future-collection-new-in'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE)
        ->and($classifier->classify('brand-x-charm-stacks-new-in'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE)
        ->and($classifier->classify('newin'))->toBe(NewInTagClassifier::CLASS_AUTO_REMOVE);
});

it('keeps ambiguous tags report-only and preserves unrelated tags', function (): void {
    $classifier = app(NewInTagClassifier::class);

    expect($classifier->classify('brand-new'))->toBe(NewInTagClassifier::CLASS_AMBIGUOUS)
        ->and($classifier->classify('new'))->toBe(NewInTagClassifier::CLASS_AMBIGUOUS)
        ->and($classifier->classify('newborn'))->toBe(NewInTagClassifier::CLASS_UNRELATED)
        ->and($classifier->stripAutoRemove([
            'bracelets', 'new-in', 'brand-new', 'sale', 'pata-pata-new-in', 'new-inventory',
        ]))->toBe(['bracelets', 'brand-new', 'sale', 'new-inventory']);
});

it('persists shopify_created_at as the same UTC instant under Africa/Johannesburg', function (): void {
    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/TZ',
        'title' => 'TZ',
        'handle' => 'tz',
        'tags' => 'bracelets',
        'status' => 'active',
        'shopify_created_at' => '2026-01-01T00:00:00Z',
    ]);

    $reloaded = $product->fresh();
    expect($reloaded->shopify_created_at)->not->toBeNull()
        ->and($reloaded->shopify_created_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe('2026-01-01T00:00:00Z')
        ->and($reloaded->shopify_created_at->eq(CarbonImmutable::parse('2026-01-01T00:00:00Z')))->toBeTrue();
});

it('expires products aged exactly 75 days and skips younger products', function (): void {
    $exactly = CarbonImmutable::now('UTC')->subDays(75)->format('Y-m-d\TH:i:s\Z');
    $younger = CarbonImmutable::now('UTC')->subDays(74)->format('Y-m-d\TH:i:s\Z');

    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/75',
                'createdAt' => $exactly,
                'tags' => ['new-in', 'bracelets', 'sale'],
                'title' => 'Exactly 75',
                'handle' => 'exactly-75',
                'sku' => 'EX-75',
            ],
        ],
        $mutations
    );

    $import = newInImport();
    Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/75',
        'title' => 'Exactly 75',
        'handle' => 'exactly-75',
        'tags' => 'new-in, bracelets, sale',
        'status' => 'active',
    ]);

    $summary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);
    expect($summary['eligible'])->toBe(1)
        ->and($summary['removed'])->toBe(1)
        ->and($mutations)->toHaveCount(1)
        ->and($mutations[0]['tags'])->toBe(['new-in']);

    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/74',
                'createdAt' => $younger,
                'tags' => ['new-in', 'bracelets'],
                'title' => 'Younger',
                'handle' => 'younger',
            ],
        ],
        $mutations
    );

    $youngSummary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);
    expect($youngSummary['eligible'])->toBe(0)
        ->and($youngSummary['removed'])->toBe(0)
        ->and($mutations)->toHaveCount(0);
});

it('removes stack-only New In tags with durable operation and preserves unrelated local edits', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    fakeNewInExpirationShopify(
        ['elevated-basics-necklace-stacks-new-in'],
        [
            'elevated-basics-necklace-stacks-new-in' => [
                'id' => 'gid://shopify/Product/STACK',
                'createdAt' => $created,
                'tags' => ['elevated-basics-necklace-stacks-new-in', 'bracelets', 'exclude-from-the-sale'],
                'title' => 'Stack Only',
                'handle' => 'stack-only',
                'status' => 'DRAFT',
            ],
        ],
        $mutations
    );

    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/STACK',
        'title' => 'Stack Only',
        'handle' => 'stack-only',
        'tags' => 'elevated-basics-necklace-stacks-new-in, bracelets, exclude-from-the-sale, cms-only-edit',
        'status' => 'draft',
        'shopify_created_at' => $created,
    ]);

    $summary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);

    expect($summary['removed'])->toBe(1)
        ->and($mutations[0]['tags'])->toBe(['elevated-basics-necklace-stacks-new-in'])
        ->and(TagNormalizer::parseTokens($product->fresh()->tags))
        ->toEqualCanonicalizing(['bracelets', 'exclude-from-the-sale', 'cms-only-edit'])
        ->and(NewInExpirationOperation::query()->where('status', NewInExpirationOperation::STATUS_CONFIRMED)->count())->toBe(1);

    $operation = NewInExpirationOperation::sole();
    expect($operation->meta['product_title'])->toBe('Stack Only')
        ->and($operation->meta['expiration_days'])->toBe(75)
        ->and($operation->meta['removal_confirmations'][0]['tags'])->toBe($mutations[0]['tags']);

    $legacy = NewInExpirationOperation::create([
        'product_gid' => $product->shopify_id,
        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
        'shopify_created_at' => $operation->shopify_created_at,
        'remote_confirmed_at' => CarbonImmutable::parse('2026-11-24T14:00:00+02:00'),
        'tags_to_remove' => ['newbies'],
    ]);
    $pending = NewInExpirationOperation::create([
        'product_gid' => 'gid://shopify/Product/NO-REMOVAL',
        'status' => NewInExpirationOperation::STATUS_PENDING,
        'tags_to_remove' => ['new-in'],
    ]);
    $user = User::factory()->create();
    $this->actingAs($user);
    expect(\App\Filament\Resources\NewInExpirationOperationResource::canViewAny())->toBeFalse();
    $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate(\App\Enums\RolesEnum::SuperAdmin->value));

    \Livewire\Livewire::test(\App\Filament\Resources\NewInExpirationOperationResource\Pages\ListNewInExpirationOperations::class)
        ->assertCanSeeTableRecords([$operation, $legacy])
        ->assertCanNotSeeTableRecords([$pending])
        ->assertSee('Stack Only')
        ->assertSee('80 days')
        ->assertSee('24 Nov 2026 14:00:00 SAST')
        ->assertSee('elevated-basics-necklace-stacks-new-in')
        ->assertSee('newbies')
        ->searchTable('Stack Only')
        ->assertCanSeeTableRecords([$operation, $legacy]);
});

it('deduplicates candidates across overlapping discovered tags before mutating', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    $shared = [
        'id' => 'gid://shopify/Product/DUP',
        'createdAt' => $created,
        'tags' => ['new-in', 'livi-road-new-in', 'bracelets'],
        'title' => 'Duplicate Candidate',
        'handle' => 'dup',
    ];

    fakeNewInExpirationShopify(
        ['new-in', 'livi-road-new-in'],
        [
            'new-in' => $shared,
            'livi-road-new-in' => $shared,
        ],
        $mutations
    );

    Product::create([
        'import_id' => newInImport()->id,
        'shopify_id' => 'gid://shopify/Product/DUP',
        'title' => 'Duplicate Candidate',
        'handle' => 'dup',
        'tags' => 'new-in, livi-road-new-in, bracelets',
        'status' => 'active',
    ]);

    $summary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);

    expect($summary['assessed'])->toBe(1)
        ->and($summary['removed'])->toBe(1)
        ->and($mutations)->toHaveCount(1)
        ->and($mutations[0]['tags'])->toEqualCanonicalizing(['new-in', 'livi-road-new-in']);
});

it('paginates productTags discovery and supports dry-run without mutations or local writes', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(100)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in', 'future-drop-new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/PAGE',
                'createdAt' => $created,
                'tags' => ['new-in', 'future-drop-new-in'],
            ],
            'future-drop-new-in' => [
                'id' => 'gid://shopify/Product/PAGE',
                'createdAt' => $created,
                'tags' => ['new-in', 'future-drop-new-in'],
            ],
        ],
        $mutations,
        paginatedDiscovery: true
    );

    $summary = app(NewInTagExpirationService::class)->run(dryRun: true, mutate: false);

    expect($summary['dry_run'])->toBeTrue()
        ->and($summary['discovered_auto_remove_tags'])->toEqualCanonicalizing(['new-in', 'future-drop-new-in'])
        ->and($summary['eligible'])->toBe(1)
        ->and($summary['removed'])->toBe(0)
        ->and($mutations)->toHaveCount(0)
        ->and(NewInExpirationOperation::query()->count())->toBe(0);
});

it('rejects missing pagination cursors', function (): void {
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        ['new-in'],
        [],
        $mutations,
        $fail,
        false,
        false,
        false,
        fn (array $pageInfo): array => ['hasNextPage' => true],
    );

    expect(fn () => app(NewInTagExpirationService::class)->discoverShopTags())
        ->toThrow(RuntimeException::class, 'endCursor');
});

it('rejects repeated pagination cursors', function (): void {
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        ['new-in', 'newbies'],
        [],
        $mutations,
        $fail,
        true,
        false,
        false,
        fn (): array => ['hasNextPage' => true, 'endCursor' => 'same-cursor'],
    );

    expect(fn () => app(NewInTagExpirationService::class)->discoverShopTags())
        ->toThrow(RuntimeException::class, 'repeated cursor');
});

it('rejects mutation responses that return the wrong product id', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/BADRESP',
                'createdAt' => $created,
                'tags' => ['new-in', 'bracelets'],
                'handle' => 'bad-resp',
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        true,
    );

    Product::create([
        'import_id' => newInImport()->id,
        'shopify_id' => 'gid://shopify/Product/BADRESP',
        'title' => 'Bad',
        'handle' => 'bad-resp',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
    ]);

    $summary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);
    expect($summary['failed'])->toBe(1)
        ->and($summary['removed'])->toBe(0);
});

it('does not treat an omitted tags field as an empty successful confirmation', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $service = app(NewInTagExpirationService::class);

    Http::fake(function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');
        if (str_contains($query, 'RemoveNewInTags')) {
            return Http::response(['data' => [
                'tagsRemove' => [
                    'node' => [
                        'id' => 'gid://shopify/Product/OMIT',
                        'createdAt' => '2026-01-01T00:00:00Z',
                    ],
                    'userErrors' => [],
                ],
            ]]);
        }
        if (str_contains($query, 'ReadProductForNewInExpiration')) {
            return Http::response(['data' => [
                'product' => [
                    'id' => 'gid://shopify/Product/OMIT',
                    'title' => 'Omit',
                    'handle' => 'omit',
                    'status' => 'ACTIVE',
                    'createdAt' => '2026-01-01T00:00:00Z',
                    'tags' => ['new-in', 'bracelets'],
                    'variants' => ['nodes' => []],
                ],
            ]]);
        }

        throw new RuntimeException('Unexpected: '.$query);
    });

    expect(fn () => $service->removeTagsOnShopify('gid://shopify/Product/OMIT', ['new-in']))
        ->toThrow(RuntimeException::class, 'did not remove');
});

it('does not update local CMS when Shopify tagsRemove fails and keeps durable op open', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $fail = true;
    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/FAIL',
                'createdAt' => $created,
                'tags' => ['new-in', 'bracelets'],
                'handle' => 'fail-product',
            ],
        ],
        $mutations,
        $fail
    );

    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/FAIL',
        'title' => 'Fail Product',
        'handle' => 'fail-product',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);

    $summary = app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true);

    expect($summary['failed'])->toBe(1)
        ->and($summary['removed'])->toBe(0)
        ->and($product->fresh()->tags)->toBe('new-in, bracelets')
        ->and(NewInExpirationOperation::query()->where('product_gid', 'gid://shopify/Product/FAIL')->exists())->toBeTrue();
});

it('creates durable operations before mutation and recovers after local sync failure', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/RECOVER',
                'createdAt' => $created,
                'tags' => ['new-in', 'bracelets'],
                'handle' => 'recover-me',
            ],
        ],
        $mutations
    );

    Queue::fake();

    $service = new class(app(\App\Services\ShopifyApiClient::class), app(NewInTagClassifier::class), app(\App\Services\NewInProductAgeResolver::class), app(NewInExpirationLock::class)) extends NewInTagExpirationService
    {
        public function mirrorLocalStateForOperation(NewInExpirationOperation $operation): void
        {
            throw new RuntimeException('CMS write failed');
        }
    };

    $summary = $service->run(dryRun: false, mutate: true);

    expect($summary['removed'])->toBe(1)
        ->and($summary['local_sync_failed'])->toBe(1)
        ->and(NewInExpirationOperation::query()->where('status', NewInExpirationOperation::STATUS_REMOTE_CONFIRMED)->count())->toBe(1);

    Queue::assertPushed(MirrorExpiredNewInTagsLocallyJob::class);

    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/RECOVER',
        'title' => 'Recover Me',
        'handle' => 'recover-me',
        'tags' => 'new-in, bracelets, newer-edit',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);

    // Stale recovery must strip only intended tags, preserving newer-edit.
    $recover = app(NewInTagExpirationService::class)->recoverPendingLocalSyncs();
    expect($recover['synced'])->toBe(1)
        ->and(TagNormalizer::parseTokens($product->fresh()->tags))->toEqualCanonicalizing(['bracelets', 'newer-edit'])
        ->and(NewInExpirationOperation::query()->where('status', NewInExpirationOperation::STATUS_CONFIRMED)->count())->toBe(1);
});

it('strips New In when local shopify_created_at is missing by fetching Shopify createdAt', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z');
    $lookups = [];
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        [],
        [
            'x' => [
                'id' => 'gid://shopify/Product/NULLDATE',
                'createdAt' => $created,
                'tags' => ['new-in'],
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        false,
        null,
        $lookups,
    );

    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/NULLDATE',
        'title' => 'Null Date',
        'handle' => 'null-date',
        'tags' => 'bracelets, new-in',
        'status' => 'active',
        'shopify_created_at' => null,
    ]);

    $filtered = app(NewInTagExpirationService::class)->filterOutboundTags($product, ['bracelets', 'new-in', 'sale']);
    expect($filtered)->toBe(['bracelets', 'sale'])
        ->and($lookups)->toContain('gid://shopify/Product/NULLDATE')
        ->and($product->fresh()->shopify_created_at)->not->toBeNull();
});

it('prevents expired New In tag reintroduction via outbound filter and NewInTagService', function (): void {
    $import = newInImport();
    $expired = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/EXPIRED',
        'title' => 'Expired',
        'handle' => 'expired',
        'tags' => 'bracelets',
        'status' => 'active',
        'shopify_created_at' => CarbonImmutable::now('UTC')->subDays(90),
    ]);
    $fresh = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/FRESH',
        'title' => 'Fresh',
        'handle' => 'fresh',
        'tags' => 'bracelets',
        'status' => 'active',
        'shopify_created_at' => CarbonImmutable::now('UTC')->subDays(10),
    ]);

    $lookups = [];
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        [],
        [
            'expired' => [
                'id' => 'gid://shopify/Product/EXPIRED',
                'createdAt' => CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z'),
                'tags' => [],
            ],
            'fresh' => [
                'id' => 'gid://shopify/Product/FRESH',
                'createdAt' => CarbonImmutable::now('UTC')->subDays(10)->format('Y-m-d\TH:i:s\Z'),
                'tags' => [],
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        false,
        null,
        $lookups,
    );

    $expiration = app(NewInTagExpirationService::class);
    expect($expiration->filterOutboundTags($expired, ['bracelets', 'new-in', 'pata-pata-new-in', 'sale']))
        ->toBe(['bracelets', 'sale']);

    $service = app(NewInTagService::class);
    expect($service->tagsForNewProduct(['bracelets'], 'Bracelets', 'Title', $expired))
        ->not->toContain('new-in', 'new-arrivals', 'newbies')
        ->and($service->tagsForNewProduct(['bracelets', 'pata-pata'], 'Bracelets', 'Title', $fresh))
        ->toContain('new-in', 'new-arrivals', 'newbies', 'pata-pata-new-in');
});

it('does not match drafts with a conflicting Shopify GID during local sync', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/A',
        'title' => 'A',
        'handle' => 'shared-handle',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);
    $otherDraftId = NewProductDraft::withoutEvents(function () {
        return NewProductDraft::query()->create([
            'title' => 'Other',
            'handle' => 'shared-handle',
            'sku' => 'OTHER-GID-B',
            'shopify_id' => 'gid://shopify/Product/B',
            'tags' => 'new-in, bracelets',
            'status' => 'draft',
            'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        ])->id;
    });
    $matchingDraftId = NewProductDraft::withoutEvents(function () {
        return NewProductDraft::query()->create([
            'title' => 'Match',
            'handle' => 'match-handle',
            'sku' => 'MATCH-GID-A',
            'shopify_id' => 'gid://shopify/Product/A',
            'tags' => 'new-in, bracelets',
            'status' => 'draft',
            'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        ])->id;
    });

    $operation = app(NewInTagExpirationService::class)->claimOperation(
        'gid://shopify/Product/A',
        ['new-in'],
        ['new-in', 'bracelets'],
        $created,
    );
    $operation->forceFill([
        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
        'remote_confirmed_at' => now(),
    ])->save();

    app(NewInTagExpirationService::class)->mirrorLocalStateForOperation($operation);

    $matchingDraft = NewProductDraft::query()->findOrFail($matchingDraftId);
    $otherDraft = NewProductDraft::query()->findOrFail($otherDraftId);

    expect(TagNormalizer::parseTokens($product->fresh()->tags))->not->toContain('new-in')
        ->and(TagNormalizer::parseTokens($matchingDraft->tags))->not->toContain('new-in')
        ->and(TagNormalizer::parseTokens($otherDraft->tags))->toContain('new-in');
});

it('fails local sync when no product or draft mapping exists', function (): void {
    $operation = app(NewInTagExpirationService::class)->claimOperation(
        'gid://shopify/Product/MISSING',
        ['new-in'],
        ['new-in'],
        CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z'),
    );
    $operation->forceFill(['status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED])->save();

    expect(fn () => app(NewInTagExpirationService::class)->mirrorLocalStateForOperation($operation))
        ->toThrow(RuntimeException::class, 'No local Product or NewProductDraft mapping');
});

it('registers the daily schedule disabled by default with overlap protection', function (): void {
    config([
        'new_in_expiration.enabled' => false,
        'new_in_expiration.enforcement_enabled' => false,
        'new_in_expiration.recovery_enabled' => false,
    ]);
    $daily = collect(app(Schedule::class)->events())
        ->first(fn ($event) => ($event->description ?? null) === 'daily-new-in-tag-expiration'
            || (str_contains((string) $event->command, 'products:expire-new-in-tags')
                && ! str_contains((string) $event->command, '--recover-local')));

    expect($daily)->not->toBeNull()
        ->and($daily->expression)->toBe('15 3 * * *')
        ->and($daily->timezone)->toBe('Africa/Johannesburg')
        ->and($daily->filtersPass(app()))->toBeFalse();

    config([
        'new_in_expiration.enabled' => true,
        'new_in_expiration.enforcement_enabled' => false,
    ]);
    expect($daily->filtersPass(app()))->toBeFalse();

    config([
        'new_in_expiration.enabled' => true,
        'new_in_expiration.enforcement_enabled' => true,
    ]);
    expect($daily->filtersPass(app()))->toBeTrue();

    $recovery = collect(app(Schedule::class)->events())
        ->first(fn ($event) => ($event->description ?? null) === 'new-in-expiration-operation-recovery');
    expect($recovery)->not->toBeNull();
    expect($recovery->filtersPass(app()))->toBeTrue();
    config(['new_in_expiration.enabled' => false]);
    config(['new_in_expiration.recovery_enabled' => false, 'new_in_expiration.enforcement_enabled' => true]);
    expect($recovery->filtersPass(app()))->toBeFalse();
    config(['new_in_expiration.recovery_enabled' => true, 'new_in_expiration.enforcement_enabled' => true]);
    expect($recovery->filtersPass(app()))->toBeTrue();
});

it('shares one execution lock key between manual commands and queued jobs', function (): void {
    $lock = Cache::lock(NewInExpirationLock::KEY, 7200);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('products:expire-new-in-tags', ['--dry-run' => true])->assertFailed();
        $this->artisan('products:expire-new-in-tags', ['--recover-local' => true])->assertFailed();
        $sweep = (new ExpireNewInTagsJob(dryRun: true))->withFakeQueueInteractions();
        app()->call([$sweep, 'handle']);
        $sweep->assertReleased(120);
        $mirror = (new MirrorExpiredNewInTagsLocallyJob('pending-operation'))->withFakeQueueInteractions();
        app()->call([$mirror, 'handle']);
        $mirror->assertReleased(120);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }

    Queue::fake();
    $this->artisan('products:expire-new-in-tags', ['--dry-run' => true, '--queue' => true])->assertSuccessful();
    Queue::assertPushed(ExpireNewInTagsJob::class, fn (ExpireNewInTagsJob $job): bool => $job->dryRun);
});

it('does not key Shop Your Vibe sorting approval gate on New In tags', function (): void {
    $syv = file_get_contents(app_path('Services/ShopYourVibeWorkflow.php'));
    $approval = file_get_contents(app_path('Services/CollectionApprovalRequestService.php'));

    expect($syv)->not->toMatch('/new-in|newbies|new-arrivals/i')
        ->and($approval)->not->toMatch('/new-in|newbies|new-arrivals/i');
});

it('preserves outer whitespace on exact Shopify tag strings used for removal', function (): void {
    $classified = app(NewInTagClassifier::class)->classifyMany([' Special New In ', 'bracelets']);
    expect($classified['auto_remove'])->toBe([' Special New In ']);
});

it('rejects missing or null productTags nodes as incomplete discovery', function (): void {
    Http::fake(fn () => Http::response(['data' => [
        'productTags' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ],
    ]]));

    expect(fn () => app(NewInTagExpirationService::class)->discoverShopTags())
        ->toThrow(RuntimeException::class, 'omitted nodes');
});

it('rejects null productTags nodes as incomplete discovery', function (): void {
    Http::fake(fn () => Http::response(json_encode([
        'data' => [
            'productTags' => [
                'nodes' => null,
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']));

    expect(fn () => app(NewInTagExpirationService::class)->discoverShopTags())
        ->toThrow(RuntimeException::class, 'nodes was null');
});

it('accepts a valid empty productTags nodes array', function (): void {
    Http::fake(fn () => Http::response(['data' => [
        'productTags' => [
            'nodes' => [],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ],
    ]]));

    $discovery = app(NewInTagExpirationService::class)->discoverShopTags();
    expect($discovery['auto_remove'])->toBe([])
        ->and($discovery['ambiguous'])->toBe([]);
});

it('merges multiple legacy JSON recovery entries for the same Shopify GID', function (): void {
    Storage::disk('local')->put('new_in_expiration/pending_local_sync.json', json_encode([
        [
            'shopify_product_id' => 'gid://shopify/Product/LEGACY',
            'removed_tags' => ['new-in'],
            'tags_after' => ['bracelets'],
            'created_at' => '2026-01-01T00:00:00Z',
        ],
        [
            'shopify_product_id' => 'gid://shopify/Product/LEGACY',
            'removed_tags' => ['newbies'],
            'tags_after' => ['bracelets'],
            'created_at' => '2026-01-01T00:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR));

    $migrated = app(NewInTagExpirationService::class)->migrateLegacyJsonPendingIfPresent();
    expect($migrated)->toBe(1);

    $op = NewInExpirationOperation::query()->where('product_gid', 'gid://shopify/Product/LEGACY')->first();
    expect($op)->not->toBeNull()
        ->and($op->tags_to_remove)->toEqualCanonicalizing(['new-in', 'newbies']);

    // Repeatable: existing open op merges rather than discarding.
    Storage::disk('local')->put('new_in_expiration/pending_local_sync.json', json_encode([
        [
            'shopify_product_id' => 'gid://shopify/Product/LEGACY',
            'removed_tags' => ['livi-road-new-in'],
            'created_at' => '2026-01-01T00:00:00Z',
        ],
    ], JSON_THROW_ON_ERROR));

    app(NewInTagExpirationService::class)->migrateLegacyJsonPendingIfPresent();
    expect($op->fresh()->tags_to_remove)->toEqualCanonicalizing(['new-in', 'newbies', 'livi-road-new-in']);

    $archive = collect(Storage::disk('local')->files('new_in_expiration'))
        ->first(fn (string $path): bool => str_contains($path, '.migrated.'));
    $op->update(['status' => NewInExpirationOperation::STATUS_CONFIRMED, 'active_product_gid' => null]);
    Storage::disk('local')->put('new_in_expiration/pending_local_sync.json', Storage::disk('local')->get($archive));
    expect(app(NewInTagExpirationService::class)->migrateLegacyJsonPendingIfPresent())->toBe(0)
        ->and(NewInExpirationOperation::count())->toBe(1);
});

it('does not mutate historical import product rows for the same Shopify GID', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $current = newInImport();
    Import::query()->whereKey($current->id)->update(['is_current' => true]);
    $historical = Import::create([
        'filename' => 'historical.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $current->created_by,
        'is_current' => false,
        'is_valid' => true,
    ]);

    $currentProduct = Product::create([
        'import_id' => $current->id,
        'shopify_id' => 'gid://shopify/Product/HIST',
        'title' => 'Current',
        'handle' => 'hist',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);
    $historicalProduct = Product::create([
        'import_id' => $historical->id,
        'shopify_id' => 'gid://shopify/Product/HIST',
        'title' => 'Historical',
        'handle' => 'hist',
        'tags' => 'new-in, bracelets, archive-snapshot',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);

    $operation = app(NewInTagExpirationService::class)->claimOperation(
        'gid://shopify/Product/HIST',
        ['new-in'],
        ['new-in', 'bracelets'],
        $created,
    );
    $operation->forceFill(['status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED])->save();
    app(NewInTagExpirationService::class)->mirrorLocalStateForOperation($operation);

    expect(TagNormalizer::parseTokens($currentProduct->fresh()->tags))->not->toContain('new-in')
        ->and(TagNormalizer::parseTokens($historicalProduct->fresh()->tags))->toContain('new-in', 'archive-snapshot');
});

it('re-removes tags when recovering a remote_confirmed operation that still has them', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/STUCK',
                'createdAt' => $created,
                'tags' => ['new-in', 'bracelets'],
                'handle' => 'stuck',
            ],
        ],
        $mutations,
        $fail,
    );

    Product::create([
        'import_id' => newInImport()->id,
        'shopify_id' => 'gid://shopify/Product/STUCK',
        'title' => 'Stuck',
        'handle' => 'stuck',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);

    $operation = app(NewInTagExpirationService::class)->claimOperation(
        'gid://shopify/Product/STUCK',
        ['new-in'],
        ['new-in', 'bracelets'],
        $created,
    );
    $operation->forceFill([
        'status' => NewInExpirationOperation::STATUS_REMOTE_CONFIRMED,
        'remote_confirmed_at' => now(),
        'available_at' => now(),
    ])->save();

    $status = app(NewInTagExpirationService::class)->recoverOperation($operation->fresh());
    expect($status)->toBe(NewInExpirationOperation::STATUS_CONFIRMED)
        ->and($mutations)->not->toBeEmpty()
        ->and($mutations[0]['tags'])->toContain('new-in');
});

it('uses the resolved mutation GID instead of a blank or conflicting local shopify_id', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z');
    $lookups = [];
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        [],
        [
            'resolved' => [
                'id' => 'gid://shopify/Product/RESOLVED',
                'createdAt' => $created,
                'tags' => ['new-in'],
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        false,
        null,
        $lookups,
    );

    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => null,
        'title' => 'Handle Only',
        'handle' => 'handle-only',
        'tags' => 'new-in, sale',
        'status' => 'active',
    ]);

    $filtered = app(NewInTagExpirationService::class)->filterOutboundTags(
        $product,
        ['new-in', 'sale'],
        resolvedProductGid: 'gid://shopify/Product/RESOLVED',
    );

    expect($filtered)->toBe(['sale'])
        ->and($lookups)->toContain('gid://shopify/Product/RESOLVED');
});

it('does not strip New In tags when enforcement is disabled', function (): void {
    config(['new_in_expiration.enforcement_enabled' => false]);
    $import = newInImport();
    $product = Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/OFF',
        'title' => 'Off',
        'handle' => 'off',
        'tags' => 'new-in, sale',
        'status' => 'active',
        'shopify_created_at' => CarbonImmutable::now('UTC')->subDays(90),
    ]);

    expect(app(NewInTagExpirationService::class)->filterOutboundTags($product, ['new-in', 'sale']))
        ->toBe(['new-in', 'sale']);
});

it('rejects missing products connection nodes during candidate discovery', function (): void {
    Http::fake(fn () => Http::response(['data' => [
        'products' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ],
    ]]));

    $generator = app(NewInTagExpirationService::class)->searchEligibleProducts(
        'new-in',
        CarbonImmutable::now('UTC')->subDays(75)->format('Y-m-d\TH:i:s\Z'),
    );

    expect(fn () => iterator_to_array($generator))
        ->toThrow(RuntimeException::class, 'omitted nodes');
});

it('rejects null products connection nodes during candidate discovery', function (): void {
    Http::fake(fn () => Http::response(json_encode([
        'data' => [
            'products' => [
                'nodes' => null,
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']));

    $generator = app(NewInTagExpirationService::class)->searchEligibleProducts(
        'new-in',
        CarbonImmutable::now('UTC')->subDays(75)->format('Y-m-d\TH:i:s\Z'),
    );

    expect(fn () => iterator_to_array($generator))
        ->toThrow(RuntimeException::class, 'nodes was null');
});

it('keeps an empty tags array when withGuardedTagMutation strips every New In tag', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z');
    $lookups = [];
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        [],
        [
            'empty' => [
                'id' => 'gid://shopify/Product/EMPTYTAGS',
                'createdAt' => $created,
                'tags' => ['new-in'],
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        false,
        null,
        $lookups,
    );

    $captured = null;
    app(NewInTagExpirationService::class)->withGuardedTagMutation(
        'gid://shopify/Product/EMPTYTAGS',
        ['new-in'],
        function (array $filtered) use (&$captured): string {
            $captured = $filtered;

            return 'ok';
        },
    );

    expect($captured)->toBe([])
        ->and($lookups)->toContain('gid://shopify/Product/EMPTYTAGS');
});

it('leaves Shop Your Vibe reconcile failures recoverable instead of confirmed', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(80)->format('Y-m-d\TH:i:s\Z');
    $mutations = [];
    fakeNewInExpirationShopify(
        ['new-in'],
        [
            'new-in' => [
                'id' => 'gid://shopify/Product/SYVFAIL',
                'createdAt' => $created,
                'tags' => ['new-in', 'bracelets'],
                'handle' => 'syv-fail',
            ],
        ],
        $mutations,
    );

    Queue::fake();

    $import = newInImport();
    Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/SYVFAIL',
        'title' => 'SYV Fail',
        'handle' => 'syv-fail',
        'tags' => 'new-in, bracelets',
        'status' => 'active',
        'shopify_created_at' => $created,
    ]);

    $service = new class(app(\App\Services\ShopifyApiClient::class), app(NewInTagClassifier::class), app(\App\Services\NewInProductAgeResolver::class), app(NewInExpirationLock::class)) extends NewInTagExpirationService
    {
        public function reconcileShopYourVibeMembership(string $productGid, array $before, array $after, ?array $collections = null): void
        {
            throw new RuntimeException('SYV membership reconcile failed');
        }
    };

    $summary = $service->run(dryRun: false, mutate: true);
    expect($summary['removed'])->toBe(1)
        ->and($summary['local_sync_failed'])->toBe(1)
        ->and(NewInExpirationOperation::query()->where('status', NewInExpirationOperation::STATUS_REMOTE_CONFIRMED)->count())->toBe(1)
        ->and(NewInExpirationOperation::query()->where('status', NewInExpirationOperation::STATUS_CONFIRMED)->count())->toBe(0);

    // Recovery with working SYV completes the operation.
    $status = app(NewInTagExpirationService::class)->recoverOperation(
        NewInExpirationOperation::query()->where('product_gid', 'gid://shopify/Product/SYVFAIL')->firstOrFail()
    );
    expect($status)->toBe(NewInExpirationOperation::STATUS_CONFIRMED);
});

it('refuses New In assignment for draft-only Shopify GIDs past the window', function (): void {
    $created = CarbonImmutable::now('UTC')->subDays(90)->format('Y-m-d\TH:i:s\Z');
    $lookups = [];
    $mutations = [];
    $fail = false;
    fakeNewInExpirationShopify(
        [],
        [
            'draftonly' => [
                'id' => 'gid://shopify/Product/DRAFTONLY',
                'createdAt' => $created,
                'tags' => ['bracelets'],
            ],
        ],
        $mutations,
        $fail,
        false,
        false,
        false,
        null,
        $lookups,
    );

    $user = User::factory()->create();
    $draft = NewProductDraft::create([
        'created_by' => $user->id,
        'shopify_id' => 'gid://shopify/Product/DRAFTONLY',
        'title' => 'Draft Only',
        'handle' => 'draft-only',
        'sku' => 'DRAFT-ONLY-1',
        'tags' => 'bracelets',
        'status' => 'draft',
        'type' => 'Bracelet',
    ]);

    $result = app(NewInTagService::class)->markDrafts([$draft]);
    expect($result['updated'] + $result['already_marked'])->toBe(1)
        ->and(TagNormalizer::parseTokens($draft->fresh()->tags))->not->toContain('new-in', 'newbies', 'new-arrivals')
        ->and($lookups)->toContain('gid://shopify/Product/DRAFTONLY');
});

it('stops unsafe work when the execution lock lease is no longer owned', function (): void {
    $now = \Illuminate\Support\Carbon::parse('2026-11-24 12:00:00', 'UTC');
    \Illuminate\Support\Carbon::setTestNow($now);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));

    $lock = app(NewInExpirationLock::class)->acquireExecution(1);

    \Illuminate\Support\Carbon::setTestNow($now->copy()->addSeconds(2));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 12:00:02', 'UTC'));

    expect(fn () => app(NewInExpirationLock::class)->assertOwned($lock, 'New In expiration'))
        ->toThrow(RuntimeException::class, 'lock lease expired');

    // Successor can acquire after expiry; old owner must not release it.
    $successor = app(NewInExpirationLock::class)->acquireExecution(30);
    app(NewInExpirationLock::class)->release($lock);
    expect(fn () => app(NewInExpirationLock::class)->acquireExecution(1))
        ->toThrow(RuntimeException::class, 'already in progress');
    app(NewInExpirationLock::class)->release($successor);

    \Illuminate\Support\Carbon::setTestNow();
});

it('guards actual handle-resolved productUpdate and productSet requests', function (): void {
    $gid = 'gid://shopify/Product/701';
    $createdAt = '2026-01-01T00:00:00Z';
    $payloads = [];
    Http::fake(function (Request $request) use ($gid, &$createdAt, &$payloads) {
        $query = $request['query'];
        if (str_contains($query, 'NewInProductCreatedAt')) {
            return Http::response(['data' => ['product' => ['id' => $gid, 'createdAt' => $createdAt]]]);
        }
        if (str_starts_with(trim($query), 'mutation')) {
            $field = str_contains($query, 'productSet(') ? 'productSet' : 'productUpdate';
            $input = $request['variables']['input'];
            if (array_key_exists('tags', $input)) {
                $locks = app(NewInExpirationLock::class);
                expect(fn () => $locks->acquireProduct($gid))->toThrow(RuntimeException::class, 'tag write is in progress');
            }
            $payloads[] = $input;

            return Http::response(['data' => [$field => ['userErrors' => [['message' => 'Stop after capturing payload']]]]]);
        }

        return Http::response(['data' => ['productByHandle' => ['id' => $gid, 'variants' => ['nodes' => []]]]]);
    });

    $product = new Product(['handle' => 'resolved-by-handle', 'tags' => 'new-in, sale']);
    $update = new ReflectionMethod(ProductShopifyUpdater::class, 'updateProduct');
    foreach (['2026-01-01T00:00:00Z', 'yesterday', '2026-11-23T00:00:00Z'] as $createdAt) {
        expect(fn () => $update->invoke(app(ProductShopifyUpdater::class), $product, ['product'], ['tags']))
            ->toThrow(RuntimeException::class, 'Stop after capturing payload');
    }
    expect(array_column($payloads, 'tags'))->toBe([['sale'], ['sale'], ['new-in', 'sale']])
        ->and(array_column($payloads, 'id'))->toBe([$gid, $gid, $gid]);

    $draft = new NewProductDraft(['handle' => 'resolved-by-handle', 'title' => 'Draft', 'tags' => 'new-in']);
    $updateDraft = new ReflectionMethod(NewProductDraftShopifyCreator::class, 'updateUnlistedProduct');
    $createdAt = '2026-01-01T00:00:00Z';
    $updateDraft->invoke(app(NewProductDraftShopifyCreator::class), $draft);
    expect($payloads[3]['id'])->toBe($gid)->and($payloads[3]['tags'])->toBe([]);

    $draft->tags = null;
    $updateDraft->invoke(app(NewProductDraftShopifyCreator::class), $draft);
    expect($payloads[4])->not->toHaveKey('tags');
});

it('does not start an outbound Shopify request with insufficient lease time', function (): void {
    $now = \Illuminate\Support\Carbon::parse('2026-11-24T12:00:00Z');
    \Illuminate\Support\Carbon::setTestNow($now);
    try {
        app(NewInTagExpirationService::class)->withGuardedTagMutation(
            'gid://shopify/Product/701',
            ['sale'],
            function (array $tags, callable $beforeRequest) use ($now): void {
                \Illuminate\Support\Carbon::setTestNow($now->copy()->addSeconds(150));
                expect($beforeRequest)->toThrow(RuntimeException::class, 'insufficient time');
            },
        );
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
});

it('rechecks a stale product payload on category fallback when the age crosses the cutoff', function (): void {
    $gid = 'gid://shopify/Product/701';
    $now = CarbonImmutable::now('UTC');
    $createdAt = $now->subDays(75)->addSeconds(5)->format('Y-m-d\TH:i:s\Z');
    $payloads = [];
    Http::fake(function (Request $request) use ($gid, $now, $createdAt, &$payloads) {
        if ($request->method() === 'PUT') {
            CarbonImmutable::setTestNow($now->addSeconds(10));

            return Http::response(['product' => ['id' => 701]]);
        }
        if (str_contains($request['query'], 'NewInProductCreatedAt')) {
            return Http::response(['data' => ['product' => ['id' => $gid, 'createdAt' => $createdAt]]]);
        }
        $input = $request['variables']['input'];
        $payloads[] = $input;
        $message = isset($input['category']) ? 'Field is not defined on ProductInput' : 'Stop after fallback';

        return Http::response(['data' => ['productUpdate' => ['userErrors' => [['message' => $message]]]]]);
    });
    $product = new Product([
        'shopify_id' => $gid, 'handle' => 'fallback', 'tags' => 'new-in, sale',
        'product_category' => 'gid://shopify/TaxonomyCategory/aa-1',
    ]);
    $update = new ReflectionMethod(ProductShopifyUpdater::class, 'updateProduct');
    expect(fn () => $update->invoke(app(ProductShopifyUpdater::class), $product, ['product'], ['tags', 'category']))
        ->toThrow(RuntimeException::class, 'Stop after fallback');
    expect(array_column($payloads, 'tags'))->toBe([['new-in', 'sale'], ['sale']])
        ->and($payloads[0])->toHaveKey('category')
        ->and($payloads[1])->not->toHaveKey('category');
});

it('recovers an uncertain successful removal after queue dispatch fails', function (): void {
    config(['new_in_expiration.queue_connection' => 'missing-expiration-queue']);
    $gid = 'gid://shopify/Product/701';
    $draftId = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'title' => 'Interrupted draft', 'shopify_id' => $gid, 'tags' => 'new-in, Sale!',
    ])->id);
    $removed = false;
    $mutationCount = 0;
    Http::fake(function (Request $request) use ($gid, &$removed, &$mutationCount) {
        if (str_contains($request['query'], 'mutation RemoveNewInTags')) {
            expect(NewInExpirationOperation::sole()->status)->toBe(NewInExpirationOperation::STATUS_MUTATING);
            $mutationCount++;
            $removed = true;

            throw new \Illuminate\Http\Client\ConnectionException('Response lost after Shopify accepted the write');
        }
        expect($request['query'])->toContain('ReadProductForNewInExpiration');
        return Http::response(['data' => ['product' => [
            'id' => $gid, 'createdAt' => '2026-01-01T00:00:00Z',
            'tags' => $removed ? ['Sale!'] : ['new-in', 'Sale!'],
        ]]]);
    });
    $service = app(NewInTagExpirationService::class);
    $candidate = $service->evaluateCandidate([
        'id' => $gid, 'createdAt' => '2026-01-01T00:00:00Z', 'tags' => ['new-in', 'Sale!'],
    ], $service->cutoff());
    $operation = $service->executeRemoval($candidate)['operation'];
    expect($operation->status)->toBe(NewInExpirationOperation::STATUS_REMOTE_CONFIRMED)
        ->and($operation->last_error)->toContain('Queue dispatch failed');

    $this->artisan('products:expire-new-in-tags', ['--recover-local' => true])->assertSuccessful();
    expect($mutationCount)->toBe(1)
        ->and($operation->fresh()->status)->toBe(NewInExpirationOperation::STATUS_CONFIRMED)
        ->and(NewProductDraft::findOrFail($draftId)->tags)->toBe('Sale!');
});

it('validates every products page before sending a removal', function (bool $malformed): void {
    $pages = [];
    Http::fake(function (Request $request) use (&$pages, $malformed) {
        if (str_contains($request['query'], 'DiscoverProductTags')) {
            return Http::response(['data' => ['productTags' => [
                'nodes' => ['Special (Edition): New In'],
                'pageInfo' => ['hasNextPage' => false],
            ]]]);
        }

        expect($request['query'])->toContain('ExpireNewInCandidates');
        expect($request['variables']['query'])->toContain('Special \\(Edition\\)\\: New In');
        $cursor = $request['variables']['after'];
        $pages[] = $cursor;
        $page = ['pageInfo' => ['hasNextPage' => $cursor === null, 'endCursor' => 'page-2']];
        if ($cursor === null || ! $malformed) {
            $page['nodes'] = [[
                'id' => 'gid://shopify/Product/'.($cursor === null ? '701' : '702'),
                'createdAt' => '2026-01-01T00:00:00Z',
                'tags' => ['Special (Edition): New In'],
            ]];
        }

        return Http::response(['data' => ['products' => $page]]);
    });

    $service = app(NewInTagExpirationService::class);
    if ($malformed) {
        expect(fn () => $service->run(dryRun: false, mutate: true))->toThrow(RuntimeException::class, 'omitted nodes');
    } else {
        expect($service->run()['eligible'])->toBe(2);
    }
    expect($pages)->toBe([null, 'page-2'])->and(NewInExpirationOperation::count())->toBe(0);
})->with([true, false]);

it('does not mutate a young product during recovery or accept a mutating dry-run combination', function (): void {
    $gid = 'gid://shopify/Product/701';
    $op = NewInExpirationOperation::create([
        'product_gid' => $gid, 'active_product_gid' => $gid,
        'status' => NewInExpirationOperation::STATUS_PENDING, 'tags_to_remove' => ['new-in'],
    ]);
    Http::fake(function (Request $request) use ($gid) {
        expect($request['query'])->toContain('ReadProductForNewInExpiration');

        return Http::response(['data' => ['product' => [
            'id' => $gid, 'tags' => ['new-in'], 'createdAt' => '2026-11-23T00:00:00Z',
        ]]]);
    });
    $this->artisan('products:expire-new-in-tags', ['--recover-local' => true, '--dry-run' => true, '--force' => true])
        ->assertFailed();
    Http::assertNothingSent();

    expect(app(NewInTagExpirationService::class)->recoverOperation($op))->toBe(NewInExpirationOperation::STATUS_NEEDS_REVIEW)
        ->and($op->fresh()->active_product_gid)->toBe($gid);
    Http::assertSentCount(1);
});

it('rejects Shopify search warnings before any cleanup mutation', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request['query'], 'DiscoverProductTags')) {
            return Http::response(['data' => ['productTags' => ['nodes' => ['new-in'], 'pageInfo' => ['hasNextPage' => false]]]]);
        }
        expect($request['query'])->toContain('ExpireNewInCandidates');

        return Http::response([
            'data' => ['products' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]],
            'extensions' => ['search' => [['warnings' => [['message' => 'Invalid status filter']]]]],
        ]);
    });
    expect(fn () => app(NewInTagExpirationService::class)->run(dryRun: false, mutate: true))
        ->toThrow(RuntimeException::class, 'Invalid status filter');
    Http::assertSentCount(2);
});

it('blocks expired New In additions from Shop Your Vibe and permits unrelated additions', function (): void {
    $gid = 'gid://shopify/Product/701';
    $added = [];
    Http::fake(function (Request $request) use ($gid, &$added) {
        if (str_contains($request['query'], 'NewInProductCreatedAt')) {
            return Http::response(['data' => ['product' => ['id' => $gid, 'createdAt' => '2026-01-01T00:00:00Z']]]);
        }
        expect($request['query'])->toContain('VibeTagsAdd');
        $added[] = $request['variables']['tags'];

        return Http::response(['data' => ['tagsAdd' => ['node' => ['id' => $gid], 'userErrors' => []]]]);
    });

    $shopify = app(\App\Services\ShopYourVibeShopify::class);
    expect(fn () => $shopify->addProductTags($gid, ['new-in']))->toThrow(RuntimeException::class, 'verified Shopify creation date');
    $shopify->addProductTags($gid, ['sale']);
    expect($added)->toBe([['sale']]);
});

it('allows only New In removal through the engraved-bead write guard', function (): void {
    config(['engraved_beads.inventory_writes_enabled' => false]);
    $gid = 'gid://shopify/Product/701';
    Product::withoutEvents(fn () => Product::create([
        'import_id' => newInImport()->id, 'shopify_id' => $gid, 'title' => 'Engraved bracelet',
        'handle' => 'engraved-bracelet', 'tags' => 'new-in, 2-engraved-beads',
    ]));
    Http::fake(function (Request $request) use ($gid) {
        expect($request['variables'])->toBe(['id' => $gid, 'tags' => ['new-in']]);

        return Http::response(['data' => ['tagsRemove' => [
            'node' => ['id' => $gid, 'tags' => ['2-engraved-beads']], 'userErrors' => [],
        ]]]);
    });

    expect(app(NewInTagExpirationService::class)->removeTagsOnShopify($gid, ['new-in'])['tags'])
        ->toBe(['2-engraved-beads']);
    expect(fn () => app(\App\Services\ShopifyApiClient::class)->graphql(
        NewInTagExpirationService::REMOVE_TAGS_MUTATION, ['id' => $gid, 'tags' => ['2-engraved-beads']]
    ))->toThrow(RuntimeException::class, 'Engraved bead Shopify mutations are disabled');
    Http::assertSentCount(1);
});

