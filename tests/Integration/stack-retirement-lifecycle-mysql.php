<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** Disposable MySQL proof for deferred retirement identity and a concurrent membership change. */
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

if (($argv[1] ?? '') === '--restore-during-lock') {
    [$expectedDatabase, $draftId, $componentId, $signal, $result] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), $argv[5] ?? '', $argv[6] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $expectedDatabase) || getenv('DB_DATABASE') !== $expectedDatabase || $draftId < 1) {
        throw new RuntimeException('Concurrent worker database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $deadline = microtime(true) + 25;
    while (! is_file($signal) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($signal)) {
        file_put_contents($result, 'no-boundary');
        exit(0);
    }
    try {
        $draft = NewProductDraft::query()->findOrFail($draftId);
        $draft->bundle_product_ids = [$componentId];
        $draft->save();
        file_put_contents($result, 'committed');
    } catch (Throwable $exception) {
        file_put_contents($result, str_contains($exception->getMessage(), 'was not saved') ? 'rejected' : 'error:'.$exception->getMessage());
    }
    exit(0);
}

if (($argv[1] ?? '') === '--die-holding') {
    $expectedDatabase = $argv[2] ?? '';
    $lockName = $argv[3] ?? '';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if ((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS locked', [$lockName])->locked !== 1) {
        throw new RuntimeException('Worker could not take the stack lock.');
    }
    echo 'holding';
    sleep(30);
    exit(0);
}

if (($argv[1] ?? '') === '--contend') {
    $expectedDatabase = $argv[2] ?? '';
    $componentId = (int) ($argv[3] ?? 0);
    $hold = $argv[4] ?? '';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $boundary = app(App\Services\StackSynchronizationBoundary::class);
    $started = microtime(true);
    try {
        $boundary->acquireForComponent($componentId);
        $boundary->release();
        echo 'acquired-immediately';
        exit(0);
    } catch (Throwable $exception) {
        if (! str_contains($exception->getMessage(), 'was not saved')) {
            throw $exception;
        }
        file_put_contents($hold.'.blocked', '1');
    }
    $deadline = microtime(true) + 15;
    while (is_file($hold) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $boundary->acquireForComponent($componentId);
    $boundary->release();
    if (microtime(true) - $started > 20) {
        throw new RuntimeException('Shared-component lock attempt exceeded the deadlock bound.');
    }
    echo 'failed-closed-then-acquired';
    exit(0);
}

if (($argv[1] ?? '') === '--shared-writers') {
    [$expectedDatabase, $draftId, $componentId, $stackId, $signal, $result] = [
        $argv[2] ?? '', (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), (int) ($argv[5] ?? 0), $argv[6] ?? '', $argv[7] ?? '',
    ];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $expectedDatabase) || getenv('DB_DATABASE') !== $expectedDatabase || $draftId < 1) {
        throw new RuntimeException('Shared-writer database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $deadline = microtime(true) + 20;
    while (! is_file($signal) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $rejected = false;
    try {
        $draft = NewProductDraft::query()->findOrFail($draftId);
        $draft->bundle_product_ids = [];
        $draft->save();
    } catch (Throwable $exception) {
        $rejected = str_contains($exception->getMessage(), 'was not saved');
        if (! $rejected) {
            file_put_contents($result, 'error:'.$exception->getMessage());
            exit(1);
        }
    }
    if (! $rejected) {
        file_put_contents($result, 'committed-while-locked');
        exit(1);
    }
    file_put_contents($result, 'rejected');
    $deadline = microtime(true) + 20;
    while (is_file($signal) && microtime(true) < $deadline) {
        usleep(20000);
    }
    $stack = Product::query()->findOrFail($stackId);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $draft = NewProductDraft::query()->findOrFail($draftId);
    $draft->bundle_product_ids = [];
    $draft->save();
    file_put_contents($result, 'rejected-then-rebuilt');
    exit(0);
}

if (($argv[1] ?? '') === '--hold-image-lock') {
    [$expectedDatabase, $productId, $ready, $release] = [$argv[2] ?? '', (int) ($argv[3] ?? 0), $argv[4] ?? '', $argv[5] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $expectedDatabase) || getenv('DB_DATABASE') !== $expectedDatabase || $productId < 1) {
        throw new RuntimeException('Image-lock holder database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mutex = new App\Services\ImageSyncMutex;
    if (! $mutex->acquire($productId)) {
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

if (($argv[1] ?? '') === '--uncommitted-membership') {
    [$expectedDatabase, $draftId, $componentId, $ready, $release] = [
        $argv[2] ?? '', (int) ($argv[3] ?? 0), (int) ($argv[4] ?? 0), $argv[5] ?? '', $argv[6] ?? '',
    ];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $expectedDatabase) || getenv('DB_DATABASE') !== $expectedDatabase || $draftId < 1) {
        throw new RuntimeException('Uncommitted membership database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $draft = NewProductDraft::query()->findOrFail($draftId);
    DB::beginTransaction();
    try {
        $draft->bundle_product_ids = [$componentId];
        $draft->save();
        file_put_contents($ready, 'holding');
        $deadline = microtime(true) + 30;
        while (! is_file($release) && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (! is_file($release)) {
            throw new RuntimeException('Retirement was not attempted while membership was uncommitted.');
        }
        DB::commit();
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (! is_file($ready) || file_get_contents($ready) === 'holding') {
            file_put_contents($ready, 'error:'.$exception->getMessage());
        }
        exit(1);
    }
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
        'DB_PORT' => $port,
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $user,
        'DB_PASSWORD' => $password,
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'COMPONENT_STACK_IMAGE_PROPAGATION' => 'false',
    ] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
        throw new RuntimeException('Refusing migration: audit database connection guard failed.');
    }
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    require_once $root.'/tests/Support/shopify-image-import.php';
    Bus::fake();
    Storage::fake('public');
    config([
        'images.component_stack_propagation' => true,
        'services.shopify.shop' => 'retirement-audit.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
    ]);

    $component = createImageImportProduct('MYSQL-RETIRE');
    $asset = createImportTestAsset('mysql-retirement-bytes', 'jpg', 'mysql.jpg');
    $source = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    $stack = createImageImportProduct('MYSQL-RETIRE-STACK', ['is_bundle' => true, 'status' => 'draft', 'tags' => 'stack']);
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'STACK-'.$stack->id, 'handle' => $stack->handle, 'shopify_id' => $stack->shopify_id,
        'title' => 'Stack', 'tags' => 'stack', 'bundle_product_ids' => [$component->id],
    ]));
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/8801',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id, 'component_image_id' => $source->id,
        'image_asset_id' => $asset->id, 'asset_sha256' => $asset->sha256, 'selection_revision' => 1,
        'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id, 'component_product_id' => $component->id,
        'component_image_id' => $source->id, 'generation_id' => $generation, 'purpose' => 'remove',
        'phase' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $removals = 0;
    $shopifyResponse = function ($request) use (&$removals) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        if (str_contains($query, 'fileUpdate')) {
            $removals++;
        }

        return Http::response(['data' => ['product' => ['media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]]);
    };
    Http::fake(function ($request) use (&$shopifyResponse) {
        return $shopifyResponse($request);
    });
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    $reader = new PDO($dsn.';dbname='.$database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $intent = $reader->query('SELECT * FROM inherited_media_retirements')->fetch(PDO::FETCH_ASSOC);
    $count = (int) $reader->query('SELECT COUNT(*) FROM inherited_media_retirements')->fetchColumn();
    if ($removals !== 0 || $count !== 1 || ($intent['status'] ?? '') !== 'pending'
        || ($intent['old_media_id'] ?? '') !== 'gid://shopify/MediaImage/8801'
        || (int) ($intent['component_image_id'] ?? 0) !== $source->id
        || ($intent['asset_sha256'] ?? '') !== $asset->sha256
        || ! str_contains((string) ($intent['deferral_reason'] ?? ''), 'deferred')) {
        throw new RuntimeException('Draft retirement intent was not durable on MySQL. removals='.$removals.' count='.$count);
    }
    echo "PASS: committed draft retirement intent is visible to a second MySQL session and was not duplicated\n";

    $draft->forceFill(['bundle_product_ids' => [$component->id]])->save();
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    DB::table('stack_image_sync_tasks')->where('id', $task)->update(['phase' => 'pending', 'last_error' => null]);
    $node = [
        'id' => 'gid://shopify/MediaImage/8801', 'status' => 'READY', 'fileStatus' => 'READY', 'alt' => null,
        'image' => ['url' => 'https://cdn.shopify.com/s/files/mysql-8801.jpg'],
        'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/mysql-8801.jpg'],
    ];
    $gateway = new class($node) implements ShopifyGraphqlGateway {
        public int $removals = 0;

        public function __construct(private array $node) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'fileUpdate') && array_key_exists('referencesToRemove', $variables['files'][0] ?? [])) {
                $this->removals++;
            }

            return ['product' => [
                'media' => ['nodes' => [$this->node], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
    $shopifyResponse = fn ($request) => $request->method() === 'GET'
        ? Http::response('mysql-retirement-bytes')
        : Http::response(['data' => $gateway->graphql($request->data()['query'] ?? '', $request->data()['variables'] ?? [])]);
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    if ($gateway->removals !== 0 || $copy->fresh()->sync_state !== Image::SYNC_STATE_SYNCED) {
        throw new RuntimeException('Retirement deleted a photograph that was already required. removals='.$gateway->removals);
    }
    echo "PASS: retirement refused an image that was required before authorization\n";

    $draft->forceFill(['bundle_product_ids' => []])->save();
    DB::table('inherited_media_retirements')->update(['status' => 'pending', 'resolution' => null, 'updated_at' => now()]);
    DB::table('stack_image_sync_tasks')->where('id', $task)->update(['phase' => 'pending', 'last_error' => null]);
    $signal = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-boundary';
    $result = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-result';
    @unlink($signal);
    @unlink($result);
    $worker = proc_open([PHP_BINARY, __FILE__, '--restore-during-lock', $database, (string) $draft->id, (string) $component->id, $signal, $result], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (! is_resource($worker)) {
        throw new RuntimeException('Could not start the membership worker.');
    }
    fclose($pipes[0]);
    $boundaryGateway = new class($node, $signal, $result, $draft->id) implements ShopifyGraphqlGateway {
        public int $removals = 0;

        public bool $membershipEmptyAtDispatch = false;

        public function __construct(private array $node, private string $signal, private string $result, private int $draftId) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'fileUpdate') && array_key_exists('referencesToRemove', $variables['files'][0] ?? [])) {
                $status = DB::table('inherited_media_retirements')->value('status');
                if ($status !== 'mutating') {
                    throw new RuntimeException('The destructive mutation was dispatched before the intent was mutating.');
                }
                file_put_contents($this->signal, 'boundary');
                $deadline = microtime(true) + 25;
                while (! is_file($this->result) && microtime(true) < $deadline) {
                    usleep(20000);
                }
                $raw = DB::table('new_product_drafts')->where('id', $this->draftId)->value('bundle_product_ids');
                $ids = is_array($raw) ? $raw : json_decode((string) $raw, true);
                $this->membershipEmptyAtDispatch = $ids === [] || $ids === null;
                $this->removals++;

                return ['fileUpdate' => ['files' => [['id' => $variables['files'][0]['id'] ?? '']], 'userErrors' => []]];
            }

            return ['product' => [
                'media' => ['nodes' => str_contains($query, 'StackRetirementConfirmed') ? [] : [$this->node], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $boundaryGateway);
    $shopifyResponse = function ($request) use ($boundaryGateway) {
        if ($request->method() === 'GET') {
            return Http::response('mysql-retirement-bytes');
        }
        $query = (string) data_get($request->data(), 'query', '');
        $variables = (array) data_get($request->data(), 'variables', []);
        $payload = $boundaryGateway->graphql($query, $variables);
        if (str_contains($query, 'StackRetirementVariantMedia')) {
            $payload = ['productVariant' => ['media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
        }

        return Http::response(['data' => $payload]);
    };
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    $workerError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $workerStatus = proc_close($worker);
    $workerResult = is_file($result) ? file_get_contents($result) : '';
    @unlink($signal);
    @unlink($result);
    $taskPhase = (string) DB::table('stack_image_sync_tasks')->where('id', $task)->value('phase');
    $taskError = (string) DB::table('stack_image_sync_tasks')->where('id', $task)->value('last_error');
    if ($workerStatus !== 0 || $workerResult !== 'rejected' || ! $boundaryGateway->membershipEmptyAtDispatch || $boundaryGateway->removals !== 1) {
        throw new RuntimeException('The membership writer committed during retirement. result='.$workerResult.' removals='.$boundaryGateway->removals.' phase='.$taskPhase.' error='.$taskError.' worker='.$workerError);
    }
    echo "PASS: a second process could not restore membership before the destructive mutation\n";

    $lockName = App\Services\ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$stack->id;
    $holder = proc_open([PHP_BINARY, __FILE__, '--die-holding', $database, $lockName], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $holderPipes, $root);
    if (! is_resource($holder)) {
        throw new RuntimeException('Could not start the lock holder.');
    }
    fclose($holderPipes[0]);
    stream_set_blocking($holderPipes[1], false);
    $holding = '';
    $deadline = microtime(true) + 20;
    while (! str_contains($holding, 'holding') && microtime(true) < $deadline) {
        $holding .= (string) stream_get_contents($holderPipes[1]);
        usleep(20000);
    }
    proc_terminate($holder);
    proc_close($holder);
    $reacquired = (int) DB::selectOne('SELECT GET_LOCK(?, 15) AS locked', [$lockName])->locked;
    if ($reacquired === 1) {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }
    if ($reacquired !== 1) {
        throw new RuntimeException('The stack lock was not released when its worker process died.');
    }
    echo "PASS: worker termination released the stack lock for the next attempt\n";

    $second = createImageImportProduct('MYSQL-RETIRE-STACK-2', ['is_bundle' => true, 'status' => 'active', 'tags' => 'stack']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'STACK-'.$second->id, 'handle' => $second->handle, 'shopify_id' => $second->shopify_id,
        'title' => 'Second stack', 'tags' => 'stack', 'bundle_product_ids' => [$component->id],
    ]));
    $draft->forceFill(['bundle_product_ids' => [$component->id]])->save();
    $hold = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-hold';
    @unlink($hold);
    $owner = app(App\Services\StackSynchronizationBoundary::class)->acquireForComponent((int) $component->id);
    file_put_contents($hold, 'held');
    $contender = proc_open([PHP_BINARY, __FILE__, '--contend', $database, (string) $component->id, $hold], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $contendPipes, $root);
    if (! is_resource($contender)) {
        throw new RuntimeException('Could not start the contending worker.');
    }
    fclose($contendPipes[0]);
    $deadline = microtime(true) + 20;
    while (! is_file($hold.'.blocked') && microtime(true) < $deadline) {
        usleep(20000);
    }
    $owner->release();
    @unlink($hold);
    @unlink($hold.'.blocked');
    $contendOutput = stream_get_contents($contendPipes[1]);
    $contendError = stream_get_contents($contendPipes[2]);
    fclose($contendPipes[1]);
    fclose($contendPipes[2]);
    if (proc_close($contender) !== 0 || ! str_contains((string) $contendOutput, 'failed-closed-then-acquired')) {
        throw new RuntimeException('Shared-component locking deadlocked or waited. '.$contendError);
    }
    echo "PASS: stacks that share a component lock in order and the loser retries without deadlocking\n";

    $sharedSignal = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-shared';
    $sharedResult = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-shared-result';
    @unlink($sharedSignal);
    @unlink($sharedResult);
    $sharedDraft = NewProductDraft::query()->where('shopify_id', $second->shopify_id)->first();
    $held = app(App\Services\StackSynchronizationBoundary::class)->acquireForComponent((int) $component->id);
    file_put_contents($sharedSignal, 'held');
    $shared = proc_open([PHP_BINARY, __FILE__, '--shared-writers', $database, (string) $sharedDraft->id, (string) $component->id, (string) $second->id, $sharedSignal, $sharedResult], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $sharedPipes, $root);
    if (! is_resource($shared)) {
        throw new RuntimeException('Could not start the shared-component writer.');
    }
    fclose($sharedPipes[0]);
    $deadline = microtime(true) + 25;
    while (microtime(true) < $deadline) {
        $marker = is_file($sharedResult) ? file_get_contents($sharedResult) : '';
        if ($marker === 'rejected') {
            break;
        }
        $raw = DB::table('new_product_drafts')->where('id', $sharedDraft->id)->value('bundle_product_ids');
        $ids = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if ($ids === [] || $ids === null) {
            $held->release();
            throw new RuntimeException('Membership was committed while the shared component locks were held.');
        }
        usleep(20000);
    }
    if (! is_file($sharedResult) || file_get_contents($sharedResult) !== 'rejected') {
        $held->release();
        throw new RuntimeException('The shared writer did not fail closed.');
    }
    @unlink($sharedSignal);
    $held->release();
    $sharedError = stream_get_contents($sharedPipes[2]);
    fclose($sharedPipes[1]);
    fclose($sharedPipes[2]);
    $sharedOutput = is_file($sharedResult) ? file_get_contents($sharedResult) : '';
    @unlink($sharedResult);
    if (proc_close($shared) !== 0 || $sharedOutput !== 'rejected-then-rebuilt') {
        throw new RuntimeException('Shared membership and gallery rebuild did not recover after the lock. '.$sharedOutput.' '.$sharedError);
    }
    echo "PASS: shared-component membership and gallery rebuild wait for the lock and then commit\n";

    $draft->forceFill(['bundle_product_ids' => [$component->id]])->save();
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active', 'is_bundle' => true, 'tags' => 'stack'])->save());
    Image::withoutEvents(fn () => $source->forceFill([
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/8802',
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
        'shopify_identity_unresolved' => false,
        'shopify_media_create_uncertain' => false,
    ])->save());
    if ((int) ($component->approval_version ?? 0) < 1) {
        Product::withoutEvents(fn () => $component->forceFill(['approval_version' => 1])->save());
        $component->refresh();
    }
    foreach ([1, 2] as $ignored) {
        $approver = App\Models\User::factory()->create();
        $component->approvals()->create(['user_id' => $approver->id, 'approval_version' => $component->approval_version]);
    }
    $publishGeneration = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $asset->id,
        'asset_sha256' => $asset->sha256,
        'shopify_media_id' => 'gid://shopify/MediaImage/8802',
        'selection_revision' => 100,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $publishTask = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $publishGeneration,
        'purpose' => 'publish',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $contentionReady = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-image-lock-ready';
    $contentionRelease = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-image-lock-release';
    @unlink($contentionReady);
    @unlink($contentionRelease);
    $contentionProcess = proc_open([PHP_BINARY, __FILE__, '--hold-image-lock', $database, (string) $stack->id, $contentionReady, $contentionRelease], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $contentionPipes, $root);
    if (! is_resource($contentionProcess)) {
        throw new RuntimeException('Could not start the image-lock holder.');
    }
    fclose($contentionPipes[0]);
    $deadline = microtime(true) + 25;
    while ((! is_file($contentionReady) || file_get_contents($contentionReady) !== 'holding') && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (! is_file($contentionReady) || file_get_contents($contentionReady) !== 'holding') {
        throw new RuntimeException('The second process did not acquire the stack lock.');
    }
    $contentionMessage = '';
    try {
        app(ComponentStackPropagation::class)->synchronizeTask($publishTask);
    } catch (Throwable $exception) {
        $contentionMessage = $exception->getMessage();
    }
    $publishPhase = (string) DB::table('stack_image_sync_tasks')->where('id', $publishTask)->value('phase');
    file_put_contents($contentionRelease, '1');
    $contentionError = stream_get_contents($contentionPipes[2]);
    fclose($contentionPipes[1]);
    fclose($contentionPipes[2]);
    $contentionStatus = proc_close($contentionProcess);
    $contentionProcess = null;
    @unlink($contentionReady);
    @unlink($contentionRelease);
    if ($contentionStatus !== 0 || ! str_contains($contentionMessage, 'Another image operation is running') || $publishPhase === 'verified') {
        throw new RuntimeException('A second process did not block stack publication. message='.$contentionMessage.' phase='.$publishPhase.' worker='.$contentionError);
    }
    $released = new App\Services\ImageSyncMutex;
    if (! $released->acquire((int) $stack->id)) {
        throw new RuntimeException('The stack lock was not released after the contending process finished.');
    }
    $released->release();
    echo "PASS: a second process holding the stack lock stopped publication before the task was verified\n";

    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active', 'is_bundle' => true, 'tags' => 'stack'])->save());
    $copy->refresh();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/8801',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'ownership' => App\Services\ImageOwnership::COMPONENT_INHERITED,
        'needs_shopify_image_sync' => false,
    ])->save());
    DB::table('stack_image_sync_tasks')->where('id', $task)->update([
        'purpose' => 'remove', 'phase' => 'pending', 'attempts' => 0, 'last_error' => null, 'updated_at' => now(),
    ]);
    $uncommittedReady = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-uncommitted-ready';
    $uncommittedRelease = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-uncommitted-release';
    @unlink($uncommittedReady);
    @unlink($uncommittedRelease);
    $uncommittedProcess = proc_open([PHP_BINARY, __FILE__, '--uncommitted-membership', $database, (string) $draft->id, (string) $component->id, $uncommittedReady, $uncommittedRelease], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $uncommittedPipes, $root);
    if (! is_resource($uncommittedProcess)) {
        throw new RuntimeException('Could not start the uncommitted membership holder.');
    }
    fclose($uncommittedPipes[0]);
    $deadline = microtime(true) + 25;
    while ((! is_file($uncommittedReady) || file_get_contents($uncommittedReady) !== 'holding') && microtime(true) < $deadline) {
        usleep(20000);
    }
    $uncommittedState = is_file($uncommittedReady) ? (string) file_get_contents($uncommittedReady) : '';
    if ($uncommittedState !== 'holding') {
        throw new RuntimeException('The membership holder did not keep the lock across its open transaction. state='.$uncommittedState);
    }
    $staleRemovals = 0;
    $staleGateway = new class($node) implements ShopifyGraphqlGateway {
        public int $removals = 0;

        public function __construct(private array $node) {}

        public function graphql(string $query, array $variables = []): array
        {
            if (str_contains($query, 'fileUpdate') && array_key_exists('referencesToRemove', $variables['files'][0] ?? [])) {
                $this->removals++;
            }

            return ['product' => [
                'media' => ['nodes' => [$this->node], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ]];
        }
    };
    app()->instance(ShopifyGraphqlGateway::class, $staleGateway);
    $shopifyResponse = function ($request) use (&$staleRemovals, $staleGateway) {
        $query = (string) data_get($request->data(), 'query', '');
        if (str_contains($query, 'fileUpdate')) {
            $staleRemovals++;
        }

        return Http::response(['data' => $staleGateway->graphql($query, (array) data_get($request->data(), 'variables', []))]);
    };
    $staleMessage = '';
    try {
        app(ComponentStackPropagation::class)->synchronizeTask($task);
    } catch (Throwable $exception) {
        $staleMessage = $exception->getMessage();
    }
    $stalePhase = (string) DB::table('stack_image_sync_tasks')->where('id', $task)->value('phase');
    $staleImage = (string) $copy->fresh()->sync_state;
    file_put_contents($uncommittedRelease, '1');
    $uncommittedError = stream_get_contents($uncommittedPipes[2]);
    fclose($uncommittedPipes[1]);
    fclose($uncommittedPipes[2]);
    $uncommittedStatus = proc_close($uncommittedProcess);
    $uncommittedProcess = null;
    @unlink($uncommittedReady);
    @unlink($uncommittedRelease);
    $restored = json_decode((string) DB::table('new_product_drafts')->where('id', $draft->id)->value('bundle_product_ids'), true);
    if (is_string($restored)) {
        $restored = json_decode($restored, true);
    }
    if ($uncommittedStatus !== 0 || $staleRemovals !== 0 || $staleGateway->removals !== 0 || $stalePhase === 'verified' || $staleImage === Image::SYNC_STATE_LOCAL_DELETED || $restored !== [$component->id]) {
        throw new RuntimeException('Retirement used stale membership while restoration was uncommitted. removals='.$staleRemovals.'/'.$staleGateway->removals.' phase='.$stalePhase.' image='.$staleImage.' message='.$staleMessage.' membership='.json_encode($restored).' worker='.$uncommittedError);
    }
    $shopifyResponse = function ($request) use (&$staleRemovals, $staleGateway) {
        return Http::response(['data' => $staleGateway->graphql((string) data_get($request->data(), 'query', ''), (array) data_get($request->data(), 'variables', []))]);
    };
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    if ($staleGateway->removals !== 0 || $copy->fresh()->sync_state === Image::SYNC_STATE_LOCAL_DELETED) {
        throw new RuntimeException('Retirement authorized removal after the membership restoration committed.');
    }
    echo "PASS: an uncommitted membership restoration blocked retirement from using stale membership\n";
    echo "Shopify was not contacted.\n";
} finally {
    if (isset($contentionRelease) && is_string($contentionRelease) && $contentionRelease !== '') {
        file_put_contents($contentionRelease, '1');
    }
    if (isset($contentionProcess) && is_resource($contentionProcess)) {
        proc_terminate($contentionProcess);
        proc_close($contentionProcess);
    }
    if (isset($uncommittedRelease) && is_string($uncommittedRelease) && $uncommittedRelease !== '') {
        file_put_contents($uncommittedRelease, '1');
    }
    if (isset($uncommittedProcess) && is_resource($uncommittedProcess)) {
        proc_terminate($uncommittedProcess);
        proc_close($uncommittedProcess);
    }
    $reader = null;
    try {
        Illuminate\Support\Facades\DB::disconnect();
    } catch (Throwable) {
    }
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo = null;
    echo "Removed only disposable database {$database}.\n";
}
