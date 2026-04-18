<?php

namespace Dnakitare\Outbox\Tests\Unit;

use Dnakitare\Outbox\Contracts\MetricsCollector;
use Dnakitare\Outbox\Contracts\OutboxRepository;
use Dnakitare\Outbox\Exceptions\TransactionException;
use Dnakitare\Outbox\OutboxService;
use Dnakitare\Outbox\Support\PayloadSerializer;
use Dnakitare\Outbox\Tests\Stubs\TestEvent;
use Dnakitare\Outbox\Tests\Stubs\TestJob;
use Dnakitare\Outbox\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

class OutboxServiceTest extends TestCase
{
    private OutboxService $service;

    /** @var MockInterface&OutboxRepository */
    private $repository;

    /** @var MockInterface&MetricsCollector */
    private $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(OutboxRepository::class);
        $this->metrics = Mockery::mock(MetricsCollector::class);

        // Replace container bindings so OutboxService resolves our mocks.
        $this->app->instance(OutboxRepository::class, $this->repository);
        $this->app->instance(MetricsCollector::class, $this->metrics);

        $this->service = new OutboxService(
            $this->app,
            $this->repository,
            $this->app->make(PayloadSerializer::class),
            $this->metrics,
            $this->app['config'],
        );
    }

    public function test_it_stores_events_dispatched_inside_transaction(): void
    {
        $this->expectSuccessMetrics();

        $this->repository->shouldReceive('transaction')->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn ($cb) => $cb());

        $this->repository->shouldReceive('store')->once()
            ->with(Mockery::on(function ($rows) {
                return count($rows) === 1
                    && $rows[0]['type'] === 'event'
                    && $rows[0]['aggregate_type'] === 'Order'
                    && $rows[0]['aggregate_id'] === '42'
                    && str_contains($rows[0]['message_type'], TestEvent::class);
            }));

        $result = $this->service->transaction('Order', '42', function () {
            $this->app['events']->dispatch(new TestEvent('hi'));

            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_it_stores_jobs_dispatched_inside_transaction(): void
    {
        $this->expectSuccessMetrics();

        $this->repository->shouldReceive('transaction')->once()
            ->andReturnUsing(fn ($cb) => $cb());

        $this->repository->shouldReceive('store')->once()
            ->with(Mockery::on(function ($rows) {
                return count($rows) === 1
                    && $rows[0]['type'] === 'job'
                    && $rows[0]['aggregate_type'] === 'Order';
            }));

        $this->service->transaction('Order', '42', function () {
            $this->app['queue']->push(new TestJob);
        });

        $this->addToAssertionCount(1);
    }

    public function test_it_prevents_nested_transactions(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectFailureMetrics();

        $this->repository->shouldReceive('transaction')
            ->andReturnUsing(fn ($cb) => $cb());

        $this->service->transaction('Order', '1', function () {
            $this->service->transaction('Order', '2', fn () => null);
        });
    }

    public function test_it_restores_original_dispatchers_after_exception(): void
    {
        $this->expectFailureMetrics();
        $originalEvents = $this->app['events'];
        $originalQueue = $this->app['queue'];

        $this->repository->shouldReceive('transaction')
            ->andThrow(new \RuntimeException('boom'));

        try {
            $this->service->transaction('Order', '1', fn () => null);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($originalEvents, $this->app['events']);
        $this->assertSame($originalQueue, $this->app['queue']);
    }

    public function test_it_does_not_call_store_when_nothing_collected(): void
    {
        $this->metrics->shouldReceive('startTimer')->once()->andReturn(microtime(true));
        $this->metrics->shouldReceive('recordTransactionDuration')->once();
        $this->metrics->shouldReceive('incrementStoredMessages')->never();

        $this->repository->shouldReceive('transaction')->once()
            ->andReturnUsing(fn ($cb) => $cb());
        $this->repository->shouldNotReceive('store');

        $this->service->transaction('Order', '1', fn () => 'noop');

        $this->addToAssertionCount(1);
    }

    private function expectSuccessMetrics(): void
    {
        $this->metrics->shouldReceive('startTimer')->once()->andReturn(microtime(true));
        $this->metrics->shouldReceive('recordTransactionDuration')->once();
        $this->metrics->shouldReceive('incrementStoredMessages')->once();
    }

    private function expectFailureMetrics(): void
    {
        $this->metrics->shouldReceive('startTimer')->once()->andReturn(microtime(true));
        $this->metrics->shouldNotReceive('recordTransactionDuration');
        $this->metrics->shouldNotReceive('incrementStoredMessages');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
