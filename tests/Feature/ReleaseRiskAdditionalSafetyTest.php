<?php

use App\Enums\PermissionEnum;
use App\Jobs\InventorySyncJob;
use App\Jobs\SendSyvSortApprovalEmailJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\SyvNotification;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryOperationContext;
use App\Services\InventoryWriteIntents;
use App\Services\ProductInventorySyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';

function releaseApprovalFixture(): array
{
    Bus::fake();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $import = Import::create(['filename' => 'release.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $requester->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/8811', 'handle' => 'release', 'title' => 'Release', 'status' => 'active']));
    $variant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/8811', 'sku' => 'RELEASE', 'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/8811', 'inventory_tracked' => true, 'inventory_qty' => 4, 'current_on_hand_quantity' => 4, 'current_available_quantity' => 4]));
    $service = app(InventoryAdjustmentApprovalService::class);
    $request = $service->submit([['variant' => $variant, 'inventory_tracked' => true, 'on_hand_quantity' => 9, 'reason' => 'Verified physical count']], $requester->id, $reviewer->id);
    $service->approve($request, $reviewer->id, false);
    $request->refresh();

    return compact('requester', 'reviewer', 'product', 'variant', 'service', 'request');
}

function releaseInventoryResponses(int $quantity, bool $confirmTarget = true, ?Closure $onRead = null, bool $missingPayload = false, bool $multipleLocations = false): void
{
    config(['services.shopify.shop' => 'test.myshopify.com', 'services.shopify.admin_access_token' => 'fake', 'services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    Http::fake(function ($request) use (&$quantity, $confirmTarget, $onRead, $missingPayload, $multipleLocations) {
        $query = $request['query'];
        if (str_contains($query, 'query ProductInventoryById')) {
            if ($onRead) {
                $onRead();
            }
            $item = ['id' => 'gid://shopify/InventoryItem/8811', 'tracked' => true, 'inventoryLevels' => ['nodes' => [['location' => ['id' => 'gid://shopify/Location/1'], 'quantities' => [['name' => 'on_hand', 'quantity' => $quantity], ['name' => 'available', 'quantity' => $quantity]]]]]];
            if ($multipleLocations) {
                $item['inventoryLevels']['nodes'][] = ['location' => ['id' => 'gid://shopify/Location/2'], 'quantities' => [['name' => 'on_hand', 'quantity' => 2], ['name' => 'available', 'quantity' => 2]]];
            }

            return Http::response(['data' => ['product' => ['id' => 'gid://shopify/Product/8811', 'status' => 'ACTIVE', 'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/8811', 'sku' => 'RELEASE', 'inventoryQuantity' => $quantity, 'inventoryItem' => $item]]]]]]);
        }
        if (str_contains($query, 'InventorySetQuantities')) {
            expect($request['variables']['input']['quantities'][0]['changeFromQuantity'])->toBe($quantity);
            expect($request['variables']['idempotencyKey'])->toHaveLength(64);
            if ($confirmTarget) {
                $quantity = $request['variables']['input']['quantities'][0]['quantity'];
            }

            return Http::response(['data' => $missingPayload ? [] : ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toISOString()], 'userErrors' => []]]]);
        }
        throw new RuntimeException('Unexpected fake inventory query: '.$query);
    });
}

it('stops stale approved counts before mutation and keeps the last confirmed stock', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(6);
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class, 'stock changed');
    Http::assertNotSent(fn ($request) => str_contains($request['query'], 'mutation'));
    expect($variant->fresh()->current_on_hand_quantity)->toBe(4)->and($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED)->and(DB::table('inventory_write_intents')->count())->toBe(0);
});

it('rejects divergent readback even with a valid adjustment group and never replays it', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(4, false);
    $job = new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id);
    expect(fn () => $job->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class);
    $job->handle(app(ProductInventorySyncService::class), $service);
    Http::assertSentCount(3); // First read, mutation, failed readback. The blocked retry does not read Shopify again.
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]['query'], 'InventorySetQuantities')))->toHaveCount(1);
    expect($variant->fresh()->current_on_hand_quantity)->toBe(4)->and($variant->fresh()->inventory_local_dirty)->toBeTrue()->and($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED);
});

it('reauthorizes an approval worker after either participant loses inventory authority', function ($participant) {
    extract(releaseApprovalFixture());
    ${$participant}->revokePermissionTo(PermissionEnum::InventoryUpdate->value);
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(HttpException::class);
    Http::assertNothingSent();
    expect($variant->fresh()->current_on_hand_quantity)->toBe(4);
})->with(['requester', 'reviewer']);

it('rejects a forged approval worker actor even when that actor has inventory permission', function () {
    extract(releaseApprovalFixture());
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $requester->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('rechecks inventory approval authority after a slow Shopify read before sending a mutation', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(4, true, fn () => $reviewer->revokePermissionTo(PermissionEnum::InventoryUpdate->value));
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn ($request) => str_contains($request['query'], 'mutation'));
    expect($variant->fresh()->current_on_hand_quantity)->toBe(4);
});

it('does not repeat an applied approval or overwrite stock received afterwards', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(4);
    $job = new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id);
    $job->handle(app(ProductInventorySyncService::class), $service);
    expect($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPLIED)->and($variant->fresh()->current_on_hand_quantity)->toBe(9);
    Variant::withoutEvents(fn () => $variant->fresh()->update(['current_on_hand_quantity' => 12]));
    $before = count(Http::recorded());
    $job->handle(app(ProductInventorySyncService::class), $service);
    expect(count(Http::recorded()))->toBe($before)->and($variant->fresh()->current_on_hand_quantity)->toBe(12);
});

it('blocks unprivileged generic inventory service and worker calls', function () {
    extract(releaseApprovalFixture());
    $actor = User::factory()->create(['is_active' => true]);
    expect(fn () => app(ProductInventorySyncService::class)->syncVariants(collect([$variant]), $actor->id))->toThrow(HttpException::class);
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $actor->id, 'inventory_manual'))->handle(app(ProductInventorySyncService::class), $service))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('does not automatically resend mail after an ambiguous transport outcome', function () {
    $reviewer = inventoryAuthorizedUser();
    $record = SyvNotification::create(['idempotency_key' => 'uncertain-mail', 'type' => 'sort_approval', 'status' => 'pending', 'payload' => ['reviewer_id' => $reviewer->id, 'submitter_name' => 'Staff', 'collection_name' => 'Test', 'submitted_at' => now()->toISOString(), 'moved_count' => 1, 'status' => 'Awaiting approval', 'review_url' => '/shop-your-vibe']]);
    Mail::shouldReceive('to')->once()->andReturn(new class
    {
        public function send($mail): void
        {
            throw new RuntimeException('Accepted message but transport response lost');
        }
    });
    $job = new SendSyvSortApprovalEmailJob($record->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'response lost');
    $job->handle();
    expect($record->fresh()->status)->toBe('uncertain')->and($record->fresh()->attempts)->toBe(1);
});

it('executes the actual stack retry callback against pending dirty inventory', function () {
    Bus::fake();
    extract(releaseApprovalFixture());
    Product::withoutEvents(fn () => $product->update(['is_bundle' => true]));
    $variant->forceFill(['inventory_local_dirty' => true, 'inventory_sync_batch_id' => 'stack_sellability_pending'])->saveQuietly();
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'stack-sellability-retries');
    $event->run(app());
    Bus::assertDispatched(InventorySyncJob::class, fn ($job) => $job->syncBatchId === 'stack_sellability_retry' && $job->variantIds === [$variant->id]);
});

it('does not overwrite a newer local intent with an older approved count', function () {
    extract(releaseApprovalFixture());
    $variant->refresh()->update(['inventory_policy' => 'continue']);
    releaseInventoryResponses(4);
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class, 'superseded');
    Http::assertNotSent(fn ($request) => str_contains($request['query'], 'mutation'));
    expect($variant->fresh()->inventory_policy)->toBe('continue')->and($variant->fresh()->current_on_hand_quantity)->toBe(4);
});

it('keeps an uncertain mutation held even when a later refresh matches the requested quantity', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(4, true, null, true);
    (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service);
    app(ProductInventorySyncService::class)->refreshVariants(collect([$variant->fresh()]));
    expect($variant->fresh()->inventory_local_dirty)->toBeTrue()->and($variant->fresh()->inventory_sync_error)->toContain('uncertain outcome')
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(4)->and($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED);
    expect(DB::table('inventory_write_intents')->value('status'))->toBe('started');
});

it('gives repeated automatic sellability cycles distinct immutable intent generations', function () {
    extract(releaseApprovalFixture());
    $variant->refresh();
    $intents = app(InventoryWriteIntents::class);
    $ids = [];
    foreach ([[true, 0], [false, null], [true, 0]] as [$tracked,$quantity]) {
        InventoryOperationContext::run(fn () => $variant->update(['inventory_tracked' => $tracked, 'inventory_qty' => $quantity]));
        $intent = $intents->claim($variant->fresh(), ['tracked' => $tracked, 'target' => $quantity, 'location' => 'test'], 'stack_sellability_retry');
        $ids[] = $intent->id;
        $intent->update(['status' => 'confirmed']);
    }
    expect(array_unique($ids))->toHaveCount(3);
});

it('refuses to publish an aggregate physical count into one of several stock locations', function () {
    extract(releaseApprovalFixture());
    releaseInventoryResponses(4, true, null, false, true);
    expect(fn () => (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $request->sync_batch_id))->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class,'multiple inventory locations');
    Http::assertNotSent(fn ($request) => str_contains($request['query'],'mutation'));
    expect($variant->fresh()->current_on_hand_quantity)->toBe(4)->and($request->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED);
});

it('hides single and bulk inventory pushes from staff with only status permission', function () {
    extract(releaseApprovalFixture());
    $actor=App\Models\User::factory()->create(['is_active'=>true]);
    Spatie\Permission\Models\Permission::findOrCreate(App\Enums\PermissionEnum::InventoryStatusUpdate->value);
    $actor->givePermissionTo(App\Enums\PermissionEnum::InventoryStatusUpdate->value); $this->actingAs($actor);
    Livewire\Livewire::test(App\Filament\Resources\InventoryResource\Pages\ListInventories::class)
        ->assertTableActionHidden('pushToShopify',$variant)->assertTableBulkActionHidden('pushToShopify');
});
