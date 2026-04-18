<?php

namespace Laravel\Outbox\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Events\MessageFailed;
use Laravel\Outbox\Events\MessageProcessed;
use Laravel\Outbox\Events\MessagesStored;
use Laravel\Outbox\Jobs\ProcessOutboxMessages;
use Laravel\Outbox\OutboxService;
use Laravel\Outbox\Tests\Stubs\TestFailingEvent;
use Laravel\Outbox\Tests\Stubs\TestOrderCreated;
use Laravel\Outbox\Tests\TestCase;

class OutboxProcessingTest extends TestCase
{
    public function test_complete_outbox_flow(): void
    {
        Event::fake([MessagesStored::class]);

        app(OutboxService::class)->transaction('Order', '123', function () {
            event(new TestOrderCreated('123'));
        });

        $message = DB::table('outbox_messages')->first();
        $this->assertNotNull($message);
        $this->assertSame('event', $message->type);
        $this->assertSame('Order', $message->aggregate_type);
        $this->assertSame('123', $message->aggregate_id);
        $this->assertSame('pending', $message->status);
        $this->assertNotEmpty($message->payload_hash);

        Event::assertDispatched(MessagesStored::class, function ($e) {
            return $e->count === 1;
        });
    }

    public function test_processor_dispatches_event_and_marks_complete(): void
    {
        app(OutboxService::class)->transaction('Order', '1', function () {
            event(new TestOrderCreated('1'));
        });

        Event::fake([TestOrderCreated::class, MessageProcessed::class]);

        $processed = $this->runProcessor();

        $this->assertSame(1, $processed);
        $this->assertSame(
            1,
            DB::table('outbox_messages')->where('status', 'completed')->count()
        );
        Event::assertDispatched(TestOrderCreated::class);
        Event::assertDispatched(MessageProcessed::class);
    }

    public function test_failure_schedules_retry_with_backoff_then_dead_letters(): void
    {
        app(OutboxService::class)->transaction('Order', '2', function () {
            event(new TestFailingEvent);
        });

        // Make the real dispatcher propagate the listener failure.
        Event::listen(TestFailingEvent::class, function ($e) {
            throw new \RuntimeException('listener failure');
        });

        Event::fake([MessageFailed::class]);

        $max = (int) config('outbox.processing.max_attempts');
        $messageId = DB::table('outbox_messages')->value('id');

        for ($i = 1; $i <= $max; $i++) {
            // Reset available_at to now so claim picks the row up again.
            DB::table('outbox_messages')
                ->where('id', $messageId)
                ->update(['available_at' => now()->subSecond()]);

            $this->runProcessor();
        }

        $finalRow = DB::table('outbox_messages')->where('id', $messageId)->first();
        $this->assertSame('failed', $finalRow->status);
        $this->assertSame($max, (int) $finalRow->attempts);

        $this->assertSame(1, DB::table('outbox_dead_letter')->count());
        $dlRow = DB::table('outbox_dead_letter')->first();
        $this->assertSame('Order', $dlRow->aggregate_type);
        $this->assertSame('2', $dlRow->aggregate_id);

        Event::assertDispatched(MessageFailed::class);
    }

    public function test_batch_processing_respects_limit(): void
    {
        $service = app(OutboxService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->transaction('Order', "order-{$i}", function () use ($i) {
                event(new TestOrderCreated("order-{$i}"));
            });
        }

        Event::fake([TestOrderCreated::class]);

        $this->assertSame(5, DB::table('outbox_messages')->where('status', 'pending')->count());

        $this->assertSame(2, $this->runProcessor(2));
        $this->assertSame(3, DB::table('outbox_messages')->where('status', 'pending')->count());
        $this->assertSame(2, DB::table('outbox_messages')->where('status', 'completed')->count());

        $this->assertSame(2, $this->runProcessor(2));
        $this->assertSame(1, DB::table('outbox_messages')->where('status', 'pending')->count());
        $this->assertSame(4, DB::table('outbox_messages')->where('status', 'completed')->count());
    }

    public function test_payload_integrity_failure_sends_to_dead_letter(): void
    {
        app(OutboxService::class)->transaction('Order', '9', function () {
            event(new TestOrderCreated('9'));
        });

        // Tamper with the stored payload — flip one byte of the body.
        $id = DB::table('outbox_messages')->value('id');
        $payload = DB::table('outbox_messages')->where('id', $id)->value('payload');
        DB::table('outbox_messages')->where('id', $id)->update([
            'payload' => $payload.'X',
        ]);

        $max = (int) config('outbox.processing.max_attempts');
        for ($i = 1; $i <= $max; $i++) {
            DB::table('outbox_messages')->where('id', $id)->update(['available_at' => now()->subSecond()]);
            $this->runProcessor();
        }

        $this->assertSame(1, DB::table('outbox_dead_letter')->count());
        $this->assertSame('failed', DB::table('outbox_messages')->where('id', $id)->value('status'));
    }

    protected function runProcessor(int $batchSize = 100): int
    {
        $job = new ProcessOutboxMessages($batchSize);

        return $job->handle(
            $this->app->make(OutboxRepository::class),
            $this->app->make(MetricsCollector::class),
            $this->app['events'],
            $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
            $this->app['config'],
        );
    }
}
