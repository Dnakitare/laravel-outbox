<?php

namespace Laravel\Outbox\Metrics;

use Laravel\Outbox\Contracts\MetricsCollector;

class NullMetricsCollector implements MetricsCollector
{
    public function startTimer()
    {
        return microtime(true);
    }

    public function recordTransactionDuration($startTime): void
    {
        // No-op
    }

    public function incrementStoredMessages(int $count): void
    {
        // No-op
    }

    public function incrementProcessedMessages(string $type): void
    {
        // No-op
    }

    public function incrementFailedMessages(string $type): void
    {
        // No-op
    }

    public function incrementDeadLetterMessages(): void
    {
        // No-op
    }
}
