<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block\Adminhtml;

use AskMerra\Connector\Model\Catalog\ProductBuilder;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Status as StatusModel;
use AskMerra\Connector\Model\Sync\QueueProcessor;
use AskMerra\Connector\Model\Sync\State;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

/** The Sync status page: every store view's sync, the latest errors, actions and a payload preview. */
class Status extends Template
{
    private ?array $stores = null;

    public function __construct(
        Context $context,
        private readonly StatusModel $status,
        private readonly Config $config,
        private readonly ProductBuilder $productBuilder,
        private readonly ProductResource $productResource,
        private readonly State $state,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStores(): array
    {
        return $this->stores ??= $this->status->getStores();
    }

    public function getWarnings(): array
    {
        return $this->status->getWarnings();
    }

    public function hasFeedStores(): bool
    {
        foreach ($this->getStores() as $store) {
            if ($store['mode'] === Config::SYNC_FEED) {
                return true;
            }
        }

        return false;
    }

    public function getActionUrl(): string
    {
        return $this->getUrl('askmerra/status/run');
    }

    public function getSettingsUrl(?int $storeId = null): string
    {
        return $this->getUrl('adminhtml/system_config/edit', array_filter([
            'section' => 'askmerra',
            'store' => $storeId,
        ]));
    }

    public function getProductUrl(int $productId): string
    {
        return $this->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    /** A UTC database time in the admin's time zone and locale. */
    public function formatUtc(?string $utc): string
    {
        if (!$utc) {
            return (string) __('never');
        }

        return $this->_localeDate->formatDateTime(
            new \DateTime($utc, new \DateTimeZone('UTC')),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /** "key: value" pairs of a run's statistics. */
    public function formatStats(?array $run): string
    {
        if (!$run) {
            return '';
        }

        $parts = [];

        foreach ((array) $run['stats'] as $key => $value) {
            $parts[] = $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
        }

        return implode(', ', $parts) . ($run['message'] ? ' - ' . $run['message'] : '');
    }

    public function getPreviewQuery(): string
    {
        return trim((string) $this->getRequest()->getParam('preview'));
    }

    public function getPreviewStoreId(): ?int
    {
        $storeId = $this->getRequest()->getParam('preview_store');

        if ($storeId !== null && $storeId !== '') {
            return (int) $storeId;
        }

        $syncing = $this->config->getSyncStoreIds();

        return $syncing ? (int) reset($syncing) : null;
    }

    /**
     * What the product looks like to AskMerra in a store view, built now.
     *
     * @return array{title: string, product_id?: ?int, json?: string, reason?: string, sent?: ?string}|null
     */
    public function getPreview(): ?array
    {
        $query = $this->getPreviewQuery();
        $storeId = $this->getPreviewStoreId();

        if ($query === '' || $storeId === null) {
            return null;
        }

        $productId = ctype_digit($query) ? (int) $query : (int) $this->productResource->getIdBySku($query);

        if (!$productId) {
            return ['title' => (string) __('No product with the ID or SKU "%1".', $query)];
        }

        $built = $this->productBuilder->build($storeId, [$productId]);
        $sent = $this->state->get($storeId, [$productId])[$productId] ?? null;

        if (isset($built['payloads'][$productId])) {
            $payload = $built['payloads'][$productId];

            return [
                'product_id' => $this->config->isSyncEnabled($storeId) ? $productId : null,
                'title' => (string) __('Product %1 as AskMerra receives it:', $productId),
                'json' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sent' => $sent === null
                    ? (string) __('Not in AskMerra yet.')
                    : ($sent['payload_hash'] === QueueProcessor::hash($payload)
                        ? (string) __('AskMerra has this version.')
                        : (string) __('AskMerra has an older version; the change is sent by the queue.')),
            ];
        }

        return [
            'product_id' => $sent !== null && $this->config->isSyncEnabled($storeId) ? $productId : null,
            'title' => (string) __('Product %1 is not sent to AskMerra in this store view.', $productId),
            'reason' => (string) ($built['ineligible'][$productId] ?? $built['errors'][$productId] ?? ''),
            'sent' => $sent === null ? null : (string) __('It is still in AskMerra and is removed by the queue.'),
        ];
    }
}
