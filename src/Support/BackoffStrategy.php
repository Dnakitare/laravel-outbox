<?php

namespace Laravel\Outbox\Support;

use Illuminate\Support\Carbon;

/**
 * Computes the next retry time after a failed attempt.
 *
 * Uses truncated exponential backoff with optional jitter so that many
 * messages failing at the same time (e.g. a downstream outage) don't all
 * retry in lockstep and re-overwhelm the dependency.
 */
class BackoffStrategy
{
    public function __construct(
        protected int $baseSeconds = 5,
        protected int $maxSeconds = 600,
        protected float $multiplier = 2.0,
        protected bool $jitter = true,
    ) {}

    public function nextAttemptAt(int $attempt): Carbon
    {
        return Carbon::now()->addSeconds($this->delayFor($attempt));
    }

    public function delayFor(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $raw = (int) min(
            $this->maxSeconds,
            $this->baseSeconds * (int) ($this->multiplier ** ($attempt - 1))
        );

        if ($raw <= 0) {
            return $this->baseSeconds;
        }

        if (! $this->jitter) {
            return $raw;
        }

        // "Full jitter" (Marc Brooker, AWS): uniform between 0 and raw.
        // Gives good spreading without risking very short retries
        // because the lower bound is still small.
        return random_int((int) ($raw / 2), $raw);
    }
}
