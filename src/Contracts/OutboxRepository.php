<?php

namespace Laravel\Outbox\Contracts;

interface OutboxRepository
{
    public function store(array $messages): void;

    public function transaction(callable $callback);

    public function getPendingMessages(int $limit): array;

    public function markAsProcessing(string $id): bool;

    public function markAsComplete(string $id): bool;

    public function markAsFailed(string $id, \Throwable $exception): void;

    public function moveToDeadLetter(OutboxMessage $message, \Throwable $exception): void;
}
