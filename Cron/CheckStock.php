<?php

declare(strict_types=1);

namespace AskMerra\Connector\Cron;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use AskMerra\Connector\Model\Sync\StockChecker;

/** Every 15 minutes: products that sold out or came back through orders (see StockChecker). */
class CheckStock
{
    public function __construct(
        private readonly Config $config,
        private readonly StockChecker $stockChecker,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->config->getSyncStoreIds() as $storeId) {
            try {
                $this->stockChecker->check($storeId);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Stock check of store %d: %s', $storeId, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
