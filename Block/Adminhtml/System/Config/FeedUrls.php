<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block\Adminhtml\System\Config;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Feed\FeedGenerator;
use AskMerra\Connector\Model\Sync\Queue;
use Magento\Backend\Block\Template\Context;

/** The feed URL of each store view in the scope, ready to copy into AskMerra. */
class FeedUrls extends AbstractButton
{
    protected $_template = 'AskMerra_Connector::system/config/feed-urls.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags,
        private readonly Queue $queue,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array[] name, url, status text, and whether the file exists */
    public function getFeeds(): array
    {
        $feeds = [];

        foreach ($this->getScopeStoreIds() as $storeId) {
            if (!$this->config->isFeedEnabled($storeId)) {
                continue;
            }

            $store = $this->_storeManager->getStore($storeId);
            $info = $this->feedGenerator->getInfo($storeId);

            if ($info['exists']) {
                $status = __(
                    'Published %1 (%2 KB).',
                    $this->formatUtc((string) $info['modified_at']),
                    number_format($info['bytes'] / 1024, 0)
                );
            } elseif (!$this->feedFlags->isReady($storeId)) {
                $status = __(
                    'Being prepared: %1 products to go. The URL works once the whole catalog is ready.',
                    $this->queue->countPending($storeId)
                );
            } else {
                $status = __('Not written yet: it is written at the next scheduled run.');
            }

            $feeds[] = [
                'name' => $store->getWebsite()->getName() . ' / ' . $store->getName(),
                'url' => $info['url'],
                'status' => (string) $status,
                'exists' => $info['exists'],
            ];
        }

        return $feeds;
    }

    public function getStatusUrl(): string
    {
        return $this->getUrl('askmerra/status/index');
    }

    private function formatUtc(string $utc): string
    {
        return $this->_localeDate->formatDateTime(
            new \DateTime($utc, new \DateTimeZone('UTC')),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }
}
