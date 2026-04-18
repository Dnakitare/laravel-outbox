<?php

namespace Dnakitare\Outbox\Tests\Stubs;

class TestEvent
{
    public function __construct(public string $data) {}
}
