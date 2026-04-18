<?php

namespace Laravel\Outbox\Events;

use Laravel\Outbox\Contracts\OutboxMessage;

class MessageProcessed
{
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly float $durationSeconds,
    ) {}
}
