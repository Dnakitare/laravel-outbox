<?php

namespace Laravel\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetryFailedCommand extends Command
{
    protected $signature = 'outbox:retry
                          {--id=* : IDs of specific messages to retry}
                          {--all : Retry all failed messages}
                          {--batch=100 : Messages to retry per batch}
                          {--force : Skip confirmation}';

    protected $description = 'Retry failed outbox messages';

    public function handle(): int
    {
        if (! $this->option('id') && ! $this->option('all')) {
            $this->error('Please specify message IDs to retry or use --all');

            return 1;
        }

        if (! $this->option('force') && ! $this->confirm('Are you sure you want to retry these messages?')) {
            return 1;
        }

        $query = DB::table(config('outbox.table.messages', 'outbox_messages'))
            ->where('status', 'failed');

        // If specific IDs were provided, only retry those
        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No failed messages found to retry');

            return 0;
        }

        $this->info("Retrying {$total} failed messages...");

        $batchSize = (int) $this->option('batch');
        $retried = 0;

        do {
            $count = $query->limit($batchSize)
                ->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'error' => null,
                    'processing_started_at' => null,
                    'updated_at' => now(),
                ]);

            $retried += $count;
            $this->output->write('.');
        } while ($count > 0);

        $this->output->writeln('');
        $this->info("Successfully reset {$retried} messages for retry");

        return 0;
    }
}
