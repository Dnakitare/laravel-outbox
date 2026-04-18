<?php

namespace Dnakitare\Outbox\Tests\Stubs;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Simulates a job whose rehydration is fine but whose invocation throws
 * — e.g. a job that holds a model reference that no longer exists in
 * the DB by the time the worker processes it.
 *
 * We use __wakeup to trigger the failure deterministically when
 * unserialize runs during replay, which matches the common "stale
 * payload" failure mode in production.
 */
class TestDeadOnWakeupJob implements ShouldQueue
{
    public bool $triggered = false;

    public function __wakeup(): void
    {
        throw new \RuntimeException('rehydration failed: referenced model no longer exists');
    }

    public function handle(): void
    {
        // Unreachable — __wakeup throws first.
    }
}
