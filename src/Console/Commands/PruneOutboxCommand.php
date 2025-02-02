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
                          {--batch=1000 : Messages to delete per batch}
                          {--force : Skip confirmation}';

    protected $description = 'Prune old outbox messages';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Are you sure you want to prune old messages?')) {
            return 1;
        }

        $completedDays = (int) $this->option('completed-days');
        $failedDays = (int) $this->option('failed-days');
        $deadLetterDays = (int) $this->option('dead-letter-days');
        $batchSize = (int) $this->option('batch');

        // Prune completed messages
        $completedCount = $this->pruneMessages('completed', $completedDays, $batchSize);
        $this->info("Pruned {$completedCount} completed messages");

        // Prune failed messages
        $failedCount = $this->pruneMessages('failed', $failedDays, $batchSize);
        $this->info("Pruned {$failedCount} failed messages");

        // Prune dead letter messages
        if (config('outbox.dead_letter.enabled', true)) {
            $deadLetterCount = $this->pruneDeadLetterMessages($deadLetterDays, $batchSize);
            $this->info("Pruned {$deadLetterCount} dead letter messages");
        }

        return 0;
    }

    private function pruneMessages(string $status, int $days, int $batchSize): int
    {
        $table = config('outbox.table.messages', 'outbox_messages');
        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        $this->output->write("Pruning {$status} messages older than {$days} days... ");

        do {
            $count = DB::table($table)
                ->where('status', $status)
                ->where('created_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $total += $count;

            if ($count > 0) {
                $this->output->write('.');
            }
        } while ($count > 0);

        $this->output->writeln('');

        return $total;
    }

    private function pruneDeadLetterMessages(int $days, int $batchSize): int
    {
        $table = config('outbox.table.dead_letter', 'outbox_dead_letter');
        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        $this->output->write("Pruning dead letter messages older than {$days} days... ");

        do {
            $count = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $total += $count;

            if ($count > 0) {
                $this->output->write('.');
            }
        } while ($count > 0);

        $this->output->writeln('');

        return $total;
    }
}
