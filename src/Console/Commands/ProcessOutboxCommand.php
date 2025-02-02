<?php

namespace Laravel\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Outbox\Jobs\ProcessOutboxMessages;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process
                          {--batch=100 : Number of messages to process in each batch}
                          {--sleep=1 : Seconds to sleep between batches}
                          {--max=0 : Maximum number of messages to process (0 for unlimited)}
                          {--loop : Keep processing in a loop}';

    protected $description = 'Process pending outbox messages';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');
        $maxMessages = (int) $this->option('max');
        $loop = (bool) $this->option('loop');

        $totalProcessed = 0;

        do {
            $job = new ProcessOutboxMessages($batchSize);
            $processed = $job->handle(
                app('Laravel\Outbox\Contracts\OutboxRepository'),
                app('Laravel\Outbox\Contracts\MetricsCollector')
            );

            if ($processed > 0) {
                $totalProcessed += $processed;
                $this->info("Processed {$processed} messages (Total: {$totalProcessed})");
            }

            // Check if we've hit the max messages limit
            if ($maxMessages > 0 && $totalProcessed >= $maxMessages) {
                $this->info('Reached maximum message limit');
                break;
            }

            // If there were no messages processed and we're not looping, exit
            if ($processed === 0 && ! $loop) {
                break;
            }

            // Sleep between batches if specified
            if ($sleep > 0 && ($loop || $processed === $batchSize)) {
                sleep($sleep);
            }

        } while ($loop);

        $this->info("Finished processing {$totalProcessed} messages");

        return 0;
    }
}
