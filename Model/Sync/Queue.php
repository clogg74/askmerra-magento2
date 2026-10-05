<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * askmerra_queue: products waiting to be sent, one row per store view and product. A product that
 * changes again while waiting stays one row. Failures are retried with growing pauses (1 minute,
 * 2, 4... up to 6 hours); after MAX_ATTEMPTS the row stays as "failed" for the status page.
 */
class Queue
{
    public const TABLE = 'askmerra_queue';

    public const MAX_ATTEMPTS = 8;

    private const MAX_BACKOFF_SECONDS = 21600;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Queues products in store views. A product queued again is due at once and its failure count
     * starts over - the change may be the fix.
     *
     * @param int[] $storeIds
     * @param int[] $productIds
     */
    public function add(array $storeIds, array $productIds): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$storeIds || !$productIds) {
            return 0;
        }

        $now = $this->now();
        $count = 0;

        foreach ($storeIds as $storeId) {
            foreach (array_chunk($productIds, 1000) as $chunk) {
                $rows = [];

                foreach ($chunk as $productId) {
                    $rows[] = [
                        'store_id' => (int) $storeId,
                        'product_id' => $productId,
                        'attempts' => 0,
                        'available_at' => $now,
                        'created_at' => $now,
                        'last_error' => null,
                    ];
                }

                $this->connection()->insertOnDuplicate(
                    $this->table(),
                    $rows,
                    ['attempts', 'available_at', 'last_error']
                );
                $count += count($rows);
            }
        }

        return $count;
    }

    /** @return int[] product ids due now in a store view, oldest first */
    public function fetchDue(int $storeId, int $limit): array
    {
        $select = $this->connection()->select()
            ->from($this->table(), ['product_id'])
            ->where('store_id = ?', $storeId)
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->where('available_at <= ?', $this->now())
            ->order(['available_at ASC', 'queue_id ASC'])
            ->limit($limit);

        return array_map('intval', $this->connection()->fetchCol($select));
    }

    /** @param int[] $productIds */
    public function remove(int $storeId, array $productIds): void
    {
        if ($productIds) {
            $this->connection()->delete($this->table(), [
                'store_id = ?' => $storeId,
                'product_id IN (?)' => array_map('intval', $productIds),
            ]);
        }
    }

    /**
     * Counts a failed attempt and pauses the products: 1 minute after the first failure, doubling
     * up to 6 hours.
     *
     * @param int[] $productIds
     */
    public function fail(int $storeId, array $productIds, string $error): void
    {
        if (!$productIds) {
            return;
        }

        $connection = $this->connection();
        $connection->update(
            $this->table(),
            [
                'available_at' => new \Zend_Db_Expr(sprintf(
                    'DATE_ADD(%s, INTERVAL LEAST(60 * POW(2, attempts), %d) SECOND)',
                    $connection->quote($this->now()),
                    self::MAX_BACKOFF_SECONDS
                )),
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_error' => mb_substr($error, 0, 2000),
            ],
            ['store_id = ?' => $storeId, 'product_id IN (?)' => array_map('intval', $productIds)]
        );
    }

    /**
     * Marks products failed for good - AskMerra rejected them as they are. They are tried again when
     * they change, or with "Retry failed".
     *
     * @param int[] $productIds
     */
    public function failPermanently(int $storeId, array $productIds, string $error): void
    {
        if ($productIds) {
            $this->connection()->update(
                $this->table(),
                ['attempts' => self::MAX_ATTEMPTS, 'last_error' => mb_substr($error, 0, 2000)],
                ['store_id = ?' => $storeId, 'product_id IN (?)' => array_map('intval', $productIds)]
            );
        }
    }

    /**
     * Pauses products without counting a failure: a rate limit or a key that is being fixed.
     *
     * @param int[] $productIds
     */
    public function postpone(int $storeId, array $productIds, int $seconds, ?string $reason = null): void
    {
        if (!$productIds) {
            return;
        }

        $data = ['available_at' => gmdate('Y-m-d H:i:s', time() + max(1, $seconds))];

        if ($reason !== null) {
            $data['last_error'] = mb_substr($reason, 0, 2000);
        }

        $this->connection()->update(
            $this->table(),
            $data,
            ['store_id = ?' => $storeId, 'product_id IN (?)' => array_map('intval', $productIds)]
        );
    }

    /** Makes failed products due again. */
    public function retryFailed(?int $storeId = null): int
    {
        $where = ['attempts >= ?' => self::MAX_ATTEMPTS];

        if ($storeId !== null) {
            $where['store_id = ?'] = $storeId;
        }

        return $this->connection()->update(
            $this->table(),
            ['attempts' => 0, 'available_at' => $this->now(), 'last_error' => null],
            $where
        );
    }

    /** Products still to be sent, including those waiting after a failure. */
    public function countPending(int $storeId): int
    {
        return (int) $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->table(), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id = ?', $storeId)
                ->where('attempts < ?', self::MAX_ATTEMPTS)
        );
    }

    /** @return int[] products that failed MAX_ATTEMPTS times */
    public function getFailedProductIds(int $storeId): array
    {
        return array_map('intval', $this->connection()->fetchCol(
            $this->connection()->select()
                ->from($this->table(), ['product_id'])
                ->where('store_id = ?', $storeId)
                ->where('attempts >= ?', self::MAX_ATTEMPTS)
        ));
    }

    /** @return array[] the latest errors of a store view, for the status page */
    public function getErrors(int $storeId, int $limit = 20): array
    {
        return $this->connection()->fetchAll(
            $this->connection()->select()
                ->from($this->table(), ['product_id', 'attempts', 'available_at', 'last_error'])
                ->where('store_id = ?', $storeId)
                ->where('last_error IS NOT NULL')
                ->order('attempts DESC')
                ->limit($limit)
        );
    }

    /** Empties a store view's queue, or the whole queue. */
    public function clear(?int $storeId = null): void
    {
        $this->connection()->delete($this->table(), $storeId === null ? [] : ['store_id = ?' => $storeId]);
    }

    /** Drops the queue of store views that no longer push (sync turned off or switched to a feed). */
    public function removeStoresExcept(array $storeIds): int
    {
        return $this->connection()->delete(
            $this->table(),
            $storeIds ? ['store_id NOT IN (?)' => $storeIds] : []
        );
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
