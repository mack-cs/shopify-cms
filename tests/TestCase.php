<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
        $dbConnection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null);
        $dbDatabase = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null);

        if ($appEnv !== 'testing' || $dbConnection !== 'sqlite' || $dbDatabase !== ':memory:') {
            throw new \RuntimeException(
                'Refusing to run tests unless APP_ENV=testing, DB_CONNECTION=sqlite, and DB_DATABASE=:memory:. ' .
                'This prevents RefreshDatabase from wiping a local or production database.'
            );
        }

        parent::setUp();
    }
}
