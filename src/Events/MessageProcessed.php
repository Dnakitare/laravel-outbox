<?php

namespace Dnakitare\Outbox\Events;

use Dnakitare\Outbox\Contracts\OutboxMessage;

class MessageProcessed
{
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly float $durationSeconds,
    ) {}
}
