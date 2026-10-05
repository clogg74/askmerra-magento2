<?php

declare(strict_types=1);

namespace AskMerra\Connector\Observer;

use AskMerra\Connector\Model\Sync\Enqueuer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * A safety net for when the "AskMerra product sync" indexer was switched to "Update on Save":
 * product saves, deletions, mass attribute updates and stock changes made in Magento are still
 * queued. With "Update by Schedule" (the default) the change log already covers them, imports and
 * SQL included, so this does nothing.
 */
class ProductChanged implements ObserverInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    public function execute(Observer $observer)
    {
        try {
            if ($this->indexerRegistry->get('askmerra_products')->isScheduled()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $event = $observer->getEvent();
        $ids = (array) $event->getData('product_ids');

        foreach (['product', 'item'] as $key) {
            $object = $event->getData($key);

            if ($object && $object->getData('product_id')) {
                $ids[] = (int) $object->getData('product_id');
            } elseif ($object && $object->getId() && $key === 'product') {
                $ids[] = (int) $object->getId();
            }
        }

        if ($ids) {
            $this->enqueuer->enqueue($ids);
        }
    }
}
