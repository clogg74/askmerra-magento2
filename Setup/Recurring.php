<?php

declare(strict_types=1);

namespace AskMerra\Connector\Setup;

use Magento\Framework\FlagManager;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * On the first setup:upgrade after install, puts the "AskMerra product sync" indexer on "Update by
 * Schedule": Magento's change log then reports every product change - imports, ERP integrations and
 * direct SQL included - and the queue sends only those products. Done here rather than in a data
 * patch because creating the database triggers cannot run inside the patch's transaction. Only
 * once: a later choice of "Update on Save" is respected.
 */
class Recurring implements InstallSchemaInterface
{
    private const FLAG = 'askmerra_indexer_scheduled';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly FlagManager $flagManager
    ) {
    }

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        if ($this->flagManager->getFlagData(self::FLAG)) {
            return;
        }

        try {
            $indexer = $this->indexerRegistry->get('askmerra_products');

            if (!$indexer->isScheduled()) {
                $indexer->setScheduled(true);
            }

            $this->flagManager->saveFlag(self::FLAG, 1);
        } catch (\Throwable) {
            // Setup goes on; the Sync status page asks for "Update by Schedule" until it is set.
        }
    }
}
