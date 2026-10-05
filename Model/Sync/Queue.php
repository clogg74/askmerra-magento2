<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * askmerra_queue: products waiting to be sent, one row per store view and product. A product that
 * changes again while waiting stays one row. Failures are retried with growing pauses (1 minute,
 * 2, 4... up to 6 hours); after MAX_ATTEMPTS the row stays as "failed" for the status page.
 *
 * Queuing a product again counts up its row's revision. The rows fetchDue() returned are removed
 * or updated only while they keep that revision: a product edited while a run was sending it stays
 * queued, with the edit.
 */
class Queue
{
    public const TABLE = 'askmerra_queue';

    public const MAX_ATTEMPTS = 8;

    private const MAX_BACKOFF_SECONDS = 21600;

    /** @var array<int, array<int, array{0: int, 1: int}>> queue id and revision of the rows fetchDue() last returned, by store view and product id */
    private array $fetched = [];

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
                    ['attempts', 'available_at', 'last_error', 'revision' => new \Zend_Db_Expr('revision + 1')]
                );
                $count += count($rows);
            }
        }

        return $count;
    }

    /**
     * @return int[] product ids due now in a store view, oldest first. Their rows are remembered:
     *               remove(), fail(), failPermanently() and postpone() change them only while nobody
     *               queued the product again.
     */
    public function fetchDue(int $storeId, int $limit): array
    {
        $select = $this->connection()->select()
            ->from($this->table(), ['product_id', 'queue_id', 'revision'])
            ->where('store_id = ?', $storeId)
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->where('available_at <= ?', $this->now())
            ->order(['available_at ASC', 'queue_id ASC'])
            ->limit($limit);

        // The store view's previous batch is finished: only this one is remembered.
        $this->fetched[$storeId] = [];

        foreach ($this->connection()->fetchAll($select) as $row) {
            $this->fetched[$storeId][(int) $row['product_id']] = [(int) $row['queue_id'], (int) $row['revision']];
        }

        return array_keys($this->fetched[$storeId]);
    }

    /** @param int[] $productIds */
    public function remove(int $storeId, array $productIds): void
    {
        foreach ($this->rows($storeId, $productIds) as $where) {
            $this->connection()->delete($this->table(), $where);
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
        $connection = $this->connection();

        foreach ($this->rows($storeId, $productIds) as $where) {
            // available_at is set first, from the attempts counted so far.
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
                $where
            );
        }
    }

    /**
     * Marks products failed for good - AskMerra rejected them as they are. They are tried again when
     * they change, or with "Retry failed".
     *
     * @param int[] $productIds
     */
    public function failPermanently(int $storeId, array $productIds, string $error): void
    {
        foreach ($this->rows($storeId, $productIds) as $where) {
            $this->connection()->update(
                $this->table(),
                ['attempts' => self::MAX_ATTEMPTS, 'last_error' => mb_substr($error, 0, 2000)],
                $where
            );
        }
    }

    /**
     * Pauses products without counting a failure.
     *
     * @param int[] $productIds
     */
    public function postpone(int $storeId, array $productIds, int $seconds, ?string $reason = null): void
    {
        $data = $this->pause($seconds, $reason);

        foreach ($this->rows($storeId, $productIds) as $where) {
            $this->connection()->update($this->table(), $data, $where);
        }
    }

    /**
     * Pauses a whole store view without counting a failure - a rate limit, a refused key, AskMerra
     * out of reach: none of its waiting products is tried before $seconds from now.
     *
     * @return int products postponed
     */
    public function postponeStore(int $storeId, int $seconds, ?string $reason = null): int
    {
        $data = $this->pause($seconds, $reason);

        return $this->connection()->update($this->table(), $data, [
            'store_id = ?' => $storeId,
            'attempts < ?' => self::MAX_ATTEMPTS,
            'available_at < ?' => $data['available_at'],
        ]);
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

    /**
     * WHERE conditions for products of a store view, in chunks. A row fetchDue() returned matches
     * only while it has the revision it was fetched with: a product queued again meanwhile keeps
     * its row. The queue id also tells a row deleted and added again since.
     *
     * @param int[] $productIds
     * @return array[]
     */
    private function rows(int $storeId, array $productIds): array
    {
        $fetched = [];
        $other = [];

        foreach ($productIds as $productId) {
            $row = $this->fetched[$storeId][(int) $productId] ?? null;

            if ($row === null) {
                $other[] = (int) $productId;
            } else {
                // Grouped by revision: one statement per revision, mostly a single one.
                $fetched[$row[1]][] = $row[0];
            }
        }

        $conditions = [];

        foreach (array_chunk($other, 1000) as $chunk) {
            $conditions[] = ['store_id = ?' => $storeId, 'product_id IN (?)' => $chunk];
        }

        foreach ($fetched as $revision => $queueIds) {
            foreach (array_chunk($queueIds, 1000) as $chunk) {
                $conditions[] = ['queue_id IN (?)' => $chunk, 'revision = ?' => $revision];
            }
        }

        return $conditions;
    }

    /** @return array the columns pausing rows for $seconds, with the reason as their error when given */
    private function pause(int $seconds, ?string $reason): array
    {
        $data = ['available_at' => gmdate('Y-m-d H:i:s', time() + max(1, $seconds))];

        if ($reason !== null) {
            $data['last_error'] = mb_substr($reason, 0, 2000);
        }

        return $data;
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
