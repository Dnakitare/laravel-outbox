<?php

namespace Laravel\Outbox\Events;

use Laravel\Outbox\Contracts\OutboxMessage;
use Throwable;

class MessageFailed
{
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly Throwable $exception,
        public readonly bool $exhausted,
    ) {}
}
