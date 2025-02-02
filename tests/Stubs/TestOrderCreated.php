<?php

namespace Laravel\Outbox\Tests\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;

class TestOrderCreated implements ShouldQueue
{
    public function __construct(public string $orderId) {}

    public function handle(): void
    {
        // Test event handler
    }
}
