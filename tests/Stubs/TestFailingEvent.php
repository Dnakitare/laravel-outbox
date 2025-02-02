<?php

namespace Laravel\Outbox\Tests\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;

class TestFailingEvent implements ShouldQueue
{
    public function __construct() {}

    public function handle(): void
    {
        throw new \Exception('Simulated failure');
    }
}
