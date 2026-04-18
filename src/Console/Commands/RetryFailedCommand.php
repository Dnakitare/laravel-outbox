<?php

namespace Dnakitare\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Dnakitare\Outbox\Contracts\OutboxRepository;

class RetryFailedCommand extends Command
{
    protected $signature = 'outbox:retry
                          {--id=* : IDs of specific messages to retry}
                          {--all : Retry all failed messages}
                          {--purge-history : Discard the message history instead of preserving it}
                          {--force : Skip confirmation}';

    protected $description = 'Reset failed outbox messages back to pending for re-processing';

    public function handle(OutboxRepository $repository): int
    {
        $ids = $this->option('id');
        $all = $this->option('all');

        if (empty($ids) && ! $all) {
            $this->error('Specify --id=<uuid> (repeatable) or --all.');

            return 1;
        }

        $table = config('outbox.table.messages', 'outbox_messages');
        $query = DB::table($table)->where('status', 'failed');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No failed messages found to retry.');

            return 0;
        }

        if (! $this->option('force') && ! $this->confirm("Retry {$count} failed message(s)?")) {
            $this->warn('Aborted.');

            return 1;
        }

        $reset = $repository->resetFailed(
            ids: ! empty($ids) ? $ids : null,
            preserveHistory: ! $this->option('purge-history'),
        );

        $this->info("Reset {$reset} message(s) for retry.");

        return 0;
    }
}
