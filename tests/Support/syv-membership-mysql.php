<?php

// Opt-in disposable localhost harness. Creates and drops its own database.
if (getenv('SYV_RUN_ISOLATED_MYSQL') !== '1') {
    exit("Set SYV_RUN_ISOLATED_MYSQL=1 to run the disposable MySQL membership test.\n");
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\Support\VibeShopifyFake;

$connection = config('database.connections.mysql');
if (! in_array($connection['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing to run: only a localhost MySQL server is permitted.');
}
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'syv_membership_isolated_'.bin2hex(random_bytes(8));
if (! preg_match('/^syv_membership_isolated_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid isolated test database name.');
}
$server = null;
if (! $worker) {
    $server = new PDO(
        'mysql:host='.$connection['host'].';port='.($connection['port'] ?? 3306).';charset=utf8mb4',
        $connection['username'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
config()->set('database.default', 'mysql');
config()->set('database.connections.mysql.database', $database);
config()->set('database.connections.mysql.url', null);
config()->set('cache.default', 'array');
config()->set('queue.default', 'sync');
DB::purge('mysql');
Http::preventStrayRequests();

if ($worker) {
    [, , , $draftId, $userId, $marker] = $argv;
    Auth::onceUsingId((int) $userId);
    app()->instance(ShopifyGraphqlGateway::class, new VibeShopifyFake);
    file_put_contents($marker, 'started');
    $draft = app(ShopYourVibeWorkflow::class)->edit((int) $draftId, (int) ShopYourVibeDraft::findOrFail($draftId)->revision, 'add_products', [
        'collection_gid' => 'gid://shopify/Collection/1',
        'ids' => ['gid://shopify/Product/104'],
    ]);
    echo json_encode(array_column($draft->desired['collections']['gid://shopify/Collection/1']['products'], 'id'));
    exit;
}

try {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(ShopifyGraphqlGateway::class, new VibeShopifyFake);
    Role::findOrCreate(RolesEnum::Admin->value);
    $cait = User::factory()->create(['name' => 'Cait', 'email' => 'cait-mysql@example.com', 'is_active' => true]);
    $leanne = User::factory()->create(['name' => 'Leanne', 'email' => 'leanne-mysql@example.com', 'is_active' => true]);
    $import = Import::create(['filename' => 'syv-mysql', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $cait->id, 'is_current' => true, 'is_valid' => true]);
    ShopifyCollection::withoutEvents(fn () => ShopifyCollection::create([
        'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Collection/1', 'title' => 'Necklaces', 'handle' => 'necklaces',
    ]));
    foreach ([101, 102, 103, 104] as $id) {
        Product::withoutEvents(fn () => Product::create([
            'import_id' => $import->id,
            'shopify_id' => 'gid://shopify/Product/'.$id,
            'handle' => 'product-'.$id,
            'title' => 'Product '.$id,
        ]));
    }
    Auth::onceUsingId($cait->id);
    $draft = app(ShopYourVibeWorkflow::class)->open('gid://shopify/Collection/1');
    $original = array_column($draft->desired['collections']['gid://shopify/Collection/1']['products'], 'id');
    $moved = $original;
    $first = array_shift($moved);
    $moved[] = $first;
    $desired = $draft->desired;
    $desired['collections']['gid://shopify/Collection/1']['products'] = array_map(
        fn (string $id) => collect($desired['collections']['gid://shopify/Collection/1']['products'])->firstWhere('id', $id),
        $original
    );
    $draft->update(['desired' => $desired, 'snapshot' => $desired, 'pending' => true, 'status' => 'pending']);

    DB::beginTransaction();
    $locked = ShopYourVibeDraft::query()->lockForUpdate()->findOrFail($draft->id);
    $marker = tempnam(sys_get_temp_dir(), 'syvmem');
    $command = [PHP_BINARY, __FILE__, 'worker', $database, (string) $draft->id, (string) $leanne->id, $marker];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    $deadline = microtime(true) + 20;
    while ((! is_file($marker) || file_get_contents($marker) !== 'started') && microtime(true) < $deadline && proc_get_status($process)['running']) {
        usleep(20000);
    }
    if (file_get_contents($marker) !== 'started') {
        $errors = stream_get_contents($pipes[2]);
        throw new RuntimeException('The second connection did not start. '.$errors);
    }
    usleep(300000);
    if (! proc_get_status($process)['running']) {
        $errors = stream_get_contents($pipes[2]);
        throw new RuntimeException('The membership update did not wait on the open draft lock. '.$errors);
    }
    $fresh = $locked->desired;
    $products = $fresh['collections']['gid://shopify/Collection/1']['products'];
    $reordered = $products;
    $item = array_shift($reordered);
    $reordered[] = $item;
    $fresh['collections']['gid://shopify/Collection/1']['products'] = $reordered;
    $locked->update(['desired' => $fresh]);
    DB::commit();
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $ids = json_decode($output, true);
    $expected = array_column($reordered, 'id');
    $expected[] = 'gid://shopify/Product/104';
    if ($exit !== 0 || $ids !== $expected) {
        throw new RuntimeException('Concurrent membership add did not keep the locked order. exit='.$exit.' output='.$output.' errors='.$errors);
    }
    echo "MySQL: overlapping membership add waited for the open draft lock and preserved the reordered products.\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('mysql');
    if ($server) {
        $server->exec('DROP DATABASE `'.$database.'`');
        echo "Disposable MySQL test database removed.\n";
    }
}
