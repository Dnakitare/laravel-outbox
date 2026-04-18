<?php

namespace Dnakitare\Outbox\Tests\Integration;

use Dnakitare\Outbox\DatabaseOutboxRepository;
use Dnakitare\Outbox\Support\PayloadSerializer;
use Dnakitare\Outbox\Tests\Stubs\TestOrderCreated;
use Dnakitare\Outbox\Tests\TestCase;
use Illuminate\Support\Str;

/**
 * SQLite doesn't implement FOR UPDATE SKIP LOCKED, so we can't
 * reproduce true contention here — but we CAN prove the claim
 * atomicity contract: two back-to-back calls never yield overlapping
 * IDs, and each claimed row ends up with attempts=1 (not 2, which
 * would indicate both workers fighting over it).
 *
 * On MySQL/Postgres production, the SKIP LOCKED lock hint in the
 * repository guarantees two concurrent transactions see disjoint rows.
 */
class ConcurrencyTest extends TestCase
{
    public function test_two_workers_claim_disjoint_sets(): void
    {
        $serializer = $this->app->make(PayloadSerializer::class);
        $repo = new DatabaseOutboxRepository(
            $this->app['db']->connection(),
            $serializer,
            $this->app['config'],
        );

        // Seed 10 pending messages.
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $payload = $serializer->serialize(['event' => new TestOrderCreated("o{$i}"), 'payload' => []]);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'transaction_id' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'sequence_number' => $i,
                'type' => 'event',
                'aggregate_type' => 'Order',
                'aggregate_id' => (string) $i,
                'message_type' => TestOrderCreated::class,
                'payload' => $payload,
                'payload_hash' => substr($serializer->hash($payload), 0, 64),
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $repo->store($rows);

        $workerA = $repo->claimPendingMessages(5);
        $workerB = $repo->claimPendingMessages(5);

        $this->assertCount(5, $workerA);
        $this->assertCount(5, $workerB);

        $idsA = array_map(fn ($m) => $m->getId(), $workerA);
        $idsB = array_map(fn ($m) => $m->getId(), $workerB);

        $this->assertEmpty(array_intersect($idsA, $idsB), 'Workers must not claim overlapping IDs');

        // Every row claimed exactly once → attempts=1 uniformly.
        $attempts = $this->app['db']->table('outbox_messages')
            ->whereIn('id', array_merge($idsA, $idsB))
            ->pluck('attempts')
            ->all();

        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1, 1, 1], $attempts);
    }

    public function test_claim_is_idempotent_once_marked_processing(): void
    {
        $serializer = $this->app->make(PayloadSerializer::class);
        $repo = new DatabaseOutboxRepository(
            $this->app['db']->connection(),
            $serializer,
            $this->app['config'],
        );

        $payload = $serializer->serialize(['event' => new TestOrderCreated('x'), 'payload' => []]);
        $repo->store([[
            'id' => (string) Str::uuid(),
            'transaction_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'sequence_number' => 0,
            'type' => 'event',
            'aggregate_type' => 'Order',
            'aggregate_id' => '1',
            'message_type' => TestOrderCreated::class,
            'payload' => $payload,
            'payload_hash' => substr($serializer->hash($payload), 0, 64),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $this->assertCount(1, $repo->claimPendingMessages(10));
        $this->assertCount(0, $repo->claimPendingMessages(10));
    }
}
