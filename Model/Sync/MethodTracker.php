<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use Magento\Framework\FlagManager;

/**
 * Notices when a store view switched between Push API and feed - also while it was turned off - and
 * has its whole catalog sent again the new way: a feed needs every product's entry, and pushed
 * products must not count on what an old feed delivered. A store view that switched to a feed does
 * not publish it before it is complete.
 */
class MethodTracker
{
    private const FLAG = 'askmerra_sync_methods';

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly FeedFlags $feedFlags,
        private readonly FlagManager $flagManager
    ) {
    }

    /** @return bool whether the store view switched since its catalog was last checked */
    public function check(int $storeId): bool
    {
        $methods = $this->flagManager->getFlagData(self::FLAG);
        $methods = is_array($methods) ? $methods : [];
        $current = $this->config->getSyncMethod($storeId);
        $previous = $methods[$storeId] ?? null;

        if ($previous === $current) {
            return false;
        }

        if ($previous !== null) {
            $isFeed = $current === Config::SYNC_FEED;
            $this->state->invalidateStore($storeId, $isFeed);

            if ($isFeed) {
                $this->feedFlags->resetReady($storeId);
            }
        }

        $methods[$storeId] = $current;
        $this->flagManager->saveFlag(self::FLAG, $methods);

        return $previous !== null;
    }
}
