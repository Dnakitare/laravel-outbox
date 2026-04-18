<?php

namespace Laravel\Outbox\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOutboxCommand extends Command
{
    protected $signature = 'outbox:prune
                          {--completed-days=7 : Days to keep completed messages}
                          {--failed-days=30 : Days to keep failed messages}
                          {--dead-letter-days=90 : Days to keep dead letter messages}
                          {--batch=1000 : Delete chunk size}
                          {--force : Skip confirmation}';

    protected $description = 'Prune old outbox messages';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Prune old outbox messages?')) {
            return 1;
        }

        $batch = max(1, (int) $this->option('batch'));

        $completed = $this->pruneByStatus('completed', (int) $this->option('completed-days'), $batch);
        $this->info("Pruned {$completed} completed messages.");

        $failed = $this->pruneByStatus('failed', (int) $this->option('failed-days'), $batch);
        $this->info("Pruned {$failed} failed messages.");

        if (config('outbox.dead_letter.enabled', true)) {
            $dead = $this->pruneDeadLetter((int) $this->option('dead-letter-days'), $batch);
            $this->info("Pruned {$dead} dead letter messages.");
        }

        return 0;
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
