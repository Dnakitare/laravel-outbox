<?php

namespace Dnakitare\Outbox\Jobs;

use Dnakitare\Outbox\Contracts\MetricsCollector;
use Dnakitare\Outbox\Contracts\OutboxMessage;
use Dnakitare\Outbox\Contracts\OutboxRepository;
use Dnakitare\Outbox\Events\MessageFailed;
use Dnakitare\Outbox\Events\MessageProcessed;
use Dnakitare\Outbox\Exceptions\SerializationException;
use Dnakitare\Outbox\Support\BackoffStrategy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutboxMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $batchSize = 100,
    ) {}

    /**
     * Claim up to batchSize pending messages, replay each through the
     * real event/queue dispatchers, and record the result. Returns
     * the number of successfully processed messages.
     */
    public function handle(
        OutboxRepository $repository,
        MetricsCollector $metrics,
        Dispatcher $events,
        BusDispatcher $bus,
        Config $config,
    ): int {
        $backoff = new BackoffStrategy(
            baseSeconds: (int) $config->get('outbox.processing.backoff.base_seconds', 5),
            maxSeconds: (int) $config->get('outbox.processing.backoff.max_seconds', 600),
            multiplier: (float) $config->get('outbox.processing.backoff.multiplier', 2.0),
            jitter: (bool) $config->get('outbox.processing.backoff.jitter', true),
        );
        $maxAttempts = (int) $config->get('outbox.processing.max_attempts', 3);

        $messages = $repository->claimPendingMessages($this->batchSize);
        $processed = 0;

        foreach ($messages as $message) {
            $startedAt = microtime(true);

            try {
                $this->replay($message, $events, $bus);
                $repository->markAsComplete($message->getId());
                $metrics->incrementProcessedMessages($message->getType());

                $duration = microtime(true) - $startedAt;
                $events->dispatch(new MessageProcessed($message, $duration));
                $processed++;
            } catch (Throwable $e) {
                $this->recordFailure(
                    $message,
                    $e,
                    $repository,
                    $metrics,
                    $events,
                    $backoff,
                    $maxAttempts,
                );
            }
        }

        return $processed;
    }

    protected function replay(OutboxMessage $message, Dispatcher $events, BusDispatcher $bus): void
    {
        $pending = $message->getPayload();

        // During storage we serialized ['event'=>..., 'payload'=>...] or
        // ['job'=>..., 'data'=>..., 'queue'=>..., 'delay'=>...]. Decode
        // and replay accordingly.
        if (! is_array($pending)) {
            // Legacy or raw payloads: best-effort by type.
            if ($message->getType() === 'event') {
                $events->dispatch($pending);
            } else {
                $bus->dispatch($pending);
            }

            return;
        }

        if ($message->getType() === 'event') {
            $event = $pending['event'] ?? null;
            $payload = $pending['payload'] ?? [];

            if ($event === null) {
                throw new SerializationException('Outbox event payload is missing the event instance.');
            }

            $events->dispatch($event, is_array($payload) ? $payload : [$payload]);

            return;
        }

        // Job
        $job = $pending['job'] ?? null;
        if ($job === null) {
            // Raw pushRaw payloads — we can't rehydrate a typed job; log
            // and mark complete (best effort). In practice pushRaw is
            // rare and users are warned in docs.
            Log::warning('Outbox: skipping raw job payload; not replayable', [
                'message_id' => $message->getId(),
                'transaction_id' => $message->getTransactionId(),
            ]);

            return;
        }

        $bus->dispatch($job);
    }

    protected function recordFailure(
        OutboxMessage $message,
        Throwable $exception,
        OutboxRepository $repository,
        MetricsCollector $metrics,
        Dispatcher $events,
        BackoffStrategy $backoff,
        int $maxAttempts,
    ): void {
        $exhausted = $message->getAttempts() >= $maxAttempts;
        $availableAt = $exhausted
            ? now()
            : $backoff->nextAttemptAt($message->getAttempts());

        $repository->markAsFailed($message->getId(), $exception, $availableAt, $exhausted);
        $metrics->incrementFailedMessages($message->getType());

        Log::error('Outbox message processing failed', [
            'message_id' => $message->getId(),
            'message_type' => $message->getMessageType(),
            'transaction_id' => $message->getTransactionId(),
            'correlation_id' => $message->getCorrelationId(),
            'attempts' => $message->getAttempts(),
            'exhausted' => $exhausted,
            'error' => $exception->getMessage(),
        ]);

        if ($exhausted) {
            $repository->moveToDeadLetter($message, $exception);
            $metrics->incrementDeadLetterMessages();
        }

        $events->dispatch(new MessageFailed($message, $exception, $exhausted));
    }
}
