<?php

namespace Dnakitare\Outbox\Tests\Feature;

use Dnakitare\Outbox\Contracts\MetricsCollector;
use Dnakitare\Outbox\Contracts\OutboxRepository;
use Dnakitare\Outbox\Events\MessageFailed;
use Dnakitare\Outbox\Events\MessageProcessed;
use Dnakitare\Outbox\Events\MessagesStored;
use Dnakitare\Outbox\Jobs\ProcessOutboxMessages;
use Dnakitare\Outbox\OutboxService;
use Dnakitare\Outbox\Tests\Stubs\TestDeadOnWakeupJob;
use Dnakitare\Outbox\Tests\Stubs\TestFailingEvent;
use Dnakitare\Outbox\Tests\Stubs\TestOrderCreated;
use Dnakitare\Outbox\Tests\TestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

    public function test_job_rehydration_failure_retries_then_dead_letters(): void
    {
        // Simulates a payload whose unserialize succeeds the write path
        // but throws at replay time — for instance, a queued job that
        // holds a reference to a model that has since been deleted.
        // Outbox should catch the failure, apply backoff, and
        // eventually send the message to dead-letter.
        app(OutboxService::class)->transaction('Order', 'dead', function () {
            dispatch(new TestDeadOnWakeupJob);
        });

        $id = DB::table('outbox_messages')->value('id');
        $this->assertNotNull($id);

        $max = (int) config('outbox.processing.max_attempts');
        for ($i = 1; $i <= $max; $i++) {
            DB::table('outbox_messages')
                ->where('id', $id)
                ->update(['available_at' => now()->subSecond()]);

            $this->runProcessor();
        }

        $row = DB::table('outbox_messages')->where('id', $id)->first();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('rehydration failed', (string) $row->error);

        $dl = DB::table('outbox_dead_letter')->where('original_message_id', $id)->first();
        $this->assertNotNull($dl);
        $this->assertSame('Order', $dl->aggregate_type);
        $this->assertSame('dead', $dl->aggregate_id);
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
            $this->app->make(Dispatcher::class),
            $this->app['config'],
        );
    }
}
