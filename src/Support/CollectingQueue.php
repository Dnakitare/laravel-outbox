<?php

namespace Dnakitare\Outbox\Support;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Dnakitare\Outbox\OutboxService;

/**
 * A Queue implementation that, instead of pushing jobs to a real queue
 * backend, captures them so the OutboxService can persist them inside
 * the current transaction.
 *
 * Used by CollectingQueueManager to intercept jobs dispatched via the
 * Bus — Bus::dispatch routes jobs through QueueManager::connection(),
 * and by returning a CollectingQueue there we guarantee all
 * asynchronous dispatch paths end up in the outbox.
 */
class CollectingQueue implements QueueContract
{
    protected string $connectionName = 'outbox-collecting';

    public function __construct(protected OutboxService $outboxService) {}

    public function size($queue = null): int
    {
        return 0;
    }

    public function push($job, $data = '', $queue = null): void
    {
        $this->outboxService->collect([
            'job' => $job,
            'data' => $data,
            'queue' => $queue,
        ], 'job');
    }

    public function pushOn($queue, $job, $data = ''): void
    {
        $this->push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): void
    {
        // Raw payloads skip the outbox — they can't be meaningfully
        // rehydrated later without knowing the original job class.
        // Collect the raw payload opaquely.
        $this->outboxService->collect([
            'raw' => true,
            'payload' => $payload,
            'queue' => $queue,
            'options' => $options,
        ], 'job');
    }

    public function later($delay, $job, $data = '', $queue = null): void
    {
        $this->outboxService->collect([
            'job' => $job,
            'data' => $data,
            'queue' => $queue,
            'delay' => $delay,
        ], 'job');
    }

    public function laterOn($queue, $delay, $job, $data = ''): void
    {
        $this->later($delay, $job, $data, $queue);
    }

    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    public function pop($queue = null): mixed
    {
        // A collecting queue is write-only from the app side; workers
        // read the outbox table directly.
        return null;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function setConnectionName($name): static
    {
        $this->connectionName = $name;

        return $this;
    }
}
