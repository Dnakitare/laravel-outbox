<?php

namespace Laravel\Outbox;

use Laravel\Outbox\Contracts\OutboxMessage as OutboxMessageContract;

class DatabaseOutboxMessage implements OutboxMessageContract
{
    public function __construct(
        private object $message
    ) {}

    public function getId(): string
    {
        return $this->message->id;
    }

    public function getTransactionId(): string
    {
        return $this->message->transaction_id;
    }

    public function getCorrelationId(): string
    {
        return $this->message->correlation_id;
    }

    public function getType(): string
    {
        return $this->message->type;
    }

    public function getPayload(): mixed
    {
        return unserialize($this->message->payload);
    }

    public function getStatus(): string
    {
        return $this->message->status;
    }

    public function getAttempts(): int
    {
        return $this->message->attempts;
    }
}
