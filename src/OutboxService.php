<?php

namespace Laravel\Outbox;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Exceptions\TransactionException;
use Laravel\Outbox\Support\CollectingEventDispatcher;
use Laravel\Outbox\Support\CollectingQueueManager;

class OutboxService
{
    protected array $pendingMessages = [];

    protected bool $isCollecting = false;

    protected ?string $currentTransactionId = null;

    protected ?string $correlationId = null;

    protected $originalDispatcher;

    protected $originalQueue;

    public function __construct(
        protected OutboxRepository $repository,
        protected Dispatcher $events,
        protected QueueManager $queue,
        protected MetricsCollector $metrics
    ) {
        $this->originalDispatcher = $events;
        $this->originalQueue = $queue;
    }

    public function transaction(string $aggregateType, string $aggregateId, callable $callback)
    {
        if ($this->isCollecting) {
            throw new TransactionException('Nested outbox transactions are not supported.');
        }

        $this->startCollecting();

        try {
            $this->currentTransactionId = (string) Str::uuid();
            $this->correlationId = (string) Str::uuid();

            $timer = $this->metrics->startTimer();

            $result = $this->repository->transaction(function () use ($callback, $aggregateType, $aggregateId) {
                $result = $callback();

                if (! empty($this->pendingMessages)) {
                    $this->storePendingMessages($aggregateType, $aggregateId);
                }

                return $result;
            });

            $this->metrics->recordTransactionDuration($timer);

            return $result;
        } finally {
            $this->stopCollecting();
            $this->currentTransactionId = null;
            $this->correlationId = null;
        }
    }

    public function collect($message, string $type): void
    {
        if (! $this->isCollecting) {
            throw new TransactionException('Cannot collect messages outside of an outbox transaction.');
        }

        $this->pendingMessages[] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    protected function startCollecting(): void
    {
        $this->isCollecting = true;
        $this->pendingMessages = [];

        // Replace event dispatcher with collecting dispatcher
        $collectingDispatcher = new CollectingEventDispatcher($this, $this->events);
        $this->events = $collectingDispatcher;
        app()->instance('events', $collectingDispatcher);

        // Replace queue with collecting queue
        $collectingQueue = new CollectingQueueManager($this);
        $this->queue = $collectingQueue;
        app()->instance('queue', $collectingQueue);
    }

    protected function stopCollecting(): void
    {
        $this->isCollecting = false;

        // Restore original dispatchers
        $this->events = $this->originalDispatcher;
        app()->instance('events', $this->originalDispatcher);

        $this->queue = $this->originalQueue;
        app()->instance('queue', $this->originalQueue);
    }

    /**
     * Get health status of the outbox system
     */
    public function health(): array
    {
        $messages = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $oldestPending = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'pending')
            ->min('created_at');

        $processingStuck = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'processing')
            ->where('processing_started_at', '<', now()->subHours(1))
            ->count();

        return [
            'status' => $this->determineHealthStatus($messages, $oldestPending, $processingStuck),
            'messages' => $messages,
            'oldest_pending' => $oldestPending,
            'stuck_processing' => $processingStuck,
            'dead_letter' => [
                'count' => DB::table(config('outbox.table.dead_letter', 'outbox_dead_letter'))->count(),
                'oldest' => DB::table(config('outbox.table.dead_letter', 'outbox_dead_letter'))->min('created_at'),
            ],
        ];
    }

    public function getStats(): array
    {
        return [
            'messages' => [
                'total' => DB::table(config('outbox.table.messages', 'outbox_messages'))->count(),
                'by_status' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->all(),
                'by_type' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->selectRaw('type, count(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->all(),
            ],
            'processing' => [
                'last_hour' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->where('processed_at', '>=', now()->subHour())
                    ->count(),
                'last_24h' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->where('processed_at', '>=', now()->subDay())
                    ->count(),
            ],
            'failures' => [
                'total' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->where('status', 'failed')
                    ->count(),
                'dead_letter' => DB::table(config('outbox.table.dead_letter', 'outbox_dead_letter'))
                    ->count(),
            ],
            'performance' => [
                'avg_processing_time' => DB::table(config('outbox.table.messages', 'outbox_messages'))
                    ->whereNotNull('processed_at')
                    ->whereNotNull('processing_started_at')
                    ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, processing_started_at, processed_at)) as avg_time')
                    ->value('avg_time'),
            ],
        ];
    }

    private function determineHealthStatus(array $messages, ?string $oldestPending, int $processingStuck): string
    {
        // Critical if there are stuck processing messages
        if ($processingStuck > 0) {
            return 'critical';
        }

        // Warning if there are pending messages older than 1 hour
        if ($oldestPending && now()->subHour()->gt($oldestPending)) {
            return 'warning';
        }

        // Warning if there are more than 1000 pending messages
        if (($messages['pending'] ?? 0) > 1000) {
            return 'warning';
        }

        return 'healthy';
    }

    protected function storePendingMessages(string $aggregateType, string $aggregateId): void
    {
        $messages = [];
        foreach ($this->pendingMessages as $index => $pending) {
            $messages[] = [
                'id' => (string) Str::uuid(),
                'transaction_id' => $this->currentTransactionId,
                'correlation_id' => $this->correlationId,
                'sequence_number' => $index,
                'type' => $pending['type'],
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'message_type' => get_class($pending['message']),
                'payload' => serialize($pending['message']),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($messages)) {
            $this->repository->store($messages);
            $this->metrics->incrementStoredMessages(count($messages));
        }
    }
}
