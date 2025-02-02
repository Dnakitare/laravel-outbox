<?php

namespace Laravel\Outbox\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\DatabaseOutboxRepository;
use Laravel\Outbox\Jobs\ProcessOutboxMessages;
use Laravel\Outbox\Metrics\NullMetricsCollector;
use Laravel\Outbox\OutboxService;
use Laravel\Outbox\Tests\Stubs\TestFailingEvent;
use Laravel\Outbox\Tests\Stubs\TestOrderCreated;
use Laravel\Outbox\Tests\Stubs\TestOutboxMessage;
use Laravel\Outbox\Tests\TestCase;

class OutboxProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Bind necessary services
        $this->app->singleton(MetricsCollector::class, NullMetricsCollector::class);
        $this->app->singleton(OutboxRepository::class, function ($app) {
            return new DatabaseOutboxRepository($app['db']->connection());
        });
    }

    public function test_complete_outbox_flow(): void
    {
        // Arrange
        Event::fake();

        // Act
        app(OutboxService::class)->transaction('Order', '123', function () {
            event(new TestOrderCreated('123'));
        });

        // Assert
        $message = DB::table('outbox_messages')->first();
        $this->assertNotNull($message);
        $this->assertEquals('event', $message->type);
        $this->assertEquals('Order', $message->aggregate_type);
        $this->assertEquals('123', $message->aggregate_id);
        $this->assertEquals('pending', $message->status);
    }

    public function test_dead_letter_handling(): void
    {
        // Arrange
        Event::fake();

        // Act
        app(OutboxService::class)->transaction('Order', '123', function () {
            event(new TestFailingEvent);
        });

        // Process message multiple times to hit max attempts
        $maxAttempts = config('outbox.processing.max_attempts', 3);
        for ($i = 0; $i < $maxAttempts; $i++) {
            $message = DB::table('outbox_messages')
                ->where('status', 'pending')
                ->first();

            if ($message) {
                DB::table('outbox_messages')
                    ->where('id', $message->id)
                    ->update([
                        'attempts' => $maxAttempts,
                        'status' => 'failed',
                    ]);

                app(OutboxRepository::class)->moveToDeadLetter(
                    new TestOutboxMessage($message),
                    new \Exception('Test failure')
                );
            }
        }

        // Assert
        $this->assertDatabaseHas('outbox_dead_letter', [
            'aggregate_type' => 'Order',
            'aggregate_id' => '123',
        ]);
    }

    public function test_batch_processing(): void
    {
        // Arrange
        Event::fake();
        $service = app(OutboxService::class);

        // Create test messages
        for ($i = 0; $i < 5; $i++) {
            $service->transaction('Order', "order-{$i}", function () use ($i) {
                event(new TestOrderCreated("order-{$i}"));
            });
        }

        // Initial assertions
        $this->assertEquals(5, DB::table('outbox_messages')
            ->where('status', 'pending')
            ->count(), 'Should start with 5 pending messages');

        // Process first batch
        $job = new ProcessOutboxMessages(2);
        $processedCount = $job->handle(
            app(OutboxRepository::class),
            app(MetricsCollector::class)
        );

        $this->assertEquals(2, $processedCount, 'Should have processed 2 messages in first batch');

        // Assert after first batch
        $this->assertEquals(3, DB::table('outbox_messages')
            ->where('status', 'pending')
            ->count(), 'Should have 3 pending messages after first batch');

        $this->assertEquals(2, DB::table('outbox_messages')
            ->where('status', 'completed')
            ->count(), 'Should have 2 completed messages after first batch');

        // Process second batch
        $processedCount = $job->handle(
            app(OutboxRepository::class),
            app(MetricsCollector::class)
        );

        $this->assertEquals(2, $processedCount, 'Should have processed 2 messages in second batch');

        // Final assertions
        $this->assertEquals(1, DB::table('outbox_messages')
            ->where('status', 'pending')
            ->count(), 'Should have 1 pending message after second batch');

        $this->assertEquals(4, DB::table('outbox_messages')
            ->where('status', 'completed')
            ->count(), 'Should have 4 completed messages after second batch');
    }

    protected function tearDown(): void
    {
        DB::table('outbox_messages')->delete();
        DB::table('outbox_dead_letter')->delete();
        parent::tearDown();
    }
}
