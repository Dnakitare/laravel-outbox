<?php

namespace Dnakitare\Outbox\Debug;

use Dnakitare\Outbox\Exceptions\SerializationException;
use Dnakitare\Outbox\Support\PayloadSerializer;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OutboxDebugger
{
    public function __construct(
        protected PayloadSerializer $serializer,
        protected Config $config,
    ) {}

    public function inspectMessage(string $messageId): ?array
    {
        $message = DB::table($this->messagesTable())
            ->where('id', $messageId)
            ->first();

        if (! $message) {
            return null;
        }

        return [
            'message' => $message,
            'payload' => $this->tryDecode($message->payload),
            'history' => json_decode($message->history ?? '[]', true) ?: [],
        ];
    }

    public function findProblematicMessages(): Collection
    {
        return collect([
            'stuck_processing' => $this->findStuckProcessing(),
            'repeated_failures' => $this->findRepeatedFailures(),
        ]);
    }

    public function getProcessingTimeline(int $minutes = 60): Collection
    {
        return DB::table($this->messagesTable())
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'status' => $m->status,
                'created' => $m->created_at,
                'started' => $m->processing_started_at,
                'completed' => $m->processed_at,
                'duration_seconds' => ($m->processed_at && $m->processing_started_at)
                    ? strtotime($m->processed_at) - strtotime($m->processing_started_at)
                    : null,
            ]);
    }

    protected function findStuckProcessing(): Collection
    {
        $threshold = now()->subSeconds(
            (int) $this->config->get('outbox.processing.lock_timeout', 300)
        );

        return DB::table($this->messagesTable())
            ->where('status', 'processing')
            ->where('processing_started_at', '<', $threshold)
            ->get();
    }

    protected function findRepeatedFailures(): Collection
    {
        return DB::table($this->messagesTable())
            ->where('status', 'failed')
            ->orderBy('attempts', 'desc')
            ->limit(100)
            ->get();
    }

    protected function tryDecode(string $data): mixed
    {
        try {
            return $this->serializer->unserialize($data);
        } catch (SerializationException) {
            return null;
        }
    }

    protected function messagesTable(): string
    {
        return $this->config->get('outbox.table.messages', 'outbox_messages');
    }
}
