<?php

namespace Laravel\Outbox;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Outbox\Console\Commands\InspectDeadLetterCommand;
use Laravel\Outbox\Console\Commands\ProcessOutboxCommand;
use Laravel\Outbox\Console\Commands\PruneOutboxCommand;
use Laravel\Outbox\Console\Commands\RetryFailedCommand;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Metrics\NullMetricsCollector;
use Laravel\Outbox\Support\PayloadSerializer;

class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outbox.php', 'outbox');

        $this->app->singleton(PayloadSerializer::class, function ($app) {
            /** @var Config $config */
            $config = $app['config'];
            $hmacKey = $config->get('outbox.serialization.hmac_key')
                ?: $config->get('app.key')
                ?: '';

            if ($hmacKey === '') {
                throw new \RuntimeException(
                    'Outbox requires an HMAC key. Set OUTBOX_HMAC_KEY or APP_KEY.'
                );
            }

            // APP_KEY is typically prefixed "base64:"; that's fine for
            // HMAC — any stable string works — but strip the prefix for
            // key hygiene.
            if (str_starts_with($hmacKey, 'base64:')) {
                $decoded = base64_decode(substr($hmacKey, 7), true);
                if ($decoded !== false) {
                    $hmacKey = $decoded;
                }
            }

            return new PayloadSerializer($config, $hmacKey);
        });

        $this->app->singleton(OutboxRepository::class, function ($app) {
            return new DatabaseOutboxRepository(
                $app['db']->connection(),
                $app[PayloadSerializer::class],
                $app['config'],
            );
        });

        $this->app->singleton(MetricsCollector::class, function ($app) {
            $class = $app['config']->get('outbox.monitoring.metrics_collector');
            if ($class && class_exists($class)) {
                return $app->make($class);
            }

            return new NullMetricsCollector;
        });

        $this->app->singleton(OutboxService::class, function ($app) {
            return new OutboxService(
                $app,
                $app[OutboxRepository::class],
                $app[PayloadSerializer::class],
                $app[MetricsCollector::class],
                $app['config'],
            );
        });

        $this->app->alias(OutboxService::class, 'outbox');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/outbox.php' => config_path('outbox.php'),
            ], 'outbox-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'outbox-migrations');

            $this->commands([
                ProcessOutboxCommand::class,
                PruneOutboxCommand::class,
                RetryFailedCommand::class,
                InspectDeadLetterCommand::class,
            ]);

            $this->app->singleton(Debug\OutboxDebugger::class);
        }
    }
}
