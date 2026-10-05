<?php

declare(strict_types=1);

namespace AskMerra\Connector\Cron;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\Reconciler;
use AskMerra\Connector\Model\Sync\RunLog;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Daily maintenance: reconciles every store view that syncs (a few queries), or rebuilds them on the
 * days the "Full rebuild" setting asks for, and tidies the queue and the run history.
 */
class Daily
{
    public function __construct(
        private readonly Config $config,
        private readonly Reconciler $reconciler,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly TimezoneInterface $timezone,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        $rebuild = $this->isRebuildDay();
        $storeIds = $this->config->getSyncStoreIds();

        foreach ($storeIds as $storeId) {
            try {
                $rebuild ? $this->reconciler->rebuild($storeId) : $this->reconciler->reconcile($storeId);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Daily maintenance of store %d: %s', $storeId, $e->getMessage()), ['exception' => $e]);
            }
        }

        // Store views that stopped syncing keep nothing in the queue.
        $this->queue->removeStoresExcept($storeIds);
        $this->runLog->cleanup();
    }

    private function isRebuildDay(): bool
    {
        $today = $this->timezone->date();

        return match ($this->config->getRebuildFrequency()) {
            'daily' => true,
            'weekly' => $today->format('w') === '0',
            'monthly' => $today->format('j') === '1',
            default => false,
        };
    }
}
