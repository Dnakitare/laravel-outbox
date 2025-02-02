<?php

namespace Laravel\Outbox\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Exceptions\TransactionException;
use Laravel\Outbox\OutboxService;
use Laravel\Outbox\Tests\Stubs\TestEvent;
use Laravel\Outbox\Tests\Stubs\TestJob;
use Laravel\Outbox\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class OutboxServiceTest extends TestCase
{
    private ?OutboxService $outboxService = null;

    /**
     * @var MockInterface&OutboxRepository
     */
    private ?OutboxRepository $repository = null;

    /**
     * @var MockInterface&MetricsCollector
     */
    private ?MetricsCollector $metrics = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Initialize mocks
        /** @var MockInterface&OutboxRepository $repository */
        $repository = Mockery::mock(OutboxRepository::class);
        /** @var MockInterface&MetricsCollector $metrics */
        $metrics = Mockery::mock(MetricsCollector::class);

        $this->repository = $repository;
        $this->metrics = $metrics;

        $this->outboxService = new OutboxService(
            $this->repository,
            app('events'),
            app('queue'),
            $this->metrics
        );
    }

    public function test_it_stores_events_in_transaction(): void
    {
        // Arrange
        Event::fake();
        $event = new TestEvent('test');

        $this->setSuccessMetricsExpectations();

        $this->repository->shouldReceive('transaction')->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn ($callback) => $callback());

        $this->repository->shouldReceive('store')->once()
            ->with(Mockery::on(function ($messages) {
                return count($messages) === 1
                    && $messages[0]['type'] === 'event'
                    && $messages[0]['aggregate_type'] === 'Order'
                    && $messages[0]['aggregate_id'] === '123';
            }));

        // Act
        $result = $this->outboxService->transaction('Order', '123', function () use ($event) {
            $this->app['events']->dispatch($event);

            return 'success';
        });

        // Assert
        $this->assertEquals('success', $result);
    }

    public function test_it_stores_queued_jobs_in_transaction(): void
    {
        // Arrange
        Queue::fake();
        $job = new TestJob;

        $this->setSuccessMetricsExpectations();

        $this->repository->shouldReceive('transaction')->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn ($callback) => $callback());

        $this->repository->shouldReceive('store')->once()
            ->with(Mockery::on(function ($messages) {
                return count($messages) === 1
                    && $messages[0]['type'] === 'job'
                    && $messages[0]['aggregate_type'] === 'Order'
                    && $messages[0]['aggregate_id'] === '123';
            }));

        // Act
        $result = $this->outboxService->transaction('Order', '123', function () use ($job) {
            $this->app['queue']->push($job);

            return 'success';
        });

        // Assert
        $this->assertEquals('success', $result);
    }

    public function test_it_prevents_nested_transactions(): void
    {
        // Arrange
        $this->expectException(TransactionException::class);

        $this->setFailureMetricsExpectations();

        $this->repository->shouldReceive('transaction')
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn ($callback) => $callback());

        // Act & Assert
        $this->outboxService->transaction('Order', '123', function () {
            $this->outboxService->transaction('Order', '456', function () {});
        });
    }

    public function test_it_handles_transaction_failure(): void
    {
        // Arrange
        $this->setFailureMetricsExpectations();

        $this->repository->shouldReceive('transaction')->once()
            ->with(Mockery::type('callable'))
            ->andThrow(new \Exception('Database error'));

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->outboxService->transaction('Order', '123', function () {
            // This should not be executed
        });
    }

    private function setSuccessMetricsExpectations(): void
    {
        $this->metrics->shouldReceive('startTimer')->once()->andReturn(microtime(true));
        $this->metrics->shouldReceive('recordTransactionDuration')->once();
        $this->metrics->shouldReceive('incrementStoredMessages')->once();
    }

    private function setFailureMetricsExpectations(): void
    {
        $this->metrics->shouldReceive('startTimer')->once()->andReturn(microtime(true));
        $this->metrics->shouldReceive('recordTransactionDuration')->never();
        $this->metrics->shouldReceive('incrementStoredMessages')->never();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
