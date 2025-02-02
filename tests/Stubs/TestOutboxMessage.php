<?php

namespace Laravel\Outbox\Tests\Stubs;

use Laravel\Outbox\Contracts\OutboxMessage;

class TestOutboxMessage implements OutboxMessage
{
    public function __construct(protected $message) {}

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
        return $this->message->payload;
    }

    public function getStatus(): string
    {
        return $this->message->status;
    }

    public function getAttempts(): int
    {
        return $this->message->attempts;
    }

    public function getAggregateType(): string
    {
        return $this->message->aggregate_type;
    }

    public function getAggregateId(): string
    {
        return $this->message->aggregate_id;
    }
}
