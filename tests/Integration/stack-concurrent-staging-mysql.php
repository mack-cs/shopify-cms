<?php

use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use App\Services\ImageSyncMutex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Two OS processes stage one unchanged stack on disposable MySQL. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$settings = is_file($root.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($root.'/.env')) : [];
$host = getenv('IMAGE_AUDIT_MYSQL_HOST') ?: ($settings['DB_HOST'] ?? '127.0.0.1');
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Refusing a nonlocal MySQL server.');
}
$port = getenv('IMAGE_AUDIT_MYSQL_PORT') ?: ($settings['DB_PORT'] ?? '3306');
$user = getenv('IMAGE_AUDIT_MYSQL_USER') ?: ($settings['DB_USERNAME'] ?? 'root');
$password = getenv('IMAGE_AUDIT_MYSQL_PASSWORD');
$password = $password === false ? ($settings['DB_PASSWORD'] ?? '') : $password;
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if (($argv[1] ?? '') === '--stage') {
    [$database, $stackId, $disk, $go, $result] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? '', $argv[5] ?? '', $argv[6] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $database) || getenv('DB_DATABASE') !== $database || $stackId < 1) {
        throw new RuntimeException('Concurrent staging database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['filesystems.disks.public.root' => $disk]);
    $deadline = microtime(true) + 20;
    while ((! is_file($go) || file_get_contents($go) !== 'go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    try {
        $stack = Product::query()->findOrFail($stackId);
        app(ComponentStackPropagation::class)->stageStack($stack);
        file_put_contents($result, 'staged');
    } catch (Throwable $exception) {
        file_put_contents($result, str_contains($exception->getMessage(), 'was not saved') ? 'rejected' : 'error:'.$exception->getMessage());
    }
    exit(0);
}

if (($argv[1] ?? '') === '--hold') {
    [$database, $stackId, $disk, $ready, $release] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? '', $argv[5] ?? '', $argv[6] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $database) || getenv('DB_DATABASE') !== $database || $stackId < 1) {
        throw new RuntimeException('Lock holder database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['filesystems.disks.public.root' => $disk]);
    $mutex = new ImageSyncMutex;
    if (! $mutex->acquire($stackId)) {
        file_put_contents($ready, 'not-acquired');
        exit(1);
    }
    file_put_contents($ready, 'holding');
    $deadline = microtime(true) + 30;
    while (! is_file($release) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $mutex->release();
    exit(0);
}

$database = 'image_audit_'.bin2hex(random_bytes(8));
$disk = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-disk';
mkdir($disk);
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Created disposable database {$database}\n";
try {
    foreach ([
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/nonexistent-'.$database.'.php',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $host,
        'DB_PORT' => (string) $port,
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $user,
        'DB_PASSWORD' => $password,
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'COMPONENT_STACK_IMAGE_PROPAGATION' => 'false',
        'COMPONENT_STACK_LOCAL_REBUILD' => 'false',
        'COMPONENT_STACK_BLIND_PUBLICATION' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
        throw new RuntimeException('Refusing migration: audit database connection guard failed.');
    }
    config(['filesystems.disks.public.root' => $disk]);
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    require_once $root.'/tests/Support/shopify-image-import.php';
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
        'filesystems.disks.public.root' => $disk,
    ]);
    $component = createImageImportProduct('CONCURRENT');
    $asset = createImportTestAsset('concurrent-bytes', 'jpg', 'CONCURRENT.jpg');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Concurrent',
        'needs_shopify_image_sync' => false,
    ]));
    $stack = createImageImportProduct('CONCURRENT-STACK', ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'CONCURRENT-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-ready';
    $release = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-release';
    @unlink($ready);
    @unlink($release);
    $holder = proc_open([PHP_BINARY, __FILE__, '--hold', $database, (string) $stack->id, $disk, $ready, $release], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $holderPipes, $root);
    if (! is_resource($holder)) {
        throw new RuntimeException('Could not start the lock holder.');
    }
    fclose($holderPipes[0]);
    $deadline = microtime(true) + 20;
    while ((! is_file($ready) || file_get_contents($ready) !== 'holding') && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($ready) || file_get_contents($ready) !== 'holding') {
        throw new RuntimeException('The lock holder did not acquire the stack mutex.');
    }
    $rejected = false;
    try {
        app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    } catch (Throwable $exception) {
        $rejected = str_contains($exception->getMessage(), 'was not saved');
        if (! $rejected) {
            throw $exception;
        }
    }
    $whileHeld = (int) Image::query()->where('product_id', $stack->id)->count();
    file_put_contents($release, 'go');
    $holderExit = proc_close($holder);
    if (! $rejected || $whileHeld !== 0 || $holderExit !== 0) {
        throw new RuntimeException('Staging was not excluded while another process held the stack lock. rejected='.(int) $rejected.' images='.$whileHeld.' holder='.$holderExit);
    }
    echo "PASS: a second process cannot stage while the stack lock is held\n";

    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-go';
    $results = [];
    $workers = [];
    foreach ([1, 2] as $index) {
        $results[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-result-'.$index;
        @unlink($results[$index]);
        $workers[$index] = proc_open([PHP_BINARY, __FILE__, '--stage', $database, (string) $stack->id, $disk, $go, $results[$index]], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (! is_resource($workers[$index])) {
            throw new RuntimeException('Could not start staging worker '.$index);
        }
        fclose($pipes[0]);
        $workers[$index.'_pipes'] = $pipes;
    }
    usleep(300000);
    file_put_contents($go, 'go');
    foreach ([1, 2] as $index) {
        $pipes = $workers[$index.'_pipes'];
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($workers[$index]);
    }
    $outcomes = [];
    foreach ([1, 2] as $index) {
        $outcomes[] = is_file($results[$index]) ? trim((string) file_get_contents($results[$index])) : 'missing';
    }
    $total = (int) Image::query()->where('product_id', $stack->id)->count();
    $active = (int) Image::query()->where('product_id', $stack->id)->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED)->count();
    if ($total !== 1 || $active !== 1 || in_array('error', array_map(fn (string $outcome): string => str_starts_with($outcome, 'error') ? 'error' : $outcome, $outcomes), true)) {
        throw new RuntimeException('Concurrent staging duplicated the inherited photograph. total='.$total.' active='.$active.' outcomes='.implode(',', $outcomes));
    }
    echo 'PASS: two processes staged one photograph. total=1 active=1 outcomes='.implode(',', $outcomes)."\n";
} finally {
    try {
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped disposable database {$database}\n";
    } catch (Throwable $exception) {
        echo 'Database cleanup failed: '.$exception->getMessage()."\n";
    }
    if (is_dir($disk)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($disk, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($disk);
    }
}
