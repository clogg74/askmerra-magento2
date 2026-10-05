<?php

declare(strict_types=1);

namespace AskMerra\Connector\Controller\Adminhtml\Connection;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Api\Client;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedGenerator;
use AskMerra\Connector\Model\Sync\Problems;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Check connection" in the settings: checks the secret key against AskMerra and the site key's
 * format, with the values typed in the form (saved ones when the field shows ******).
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'AskMerra_Connector::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Client $client,
        private readonly Config $config,
        private readonly Problems $problems,
        private readonly FeedGenerator $feedGenerator,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = $this->getStoreId();
        $messages = [];

        $secretKey = $this->typed('secret_key');
        $siteKey = $this->typed('site_key') ?? $this->config->getSiteKey($storeId);
        $apiUrl = $this->typed('api_url');
        $syncMethod = $this->typed('sync_method') ?? $this->config->getSyncMethod($storeId);
        $savedSecretKey = $this->config->getSecretKey($storeId);

        if ($apiUrl !== null && !preg_match('#^https?://#i', $apiUrl)) {
            return $this->respond([['type' => 'error', 'text' => (string) __('The API URL must start with https://.')]]);
        }

        $effectiveSecretKey = $secretKey ?? $savedSecretKey;

        if ($effectiveSecretKey === '') {
            $messages[] = $syncMethod === Config::SYNC_FEED
                ? ['type' => 'notice', 'text' => (string) __('No secret key: not needed with a product feed - AskMerra downloads the feed URL shown under Product feed.')]
                : ['type' => 'error', 'text' => (string) __('Enter the secret key (sk_live_...) from your AskMerra dashboard, API keys.')];
        } elseif (str_starts_with($effectiveSecretKey, 'pk_')) {
            $messages[] = ['type' => 'error', 'text' => (string) __('The secret key field holds the site key (pk_...). The secret key starts with sk_live_.')];
        } else {
            $messages[] = $this->ping($storeId, $effectiveSecretKey, $apiUrl, $secretKey === null);
        }

        if ($siteKey === '') {
            $messages[] = ['type' => 'warning', 'text' => (string) __('No site key: the chat widget will not show on the storefront.')];
        } elseif (str_starts_with($siteKey, 'sk_')) {
            $messages[] = ['type' => 'error', 'text' => (string) __('The site key field holds the secret key - remove it from there at once: the site key is public. The site key starts with pk_live_.')];
        } elseif (!str_starts_with($siteKey, 'pk_')) {
            $messages[] = ['type' => 'warning', 'text' => (string) __('The site key usually starts with pk_live_. Check that it was copied whole.')];
        } else {
            $host = (string) parse_url((string) $this->storeManager->getStore($storeId)->getBaseUrl(), PHP_URL_HOST);
            $messages[] = ['type' => 'success', 'text' => (string) __(
                'Site key set. In the AskMerra dashboard, allow the domain %1 for the widget (Install, Allowed domains), or the chat stays hidden.',
                $host
            )];
        }

        if ($syncMethod === Config::SYNC_FEED && $this->config->isFeedEnabled($storeId)) {
            $messages[] = ['type' => 'notice', 'text' => (string) __(
                'Feed URL for AskMerra (Catalog, Add source, Feed URL): %1',
                $this->feedGenerator->getUrl($storeId)
            )];
        }

        return $this->respond($messages);
    }

    private function ping(int $storeId, string $secretKey, ?string $apiUrl, bool $isSaved): array
    {
        try {
            $shop = $this->client->ping($storeId, $secretKey, $apiUrl);
        } catch (ApiException $e) {
            if ($e->isAuthError()) {
                return ['type' => 'error', 'text' => (string) __(
                    'AskMerra refused the secret key (%1). Check that it is copied whole, not revoked, and that the AskMerra shop is active.',
                    $e->getErrorCode()
                )];
            }

            return ['type' => 'error', 'text' => (string) __('AskMerra could not be reached: %1', $e->getMessage())];
        }

        if ($isSaved) {
            $this->problems->clear($storeId);
        }

        $text = (string) __('Connected to the AskMerra shop "%1".', $shop['shopName'] ?: $shop['shopId']);

        if (!$isSaved) {
            $text .= ' ' . __('Save the settings to start using this key.');
        }

        return ['type' => 'success', 'text' => $text];
    }

    /** A value typed in the form; null when the field is inherited or shows the saved, hidden key. */
    private function typed(string $name): ?string
    {
        $value = $this->getRequest()->getParam($name);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\*+$/', $value) ? null : $value;
    }

    private function getStoreId(): int
    {
        $storeId = $this->getRequest()->getParam('store');

        if ($storeId !== null && $storeId !== '') {
            return (int) $storeId;
        }

        $websiteId = $this->getRequest()->getParam('website');

        if ($websiteId !== null && $websiteId !== '') {
            $store = $this->storeManager->getWebsite((int) $websiteId)->getDefaultStore();

            if ($store) {
                return (int) $store->getId();
            }
        }

        return (int) $this->storeManager->getDefaultStoreView()?->getId();
    }

    private function respond(array $messages): Json
    {
        return $this->jsonFactory->create()->setData(['messages' => $messages]);
    }
}
