<?php

declare(strict_types=1);

namespace AskMerra\Connector\Plugin\CatalogRule;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use AskMerra\Connector\Model\Sync\Enqueuer;
use Magento\CatalogRule\Model\Indexer\IndexBuilder;
use Magento\Framework\App\ResourceConnection;

/**
 * A full catalog price rule reindex (saving or applying rules, the nightly rule update) rebuilds
 * catalogrule_product_price in a copy of the table, so the change log sees nothing. The guest
 * prices from before and after are compared, and the products whose price changed are queued.
 */
class CaptureRulePriceChanges
{
    public function __construct(
        private readonly Config $config,
        private readonly Enqueuer $enqueuer,
        private readonly ResourceConnection $resource,
        private readonly Logger $logger
    ) {
    }

    public function aroundReindexFull(IndexBuilder $subject, callable $proceed)
    {
        if (!$this->config->getSyncStoreIds()) {
            return $proceed();
        }

        $before = $this->getPrices();
        $result = $proceed();

        try {
            $after = $this->getPrices();
            $changed = [];

            foreach ($before + $after as $key => $unused) {
                if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                    $changed[(int) $key] = true;
                }
            }

            if ($changed) {
                $this->enqueuer->enqueue(array_keys($changed));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Catalog rule price changes: ' . $e->getMessage(), ['exception' => $e]);
        }

        return $result;
    }

    /** @return array<string, string> product id => fingerprint of its guest rule prices from yesterday on, per website */
    private function getPrices(): array
    {
        $connection = $this->resource->getConnection();
        $connection->query('SET SESSION group_concat_max_len = 65536');
        $select = $connection->select()
            ->from($this->resource->getTableName('catalogrule_product_price'), [
                'product_id',
                'prices' => new \Zend_Db_Expr(
                    "MD5(GROUP_CONCAT(CONCAT(website_id, '@', rule_date, '=', rule_price) ORDER BY website_id, rule_date SEPARATOR ';'))"
                ),
            ])
            ->where('customer_group_id = ?', 0)
            ->where('rule_date >= ?', gmdate('Y-m-d', time() - 86400))
            ->group('product_id');

        return $connection->fetchPairs($select);
    }
}
