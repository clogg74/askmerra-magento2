<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatus;
use Magento\Framework\App\Area;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns Magento products into AskMerra products (the ProductInput shape of the Push API, also used
 * for the JSON feed) for one store view: its names, prices, currency, stock, URLs, categories and
 * language.
 *
 * One product is one AskMerra product. Variants of a configurable product are not sent on their own
 * (they are "Not visible individually"); their options become attributes of the configurable one,
 * as AskMerra's own Shopify connector does.
 */
class ProductBuilder
{
    public const EXCLUDE_ATTRIBUTE = 'askmerra_exclude';

    private const BASE_ATTRIBUTES = [
        'name', 'description', 'short_description', 'url_key', 'image', 'small_image', 'status', 'visibility',
        'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id', 'price_type', 'price_view',
        'links_purchased_separately',
    ];

    private const QTY_TYPES = ['simple', 'virtual', 'downloadable'];

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $collectionFactory,
        private readonly StockStatus $stockStatus,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryPaths $categoryPaths,
        private readonly AttributeValues $attributeValues,
        private readonly PriceResolver $priceResolver,
        private readonly ExternalId $externalId,
        private readonly TextCleaner $text,
        private readonly Logger $logger
    ) {
    }

    /**
     * Builds the products of a store view.
     *
     * - payloads: products to send, keyed by product id
     * - ineligible: products that must not be in AskMerra (anymore), with the reason
     * - errors: products that could not be built this time; they are tried again, never removed
     *
     * @param int[] $productIds
     * @return array{payloads: array<int, array>, ineligible: array<int, string>, errors: array<int, string>}
     */
    public function build(int $storeId, array $productIds): array
    {
        $result = ['payloads' => [], 'ineligible' => [], 'errors' => []];
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$productIds) {
            return $result;
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            /** @var Store $store */
            $store = $this->storeManager->getStore($storeId);
            $found = [];

            foreach ($this->loadProducts($storeId, $productIds) as $product) {
                $productId = (int) $product->getId();
                $found[$productId] = true;

                try {
                    $reason = $this->getIneligibleReason($product, $storeId);

                    if ($reason !== null) {
                        $result['ineligible'][$productId] = $reason;
                    } else {
                        $result['payloads'][$productId] = $this->buildPayload($product, $store);
                    }
                } catch (\Throwable $e) {
                    $result['errors'][$productId] = $e->getMessage();
                    $this->logger->error(sprintf('Product %d, store %d: %s', $productId, $storeId, $e->getMessage()));
                }
            }

            foreach ($productIds as $productId) {
                if (!isset($found[$productId])) {
                    $result['ineligible'][$productId] = (string) __(
                        'Not offered in this store view: deleted, disabled, not visible, not in its website or of a product type that is not sent.'
                    );
                }
            }
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        return $result;
    }

    /**
     * Ids of the products a store view may send; the finer checks (excluded categories, stock)
     * run in build().
     *
     * @return int[]
     */
    public function getCandidateIds(int $storeId): array
    {
        $collection = $this->createCollection($storeId);

        if ($this->attributeValues->getAttribute(self::EXCLUDE_ATTRIBUTE)) {
            $collection->addAttributeToFilter(self::EXCLUDE_ATTRIBUTE, [['null' => true], ['neq' => 1]], 'left');
        }

        return array_map('intval', $collection->getAllIds());
    }

    /**
     * Whether each product a store view may send can be bought now, as the storefront sees it
     * (MSI salable quantity included). One query, for the stock check.
     *
     * @return array<int, bool>
     */
    public function getStockFlags(int $storeId): array
    {
        $collection = $this->createCollection($storeId);
        $this->stockStatus->addStockDataToCollection($collection, false);

        $flags = [];
        $statement = $collection->getConnection()->query($collection->getSelect());

        while ($row = $statement->fetch()) {
            if (array_key_exists('is_salable', $row)) {
                $flags[(int) $row['entity_id']] = (bool) $row['is_salable'];
            }
        }

        return $flags;
    }

    /** @return Collection loaded with everything a payload needs */
    private function loadProducts(int $storeId, array $productIds): Collection
    {
        $attributes = array_merge(
            self::BASE_ATTRIBUTES,
            $this->config->getAttributeCodes($storeId),
            array_filter([$this->config->getBrandAttribute($storeId)])
        );

        if ($this->attributeValues->getAttribute(self::EXCLUDE_ATTRIBUTE)) {
            $attributes[] = self::EXCLUDE_ATTRIBUTE;
        }

        $collection = $this->createCollection($storeId)
            ->addIdFilter($productIds)
            ->addAttributeToSelect(array_values(array_unique($attributes)))
            ->addUrlRewrite();

        // Adds is_salable for the website's stock (MSI adapts this call to its own stock index).
        $this->stockStatus->addStockDataToCollection($collection, false);

        $collection->load();
        $collection->addCategoryIds();
        $collection->addMediaGalleryData();

        return $collection;
    }

    private function createCollection(int $storeId): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['in' => $this->config->getVisibilities($storeId)]);

        $types = $this->config->getProductTypes($storeId);

        if ($types) {
            $collection->addAttributeToFilter('type_id', ['in' => $types]);
        }

        return $collection;
    }

    private function getIneligibleReason(Product $product, int $storeId): ?string
    {
        if ((int) $product->getData(self::EXCLUDE_ATTRIBUTE) === 1) {
            return (string) __('"Hide from AskMerra" is set on the product.');
        }

        $categoryIds = array_map('intval', (array) $product->getCategoryIds());

        if ($this->categoryPaths->isExcluded($categoryIds, $this->config->getExcludedCategoryIds($storeId), $storeId)) {
            return (string) __('It is in an excluded category.');
        }

        if (!$this->config->includeOutOfStock($storeId) && !$this->isInStock($product)) {
            return (string) __('It is out of stock, and out-of-stock products are not sent.');
        }

        if ($this->text->toLine((string) $product->getName(), 500) === null) {
            return (string) __('It has no name in this store view.');
        }

        return null;
    }

    private function buildPayload(Product $product, Store $store): array
    {
        $storeId = (int) $store->getId();
        $prices = $this->priceResolver->resolve($product, $store);
        $brandCode = $this->config->getBrandAttribute($storeId);
        $updatedAt = (string) $product->getData('updated_at');

        $attributes = $this->attributeValues->getValues($product, $this->config->getAttributeCodes($storeId), $storeId);

        if ($product->getTypeId() === 'configurable' && $this->config->includeVariantOptions($storeId)) {
            $attributes = array_merge($attributes, $this->getVariantOptions($product, $storeId));
        }

        return [
            'external_id' => $this->externalId->get($product, $storeId),
            'sku' => $this->text->toLine((string) $product->getSku(), 255),
            'parent_external_id' => null,
            'name' => (string) $this->text->toLine((string) $product->getName(), 500),
            'description' => $this->getDescription($product, $storeId),
            'url' => $this->getProductUrl($product, $store),
            'image_urls' => $this->getImageUrls($product, $store),
            'price' => $prices['price'],
            'sale_price' => $prices['sale_price'],
            'currency' => strtoupper((string) $store->getDefaultCurrencyCode()) ?: null,
            'in_stock' => $this->isInStock($product),
            'stock_qty' => $this->getStockQty($product, $store),
            'categories' => $this->categoryPaths->getPaths(array_map('intval', (array) $product->getCategoryIds()), $storeId),
            'brand' => $brandCode ? $this->attributeValues->getText($product, $brandCode, $storeId, 255) : null,
            // An object even when empty: AskMerra expects {"key": value}, never [].
            'attributes' => $attributes ?: new \stdClass(),
            'locale' => $this->config->getLocale($storeId),
            'source_updated_at' => $updatedAt !== ''
                ? (new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC')))->format(DATE_ATOM)
                : null,
        ];
    }

    private function getDescription(Product $product, int $storeId): ?string
    {
        $short = (string) $product->getData('short_description');
        $long = (string) $product->getData('description');

        $html = match ($this->config->getDescriptionSource($storeId)) {
            Config::DESCRIPTION_SHORT => $short ?: $long,
            Config::DESCRIPTION_BOTH => trim($short . "\n\n" . $long),
            default => $long ?: $short,
        };

        return $this->text->toPlainText($html, 20000);
    }

    /**
     * The product's storefront URL, built from its URL rewrite: getProductUrl() would use the URL
     * builder of the area it runs in, which is the admin's when a sync is started from there.
     */
    private function getProductUrl(Product $product, Store $store): ?string
    {
        $path = ltrim((string) $product->getData('request_path'), '/');

        // URL keys can hold spaces or non-ASCII letters; encode them, keep what is already encoded.
        $path = (string) preg_replace_callback(
            '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@/%]#u',
            static fn (array $match) => rawurlencode($match[0]),
            $path
        );

        return $this->toUrl(
            $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isFrontUrlSecure())
            . ($path !== '' ? $path : 'catalog/product/view/id/' . (int) $product->getId())
        );
    }

    /** @return string[] the main image first, then the gallery in its order */
    private function getImageUrls(Product $product, Store $store): array
    {
        $files = [];
        $main = (string) $product->getData('image');

        if ($main !== '' && $main !== 'no_selection') {
            $files[] = $main;
        }

        $gallery = $product->getMediaGalleryImages();

        if ($gallery) {
            foreach ($gallery as $image) {
                $files[] = (string) $image->getData('file');
            }
        }

        $baseUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, $store->isFrontUrlSecure()), '/') . '/catalog/product/';
        $urls = [];

        foreach (array_unique(array_filter($files)) as $file) {
            $path = implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/'))));
            $url = $this->toUrl($baseUrl . $path);

            if ($url !== null) {
                $urls[] = $url;
            }

            if (count($urls) >= $this->config->getImageCount((int) $store->getId())) {
                break;
            }
        }

        return $urls;
    }

    /**
     * The options a shopper can pick on a configurable product, e.g. {"Size": ["S", "M"]}.
     *
     * @return array<string, string[]>
     */
    private function getVariantOptions(Product $product, int $storeId): array
    {
        try {
            $options = $product->getTypeInstance()->getConfigurableOptions($product);
        } catch (\Throwable) {
            return [];
        }

        $result = [];

        foreach ($options as $rows) {
            $code = (string) ($rows[0]['attribute_code'] ?? '');
            $attribute = $code !== '' ? $this->attributeValues->getAttribute($code) : null;
            $label = $attribute
                ? $this->attributeValues->getLabel($attribute, $storeId)
                : $this->text->toLine((string) ($rows[0]['super_attribute_label'] ?? ''), 100);
            $values = [];

            foreach ($rows as $row) {
                $value = $this->text->toLine((string) ($row['option_title'] ?? $row['default_title'] ?? ''), 500);

                if ($value !== null) {
                    $values[] = $value;
                }
            }

            if ($label && $values) {
                $result[$label] = array_slice(array_values(array_unique($values)), 0, 50);
            }
        }

        return $result;
    }

    private function isInStock(Product $product): bool
    {
        $salable = $product->getData('is_salable');

        return $salable !== null ? (bool) $salable : (bool) $product->isSalable();
    }

    private function getStockQty(Product $product, Store $store): ?int
    {
        if (!$this->config->includeStockQty((int) $store->getId()) || !in_array($product->getTypeId(), self::QTY_TYPES, true)) {
            return null;
        }

        try {
            $qty = $this->stockRegistry->getStockItem((int) $product->getId(), (int) $store->getWebsiteId())->getQty();
        } catch (\Throwable) {
            return null;
        }

        return $qty === null ? null : max(0, (int) floor((float) $qty));
    }

    /** AskMerra accepts only http(s) URLs of up to 2,048 characters. */
    private function toUrl(string $url): ?string
    {
        $url = trim($url);

        return preg_match('#^https?://#i', $url) && strlen($url) <= 2048 ? $url : null;
    }
}
