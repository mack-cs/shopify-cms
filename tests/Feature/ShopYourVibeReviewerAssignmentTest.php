<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\SyvCollectionSortEvent;
use App\Models\SyvCollectionSortState;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeSorting;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->sorter = User::factory()->create(['name' => 'Sorter '.uniqid(), 'email' => 'sorter-'.uniqid().'@example.com', 'is_active' => true]);
    $this->reviewer = User::factory()->create(['name' => 'Reviewer '.uniqid(), 'email' => 'reviewer-'.uniqid().'@example.com', 'is_active' => true]);
    $this->other = User::factory()->create(['name' => 'Other '.uniqid(), 'email' => 'other-'.uniqid().'@example.com', 'is_active' => true]);
    foreach ([$this->sorter, $this->reviewer, $this->other] as $user) {
        $user->assignRole(RolesEnum::Admin->value);
    }
    $import = Import::create(['filename' => 'syv-reviewers', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->sorter->id, 'is_current' => true, 'is_valid' => true]);
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
        ]));
    }
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->sorting = app(ShopYourVibeSorting::class);
    $this->editing = app(ShopYourVibeEditing::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->gid = 'gid://shopify/Collection/1';
    $this->scope = ShopYourVibeEditing::sortScope($this->gid);
});

function reviewerOrder($draft, string $gid): array
{
    return array_column($draft->fresh()->desired['collections'][$gid]['products'], 'id');
}

function reviewerShift(array $ids, string $product): array
{
    $from = array_search($product, $ids, true);
    $next = $ids;
    $item = array_splice($next, $from, 1)[0];
    array_splice($next, min($from + 1, count($next)), 0, [$item]);

    return $next;
}

function reviewerSubmit($test, string $product = 'gid://shopify/Product/101'): array
{
    $test->actingAs($test->sorter);
    $editing = app(ShopYourVibeEditing::class);
    $revision = (int) ($test->draft->fresh()->editing_state['locks'][$test->scope]['revision'] ?? 0);
    $editing->token = $test->scopeToken ?? null;
    $test->draft = $editing->acquireScope($test->draft->id, $revision, $test->scope)->fresh();
    $order = reviewerShift(reviewerOrder($test->draft, $test->gid), $product);
    $test->draft = $test->workflow->edit($test->draft->id, $test->draft->revision, 'reorder_products', [
        'collection_gid' => $test->gid,
        'ids' => $order,
        'explicit_ids' => [$product],
        'scope_revision' => $revision,
    ])->fresh();
    $test->scopeToken = app(ShopYourVibeEditing::class)->token;
    $scopeRevision = (int) $test->draft->editing_state['locks'][$test->scope]['revision'];
    $test->draft = $test->sorting->submit($test->draft->id, $test->draft->revision, $test->reviewer->id, $test->gid, $scopeRevision)->fresh();

    return $order;
}

it('keeps the selected reviewer when changes are requested and the sorter resubmits', function () {
    $order = reviewerSubmit($this);
    $submitted = SyvCollectionSortEvent::query()->where('type', 'submitted')->count();
    $this->actingAs($this->reviewer);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$this->scope]['revision'];
    $this->draft = $this->sorting->requestScopeChanges($this->draft->id, $this->gid, $scopeRevision, 'Move the first product back.')->fresh();
    $lock = $this->draft->editing_state['locks'][$this->scope];
    $row = SyvCollectionSortState::query()->where('collection_gid', $this->gid)->first();

    expect($lock['pending_owner_id'])->toBe($this->sorter->id)
        ->and($lock['review']['reviewer_id'])->toBe($this->reviewer->id)
        ->and($lock['review']['reviewer_name'])->toBe($this->reviewer->name)
        ->and($row->reviewer_id)->toBe($this->reviewer->id)
        ->and($this->sorting->view($this->draft->fresh(), $this->gid)['reviewer_name'])->toBe($this->reviewer->name)
        ->and($this->sorting->view($this->draft, $this->gid)['request_status'])->toBe('Returned for changes')
        ->and(reviewerOrder($this->draft, $this->gid))->toBe($order);

    $this->actingAs($this->other);
    app(ShopYourVibeEditing::class)->token = null;
    expect(fn () => app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, (int) $lock['revision'], $this->scope))
        ->toThrow(RuntimeException::class, 'pending changes');

    $this->actingAs($this->sorter);
    app(ShopYourVibeEditing::class)->token = null;
    $returnedRevision = (int) $this->draft->fresh()->editing_state['locks'][$this->scope]['revision'];
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $returnedRevision, $this->scope)->fresh();
    $resubmitted = reviewerShift($order, 'gid://shopify/Product/102');
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', [
        'collection_gid' => $this->gid,
        'ids' => $resubmitted,
        'explicit_ids' => ['gid://shopify/Product/102'],
        'scope_revision' => $returnedRevision,
    ])->fresh();
    $resumeToken = app(ShopYourVibeEditing::class)->token;
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->gid);
    $page->call('beginSortSubmit');
    expect($page->instance()->sortReviewerId)->toBe($this->reviewer->id)
        ->and($page->html())->toContain($this->reviewer->name)
        ->and($page->html())->toContain('Returned for changes');
    $scopeRevision = (int) $this->draft->editing_state['locks'][$this->scope]['revision'];
    app(ShopYourVibeEditing::class)->token = $resumeToken;
    $this->draft = $this->sorting->submit($this->draft->id, $this->draft->revision, (int) $page->instance()->sortReviewerId, $this->gid, $scopeRevision)->fresh();

    expect($this->draft->editing_state['locks'][$this->scope]['review']['reviewer_id'])->toBe($this->reviewer->id)
        ->and(SyvCollectionSortState::query()->where('collection_gid', $this->gid)->first()->reviewer_id)->toBe($this->reviewer->id)
        ->and(SyvCollectionSortEvent::query()->where('type', 'submitted')->count())->toBeGreaterThan($submitted);

    $this->actingAs($this->other);
    expect(fn () => $this->sorting->approveScope($this->draft->id, $this->gid, $scopeRevision))
        ->toThrow(RuntimeException::class, 'Only the assigned reviewer');

    $this->actingAs($this->reviewer);
    $this->draft = $this->sorting->approveScope($this->draft->id, $this->gid, $scopeRevision)->fresh();
    app(ShopYourVibeEditing::class)->token = null;
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $this->scope)->fresh();
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $scopeRevision, $this->gid);
    $this->workflow->push($queued->id, $ticket);

    expect(SyvCollectionSortState::query()->where('collection_gid', $this->gid)->first()->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and($this->sorting->view($this->draft->fresh(), $this->gid)['reviewer_id'])->toBe($this->reviewer->id);
});

it('lets the assigned reviewer correct and publish without asking for another approval', function () {
    reviewerSubmit($this);
    $submitted = SyvCollectionSortEvent::query()->where('type', 'submitted')->count();
    $this->actingAs($this->reviewer);
    app(ShopYourVibeEditing::class)->token = null;
    $scopeRevision = (int) $this->draft->editing_state['locks'][$this->scope]['revision'];
    $this->draft = app(ShopYourVibeEditing::class)->acquireScope($this->draft->id, $scopeRevision, $this->scope)->fresh();
    $corrected = reviewerShift(reviewerOrder($this->draft, $this->gid), 'gid://shopify/Product/102');
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_products', [
        'collection_gid' => $this->gid,
        'ids' => $corrected,
        'explicit_ids' => ['gid://shopify/Product/102'],
        'scope_revision' => $scopeRevision,
    ])->fresh();
    $row = SyvCollectionSortState::query()->where('collection_gid', $this->gid)->where('product_key', 'gid://shopify/Product/102')->first();
    $correctedRevision = (int) $this->draft->editing_state['locks'][$this->scope]['revision'];

    expect($row->status)->toBe(SyvCollectionSortState::AWAITING)
        ->and($row->reviewer_id)->toBe($this->reviewer->id)
        ->and($row->approved_at)->toBeNull()
        ->and(SyvCollectionSortEvent::query()->where('type', 'submitted')->count())->toBe($submitted);

    $this->draft = $this->sorting->approveScope($this->draft->id, $this->gid, $correctedRevision)->fresh();
    [$queued, $ticket] = $this->workflow->queueCollection($this->draft->id, $correctedRevision, $this->gid);
    $this->workflow->push($queued->id, $ticket);

    expect(reviewerOrder($this->draft, $this->gid))->toBe($corrected)
        ->and(SyvCollectionSortState::query()->where('product_key', 'gid://shopify/Product/102')->first()->status)->toBe(SyvCollectionSortState::PUBLISHED)
        ->and(SyvCollectionSortEvent::query()->where('type', 'submitted')->count())->toBe($submitted);
});

it('keeps the reviewer id and name after a reload, a revision change, and a refused refresh', function () {
    reviewerSubmit($this);
    $this->actingAs($this->reviewer);
    $scopeRevision = (int) $this->draft->editing_state['locks'][$this->scope]['revision'];
    $this->draft = $this->sorting->requestScopeChanges($this->draft->id, $this->gid, $scopeRevision, 'Please check the first three products.')->fresh();
    $reloaded = $this->draft->fresh();
    $view = $this->sorting->view($reloaded, $this->gid);

    expect($reloaded->editing_state['locks'][$this->scope]['revision'])->toBeGreaterThan($scopeRevision)
        ->and($view['reviewer_id'])->toBe($this->reviewer->id)
        ->and($view['reviewer_name'])->toBe($this->reviewer->name)
        ->and($view['changes_feedback'])->toBe('Please check the first three products.');

    expect(fn () => $this->workflow->refresh($reloaded->id, $reloaded->revision))->toThrow(RuntimeException::class);
    $afterRefresh = $reloaded->fresh();
    expect($this->sorting->view($afterRefresh, $this->gid)['reviewer_id'])->toBe($this->reviewer->id)
        ->and($this->sorting->view($afterRefresh, $this->gid)['reviewer_name'])->toBe($this->reviewer->name)
        ->and($afterRefresh->editing_state['locks'][$this->scope]['pending_owner_id'])->toBe($this->sorter->id);
});
