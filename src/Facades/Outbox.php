<?php

namespace Laravel\Outbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed transaction(string $aggregateType, string $aggregateId, callable $callback)
 * @method static array getStats()
 * @method static array health()
 * 
 * @see \Laravel\Outbox\OutboxService
 */
class Outbox extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'outbox';
    }
}