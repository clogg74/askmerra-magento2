<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Queues changed products for every store view that syncs. A variant that changes is shown through
 * its configurable, bundle or grouped product, so those are queued with it.
 */
class Enqueuer
{
    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @param int[] $productIds
     * @param int[]|null $storeIds null: every store view that syncs
     */
    public function enqueue(array $productIds, ?array $storeIds = null): int
    {
        $storeIds ??= $this->config->getSyncStoreIds();
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$storeIds || !$productIds) {
            return 0;
        }

        return $this->queue->add($storeIds, $this->withParents($productIds));
    }

    /**
     * @param int[] $productIds
     * @return int[]
     */
    private function withParents(array $productIds): array
    {
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $parents = [];

        foreach (array_chunk($productIds, 1000) as $chunk) {
            // catalog_product_relation.parent_id is the link field: entity_id in Open Source,
            // row_id in Adobe Commerce.
            $select = $connection->select()
                ->distinct()
                ->from(['relation' => $this->resource->getTableName('catalog_product_relation')], [])
                ->join(
                    ['parent' => $this->resource->getTableName('catalog_product_entity')],
                    'parent.' . $linkField . ' = relation.parent_id',
                    ['entity_id']
                )
                ->where('relation.child_id IN (?)', $chunk);

            $parents[] = $connection->fetchCol($select);
        }

        return array_values(array_unique(array_merge($productIds, array_map('intval', array_merge([], ...$parents)))));
    }
}
