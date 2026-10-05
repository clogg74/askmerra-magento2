<?php

declare(strict_types=1);

namespace AskMerra\Connector\Setup;

use AskMerra\Connector\Model\Catalog\ProductBuilder;
use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall AskMerra_Connector (Composer installs): removes the indexer triggers,
 * the attribute, the settings and the module's tables. Run bin/magento askmerra:remove first to
 * take the products out of AskMerra.
 */
class Uninstall implements UninstallInterface
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        try {
            $this->indexerRegistry->get('askmerra_products')->setScheduled(false);
        } catch (\Throwable) {
            // the indexer is already gone
        }

        $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->removeAttribute(Product::ENTITY, ProductBuilder::EXCLUDE_ATTRIBUTE);

        $connection->delete($setup->getTable('core_config_data'), ["path LIKE 'askmerra/%'"]);
        $connection->delete($setup->getTable('core_config_data'), ["path LIKE 'crontab/default/jobs/askmerra_%'"]);
        $connection->delete($setup->getTable('flag'), ["flag_code LIKE 'askmerra\\_%'"]);
        $connection->delete($setup->getTable('indexer_state'), ['indexer_id = ?' => 'askmerra_products']);
        $connection->delete($setup->getTable('mview_state'), ['view_id = ?' => 'askmerra_products']);

        foreach (['askmerra_queue', 'askmerra_product_state', 'askmerra_sync_run', 'askmerra_products_cl'] as $table) {
            $connection->dropTable($setup->getTable($table));
        }

        $setup->endSetup();
    }
}
