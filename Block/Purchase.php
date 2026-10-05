<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block;

use AskMerra\Connector\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;

/**
 * The order on the success page, for AskMerra's sales attribution: order number, total, currency
 * and products (GA4 "purchase" shape). No name, e-mail or address. Products are identified the way
 * the catalog sends them, so AskMerra can tell which ones the assistant recommended.
 */
class Purchase extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductResource $productResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getPurchase(): ?array
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();

        if (!$this->config->isWidgetEnabled($storeId) || !$this->config->isPurchaseTrackingEnabled($storeId)) {
            return null;
        }

        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order instanceof Order || !$order->getId() || (int) $order->getStoreId() !== $storeId) {
            return null;
        }

        $visibleItems = $order->getAllVisibleItems();
        $useSku = $this->config->getProductIdentifier($storeId) === Config::IDENTIFIER_SKU;
        $skus = $useSku ? $this->getSkus($visibleItems) : [];
        $items = [];

        foreach ($visibleItems as $item) {
            $productId = $this->getAskMerraProductId($item);
            $qty = (float) $item->getQtyOrdered();

            if ($qty <= 0) {
                continue;
            }

            // A configurable's order line carries the chosen variant's SKU; AskMerra knows the
            // configurable itself.
            $id = $useSku ? ($skus[$productId] ?? (string) $item->getSku()) : (string) $productId;
            $paid = (float) $item->getRowTotalInclTax() - (float) $item->getDiscountAmount();

            $items[] = [
                'item_id' => $id,
                // A grouped product's part goes by the grouped product's name, like its id.
                'item_name' => $productId === (int) $item->getProductId() ? (string) $item->getName() : $this->getName($productId, $storeId, (string) $item->getName()),
                'price' => round(max(0.0, $paid) / $qty, 2),
                'quantity' => (int) ceil($qty),
            ];
        }

        return [
            'transaction_id' => (string) $order->getIncrementId(),
            'value' => round((float) $order->getGrandTotal(), 2),
            'currency' => (string) $order->getOrderCurrencyCode(),
            'tax' => round((float) $order->getTaxAmount(), 2),
            'shipping' => round((float) $order->getShippingAmount(), 2),
            'items' => $items,
        ];
    }

    /** JSON safe inside an inline script. */
    public function encodeJson(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * The product AskMerra knows for an order line: the line's product (a configurable's line carries
     * the configurable), or the grouped product a product was bought from - its products are sold
     * through it and are usually not sent on their own.
     */
    private function getAskMerraProductId(Order\Item $item): int
    {
        $grouped = $item->getProductOptionByCode('super_product_config');

        if (is_array($grouped) && ($grouped['product_type'] ?? null) === 'grouped' && (int) ($grouped['product_id'] ?? 0) > 0) {
            return (int) $grouped['product_id'];
        }

        return (int) $item->getProductId();
    }

    private function getName(int $productId, int $storeId, string $fallback): string
    {
        $name = $this->productResource->getAttributeRawValue($productId, 'name', $storeId);

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    /** @return array<int, string> product id => SKU of the product itself (not the chosen variant) */
    private function getSkus(array $items): array
    {
        $ids = array_values(array_unique(array_map(fn ($item) => $this->getAskMerraProductId($item), $items)));
        $skus = [];

        foreach ($ids ? $this->productResource->getProductsSku($ids) : [] as $row) {
            $skus[(int) $row['entity_id']] = (string) $row['sku'];
        }

        return $skus;
    }
}
