<?php

namespace Dnakitare\Outbox\Tests;

use Dnakitare\Outbox\OutboxServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            OutboxServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Honour CI's DB_CONNECTION when it points at a real database so
        // the same test suite exercises MySQL/MariaDB/Postgres as well
        // as the SQLite default used locally. If DB_CONNECTION isn't set
        // or is 'testing'/'sqlite', fall back to in-memory SQLite.
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        if ($driver === 'mysql' || $driver === 'pgsql') {
            $app['config']->set('database.default', $driver);
            $app['config']->set("database.connections.{$driver}", [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306),
                'database' => getenv('DB_DATABASE') ?: 'outbox_test',
                'username' => getenv('DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'schema' => $driver === 'pgsql' ? 'public' : null,
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
        }

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Processing immediately would push ProcessOutboxMessages onto
        // the queue which is not what most tests want to assert.
        $app['config']->set('outbox.processing.process_immediately', false);

        // Permit the test stub classes to rehydrate.
        $app['config']->set('outbox.serialization.allowed_classes', [
            \Dnakitare\Outbox\Tests\Stubs\TestEvent::class,
            \Dnakitare\Outbox\Tests\Stubs\TestJob::class,
            \Dnakitare\Outbox\Tests\Stubs\TestOrderCreated::class,
            \Dnakitare\Outbox\Tests\Stubs\TestFailingEvent::class,
            \Dnakitare\Outbox\Tests\Stubs\TestDeadOnWakeupJob::class,
        ]);
    }
}
