<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    */
    'table' => [
        'messages' => 'outbox_messages',
        'dead_letter' => 'outbox_dead_letter',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    */
    'processing' => [
        'max_attempts' => 3,
        'batch_size' => 100,
        'process_immediately' => true,
        'process_delay' => 1,
        'lock_timeout' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('OUTBOX_QUEUE_CONNECTION', 'redis'),
        'name' => env('OUTBOX_QUEUE', 'outbox'),
        'dead_letter' => env('OUTBOX_DEAD_LETTER_QUEUE', 'outbox-dead-letter'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Configuration
    |--------------------------------------------------------------------------
    */
    'dead_letter' => [
        'enabled' => true,
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'enabled' => true,
        'collector' => env('OUTBOX_METRICS_COLLECTOR', 'null'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning Configuration
    |--------------------------------------------------------------------------
    */
    'pruning' => [
        'enabled' => true,
        'retention_days' => 7,
        'chunk_size' => 1000,
    ],
];
