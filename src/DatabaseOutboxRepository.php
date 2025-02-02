<?php

namespace Laravel\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laravel\Outbox\Contracts\OutboxMessage;
use Laravel\Outbox\Contracts\OutboxRepository;

class DatabaseOutboxRepository implements OutboxRepository
{
    public function __construct(
        protected ConnectionInterface $db
    ) {}

    public function store(array $messages): void
    {
        $this->db->table(config('outbox.table.messages', 'outbox_messages'))
            ->insert($messages);
    }

    public function transaction(callable $callback)
    {
        return $this->db->transaction($callback);
    }

    public function getPendingMessages(int $limit): array
    {
        return $this->db->table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'pending')
            ->where('attempts', '<', config('outbox.processing.max_attempts', 3))
            ->orderBy('created_at')
            ->orderBy('sequence_number')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function markAsProcessing(string $id): bool
    {
        return $this->db->table(config('outbox.table.messages', 'outbox_messages'))
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status' => 'processing',
                'attempts' => $this->db->raw('attempts + 1'),
                'processing_started_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    public function markAsComplete(string $id): bool
    {
        return $this->db->table(config('outbox.table.messages', 'outbox_messages'))
            ->where('id', $id)
            ->update([
                'status' => 'completed',
                'processed_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    public function markAsFailed(string $id, \Throwable $exception): void
    {
        $this->db->table(config('outbox.table.messages', 'outbox_messages'))
            ->where('id', $id)
            ->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'updated_at' => now(),
            ]);
    }

    public function moveToDeadLetter(OutboxMessage $message, \Throwable $exception): void
    {
        if (! config('outbox.dead_letter.enabled', true)) {
            return;
        }

        $this->db->table(config('outbox.table.dead_letter', 'outbox_dead_letter'))->insert([
            'id' => (string) Str::uuid(),
            'original_message_id' => $message->getId(),
            'transaction_id' => $message->getTransactionId(),
            'correlation_id' => $message->getCorrelationId(),
            'aggregate_type' => 'Order', // This should come from the message
            'aggregate_id' => '123', // This should come from the message
            'message_type' => get_class($message),
            'payload' => serialize($message),
            'error' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
            'metadata' => json_encode([
                'attempts' => $message->getAttempts(),
            ]),
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
