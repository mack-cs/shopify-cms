<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\PushShopYourVibe;
use App\Models\ChangeLog;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use App\Services\ShopYourVibeShopify;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake();
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new VibeShopifyFake;
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->a = User::factory()->create(['name' => 'Leanne', 'is_active' => true]);
    $this->b = User::factory()->create(['name' => 'Shona', 'is_active' => true]);
    $this->a->assignRole(RolesEnum::Admin->value);
    $this->b->assignRole(RolesEnum::Admin->value);
    $import = Import::create(['filename' => 'f4', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->a->id, 'is_current' => true, 'is_valid' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create(['import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle']]));
    }
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->editing = app(ShopYourVibeEditing::class);
    $this->actingAs($this->a);
    $this->editing->acquire($this->draft->id, $this->draft->revision);
    $this->token = $this->editing->token;
    $this->draft = $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => array_reverse($this->fake->references)]);
});

it('records the owner change details timestamp and pending revision', function () {
    $state = $this->draft->editing_state;
    expect($state['pending_owner_id'])->toBe($this->a->id)->and($state['pending_owner_name'])->toBe('Leanne')
        ->and($state['pending_revision'])->toBe($this->draft->revision)->and($state['changes'][0]['type'])->toBe('reorder_cards')
        ->and($state['changes'][0]['details']['keys'])->toBe(array_reverse($this->fake->references))
        ->and($state['expires_at'])->not->toBeEmpty();
});

it('blocks another user and administrators from edits or discard during an active lock', function () {
    $this->actingAs($this->b);
    $before = $this->draft->fresh()->getAttributes();
    expect(fn () => $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => $this->fake->references]))->toThrow(RuntimeException::class, 'owns this editing lock');
    expect(fn () => $this->editing->discard($this->draft->id, $this->draft->revision, 'Reviewed and discarded after testing'))->toThrow(RuntimeException::class, 'Administrators cannot bypass');
    expect($this->draft->fresh()->getAttributes())->toBe($before)->and(ChangeLog::count())->toBe(0);
});

it('allows another user to edit an independent parent draft', function () {
    $other = ShopYourVibeDraft::create(['collection_gid' => 'gid://shopify/Collection/99', 'snapshot' => $this->draft->snapshot, 'desired' => $this->draft->snapshot]);
    $this->actingAs($this->b);
    $this->editing->token = null;
    $this->editing->acquire($other->id, $other->revision);
    $other = $this->workflow->edit($other->id, $other->revision, 'reorder_cards', ['keys' => array_reverse($this->fake->references)]);
    expect($other->editing_state['pending_owner_id'])->toBe($this->b->id)
        ->and($this->draft->fresh()->editing_state['pending_owner_id'])->toBe($this->a->id);
});

it('renews for exactly fourteen minutes from genuine editing activity', function () {
    $this->travel(13)->minutes();
    $this->editing->heartbeat($this->draft->id, $this->draft->revision);
    expect(now()->diffInSeconds($this->draft->fresh()->editing_state['expires_at']))->toBe(840.0);
    $this->travel(13)->minutes();
    expect($this->editing->view($this->draft->fresh())['active'])->toBeTrue();
});

it('does not renew on passive reads and expiry keeps the pending edits', function () {
    $desired = $this->draft->desired;
    $expires = $this->draft->editing_state['expires_at'];
    $this->travel(14)->minutes();
    $this->travel(1)->seconds();
    expect($this->editing->view($this->draft->fresh())['active'])->toBeFalse()
        ->and($this->draft->fresh()->editing_state['expires_at'])->toBe($expires)
        ->and($this->draft->fresh()->pending)->toBeTrue()->and($this->draft->fresh()->desired)->toBe($desired);
    expect(fn () => $this->editing->heartbeat($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class, 'expired');
});

it('requires explicit reacquisition and rejects expired tokens and stale revisions', function () {
    $this->travel(15)->minutes();
    expect(fn () => $this->workflow->edit($this->draft->id, $this->draft->revision, 'reorder_cards', ['keys' => $this->fake->references]))->toThrow(RuntimeException::class, 'expired');
    expect(fn () => $this->editing->acquire($this->draft->id, $this->draft->revision - 1))->toThrow(RuntimeException::class, 'changed');
    $this->editing->acquire($this->draft->id, $this->draft->revision);
    expect($this->editing->token)->not->toBe($this->token);
    $this->editing->token = $this->token;
    expect(fn () => $this->editing->heartbeat($this->draft->id, $this->draft->revision))->toThrow(RuntimeException::class, 'owns this editing lock');
});

it('discards after expiry atomically audits complete history and preserves other drafts', function () {
    $other = ShopYourVibeDraft::create(['collection_gid' => 'gid://shopify/Collection/99', 'snapshot' => $this->draft->snapshot, 'desired' => $this->draft->desired, 'pending' => true]);
    $otherBefore = $other->fresh()->getAttributes();
    $proposed = $this->draft->desired;
    $confirmed = $this->draft->snapshot;
    $confirmedAt = $this->draft->refreshed_at?->toISOString();
    $revision = $this->draft->revision;
    $this->travel(15)->minutes();
    $this->actingAs($this->b);
    $reason = '  Reviewed changes; testing is complete.  ';
    $draft = $this->editing->discard($this->draft->id, $revision, $reason);
    $entry = ChangeLog::sole();
    $old = json_decode($entry->old_value, true);
    $new = json_decode($entry->new_value, true);
    expect($old['editor_id'])->toBe($this->a->id)->and($old['editor_name'])->toBe('Leanne')
        ->and($old['confirmed'])->toBe($confirmed)->and($old['proposed'])->toBe($proposed)->and($old['revision'])->toBe($revision)
        ->and($new['reason'])->toBe($reason)->and($new['discarded_by_id'])->toBe($this->b->id)->and($new['discarded_by_name'])->toBe('Shona')
        ->and($new['result'])->toBe('discarded_locally')->and($new['at'])->not->toBeEmpty()
        ->and($draft->pending)->toBeFalse()->and($draft->refreshed_at?->toISOString())->toBe($confirmedAt)
        ->and($other->fresh()->getAttributes())->toBe($otherBefore)->and($this->fake->mutations())->toBe([]);
    expect(fn () => $entry->update(['new_value' => 'tampered']))->toThrow(Exception::class, 'immutable');
    expect(fn () => $entry->delete())->toThrow(Exception::class, 'immutable');
});

it('permits an explicit owner discard without waiting for expiry', function () {
    $draft = $this->editing->discard($this->draft->id, $this->draft->revision, 'My requested edits should be cancelled');
    expect($draft->pending)->toBeFalse()->and(ChangeLog::count())->toBe(1)->and($this->fake->mutations())->toBe([]);
});

it('rejects empty whitespace and short discard reasons', function (string $reason) {
    expect(fn () => $this->editing->discard($this->draft->id, $this->draft->revision, $reason))->toThrow(ValidationException::class);
    expect(ChangeLog::count())->toBe(0)->and($this->draft->fresh()->pending)->toBeTrue();
})->with(['', '    ', " \n\t ", 'ninechars', 'a b c d e f g h i', '!!!!!!!!!!!!!!!!!']);

it('rolls back the discard if creating the audit entry fails', function () {
    $before = $this->draft->fresh()->getAttributes();
    ChangeLog::creating(fn () => throw new RuntimeException('Audit storage failed'));
    try {
        expect(fn () => $this->editing->discard($this->draft->id, $this->draft->revision, 'Audit must commit with the discard'))->toThrow(RuntimeException::class, 'Audit storage failed');
        expect($this->draft->fresh()->getAttributes())->toBe($before);
    } finally {
        ChangeLog::flushEventListeners();
        ChangeLog::clearBootedModels();
    }
});

it('serializes a renewal before competing discard without an administrator bypass', function () {
    $this->travel(13)->minutes();
    $this->editing->heartbeat($this->draft->id, $this->draft->revision);
    $this->actingAs($this->b);
    expect(fn () => $this->editing->discard($this->draft->id, $this->draft->revision, 'Competing discard must not win'))->toThrow(RuntimeException::class, 'Discard blocked');
    expect(ChangeLog::count())->toBe(0);
});

it('invalidates old renewals after an expired discard commits', function () {
    $revision = $this->draft->revision;
    $this->travel(15)->minutes();
    $this->actingAs($this->b);
    $this->editing->discard($this->draft->id, $revision, 'Expired work has been reviewed');
    $this->actingAs($this->a);
    expect(fn () => $this->editing->heartbeat($this->draft->id, $revision))->toThrow(RuntimeException::class, 'changed');
});

it('rejects duplicate discards and delayed edits', function () {
    $revision = $this->draft->revision;
    $this->editing->discard($this->draft->id, $revision, 'Cancel my previous pending changes');
    expect(fn () => $this->editing->discard($this->draft->id, $revision, 'A delayed duplicate discard request'))->toThrow(RuntimeException::class, 'changed');
    expect(fn () => $this->workflow->edit($this->draft->id, $revision, 'reorder_cards', ['keys' => $this->fake->references]))->toThrow(RuntimeException::class, 'changed');
    expect(ChangeLog::count())->toBe(1);
});

it('does not let a stale editor adopt another owners revision for a push', function () {
    $this->actingAs($this->b);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid);
    $page->call('reviewPush')->assertSet('confirmingPush', false)->assertSee('Leanne');
    $page->call('pushChanges');
    Bus::assertNotDispatched(PushShopYourVibe::class);
    expect($this->draft->fresh()->status)->toBe('pending');
});

it('revalidates captured queued authorization when permissions revision or lease change', function (string $invalidation) {
    $draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $job = new PushShopYourVibe($draft->id);
    if ($invalidation === 'permission') {
        $this->a->syncRoles([]);
    } elseif ($invalidation === 'revision') {
        $draft->increment('revision');
    } elseif ($invalidation === 'disabled account') {
        $this->a->update(['is_active' => false]);
    } else {
        $this->travel(15)->minutes();
    }
    expect(fn () => $job->handle($this->workflow))->toThrow(RuntimeException::class, 'authorization');
    expect($this->fake->mutations())->toBe([]);
})->with(['permission', 'revision', 'expiry', 'disabled account']);

it('binds the exact reviewed revision and rejects a duplicate queue request', function () {
    $revision = $this->draft->revision;
    $draft = $this->workflow->queue($this->draft->id, $revision);
    expect($draft->editing_state['push']['reviewed_revision'])->toBe($revision)
        ->and($draft->editing_state['push']['revision'])->toBe($revision + 1);
    expect(fn () => $this->workflow->queue($draft->id, $revision))->toThrow(RuntimeException::class);
});

it('requires an audited discard instead of refreshing away owned pending changes', function () {
    $this->fake->calls = [];
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision, true))->toThrow(RuntimeException::class, 'reason');
    expect($this->fake->calls)->toBe([])->and($this->draft->fresh()->pending)->toBeTrue();
});

it('does not renew locks or render the grid when an idle browser polls ownership status', function () {
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid);
    $expiry = $this->draft->fresh()->editing_state['expires_at'];
    $this->travel(15)->minutes();
    $page->call('editingStatus');
    expect($page->effects)->not->toHaveKey('html');
    expect($page->instance()->editingStatus()['active'])->toBeFalse()
        ->and($this->draft->fresh()->editing_state['expires_at'])->toBe($expiry);
});

it('shows the required discard review and a permanent history record in the page', function () {
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid)->call('reviewDiscard')
        ->assertSee('Original editor: Leanne')->assertSee('reorder_cards')->assertSee('Reason (required');
    $page->set('discardReason', '    ')->call('discardChanges')->assertHasErrors('discardReason');
    $page->set('discardReason', 'Reviewed my pending edits and cancelled them')->call('discardChanges')->assertHasNoErrors()
        ->assertSee('Discarded by Leanne');
    $page->set('showEditingHistory', true)->assertSee('Permanent discard history')->assertSee('Reviewed my pending edits and cancelled them');
    expect($this->fake->mutations())->toBe([]);
});

it('checks worker authorization again before a mutation even if initial checks already passed', function () {
    $draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $ticket = $draft->editing_state['push'];
    $this->editing->validateJob($draft->id, $ticket);
    $this->a->update(['is_active' => false]);
    $adapter = app(ShopYourVibeShopify::class);
    $adapter->beforeMutation = fn () => $this->editing->validateJob($draft->id, $ticket, false);
    expect(fn () => $adapter->reorderProducts($draft->collection_gid, []))->toThrow(RuntimeException::class, 'authorization');
    expect($this->fake->mutations())->toBe([]);
});

it('does not let an old queue failure mark a newer reviewed push as failed', function () {
    $draft = $this->workflow->queue($this->draft->id, $this->draft->revision);
    $oldJob = new PushShopYourVibe($draft->id);
    $state = $draft->editing_state;
    $state['push']['id'] = 'new-reviewed-attempt';
    $draft->update(['editing_state' => $state, 'revision' => $draft->revision + 1]);
    $before = $draft->fresh()->getAttributes();
    $oldJob->failed(new RuntimeException('An older worker timed out'));
    expect($draft->fresh()->getAttributes())->toBe($before);
});

it('cancels discard review and clears its reason and validation without changing pending work', function () {
    $before = $this->draft->fresh()->getAttributes();
    $this->fake->calls = [];
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid)->call('reviewDiscard');
    $this->fake->calls = [];
    $page->set('discardReason', 'short')->call('discardChanges')->assertHasErrors('discardReason');
    $page->call('cancelDiscard')->assertSet('confirmingDiscard', false)->assertSet('discardReason', '')
        ->assertHasNoErrors('discardReason')->assertDontSee('Confirm local discard');
    $page->call('reviewDiscard')->assertSet('discardReason', '')->assertSee('Confirm local discard');
    expect($this->draft->fresh()->getAttributes())->toBe($before)
        ->and(ChangeLog::count())->toBe(0)->and($this->fake->calls)->toBe([]);
});

function f4AbandonedLegacy($test): ShopYourVibeDraft
{
    $test->draft->update(['editing_state' => null, 'status' => 'pushing', 'progress' => null, 'remote_jobs' => null, 'operation' => null]);
    DB::table('shop_your_vibe_drafts')->where('id', $test->draft->id)->update(['updated_at' => now()->subDays(2)]);

    return $test->draft->fresh();
}

it('audits a local discard of an abandoned unowned legacy pushing flag without Shopify calls', function () {
    $draft = f4AbandonedLegacy($this);
    $confirmedAt = $draft->refreshed_at?->toISOString();
    $this->fake->calls = [];
    $discarded = $this->editing->discard($draft->id, $draft->revision, 'No longer needed');
    $audit = json_decode(ChangeLog::sole()->old_value, true);
    expect($discarded->pending)->toBeFalse()->and($discarded->status)->toBe('synced')
        ->and($discarded->refreshed_at?->toISOString())->toBe($confirmedAt)
        ->and($audit['previous_status'])->toBe('pushing')->and($audit['abandoned_legacy_push'])->toBeTrue()
        ->and($this->fake->calls)->toBe([]);
});

it('never treats a current queued locked checkpointed or owned push as abandoned', function (string $condition) {
    $draft = f4AbandonedLegacy($this);
    if ($condition === 'recent') {
        $draft->update(['updated_at' => now()]);
    } elseif ($condition === 'queued or reserved') {
        DB::table('jobs')->insert(['queue' => 'shop-your-vibe', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()]);
    } elseif ($condition === 'operation lock') {
        Cache::put('shop-your-vibe-op:'.$draft->collection_gid, 'running-owner', 840);
    } elseif ($condition === 'remote receipt') {
        $draft->update(['remote_jobs' => ['collection' => 'remote-job']]);
    } elseif ($condition === 'progress') {
        $draft->update(['progress' => ['A Shopify change was confirmed.']]);
    } elseif ($condition === 'owned') {
        $draft->update(['editing_state' => ['owner_id' => $this->a->id, 'owner_name' => $this->a->name]]);
    } else {
        config()->set('queue.connections.shop-your-vibe.driver', 'redis');
    }
    expect(fn () => $this->editing->discard($draft->id, $draft->revision, 'No longer needed'))->toThrow(RuntimeException::class, 'needs confirmation');
    expect(ChangeLog::count())->toBe(0)->and($draft->fresh()->pending)->toBeTrue();
})->with(['recent', 'queued or reserved', 'operation lock', 'remote receipt', 'progress', 'owned', 'unknown queue backend']);

it('shows discard rejection beside the confirmation instead of only at the top of the page', function () {
    $this->draft->update(['status' => 'pushing']);
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid)->call('reviewDiscard')
        ->set('discardReason', 'No longer needed')->call('discardChanges');
    expect($page->get('discardError'))->toContain('needs confirmation');
    $page->assertSet('confirmingDiscard', true)->assertSee('Enter at least 10 letters or numbers to enable Confirm');
    expect(ChangeLog::count())->toBe(0)->and($this->fake->mutations())->toBe([]);
});
