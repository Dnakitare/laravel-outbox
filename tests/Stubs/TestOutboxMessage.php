<?php

namespace Dnakitare\Outbox\Tests\Stubs;

use Dnakitare\Outbox\Contracts\OutboxMessage;

class TestOutboxMessage implements OutboxMessage
{
    public function __construct(protected object $message) {}

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

    public function getAggregateType(): string
    {
        return $this->message->aggregate_type;
    }

    public function getAggregateId(): string
    {
        return $this->message->aggregate_id;
    }

    public function getMessageType(): string
    {
        return $this->message->message_type;
    }

    public function getPayload(): mixed
    {
        return $this->message->payload;
    }

    public function getRawPayload(): string
    {
        return is_string($this->message->payload)
            ? $this->message->payload
            : (string) $this->message->payload;
    }

    public function getStatus(): string
    {
        return $this->message->status;
    }

    public function getAttempts(): int
    {
        return (int) $this->message->attempts;
    }

    public function getSequenceNumber(): int
    {
        return (int) ($this->message->sequence_number ?? 0);
    }

    public function getHistory(): array
    {
        $history = $this->message->history ?? null;
        if (is_string($history) && $history !== '') {
            $decoded = json_decode($history, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($history) ? $history : [];
    }
}
