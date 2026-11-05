<?php

// Opt-in, disposable localhost-only integration harness. Never uses application tables.
if (getenv('SYV_RUN_ISOLATED_MYSQL') !== '1') {
    exit("Set SYV_RUN_ISOLATED_MYSQL=1 to run the disposable MySQL lock test.\n");
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Jobs\HandleShopifyInventoryLevelUpdatedJob;
use App\Jobs\PushShopYourVibe;
use App\Jobs\SendSyvSortApprovalEmailJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\ShopYourVibeDraft;
use App\Models\SyvNotification;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryLevelReconciliation;
use App\Services\InventoryWriteIntents;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

$connection = config('database.connections.mysql');
if (! in_array($connection['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing to run: only a localhost MySQL server is permitted.');
}
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'syv_risk_isolated_'.bin2hex(random_bytes(8));
if (! preg_match('/^syv_risk_isolated_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid isolated test database name.');
}
$server = null;
if (! $worker) {
    $server = new PDO('mysql:host='.$connection['host'].';port='.($connection['port'] ?? 3306).';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
config()->set('database.default', 'mysql');
config()->set('database.connections.mysql.database', $database);
config()->set('database.connections.mysql.url', null);
config()->set('cache.default', 'database');
config()->set('permission.cache.store', 'array');
config()->set('permission.cache.key', $database);
config()->set('session.driver', 'array');
DB::purge('mysql');
Http::preventStrayRequests();

function riskAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    } echo "PASS: $message\n";
}
function riskStart(string $database, string $mode, int $id, string $marker): array
{
    $pipes = [];
    $proc = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,fsockopen,stream_socket_client', __FILE__, 'worker', $database, $mode, (string) $id, $marker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($proc)) {
        throw new RuntimeException('Cannot start worker');
    } fclose($pipes[0]);

    return [$proc, $pipes];
}
function riskJoin(array $child): string
{
    [$proc,$pipes] = $child;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException($out.$err);
    }

return trim($out);
}
function riskAwait(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! file_exists($path) && microtime(true) < $deadline) {
        usleep(20000);
    } if (! file_exists($path)) {
        throw new RuntimeException('Worker barrier timed out');
    }
}
function riskEvent(string $name)
{
    return collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === $name);
}
function riskPayload(int $reviewer): array
{
    return ['reviewer_id' => $reviewer, 'submitter_name' => 'Staff', 'collection_name' => 'Test', 'submitted_at' => now()->toISOString(), 'moved_count' => 1, 'status' => 'Awaiting approval', 'review_url' => '/shop-your-vibe'];
}

if ($worker) {
    $mode = $argv[3];
    $id = (int) $argv[4];
    $marker = $argv[5];
    try {
        if ($mode === 'lock') {
            $flow = app(ShopYourVibeWorkflow::class);
            $draft = ShopYourVibeDraft::findOrFail($id);
            (new ReflectionMethod($flow, 'holdOperationLock'))->invoke($flow, $draft);
            file_put_contents($marker, 'owned');
            riskAwait($marker.'.release');
            (new ReflectionMethod($flow, 'releaseOperationLock'))->invoke($flow, $draft);
            echo 'released';
        } elseif ($mode === 'mail') {
            Mail::shouldReceive('to')->once()->andReturn(new class($marker)
            {
                public function __construct(public string $marker) {}

                public function send($mail): void
                {
                    DB::table('risk_deliveries')->insert(['kind' => 'mail']);
                    file_put_contents($this->marker, 'sending');
                    riskAwait($this->marker.'.release');
                }
            });
            (new SendSyvSortApprovalEmailJob($id))->handle();
            echo 'sent';
        } elseif ($mode === 'scheduler') {
            $schedule = app(Schedule::class);
            $event = riskEvent('stack-sellability-retries');
            $event->before(fn () => DB::table('risk_deliveries')->insert(['kind' => 'schedule']));
            if ($schedule->serverShouldRun($event, Carbon::createFromTimestamp($id))) {
                $event->run(app());
                echo 'ran';
            } else {
                echo 'excluded';
            }
        } elseif ($mode === 'approval' || $mode === 'reject') {
            $request = InventoryAdjustmentRequest::findOrFail($id);
            file_put_contents($marker, 'loaded');
            $service = app(InventoryAdjustmentApprovalService::class);
            $result = $mode === 'approval' ? $service->approve($request, $request->approver_id) : $service->reject($request, $request->approver_id, 'Competing decision');
            echo $result->status;
        } elseif ($mode === 'claim') {
            file_put_contents($marker, 'loaded');
            echo ProcurementSupplierReceipt::whereKey($id)->whereNull('shopify_adjustment_started_at')->update(['status' => 'processing', 'shopify_adjustment_started_at' => now()]);
        } elseif ($mode === 'intent') {
            file_put_contents($marker, 'loaded');
            app(InventoryWriteIntents::class)->claim(Variant::findOrFail($id), ['tracked' => true, 'target' => 9, 'location' => 'test'], 'competing-batch');
            echo 'claimed';
        } elseif ($mode === 'webhook') {
            app(InventoryLevelReconciliation::class)->record('risk-item');
            $job = new HandleShopifyInventoryLevelUpdatedJob('risk-item');
            echo (new UniqueLock(Cache::store()))->acquire($job) ? 'acquired' : 'coalesced';
        } elseif ($mode === 'queue') {
            echo app('queue')->connection('inventory-events')->pop('risk-visibility') ? 'visible' : 'hidden';
        }
    } catch (RuntimeException $e) {
        echo 'blocked:'.$e->getMessage();
    }
    exit;
}

$markers = [];
$makeMarker = function () use (&$markers): string {
    $path = sys_get_temp_dir().'/syvrisk_'.bin2hex(random_bytes(8));
    $markers[] = $path;

    return $path;
};
try {
    Artisan::call('migrate', ['--force' => true]);
    config(['google_sheets.enabled' => false, 'shop_your_vibe.sort_approval_emails' => true]);
    app()->instance(ShopifyGraphqlGateway::class, $fake = new VibeShopifyFake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $actor = User::factory()->create(['is_active' => true]);
    $actor->assignRole(RolesEnum::Admin->value);
    Auth::onceUsingId($actor->id);
    $flow = app(ShopYourVibeWorkflow::class);
    $draft = $flow->open('gid://shopify/Collection/1');
    $card = $draft->desired['cards'][0];
    $draft = $flow->edit($draft->id, $draft->revision, 'edit_card', ['key' => $card['key'], 'name' => 'Test layout', 'image' => $card['image'], 'link' => $card['link']]);
    $draft = $flow->queue($draft->id, $draft->revision);
    $ticket = $draft->editing_state['push'];
    (new ReflectionMethod($flow, 'holdOperationLock'))->invoke($flow, $draft);
    $marker = $makeMarker();
    $child = riskStart($database, 'lock', $draft->id, $marker);
    riskAssert(str_starts_with(riskJoin($child), 'blocked:'), 'A competing worker cannot enter a live parent operation lease');
    DB::table('cache_locks')->where('key', 'like', '%shop-your-vibe-op:'.$draft->collection_gid)->update(['expiration' => time() - 1]);
    $child = riskStart($database, 'lock', $draft->id, $marker);
    riskAwait($marker);
    $calls = count($fake->calls);
    $blocked = false;
    try {
        $flow->push($draft->id, $ticket);
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'lease was lost');
    }
    riskAssert($blocked && count($fake->calls) === $calls, 'An expired parent owner cannot issue another Shopify write after takeover');
    (new ReflectionMethod($flow, 'releaseOperationLock'))->invoke($flow, $draft);
    riskAssert(ShopYourVibeWorkflow::operationRunning($draft->collection_gid), 'The old owner cannot release its replacement owner lease');
    touch($marker.'.release');
    riskJoin($child);
    riskAssert(! ShopYourVibeWorkflow::operationRunning($draft->collection_gid), 'The replacement owner releases only its own lease');

    $recoveryFlow = app(ShopYourVibeWorkflow::class);
    (new ReflectionMethod($recoveryFlow, 'holdOperationLock'))->invoke($recoveryFlow, $draft);
    (new PushShopYourVibe($draft->id, $ticket))->failed(new RuntimeException('Final attempt ended'));
    riskAssert($draft->fresh()->status === 'pushing', 'Terminal cleanup defers while a worker lease is still held');
    $desired = $draft->desired;
    $snapshot = $draft->snapshot;
    DB::table('cache_locks')->where('key', 'like', '%shop-your-vibe-op:'.$draft->collection_gid)->update(['expiration' => time() - 1]);
    riskEvent('syv-parent-recovery')->run(app());
    $draft->refresh();
    riskAssert($draft->status === 'failed' && $draft->pending && $draft->desired === $desired && $draft->snapshot === $snapshot, 'Actual scheduled recovery fences the ended parent and preserves all pending and confirmed state');

    Schema::create('risk_deliveries', fn (Blueprint $table) => $table->string('kind'));
    $reviewer = User::factory()->create();
    $notification = SyvNotification::create(['idempotency_key' => 'risk-mail', 'type' => 'sort_approval', 'status' => 'pending', 'payload' => riskPayload($reviewer->id)]);
    $marker = $makeMarker();
    $child = riskStart($database, 'mail', $notification->id, $marker);
    riskAwait($marker);
    Mail::shouldReceive('to')->never();
    (new SendSyvSortApprovalEmailJob($notification->id))->handle();
    touch($marker.'.release');
    riskJoin($child);
    riskAssert(DB::table('risk_deliveries')->where('kind', 'mail')->count() === 1, 'Competing mail workers deliver one notification once');

    $event = riskEvent('stack-sellability-retries');
    $event->mutex->create($event);
    $mutex = DB::table('cache_locks')->where('key', 'like', '%'.$event->mutexName())->first();
    riskAssert($mutex && $mutex->expiration - time() <= 120 && $event->onOneServer, 'Actual retry schedule uses a short crash lease and single-server election');
    $event->mutex->forget($event);
    $event->before(fn () => DB::table('risk_deliveries')->insert(['kind' => 'schedule']));
    $tick = time();
    $schedule = app(Schedule::class);
    riskAssert($schedule->serverShouldRun($event, Carbon::createFromTimestamp($tick)), 'First scheduler wins the shared per-tick election');
    $event->run(app());
    riskAssert(riskJoin(riskStart($database, 'scheduler', $tick, $makeMarker())) === 'excluded', 'Second scheduler is excluded even after the first callback completed');
    riskAssert(DB::table('risk_deliveries')->where('kind', 'schedule')->count() === 1, 'Actual stack callback resolves successfully and runs once per tick');

    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $requester = User::factory()->create(['is_active' => true]);
    $approver = User::factory()->create(['is_active' => true]);
    foreach ([$requester, $approver] as $person) {
        $person->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    }
    $import = Import::create(['filename' => 'risk', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $actor->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/9001', 'handle' => 'risk', 'title' => 'Risk', 'status' => 'active']));
    $variant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'sku' => 'RISK', 'inventory_tracked' => true, 'inventory_qty' => 4, 'current_on_hand_quantity' => 4, 'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/9001']));
    $approval = app(InventoryAdjustmentApprovalService::class);
    $request = $approval->submit([['variant' => $variant, 'inventory_tracked' => true, 'on_hand_quantity' => 9, 'reason' => 'Count']], $requester->id, $approver->id);
    DB::beginTransaction();
    InventoryAdjustmentRequest::whereKey($request->id)->lockForUpdate()->first();
    $marker = $makeMarker();
    $child = riskStart($database, 'approval', $request->id, $marker);
    riskAwait($marker);
    usleep(150000);
    riskAssert(proc_get_status($child[0])['running'], 'Competing approval waits for the request row lock');
    $approval->approve($request, $approver->id);
    DB::commit();
    riskAssert(riskJoin($child) === 'APPROVED' && DB::table('jobs')->where('queue', 'inventory-sync')->count() === 1, 'Competing approvals produce one approved intent and one sync job');
    riskAssert($variant->fresh()->current_on_hand_quantity === 4, 'Approval does not overwrite confirmed physical inventory before Shopify confirmation');

    $request = $approval->submit([['variant' => $variant->fresh(), 'inventory_tracked' => true, 'on_hand_quantity' => 8, 'reason' => 'Second count']], $requester->id, $approver->id);
    DB::beginTransaction();
    InventoryAdjustmentRequest::whereKey($request->id)->lockForUpdate()->first();
    $marker = $makeMarker();
    $child = riskStart($database, 'reject', $request->id, $marker);
    riskAwait($marker);
    usleep(150000);
    $approval->approve($request, $approver->id, false);
    DB::commit();
    riskAssert(riskJoin($child) === 'APPROVED' && $request->fresh()->status === 'APPROVED', 'A stale competing rejection cannot undo committed approval');

    $variant->refresh();
    DB::beginTransaction();
    Variant::whereKey($variant->id)->lockForUpdate()->first();
    $marker = $makeMarker();
    $child = riskStart($database, 'intent', $variant->id, $marker);
    riskAwait($marker);
    usleep(150000);
    app(InventoryWriteIntents::class)->claim($variant, ['tracked' => true, 'target' => 9, 'location' => 'test'], 'first-batch');
    DB::commit();
    riskAssert(str_contains(riskJoin($child), 'uncertain outcome'), 'A competing inventory batch cannot pass an earlier durable in-flight mutation');

    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-RISK', 5, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 5, 'risk-receipt', dispatch: false);
    DB::beginTransaction();
    DB::table('procurement_supplier_receipts')->where('id', $receipt->id)->lockForUpdate()->first();
    $marker = $makeMarker();
    $child = riskStart($database, 'claim', $receipt->id, $marker);
    riskAwait($marker);
    usleep(150000);
    riskAssert(proc_get_status($child[0])['running'], 'Competing receipt claimant waits on the InnoDB row');
    ProcurementSupplierReceipt::whereKey($receipt->id)->whereNull('shopify_adjustment_started_at')->update(['status' => 'processing', 'shopify_adjustment_started_at' => now()]);
    DB::commit();
    riskAssert(riskJoin($child) === '0', 'Only one supplier delta claimant can win');
    $receipt->update(['status' => 'manual_review']);
    $blocked = false;
    try {
        app(SupplierReceiptService::class)->create($line, 5, 'replacement-key', dispatch: false);
    } catch (ValidationException $e) {
        $blocked = true;
    }
    riskAssert($blocked && $line->receipts()->count() === 1, 'A new receipt key cannot bypass ambiguous-outcome quarantine');

    $reconcile = app(InventoryLevelReconciliation::class);
    $reconcile->record('risk-item');
    $unique = new UniqueLock(Cache::store());
    $first = new HandleShopifyInventoryLevelUpdatedJob('risk-item');
    $unique->acquire($first);
    riskAssert(riskJoin(riskStart($database, 'webhook', 0, $makeMarker())) === 'coalesced', 'Concurrent inventory event coalescing still prevents redundant dispatch');
    $unique->release($first);
    riskEvent('inventory-level-reconciliation')->run(app());
    riskAssert(DB::table('inventory_reconciliations')->where('inventory_item_id', 'risk-item')->value('version') === 2
        && DB::table('jobs')->where('queue', 'inventory-events')->count() === 1, 'The coalesced newer event remains durable and the actual callback schedules recovery');

    $queue = app('queue')->connection('inventory-events');
    $queue->pushRaw('{"displayName":"risk-sentinel"}', 'risk-visibility');
    $reserved = $queue->pop('risk-visibility');
    DB::table('jobs')->where('id', $reserved->getJobId())->update(['reserved_at' => time() - 301]);
    riskAssert(riskJoin(riskStart($database, 'queue', 0, $makeMarker())) === 'hidden', 'An inventory event remains invisible until its 300-second worker timeout plus margin');
    DB::table('jobs')->where('id', $reserved->getJobId())->update(['reserved_at' => time() - 361]);
    riskAssert(riskJoin(riskStart($database,'queue',0,$makeMarker())) === 'visible', 'A crashed inventory event recovers after six minutes rather than four hours');
    riskAssert(config('queue.connections.database.retry_after') >= 14520,'Long procurement visibility remains protected');
} finally {
    if (DB::transactionLevel()) {
        DB::rollBack();
    } DB::disconnect('mysql');
    $server->exec('DROP DATABASE `'.$database.'`');
    foreach ($markers as $marker) {
        if (file_exists($marker)) {
            unlink($marker);
        } if (file_exists($marker.'.release')) {
            unlink($marker.'.release');
        }
    }
}
