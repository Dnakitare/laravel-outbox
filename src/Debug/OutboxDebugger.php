<?php

namespace Laravel\Outbox\Debug;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class OutboxDebugger
{
    /**
     * Get detailed information about a specific message
     */
    public function inspectMessage(string $messageId): ?array
    {
        $message = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('id', $messageId)
            ->first();

        if (!$message) {
            return null;
        }

        return [
            'message' => $message,
            'payload' => $this->tryUnserialize($message->payload),
            'history' => json_decode($message->processing_history ?? '[]', true),
        ];
    }

    /**
     * Find problematic messages
     */
    public function findProblematicMessages(): Collection
    {
        return collect([
            'stuck_processing' => $this->findStuckProcessing(),
            'repeated_failures' => $this->findRepeatedFailures(),
            'out_of_order' => $this->findOutOfOrderProcessing(),
        ]);
    }

    /**
     * Get processing timeline for analysis
     */
    public function getProcessingTimeline(int $minutes = 60): Collection
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->orderBy('created_at')
            ->get()
            ->map(function ($message) {
                return [
                    'id' => $message->id,
                    'type' => $message->type,
                    'status' => $message->status,
                    'timeline' => [
                        'created' => $message->created_at,
                        'processing_started' => $message->processing_started_at,
                        'processed' => $message->processed_at,
                    ],
                    'duration' => $message->processed_at 
                        ? strtotime($message->processed_at) - strtotime($message->processing_started_at)
                        : null,
                ];
            });
    }

    /**
     * Analyze message patterns
     */
    public function analyzePatterns(): array
    {
        return [
            'processing_times' => $this->analyzeProcessingTimes(),
            'error_patterns' => $this->analyzeErrorPatterns(),
            'volume_patterns' => $this->analyzeVolumePatterns(),
        ];
    }

    private function findStuckProcessing(): Collection
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'processing')
            ->where('processing_started_at', '<', now()->subHours(1))
            ->get();
    }

    private function findRepeatedFailures(): Collection
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'failed')
            ->where('attempts', '>=', 2)
            ->orderBy('attempts', 'desc')
            ->get();
    }

    private function findOutOfOrderProcessing(): Collection
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->whereNotNull('processed_at')
            ->whereRaw('processed_at < processing_started_at')
            ->get();
    }

    private function analyzeProcessingTimes(): array
    {
        $times = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->whereNotNull('processed_at')
            ->whereNotNull('processing_started_at')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(SECOND, processing_started_at, processed_at)) as avg_time,
                MAX(TIMESTAMPDIFF(SECOND, processing_started_at, processed_at)) as max_time,
                MIN(TIMESTAMPDIFF(SECOND, processing_started_at, processed_at)) as min_time
            ')
            ->first();

        return [
            'average' => round($times->avg_time, 2),
            'maximum' => $times->max_time,
            'minimum' => $times->min_time,
        ];
    }

    private function analyzeErrorPatterns(): array
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'failed')
            ->selectRaw('error, COUNT(*) as count')
            ->groupBy('error')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    private function analyzeVolumePatterns(): array
    {
        return DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->selectRaw('
                DATE_FORMAT(created_at, "%Y-%m-%d %H:00:00") as hour,
                COUNT(*) as total,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->toArray();
    }

    private function tryUnserialize($data)
    {
        try {
            return unserialize($data);
        } catch (\Throwable $e) {
            return null;
        }
    }
}