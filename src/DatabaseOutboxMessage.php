<?php

namespace Dnakitare\Outbox;

use Dnakitare\Outbox\Contracts\OutboxMessage as OutboxMessageContract;
use Dnakitare\Outbox\Support\PayloadSerializer;

class DatabaseOutboxMessage implements OutboxMessageContract
{
    public function __construct(
        protected object $row,
        protected PayloadSerializer $serializer,
    ) {}

    public function getId(): string
    {
        return $this->row->id;
    }

    public function getTransactionId(): string
    {
        return $this->row->transaction_id;
    }

    public function getCorrelationId(): string
    {
        return $this->row->correlation_id;
    }

    public function getType(): string
    {
        return $this->row->type;
    }

    public function getAggregateType(): string
    {
        return $this->row->aggregate_type;
    }

    public function getAggregateId(): string
    {
        return $this->row->aggregate_id;
    }

    public function getMessageType(): string
    {
        return $this->row->message_type;
    }

    public function getPayload(): mixed
    {
        return $this->serializer->unserialize($this->row->payload);
    }

    public function getRawPayload(): string
    {
        return $this->row->payload;
    }

    public function getStatus(): string
    {
        return $this->row->status;
    }

    public function getAttempts(): int
    {
        return (int) $this->row->attempts;
    }

    public function getSequenceNumber(): int
    {
        return (int) $this->row->sequence_number;
    }

    public function getHistory(): array
    {
        $history = $this->row->history ?? null;

        if ($history === null || $history === '') {
            return [];
        }

        $decoded = json_decode($history, true);

        return is_array($decoded) ? $decoded : [];
    }
}
