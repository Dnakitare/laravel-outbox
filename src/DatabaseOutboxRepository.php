<?php

namespace Laravel\Outbox;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Laravel\Outbox\Contracts\OutboxMessage;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Support\PayloadSerializer;
use Throwable;

class DatabaseOutboxRepository implements OutboxRepository
{
    protected string $messagesTable;

    protected string $deadLetterTable;

    protected bool $deadLetterEnabled;

    protected bool $supportsSkipLocked;

    public function __construct(
        protected ConnectionInterface $db,
        protected PayloadSerializer $serializer,
        protected Config $config,
    ) {
        $this->messagesTable = $config->get('outbox.table.messages', 'outbox_messages');
        $this->deadLetterTable = $config->get('outbox.table.dead_letter', 'outbox_dead_letter');
        $this->deadLetterEnabled = (bool) $config->get('outbox.dead_letter.enabled', true);
        $this->supportsSkipLocked = in_array(
            $this->driverName($db),
            ['mysql', 'mariadb', 'pgsql'],
            true
        );
    }

    public function store(array $messages): void
    {
        $this->db->table($this->messagesTable)->insert($messages);
    }

    public function transaction(callable $callback)
    {
        return $this->db->transaction($callback);
    }

    public function claimPendingMessages(int $limit): array
    {
        return $this->db->transaction(function () use ($limit) {
            $query = $this->db->table($this->messagesTable)
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('sequence_number')
                ->limit($limit)
                ->lockForUpdate();

            if ($this->supportsSkipLocked) {
                // SKIP LOCKED is crucial for horizontal scaling: without
                // it, a second worker arriving mid-claim blocks until
                // the first worker's tx commits. With it, the second
                // worker picks the next batch. SQLite and older DBs
                // fall back to plain FOR UPDATE, which is still
                // correct (just serialises workers).
                $query->lock('FOR UPDATE SKIP LOCKED');
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $now = now();
            $ids = $rows->pluck('id')->all();

            $this->db->table($this->messagesTable)
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'processing',
                    'attempts' => $this->db->raw('attempts + 1'),
                    'processing_started_at' => $now,
                    'updated_at' => $now,
                ]);

            // Reflect the claim in the objects we hand back so callers
            // see consistent state without an extra SELECT.
            return $rows->map(function ($row) use ($now) {
                $row->status = 'processing';
                $row->attempts = (int) $row->attempts + 1;
                $row->processing_started_at = $now;

                return new DatabaseOutboxMessage($row, $this->serializer);
            })->all();
        });
    }

    public function markAsComplete(string $id): bool
    {
        $now = now();

        return $this->db->table($this->messagesTable)
            ->where('id', $id)
            ->update([
                'status' => 'completed',
                'processed_at' => $now,
                'error' => null,
                'updated_at' => $now,
            ]) > 0;
    }

    public function markAsFailed(string $id, Throwable $exception, \DateTimeInterface $availableAt, bool $exhausted): void
    {
        $now = now();

        $this->db->table($this->messagesTable)
            ->where('id', $id)
            ->update([
                'status' => $exhausted ? 'failed' : 'pending',
                'available_at' => $availableAt,
                'error' => $this->truncateError($exception->getMessage()),
                'processing_started_at' => null,
                'updated_at' => $now,
            ]);
    }

    public function moveToDeadLetter(OutboxMessage $message, Throwable $exception): void
    {
        if (! $this->deadLetterEnabled) {
            return;
        }

        $now = now();
        $payload = $message->getRawPayload();

        $this->db->table($this->deadLetterTable)->insert([
            'id' => (string) Str::uuid(),
            'original_message_id' => $message->getId(),
            'transaction_id' => $message->getTransactionId(),
            'correlation_id' => $message->getCorrelationId(),
            'aggregate_type' => $message->getAggregateType(),
            'aggregate_id' => $message->getAggregateId(),
            'message_type' => $message->getMessageType(),
            'payload' => $payload,
            'payload_hash' => $this->serializer->verifyHash($payload)
                ? substr($payload, 0, 64)
                : hash('sha256', $payload),
            'error' => $this->truncateError($exception->getMessage()),
            'stack_trace' => $exception->getTraceAsString(),
            'metadata' => json_encode([
                'attempts' => $message->getAttempts(),
                'sequence_number' => $message->getSequenceNumber(),
                'history' => $message->getHistory(),
            ]),
            'failed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function resetFailed(?array $ids = null, bool $preserveHistory = true): int
    {
        $query = $this->db->table($this->messagesTable)->where('status', 'failed');

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $now = now();
        $reset = 0;

        $query->orderBy('id')->chunkById(500, function ($rows) use (&$reset, $now, $preserveHistory) {
            foreach ($rows as $row) {
                $update = [
                    'status' => 'pending',
                    'attempts' => 0,
                    'error' => null,
                    'available_at' => $now,
                    'processing_started_at' => null,
                    'updated_at' => $now,
                ];

                if ($preserveHistory) {
                    $update['history'] = $this->appendHistoryEntry(
                        $row->history,
                        [
                            'attempts' => (int) $row->attempts,
                            'error' => $row->error,
                            'failed_at' => (string) ($row->updated_at ?? $now),
                            'reset_at' => (string) $now,
                        ]
                    );
                } else {
                    $update['history'] = null;
                }

                $this->db->table($this->messagesTable)
                    ->where('id', $row->id)
                    ->update($update);

                $reset++;
            }
        }, 'id');

        return $reset;
    }

    protected function appendHistoryEntry(?string $existing, array $entry): string
    {
        $history = [];

        if ($existing !== null && $existing !== '') {
            $decoded = json_decode($existing, true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }

        $history[] = $entry;

        // Cap history at 50 entries to keep rows bounded.
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        return json_encode($history);
    }

    protected function driverName(ConnectionInterface $db): string
    {
        // ConnectionInterface doesn't declare getDriverName, but the
        // concrete Connection subclasses all expose it.
        return method_exists($db, 'getDriverName') ? $db->getDriverName() : '';
    }

    protected function truncateError(string $message, int $max = 4000): string
    {
        return mb_strlen($message) > $max
            ? mb_substr($message, 0, $max - 3).'...'
            : $message;
    }
}
