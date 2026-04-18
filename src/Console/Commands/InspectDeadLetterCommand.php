<?php

namespace Laravel\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Outbox\Exceptions\SerializationException;
use Laravel\Outbox\Support\PayloadSerializer;

class InspectDeadLetterCommand extends Command
{
    protected $signature = 'outbox:inspect-dead-letter
                          {--id= : Inspect a specific message by id or original_message_id}
                          {--type= : Filter by message_type (substring match)}
                          {--aggregate= : Filter by aggregate_type (substring match)}
                          {--limit=50 : Maximum rows to display}
                          {--days=7 : Restrict to messages failed in the last N days}';

    protected $description = 'Inspect messages in the dead letter queue';

    public function handle(PayloadSerializer $serializer): int
    {
        if (! config('outbox.dead_letter.enabled', true)) {
            $this->error('Dead letter queue is not enabled.');

            return 1;
        }

        $query = DB::table(config('outbox.table.dead_letter', 'outbox_dead_letter'))
            ->orderBy('created_at', 'desc');

        if ($id = $this->option('id')) {
            $query->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('original_message_id', $id);
            });
        }

        if ($type = $this->option('type')) {
            $query->where('message_type', 'like', "%{$type}%");
        }

        if ($aggregate = $this->option('aggregate')) {
            $query->where('aggregate_type', 'like', "%{$aggregate}%");
        }

        if ($days = (int) $this->option('days')) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $messages = $query->limit((int) $this->option('limit'))->get();

        if ($messages->isEmpty()) {
            $this->info('No dead letter messages found.');

            return 0;
        }

        if ($this->option('id')) {
            $this->displayDetailed($messages->first(), $serializer);
        } else {
            $this->displaySummary($messages);
        }

        return 0;
    }

    protected function displaySummary($messages): void
    {
        $rows = [];
        foreach ($messages as $m) {
            $rows[] = [
                Str::limit($m->id, 8, ''),
                class_basename($m->message_type),
                "{$m->aggregate_type}:{$m->aggregate_id}",
                Str::limit($m->error, 60),
                $m->failed_at,
            ];
        }

        $this->table(['ID', 'Type', 'Aggregate', 'Error', 'Failed At'], $rows);
        $this->info('Use --id=<uuid> for full details.');
    }

    protected function displayDetailed($message, PayloadSerializer $serializer): void
    {
        $this->line("ID:              {$message->id}");
        $this->line("Original ID:     {$message->original_message_id}");
        $this->line("Transaction:     {$message->transaction_id}");
        $this->line("Correlation:     {$message->correlation_id}");
        $this->line("Type:            {$message->message_type}");
        $this->line("Aggregate:       {$message->aggregate_type}:{$message->aggregate_id}");
        $this->line("Failed at:       {$message->failed_at}");
        $this->newLine();

        $this->error('Error:');
        $this->line($message->error);
        $this->newLine();

        $this->warn('Stack trace:');
        $this->line($message->stack_trace);
        $this->newLine();

        if (! empty($message->metadata)) {
            $this->info('Metadata:');
            $this->line(json_encode(json_decode($message->metadata, true), JSON_PRETTY_PRINT));
            $this->newLine();
        }

        $this->info('Payload:');
        try {
            $payload = $serializer->unserialize($message->payload);
            $this->line(json_encode($this->describePayload($payload), JSON_PRETTY_PRINT));
        } catch (SerializationException $e) {
            $this->warn("Payload cannot be decoded: {$e->getMessage()}");
        }
    }

    protected function describePayload(mixed $payload): mixed
    {
        if (is_object($payload)) {
            return [
                'class' => get_class($payload),
                'properties' => method_exists($payload, 'toArray')
                    ? $payload->toArray()
                    : get_object_vars($payload),
            ];
        }

        return $payload;
    }
}
