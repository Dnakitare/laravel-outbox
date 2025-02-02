<?php

namespace Laravel\Outbox\Tests\Stubs;

class TestEvent
{
    public function __construct(public string $data) {}
}
