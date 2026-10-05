<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * askmerra_product_state: what each store view has in AskMerra - id, language, a hash of the
 * product as built and, for feed store views, the finished feed entry (deflated JSON).
 */
class State
{
    public const TABLE = 'askmerra_product_state';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{product_id: int, external_id: string, locale: ?string, payload_hash: string}>
     */
    public function get(int $storeId, array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $rows = $this->connection()->fetchAll(
            $this->connection()->select()
                ->from($this->table(), ['product_id', 'external_id', 'locale', 'payload_hash'])
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', array_map('intval', $productIds))
        );

        $result = [];

        foreach ($rows as $row) {
            $row['product_id'] = (int) $row['product_id'];
            $result[$row['product_id']] = $row;
        }

        return $result;
    }

    /**
     * Records products as sent or rebuilt now.
     *
     * @param array[] $rows each: product_id, external_id, locale, payload_hash, in_stock, and payload
     *                      (the product's JSON) for feed store views
     */
    public function save(int $storeId, array $rows): void
    {
        if (!$rows) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = [];

        foreach ($rows as $row) {
            $payload = $row['payload'] ?? null;
            $data[] = [
                'store_id' => $storeId,
                'product_id' => (int) $row['product_id'],
                'external_id' => (string) $row['external_id'],
                'locale' => $row['locale'],
                'payload_hash' => (string) $row['payload_hash'],
                'in_stock' => isset($row['in_stock']) ? (int) (bool) $row['in_stock'] : null,
                'payload' => $payload === null ? null : gzdeflate($payload, 6),
                'synced_at' => $now,
            ];
        }

        foreach (array_chunk($data, 500) as $chunk) {
            $this->connection()->insertOnDuplicate(
                $this->table(),
                $chunk,
                ['external_id', 'locale', 'payload_hash', 'in_stock', 'payload', 'synced_at']
            );
        }
    }

    /**
     * Makes the queue rebuild these products even when their content did not change (their stored
     * entry is damaged).
     *
     * @param int[] $productIds
     */
    public function invalidate(int $storeId, array $productIds): void
    {
        foreach (array_chunk(array_map('intval', $productIds), 1000) as $chunk) {
            $this->connection()->update(
                $this->table(),
                ['payload_hash' => ''],
                ['store_id = ?' => $storeId, 'product_id IN (?)' => $chunk]
            );
        }
    }

    /**
     * Makes the queue rebuild and send every product of a store view, e.g. after it switched between
     * Push API and feed. The rows stay: they still say what AskMerra has, so leftovers are removed.
     */
    public function invalidateStore(int $storeId, bool $keepPayloads): void
    {
        $data = ['payload_hash' => ''];

        if (!$keepPayloads) {
            $data['payload'] = null;
        }

        $this->connection()->update($this->table(), $data, ['store_id = ?' => $storeId]);
    }

    /** @param int[] $productIds */
    public function delete(int $storeId, array $productIds): void
    {
        foreach (array_chunk(array_map('intval', $productIds), 1000) as $chunk) {
            $this->connection()->delete($this->table(), ['store_id = ?' => $storeId, 'product_id IN (?)' => $chunk]);
        }
    }

    /** Forgets everything a store view sent. */
    public function clear(int $storeId): void
    {
        $this->connection()->delete($this->table(), ['store_id = ?' => $storeId]);
    }

    /** @return int[] every product a store view has in AskMerra */
    public function getProductIds(int $storeId): array
    {
        return array_map('intval', $this->connection()->fetchCol(
            $this->connection()->select()->from($this->table(), ['product_id'])->where('store_id = ?', $storeId)
        ));
    }

    /** @return array<int, bool> product id => in stock as last sent, for the products where it is known */
    public function getStockFlags(int $storeId): array
    {
        $pairs = $this->connection()->fetchPairs(
            $this->connection()->select()
                ->from($this->table(), ['product_id', 'in_stock'])
                ->where('store_id = ?', $storeId)
                ->where('in_stock IS NOT NULL')
        );

        return array_map(static fn ($value) => (bool) $value, $pairs);
    }

    /** @return array[] product_id, external_id, locale - a page of everything a store view sent */
    public function getPage(int $storeId, int $afterProductId, int $limit): array
    {
        return $this->connection()->fetchAll(
            $this->connection()->select()
                ->from($this->table(), ['product_id', 'external_id', 'locale'])
                ->where('store_id = ?', $storeId)
                ->where('product_id > ?', $afterProductId)
                ->order('product_id ASC')
                ->limit($limit)
        );
    }

    /**
     * A page of finished feed entries, in product id order.
     *
     * @return array<int, ?string> product id => the product's JSON, null when the entry is damaged
     */
    public function getPayloadPage(int $storeId, int $afterProductId, int $limit): array
    {
        $rows = $this->connection()->fetchPairs(
            $this->connection()->select()
                ->from($this->table(), ['product_id', 'payload'])
                ->where('store_id = ?', $storeId)
                ->where('product_id > ?', $afterProductId)
                ->where('payload IS NOT NULL')
                ->order('product_id ASC')
                ->limit($limit)
        );

        $result = [];

        foreach ($rows as $productId => $payload) {
            try {
                $json = gzinflate((string) $payload);
            } catch (\Throwable) {
                // Magento turns the warning about damaged data into an exception.
                $json = false;
            }

            $result[(int) $productId] = $json === false ? null : $json;
        }

        return $result;
    }

    public function count(int $storeId): int
    {
        return (int) $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->table(), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id = ?', $storeId)
        );
    }

    /** Products whose finished feed entry is stored (feed store views). */
    public function countPayloads(int $storeId): int
    {
        return (int) $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->table(), [new \Zend_Db_Expr('COUNT(*)')])
                ->where('store_id = ?', $storeId)
                ->where('payload IS NOT NULL')
        );
    }

    public function getLastSyncedAt(int $storeId): ?string
    {
        $value = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->table(), [new \Zend_Db_Expr('MAX(synced_at)')])
                ->where('store_id = ?', $storeId)
        );

        return $value ?: null;
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
