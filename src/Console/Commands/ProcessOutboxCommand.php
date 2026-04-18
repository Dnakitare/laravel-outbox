<?php

namespace Laravel\Outbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Outbox\Contracts\MetricsCollector;
use Laravel\Outbox\Contracts\OutboxRepository;
use Laravel\Outbox\Jobs\ProcessOutboxMessages;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process
                          {--batch=100 : Number of messages to process per iteration}
                          {--sleep=1 : Seconds to sleep when no messages are pending}
                          {--max=0 : Stop after processing this many messages (0 = unlimited)}
                          {--once : Run one batch and exit}';

    protected $description = 'Process pending outbox messages';

    protected bool $shouldStop = false;

    public function handle(
        OutboxRepository $repository,
        MetricsCollector $metrics,
        Dispatcher $events,
        BusDispatcher $bus,
        Config $config,
    ): int {
        $this->installSignalHandlers();

        $batch = max(1, (int) $this->option('batch'));
        $sleep = max(0, (int) $this->option('sleep'));
        $max = max(0, (int) $this->option('max'));
        $once = (bool) $this->option('once');

        $total = 0;

        while (! $this->shouldStop) {
            $job = new ProcessOutboxMessages($batch);
            $processed = $job->handle($repository, $metrics, $events, $bus, $config);

            if ($processed > 0) {
                $total += $processed;
                $this->info("Processed {$processed} (total: {$total})");
            }

            if ($max > 0 && $total >= $max) {
                $this->info('Reached --max limit.');
                break;
            }

            if ($once) {
                break;
            }

            if ($processed === 0) {
                if ($sleep > 0 && ! $this->shouldStop) {
                    sleep($sleep);
                }
            }
        }

        $this->info("Finished. Processed {$total} message(s).");

        return 0;
    }

    protected function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
    }
}
