<?php

namespace Laravel\Outbox\Contracts;

interface MetricsCollector
{
    public function startTimer();

    public function recordTransactionDuration($startTime): void;

    public function incrementStoredMessages(int $count): void;

    public function incrementProcessedMessages(string $type): void;

    public function incrementFailedMessages(string $type): void;

    public function incrementDeadLetterMessages(): void;
}
