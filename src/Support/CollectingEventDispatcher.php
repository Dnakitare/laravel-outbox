<?php

namespace Laravel\Outbox\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Outbox\OutboxService;

class CollectingEventDispatcher implements Dispatcher
{
    public function __construct(
        private OutboxService $outboxService,
        private Dispatcher $dispatcher
    ) {}

    public function dispatch($event, $payload = [], $halt = false)
    {
        $this->outboxService->collect($event, 'event');
    }

    public function listen($events, $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    public function hasListeners($eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    public function subscribe($subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatcher->until($event, $payload);
    }

    public function forget($event): void
    {
        $this->dispatcher->forget($event);
    }

    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    public function push($event, $payload = []): void
    {
        $this->dispatcher->push($event, $payload);
    }

    public function flush($event): void
    {
        $this->dispatcher->flush($event);
    }
}
