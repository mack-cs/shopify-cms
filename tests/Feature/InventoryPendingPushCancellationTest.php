<?php

use App\Enums\RolesEnum;
use App\Filament\Resources\InventoryAdjustmentRequestResource\Pages\ListInventoryAdjustmentRequests;
use App\Jobs\InventorySyncJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryPushCancellation;
use App\Models\InventoryWriteIntent;
use App\Models\Product;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryPendingPushCancellation;
use App\Services\InventoryWriteIntents;
use App\Services\ProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';

beforeEach(function () {
    config(['inventory_monitor.enabled' => false, 'services.shopify.shop' => 'test-shop.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token']);
    Bus::fake();
});

it('discards waiting pushes, cancels approvals and adopts Shopify zero without a mutation', function () {
    $f = cancellationFixture();
    $jobId = cancellationQueueJob([$f['variant']->id], $f['request']->sync_batch_id, $f['admin']->id);
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    $plan = $service->preview([$f['variant']->id], $f['admin']->id);
    $result = $service->cancel([$f['variant']->id], $f['admin']->id, 'Corrected directly in Shopify; discard stale seven.', $plan['fingerprint']);

    expect($result['deleted_jobs'])->toBe(1)
        ->and(DB::table('jobs')->where('id', $jobId)->exists())->toBeFalse()
        ->and($f['request']->fresh()->status)->toBe('CANCELLED')
        ->and($f['intent']->fresh()->status)->toBe('cancelled')
        ->and($f['intent']->fresh()->payload['target'])->toBe(7)
        ->and($f['variant']->fresh()->current_on_hand_quantity)->toBe(0)
        ->and($f['variant']->fresh()->inventory_local_dirty)->toBeFalse()
        ->and($f['variant']->fresh()->inventory_sync_error)->toBeNull()
        ->and(InventoryPushCancellation::sole()->evidence['intents'][0]['error'])->toBe('Shopify did not confirm the inventory adjustment.');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with(trim($request['query']), 'query ProductInventoryById'));
    Bus::assertNothingDispatched();
});

it('prevents a deleted or failed old job from replaying after cancellation', function () {
    $f = cancellationFixture();
    $job = new InventorySyncJob([$f['variant']->id], 'push', $f['admin']->id, $f['request']->sync_batch_id);
    cancellationQueueJob([$f['variant']->id], $f['request']->sync_batch_id, $f['admin']->id);
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']);
    $unique = $job->uniqueId();
    $job->handle(app(ProductInventorySyncService::class), app(InventoryAdjustmentApprovalService::class));
    expect($job->uniqueId())->toBe($unique)->and($f['variant']->fresh()->current_on_hand_quantity)->toBe(0);
    Http::assertSentCount(1);
    expect(fn () => app(InventoryWriteIntents::class)->claim($f['variant']->fresh(), ['target' => 7], $f['request']->sync_batch_id))
        ->toThrow(RuntimeException::class, 'cancelled by a Super Admin');
});

it('allows a genuinely new approved adjustment after cancellation', function () {
    $f = cancellationFixture();
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']);
    $new = app(InventoryAdjustmentApprovalService::class)->submit([['variant' => $f['variant']->fresh(), 'inventory_tracked' => true,
        'on_hand_quantity' => 2, 'reason' => 'New physical count']], $f['requester']->id, $f['admin']->id);
    $new = app(InventoryAdjustmentApprovalService::class)->approve($new, $f['admin']->id, false);
    expect(InventoryPushCancellation::blocks($f['variant']->id, $new->sync_batch_id))->toBeFalse()
        ->and(app(InventoryWriteIntents::class)->claim($f['variant']->fresh(), ['target' => 2], $new->sync_batch_id)->status)->toBe('started');
});

it('enforces the Super Admin boundary in the service and table action', function () {
    $f = cancellationFixture();
    $ordinary = inventoryAuthorizedUser();
    $this->actingAs($ordinary);
    Livewire::test(ListInventoryAdjustmentRequests::class)->assertTableActionHidden('discardPendingPushes', $f['request']);
    expect(fn () => app(InventoryPendingPushCancellation::class)->preview([$f['variant']->id], $ordinary->id))
        ->toThrow(HttpException::class);
    $this->actingAs($f['admin']);
    Livewire::test(ListInventoryAdjustmentRequests::class)->assertTableActionVisible('discardPendingPushes', $f['request']);
    Http::assertNothingSent();
});

it('refuses reserved jobs and a worker-held lock without clearing anything', function () {
    $f = cancellationFixture();
    $id = cancellationQueueJob([$f['variant']->id], $f['request']->sync_batch_id, $f['admin']->id, true);
    $service = app(InventoryPendingPushCancellation::class);
    $plan = $service->preview([$f['variant']->id], $f['admin']->id);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $plan['fingerprint']))
        ->toThrow(ValidationException::class, 'reserved/running');
    DB::table('jobs')->where('id', $id)->update(['reserved_at' => null]);
    $plan = $service->preview([$f['variant']->id], $f['admin']->id);
    $lock = Cache::lock(InventoryPendingPushCancellation::lockKey($f['variant']->id), 60);
    $lock->get();
    try {
        expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $plan['fingerprint']))
            ->toThrow(ValidationException::class, 'push is running');
    } finally {
        $lock->release();
    }
    expect(InventoryPushCancellation::count())->toBe(0)->and($f['intent']->fresh()->status)->toBe('started');
    Http::assertNothingSent();
});

it('retains all pending work if Shopify cannot return complete inventory', function () {
    $f = cancellationFixture();
    $id = cancellationQueueJob([$f['variant']->id], $f['request']->sync_batch_id, $f['admin']->id);
    Http::fake(['*' => Http::response(['data' => ['product' => null]])]);
    $service = app(InventoryPendingPushCancellation::class);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']))
        ->toThrow(ValidationException::class, 'Nothing was discarded');
    expect(DB::table('jobs')->where('id', $id)->exists())->toBeTrue()
        ->and($f['request']->fresh()->status)->toBe('APPROVED')
        ->and($f['variant']->fresh()->current_on_hand_quantity)->toBe(7)
        ->and($f['intent']->fresh()->status)->toBe('started')
        ->and(InventoryPushCancellation::count())->toBe(0);
});

it('rejects a changed preview and an edit arriving during the Shopify read', function () {
    $f = cancellationFixture();
    $service = app(InventoryPendingPushCancellation::class);
    $plan = $service->preview([$f['variant']->id], $f['admin']->id);
    Variant::whereKey($f['variant']->id)->increment('inventory_write_version');
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $plan['fingerprint']))
        ->toThrow(ValidationException::class, 'changed since the preview');
    cancellationShopifyRead($f['variant'], 0, function () use ($f) {
        Variant::whereKey($f['variant']->id)->increment('inventory_write_version');
    });
    $plan = $service->preview([$f['variant']->id], $f['admin']->id);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $plan['fingerprint']))
        ->toThrow(ValidationException::class, 'changed since the preview');
    expect(InventoryPushCancellation::count())->toBe(0)->and($f['intent']->fresh()->status)->toBe('started');
});

it('keeps mixed jobs for other SKUs and fences only the cancelled SKU', function () {
    $f = cancellationFixture();
    $other = cancellationVariant($f['import'], 9002, 'OTHER');
    $id = cancellationQueueJob([$f['variant']->id, $other->id], 'inventory_mixed', $f['admin']->id);
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    $result = $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']);
    expect($result['deleted_jobs'])->toBe(0)->and(DB::table('jobs')->where('id', $id)->exists())->toBeTrue()
        ->and(InventoryPushCancellation::blocks($f['variant']->id, 'inventory_mixed'))->toBeTrue()
        ->and(InventoryPushCancellation::blocks($other->id, 'inventory_mixed'))->toBeFalse();
});

it('discloses linked multi-SKU approvals in full before cancelling them', function () {
    $f = cancellationFixture();
    $other = cancellationVariant($f['import'], 9002, 'OTHER');
    app(InventoryAdjustmentApprovalService::class)->submit([
        ['variant' => $f['variant']->fresh(), 'inventory_tracked' => true, 'on_hand_quantity' => 1, 'reason' => 'Bulk count'],
        ['variant' => $other, 'inventory_tracked' => true, 'on_hand_quantity' => 2, 'reason' => 'Bulk count'],
    ], $f['requester']->id, $f['admin']->id, 'bulk');
    $plan = app(InventoryPendingPushCancellation::class)->preview([$f['variant']->id], $f['admin']->id);
    expect($plan['variant_ids'])->toBe([$f['variant']->id, $other->id])->and(count($plan['requests']))->toBe(2);
    Http::assertNothingSent();
    cancellationShopifyRead([$f['variant'], $other], 0);
    app(InventoryPendingPushCancellation::class)->cancel([$f['variant']->id], $f['admin']->id, 'Discard linked stale approvals', $plan['fingerprint']);
    expect(InventoryAdjustmentRequest::where('status', 'CANCELLED')->count())->toBe(2)
        ->and($other->fresh()->current_on_hand_quantity)->toBe(0)
        ->and(InventoryPushCancellation::count())->toBe(2);
    Http::assertSentCount(2);
});

it('executes the confirmed Super Admin table action and requires acknowledgement', function () {
    $f = cancellationFixture();
    cancellationShopifyRead($f['variant'], 0);
    $this->actingAs($f['admin']);
    $fingerprint = app(InventoryPendingPushCancellation::class)->preview([$f['variant']->id], $f['admin']->id)['fingerprint'];
    Livewire::test(ListInventoryAdjustmentRequests::class)
        ->callTableAction('discardPendingPushes', $f['request'], ['reason' => 'Use corrected Shopify inventory', 'accept_shopify' => false, 'fingerprint' => $fingerprint])
        ->assertHasTableActionErrors(['accept_shopify']);
    expect($f['request']->fresh()->status)->toBe('APPROVED');
    Livewire::test(ListInventoryAdjustmentRequests::class)
        ->callTableAction('discardPendingPushes', $f['request'], ['reason' => 'Use corrected Shopify inventory', 'accept_shopify' => true, 'fingerprint' => $fingerprint])
        ->assertHasNoTableActionErrors();
    expect($f['request']->fresh()->status)->toBe('CANCELLED')->and($f['variant']->fresh()->current_on_hand_quantity)->toBe(0);
});

it('rolls back queue deletion and local cancellation if persistence fails', function () {
    $f = cancellationFixture();
    $jobId = cancellationQueueJob([$f['variant']->id], $f['request']->sync_batch_id, $f['admin']->id);
    cancellationShopifyRead($f['variant'], 0);
    DB::listen(function ($query) {
        if (str_starts_with(strtolower($query->sql), 'insert into "inventory_push_cancellations"')) {
            throw new RuntimeException('Simulated audit persistence failure');
        }
    });
    $service = app(InventoryPendingPushCancellation::class);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']))
        ->toThrow(RuntimeException::class, 'audit persistence failure');
    expect(DB::table('jobs')->where('id', $jobId)->exists())->toBeTrue()
        ->and($f['intent']->fresh()->status)->toBe('started')
        ->and($f['request']->fresh()->status)->toBe('APPROVED')
        ->and(InventoryPushCancellation::count())->toBe(0);
});

it('refuses to clear an intent at a location not present in the Shopify read', function () {
    $f = cancellationFixture();
    $f['intent']->update(['payload' => ['target' => 7, 'tracked' => true, 'location' => 'gid://shopify/Location/999']]);
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']))
        ->toThrow(ValidationException::class, 'location that was not returned');
    expect($f['intent']->fresh()->status)->toBe('started');
});

it('records failed-job batches so later manual queue retries cannot revive them', function () {
    $f = cancellationFixture();
    DB::table('failed_jobs')->insert(['uuid' => 'failed-old-push', 'connection' => 'inventory-sync', 'queue' => 'inventory-sync',
        'payload' => json_encode(['uuid' => 'failed-old-push', 'displayName' => InventorySyncJob::class,
            'data' => ['command' => serialize(new InventorySyncJob([$f['variant']->id], 'push', $f['admin']->id, 'inventory_old_failed'))]]),
        'exception' => 'Uncertain prior write', 'failed_at' => now()]);
    cancellationShopifyRead($f['variant'], 0);
    $service = app(InventoryPendingPushCancellation::class);
    $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']);
    expect(InventoryPushCancellation::blocks($f['variant']->id, 'inventory_old_failed'))->toBeTrue()
        ->and(DB::table('failed_jobs')->where('uuid', 'failed-old-push')->exists())->toBeTrue();
});

it('leaves ledger jobs untouched and refuses a cancellation whose lock expired during the read', function () {
    $f = cancellationFixture();
    $ledgerId = DB::table('jobs')->insertGetId(['queue' => 'inventory-ledger', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    cancellationShopifyRead($f['variant'], 0, fn () => Cache::lock(InventoryPendingPushCancellation::lockKey($f['variant']->id))->forceRelease());
    $service = app(InventoryPendingPushCancellation::class);
    expect(fn () => $service->cancel([$f['variant']->id], $f['admin']->id, 'Use Shopify', $service->preview([$f['variant']->id], $f['admin']->id)['fingerprint']))
        ->toThrow(ValidationException::class, 'lock expired');
    expect(InventoryPushCancellation::count())->toBe(0)->and(DB::table('jobs')->where('id', $ledgerId)->exists())->toBeTrue();
});

function cancellationFixture(): array
{
    $requester = inventoryAuthorizedUser();
    $admin = inventoryAuthorizedUser();
    Role::findOrCreate(RolesEnum::SuperAdmin->value);
    $admin->assignRole(RolesEnum::SuperAdmin->value);
    $import = Import::create(['filename' => 'cancel.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => $requester->id]);
    $variant = cancellationVariant($import, 9001, 'LAB0184');
    $request = app(InventoryAdjustmentApprovalService::class)->submit([['variant' => $variant, 'inventory_tracked' => true,
        'on_hand_quantity' => 7, 'reason' => 'Old count']], $requester->id, $admin->id);
    $request = app(InventoryAdjustmentApprovalService::class)->approve($request, $admin->id, false);
    $intent = InventoryWriteIntent::create(['variant_id' => $variant->id, 'intent_key' => hash('sha256', 'old-intent'),
        'payload' => ['target' => 7, 'tracked' => true, 'location' => 'gid://shopify/Location/1'], 'status' => 'started',
        'error' => 'Shopify did not confirm the inventory adjustment.']);

    return compact('admin', 'requester', 'import', 'variant', 'request', 'intent');
}

function cancellationVariant(Import $import, int $id, string $sku): Variant
{
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/'.$id,
        'handle' => 'cancel-'.$id, 'title' => $sku, 'status' => 'active']));

    return Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/'.$id,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$id, 'sku' => $sku, 'inventory_tracked' => true,
        'inventory_qty' => 7, 'current_on_hand_quantity' => 7, 'current_available_quantity' => 7,
        'inventory_policy' => 'deny', 'inventory_local_dirty' => true]));
}

function cancellationQueueJob(array $variantIds, string $batch, int $actorId, bool $reserved = false): int
{
    return DB::table('jobs')->insertGetId(['queue' => 'inventory-sync', 'payload' => json_encode([
        'uuid' => fake()->uuid(), 'displayName' => InventorySyncJob::class,
        'data' => ['command' => serialize(new InventorySyncJob($variantIds, 'push', $actorId, $batch))],
    ]), 'attempts' => 0, 'reserved_at' => $reserved ? now()->timestamp : null,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
}

function cancellationShopifyRead(Variant|array $variant, int $onHand, ?Closure $duringRead = null): void
{
    Http::fake(function ($request) use ($variant, $onHand, $duringRead) {
        expect(trim($request['query']))->toStartWith('query ProductInventoryById');
        $variant = is_array($variant) ? collect($variant)->first(fn ($v) => $v->product->shopify_id === $request['variables']['id']) : $variant;
        $duringRead?->__invoke();

        return Http::response(['data' => ['product' => ['id' => $variant->product->shopify_id, 'status' => 'ACTIVE', 'variants' => [
            'pageInfo' => ['hasNextPage' => false], 'nodes' => [['id' => $variant->shopify_id, 'inventoryPolicy' => 'DENY',
                'availableForSale' => false, 'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true,
                    'inventoryLevels' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [[
                        'location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [
                            ['name' => 'available', 'quantity' => $onHand - 5], ['name' => 'committed', 'quantity' => 2], ['name' => 'on_hand', 'quantity' => $onHand],
                        ],
                    ]]],
                ]],
            ],
        ]]]]);
    });
}

it('bulk discards a file approval larger than ten SKUs with one confirmation and only Shopify reads', function () {
    $f = cancellationFixture();
    $variants = collect([$f['variant']]);
    for ($i = 0; $i < 11; $i++) {
        $variants->push(cancellationVariant($f['import'], 9100 + $i, 'BULK-'.$i));
    }
    $file = app(InventoryAdjustmentApprovalService::class)->submit($variants->map(fn ($v) => [
        'variant' => $v, 'inventory_tracked' => true, 'on_hand_quantity' => 7, 'reason' => 'File count',
    ])->all(), $f['requester']->id, $f['admin']->id, 'file');
    $file = app(InventoryAdjustmentApprovalService::class)->approve($file, $f['admin']->id, false);
    $job = cancellationQueueJob($variants->pluck('id')->all(), $file->sync_batch_id, $f['admin']->id);
    cancellationShopifyRead($variants->all(), 0);
    $this->actingAs($f['admin']);
    Livewire::test(ListInventoryAdjustmentRequests::class)
        ->assertTableBulkActionVisible('discardPendingPushes')
        ->callTableBulkAction('discardPendingPushes', [$f['request'], $file], data: [
            'reason' => 'Use corrected Shopify counts', 'accept_shopify' => true,
        ])
        ->assertHasNoTableBulkActionErrors();
    expect($file->fresh()->status)->toBe('CANCELLED')
        ->and($f['request']->fresh()->status)->toBe('CANCELLED')
        ->and(InventoryPushCancellation::count())->toBe(12)
        ->and(DB::table('jobs')->where('id', $job)->exists())->toBeFalse();
    foreach ($variants as $variant) {
        expect($variant->fresh()->current_on_hand_quantity)->toBe(0);
    }
    Http::assertSentCount(12);
    Bus::assertNothingDispatched();
});

it('offers bulk discard only to Super Admins in Everyday Inventory and approvals', function () {
    $f = cancellationFixture();
    $this->actingAs($f['requester']);
    Livewire::test(ListInventoryAdjustmentRequests::class)->assertTableBulkActionHidden('discardPendingPushes');
    Livewire::test(\App\Filament\Resources\InventoryResource\Pages\ListInventories::class)
        ->assertTableBulkActionHidden('discardPendingPushes');
    $this->actingAs($f['admin']);
    Livewire::test(\App\Filament\Resources\InventoryResource\Pages\ListInventories::class)
        ->assertTableBulkActionVisible('discardPendingPushes')
        ->set('activeTab', 'orders')
        ->assertTableBulkActionHidden('discardPendingPushes');
});

it('requires acknowledgement for Everyday Inventory bulk discard and then refreshes all selected rows', function () {
    $f = cancellationFixture();
    $other = cancellationVariant($f['import'], 9200, 'OTHER-BULK');
    cancellationShopifyRead([$f['variant'], $other], 0);
    $this->actingAs($f['admin']);
    Livewire::test(\App\Filament\Resources\InventoryResource\Pages\ListInventories::class)
        ->callTableBulkAction('discardPendingPushes', [$f['variant'], $other], data: [
            'reason' => 'Use Shopify', 'accept_shopify' => false,
        ])->assertHasTableBulkActionErrors(['accept_shopify']);
    expect(InventoryPushCancellation::count())->toBe(0);
    Http::assertNothingSent();
    Livewire::test(\App\Filament\Resources\InventoryResource\Pages\ListInventories::class)
        ->callTableBulkAction('discardPendingPushes', [$f['variant'], $other], data: [
            'reason' => 'Use Shopify', 'accept_shopify' => true,
        ])->assertHasNoTableBulkActionErrors();
    expect(InventoryPushCancellation::count())->toBe(2);
});

it('refuses a bulk selection containing an applied approval without changing pending requests', function () {
    $f = cancellationFixture();
    $applied = app(InventoryAdjustmentApprovalService::class)->submit([[
        'variant' => $f['variant'], 'inventory_tracked' => true, 'on_hand_quantity' => 7, 'reason' => 'Previous count',
    ]], $f['requester']->id, $f['admin']->id);
    $applied->update(['status' => InventoryAdjustmentRequest::STATUS_APPLIED]);
    $this->actingAs($f['admin']);
    Livewire::test(ListInventoryAdjustmentRequests::class)
        ->callTableBulkAction('discardPendingPushes', [$f['request'], $applied], data: [
            'reason' => 'Use Shopify', 'accept_shopify' => true,
        ])->assertHasTableBulkActionErrors(['fingerprint']);
    expect($f['request']->fresh()->status)->toBe('APPROVED')
        ->and($applied->fresh()->status)->toBe('APPLIED')
        ->and(InventoryPushCancellation::count())->toBe(0);
    Http::assertNothingSent();
});
