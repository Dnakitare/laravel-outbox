# Laravel Outbox

A production-grade implementation of the Transactional Outbox Pattern for Laravel.

Events and queued jobs dispatched inside `Outbox::transaction()` are persisted to an `outbox_messages` table atomically with your business writes. A worker then replays them against the real event dispatcher and job queue. If the downstream fails, messages retry with exponential backoff, and ultimately land in a dead-letter table where they can be inspected and manually reset.

## Requirements

- PHP 8.1+
- Laravel 10 or 11
- A database that supports row-level locks (MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+). SQLite works for testing but has no `SKIP LOCKED` so workers will serialize.

## Installation

```bash
composer require laravel/outbox
php artisan vendor:publish --tag=outbox-config
php artisan vendor:publish --tag=outbox-migrations
php artisan migrate
```

## Usage

```php
use Laravel\Outbox\Facades\Outbox;

Outbox::transaction('Order', $order->id, function () use ($order) {
    $order->save();

    event(new OrderCreated($order));
    SendReceipt::dispatch($order);
});
```

Inside the closure, `event()` and `dispatch()` are intercepted: nothing fires on the real event bus or goes to the real queue. They are persisted with your `$order->save()` in one SQL transaction. After commit, a worker (`outbox:process`) picks them up and fires them for real.

### Allowlist your event/job classes

Outbox refuses to deserialize classes that aren't explicitly allowed. Add yours to `config/outbox.php`:

```php
'serialization' => [
    'allowed_classes' => [
        App\Events\OrderCreated::class,
        App\Jobs\SendReceipt::class,
    ],
],
```

This is a defense-in-depth measure on top of HMAC integrity checks. See [Security](#security) below.

### Running the worker

```bash
# Supervised (recommended in production):
php artisan outbox:process --batch=100 --sleep=1

# One shot (cron-driven):
php artisan outbox:process --once
```

The worker responds to `SIGTERM`/`SIGINT` and exits cleanly between batches.

If you prefer, enable `process_immediately` in config and each successful transaction will enqueue a `ProcessOutboxMessages` job onto your normal queue worker — useful when you already have `queue:work` running and don't want a dedicated outbox worker.

## Delivery semantics

**At-least-once.** A message may be delivered more than once in the face of worker crashes between `dispatch` and `markAsComplete`. Design your event listeners and job handlers to be idempotent (e.g. use the `correlation_id` as a dedup key, or check business state before applying side-effects).

**Ordering is preserved within a transaction.** Messages from the same `Outbox::transaction()` call carry a `sequence_number` and are claimed in order. Across transactions there is no ordering guarantee.

**Backoff between retries.** On failure, a message is rescheduled with truncated exponential backoff plus full jitter. Configure via `processing.backoff.*`. Once `max_attempts` is exhausted, the message is marked `failed` and copied to the dead-letter table.

## Concurrency

`claimPendingMessages()` uses `SELECT ... FOR UPDATE SKIP LOCKED` on MySQL/Postgres so you can run many workers horizontally without contention. Each worker sees a disjoint batch. Without `SKIP LOCKED` (older MySQL, SQLite), workers still work correctly — they just serialize.

## Operations

```bash
# Dead-letter inspection
php artisan outbox:inspect-dead-letter
php artisan outbox:inspect-dead-letter --id=<uuid>
php artisan outbox:inspect-dead-letter --aggregate=Order

# Reset failed messages back to pending (preserves history in each row)
php artisan outbox:retry --all
php artisan outbox:retry --id=<uuid1> --id=<uuid2>
php artisan outbox:retry --all --purge-history  # if you want to discard

# Prune
php artisan outbox:prune --completed-days=7 --failed-days=30 --dead-letter-days=90
```

### Health

```php
use Laravel\Outbox\Facades\Outbox;

Outbox::health();
// [
//     'status' => 'healthy'|'warning'|'critical',
//     'messages' => ['pending' => 0, 'processing' => 0, 'completed' => 1234, ...],
//     'oldest_pending' => '2026-04-18 12:00:00',
//     'stuck_processing' => 0,
//     'lock_timeout_seconds' => 300,
//     'dead_letter' => ['count' => 0, 'oldest' => null],
// ]
```

`critical` means at least one message has been in `processing` longer than `outbox.processing.lock_timeout` seconds — likely a worker died mid-batch. Such messages will NOT be retried until you manually reset them (they're not in `pending` state).

### Observability hooks

Three events fire during processing; wire them to your metrics backend:

- `Laravel\Outbox\Events\MessagesStored` — emitted after a successful transaction, with `$transactionId`, `$correlationId`, `$count`.
- `Laravel\Outbox\Events\MessageProcessed` — emitted after a message is replayed successfully, with `$message` and `$durationSeconds`.
- `Laravel\Outbox\Events\MessageFailed` — emitted on every failure, with `$message`, `$exception`, `$exhausted` (true when moved to dead-letter).

For deeper integration, implement `Laravel\Outbox\Contracts\MetricsCollector` and set its FQCN in `config/outbox.monitoring.metrics_collector`.

## Security

**HMAC-signed payloads.** Every stored payload is prefixed with an HMAC-SHA256 tag computed with your `APP_KEY` (override via `OUTBOX_HMAC_KEY`). A tampered payload fails verification at replay and is sent to dead-letter.

**Class allowlist on deserialization.** `unserialize()` is called with `allowed_classes` populated from `outbox.serialization.allowed_classes`. A payload referencing a class not on the list lands in dead-letter rather than rehydrating.

Together these close the PHP-object-injection attack surface typical of naive outbox implementations: an attacker who gains write access to `outbox_messages` (e.g. SQL injection in another part of your app) cannot turn that into RCE without also stealing the HMAC key.

Report security issues to the package maintainer directly rather than via the public issue tracker.

## Testing

```bash
composer test
```

The test suite includes unit tests (service, serializer, backoff), integration tests (real repository against SQLite), a concurrency test (disjoint claims), and end-to-end feature tests covering success, retry, backoff, dead-letter, and payload-tampering paths.

## License

MIT.
