# Changelog

All notable changes to `dnakitare/laravel-outbox` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the package is at `0.x`, minor versions may include breaking changes;
see individual release notes. Starting at `1.0.0` the project will commit to
SemVer strictly.

## [Unreleased]

## [0.1.0-beta2] - 2026-04-19

Functional superset of 0.1.0-beta1. **No user-facing code changes.**
Release exists so Composer resolvers see a clean `php: ^8.2`
constraint (0.1.0-beta1 was tagged with the pre-fix `^8.1`).

### Changed

- Require PHP 8.2+. PHP 8.1 reached end-of-life in December 2025 and
  Pest 2's transitive dependency `brianium/paratest` now requires
  PHP 8.2+, so the matrix combination could not resolve.
- Dev-only security advisories resolved via dependency bumps:
  `phpunit/phpunit` (CVE-2026-24765), `symfony/http-foundation`
  (CVE-2025-64500), `psy/psysh` (CVE-2026-25129).
- `nunomaduro/larastan` (abandoned) → `larastan/larastan`.
- `actions/checkout@v4` → `v6` (clears Node.js 20 deprecation).

## [0.1.0-beta1] - 2026-04-18

Initial public release. Not yet battle-tested in production — adopt with
eyes open and file issues generously.

### Added

- `Outbox::transaction(string $aggregateType, string $aggregateId, callable $callback)`
  facade that wraps business writes and captures events/jobs dispatched
  inside the callback.
- Atomic persistence of captured events/jobs to `outbox_messages` in the same
  DB transaction as the business writes.
- `outbox:process` artisan command with `--batch`, `--sleep`, `--max`,
  `--once` flags. Handles `SIGTERM`/`SIGINT` for graceful shutdown.
- Concurrent worker claim using `SELECT ... FOR UPDATE SKIP LOCKED` on
  MySQL 8+, MariaDB 10.6+, and PostgreSQL. Falls back to plain
  `FOR UPDATE` on SQLite and older engines (correct, just serialised).
- Truncated exponential backoff with full jitter on failed replays,
  configurable via `outbox.processing.backoff.*`.
- Dead-letter table with full error, stack trace, metadata, history, and
  aggregate-typed context for forensics.
- `outbox:retry` command (preserves failure history by default;
  `--purge-history` opts out).
- `outbox:prune` command honouring `outbox.pruning.*` and
  `outbox.dead_letter.retention_days` as defaults.
- `outbox:inspect-dead-letter` command with summary and detailed views.
- HMAC-signed payloads: every stored payload is prefixed with a
  SHA-256 HMAC computed from the app key (override via
  `OUTBOX_HMAC_KEY`). Tampered payloads route to dead-letter instead
  of executing.
- Class allowlist on deserialisation via
  `outbox.serialization.allowed_classes`. Unknown classes are rejected.
- Observability events: `MessagesStored`, `MessageProcessed`,
  `MessageFailed`.
- Config-driven `MetricsCollector` binding via
  `outbox.monitoring.metrics_collector`.
- `Outbox::health()` with stuck-processing detection driven by
  `outbox.processing.lock_timeout`.
- `Outbox::getStats()` for pending/completed/failed/dead-letter counts.
- `OutboxDebugger` for interactive inspection of individual messages,
  stuck processors, and repeated failures.

### Supported

- PHP 8.2, 8.3, 8.4
- Laravel 10.x, 11.x, 12.x
- MySQL 8+, MariaDB 10.6+, PostgreSQL 9.5+, SQLite (testing only)

### Known limitations

- At-least-once delivery. Listeners must be idempotent.
- Ordering is preserved within a single `Outbox::transaction()` call,
  not across transactions.
- `SKIP LOCKED` concurrency behaviour is not exercised in CI on SQLite;
  the MySQL/MariaDB/Postgres CI jobs cover it.
- No metrics adapter ships — only `NullMetricsCollector`. Implement
  `MetricsCollector` in your app to push to Prometheus/StatsD.

[Unreleased]: https://github.com/dnakitare/laravel-outbox/compare/0.1.0-beta2...HEAD
[0.1.0-beta2]: https://github.com/dnakitare/laravel-outbox/releases/tag/0.1.0-beta2
[0.1.0-beta1]: https://github.com/dnakitare/laravel-outbox/releases/tag/0.1.0-beta1
