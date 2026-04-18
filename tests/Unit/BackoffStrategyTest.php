<?php

namespace Dnakitare\Outbox\Tests\Unit;

use Dnakitare\Outbox\Support\BackoffStrategy;
use Dnakitare\Outbox\Tests\TestCase;

class BackoffStrategyTest extends TestCase
{
    public function test_grows_exponentially_without_jitter(): void
    {
        $strategy = new BackoffStrategy(baseSeconds: 5, maxSeconds: 1000, multiplier: 2.0, jitter: false);

        $this->assertSame(5, $strategy->delayFor(1));
        $this->assertSame(10, $strategy->delayFor(2));
        $this->assertSame(20, $strategy->delayFor(3));
        $this->assertSame(40, $strategy->delayFor(4));
    }

    public function test_caps_at_max_seconds(): void
    {
        $strategy = new BackoffStrategy(baseSeconds: 5, maxSeconds: 30, multiplier: 2.0, jitter: false);

        $this->assertSame(30, $strategy->delayFor(10));
    }

    public function test_jitter_produces_values_within_range(): void
    {
        $strategy = new BackoffStrategy(baseSeconds: 10, maxSeconds: 100, multiplier: 2.0, jitter: true);

        for ($i = 0; $i < 20; $i++) {
            $d = $strategy->delayFor(3); // raw would be 40
            $this->assertGreaterThanOrEqual(20, $d);
            $this->assertLessThanOrEqual(40, $d);
        }
    }
}
