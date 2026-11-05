<?php

// Disposable localhost-only races for approval/refresh and supplier receipts.
// Creates and drops only a database named syv_risk_isolated_<16 hex chars>.
if (getenv('SYV_RUN_ISOLATED_MYSQL') !== '1') {
    exit("Set SYV_RUN_ISOLATED_MYSQL=1 to run the disposable MySQL inventory race test.\n");
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PermissionEnum;
use App\Jobs\InventorySyncJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\ProductInventorySyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

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
    $server = new PDO(
        'mysql:host='.$connection['host'].';port='.($connection['port'] ?? 3306).';charset=utf8mb4',
        $connection['username'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

config()->set('database.default', 'mysql');
config()->set('database.connections.mysql.database', $database);
config()->set('database.connections.mysql.url', null);
config()->set('cache.default', 'database');
config()->set('queue.default', 'database');
DB::purge('mysql');
DB::reconnect('mysql');

function safetyAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function safetyStart(string $database, string $mode, int $id, string $marker): array
{
    $pipes = [];
    $command = [PHP_BINARY, '-d', 'memory_limit=1G', __FILE__, 'worker', $database, $mode, (string) $id, $marker];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start worker');
    }
    fclose($pipes[0]);

    return [$process, $pipes];
}

function safetyJoin(array $child): string
{
    [$process, $pipes] = $child;
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException($output.$error);
    }

    return trim($output);
}

function safetyAwait(string $path): void
{
    $deadline = microtime(true) + 30;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($path)) {
        throw new RuntimeException('Worker barrier timed out');
    }
}

if ($worker) {
    $mode = $argv[3];
    $id = (int) $argv[4];
    $marker = $argv[5];
    try {
        if ($mode === 'refresh') {
            config([
                'services.shopify.shop' => 'cursor-test.myshopify.com',
                'services.shopify.admin_access_token' => 'cursor-token',
                'services.shopify.inventory_location_id' => 'gid://shopify/Location/1',
            ]);
            $variant = Variant::query()->with('product')->findOrFail($id);
            Http::preventStrayRequests();
            Http::fake(fn () => Http::response(['data' => ['product' => [
                'id' => $variant->product->shopify_id,
                'status' => 'ACTIVE',
                'variants' => ['nodes' => [[
                    'id' => $variant->shopify_id,
                    'sku' => $variant->sku,
                    'inventoryQuantity' => null,
                    'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => false, 'inventoryLevels' => ['nodes' => []]],
                ]]],
            ]]]));
            file_put_contents($marker, 'entered');
            app(ProductInventorySyncService::class)->refreshVariants(collect([$variant]));
            $fresh = $variant->fresh();
            echo ((int) $fresh->inventory_local_dirty === 1 ? 'dirty' : 'clean').':'.$fresh->inventory_write_version;
        } elseif ($mode === 'claim') {
            file_put_contents($marker, 'entered');
            echo (string) ProcurementSupplierReceipt::query()->whereKey($id)->whereNull('shopify_adjustment_started_at')->update([
                'status' => 'processing',
                'shopify_adjustment_started_at' => now(),
            ]);
        }
    } catch (Throwable $exception) {
        echo 'blocked:'.$exception->getMessage();
        exit(0);
    }
    exit(0);
}

$markers = [];
try {
    Artisan::call('migrate', ['--force' => true]);
    config([
        'services.shopify.shop' => 'cursor-test.myshopify.com',
        'services.shopify.admin_access_token' => 'cursor-token',
        'services.shopify.inventory_location_id' => 'gid://shopify/Location/1',
        'google_sheets.enabled' => false,
    ]);
    PermissionEnum::InventoryUpdate->value;
    \Spatie\Permission\Models\Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $requester = User::factory()->create(['is_active' => true]);
    $approver = User::factory()->create(['is_active' => true]);
    $requester->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $approver->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $import = Import::query()->create([
        'filename' => 'mysql-safety.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $requester->id,
    ]);
    $product = Product::withoutEvents(fn () => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/mysql-safety',
        'handle' => 'mysql-safety',
        'title' => 'MySQL safety',
        'status' => 'active',
    ]));
    $variant = Variant::withoutEvents(fn () => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/mysql-safety',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/mysql-safety',
        'sku' => 'MYSQL-SAFETY',
        'inventory_tracked' => false,
        'inventory_qty' => null,
    ]));
    $approval = app(InventoryAdjustmentApprovalService::class);
    $request = $approval->approve($approval->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 0,
        'reason' => 'Concurrent refresh must not publish this by itself',
    ]], $requester->id, $approver->id), $approver->id, false);
    $version = (int) $variant->fresh()->inventory_write_version;

    DB::beginTransaction();
    Variant::query()->whereKey($variant->id)->lockForUpdate()->first();
    $marker = sys_get_temp_dir().'/syv_safety_'.bin2hex(random_bytes(8));
    $markers[] = $marker;
    $child = safetyStart($database, 'refresh', $variant->id, $marker);
    safetyAwait($marker);
    usleep(200000);
    safetyAssert(proc_get_status($child[0])['running'], 'A refresh waits while the approved variant row is locked');
    DB::commit();
    $refreshResult = safetyJoin($child);
    safetyAssert(
        $refreshResult === 'dirty:'.$version && $request->fresh()->status === InventoryAdjustmentRequest::STATUS_APPROVED,
        'A concurrent refresh cannot clear or apply an in-flight approved correction',
    );

    $mutations = 0;
    Http::preventStrayRequests();
    Http::fake(function ($http) use ($variant, &$mutations) {
        $query = (string) ($http['query'] ?? '');
        if (str_contains($query, 'InventoryItemTrackingUpdate') || str_contains($query, 'mutation InventorySetQuantities')) {
            $mutations++;
            if (str_contains($query, 'InventoryItemTrackingUpdate')) {
                return Http::response(['data' => ['inventoryItemUpdate' => [
                    'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true],
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()],
                'userErrors' => [],
            ]]]);
        }

        return Http::response(['data' => ['product' => [
            'id' => $variant->product->shopify_id,
            'status' => 'ACTIVE',
            'variants' => ['nodes' => [[
                'id' => $variant->shopify_id,
                'sku' => $variant->sku,
                'inventoryQuantity' => $mutations >= 2 ? 0 : null,
                'inventoryPolicy' => 'DENY',
                'inventoryItem' => [
                    'id' => $variant->shopify_inventory_item_id,
                    'tracked' => $mutations >= 1,
                    'inventoryLevels' => ['nodes' => $mutations >= 2 ? [[
                        'location' => ['id' => 'gid://shopify/Location/1'],
                        'quantities' => [
                            ['name' => 'on_hand', 'quantity' => 0],
                            ['name' => 'available', 'quantity' => 0],
                            ['name' => 'committed', 'quantity' => 0],
                        ],
                    ]] : []],
                ],
            ]]],
        ]]]);
    });
    $variant->refresh();
    (new InventorySyncJob([$variant->id], 'push', $approver->id, $request->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), $approval);
    safetyAssert(
        $mutations === 2
            && $request->fresh()->status === InventoryAdjustmentRequest::STATUS_APPLIED
            && $variant->fresh()->inventory_tracked === true
            && (int) $variant->fresh()->current_on_hand_quantity === 0,
        'The locked approval still publishes once and only after Shopify confirms tracking and quantity',
    );

    $receiptVariant = Variant::withoutEvents(fn () => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/mysql-receipt',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/mysql-receipt',
        'sku' => 'MYSQL-RECEIPT',
        'inventory_tracked' => true,
        'inventory_qty' => 1,
        'current_on_hand_quantity' => 1,
    ]));
    $line = app(SupplierOrderService::class)->createForVariant($receiptVariant, 'PO-MYSQL', 5, '2026-11-10', $requester->id);
    $receipt = app(SupplierReceiptService::class)->create($line, 5, 'mysql-receipt', $requester->id, dispatch: false);
    DB::beginTransaction();
    DB::table('procurement_supplier_receipts')->where('id', $receipt->id)->lockForUpdate()->first();
    $claimMarker = sys_get_temp_dir().'/syv_safety_'.bin2hex(random_bytes(8));
    $markers[] = $claimMarker;
    $claimant = safetyStart($database, 'claim', $receipt->id, $claimMarker);
    safetyAwait($claimMarker);
    usleep(200000);
    safetyAssert(proc_get_status($claimant[0])['running'], 'A second receipt claimant waits on the receipt row');
    $winner = ProcurementSupplierReceipt::query()->whereKey($receipt->id)->whereNull('shopify_adjustment_started_at')->update([
        'status' => 'processing',
        'shopify_adjustment_started_at' => now(),
    ]);
    DB::commit();
    safetyAssert($winner === 1 && safetyJoin($claimant) === '0', 'Only one of two concurrent receipt attempts can claim the Shopify delta');
    $receipt->update(['status' => 'manual_review']);
    $blocked = false;
    try {
        app(SupplierReceiptService::class)->create($line, 1, 'mysql-replacement', $requester->id, dispatch: false);
    } catch (ValidationException) {
        $blocked = true;
    }
    safetyAssert($blocked && $line->receipts()->count() === 1, 'A new receipt key cannot bypass an uncertain receipt on MySQL');
    echo "ISOLATED DATABASE: $database\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('mysql');
    if ($server instanceof PDO) {
        $server->exec('DROP DATABASE `'.$database.'`');
        echo "DROPPED DATABASE: $database\n";
    }
    foreach ($markers as $marker) {
        if (is_file($marker)) {
            unlink($marker);
        }
    }
}
