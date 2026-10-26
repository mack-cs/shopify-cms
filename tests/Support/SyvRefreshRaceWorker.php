<?php

// Subprocess fixture: only an explicitly supplied temporary SQLite database is used.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $argv[1]);
config()->set('database.connections.sqlite.busy_timeout', 5000);
config()->set('cache.default', 'array');
config()->set('queue.connections.shop-your-vibe.connection', 'sqlite');
config()->set('queue.connections.shop-your-vibe.driver', 'database');
config()->set('queue.connections.shop-your-vibe.table', 'jobs');
\Illuminate\Support\Facades\DB::purge('sqlite');
file_put_contents($argv[2].'.'.$argv[3].'.ready', 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[2].'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Race test barrier timed out.');
    }
    usleep(1000);
}
echo json_encode(app(\App\Services\ShopYourVibeRefresh::class)->request(1, 1, (int) $argv[3]));
