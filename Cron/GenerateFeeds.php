<?php

declare(strict_types=1);

namespace AskMerra\Connector\Cron;

use AskMerra\Connector\Model\Feed\FeedGenerator;

/**
 * Rewrites the feed files whose products changed since they were last written, and removes the
 * files of store views that no longer publish a feed.
 */
class GenerateFeeds
{
    public function __construct(private readonly FeedGenerator $feedGenerator)
    {
    }

    public function execute(): void
    {
        $this->feedGenerator->generateAll();
        $this->feedGenerator->removeUnusedFiles();
    }
}
