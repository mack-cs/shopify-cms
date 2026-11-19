<?php

use App\Models\Approval;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ComponentPhotographSelector;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\ImageSyncMutex;
use App\Services\ImageUploadOperations;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Standalone disposable MySQL checks. Does not load or write the configured CMS database. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$settings = is_file($root.'/.env') ? Dotenv\Dotenv::parse(file_get_contents($root.'/.env')) : [];
$host = getenv('IMAGE_AUDIT_MYSQL_HOST') ?: ($settings['DB_HOST'] ?? '127.0.0.1');
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Refusing a nonlocal MySQL server. Supply an explicitly isolated local audit server.');
}
$port = getenv('IMAGE_AUDIT_MYSQL_PORT') ?: ($settings['DB_PORT'] ?? '3306');
$user = getenv('IMAGE_AUDIT_MYSQL_USER') ?: ($settings['DB_USERNAME'] ?? 'root');
$password = getenv('IMAGE_AUDIT_MYSQL_PASSWORD');
$password = $password === false ? ($settings['DB_PASSWORD'] ?? '') : $password;
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if (($argv[1] ?? '') === '--probe-lock') {
    $name = $argv[2] ?? '';
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $name)) {
        throw new RuntimeException('Invalid isolated lock name.');
    }
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$name]);
    echo (string) $stmt->fetchColumn();
    exit(0);
}

if (in_array($argv[1] ?? '', ['--generation-worker', '--claim-worker'], true)) {
    $expectedDatabase = $argv[2] ?? '';
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $expectedDatabase) || getenv('DB_DATABASE') !== $expectedDatabase) {
        throw new RuntimeException('Concurrent worker database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== $expectedDatabase) {
        throw new RuntimeException('Concurrent worker selected another database.');
    }
    Http::preventStrayRequests();
    Bus::fake();
    config(['filesystems.disks.image_audit' => ['driver' => 'local', 'root' => $root.'/storage/framework/testing/'.$expectedDatabase]]);
    if ($argv[1] === '--claim-worker') {
        $image = Image::findOrFail((int) $argv[3]);
        $operation = app(ImageUploadOperations::class)->claim($image, $image->product->shopify_id, []);
        // Terminate at the critical boundary: claim committed, no Shopify request sent.
        exit($operation ? 73 : 74);
    }
    config(['images.component_stack_propagation' => true]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph(Image::findOrFail((int) $argv[3]), true);
    echo 'recorded';
    exit(0);
}

$database = 'image_audit_'.bin2hex(random_bytes(8));
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Created disposable database {$database}\n";
try {
    foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/nonexistent-'.$database.'.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $database,
        'DB_USERNAME' => $user, 'DB_PASSWORD' => $password, 'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'COMPONENT_STACK_IMAGE_PROPAGATION' => 'false'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    Http::preventStrayRequests();
    Bus::fake();
    Mail::fake();
    $actual = DB::selectOne('SELECT DATABASE() AS name')->name;
    if ($actual !== $database) {
        throw new RuntimeException('Refusing migration: audit database connection guard failed.');
    }
    $baselinePaths = array_values(array_filter(glob($root.'/database/migrations/*.php'), fn ($path) => basename($path) < '2026_11_17_170000_add_shopify_media_identity_to_images_table.php'));
    if (Artisan::call('migrate', ['--force' => true, '--realpath' => true, '--path' => $baselinePaths]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    require_once $root.'/tests/Support/shopify-image-import.php';
    $oldProduct = createImageImportProduct('MYSQL-BEFORE-UPGRADE', ['is_bundle' => true]);
    $oldImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $oldProduct->id, 'position' => 1, 'shopify_id' => 'gid://shopify/ProductImage/991',
        'alt_text' => 'Approved before identity migrations', 'alt_text_status' => Image::ALT_APPROVED, 'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $oldApproval = ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $oldProduct->id, 'approval_version' => $oldProduct->approval_version ?? 1, 'requested_by' => User::factory()->create()->id,
        'status' => 'approved', 'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
        'image_alt_snapshot' => ['images' => [['image_id' => $oldImage->id, 'proposed_alt' => $oldImage->alt_text, 'changed' => true]]], 'approved_at' => now(),
    ]));
    // Compare persisted JSON with persisted JSON; MySQL canonicalizes object key order.
    $oldSnapshot = $oldApproval->fresh()->image_alt_snapshot;
    $result = Artisan::call('migrate', ['--force' => true]);
    if ($result !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    echo "PASS: full migration chain on MySQL\n";
    if ($oldImage->fresh()->ownership !== null || $oldImage->fresh()->alt_text !== 'Approved before identity migrations'
        || $oldApproval->fresh()->image_alt_snapshot !== $oldSnapshot || $oldApproval->fresh()->status !== 'approved') {
        throw new RuntimeException('Migration changed legacy ownership, sentence, or approval snapshot.');
    }
    echo "PASS: upgrade of existing legacy image and historical approval records without classification or evidence loss\n";
    foreach (glob($root.'/database/migrations/2026_11_1[789]_*.php') as $file) {
        (require $file)->up();
    }
    echo "PASS: image/recovery migration retry after completed chain\n";

    $mutex = new ImageSyncMutex;
    if (! $mutex->acquire(987654321)) {
        throw new RuntimeException('Audit product mutex unavailable.');
    }
    $mutex->assertOwned();
    $other = new PDO($dsn.';dbname='.$database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $other->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.'987654321']);
    if ((int) $stmt->fetchColumn() !== 0) {
        throw new RuntimeException('A second session acquired an owned image mutex.');
    }
    $mutex->release();
    echo "PASS: ImageSyncMutex owner check and independent-session contention\n";

    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$database]);
    $process = proc_open([PHP_BINARY, __FILE__, '--probe-lock', $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start concurrent lock probe.');
    }
    fclose($pipes[0]);
    $answer = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($answer) !== '0') {
        throw new RuntimeException('Concurrent worker did not observe lock contention: '.$errors);
    }
    $stmt = null;
    $pdo = null; // Session death releases its non-expiring lock.
    $stmt = $other->prepare('SELECT GET_LOCK(?, 2)');
    $stmt->execute([$database]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('The disconnected session left a stuck lock.');
    }
    echo "PASS: separate PHP worker contention and connection-loss lock release\n";

    $connection = DB::connection();
    $connection->beginTransaction();
    $connection->statement('INSERT INTO component_selection_revisions (component_product_id, revision, created_at, updated_at) VALUES (987654321, 1, NOW(), NOW())');
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $other->exec('UPDATE component_selection_revisions SET revision = 2 WHERE component_product_id = 987654321');
        throw new RuntimeException('A second session bypassed an uncommitted revision lock.');
    } catch (PDOException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1205) {
            throw $exception;
        }
    }
    $connection->rollBack();
    echo "PASS: InnoDB revision contention and rollback\n";
    require_once $root.'/tests/Support/shopify-image-import.php';
    $product = createImageImportProduct('MYSQL-CONCURRENT');
    config(['filesystems.disks.image_audit' => ['driver' => 'local', 'root' => $root.'/storage/framework/testing/'.$database]]);
    Storage::disk('image_audit')->put('original.jpg', 'audit photo');
    $asset = ImageAsset::create([
        'sha256' => hash('sha256', 'audit photo'), 'storage_disk' => 'image_audit', 'storage_path' => 'original.jpg',
        'original_filename' => 'audit.jpg', 'source_url' => 'https://cdn.shopify.com/s/files/audit.jpg',
        'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'file_size' => 11, 'status' => ImageAsset::STATUS_AVAILABLE,
        'downloaded_at' => now()->subDay(),
    ]);
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'image_asset_id' => $asset->id, 'last_shopify_synced_image_asset_id' => $asset->id,
        'shopify_id' => 'gid://shopify/MediaImage/12345', 'src' => $asset->source_url, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'alt_text' => 'Historical approved sentence', 'alt_text_status' => Image::ALT_APPROVED,
    ]));
    foreach (range(1, 2) as $ignored) {
        Approval::withoutEvents(fn () => Approval::create([
            'product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $product->approval_version ?? 1,
        ]));
    }
    $request = ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $product->id, 'approval_version' => $product->approval_version ?? 1, 'requested_by' => User::factory()->create()->id,
        'status' => 'approved', 'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
        'image_alt_snapshot' => ['images' => [['image_id' => $image->id, 'src' => $asset->source_url, 'proposed_alt' => $image->alt_text, 'changed' => true]]],
        'approved_at' => now(),
    ]));
    if (app(ProductPartialApprovalService::class)->approvedAlt($image->fresh()) !== $image->alt_text) {
        throw new RuntimeException('Legacy MySQL approval evidence was not preserved.');
    }
    $stack = createImageImportProduct('MYSQL-LEGACY', ['is_bundle' => true]);
    $legacy = Image::withoutEvents(fn () => Image::create(['product_id' => $stack->id, 'ownership' => null, 'image_asset_id' => $asset->id, 'alt_text' => 'Do not guess ownership']));
    if (app(ProductPartialApprovalService::class)->approvedAlt($legacy) !== null || ImageOwnership::effective($legacy) !== 'unresolved') {
        throw new RuntimeException('Legacy null ownership was guessed.');
    }
    echo "PASS: MySQL historical approval JSON and unresolved ownership safeguards\n";
    if (! $product->fresh()->isApprovedByTwo() || ! app(ComponentPhotographSelector::class)->confirmed($image->fresh())) {
        throw new RuntimeException('The concurrent-worker fixture is not approved and confirmed.');
    }
    $connection->beginTransaction();
    $connection->table('products')->where('id', $product->id)->lockForUpdate()->first();
    $workers = [];
    foreach (range(1, 2) as $ignored) {
        $worker = proc_open([PHP_BINARY, __FILE__, '--generation-worker', $database, (string) $image->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $channels, $root);
        if (! is_resource($worker)) {
            throw new RuntimeException('Could not start a generation worker.');
        }
        fclose($channels[0]);
        $workers[] = [$worker, $channels];
    }
    usleep(250000);
    $connection->commit();
    foreach ($workers as [$worker, $channels]) {
        $output = stream_get_contents($channels[1]);
        $errors = stream_get_contents($channels[2]);
        fclose($channels[1]);
        fclose($channels[2]);
        if (proc_close($worker) !== 0 || trim($output) !== 'recorded') {
            throw new RuntimeException('Concurrent generation allocation failed: '.$errors.$output);
        }
    }
    if ($connection->table('component_photograph_generations')->where('component_product_id', $product->id)->count() !== 1
        || $connection->table('component_publication_outbox')->where('component_product_id', $product->id)->count() !== 1) {
        throw new RuntimeException('Concurrent workers allocated duplicate generations or outbox records.');
    }
    echo "PASS: two concurrent application workers allocate one generation and one outbox record\n";
    $claimers = [];
    foreach (range(1, 2) as $ignored) {
        $worker = proc_open([PHP_BINARY, __FILE__, '--claim-worker', $database, (string) $image->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $channels, $root);
        if (! is_resource($worker)) {
            throw new RuntimeException('Could not start an upload-claim worker.');
        }
        fclose($channels[0]);
        $claimers[] = [$worker, $channels];
    }
    $exits = [];
    foreach ($claimers as [$worker, $channels]) {
        stream_get_contents($channels[1]);
        $error = stream_get_contents($channels[2]);
        fclose($channels[1]);
        fclose($channels[2]);
        $exits[] = proc_close($worker);
    }
    sort($exits);
    if ($exits !== [73, 74] || $connection->table('image_upload_operations')->where('active_image_id', $image->id)->count() !== 1) {
        throw new RuntimeException('Concurrent upload claims were not safely serialized: '.json_encode($exits).' '.$error);
    }
    if (! $image->fresh()->shopify_media_create_uncertain || app(ImageUploadOperations::class)->claim($image->fresh(), $product->shopify_id, []) !== null) {
        throw new RuntimeException('A terminated worker lost its durable upload claim.');
    }
    echo "PASS: concurrent upload claims and worker termination leave one durable uncertain operation; no replay\n";
    echo "Shopify was not contacted. No production database was selected.\n";
} finally {
    $auditAssetPath = $root.'/storage/framework/testing/'.$database.'/original.jpg';
    if (is_file($auditAssetPath)) {
        unlink($auditAssetPath);
        @rmdir(dirname($auditAssetPath));
    }
    DB::disconnect();
    $cleanup = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cleanup->exec("DROP DATABASE `{$database}`");
    echo "Removed only disposable database {$database}\n";
}
