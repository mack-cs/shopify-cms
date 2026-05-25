<?php

namespace App\Services;

final class InventoryOperationContext
{
    private static int $depth = 0;

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth = max(0, self::$depth - 1);
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
