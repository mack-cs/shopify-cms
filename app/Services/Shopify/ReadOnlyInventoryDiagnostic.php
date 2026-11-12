<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\DB;

/** Database-enforced protection, not merely a convention against calling save(). */
final class ReadOnlyInventoryDiagnostic
{
    public function run(callable $callback): mixed
    {
        $names = array_unique(array_filter([config('database.default'), config('queue.failed.database'),
            config('queue.connections.inventory-ledger.connection')]));
        $opened = [];
        try {
            foreach ($names as $name) {
                $connection = DB::connection($name);
                $driver = $connection->getDriverName();
                if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
                    throw new \RuntimeException("Read-only diagnostics do not support database driver {$driver}.");
                }
                if ($connection->transactionLevel() !== 0 && $driver !== 'sqlite') {
                    throw new \RuntimeException('Refusing to join an existing transaction without a read-only guarantee.');
                }
                if ($driver === 'sqlite') {
                    $previous = (int) $connection->selectOne('PRAGMA query_only')->query_only;
                    $connection->statement('PRAGMA query_only = ON');
                    $ownsTransaction = $connection->transactionLevel() === 0;
                    $opened[] = [$connection, $ownsTransaction, $previous];
                    if ($ownsTransaction) {
                        $connection->beginTransaction();
                    }

                    continue;
                }
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    $connection->statement('SET TRANSACTION READ ONLY');
                }
                $connection->beginTransaction();
                $opened[] = [$connection, true, null];
                if ($driver === 'pgsql') {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                }
            }

            return $callback();
        } finally {
            foreach (array_reverse($opened) as [$connection, $transaction, $previous]) {
                if ($transaction) {
                    $connection->rollBack();
                }
                if ($previous !== null) {
                    $connection->statement('PRAGMA query_only = '.($previous ? 'ON' : 'OFF'));
                }
            }
        }
    }
}
