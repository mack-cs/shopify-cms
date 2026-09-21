<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config-testing.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ] as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        $app = parent::createApplication();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against configured database [{$connection}:{$database}]. " .
                'The application must resolve to sqlite::memory: before RefreshDatabase starts.'
            );
        }

        return $app;
    }

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
