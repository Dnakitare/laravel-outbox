<?php

namespace Dnakitare\Outbox\Events;

use Dnakitare\Outbox\Contracts\OutboxMessage;
use Throwable;

class MessageFailed
{
    public function __construct(
        public readonly OutboxMessage $message,
        public readonly Throwable $exception,
        public readonly bool $exhausted,
    ) {}
}
