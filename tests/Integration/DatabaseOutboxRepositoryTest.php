<?php

namespace Laravel\Outbox\Tests\Integration;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Outbox\DatabaseOutboxRepository;
use Laravel\Outbox\Support\PayloadSerializer;
use Laravel\Outbox\Tests\Stubs\TestOrderCreated;
use Laravel\Outbox\Tests\TestCase;

class DatabaseOutboxRepositoryTest extends TestCase
{
    private DatabaseOutboxRepository $repository;

    private PayloadSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serializer = $this->app->make(PayloadSerializer::class);
        $this->repository = new DatabaseOutboxRepository(
            $this->app['db']->connection(),
            $this->serializer,
            $this->app['config'],
        );
    }

    public function test_it_stores_messages(): void
    {
        $payload = $this->serializer->serialize(['event' => new TestOrderCreated('t1'), 'payload' => []]);

        $row = [
            'id' => (string) Str::uuid(),
            'transaction_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'sequence_number' => 0,
            'type' => 'event',
            'aggregate_type' => 'Order',
            'aggregate_id' => '42',
            'message_type' => TestOrderCreated::class,
            'payload' => $payload,
            'payload_hash' => substr($this->serializer->hash($payload), 0, 64),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->repository->store([$row]);

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $row['id'],
            'aggregate_type' => 'Order',
            'aggregate_id' => '42',
        ]);
    }

    public function test_claim_pending_messages_flips_status_and_increments_attempts(): void
    {
        $this->seedPending('Order', '100', 3);

        $claimed = $this->repository->claimPendingMessages(10);

        $this->assertCount(3, $claimed);
        foreach ($claimed as $m) {
            $this->assertSame('processing', $m->getStatus());
            $this->assertSame(1, $m->getAttempts());
        }

        $this->assertSame(
            0,
            $this->app['db']->table('outbox_messages')->where('status', 'pending')->count()
        );
    }

    public function test_claim_respects_available_at(): void
    {
        $future = Carbon::now()->addMinutes(10);
        $this->seedPending('Order', '200', 1, $future);

        $this->assertCount(0, $this->repository->claimPendingMessages(10));
    }

    public function test_mark_as_failed_with_backoff_keeps_message_pending(): void
    {
        $this->seedPending('Order', '300', 1);
        [$claimed] = $this->repository->claimPendingMessages(10);

        $this->repository->markAsFailed(
            $claimed->getId(),
            new \RuntimeException('temporary'),
            Carbon::now()->addMinutes(5),
            exhausted: false,
        );

        $row = $this->app['db']->table('outbox_messages')->where('id', $claimed->getId())->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('temporary', $row->error);
        $this->assertNull($row->processing_started_at);
    }

    public function test_mark_as_failed_exhausted_marks_failed(): void
    {
        $this->seedPending('Order', '301', 1);
        [$claimed] = $this->repository->claimPendingMessages(10);

        $this->repository->markAsFailed(
            $claimed->getId(),
            new \RuntimeException('final'),
            Carbon::now(),
            exhausted: true,
        );

        $row = $this->app['db']->table('outbox_messages')->where('id', $claimed->getId())->first();
        $this->assertSame('failed', $row->status);
    }

    public function test_move_to_dead_letter_uses_real_aggregate_values(): void
    {
        $this->seedPending('Invoice', '999', 1);
        [$claimed] = $this->repository->claimPendingMessages(10);

        $this->repository->moveToDeadLetter($claimed, new \RuntimeException('bad'));

        $this->assertDatabaseHas('outbox_dead_letter', [
            'original_message_id' => $claimed->getId(),
            'aggregate_type' => 'Invoice',
            'aggregate_id' => '999',
        ]);
    }

    public function test_reset_failed_purge_history_uses_batch_update(): void
    {
        $this->seedPending('Order', '401', 3);
        foreach ($this->repository->claimPendingMessages(10) as $m) {
            $this->repository->markAsFailed(
                $m->getId(),
                new \RuntimeException('boom'),
                now(),
                exhausted: true,
            );
        }

        $reset = $this->repository->resetFailed(ids: null, preserveHistory: false);
        $this->assertSame(3, $reset);

        $rows = $this->app['db']->table('outbox_messages')->get();
        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
            $this->assertSame(0, (int) $row->attempts);
            $this->assertNull($row->error);
            $this->assertNull($row->history);
        }
    }

    public function test_reset_failed_preserves_history(): void
    {
        $this->seedPending('Order', '400', 1);
        [$claimed] = $this->repository->claimPendingMessages(10);
        $this->repository->markAsFailed(
            $claimed->getId(),
            new \RuntimeException('first fail'),
            now(),
            exhausted: true,
        );

        $reset = $this->repository->resetFailed([$claimed->getId()]);
        $this->assertSame(1, $reset);

        $row = $this->app['db']->table('outbox_messages')->where('id', $claimed->getId())->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->error);

        $history = json_decode($row->history, true);
        $this->assertIsArray($history);
        $this->assertCount(1, $history);
        $this->assertSame('first fail', $history[0]['error']);
    }

    protected function seedPending(string $aggregateType, string $aggregateId, int $count, ?Carbon $availableAt = null): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $payload = $this->serializer->serialize(['event' => new TestOrderCreated("o{$i}"), 'payload' => []]);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'transaction_id' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'sequence_number' => $i,
                'type' => 'event',
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'message_type' => TestOrderCreated::class,
                'payload' => $payload,
                'payload_hash' => substr($this->serializer->hash($payload), 0, 64),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => $availableAt ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->repository->store($rows);
    }
}
