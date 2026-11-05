<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\SendSyvLaunchSortWarningJob;
use App\Jobs\SendSyvSortApprovalEmailJob;
use App\Mail\SyvLaunchSortWarningMail;
use App\Mail\SyvSortApprovalMail;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductLaunchItem;
use App\Models\ProductLaunchRun;
use App\Models\ProductTagProvenance;
use App\Models\ShopifyCollection;
use App\Models\SyvCollectionSortEvent;
use App\Models\SyvCollectionSortState;
use App\Models\SyvNotification;
use App\Models\SyvVerifiedMembership;
use App\Models\User;
use App\Services\ProductTagProvenanceService;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeSorting;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    config()->set('shop_your_vibe.launch_sort_notify_to', ['launch-sort@example.com']);
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->cait = User::factory()->create(['name' => 'Cait', 'email' => 'cait@example.com', 'is_active' => true]);
    $this->leanne = User::factory()->create(['name' => 'Leanne', 'email' => 'leanne@example.com', 'is_active' => true]);
    $this->shona = User::factory()->create(['name' => 'Shona', 'email' => 'shona@example.com', 'is_active' => true]);
    $this->cait->assignRole(RolesEnum::Admin->value);
    $this->leanne->assignRole(RolesEnum::Admin->value);
    $this->shona->assignRole(RolesEnum::Admin->value);
    $import = Import::create(['filename' => 'syv-sort', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->cait->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle'],
        ]));
    }
    foreach ([101, 102, 103] as $id) {
        Product::withoutEvents(fn () => Product::create([
            'import_id' => $import->id,
            'shopify_id' => 'gid://shopify/Product/'.$id,
            'handle' => 'product-'.$id,
            'title' => 'Product '.$id,
            'tags' => 'necklaces',
        ]));
    }
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->sorting = app(ShopYourVibeSorting::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->moved = 'gid://shopify/Product/101';
});

function sortingLock($test, User $user): void
{
    $test->actingAs($user);
    $editing = app(ShopYourVibeEditing::class);
    $editing->token = null;
    $test->draft = $editing->acquire($test->draft->id, $test->draft->fresh()->revision)->fresh();
}

function sortingMove($test, string $explicit): void
{
    $test->draft = $test->draft->fresh();
    $gid = 'gid://shopify/Collection/1';
    $ids = array_column($test->draft->desired['collections'][$gid]['products'], 'id');
    $from = array_search($explicit, $ids, true);
    $next = $ids;
    $item = array_splice($next, $from, 1)[0];
    array_splice($next, min($from + 1, count($next)), 0, [$item]);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $gid,
        'ids' => $next,
        'explicit_ids' => [$explicit],
    ])->fresh();
}

it('records only the explicitly moved product and keeps that highlight through refresh and handover', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);

    $rows = SyvCollectionSortState::query()->where('draft_id', $this->draft->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->product_key)->toBe($this->moved)
        ->and($rows->first()->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and($rows->first()->highlight)->toBeTrue()
        ->and($rows->first()->sorted_by)->toBe($this->cait->id)
        ->and(SyvCollectionSortState::query()->where('collection_gid', 'gid://shopify/Collection/2')->count())->toBe(0);

    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class);
    $this->sorting->replaceMembership($this->draft, 'gid://shopify/Collection/1', [
        'gid://shopify/Product/101', 'gid://shopify/Product/102', 'gid://shopify/Product/103',
    ], 'Necklaces');
    expect(SyvCollectionSortState::query()->where('highlight', true)->count())->toBe(1);

    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();
    $state = $this->draft->editing_state;
    expect($state['owner_id'] ?? null)->toBeNull()
        ->and($state['pending_owner_id'])->toBe($this->leanne->id)
        ->and($state['review']['submitted_by'])->toBe($this->cait->id)
        ->and(SyvCollectionSortState::query()->first()->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and(SyvCollectionSortState::query()->first()->highlight)->toBeTrue()
        ->and(SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->where('actor_id', $this->cait->id)->count())->toBe(1);
});

it('lets the reviewer correct the submitted revision without discarding the original sort', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();

    sortingLock($this, $this->leanne);
    sortingMove($this, 'gid://shopify/Product/102');

    $caitEvent = SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->where('actor_id', $this->cait->id)->first();
    $leanneEvent = SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->where('actor_id', $this->leanne->id)->first();
    expect($caitEvent)->not->toBeNull()
        ->and($leanneEvent)->not->toBeNull()
        ->and($leanneEvent->product_key)->toBe('gid://shopify/Product/102')
        ->and(SyvCollectionSortState::query()->where('product_key', 'gid://shopify/Product/102')->first()->status)
        ->toBe(SyvCollectionSortState::AWAITING);

    $this->draft = $this->sorting->approve($this->draft->id, $this->draft->fresh()->revision)->fresh();
    expect(SyvCollectionSortState::query()->whereNull('approved_at')->where('status', SyvCollectionSortState::AWAITING)->count())->toBe(0);

    sortingMove($this, 'gid://shopify/Product/103');
    expect(SyvCollectionSortState::query()->whereNotNull('approved_at')->count())->toBe(0)
        ->and(SyvCollectionSortEvent::query()->where('type', 'approval_invalidated')->count())->toBeGreaterThan(0);
});

it('blocks an unapproved push and keeps approval when Shopify rejects the reorder', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $gid = 'gid://shopify/Collection/1';
    $scope = ShopYourVibeEditing::sortScope($gid);
    expect(fn () => $this->workflow->queue($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'eligible non-sorting');

    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, 0, $scope)->fresh();
    $token = app(ShopYourVibeEditing::class)->token;
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    app(ShopYourVibeEditing::class)->token = $token;
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $gid, $revision)->fresh();
    $released = $this->draft->editing_state;
    foreach (['owner_id', 'owner_name', 'token_hash', 'expires_at'] as $key) {
        $released[$key] = null;
    }
    $this->draft->update(['editing_state' => $released]);
    $this->draft->refresh();
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => $this->workflow->queue($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'eligible non-sorting');

    $this->actingAs($this->leanne);
    expect(fn () => $this->sorting->assertPushAllowed($this->draft->fresh(), $gid))
        ->toThrow(RuntimeException::class, 'approve this exact revision');

    $this->draft = $this->sorting->approveScope($this->draft->id, $gid, $revision)->fresh();
    $this->actingAs($this->shona);
    expect(fn () => $this->workflow->queue($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'eligible non-sorting');
    expect(fn () => $this->sorting->assertPushAllowed($this->draft->fresh(), $gid))
        ->toThrow(RuntimeException::class, 'Only the assigned reviewer');

    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $revision, $scope)->fresh();
    $this->fake->failProductReorder = true;
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $revision, $gid);
    $this->workflow->push($queued->id, $ticket);
    $this->draft->refresh();
    $row = SyvCollectionSortState::query()->first();
    expect($this->draft->pending)->toBeTrue()
        ->and($row->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and($row->approved_at)->not->toBeNull()
        ->and($row->shopify_confirmed_at)->toBeNull()
        ->and(SyvCollectionSortState::label($row->status))->toBe('Awaiting approval');
});

it('publishes sorting only after the approved revision is confirmed by Shopify', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $gid = 'gid://shopify/Collection/1';
    $scope = ShopYourVibeEditing::sortScope($gid);
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, 0, $scope)->fresh();
    $token = app(ShopYourVibeEditing::class)->token;
    $revision = (int) $this->draft->editing_state['locks'][$scope]['revision'];
    app(ShopYourVibeEditing::class)->token = $token;
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id, $gid, $revision)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->approveScope($this->draft->id, $gid, $revision)->fresh();
    $approvedAt = SyvCollectionSortState::query()->first()->approved_at;
    $released = $this->draft->editing_state;
    foreach (['owner_id', 'owner_name', 'token_hash', 'expires_at'] as $key) {
        $released[$key] = null;
    }
    $this->draft->update(['editing_state' => $released]);
    $this->draft->refresh();
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => $this->workflow->queue($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'eligible non-sorting');
    expect(SyvCollectionSortState::query()->first()->status)->toBe(SyvCollectionSortState::AWAITING);

    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $revision, $scope)->fresh();
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $revision, $gid);
    $this->workflow->push($queued->id, $ticket);
    $this->draft->refresh();
    $row = SyvCollectionSortState::query()->first()->fresh();
    expect($row->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and($row->shopify_confirmed_at)->not->toBeNull()
        ->and($row->approved_at->eq($approvedAt))->toBeTrue()
        ->and(SyvCollectionSortEvent::query()->where('type', 'shopify_confirmed')->count())->toBe(1)
        ->and(SyvCollectionSortState::label($row->status))->toBe('Approved & published');
});

it('sends one approval email for the submitted revision and retries a mail failure without a second submission', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();

    expect(SyvNotification::query()->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'syv-notifications')->count())->toBe(1);
    $notification = SyvNotification::query()->first();
    expect($notification->idempotency_key)->toStartWith('sort-approval:'.$this->draft->id.':')
        ->and($notification->payload['submitter_name'])->toBe('Cait')
        ->and($notification->payload['collection_name'])->toBe('Necklaces')
        ->and($notification->payload['moved_count'])->toBe(1)
        ->and($notification->payload['status'])->toBe('Awaiting approval')
        ->and($notification->payload['review_url'])->toContain('/shop-your-vibe?collection=');

    $failMail = true;
    view()->composer('emails.syv-sort-approval', function () use (&$failMail): void {
        if ($failMail) {
            throw new RuntimeException('smtp down');
        }
    });
    expect(fn () => (new SendSyvSortApprovalEmailJob($notification->id))->handle())->toThrow(RuntimeException::class, 'smtp down');
    expect($this->draft->fresh()->pending)->toBeTrue()
        ->and(SyvNotification::query()->count())->toBe(1)
        ->and(SyvCollectionSortEvent::query()->where('type', 'submitted')->count())->toBe(1)
        ->and($notification->fresh()->status)->toBe(SyvNotification::FAILED);

    $failMail = false;
    Mail::fake();
    (new SendSyvSortApprovalEmailJob($notification->id))->handle();
    (new SendSyvSortApprovalEmailJob($notification->id))->handle();
    Mail::assertSent(SyvSortApprovalMail::class, 1);
    Mail::assertSent(SyvSortApprovalMail::class, function (SyvSortApprovalMail $mail): bool {
        return $mail->hasTo('leanne@example.com')
            && $mail->details['submitter_name'] === 'Cait'
            && $mail->details['status'] === 'Awaiting approval';
    });
    expect(SyvNotification::query()->first()->status)->toBe(SyvNotification::SENT);
});

it('lets only the assigned reviewer discard a submitted revision', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();
    $this->actingAs($this->cait);
    expect(fn () => app(ShopYourVibeEditing::class)->discard($this->draft->id, $this->draft->revision, 'Cait tried to discard this submitted revision'))
        ->toThrow(RuntimeException::class, 'assigned reviewer');

    $this->actingAs($this->leanne);
    app(ShopYourVibeEditing::class)->discard($this->draft->id, $this->draft->revision, 'Leanne discarded the submitted revision after review');
    expect(SyvCollectionSortState::query()->where('highlight', true)->count())->toBe(0)
        ->and(SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->count())->toBe(1)
        ->and(SyvCollectionSortEvent::query()->where('type', 'discarded')->where('actor_id', $this->leanne->id)->count())->toBe(1);
});

it('warns configured recipients when a launched product is still unsorted and does not republish it', function () {
    $product = Product::query()->where('shopify_id', $this->moved)->first();
    $run = ProductLaunchRun::query()->create([
        'user_id' => $this->leanne->id,
        'status' => ProductLaunchRun::STATUS_COMPLETED,
        'publication_ids' => ['gid://shopify/Publication/1'],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $item = ProductLaunchItem::query()->create([
        'product_launch_run_id' => $run->id,
        'product_id' => $product->id,
        'product_title' => $product->title,
        'sku' => 'SKU-101',
        'validation' => ProductLaunchItem::VALIDATION_READY,
        'outcome' => ProductLaunchItem::OUTCOME_LAUNCHED,
        'status_confirmed_at' => now(),
    ]);
    $before = $this->fake->calls;
    $this->sorting->recordLaunchWarning($item);
    $this->sorting->recordLaunchWarning($item->fresh());
    expect(SyvNotification::query()->where('type', SyvNotification::TYPE_LAUNCH_WARNING)->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'syv-notifications')->count())->toBe(1)
        ->and($item->fresh()->outcome)->toBe(ProductLaunchItem::OUTCOME_LAUNCHED)
        ->and($this->fake->calls)->toBe($before);

    $notification = SyvNotification::query()->first();
    Mail::fake();
    (new SendSyvLaunchSortWarningJob($notification->id))->handle($this->sorting);
    (new SendSyvLaunchSortWarningJob($notification->id))->handle($this->sorting);
    Mail::assertSent(SyvLaunchSortWarningMail::class, 1);
    Mail::assertSent(SyvLaunchSortWarningMail::class, function (SyvLaunchSortWarningMail $mail): bool {
        return $mail->hasTo('launch-sort@example.com')
            && $mail->details['sku'] === 'SKU-101'
            && $mail->details['launch_actor'] === 'Leanne'
            && collect($mail->details['collections'])->pluck('status')->contains('Not sorted');
    });
    expect($item->fresh()->outcome)->toBe(ProductLaunchItem::OUTCOME_LAUNCHED);
});

it('keeps Shopify-cached tags distinct from planned draft tags and does not treat a tag as membership', function () {
    $before = SyvVerifiedMembership::query()->count();
    $draft = NewProductDraft::query()->create([
        'handle' => 'planned-bracelet',
        'sku' => 'PLAN-1',
        'title' => 'Planned Bracelet',
        'type' => 'Bracelets',
        'status' => 'DRAFT',
        'published' => false,
        'tags' => 'necklaces',
    ]);
    $provenance = app(ProductTagProvenanceService::class);
    expect($provenance->sourceFor($draft, 'necklaces'))->toBe(ProductTagProvenance::PLANNED)
        ->and($provenance->sourceFor(Product::query()->where('shopify_id', $this->moved)->first(), 'necklaces'))->toBe(ProductTagProvenance::SHOPIFY)
        ->and(SyvVerifiedMembership::query()->count())->toBe($before);
});

it('releases the submitter lock immediately and lets the reviewer open the request', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $order = array_column($this->draft->desired['collections']['gid://shopify/Collection/1']['products'], 'id');
    $caitToken = app(ShopYourVibeEditing::class)->token;
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();

    expect(app(ShopYourVibeEditing::class)->active($this->draft->editing_state))->toBeFalse()
        ->and($this->draft->editing_state['owner_id'] ?? null)->toBeNull()
        ->and($this->draft->editing_state['token_hash'] ?? null)->toBeNull()
        ->and($this->draft->editing_state['pending_owner_id'])->toBe($this->leanne->id);
    app(ShopYourVibeEditing::class)->token = $caitToken;
    expect(fn () => app(ShopYourVibeEditing::class)->authorize($this->draft, $this->draft->revision))
        ->toThrow(RuntimeException::class);

    $this->actingAs($this->leanne);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', 'gid://shopify/Collection/1');
    $page->assertSee('Sorting approval request')
        ->assertSee('Submitted by')
        ->assertSee('Assigned reviewer')
        ->assertSee('Cait')
        ->assertSee('Leanne')
        ->assertSee('Product 101')
        ->assertSee('Awaiting approval')
        ->assertSee('Review / Edit')
        ->assertSee('Approve & Publish Sorting')
        ->assertSee('Request Changes');

    $page->call('reviewSorting');
    $locked = $this->draft->fresh();
    $scope = ShopYourVibeEditing::sortScope('gid://shopify/Collection/1');
    expect($locked->editing_state['locks'][$scope]['owner_id'])->toBe($this->leanne->id)
        ->and(app(ShopYourVibeEditing::class)->active($locked->editing_state['locks'][$scope]))->toBeTrue()
        ->and(array_column($locked->desired['collections']['gid://shopify/Collection/1']['products'], 'id'))->toBe($order);
});

it('lets the reviewer approve the unchanged revision without calling Shopify', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();
    $this->actingAs($this->leanne);
    $before = $this->fake->calls;
    $this->draft = $this->sorting->approve($this->draft->id, $this->draft->revision)->fresh();
    $row = SyvCollectionSortState::query()->first();

    expect($row->approved_by)->toBe($this->leanne->id)
        ->and($row->approved_at)->not->toBeNull()
        ->and($row->approved_revision)->toBe($this->draft->revision)
        ->and($row->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and($row->shopify_confirmed_at)->toBeNull()
        ->and($this->fake->calls)->toBe($before);
});

it('returns a revision to the original sorter with feedback and without discarding the sort', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $order = array_column($this->draft->desired['collections']['gid://shopify/Collection/1']['products'], 'id');
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();
    $this->actingAs($this->leanne);
    $this->draft = $this->sorting->requestChanges($this->draft->id, $this->draft->revision, 'Place Product 101 after Product 102.')->fresh();
    $row = SyvCollectionSortState::query()->first();

    expect(array_column($this->draft->desired['collections']['gid://shopify/Collection/1']['products'], 'id'))->toBe($order)
        ->and($this->draft->editing_state['pending_owner_id'])->toBe($this->cait->id)
        ->and($this->draft->editing_state['review']['reviewer_id'])->toBe($this->leanne->id)
        ->and($row->reviewer_id)->toBe($this->leanne->id)
        ->and($this->draft->editing_state['review']['changes_requested_feedback'])->toBe('Place Product 101 after Product 102.')
        ->and(app(ShopYourVibeEditing::class)->active($this->draft->editing_state))->toBeFalse()
        ->and($row->status)->toBe(SyvCollectionSortState::NOT_SORTED)
        ->and($row->highlight)->toBeTrue()
        ->and(SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->where('actor_id', $this->cait->id)->count())->toBe(1)
        ->and(SyvCollectionSortEvent::query()->where('type', 'changes_requested')->where('actor_id', $this->leanne->id)->where('note', 'Place Product 101 after Product 102.')->count())->toBe(1);

    sortingLock($this, $this->cait);
    expect($this->draft->fresh()->editing_state['owner_id'])->toBe($this->cait->id);
});

it('rejects a stale revision and an unauthorized approval', function () {
    sortingLock($this, $this->cait);
    sortingMove($this, $this->moved);
    $snapshot = $this->draft->snapshot;
    $snapshot['collections']['gid://shopify/Collection/1']['products'] = array_values(array_filter(
        $snapshot['collections']['gid://shopify/Collection/1']['products'],
        fn (array $product): bool => $product['id'] !== $this->moved,
    ));
    $this->draft->update(['snapshot' => $snapshot]);
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, $this->leanne->id)->fresh();
    expect(collect($this->sorting->view($this->draft)['moves'])->firstWhere('product_key', $this->moved)['previous_label'])->toBe('New product');

    $this->actingAs($this->shona);
    expect(fn () => $this->sorting->approve($this->draft->id, $this->draft->revision))
        ->toThrow(RuntimeException::class, 'Only the assigned reviewer');

    $this->actingAs($this->leanne);
    expect(fn () => $this->sorting->approve($this->draft->id, $this->draft->revision - 1))
        ->toThrow(RuntimeException::class, 'This draft changed');
    expect(SyvCollectionSortState::query()->whereNotNull('approved_at')->count())->toBe(0);
});

it('lets the original sorter resume on the open page after changes are requested', function () {
    $gid = 'gid://shopify/Collection/1';
    $orderOf = fn () => array_column($this->draft->fresh()->desired['collections'][$gid]['products'], 'id');
    $shift = function (array $ids, string $product) {
        $from = array_search($product, $ids, true);
        $next = $ids;
        $item = array_splice($next, $from, 1)[0];
        array_splice($next, min($from + 1, count($next)), 0, [$item]);

        return $next;
    };

    $this->actingAs($this->cait);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $gid);
    $page->call('startEditing');
    $caitToken = $page->instance()->editingToken;
    $submittedOrder = $shift($orderOf(), $this->moved);
    $page->call('reorderProducts', $gid, $submittedOrder, 1, 'cait-original', $this->moved);
    expect($orderOf())->toBe($submittedOrder)->and($page->instance()->loadError)->toBeNull();

    $page->set('sortReviewerId', $this->leanne->id)->call('submitSorting');
    $submittedRevision = $page->instance()->revision;
    $stale = Livewire::test(ShopYourVibe::class)->call('manage', $gid);
    expect($stale->instance()->revision)->toBe($submittedRevision);

    $this->actingAs($this->leanne);
    $calls = $this->fake->calls;
    $leanne = Livewire::test(ShopYourVibe::class)->call('manage', $gid)->call('reviewSorting');
    $leanneToken = $leanne->instance()->editingToken;
    $leanne->set('sortChangeFeedback', 'Place Product 101 after Product 102.')->call('requestSortingChanges');

    $returned = $this->draft->fresh();
    $scope = ShopYourVibeEditing::sortScope($gid);
    $returnedLock = $returned->editing_state['locks'][$scope] ?? [];
    expect($orderOf())->toBe($submittedOrder)
        ->and($returnedLock['owner_id'] ?? null)->toBeNull()
        ->and($returnedLock['token_hash'] ?? null)->toBeNull()
        ->and(app(ShopYourVibeEditing::class)->active($returnedLock))->toBeFalse()
        ->and($returnedLock['pending_owner_id'])->toBe($this->cait->id)
        ->and($returnedLock['review']['changes_requested_feedback'])->toBe('Place Product 101 after Product 102.')
        ->and($returnedLock['review']['submitted_by'])->toBe($this->cait->id)
        ->and(array_values(array_filter($this->fake->calls, fn (array $call): bool => ! str_contains($call[0], 'query VibeMembership'))))
        ->toBe(array_values(array_filter($calls, fn (array $call): bool => ! str_contains($call[0], 'query VibeMembership'))));

    $this->actingAs($this->cait);
    $resumed = $shift($submittedOrder, 'gid://shopify/Product/102');
    $page->call('reorderProducts', $gid, $resumed, 1, 'cait-resume', 'gid://shopify/Product/102');
    $locked = $this->draft->fresh();
    $resumedToken = $page->instance()->editingToken;
    $resumedLock = $locked->editing_state['locks'][$scope] ?? [];
    expect($page->instance()->loadError)->toBeNull()
        ->and($orderOf())->toBe($resumed)
        ->and($resumedLock['owner_id'])->toBe($this->cait->id)
        ->and($resumedToken)->not->toBeNull()
        ->and($resumedToken)->not->toBe($caitToken)
        ->and(app(ShopYourVibeEditing::class)->active($resumedLock))->toBeTrue()
        ->and(now()->diffInSeconds($resumedLock['expires_at'], false))->toBeGreaterThan(800);

    $page->call('moveProductToPosition', $gid, 'gid://shopify/Product/103', 1, 2, 'cait-resume');
    $positioned = $orderOf();
    expect($page->instance()->loadError)->toBeNull()
        ->and($positioned[0])->toBe('gid://shopify/Product/103')
        ->and($positioned)->not->toBe($resumed);

    $page->call('reorderProducts', $gid, $submittedOrder, 1, 'cait-resume', $this->moved);
    expect($orderOf())->toBe($positioned)->and($page->instance()->loadError)->toBeNull();

    $recovered = $shift($positioned, 'gid://shopify/Product/102');
    $page->call('reorderProducts', $gid, $recovered, 3, 'cait-resume', 'gid://shopify/Product/102');
    expect($page->instance()->loadError)->toBeNull()->and($orderOf())->toBe($recovered);

    $editing = app(ShopYourVibeEditing::class);
    $currentRevision = (int) (($this->draft->fresh()->editing_state['locks'][$scope]['revision'] ?? 0));
    $editing->token = $caitToken;
    expect(fn () => $editing->authorizeScope($this->draft->fresh(), $scope))->toThrow(RuntimeException::class, 'another tab');
    $editing->token = $leanneToken;
    expect(fn () => $editing->authorizeScope($this->draft->fresh(), $scope))->toThrow(RuntimeException::class, 'another tab');

    $this->actingAs($this->shona);
    $editing->token = null;
    expect(fn () => $editing->acquireScope($this->draft->id, $currentRevision, $scope))->toThrow(RuntimeException::class, '14 minutes');

    $this->actingAs($this->cait);
    $stale->call('reorderProducts', $gid, $submittedOrder, 1, 'stale-tab', $this->moved);
    expect($stale->instance()->loadError)->toContain('Check this collection against current Shopify before sorting.')
        ->and($stale->instance()->revision)->toBe($submittedRevision)
        ->and($orderOf())->toBe($recovered);

    $page->set('sortReviewerId', $this->leanne->id)->call('submitSorting');
    expect($page->instance()->loadError)->toBeNull()
        ->and($this->draft->fresh()->editing_state['locks'][$scope]['pending_owner_id'])->toBe($this->leanne->id)
        ->and(app(ShopYourVibeEditing::class)->active($this->draft->fresh()->editing_state['locks'][$scope]))->toBeFalse();

    $this->actingAs($this->leanne);
    $approval = Livewire::test(ShopYourVibe::class)->call('manage', $gid);
    $approval->call('approveSorting');
    $row = SyvCollectionSortState::query()->where('product_key', $this->moved)->first();
    expect($approval->instance()->loadError)->toBeNull()
        ->and($row->approved_by)->toBe($this->leanne->id)
        ->and($row->approved_at)->not->toBeNull()
        ->and($row->highlight)->toBeTrue()
        ->and($orderOf())->toBe($recovered)
        ->and(array_values(array_filter($this->fake->calls, fn (array $call): bool => ! str_contains($call[0], 'query VibeMembership'))))
        ->toBe(array_values(array_filter($calls, fn (array $call): bool => ! str_contains($call[0], 'query VibeMembership'))))
        ->and(SyvCollectionSortEvent::query()->where('type', 'explicit_sort')->where('actor_id', $this->cait->id)->count())->toBeGreaterThan(1)
        ->and(SyvCollectionSortEvent::query()->where('type', 'changes_requested')->where('actor_id', $this->leanne->id)->where('note', 'Place Product 101 after Product 102.')->count())->toBe(1)
        ->and(SyvCollectionSortEvent::query()->where('type', 'approved')->where('actor_id', $this->leanne->id)->count())->toBeGreaterThan(0)
        ->and(SyvCollectionSortEvent::query()->where('type', 'submitted')->where('actor_id', $this->cait->id)->pluck('revision')->unique()->count())->toBe(2);
});
