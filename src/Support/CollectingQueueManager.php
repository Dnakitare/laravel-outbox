<?php

namespace Laravel\Outbox\Support;

use Illuminate\Queue\QueueManager;
use Laravel\Outbox\OutboxService;

class CollectingQueueManager extends QueueManager
{
    public function __construct(
        private OutboxService $outboxService,
    ) {
        parent::__construct(app());
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->outboxService->collect($job, 'job');
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $this->outboxService->collect($job, 'job');
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ($jobs as $job) {
            $this->outboxService->collect($job, 'job');
        }
    }
}
