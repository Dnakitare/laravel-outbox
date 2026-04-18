<?php

namespace Laravel\Outbox\Contracts;

use Throwable;

interface OutboxRepository
{
    /**
     * Insert a batch of message rows atomically.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function store(array $messages): void;

    /**
     * Execute the callback inside a database transaction.
     */
    public function transaction(callable $callback);

    /**
     * Atomically claim up to $limit pending messages that are due for
     * processing (available_at <= now). Claiming increments the attempts
     * counter and flips status to 'processing'. Uses row-level locks with
     * SKIP LOCKED on supported drivers so concurrent workers never claim
     * the same row.
     *
     * @return array<int, OutboxMessage>
     */
    public function claimPendingMessages(int $limit): array;

    public function markAsComplete(string $id): bool;

    /**
     * Record a processing failure. If the attempt count has not reached
     * the max, schedules a retry at $availableAt. Otherwise marks the
     * message 'failed' and it will not be retried automatically.
     */
    public function markAsFailed(string $id, Throwable $exception, \DateTimeInterface $availableAt, bool $exhausted): void;

    public function moveToDeadLetter(OutboxMessage $message, Throwable $exception): void;

    /**
     * Reset failed messages back to pending. Appends current error/attempt
     * state to the message history before resetting.
     *
     * @param  array<int, string>|null  $ids  Null = all failed messages
     */
    public function resetFailed(?array $ids = null, bool $preserveHistory = true): int;
}
