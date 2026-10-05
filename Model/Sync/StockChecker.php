<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Catalog\ProductBuilder;

/**
 * Every 15 minutes: queues the products whose "can be bought" state changed since they were sent.
 * Orders change it through MSI reservations, which update no product row, so the change log does
 * not see it. One query per store view; only the changed products are rebuilt.
 */
class StockChecker
{
    public function __construct(
        private readonly ProductBuilder $productBuilder,
        private readonly State $state,
        private readonly Queue $queue
    ) {
    }

    /** @return int products queued */
    public function check(int $storeId): int
    {
        $sent = $this->state->getStockFlags($storeId);

        if (!$sent) {
            return 0;
        }

        $changed = [];

        foreach ($this->productBuilder->getStockFlags($storeId) as $productId => $inStock) {
            if (isset($sent[$productId]) && $sent[$productId] !== $inStock) {
                $changed[] = $productId;
            }
        }

        return $this->queue->add([$storeId], $changed);
    }
}
