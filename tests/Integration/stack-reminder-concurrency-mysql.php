<?php

use App\Models\Image;
use App\Services\ImagePublicationReminderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** Two OS processes send one reminder for the same episode on disposable MySQL. */
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

if (($argv[1] ?? '') === '--send') {
    [$database, $go, $log, $result] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '', $argv[5] ?? ''];
    if (! preg_match('/^image_audit_[a-f0-9]{16}$/', $database) || getenv('DB_DATABASE') !== $database) {
        throw new RuntimeException('Reminder database guard failed.');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'mail.default' => 'audit',
        'mail.mailers.audit' => ['transport' => 'audit'],
        'services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za',
    ]);
    Mail::extend('audit', fn () => new class($log) extends AbstractTransport
    {
        public function __construct(private string $log)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            file_put_contents($this->log, "sent\n", FILE_APPEND | LOCK_EX);
        }

        public function __toString(): string
        {
            return 'audit';
        }
    });
    $deadline = microtime(true) + 20;
    while ((! is_file($go) || file_get_contents($go) !== 'go') && microtime(true) < $deadline) {
        usleep(10000);
    }
    try {
        $sent = app(ImagePublicationReminderService::class)->sendDue();
        file_put_contents($result, ($sent['sent'] ? 'sent' : 'skipped').' '.($sent['error'] ?? ''));
    } catch (Throwable $exception) {
        file_put_contents($result, 'error:'.$exception->getMessage());
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
        'DB_PORT' => (string) $port,
        'DB_DATABASE' => $database,
        'DB_USERNAME' => $user,
        'DB_PASSWORD' => $password,
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
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
    echo "Migrated disposable database {$database}\n";
    require_once $root.'/tests/Support/shopify-image-import.php';
    $outerLog = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-outer.log';
    @unlink($outerLog);
    config([
        'mail.default' => 'outer',
        'mail.mailers.outer' => ['transport' => 'outer'],
        'services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za',
    ]);
    Mail::extend('outer', fn () => new class($outerLog) extends AbstractTransport
    {
        public function __construct(private string $log)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            file_put_contents($this->log, "sent\n", FILE_APPEND | LOCK_EX);
        }

        public function __toString(): string
        {
            return 'outer';
        }
    });
    app('mail.manager')->purge('outer');
    $outer = createImageImportProduct('REMIND-OUTER');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $outer->id,
        'position' => 1,
        'alt_text' => 'Waiting',
        'needs_shopify_image_sync' => true,
        'image_attention_since' => now()->subHours(2),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));
    DB::beginTransaction();
    $rejected = app(ImagePublicationReminderService::class)->sendDue();
    $outerSends = is_file($outerLog) ? substr_count((string) file_get_contents($outerLog), 'sent') : 0;
    $outerClaims = (int) DB::table('image_publication_reminders')->where('product_id', $outer->id)->count();
    if ($rejected['sent'] || $outerSends !== 0 || $outerClaims !== 0) {
        throw new RuntimeException('Open transaction sent a reminder. sends='.$outerSends.' claims='.$outerClaims);
    }
    DB::rollBack();
    if ((int) DB::table('image_publication_reminders')->where('product_id', $outer->id)->count() !== 0) {
        throw new RuntimeException('Rolling back the open transaction left a reminder claim.');
    }
    $normal = app(ImagePublicationReminderService::class)->sendDue();
    $outerSends = is_file($outerLog) ? substr_count((string) file_get_contents($outerLog), 'sent') : 0;
    $outerAccepted = (int) DB::table('image_publication_reminders')->where('product_id', $outer->id)->where('kind', 'initial')->where('status', 'accepted')->count();
    if (! $normal['sent'] || $outerSends !== 1 || $outerAccepted !== 1) {
        throw new RuntimeException('Normal reminder did not send once. sends='.$outerSends.' accepted='.$outerAccepted);
    }
    $repeat = app(ImagePublicationReminderService::class)->sendDue();
    $outerSends = is_file($outerLog) ? substr_count((string) file_get_contents($outerLog), 'sent') : 0;
    if ($repeat['sent'] || $outerSends !== 1) {
        throw new RuntimeException('Normal reminder sent more than once. sends='.$outerSends);
    }
    echo "PASS: open transaction sent nothing; normal execution sent once.\n";

    $product = createImageImportProduct('REMIND-RACE');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'alt_text' => 'Waiting',
        'needs_shopify_image_sync' => true,
        'image_attention_since' => now()->subHours(2),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));

    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-go';
    $log = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-sends.log';
    @unlink($go);
    @unlink($log);
    $workers = [];
    foreach ([1, 2] as $index) {
        $result = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'-result-'.$index;
        @unlink($result);
        $workers[$index] = proc_open([PHP_BINARY, __FILE__, '--send', $database, $go, $log, $result], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $root);
        if (! is_resource($workers[$index])) {
            throw new RuntimeException('Could not start reminder worker '.$index);
        }
        fclose($pipes[0]);
        $workers[$index.'_pipes'] = $pipes;
        $workers[$index.'_result'] = $result;
    }
    usleep(300000);
    file_put_contents($go, 'go');
    $errors = [];
    foreach ([1, 2] as $index) {
        $pipes = $workers[$index.'_pipes'];
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($workers[$index]);
        if ($exit !== 0) {
            $errors[] = 'worker '.$index.' exit '.$exit.' '.$error.' '.$output;
        }
    }
    $outcomes = [];
    foreach ([1, 2] as $index) {
        $outcomes[] = is_file($workers[$index.'_result']) ? trim((string) file_get_contents($workers[$index.'_result'])) : 'missing';
    }
    $sends = is_file($log) ? substr_count((string) file_get_contents($log), 'sent') : 0;
    $accepted = (int) DB::table('image_publication_reminders')->where('product_id', $product->id)->where('kind', 'initial')->where('status', 'accepted')->count();
    $sending = (int) DB::table('image_publication_reminders')->where('product_id', $product->id)->where('status', 'sending')->count();
    $failedOutcomes = array_filter($outcomes, fn (string $outcome): bool => str_starts_with($outcome, 'error') || $outcome === 'missing');
    if ($errors !== [] || $failedOutcomes !== [] || $sends !== 1 || $accepted !== 1 || $sending !== 0) {
        throw new RuntimeException('Concurrent reminders were not claimed once. sends='.$sends.' accepted='.$accepted.' sending='.$sending.' outcomes='.implode(' | ', $outcomes).' errors='.implode(' | ', $errors));
    }
    echo 'PASS: two processes sent one reminder. sends=1 accepted=1 outcomes='.implode(',', $outcomes)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getFile().':'.$exception->getLine()."\n");
    throw $exception;
} finally {
    try {
        if (class_exists(Illuminate\Support\Facades\DB::class)) {
            DB::disconnect();
        }
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        echo "Dropped disposable database {$database}\n";
    } catch (Throwable $exception) {
        echo 'Database cleanup failed: '.$exception->getMessage()."\n";
    }
}
