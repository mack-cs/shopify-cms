<?php

use App\Models\Product;
use App\Services\ImageSyncMutex;
use App\Services\StackImageSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Two OS processes recover one abandoned cursor on disposable MySQL. */
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

if (($argv[1] ?? '') === '--recover') {
    [$database, $stackId, $go, $result] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? '', $argv[5] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $database) || getenv('DB_DATABASE') !== $database || $stackId < 1) {
        throw new RuntimeException('Recovery database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'images.component_stack_propagation' => true,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => true,
        'queue.default' => 'database',
    ]);
    $deadline = microtime(true) + 20;
    while ((! is_file($go) || file_get_contents($go) !== 'go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    try {
        file_put_contents($result, (string) app(StackImageSync::class)->recoverAbandonedCursors());
    } catch (Throwable $exception) {
        file_put_contents($result, 'error:'.$exception->getMessage());
    }
    exit(0);
}

if (($argv[1] ?? '') === '--hold') {
    [$database, $stackId, $ready, $release] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? '', $argv[5] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $database) || getenv('DB_DATABASE') !== $database || $stackId < 1) {
        throw new RuntimeException('Lock holder database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
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
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'array',
        'COMPONENT_STACK_IMAGE_PROPAGATION' => 'true',
        'COMPONENT_STACK_LOCAL_REBUILD' => 'false',
        'COMPONENT_STACK_BLIND_PUBLICATION' => 'true',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
        throw new RuntimeException('Refusing migration: audit database connection guard failed.');
    }
    config([
        'images.component_stack_propagation' => true,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => true,
        'queue.default' => 'database',
    ]);
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    require_once $root.'/tests/Support/shopify-image-import.php';
    $stack = createImageImportProduct('RECOVER-STACK', ['is_bundle' => true, 'status' => 'active']);
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 4,
        'working_generation' => 2,
        'status' => 'running',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-ready';
    $release = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-release';
    @unlink($ready);
    @unlink($release);
    $holder = proc_open([PHP_BINARY, __FILE__, '--hold', $database, (string) $stack->id, $ready, $release], [
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
        $holderError = stream_get_contents($holderPipes[2]);
        throw new RuntimeException('The lock holder did not acquire the stack mutex. '.$holderError);
    }
    $recoveredWhileHeld = app(StackImageSync::class)->recoverAbandonedCursors();
    $statusWhileHeld = (string) DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status');
    $jobsWhileHeld = (int) DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count();
    file_put_contents($release, 'go');
    $holderExit = proc_close($holder);
    if ($recoveredWhileHeld !== 0 || $statusWhileHeld !== 'running' || $jobsWhileHeld !== 0 || $holderExit !== 0) {
        throw new RuntimeException('A live lock was recovered. recovered='.$recoveredWhileHeld.' status='.$statusWhileHeld.' jobs='.$jobsWhileHeld.' holder='.$holderExit);
    }
    echo "PASS: a live worker holding the stack lock was not recovered\n";

    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-go';
    @unlink($go);
    $results = [];
    $workers = [];
    foreach ([1, 2] as $index) {
        $results[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-result-'.$index;
        @unlink($results[$index]);
        $workers[$index] = proc_open([PHP_BINARY, __FILE__, '--recover', $database, (string) $stack->id, $go, $results[$index]], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (! is_resource($workers[$index])) {
            throw new RuntimeException('Could not start recovery worker '.$index);
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
    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    $jobs = (int) DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count();
    $errors = array_filter($outcomes, fn (string $outcome): bool => str_starts_with($outcome, 'error') || $outcome === 'missing');
    if ($errors !== [] || $jobs !== 1 || $cursor->status !== 'queued' || (int) $cursor->desired_generation !== 4 || (int) $cursor->working_generation !== 2) {
        throw new RuntimeException('Concurrent recovery dispatched more than one job. jobs='.$jobs.' status='.$cursor->status.' desired='.$cursor->desired_generation.' working='.$cursor->working_generation.' outcomes='.implode(',', $outcomes));
    }
    echo 'PASS: two processes recovered one cursor. jobs=1 status=queued outcomes='.implode(',', $outcomes)."\n";
} finally {
    try {
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped disposable database {$database}\n";
    } catch (Throwable $exception) {
        echo 'Database cleanup failed: '.$exception->getMessage()."\n";
    }
}
