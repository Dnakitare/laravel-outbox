# Laravel Outbox

[![Tests](https://github.com/dnakitare/laravel-outbox/actions/workflows/test.yml/badge.svg)](https://github.com/dnakitare/laravel-outbox/actions/workflows/test.yml)
[![Static analysis](https://github.com/dnakitare/laravel-outbox/actions/workflows/static.yml/badge.svg)](https://github.com/dnakitare/laravel-outbox/actions/workflows/static.yml)
[![Latest version](https://img.shields.io/packagist/v/dnakitare/laravel-outbox.svg?include_prereleases)](https://packagist.org/packages/dnakitare/laravel-outbox)
[![License](https://img.shields.io/packagist/l/dnakitare/laravel-outbox.svg)](LICENSE)

> **⚠️ Beta software.** This package is at `0.1.0-beta1` and has not yet
> been battle-tested in production. Adopt with eyes open, file issues
> generously. We commit to strict SemVer starting at `1.0.0`.

A production-grade implementation of the Transactional Outbox Pattern
for Laravel.

Events and queued jobs dispatched inside `Outbox::transaction()` are
persisted to an `outbox_messages` table atomically with your business
writes. A worker then replays them against the real event dispatcher
and job queue. If the downstream fails, messages retry with
exponential backoff, and ultimately land in a dead-letter table where
they can be inspected and manually reset.

## Requirements

- PHP 8.1+ (PHP 8.2+ for Laravel 12)
- Laravel 10, 11, or 12
- A database that supports row-level locks: MySQL 8+, MariaDB 10.6+,
  PostgreSQL 9.5+. SQLite works for testing but serialises workers.

## Installation

```bash
composer require dnakitare/laravel-outbox
php artisan vendor:publish --tag=outbox-config
php artisan vendor:publish --tag=outbox-migrations
php artisan migrate
```

## Usage

```php
use Dnakitare\Outbox\Facades\Outbox;

Outbox::transaction('Order', $order->id, function () use ($order) {
    $order->save();

    event(new OrderCreated($order));
    SendReceipt::dispatch($order);
});
```

Inside the closure, `event()` and `dispatch()` are intercepted: nothing
fires on the real event bus or goes to the real queue. They are
persisted with your `$order->save()` in one SQL transaction. After
commit, a worker (`outbox:process`) picks them up and fires them for
real.

## Production checklist

This is a database-integrity-critical piece of your system. Go through
this list before relying on it in production.

### 1. Allowlist every event and job class you dispatch

Outbox refuses to deserialize classes that aren't explicitly allowed.
Add yours to `config/outbox.php`:

```php
'serialization' => [
    'allowed_classes' => [
        App\Events\OrderCreated::class,
        App\Events\OrderShipped::class,
        App\Jobs\SendReceipt::class,
        App\Jobs\NotifyWarehouse::class,
    ],
],
```

This is defence-in-depth on top of HMAC integrity. An attacker who
somehow gets write access to `outbox_messages` still cannot execute
arbitrary classes. Forgetting to add a class sends its messages to
dead-letter — not to execution.

### 2. Run the worker from the scheduler

Pick one pattern. Do not mix them — you'll get duplicate work.

**Option A — long-running supervised worker (recommended at scale):**

Use Supervisor / systemd / Horizon to keep this running:

```bash
php artisan outbox:process --batch=100 --sleep=1
```

**Option B — cron-driven bursts (simpler, lower throughput):**

In `app/Console/Kernel.php` (Laravel 10) or `routes/console.php`
(Laravel 11+):

```php
$schedule->command('outbox:process --once --batch=200')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
```

**Option C — inline after each transaction (dev/low-volume):**

```env
OUTBOX_PROCESS_IMMEDIATELY=true
```

Each transaction commit dispatches a `ProcessOutboxMessages` job to
your normal queue. Requires a running `queue:work` or Horizon worker.

### 3. Prune old rows on a schedule

The tables grow forever otherwise.

```php
$schedule->command('outbox:prune --force')->dailyAt('03:00');
```

Tune retention via `config/outbox.php` — `pruning.retention_days`
and `dead_letter.retention_days`.

### 4. Monitor health and alert

Wire `Outbox::health()` into whatever your ops team watches. It
returns `status: healthy|warning|critical`. `critical` means at least
one message has been stuck in `processing` longer than
`outbox.processing.lock_timeout` seconds — a worker almost certainly
died mid-batch and those messages will NOT retry automatically until
a human resets them.

```php
Route::get('/_health/outbox', function () {
    $health = Outbox::health();
    $status = match ($health['status']) {
        'healthy' => 200,
        'warning' => 200, // Still serving; let your graphs fire.
        'critical' => 503,
    };
    return response()->json($health, $status);
})->middleware('internal-only');
```

### 5. Subscribe to observability events

Three events fire during the outbox lifecycle. Wire them to whatever
metric backend you use:

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Dnakitare\Outbox\Events\MessagesStored::class => [
        \App\Listeners\Outbox\RecordStored::class,
    ],
    \Dnakitare\Outbox\Events\MessageProcessed::class => [
        \App\Listeners\Outbox\RecordProcessingDuration::class,
    ],
    \Dnakitare\Outbox\Events\MessageFailed::class => [
        \App\Listeners\Outbox\PageOnExhaustedFailure::class,
    ],
];
```

`MessageFailed` carries `$exhausted: true` when the message has just
landed in dead-letter. That's the signal to page a human.

Alternatively, implement `Dnakitare\Outbox\Contracts\MetricsCollector`
and set its FQCN in `outbox.monitoring.metrics_collector`.

### 6. Make your listeners and jobs idempotent

Outbox delivers **at-least-once**. A message may replay if the worker
crashes between dispatching and marking complete. Every listener and
job handler must be safe to execute twice.

The simplest pattern: use the `correlation_id` (available on every
outbox row) as a dedup key in an `idempotency_log` table or a Redis
SETNX.

### 7. Tune backoff for your downstream

The default backoff starts at 5s, doubles each attempt, caps at 600s,
and adds full jitter. If your downstream is a fast internal service,
tighten `base_seconds`. If it's a flaky third-party with long
outages, raise `max_seconds`. Jitter should almost always be left on.

```env
OUTBOX_BACKOFF_BASE=5
OUTBOX_BACKOFF_MAX=600
OUTBOX_BACKOFF_MULTIPLIER=2.0
OUTBOX_BACKOFF_JITTER=true
```

### 8. Rotate the HMAC key carefully

Payloads are signed with `OUTBOX_HMAC_KEY` (falling back to `APP_KEY`).
If you rotate the key, all in-flight messages signed with the old key
will fail integrity and dead-letter. Drain the outbox table before
rotating, or dual-sign during a transition window (not yet supported —
issue welcome).

## Delivery semantics

- **At-least-once.** Listeners and job handlers must be idempotent.
- **Ordering is preserved within a single transaction.** Messages from
  the same `Outbox::transaction()` call carry a `sequence_number` and
  are claimed in order. Across transactions there is no ordering
  guarantee.
- **Backoff between retries.** Truncated exponential with full jitter.
- **Exhaustion → dead-letter.** After `max_attempts` the message is
  marked `failed` and copied to `outbox_dead_letter`.

## Concurrency

`claimPendingMessages()` uses `SELECT ... FOR UPDATE SKIP LOCKED` on
MySQL/Postgres so you can run many workers horizontally without
contention. Each worker sees a disjoint batch. On SQLite (tests only)
it falls back to plain `FOR UPDATE`, which is correct but serialises.

## Operations

```bash
# Inspect dead-letter
php artisan outbox:inspect-dead-letter
php artisan outbox:inspect-dead-letter --id=<uuid>
php artisan outbox:inspect-dead-letter --aggregate=Order

# Retry failed messages (preserves history)
php artisan outbox:retry --all
php artisan outbox:retry --id=<uuid1> --id=<uuid2>
php artisan outbox:retry --all --purge-history   # discards history

# Prune
php artisan outbox:prune                         # uses config defaults
php artisan outbox:prune --completed-days=3 --dead-letter-days=180
```

## Security

**HMAC-signed payloads.** Every stored payload is prefixed with an
HMAC-SHA256 tag computed with your `APP_KEY` (override via
`OUTBOX_HMAC_KEY`). A tampered payload fails verification at replay
and is sent to dead-letter.

**Class allowlist on deserialisation.** `unserialize()` is called with
`allowed_classes` populated from `outbox.serialization.allowed_classes`.
A payload referencing a class not on the list lands in dead-letter
rather than rehydrating.

Report security issues privately to the package maintainer rather than
via the public issue tracker.

## Testing

```bash
composer test                 # pest, against SQLite
composer analyse              # phpstan level 5
composer format-check         # pint
composer check                # all three
```

Tests cover unit (service, serializer, backoff), integration (real
repository against SQLite), concurrency (disjoint claims), and
end-to-end feature tests covering success, retry, backoff,
dead-letter, payload tampering, and rehydration failure.

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) and
[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## License

MIT. See [LICENSE](LICENSE).
