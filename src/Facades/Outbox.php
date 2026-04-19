<?php

namespace Dnakitare\Outbox\Facades;

use Dnakitare\Outbox\OutboxService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed transaction(string $aggregateType, string $aggregateId, callable $callback)
 * @method static void collect(mixed $message, string $type)
 * @method static array health()
 * @method static array getStats()
 *
 * @see OutboxService
 */
class Outbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'outbox';
    }
}
