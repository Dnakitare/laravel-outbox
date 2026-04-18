<?php

namespace Dnakitare\Outbox\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOutboxCommand extends Command
{
    protected $signature = 'outbox:prune
                          {--completed-days= : Days to keep completed messages (default: outbox.pruning.retention_days)}
                          {--failed-days= : Days to keep failed messages (default: outbox.pruning.retention_days * 4)}
                          {--dead-letter-days= : Days to keep dead letter messages (default: outbox.dead_letter.retention_days)}
                          {--batch= : Delete chunk size (default: outbox.pruning.chunk_size)}
                          {--force : Skip confirmation}';

    protected $description = 'Prune old outbox messages';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Prune old outbox messages?')) {
            return 1;
        }

        $defaultRetention = (int) config('outbox.pruning.retention_days', 7);

        $completedDays = $this->intOption('completed-days', $defaultRetention);
        $failedDays = $this->intOption('failed-days', $defaultRetention * 4);
        $deadLetterDays = $this->intOption(
            'dead-letter-days',
            (int) config('outbox.dead_letter.retention_days', 30)
        );
        $batch = max(1, $this->intOption('batch', (int) config('outbox.pruning.chunk_size', 1000)));

        $completed = $this->pruneByStatus('completed', $completedDays, $batch);
        $this->info("Pruned {$completed} completed messages.");

        $failed = $this->pruneByStatus('failed', $failedDays, $batch);
        $this->info("Pruned {$failed} failed messages.");

        if (config('outbox.dead_letter.enabled', true)) {
            $dead = $this->pruneDeadLetter($deadLetterDays, $batch);
            $this->info("Pruned {$dead} dead letter messages.");
        }

        return 0;
    }

    protected function intOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    protected function pruneByStatus(string $status, int $days, int $batch): int
    {
        $table = config('outbox.table.messages', 'outbox_messages');
        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        do {
            $count = DB::table($table)
                ->where('status', $status)
                ->where('created_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $total += $count;
        } while ($count > 0);

        return $total;
    }

    protected function pruneDeadLetter(int $days, int $batch): int
    {
        $table = config('outbox.table.dead_letter', 'outbox_dead_letter');
        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        do {
            $count = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $total += $count;
        } while ($count > 0);

        return $total;
    }
}
