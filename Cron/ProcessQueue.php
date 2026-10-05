<?php

declare(strict_types=1);

namespace AskMerra\Connector\Cron;

use AskMerra\Connector\Model\Sync\QueueProcessor;

/** Every minute: sends (or caches, for feeds) the products that changed. */
class ProcessQueue
{
    public function __construct(private readonly QueueProcessor $queueProcessor)
    {
    }

    public function execute(): void
    {
        $this->queueProcessor->run(QueueProcessor::DEFAULT_SECONDS);
    }
}
