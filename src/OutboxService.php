<?php

namespace Laravel\Outbox;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Events\MessagesStored;
use Laravel\Outbox\Exceptions\TransactionException;
use Laravel\Outbox\Support\CollectingEventDispatcher;
use Laravel\Outbox\Support\CollectingQueueManager;
use Laravel\Outbox\Support\PayloadSerializer;

class OutboxService
{
    /** @var array<int, array{message: mixed, type: string}> */
    protected array $pendingMessages = [];

    protected bool $isCollecting = false;

    protected ?string $currentTransactionId = null;

    protected ?string $correlationId = null;

    protected ?Dispatcher $originalDispatcher = null;

    protected ?QueueManager $originalQueue = null;

    protected string $messagesTable;

    protected string $deadLetterTable;

    public function __construct(
        protected Container $container,
        protected OutboxRepository $repository,
        protected PayloadSerializer $serializer,
        protected MetricsCollector $metrics,
        protected Config $config,
    ) {
        $this->messagesTable = $config->get('outbox.table.messages', 'outbox_messages');
        $this->deadLetterTable = $config->get('outbox.table.dead_letter', 'outbox_dead_letter');
    }

    /**
     * Run $callback inside a transactional outbox scope. Any events
     * dispatched or jobs queued during the callback are captured and
     * persisted atomically with $callback's own DB writes.
     */
    public function transaction(string $aggregateType, string $aggregateId, callable $callback): mixed
    {
        if ($this->isCollecting) {
            throw new TransactionException('Nested outbox transactions are not supported.');
        }

        $this->startCollecting();

        $timer = $this->metrics->startTimer();
        $storedCount = 0;
        $result = null;
        $transactionId = null;
        $correlationId = null;

        try {
            $this->currentTransactionId = $transactionId = (string) Str::uuid();
            $this->correlationId = $correlationId = (string) Str::uuid();

            $result = $this->repository->transaction(function () use ($callback, $aggregateType, $aggregateId, &$storedCount) {
                $result = $callback();

                if (! empty($this->pendingMessages)) {
                    $storedCount = $this->storePendingMessages($aggregateType, $aggregateId);
                }

                return $result;
            });

            $this->metrics->recordTransactionDuration($timer);
        } finally {
            // Restore original event/queue bindings BEFORE any
            // afterCommit side-effects so emitted events / dispatched
            // jobs flow through the real dispatchers.
            $this->stopCollecting();
            $this->currentTransactionId = null;
            $this->correlationId = null;
            $this->pendingMessages = [];
        }

        if ($storedCount > 0) {
            $this->afterCommit($storedCount, $transactionId, $correlationId);
        }

        return $result;
    }

    /**
     * Called by CollectingEventDispatcher / CollectingQueue.
     */
    public function collect(mixed $message, string $type): void
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

        $this->originalDispatcher = $this->container->make('events');
        $this->originalQueue = $this->container->make('queue');

        $this->container->instance(
            'events',
            new CollectingEventDispatcher($this, $this->originalDispatcher)
        );

        $this->container->instance(
            'queue',
            new CollectingQueueManager($this, $this->originalQueue)
        );
    }

    protected function stopCollecting(): void
    {
        $this->isCollecting = false;

        if ($this->originalDispatcher !== null) {
            $this->container->instance('events', $this->originalDispatcher);
        }

        if ($this->originalQueue !== null) {
            $this->container->instance('queue', $this->originalQueue);
        }

        $this->originalDispatcher = null;
        $this->originalQueue = null;
    }

    protected function storePendingMessages(string $aggregateType, string $aggregateId): int
    {
        $now = Carbon::now();
        $rows = [];

        foreach ($this->pendingMessages as $index => $pending) {
            $messageObject = $this->extractMessage($pending);
            // Serialize just the captured payload (what the collecting
            // dispatcher passed to collect()), not the outer {message,
            // type} envelope. The outer envelope is metadata the
            // database row already stores explicitly.
            $payload = $this->serializer->serialize($pending['message']);
            $hash = $this->serializer->hash($payload);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'transaction_id' => $this->currentTransactionId,
                'correlation_id' => $this->correlationId,
                'sequence_number' => $index,
                'type' => $pending['type'],
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'message_type' => is_object($messageObject) ? get_class($messageObject) : gettype($messageObject),
                'payload' => $payload,
                'payload_hash' => substr($hash, 0, 64),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        $this->repository->store($rows);
        $this->metrics->incrementStoredMessages(count($rows));

        return count($rows);
    }

    protected function extractMessage(array $pending): mixed
    {
        $message = $pending['message'];

        if (! is_array($message)) {
            return $message;
        }

        if (isset($message['event'])) {
            return $message['event'];
        }

        if (isset($message['job'])) {
            return $message['job'];
        }

        return $message;
    }

    /**
     * Hook point after the outbox transaction commits successfully.
     * Dispatches the internal MessagesStored event so metrics sinks and
     * other listeners can react, and optionally kicks the processor job
     * if process_immediately is enabled.
     */
    protected function afterCommit(int $storedCount, string $transactionId, string $correlationId): void
    {
        $this->container->make('events')->dispatch(new MessagesStored(
            transactionId: $transactionId,
            correlationId: $correlationId,
            count: $storedCount,
        ));

        if (! $this->config->get('outbox.processing.process_immediately', false)) {
            return;
        }

        try {
            $job = new \Laravel\Outbox\Jobs\ProcessOutboxMessages(
                batchSize: (int) $this->config->get('outbox.processing.batch_size', 100)
            );

            $dispatch = dispatch($job);
            if ($connection = $this->config->get('outbox.queue.connection')) {
                $dispatch->onConnection($connection);
            }
            if ($queue = $this->config->get('outbox.queue.name')) {
                $dispatch->onQueue($queue);
            }
        } catch (\Throwable $e) {
            logger()->warning('Failed to schedule immediate outbox processing', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function health(): array
    {
        $lockTimeoutSeconds = (int) $this->config->get('outbox.processing.lock_timeout', 300);
        $stuckThreshold = Carbon::now()->subSeconds($lockTimeoutSeconds);

        $statusCounts = DB::table($this->messagesTable)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $oldestPending = DB::table($this->messagesTable)
            ->where('status', 'pending')
            ->min('created_at');

        $stuckProcessing = DB::table($this->messagesTable)
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $stuckThreshold)
            ->count();

        $deadLetter = [
            'count' => DB::table($this->deadLetterTable)->count(),
            'oldest' => DB::table($this->deadLetterTable)->min('created_at'),
        ];

        return [
            'status' => $this->determineHealthStatus($statusCounts, $oldestPending, $stuckProcessing),
            'messages' => $statusCounts,
            'oldest_pending' => $oldestPending,
            'stuck_processing' => $stuckProcessing,
            'lock_timeout_seconds' => $lockTimeoutSeconds,
            'dead_letter' => $deadLetter,
        ];
    }

    public function getStats(): array
    {
        $since24h = Carbon::now()->subDay();
        $since1h = Carbon::now()->subHour();

        return [
            'messages' => [
                'total' => DB::table($this->messagesTable)->count(),
                'by_status' => DB::table($this->messagesTable)
                    ->selectRaw('status, count(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->all(),
                'by_type' => DB::table($this->messagesTable)
                    ->selectRaw('type, count(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type')
                    ->all(),
            ],
            'processing' => [
                'last_hour' => DB::table($this->messagesTable)
                    ->where('processed_at', '>=', $since1h)
                    ->count(),
                'last_24h' => DB::table($this->messagesTable)
                    ->where('processed_at', '>=', $since24h)
                    ->count(),
            ],
            'failures' => [
                'total' => DB::table($this->messagesTable)
                    ->where('status', 'failed')
                    ->count(),
                'dead_letter' => DB::table($this->deadLetterTable)->count(),
            ],
        ];
    }

    private function determineHealthStatus(array $statusCounts, ?string $oldestPending, int $stuckProcessing): string
    {
        if ($stuckProcessing > 0) {
            return 'critical';
        }

        $threshold = (int) $this->config->get('outbox.health.pending_age_warning_seconds', 3600);
        if ($oldestPending && Carbon::now()->subSeconds($threshold)->gt($oldestPending)) {
            return 'warning';
        }

        $maxPending = (int) $this->config->get('outbox.health.pending_count_warning', 1000);
        if (($statusCounts['pending'] ?? 0) > $maxPending) {
            return 'warning';
        }

        return 'healthy';
    }
}
