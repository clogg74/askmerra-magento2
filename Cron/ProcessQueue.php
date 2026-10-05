<?php

declare(strict_types=1);

namespace AskMerra\Connector\Cron;

use AskMerra\Connector\Model\Feed\FeedGenerator;
use AskMerra\Connector\Model\Log\Logger;
use AskMerra\Connector\Model\Sync\QueueProcessor;

/**
 * Every minute: sends (or caches, for feeds) the products that changed, and takes offline the feed
 * file of a store view that no longer publishes one (AskMerra turned off for it, or switched to the
 * Push API) - not only at the next feed run.
 */
class ProcessQueue
{
    public function __construct(
        private readonly QueueProcessor $queueProcessor,
        private readonly FeedGenerator $feedGenerator,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        $this->queueProcessor->run(QueueProcessor::DEFAULT_SECONDS);

        try {
            $this->feedGenerator->removeUnusedFiles();
        } catch (\Throwable $e) {
            $this->logger->error('Removing unused feed files: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
