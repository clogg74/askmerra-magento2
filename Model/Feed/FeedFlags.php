<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Feed;

use Magento\Framework\FlagManager;

/**
 * Per store view: whether its feed file is behind its cached products ("dirty"), and whether the
 * cache holds the whole catalog yet ("ready"). A feed is never written before it is ready: AskMerra
 * removes every product missing from a feed, so a half-built feed would empty the assistant.
 */
class FeedFlags
{
    private const DIRTY = 'askmerra_feed_dirty';
    private const READY = 'askmerra_feed_ready';

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    public function markDirty(int $storeId): void
    {
        $this->set(self::DIRTY, $storeId, true);
    }

    public function clearDirty(int $storeId): void
    {
        $this->set(self::DIRTY, $storeId, false);
    }

    public function isDirty(int $storeId): bool
    {
        return $this->get(self::DIRTY, $storeId);
    }

    public function markReady(int $storeId): void
    {
        $this->set(self::READY, $storeId, true);
    }

    /** The cache must be filled again (the store view just switched to a feed). */
    public function resetReady(int $storeId): void
    {
        $this->set(self::READY, $storeId, false);
    }

    public function isReady(int $storeId): bool
    {
        return $this->get(self::READY, $storeId);
    }

    private function get(string $flag, int $storeId): bool
    {
        $data = $this->flagManager->getFlagData($flag);

        return is_array($data) && !empty($data[$storeId]);
    }

    private function set(string $flag, int $storeId, bool $value): void
    {
        $data = $this->flagManager->getFlagData($flag);
        $data = is_array($data) ? $data : [];

        if ((bool) ($data[$storeId] ?? false) === $value) {
            return;
        }

        $data[$storeId] = $value;
        $this->flagManager->saveFlag($flag, $data);
    }
}
