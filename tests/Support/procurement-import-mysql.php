<?php

// Opt-in. Only a randomly named disposable localhost database is migrated or dropped.
if (getenv('SYV_RUN_ISOLATED_MYSQL') !== '1') {
    exit("Set SYV_RUN_ISOLATED_MYSQL=1 to run procurement import concurrency tests.\n");
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Import;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

$connection = config('database.connections.mysql');
if (! in_array($connection['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Only localhost MySQL is allowed.');
}
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'procurement_isolated_'.bin2hex(random_bytes(8));
if (! preg_match('/^procurement_isolated_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid disposable database.');
}
$server = null;
if (! $worker) {
    $server = new PDO('mysql:host='.$connection['host'].';port='.($connection['port'] ?? 3306).';charset=utf8mb4', $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null,
    'cache.default' => 'array', 'permission.cache.store' => 'array', 'permission.cache.key' => $database, 'session.driver' => 'array',
    'google_sheets.enabled' => false, 'services.shopify.shop' => 'test.invalid', 'services.shopify.admin_access_token' => 'unused']);
DB::purge('mysql');
Bus::fake();
Http::preventStrayRequests();

if ($worker) {
    file_put_contents($argv[4], 'started');
    try {
        app(SupplierOrderCsvService::class)->confirm($argv[3], dispatchReceipts: false, acceptValidRows: true);
        echo 'confirmed';
    } catch (ValidationException $e) {
        echo 'review-required:'.$e->getMessage();
    }
    Http::assertNothingSent();
    exit;
}

function importAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}
function competingImport(string $database, string $uuid, callable $beforeCommit): string
{
    $marker = tempnam(sys_get_temp_dir(), 'procurement-race-');
    $process = null;
    $pipes = [];
    try {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,fsockopen,stream_socket_client', __FILE__, 'worker', $database, $uuid, $marker],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start competing worker.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 25;
        while (file_get_contents($marker) !== 'started' && microtime(true) < $deadline && proc_get_status($process)['running']) {
            usleep(20000);
        }
        importAssert(file_get_contents($marker) === 'started', 'Competing worker reached confirmation.');
        usleep(200000);
        importAssert(proc_get_status($process)['running'], 'Competing confirmation waits for the locked batch/order line.');
        $beforeCommit();
        DB::commit();
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $process = null;
        if ($code !== 0) {
            throw new RuntimeException($out.$err);
        }

        return trim($out);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($marker);
    }
}

try {
    Artisan::call('migrate', ['--force' => true]);
    $actor = User::withoutEvents(fn () => User::create(['name' => 'Import race', 'email' => 'import-race@test.invalid', 'password' => 'unused', 'is_active' => true]));
    $import = Import::create(['filename' => 'isolated-import', 'mode' => 'append', 'status' => 'ready', 'created_by' => $actor->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'handle' => 'race', 'title' => 'Race', 'shopify_id' => 'gid://shopify/Product/1', 'status' => 'active', 'is_bundle' => false]));
    $variant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'sku' => 'IMPORT-RACE', 'shopify_id' => 'gid://shopify/ProductVariant/1', 'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/1', 'inventory_tracked' => true, 'inventory_qty' => 5, 'current_available_quantity' => 5, 'current_on_hand_quantity' => 5]));
    $orders = app(SupplierOrderService::class);
    $csv = app(SupplierOrderCsvService::class);
    $line = $orders->createForVariant($variant, 'PO-CAPACITY', 5, '2026-11-10');
    $batch = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-CAPACITY\tIMPORT-RACE\t4");
    DB::beginTransaction();
    ProcurementSupplierOrderLine::lockForUpdate()->findOrFail($line->id);
    $result = competingImport($database, $batch->uuid, fn () => app(SupplierReceiptService::class)->create($line, 3, 'overlap-stage', dispatch: false));
    importAssert(str_starts_with($result, 'review-required:') && str_contains($result, 'preview changed'), 'Competing import revalidates the newly staged capacity and requires review.');
    importAssert($line->receipts()->count() === 1 && (int) $line->receipts()->sum('quantity_received') === 3, 'Competing imports do not over-receive or duplicate staged stock.');
    importAssert($batch->fresh()->status === 'previewed' && $batch->fresh()->invalid_count === 1, 'Changed preview commits its explanation without persisting a receipt.');

    $line = $orders->createForVariant($variant, 'PO-IDEMPOTENT', 5, '2026-11-10');
    $batch = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-IDEMPOTENT\tIMPORT-RACE\t2");
    DB::beginTransaction();
    $csv->confirm($batch->uuid, dispatchReceipts: false);
    $result = competingImport($database, $batch->uuid, fn () => null);
    importAssert($result === 'confirmed' && $line->receipts()->count() === 1, 'Two simultaneous confirmations of one receipt batch persist exactly one receipt.');
    importAssert($line->fresh()->quantity_received === 0 && $line->fresh()->quantity_staged === 2 && $line->fresh()->quantity_outstanding === 5, 'Staged stock remains separate from Shopify-confirmed receiving.');

    $batch = $csv->previewPastedOrder("SKU\tQuantity Ordered\tOrder ID\tETA Date\nIMPORT-RACE\t2\tPO-ORDER-RACE\t10/11/2026");
    DB::beginTransaction();
    $csv->confirm($batch->uuid);
    $result = competingImport($database, $batch->uuid, fn () => null);
    importAssert($result === 'confirmed' && ProcurementSupplierOrderLine::where('sku', 'IMPORT-RACE')->whereHas('order', fn ($q) => $q->where('order_number', 'PO-ORDER-RACE'))->count() === 1, 'Two simultaneous confirmations of one purchase order batch persist exactly one order line.');
    Http::assertNothingSent();
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('mysql');
    $server->exec('DROP DATABASE `'.$database.'`');
    echo "Disposable MySQL database removed. No Shopify HTTP calls.\n";
}
