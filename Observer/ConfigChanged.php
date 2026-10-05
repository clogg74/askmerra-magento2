<?php

declare(strict_types=1);

namespace AskMerra\Connector\Observer;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Sync\Reconciler;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * After the AskMerra settings are saved: when the change alters what is sent (keys, language,
 * catalog settings, sync method), the store views in the saved scope rebuild their catalog -
 * still only products whose content changed are sent. A feed format change rewrites the files.
 */
class ConfigChanged implements ObserverInterface
{
    private const REBUILD_PATHS = [
        'askmerra/general/enabled',
        'askmerra/general/secret_key',
        'askmerra/general/locale',
        'askmerra/catalog/',
        'askmerra/advanced/api_url',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Reconciler $reconciler,
        private readonly FeedFlags $feedFlags,
        private readonly StoreManagerInterface $storeManager,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(Observer $observer)
    {
        $changed = (array) $observer->getEvent()->getData('changed_paths');
        $rebuild = false;
        $feedChanged = false;

        foreach ($changed as $path) {
            foreach (self::REBUILD_PATHS as $prefix) {
                if (str_starts_with((string) $path, $prefix)) {
                    $rebuild = true;
                }
            }

            if (str_starts_with((string) $path, 'askmerra/feed/format')) {
                $feedChanged = true;
            }
        }

        if (!$rebuild && !$feedChanged) {
            return;
        }

        $storeIds = array_intersect(
            $this->getScopeStoreIds((string) $observer->getEvent()->getData('website'), (string) $observer->getEvent()->getData('store')),
            $this->config->getSyncStoreIds()
        );

        foreach ($storeIds as $storeId) {
            if ($this->config->isFeedEnabled($storeId)) {
                $this->feedFlags->markDirty($storeId);
            }

            if ($rebuild) {
                // Also notices a switch between Push API and feed (Sync\MethodTracker).
                $this->reconciler->rebuild($storeId);
            }
        }

        if ($rebuild && $storeIds) {
            $this->messageManager->addNoticeMessage(__(
                'AskMerra: the catalog of %1 store view(s) is being rebuilt; only products whose content changed are sent. Follow it under Marketing > AskMerra > Sync status.',
                count($storeIds)
            ));
        }
    }

    /** @return int[] */
    private function getScopeStoreIds(string $websiteId, string $storeId): array
    {
        if ($storeId !== '') {
            return [(int) $storeId];
        }

        $ids = [];

        foreach ($this->storeManager->getStores() as $store) {
            if ($websiteId === '' || (int) $store->getWebsiteId() === (int) $websiteId) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }
}
