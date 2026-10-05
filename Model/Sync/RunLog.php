<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/** askmerra_sync_run: rebuilds, daily reconciliations, feed files and removals, for the status page. */
class RunLog
{
    public const TABLE = 'askmerra_sync_run';

    public const TYPE_REBUILD = 'rebuild';
    public const TYPE_RECONCILE = 'reconcile';
    public const TYPE_FEED = 'feed';
    public const TYPE_REMOVE_ALL = 'remove_all';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REPLACED = 'replaced';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function start(int $storeId, string $type, array $stats = []): int
    {
        $this->connection()->insert($this->table(), [
            'store_id' => $storeId,
            'type' => $type,
            'status' => self::STATUS_RUNNING,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'stats' => json_encode($stats),
        ]);

        return (int) $this->connection()->lastInsertId($this->table());
    }

    public function finish(int $runId, string $status, array $stats = [], ?string $message = null): void
    {
        $current = $this->get($runId);

        $this->connection()->update($this->table(), [
            'status' => $status,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'stats' => json_encode(array_merge($current['stats'] ?? [], $stats)),
            'message' => $message === null ? null : mb_substr($message, 0, 4000),
        ], ['run_id = ?' => $runId]);
    }

    public function get(int $runId): ?array
    {
        $row = $this->connection()->fetchRow(
            $this->connection()->select()->from($this->table())->where('run_id = ?', $runId)
        );

        return $row ? $this->decode($row) : null;
    }

    /** The run of this type still going in a store view. */
    public function getRunning(int $storeId, string $type): ?array
    {
        return $this->fetchLatest($storeId, $type, self::STATUS_RUNNING);
    }

    public function getLast(int $storeId, string $type): ?array
    {
        return $this->fetchLatest($storeId, $type, null);
    }

    /** Forgets runs older than $days days. */
    public function cleanup(int $days = 30): int
    {
        return $this->connection()->delete($this->table(), [
            'started_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400),
            'status <> ?' => self::STATUS_RUNNING,
        ]);
    }

    private function fetchLatest(int $storeId, string $type, ?string $status): ?array
    {
        $select = $this->connection()->select()
            ->from($this->table())
            ->where('store_id = ?', $storeId)
            ->where('type = ?', $type)
            ->order('run_id DESC')
            ->limit(1);

        if ($status !== null) {
            $select->where('status = ?', $status);
        }

        $row = $this->connection()->fetchRow($select);

        return $row ? $this->decode($row) : null;
    }

    private function decode(array $row): array
    {
        $row['run_id'] = (int) $row['run_id'];
        $row['store_id'] = (int) $row['store_id'];
        $row['stats'] = json_decode((string) $row['stats'], true) ?: [];

        return $row;
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
