<?php

namespace Laravel\Outbox\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Outbox\OutboxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup
    }

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
        // Use in-memory SQLite database for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set default outbox configuration for testing
        $app['config']->set('outbox.processing.process_immediately', false);
    }
}
