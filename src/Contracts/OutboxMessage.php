<?php

namespace Laravel\Outbox\Contracts;

interface OutboxMessage
{
    public function getId(): string;

    public function getTransactionId(): string;

    public function getCorrelationId(): string;

    public function getType(): string;

    public function getAggregateType(): string;

    public function getAggregateId(): string;

    public function getMessageType(): string;

    /**
     * The decoded payload (event or job instance). May throw
     * SerializationException if the stored payload fails integrity or
     * allowlist checks.
     */
    public function getPayload(): mixed;

    /**
     * The raw, unprocessed payload string as stored. Safe to read without
     * triggering deserialization.
     */
    public function getRawPayload(): string;

    public function getStatus(): string;

    public function getAttempts(): int;

    public function getSequenceNumber(): int;

    /**
     * Previous failure history (append-only log of prior errors).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(): array;
}
