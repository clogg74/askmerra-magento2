<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Catalog\ProductBuilder;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Keeps a store view's AskMerra catalog matching Magento without rebuilding products that did not
 * change - for catalogs of tens of thousands of products.
 *
 * - reconcile() (daily, a few queries): queues products that should be in AskMerra but are not,
 *   products that are in AskMerra but left the catalog, and products whose special price starts
 *   or ends today (a date passing changes no row, so nothing else notices).
 * - rebuild() (weekly by default, or by hand): queues every product; the queue still sends only
 *   those whose content changed.
 */
class Reconciler
{
    public function __construct(
        private readonly ProductBuilder $productBuilder,
        private readonly MethodTracker $methodTracker,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Rebuilds a store view that switched between Push API and feed since its catalog was last
     * handled - however the setting was changed.
     *
     * @return bool whether it switched
     */
    public function ensureSyncMethod(int $storeId): bool
    {
        if (!$this->methodTracker->check($storeId)) {
            return false;
        }

        $this->rebuild($storeId);

        return true;
    }

    /** @return array{missing: int, gone: int, price_dates: int} */
    public function reconcile(int $storeId): array
    {
        if ($this->ensureSyncMethod($storeId)) {
            return ['missing' => 0, 'gone' => 0, 'price_dates' => 0];
        }

        $runId = $this->runLog->start($storeId, RunLog::TYPE_RECONCILE);
        $candidates = $this->productBuilder->getCandidateIds($storeId);
        $known = $this->state->getProductIds($storeId);

        $missing = array_diff($candidates, $known);
        $gone = array_diff($known, $candidates);
        $dated = array_intersect(
            array_unique(array_merge($this->getSpecialPriceChanges(), $this->getRulePriceChanges())),
            $candidates
        );

        $this->queue->add([$storeId], array_merge($missing, $gone, $dated));

        $stats = ['missing' => count($missing), 'gone' => count($gone), 'price_dates' => count($dated)];
        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, $stats);

        return $stats;
    }

    /** @return array{queued: int} */
    public function rebuild(int $storeId): array
    {
        $this->methodTracker->check($storeId);
        $runId = $this->runLog->start($storeId, RunLog::TYPE_REBUILD);
        $candidates = $this->productBuilder->getCandidateIds($storeId);
        $gone = array_diff($this->state->getProductIds($storeId), $candidates);

        $queued = $this->queue->add([$storeId], array_merge($candidates, $gone));
        $this->methodTracker->rebuilt($storeId);

        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, ['queued' => $queued]);

        return ['queued' => $queued];
    }

    /**
     * Products whose catalog price rule price for guests is not the same today as yesterday: a rule
     * started or ended (the rule index holds a price per day, so nothing changes at midnight).
     *
     * @return int[]
     */
    private function getRulePriceChanges(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('catalogrule_product_price');

        if (!$connection->isTableExists($table)) {
            return [];
        }

        $today = $this->timezone->date()->format('Y-m-d');
        $yesterday = $this->timezone->date()->modify('-1 day')->format('Y-m-d');
        $ids = [];

        // A price today that differs from yesterday's or is new, and yesterday's prices that ended.
        foreach ([[$today, $yesterday], [$yesterday, $today]] as [$day, $otherDay]) {
            $select = $connection->select()
                ->distinct()
                ->from(['day' => $table], ['product_id'])
                ->joinLeft(
                    ['other' => $table],
                    implode(' AND ', [
                        'other.product_id = day.product_id',
                        'other.website_id = day.website_id',
                        'other.customer_group_id = day.customer_group_id',
                        $connection->quoteInto('other.rule_date = ?', $otherDay),
                    ]),
                    []
                )
                ->where('day.customer_group_id = ?', 0)
                ->where('day.rule_date = ?', $day)
                ->where('other.product_id IS NULL OR other.rule_price <> day.rule_price');

            $ids[] = $connection->fetchCol($select);
        }

        return array_map('intval', array_unique(array_merge([], ...$ids)));
    }

    /**
     * Products whose special price started today or ended yesterday, in any store view.
     *
     * @return int[]
     */
    private function getSpecialPriceChanges(): array
    {
        $attributeIds = [];

        foreach (['special_from_date', 'special_to_date'] as $code) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);

            if ($attribute && $attribute->getId()) {
                $attributeIds[] = (int) $attribute->getId();
            }
        }

        if (!$attributeIds) {
            return [];
        }

        $today = $this->timezone->date()->setTime(0, 0);
        $from = (clone $today)->modify('-1 day')->format('Y-m-d H:i:s');
        $to = (clone $today)->modify('+1 day')->format('Y-m-d H:i:s');

        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $select = $connection->select()
            ->distinct()
            ->from(['value' => $this->resource->getTableName('catalog_product_entity_datetime')], [])
            ->join(
                ['product' => $this->resource->getTableName('catalog_product_entity')],
                'product.' . $linkField . ' = value.' . $linkField,
                ['entity_id']
            )
            ->where('value.attribute_id IN (?)', $attributeIds)
            ->where('value.value >= ?', $from)
            ->where('value.value < ?', $to);

        return array_map('intval', $connection->fetchCol($select));
    }
}
