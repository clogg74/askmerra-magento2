<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Indexer;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Sync\Enqueuer;
use AskMerra\Connector\Model\Sync\Reconciler;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * The "AskMerra product sync" indexer. It sends nothing itself: it queues the products Magento's
 * change log reports, and the queue (cron, every minute) builds and sends them. A full reindex
 * rebuilds every product of every store view that syncs.
 */
class Product implements IndexerActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly Enqueuer $enqueuer,
        private readonly Reconciler $reconciler,
        private readonly Config $config
    ) {
    }

    /** Products from the change log ("Update by Schedule"). */
    public function execute($ids)
    {
        $this->enqueuer->enqueue((array) $ids);
    }

    public function executeFull()
    {
        foreach ($this->config->getSyncStoreIds() as $storeId) {
            $this->reconciler->rebuild($storeId);
        }
    }

    public function executeList(array $ids)
    {
        $this->enqueuer->enqueue($ids);
    }

    public function executeRow($id)
    {
        $this->enqueuer->enqueue([(int) $id]);
    }
}
