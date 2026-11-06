<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ShopYourVibe;
use App\Jobs\RefreshShopYourVibeDraft;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeRefresh;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.shopify.storefront_url', 'https://leighavenue.co.za');
    $this->fake = new class extends VibeShopifyFake {
        public ?Closure $afterRead = null;

        public function graphql(string $query, array $variables = []): array
        {
            $result = parent::graphql($query, $variables);
            if ($callback = $this->afterRead) {
                $this->afterRead = null;
                $callback();
            }

            return $result;
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $this->fake);
    $this->workflow = app(ShopYourVibeWorkflow::class);
    $this->refresh = app(ShopYourVibeRefresh::class);
    $this->user = User::factory()->create();
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($this->user);
    $import = Import::create(['filename' => 'f5-test', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $this->user->id, 'is_current' => true]);
    foreach ($this->fake->collections as $collection) {
        ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
            'import_id' => $import->id, 'shopify_id' => $collection['id'], 'title' => $collection['title'], 'handle' => $collection['handle'],
        ]));
    }
    $this->draft = $this->workflow->open('gid://shopify/Collection/1');
    $this->fake->calls = [];
});

function f5Job(array $claim, ShopYourVibeDraft $draft, ?int $userId = null): RefreshShopYourVibeDraft
{
    return new RefreshShopYourVibeDraft($draft->id, $claim['operation']['revision'], false, $userId, $claim['operation']['token']);
}

it('F5 repeated refresh clicks enqueue one real database job and show the existing status', function () {
    $page = Livewire::test(ShopYourVibe::class)->call('manage', $this->draft->collection_gid);
    $this->fake->calls = [];
    $page->call('refreshDraft');
    $token = $this->draft->fresh()->refresh_state['token'];
    $page->call('refreshDraft')->assertNotified('This collection is already refreshing.');
    expect(DB::table('jobs')->where('queue', 'shop-your-vibe')->count())->toBe(1)
        ->and($this->draft->fresh()->refresh_state['token'])->toBe($token)
        ->and($this->fake->calls)->toBe([]);
});

it('F5 returns queued and running operations to another user without replacing progress', function () {
    $first = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $other = User::factory()->create();
    $second = $this->refresh->request($this->draft->id, $this->draft->revision, $other->id);
    expect($second['created'])->toBeFalse()->and($second['operation'])->toBe($first['operation']);
    expect($this->refresh->start($this->draft->id, $first['operation']['token']))->toBeTrue();
    $this->refresh->heartbeat($this->draft->id, $first['operation']['token'], ['done' => 17, 'total' => 100]);
    Cache::forget(ShopYourVibeWorkflow::progressKey($this->draft->collection_gid));
    $third = $this->refresh->request($this->draft->id, $this->draft->revision, $other->id);
    expect($third['created'])->toBeFalse()->and($third['operation']['state'])->toBe('running')
        ->and($third['operation']['done'])->toBe(17)
        ->and(Cache::get(ShopYourVibeWorkflow::progressKey($this->draft->collection_gid))['done'])->toBe(17)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('F5 permits independent collections and rejects duplicate delivery of one attempt', function () {
    $other = $this->workflow->open('gid://shopify/Collection/2');
    $first = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $second = $this->refresh->request($other->id, $other->revision, $this->user->id);
    expect($this->refresh->start($this->draft->id, $first['operation']['token']))->toBeTrue()
        ->and($this->refresh->start($other->id, $second['operation']['token']))->toBeTrue();
    $this->fake->calls = [];
    f5Job($first, $this->draft)->handle($this->workflow);
    expect($this->fake->calls)->toBe([])->and(DB::table('jobs')->count())->toBe(2);
});

it('F5 completes and publishes once then accepts a fresh request', function () {
    $claim = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $job = f5Job($claim, $this->draft, $this->user->id);
    $job->handle($this->workflow);
    $fresh = $this->draft->fresh();
    expect($fresh->refresh_state['state'])->toBe('success')->and($fresh->revision)->toBe($this->draft->revision + 1);
    $this->fake->calls = [];
    $job->handle($this->workflow);
    expect($this->fake->calls)->toBe([])->and($this->draft->fresh()->revision)->toBe($fresh->revision);
    $next = $this->refresh->request($fresh->id, $fresh->revision, $this->user->id);
    expect($next['created'])->toBeTrue()->and($next['operation']['token'])->not->toBe($claim['operation']['token']);
});

it('F5 fails incomplete API retrieval and permits immediate retry without clearing locks', function () {
    $claim = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $snapshot = $this->draft->snapshot;
    $this->fake->fail = 'VibeProducts';
    expect(fn () => f5Job($claim, $this->draft)->handle($this->workflow))->toThrow(RuntimeException::class);
    $fresh = $this->draft->fresh();
    expect($fresh->refresh_state['state'])->toBe('failed')->and($fresh->snapshot)->toBe($snapshot)
        ->and($fresh->revision)->toBe($this->draft->revision);
    $this->fake->fail = null;
    $retry = $this->refresh->request($fresh->id, $fresh->revision, $this->user->id);
    f5Job($retry, $fresh)->handle($this->workflow);
    expect($fresh->fresh()->refresh_state['state'])->toBe('success');
});

it('F5 rolls back an unsuccessful enqueue and can immediately dispatch again', function () {
    config()->set('queue.connections.shop-your-vibe.table', 'f5_nonexistent_queue');
    expect(fn () => $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect($this->draft->fresh()->refresh_state)->toBeNull();
    config()->set('queue.connections.shop-your-vibe.table', 'jobs');
    app()->forgetInstance('queue');
    expect($this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id)['created'])->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('F5 processes the actual database job and recovers from a timeout failure callback', function () {
    $claim = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $this->refresh->start($this->draft->id, $claim['operation']['token']);
    f5Job($claim, $this->draft)->failed(new \Illuminate\Queue\TimeoutExceededException);
    expect($this->draft->fresh()->refresh_state['state'])->toBe('failed');
    $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $queue = app('queue')->connection('shop-your-vibe');
    while ($job = $queue->pop('shop-your-vibe')) {
        $job->fire();
        $job->delete();
    }
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($this->draft->fresh()->refresh_state['state'])->toBe('success');
});

it('F5 rejects stale publication even without an API progress callback', function () {
    $old = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $this->refresh->start($this->draft->id, $old['operation']['token']);
    $this->travel(961)->seconds();
    $next = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    expect(fn () => $this->workflow->refresh($this->draft->id, $this->draft->revision, false, true, $this->user->id, $old['operation']['token']))
        ->toThrow(\App\Exceptions\ShopYourVibeRefreshExpiredException::class);
    expect($this->draft->fresh()->snapshot)->toBe($this->draft->snapshot)
        ->and($this->draft->fresh()->refresh_state['token'])->toBe($next['operation']['token']);
});

it('F5 recovers expired queued and crashed workers while fencing their late delivery and failure callbacks', function (bool $running) {
    $claim = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    if ($running) {
        $this->refresh->start($this->draft->id, $claim['operation']['token']);
    }
    $this->travel(961)->seconds();
    expect($this->refresh->status($this->draft->id)['state'])->toBe('failed');
    $retry = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $this->fake->calls = [];
    $old = f5Job($claim, $this->draft);
    $old->handle($this->workflow);
    $old->failed(new RuntimeException('Late failure'));
    expect($this->fake->calls)->toBe([])
        ->and($this->draft->fresh()->refresh_state['token'])->toBe($retry['operation']['token'])
        ->and($this->draft->fresh()->refresh_state['state'])->toBe('queued');
})->with([false, true]);

it('F5 fences a worker that expires during a Shopify read and preserves newer confirmed data', function () {
    $old = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $this->fake->afterRead = function () {
        $this->travel(961)->seconds();
        $fresh = $this->draft->fresh();
        $this->refresh->request($fresh->id, $fresh->revision, $this->user->id);
        // Another successful publisher confirms a newer baseline while the old API call is in flight.
        $snapshot = $fresh->snapshot;
        $snapshot['parent']['title'] = 'Newer confirmed title';
        $fresh->update(['snapshot' => $snapshot, 'desired' => $snapshot, 'revision' => $fresh->revision + 1]);
    };
    f5Job($old, $this->draft)->handle($this->workflow);
    expect($this->draft->fresh()->snapshot['parent']['title'])->toBe('Newer confirmed title')
        ->and($this->draft->fresh()->refresh_state['state'])->toBe('queued');
});

it('F5 preserves pending work and editing ownership acquired after enqueue', function () {
    $claim = $this->refresh->request($this->draft->id, $this->draft->revision, $this->user->id);
    $editing = app(\App\Services\ShopYourVibeEditing::class);
    $editing->acquire($this->draft->id, $this->draft->revision);
    $fresh = $this->draft->fresh();
    $desired = $fresh->desired;
    $desired['parent']['title'] = 'Pending local title';
    $fresh->update(['desired' => $desired, 'pending' => true, 'revision' => $fresh->revision + 1]);
    $owner = $fresh->editing_state;
    f5Job($claim, $this->draft)->handle($this->workflow);
    expect($fresh->fresh()->desired)->toBe($desired)->and($fresh->fresh()->editing_state)->toBe($owner)
        ->and($fresh->fresh()->pending)->toBeTrue()->and($fresh->fresh()->refresh_state['state'])->toBe('skipped');
});

it('F5 atomically deduplicates simultaneous requests from two independent PHP processes', function () {
    $path = tempnam(sys_get_temp_dir(), 'syv-f5-race-');
    $barrier = $path.'-barrier';
    config()->set('database.connections.syv_race', ['driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => true, 'busy_timeout' => 5000]);
    $schema = \Illuminate\Support\Facades\Schema::connection('syv_race');
    $schema->create('shop_your_vibe_drafts', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('collection_gid')->unique();
        $table->integer('revision');
        $table->boolean('pending')->default(false);
        $table->string('status')->default('synced');
        foreach (['refresh_state', 'editing_state', 'operation', 'remote_jobs'] as $column) {
            $table->json($column)->nullable();
        }
        $table->timestamps();
    });
    $schema->create('jobs', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    DB::connection('syv_race')->table('shop_your_vibe_drafts')->insert(['collection_gid' => 'gid://shopify/Collection/1', 'revision' => 1]);
    $workers = [];
    try {
        foreach ([1, 2] as $actor) {
            $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/SyvRefreshRaceWorker.php'), $path, $barrier, (string) $actor], base_path(), [
                'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $path.'-no-config',
            ]);
            $worker->setTimeout(20)->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 10;
        while (! (file_exists($barrier.'.1.ready') && file_exists($barrier.'.2.ready'))) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent test workers did not start.');
            }
            usleep(1000);
        }
        touch($barrier.'.go');
        $claims = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
            expect(json_validate($worker->getOutput()))->toBeTrue($worker->getOutput());
            $claims[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(array_column($claims, 'created'))->toContain(true, false)
            ->and($claims[0]['operation']['token'])->toBe($claims[1]['operation']['token'])
            ->and(DB::connection('syv_race')->table('jobs')->count())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::purge('syv_race');
        foreach ([$path, $path.'-journal', $path.'-wal', $path.'-shm', $barrier.'.go', $barrier.'.1.ready', $barrier.'.2.ready'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
