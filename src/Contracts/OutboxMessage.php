<?php

namespace Laravel\Outbox\Contracts;

interface OutboxMessage
{
    public function getId(): string;

    public function getTransactionId(): string;

    public function getCorrelationId(): string;

    public function getType(): string;

    public function getPayload(): mixed;

    public function getStatus(): string;

    public function getAttempts(): int;
}
