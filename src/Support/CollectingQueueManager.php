<?php

namespace Dnakitare\Outbox\Support;

use Dnakitare\Outbox\OutboxService;
use Illuminate\Queue\QueueManager;

/**
 * During an outbox transaction, replaces the 'queue' container binding
 * so that every job push — whether made directly via Queue::push() or
 * indirectly via Bus::dispatch() → QueueManager::connection()->push() —
 * is captured into the outbox instead of being sent to a real broker.
 *
 * Inherits from QueueManager to remain type-compatible with anything
 * hinting the concrete class, but overrides the surface the app
 * actually touches. Unrecognised calls fall through to the real
 * manager via __call.
 */
class CollectingQueueManager extends QueueManager
{
    protected OutboxService $outboxService;

    protected QueueManager $inner;

    protected CollectingQueue $collectingQueue;

    public function __construct(OutboxService $outboxService, QueueManager $inner)
    {
        // Construct the parent with a sensible Application reference so
        // any inherited state is valid if it's ever reached via parent
        // dispatch. We don't rely on parent behaviour for the hot path.
        parent::__construct($inner->getApplication());

        $this->outboxService = $outboxService;
        $this->inner = $inner;
        $this->collectingQueue = new CollectingQueue($outboxService);
    }

    public function connection($name = null)
    {
        return $this->collectingQueue;
    }

    public function push($job, $data = '', $queue = null): void
    {
        $this->collectingQueue->push($job, $data, $queue);
    }

    public function pushOn($queue, $job, $data = ''): void
    {
        $this->collectingQueue->pushOn($queue, $job, $data);
    }

    public function later($delay, $job, $data = '', $queue = null): void
    {
        $this->collectingQueue->later($delay, $job, $data, $queue);
    }

    public function laterOn($queue, $delay, $job, $data = ''): void
    {
        $this->collectingQueue->laterOn($queue, $delay, $job, $data);
    }

    public function bulk($jobs, $data = '', $queue = null): void
    {
        $this->collectingQueue->bulk($jobs, $data, $queue);
    }

    /**
     * Forward anything else (addConnector, extend, before/after hooks,
     * size, etc.) to the real queue manager so non-dispatch code keeps
     * working during a transaction.
     */
    public function __call($method, $parameters)
    {
        return $this->inner->{$method}(...$parameters);
    }
}
