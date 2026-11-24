<?php

use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Services\ImageOwnership;
use App\Services\ShopifyImageImportService;
use App\Services\StackRebuildLockStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
    Http::preventStrayRequests();
});

it('keeps a recovered claim when the stale worker releases it', function (): void {
    [$stack, $component] = ownershipBundle('CLAIMSTACK', 'CLAIMCOMP');
    $asset = createImportTestAsset('claim-bytes', 'jpg', 'CLAIMCOMP.jpg');
    ownershipPhotograph($component, ['image_asset_id' => $asset->id]);
    $batch = ownershipBatch();
    ownershipOwn($stack, $batch, ShopifyImageImportBatch::REBUILD_PENDING, null);
    Queue::fake();
    dispatch($workerA = new RebuildShopifyStackImagesJob($batch->id, [$stack->id]));
    $key = UniqueLock::getKey($workerA);
    DB::table('stack_rebuild_claims')->where('claim_key', $key)->update([
        'started_at' => now()->subHours(3),
        'heartbeat_at' => now()->subHours(3),
    ]);

    $workerB = new RebuildShopifyStackImagesJob($batch->id, [$stack->id]);
    $workerC = new RebuildShopifyStackImagesJob($batch->id, [$stack->id]);
    expect($workerB->uniqueVia()->lock($key)->get())->toBeTrue()
        ->and($workerB->claimAcquired)->toBeTrue();

    (new UniqueLock(app(Repository::class)))->release($workerA);
    $workerA->releaseClaim();

    $held = DB::table('stack_rebuild_claims')->where('claim_key', $key)->first();
    $heartbeat = $held->heartbeat_at;
    expect($held->owner)->toBe($workerB->claimOwner)
        ->and($workerC->uniqueVia()->lock($key)->get())->toBeFalse()
        ->and(app(StackRebuildLockStore::class)->markRunning($key, (string) $workerA->claimOwner))->toBeFalse()
        ->and(DB::table('stack_rebuild_claims')->where('claim_key', $key)->value('heartbeat_at'))->toBe($heartbeat)
        ->and(DB::table('stack_rebuild_claims')->where('claim_key', $key)->value('owner'))->toBe($workerB->claimOwner);

    $workerA->handle(app(ShopifyImageImportService::class));
    expect($stack->allImages()->count())->toBe(0)
        ->and($stack->fresh()->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_PENDING);

    $workerB->handle(app(ShopifyImageImportService::class));
    expect($stack->fresh()->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->allImages()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1);

    $workerB->releaseClaim();
    expect(DB::table('stack_rebuild_claims')->where('claim_key', $key)->exists())->toBeFalse();

    dispatch(new RebuildShopifyStackImagesJob($batch->id, [$stack->id]));
    expect(DB::table('stack_rebuild_claims')->where('claim_key', $key)->exists())->toBeTrue()
        ->and(config('images.component_stack_propagation'))->toBeFalse()
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse()
        ->and(config('images.component_stack_blind_publication'))->toBeFalse();
});

it('lets exactly one recovery become the claim owner', function (): void {
    $batch = ownershipBatch();
    $stale = new RebuildShopifyStackImagesJob($batch->id, [4, 9]);
    $key = UniqueLock::getKey($stale);
    DB::table('stack_rebuild_claims')->insert([
        'claim_key' => $key,
        'owner' => 'stale-owner',
        'started_at' => now()->subHours(3),
        'heartbeat_at' => now()->subHours(3),
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);
    $first = new RebuildShopifyStackImagesJob($batch->id, [9, 4]);
    $second = new RebuildShopifyStackImagesJob($batch->id, [4, 9]);

    $won = [
        $first->uniqueVia()->lock($key)->get(),
        $second->uniqueVia()->lock($key)->get(),
    ];

    expect(array_values(array_filter($won)))->toHaveCount(1)
        ->and(DB::table('stack_rebuild_claims')->where('claim_key', $key)->count())->toBe(1)
        ->and(DB::table('stack_rebuild_claims')->where('claim_key', $key)->value('owner'))->not->toBe('stale-owner');
});

it('does not let an older rebuild replace a newer status or gallery', function (): void {
    [$stack, $component] = ownershipBundle('OLDERSTACK', 'OLDERCOMP');
    $current = createImportTestAsset('current-gallery', 'jpg', 'current.jpg');
    $replacement = createImportTestAsset('replacement-source', 'jpg', 'replacement.jpg');
    $source = ownershipPhotograph($component, ['image_asset_id' => $replacement->id]);
    ownershipPhotograph($stack, [
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $source->id,
        'image_asset_id' => $current->id,
        'position' => 1,
    ]);
    $older = ownershipBatch();
    $newer = ownershipBatch();
    ownershipOwn($stack, $newer, ShopifyImageImportBatch::REBUILD_PENDING, 'newer pending', 'updated');

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($older, [$stack->id]);

    $stack->refresh();
    $inherited = $stack->allImages()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get();
    expect($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_PENDING)
        ->and($stack->image_rebuild_message)->toBe('newer pending')
        ->and($stack->image_rebuild_batch_id)->toBe($newer->id)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($inherited)->toHaveCount(1)
        ->and($inherited->first()->image_asset_id)->toBe($current->id)
        ->and($older->fresh()->rebuild_status)->toBeNull();
});

it('does not let an older incomplete rebuild or terminal failure replace a newer status', function (): void {
    [$stack, $component] = ownershipBundle('FAILSTACK', 'FAILCOMP');
    $asset = createImportTestAsset('corrupt-source', 'jpg', 'corrupt.jpg');
    Storage::disk('public')->put($asset->storage_path, 'tampered-bytes');
    ownershipPhotograph($component, ['image_asset_id' => $asset->id]);
    $older = ownershipBatch();
    $newer = ownershipBatch();
    ownershipOwn($stack, $newer, ShopifyImageImportBatch::REBUILD_COMPLETED, 'newer result', 'updated');

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($older, [$stack->id]);
    (new RebuildShopifyStackImagesJob($older->id, [$stack->id]))
        ->failed(new RuntimeException('old worker stopped'));

    $stack->refresh();
    expect($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->image_rebuild_message)->toBe('newer result')
        ->and($stack->image_rebuild_batch_id)->toBe($newer->id)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->allImages()->count())->toBe(0);
});

it('does not let a superseded attempt overwrite the current result, and the owner can clear its own error', function (): void {
    [$stack, $component] = ownershipBundle('ATTEMPTSTACK', 'ATTEMPTCOMP');
    $current = createImportTestAsset('attempt-current', 'jpg', 'attempt-current.jpg');
    $next = createImportTestAsset('attempt-next', 'jpg', 'attempt-next.jpg');
    $source = ownershipPhotograph($component, ['image_asset_id' => $current->id]);
    ownershipPhotograph($stack, [
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $source->id,
        'image_asset_id' => $current->id,
        'position' => 1,
    ]);
    $batch = ownershipBatch();
    $currentJob = new RebuildShopifyStackImagesJob($batch->id, [$stack->id]);
    $staleJob = new RebuildShopifyStackImagesJob($batch->id, [$stack->id]);
    $key = UniqueLock::getKey($currentJob);
    DB::table('stack_rebuild_claims')->insert([
        'claim_key' => $key,
        'owner' => $currentJob->claimOwner,
        'heartbeat_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $currentJob->claimAcquired = true;
    $staleJob->claimAcquired = true;
    ownershipOwn($stack, $batch, ShopifyImageImportBatch::REBUILD_COMPLETED, 'current attempt', 'updated');
    DB::table('products')->where('id', $stack->id)->update(['image_rebuild_owner' => $currentJob->claimOwner]);
    $source->forceFill(['image_asset_id' => $next->id])->saveQuietly();

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id], [
        'key' => $key,
        'owner' => (string) $staleJob->claimOwner,
    ]);

    $stack->refresh();
    expect($stack->image_rebuild_message)->toBe('current attempt')
        ->and($stack->image_rebuild_batch_id)->toBe($batch->id)
        ->and($stack->allImages()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first()->image_asset_id)->toBe($current->id);

    DB::table('products')->where('id', $stack->id)->update([
        'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_FAILED,
        'image_rebuild_message' => 'stale error',
        'image_rebuild_owner' => null,
    ]);
    DB::table('stack_rebuild_claims')->where('claim_key', $key)->delete();
    $source->forceFill(['image_asset_id' => $current->id])->saveQuietly();
    dispatch(new RebuildShopifyStackImagesJob($batch->id, [$stack->id]));

    $stack->refresh();
    expect($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->image_rebuild_message)->not->toContain('stale error')
        ->and($stack->image_rebuild_batch_id)->toBe($batch->id)
        ->and($stack->image_import_status)->toBe('updated');
});

it('reaches the same rebuild schema from a fresh database, the original migration, and the extended migration', function (): void {
    ownershipProbe(function (): void {
        ownershipBaseTables();
        ownershipMigration('2026_11_23_170000_add_stack_rebuild_status_to_image_imports.php')->up();
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->up();
        ownershipExpectRebuildSchema();
        $id = ownershipKeep('completed', 'fresh row', 41);
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->down();
        expect(Schema::hasColumn('products', 'image_rebuild_owner'))->toBeFalse()
            ->and(Schema::hasColumn('products', 'image_rebuild_batch_id'))->toBeTrue()
            ->and(Schema::hasColumn('products', 'image_rebuild_status'))->toBeTrue()
            ->and(Schema::hasTable('stack_rebuild_claims'))->toBeTrue()
            ->and(DB::table('products')->where('id', $id)->value('image_rebuild_message'))->toBe('fresh row');
    });

    ownershipProbe(function (): void {
        ownershipBaseTables(original: true);
        $id = ownershipKeep('incomplete', 'original row');
        DB::table('shopify_image_import_batches')->insert([
            'error_message' => null,
            'rebuild_status' => 'failed',
            'rebuild_message' => 'keep this batch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->up();
        ownershipExpectRebuildSchema();
        expect(DB::table('products')->where('id', $id)->value('image_rebuild_status'))->toBe('incomplete')
            ->and(DB::table('products')->where('id', $id)->value('image_rebuild_message'))->toBe('original row')
            ->and(DB::table('shopify_image_import_batches')->value('rebuild_message'))->toBe('keep this batch');
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->down();
        expect(Schema::hasColumn('products', 'image_rebuild_status'))->toBeTrue()
            ->and(Schema::hasColumn('products', 'image_rebuild_message'))->toBeTrue()
            ->and(Schema::hasColumn('products', 'image_rebuild_batch_id'))->toBeFalse()
            ->and(Schema::hasColumn('products', 'image_rebuild_owner'))->toBeFalse()
            ->and(Schema::hasTable('stack_rebuild_claims'))->toBeFalse()
            ->and(DB::table('products')->where('id', $id)->value('image_rebuild_message'))->toBe('original row')
            ->and(DB::table('shopify_image_import_batches')->value('rebuild_status'))->toBe('failed');
    });

    ownershipProbe(function (): void {
        ownershipBaseTables();
        ownershipMigration('2026_11_23_170000_add_stack_rebuild_status_to_image_imports.php')->up();
        $id = ownershipKeep('failed', 'extended row', 77);
        DB::table('stack_rebuild_claims')->insert([
            'claim_key' => 'extended-claim',
            'owner' => 'extended-owner',
            'heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->up();
        ownershipExpectRebuildSchema();
        expect(DB::table('products')->where('id', $id)->value('image_rebuild_status'))->toBe('failed')
            ->and(DB::table('products')->where('id', $id)->value('image_rebuild_message'))->toBe('extended row')
            ->and((int) DB::table('products')->where('id', $id)->value('image_rebuild_batch_id'))->toBe(77)
            ->and(DB::table('stack_rebuild_claims')->where('claim_key', 'extended-claim')->value('owner'))->toBe('extended-owner');
        ownershipMigration('2026_11_24_070000_add_durable_stack_rebuild_claims.php')->down();
        expect(Schema::hasColumn('products', 'image_rebuild_batch_id'))->toBeTrue()
            ->and(Schema::hasTable('stack_rebuild_claims'))->toBeTrue()
            ->and(Schema::hasColumn('products', 'image_rebuild_status'))->toBeTrue()
            ->and(DB::table('stack_rebuild_claims')->where('claim_key', 'extended-claim')->value('owner'))->toBe('extended-owner')
            ->and(DB::table('products')->where('id', $id)->value('image_rebuild_message'))->toBe('extended row');
    });
});

function ownershipBundle(string $stackSku, string $componentSku): array
{
    $component = createImageImportProduct($componentSku);
    $stack = createImageImportProduct($stackSku, [
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => $stackSku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    return [$stack, $component];
}

function ownershipPhotograph(Product $product, array $overrides = []): Image
{
    return Image::withoutEvents(fn (): Image => Image::create(array_merge([
        'product_id' => $product->id,
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'needs_shopify_image_sync' => false,
        'photograph_version' => 3,
        'alt_text_version' => 1,
        'alt_text' => 'Component sentence',
        'alt_text_status' => Image::ALT_APPROVED,
    ], $overrides)));
}

function ownershipBatch(): ShopifyImageImportBatch
{
    return ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/ownership-'.uniqid(),
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
    ]);
}

function ownershipOwn(Product $stack, ShopifyImageImportBatch $batch, string $status, ?string $message, ?string $importStatus = null): void
{
    DB::table('products')->where('id', $stack->id)->update([
        'image_rebuild_status' => $status,
        'image_rebuild_message' => $message,
        'image_rebuild_batch_id' => $batch->id,
        'image_import_status' => $importStatus,
    ]);
}

function ownershipProbe(callable $callback): void
{
    $original = config('database.default');
    config([
        'database.connections.rebuild_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'database.default' => 'rebuild_probe',
    ]);
    DB::purge('rebuild_probe');
    DB::reconnect('rebuild_probe');
    app()->forgetInstance('db.schema');
    Schema::clearResolvedInstances();
    try {
        $callback();
    } finally {
        config(['database.default' => $original]);
        DB::purge('rebuild_probe');
        app()->forgetInstance('db.schema');
        Schema::clearResolvedInstances();
    }
}

function ownershipBaseTables(bool $original = false): void
{
    Schema::create('products', function (Blueprint $table) use ($original): void {
        $table->id();
        $table->string('image_import_status')->nullable();
        if ($original) {
            $table->string('image_rebuild_status', 32)->nullable();
            $table->text('image_rebuild_message')->nullable();
        }
        $table->timestamps();
    });
    Schema::create('shopify_image_import_batches', function (Blueprint $table) use ($original): void {
        $table->id();
        $table->text('error_message')->nullable();
        if ($original) {
            $table->string('rebuild_status', 32)->nullable();
            $table->text('rebuild_message')->nullable();
            $table->json('rebuild_stack_product_ids')->nullable();
        }
        $table->timestamps();
    });
}

function ownershipKeep(string $status, string $message, ?int $batchId = null): int
{
    $values = [
        'image_import_status' => 'updated',
        'image_rebuild_status' => $status,
        'image_rebuild_message' => $message,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if ($batchId !== null && Schema::hasColumn('products', 'image_rebuild_batch_id')) {
        $values['image_rebuild_batch_id'] = $batchId;
    }

    return DB::table('products')->insertGetId($values);
}

function ownershipExpectRebuildSchema(): void
{
    expect(Schema::hasColumns('products', [
        'image_rebuild_status',
        'image_rebuild_message',
        'image_rebuild_batch_id',
        'image_rebuild_owner',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('shopify_image_import_batches', [
            'rebuild_status',
            'rebuild_message',
            'rebuild_stack_product_ids',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('stack_rebuild_claims', [
            'claim_key',
            'owner',
            'started_at',
            'heartbeat_at',
        ]))->toBeTrue();
}

function ownershipMigration(string $file): object
{
    $copy = tempnam(sys_get_temp_dir(), 'rebuild-migration');
    file_put_contents($copy, file_get_contents(database_path('migrations/'.$file)));
    try {
        return require $copy;
    } finally {
        unlink($copy);
    }
}
