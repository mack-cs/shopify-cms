<?php

// Opt-in, disposable localhost-only integration harness. Never uses application tables.
if (getenv('SYV_RUN_ISOLATED_MYSQL') !== '1') {
    exit("Set SYV_RUN_ISOLATED_MYSQL=1 to run the disposable MySQL lock test.\n");
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\RolesEnum;
use App\Models\ChangeLog;
use App\Models\ShopYourVibeDraft;
use App\Models\User;
use App\Services\ShopYourVibeEditing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

$connection = config('database.connections.mysql');
if (! in_array($connection['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing to run: only a localhost MySQL server is permitted.');
}
$worker = ($argv[1] ?? '') === 'worker';
$database = $worker ? ($argv[2] ?? '') : 'syv_f4_isolated_'.bin2hex(random_bytes(8));
if (! preg_match('/^syv_f4_isolated_[a-f0-9]{16}$/D', $database)) {
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
config()->set('cache.default', 'array');
config()->set('permission.cache.store', 'array');
config()->set('permission.cache.key', $database);
config()->set('session.driver', 'array');
DB::purge('mysql');
Http::preventStrayRequests();

if ($worker) {
    [$unused, $unusedMode, $unusedDatabase, $mode, $draftId, $revision, $userId, $token, $marker] = $argv;
    Auth::onceUsingId((int) $userId);
    $editing = app(ShopYourVibeEditing::class);
    $editing->token = $token;
    file_put_contents($marker, 'started');
    try {
        if ($mode === 'scope-fail') {
            $editing->failScopePush((int) $draftId, json_decode(base64_decode($token), true, flags: JSON_THROW_ON_ERROR), 'Old worker timeout');
        } elseif ($mode === 'scope-acquire') {
            $editing->acquireScope((int) $draftId, 0, ShopYourVibeEditing::sortScope('gid://shopify/Collection/99'));
        } elseif ($mode === 'discard') {
            $editing->discard((int) $draftId, (int) $revision, 'Competing user reviewed the pending changes');
        } else {
            $editing->heartbeat((int) $draftId, (int) $revision);
        }
        echo json_encode(['result' => 'succeeded']);
    } catch (RuntimeException $e) {
        echo json_encode(['result' => 'blocked', 'message' => $e->getMessage()]);
    }
    exit;
}

function f4Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function f4Compete(string $database, string $mode, ShopYourVibeDraft $draft, User $user, string $token): array
{
    $marker = tempnam(sys_get_temp_dir(), 'syvf4');
    $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,fsockopen,stream_socket_client', __FILE__, 'worker', $database, $mode, (string) $draft->id, (string) $draft->revision, (string) $user->id, $token, $marker];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    try {
        f4Assert(is_resource($process), 'Cannot start the competing PHP connection.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        while (file_get_contents($marker) !== 'started' && microtime(true) < $deadline && proc_get_status($process)['running']) {
            usleep(20000);
        }
        f4Assert(file_get_contents($marker) === 'started', 'The competing connection did not start.');
        usleep(150000);
        f4Assert(proc_get_status($process)['running'], 'Competing operation completed while the draft row was locked.');
        DB::commit();
        $result = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $process = null;
        $decoded = json_decode($result, true);
        f4Assert(is_array($decoded), 'Worker failed: '.$errors);

        return $decoded;
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        unlink($marker);
    }
}

try {
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $a = User::withoutEvents(fn () => User::create(['name' => 'Editor A', 'email' => 'a@test.invalid', 'password' => 'unused', 'is_active' => true]));
    $b = User::withoutEvents(fn () => User::create(['name' => 'Editor B', 'email' => 'b@test.invalid', 'password' => 'unused', 'is_active' => true]));
    $a->assignRole(RolesEnum::Admin->value); $b->assignRole(RolesEnum::Admin->value);
    $editing = app(ShopYourVibeEditing::class);
    $makeDraft = function (int $number) use ($a, $editing): ShopYourVibeDraft {
        Auth::onceUsingId($a->id);
        $snapshot = ['parent' => ['title' => 'Race test'], 'cards' => [], 'collections' => []];
        $draft = ShopYourVibeDraft::create(['collection_gid' => 'gid://shopify/Collection/'.$number, 'snapshot' => $snapshot, 'desired' => $snapshot]);
        $editing->acquire($draft->id, $draft->revision);
        $draft->refresh()->update(['pending' => true, 'revision' => 1, 'desired' => $snapshot + ['local_edit' => 'pending']]);
        $editing->record($draft, 'race_test', []);

        return $draft->fresh();
    };
    $draft = $makeDraft(1);
    DB::beginTransaction();
    ShopYourVibeDraft::lockForUpdate()->findOrFail($draft->id);
    $editing->heartbeat($draft->id, $draft->revision);
    $result = f4Compete($database, 'discard', $draft, $b, (string) $editing->token);
    f4Assert($result['result'] === 'blocked' && str_contains($result['message'], 'Discard blocked'), 'Renewal and competing discard both succeeded.');
    f4Assert(ChangeLog::count() === 0 && $draft->fresh()->pending, 'Blocked discard changed the pending draft or audit.');

    $draft = $makeDraft(2);
    $token = $editing->token;
    $state = $draft->editing_state; $state['expires_at'] = now()->subSecond()->toISOString();
    $draft->update(['editing_state' => $state]);
    Auth::onceUsingId($b->id);
    DB::beginTransaction();
    $editing->discard($draft->id, $draft->revision, 'Expired pending work has been reviewed');
    $result = f4Compete($database, 'renew', $draft, $a, (string) $token);
    f4Assert($result['result'] === 'blocked' && str_contains($result['message'], 'changed'), 'A stale renewal succeeded after discard.');
    f4Assert(ChangeLog::count() === 1 && ! $draft->fresh()->pending, 'Discard and audit did not commit together.');
    // Force stale failure cleanup to wait while a replacement ticket commits.
    Auth::onceUsingId($a->id);
    $scope = ShopYourVibeEditing::sortScope('gid://shopify/Collection/99');
    $scoped = ShopYourVibeDraft::create(['collection_gid' => 'gid://shopify/Collection/99',
        'snapshot' => ['collections' => []], 'desired' => ['collections' => []], 'pending' => true]);
    $scoped = $editing->acquireScope($scoped->id, 0, $scope);
    $oldTicket = $editing->ticketScope($scoped, $scope, 'gid://shopify/Collection/99');
    DB::beginTransaction();
    $scoped = ShopYourVibeDraft::lockForUpdate()->findOrFail($scoped->id);
    $newTicket = $editing->ticketScope($scoped, $scope, 'gid://shopify/Collection/99');
    f4Compete($database, 'scope-fail', $scoped, $a, base64_encode(json_encode($oldTicket)));
    f4Assert($scoped->fresh()->editing_state['locks'][$scope]['push']['id'] === $newTicket['id'], 'Stale failure cleared a replacement ticket.');
    $editing->failScopePush($scoped->id, $newTicket, 'Current worker timeout');
    $state = $scoped->fresh()->editing_state;
    $state['locks'][$scope]['expires_at'] = now()->subSeconds(841)->toISOString();
    $scoped->update(['editing_state' => $state]);
    DB::beginTransaction();
    $scoped = $editing->acquireScope($scoped->id, 0, $scope);
    $replacement = $editing->ticketScope($scoped, $scope, 'gid://shopify/Collection/99');
    $result = f4Compete($database, 'scope-acquire', $scoped, $b, 'unused');
    f4Assert($result['result'] === 'blocked', 'A competing acquire stole a replacement push.');
    f4Assert($scoped->fresh()->editing_state['locks'][$scope]['push']['id'] === $replacement['id'], 'Replacement push was changed by competing acquire.');
    echo "MySQL: scoped stale-failure/replacement and expired-acquire/replacement interleavings passed.\n";
    echo "MySQL: both forced two-process renewal/discard interleavings passed; audit and pending state verified.\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('mysql');
    // Identifier checked above; this can only drop the database created by this run.
    $server->exec('DROP DATABASE `'.$database.'`');
    echo "Disposable MySQL test database removed.\n";
}

