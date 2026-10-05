<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

use AskMerra\Connector\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

/**
 * The id a product has in AskMerra: its Magento product id, or its SKU (setting "Product
 * identifier"). The same value goes into the catalog, the product page context, add to cart and
 * the order report, so AskMerra can match them all.
 */
class ExternalId
{
    public function __construct(
        private readonly Config $config,
        private readonly ProductResource $productResource
    ) {
    }

    public function get(ProductInterface $product, int $storeId): string
    {
        return $this->config->getProductIdentifier($storeId) === Config::IDENTIFIER_SKU
            ? (string) $product->getSku()
            : (string) $product->getId();
    }

    /** The Magento product id behind an AskMerra id, or null when there is none. */
    public function toProductId(string $externalId, int $storeId): ?int
    {
        $externalId = trim($externalId);

        if ($externalId === '') {
            return null;
        }

        if ($this->config->getProductIdentifier($storeId) === Config::IDENTIFIER_SKU) {
            $id = $this->productResource->getIdBySku($externalId);

            return $id ? (int) $id : null;
        }

        return ctype_digit($externalId) ? (int) $externalId : null;
    }
}
