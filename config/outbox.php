<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    */
    'table' => [
        'messages' => env('OUTBOX_TABLE', 'outbox_messages'),
        'dead_letter' => env('OUTBOX_DEAD_LETTER_TABLE', 'outbox_dead_letter'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    |
    | max_attempts: number of worker attempts before a message is moved to
    |   the dead letter queue.
    | batch_size: messages claimed per worker loop iteration.
    | process_immediately: if true, each successful outbox transaction
    |   dispatches a ProcessOutboxMessages job so delivery latency stays
    |   low. Leave false if a supervised `outbox:process --loop` worker
    |   is running, to avoid redundant dispatches.
    | lock_timeout: seconds after which a message stuck in 'processing' is
    |   considered abandoned and reported as critical by health().
    | backoff: exponential backoff with full jitter between retries.
    |
    */
    'processing' => [
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 3),
        'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),
        'process_immediately' => (bool) env('OUTBOX_PROCESS_IMMEDIATELY', false),
        'lock_timeout' => (int) env('OUTBOX_LOCK_TIMEOUT', 300),
        'backoff' => [
            'base_seconds' => (int) env('OUTBOX_BACKOFF_BASE', 5),
            'max_seconds' => (int) env('OUTBOX_BACKOFF_MAX', 600),
            'multiplier' => (float) env('OUTBOX_BACKOFF_MULTIPLIER', 2.0),
            'jitter' => (bool) env('OUTBOX_BACKOFF_JITTER', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where ProcessOutboxMessages jobs are dispatched when
    | process_immediately is enabled. Null uses Laravel's defaults.
    |
    */
    'queue' => [
        'connection' => env('OUTBOX_QUEUE_CONNECTION'),
        'name' => env('OUTBOX_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serialization
    |--------------------------------------------------------------------------
    |
    | Every payload is stored as HMAC-signed PHP serialize bytes. The
    | signing key defaults to the application key; set hmac_key to
    | override (useful when rotating keys).
    |
    | allowed_classes restricts which classes unserialize() will rehydrate.
    | Set to true to allow any class (not recommended in production).
    |
    */
    'serialization' => [
        'hmac_key' => env('OUTBOX_HMAC_KEY'),
        'allowed_classes' => [
            // Add your event and job classes here, e.g.
            // App\Events\OrderCreated::class,
            // App\Jobs\SendInvoice::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter
    |--------------------------------------------------------------------------
    */
    'dead_letter' => [
        'enabled' => (bool) env('OUTBOX_DEAD_LETTER_ENABLED', true),
        'retention_days' => (int) env('OUTBOX_DEAD_LETTER_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Checks
    |--------------------------------------------------------------------------
    */
    'health' => [
        'pending_age_warning_seconds' => (int) env('OUTBOX_HEALTH_PENDING_AGE', 3600),
        'pending_count_warning' => (int) env('OUTBOX_HEALTH_PENDING_COUNT', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | Set metrics_collector to the FQCN of a Laravel\Outbox\Contracts\MetricsCollector
    | implementation, or leave null to use NullMetricsCollector.
    |
    */
    'monitoring' => [
        'enabled' => (bool) env('OUTBOX_MONITORING_ENABLED', true),
        'metrics_collector' => env('OUTBOX_METRICS_COLLECTOR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    */
    'pruning' => [
        'retention_days' => (int) env('OUTBOX_PRUNING_RETENTION_DAYS', 7),
        'chunk_size' => (int) env('OUTBOX_PRUNING_CHUNK', 1000),
    ],
];
