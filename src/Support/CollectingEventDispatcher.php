<?php

namespace Dnakitare\Outbox\Support;

use Dnakitare\Outbox\OutboxService;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Intercepts events dispatched during an outbox transaction and routes
 * them to the outbox service for persistence instead of letting them
 * fire listeners inline.
 *
 * Listener registration, subscribers, push, until, etc. pass through
 * unchanged to the wrapped dispatcher.
 */
class CollectingEventDispatcher implements Dispatcher
{
    public function __construct(
        protected OutboxService $outboxService,
        protected Dispatcher $inner,
    ) {}

    public function dispatch($event, $payload = [], $halt = false)
    {
        // Normalise the Laravel signature: dispatch may be called with
        // either an event object, or a string event name + payload.
        // In both cases we capture exactly what the caller supplied.
        $this->outboxService->collect([
            'event' => $event,
            'payload' => is_array($payload) ? $payload : [$payload],
        ], 'event');

        // The interface contract is `array|null`. Listeners intentionally
        // do not run inside the outbox transaction — they'll run when
        // the replay job rehydrates the event. Returning null signals
        // "no listeners responded", which is truthful.
        return $halt ? null : [];
    }

    public function listen($events, $listener = null): void
    {
        $this->inner->listen($events, $listener);
    }

    public function hasListeners($eventName): bool
    {
        return $this->inner->hasListeners($eventName);
    }

    public function subscribe($subscriber): void
    {
        $this->inner->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        // `until` expects a synchronous response from listeners (e.g.
        // authorization gates). We can't defer those — pass through so
        // the app's semantics are preserved.
        return $this->inner->until($event, $payload);
    }

    public function forget($event): void
    {
        $this->inner->forget($event);
    }

    public function forgetPushed(): void
    {
        $this->inner->forgetPushed();
    }

    public function push($event, $payload = []): void
    {
        $this->inner->push($event, $payload);
    }

    public function flush($event): void
    {
        $this->inner->flush($event);
    }
}
