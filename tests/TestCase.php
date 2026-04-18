<?php

namespace Laravel\Outbox\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Outbox\OutboxServiceProvider;
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Processing immediately would push ProcessOutboxMessages onto
        // the queue which is not what most tests want to assert.
        $app['config']->set('outbox.processing.process_immediately', false);

        // Permit the test stub classes to rehydrate.
        $app['config']->set('outbox.serialization.allowed_classes', [
            \Laravel\Outbox\Tests\Stubs\TestEvent::class,
            \Laravel\Outbox\Tests\Stubs\TestJob::class,
            \Laravel\Outbox\Tests\Stubs\TestOrderCreated::class,
            \Laravel\Outbox\Tests\Stubs\TestFailingEvent::class,
        ]);
    }
}
