<?php

namespace Laravel\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InspectDeadLetterCommand extends Command
{
    protected $signature = 'outbox:inspect-dead-letter
                          {--id= : Inspect a specific message by ID}
                          {--type= : Filter by message type}
                          {--aggregate= : Filter by aggregate type}
                          {--limit=50 : Maximum number of messages to display}
                          {--days=7 : Only show messages from the last N days}';

    protected $description = 'Inspect messages in the dead letter queue';

    public function handle(): int
    {
        if (! config('outbox.dead_letter.enabled', true)) {
            $this->error('Dead letter queue is not enabled');

            return 1;
        }

        $query = DB::table(config('outbox.table.dead_letter', 'outbox_dead_letter'))
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($id = $this->option('id')) {
            $query->where('id', $id)
                ->orWhere('original_message_id', $id);
        }

        if ($type = $this->option('type')) {
            $query->where('message_type', 'like', "%{$type}%");
        }

        if ($aggregate = $this->option('aggregate')) {
            $query->where('aggregate_type', 'like', "%{$aggregate}%");
        }

        if ($days = $this->option('days')) {
            $query->where('created_at', '>=', now()->subDays((int) $days));
        }

        $messages = $query->limit((int) $this->option('limit'))->get();

        if ($messages->isEmpty()) {
            $this->info('No dead letter messages found');

            return 0;
        }

        // Show summary or detailed view
        if ($this->option('id')) {
            $this->displayDetailedView($messages->first());
        } else {
            $this->displaySummaryView($messages);
        }

        return 0;
    }

    private function displaySummaryView($messages): void
    {
        $headers = ['ID', 'Type', 'Aggregate', 'Error', 'Failed At'];
        $rows = [];

        foreach ($messages as $message) {
            $rows[] = [
                Str::limit($message->id, 8),
                class_basename($message->message_type),
                "{$message->aggregate_type}:{$message->aggregate_id}",
                Str::limit($message->error, 50),
                $message->failed_at,
            ];
        }

        $this->table($headers, $rows);
        $this->info("\nUse --id=<message_id> to see full message details");
    }

    private function displayDetailedView($message): void
    {
        $this->info('Message Details:');
        $this->line('------------------------');
        $this->line("ID: {$message->id}");
        $this->line("Original Message ID: {$message->original_message_id}");
        $this->line("Message Type: {$message->message_type}");
        $this->line("Aggregate: {$message->aggregate_type}:{$message->aggregate_id}");
        $this->line("Failed At: {$message->failed_at}");

        $this->newLine();
        $this->info('Error:');
        $this->error($message->error);

        $this->newLine();
        $this->info('Stack Trace:');
        $this->line($message->stack_trace);

        $this->newLine();
        $this->info('Metadata:');
        $metadata = json_decode($message->metadata, true);
        foreach ($metadata as $key => $value) {
            $this->line("- {$key}: ".json_encode($value));
        }

        $this->newLine();
        $this->info('Payload:');
        $payload = unserialize($message->payload);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
    }
}
