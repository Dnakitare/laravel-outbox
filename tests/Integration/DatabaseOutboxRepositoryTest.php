<?php

namespace Laravel\Outbox\Tests\Integration;

use Illuminate\Support\Str;
use Laravel\Outbox\DatabaseOutboxRepository;
use Laravel\Outbox\Tests\Stubs\TestOrderCreated;
use Laravel\Outbox\Tests\Stubs\TestOutboxMessage;
use Laravel\Outbox\Tests\TestCase;

class DatabaseOutboxRepositoryTest extends TestCase
{
    private ?DatabaseOutboxRepository $repository = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DatabaseOutboxRepository($this->app['db']->connection());

        // Run migrations
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_it_stores_messages(): void
    {
        $messages = [
            [
                'id' => (string) Str::uuid(),
                'transaction_id' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'sequence_number' => 0,
                'type' => 'event',
                'aggregate_type' => 'Order',
                'aggregate_id' => '123',
                'message_type' => TestOrderCreated::class,
                'payload' => serialize(new TestOrderCreated('test')),
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $this->repository->store($messages);

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $messages[0]['id'],
            'aggregate_type' => 'Order',
            'aggregate_id' => '123',
        ]);
    }

    public function test_it_moves_message_to_dead_letter(): void
    {
        // Create a test message in the database
        $messageId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();

        $data = [
            'id' => $messageId,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'sequence_number' => 0,
            'type' => 'event',
            'aggregate_type' => 'Order',
            'aggregate_id' => '123',
            'message_type' => TestOrderCreated::class,
            'payload' => serialize(new TestOrderCreated('test')),
            'status' => 'failed',
            'attempts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->app['db']->table('outbox_messages')->insert($data);

        // Create message object
        $message = (object) $data;

        $exception = new \Exception('Test error');

        $this->repository->moveToDeadLetter(new TestOutboxMessage($message), $exception);

        $this->assertDatabaseHas('outbox_dead_letter', [
            'original_message_id' => $messageId,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'aggregate_type' => 'Order',
            'aggregate_id' => '123',
        ]);
    }
}
