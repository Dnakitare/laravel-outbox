<?php

namespace Laravel\Outbox;

use Illuminate\Support\ServiceProvider;
use Laravel\Outbox\Console\Commands\InspectDeadLetterCommand;
use Laravel\Outbox\Console\Commands\ProcessOutboxCommand;
use Laravel\Outbox\Console\Commands\PruneOutboxCommand;
use Laravel\Outbox\Console\Commands\RetryFailedCommand;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Metrics\NullMetricsCollector;

class OutboxServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register config
        $this->mergeConfigFrom(
            __DIR__.'/../config/outbox.php', 'outbox'
        );

        // Register repository
        $this->app->singleton(OutboxRepository::class, function ($app) {
            return new DatabaseOutboxRepository(
                $app['db']->connection()
            );
        });

        // Register metrics collector
        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new NullMetricsCollector;
        });

        // Register main service
        $this->app->singleton(OutboxService::class, function ($app) {
            return new OutboxService(
                $app[OutboxRepository::class],
                $app['events'],
                $app['queue'],
                $app[MetricsCollector::class]
            );
        });

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register facade
        $this->app->alias(OutboxService::class, 'outbox');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__.'/../config/outbox.php' => config_path('outbox.php'),
            ], 'outbox-config');

            // Publish migrations
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'outbox-migrations');

            // Register commands
            $this->commands([
                ProcessOutboxCommand::class,
                PruneOutboxCommand::class,
                RetryFailedCommand::class,
                InspectDeadLetterCommand::class,
            ]);

            // Register debugger
            $this->app->singleton(Debug\OutboxDebugger::class);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
