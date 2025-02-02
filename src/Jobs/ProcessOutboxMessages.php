<?php

namespace Laravel\Outbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\DatabaseOutboxMessage;

class ProcessOutboxMessages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $batchSize = 100
    ) {}

    public function handle(OutboxRepository $repository, MetricsCollector $metrics)
    {
        // Get pending messages limited by batch size
        $messages = DB::table('outbox_messages')
            ->where('status', 'pending')
            ->where('attempts', '<', config('outbox.processing.max_attempts', 3))
            ->orderBy('created_at')
            ->orderBy('sequence_number')
            ->limit($this->batchSize)
            ->get();

        $processedCount = 0;

        foreach ($messages as $databaseMessage) {
            $message = new DatabaseOutboxMessage($databaseMessage);
            DB::beginTransaction();
            try {
                // Try to mark message as processing
                if (! $repository->markAsProcessing($message->getId())) {
                    DB::rollBack();

                    continue; // Message was picked up by another processor
                }

                // Process the message
                $payload = $message->getPayload();

                if ($message->getType() === 'event') {
                    Event::dispatch($payload);
                } else {
                    dispatch($payload);
                }

                // Mark as completed
                $repository->markAsComplete($message->getId());
                $metrics->incrementProcessedMessages($message->getType());
                $processedCount++;

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                logger()->error('Failed to process outbox message', [
                    'message_id' => $message->getId(),
                    'error' => $e->getMessage(),
                ]);

                $repository->markAsFailed($message->getId(), $e);
                $metrics->incrementFailedMessages($message->getType());

                if ($message->getAttempts() >= config('outbox.processing.max_attempts', 3)) {
                    $repository->moveToDeadLetter($message, $e);
                    $metrics->incrementDeadLetterMessages();
                }
            }
        }

        return $processedCount;
    }
}
