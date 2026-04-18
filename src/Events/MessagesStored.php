<?php

namespace Laravel\Outbox\Events;

/**
 * Fired after an outbox transaction commits, exposing the transaction
 * and correlation IDs and the number of rows stored. Useful for wiring
 * external metrics/tracing without having to subclass OutboxService.
 */
class MessagesStored
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $correlationId,
        public readonly int $count,
    ) {}
}
