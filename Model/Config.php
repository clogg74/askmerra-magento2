<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model;

use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every setting of the module, read per store view: each store view can point to its own AskMerra
 * shop (one shop has one currency and one site key), with its own locale and sync method.
 */
class Config
{
    /** The languages AskMerra serves (packages/shared/src/locales.ts). */
    public const LOCALES = ['en', 'ro', 'it', 'fr', 'de', 'es'];

    public const SYNC_PUSH = 'push';
    public const SYNC_FEED = 'feed';

    public const IDENTIFIER_ID = 'id';
    public const IDENTIFIER_SKU = 'sku';

    public const FEED_JSON = 'json';
    public const FEED_GOOGLE_XML = 'google_xml';

    public const DESCRIPTION_LONG = 'description';
    public const DESCRIPTION_SHORT = 'short_description';
    public const DESCRIPTION_BOTH = 'both';

    public const CONSENT_AUTO = 'auto';
    public const CONSENT_GRANTED = 'granted';
    public const CONSENT_MAGENTO = 'magento';
    public const CONSENT_MANUAL = 'manual';

    public const AFTER_ADD_STAY = 'stay';
    public const AFTER_ADD_CART = 'cart';

    public const DEFAULT_API_URL = 'https://api.askmerra.com';
    public const DEFAULT_WIDGET_URL = 'https://cdn.askmerra.com/v1/widget.js';

    /** AskMerra accepts at most 500 products per batchUpsert call. */
    public const MAX_BATCH_SIZE = 500;

    private const PATH_ENABLED = 'askmerra/general/enabled';
    private const PATH_SECRET_KEY = 'askmerra/general/secret_key';
    private const PATH_SITE_KEY = 'askmerra/general/site_key';
    private const PATH_LOCALE = 'askmerra/general/locale';

    private const PATH_SYNC_METHOD = 'askmerra/catalog/sync_method';
    private const PATH_IDENTIFIER = 'askmerra/catalog/product_identifier';
    private const PATH_PRODUCT_TYPES = 'askmerra/catalog/product_types';
    private const PATH_VISIBILITY = 'askmerra/catalog/visibility';
    private const PATH_OUT_OF_STOCK = 'askmerra/catalog/include_out_of_stock';
    private const PATH_ATTRIBUTES = 'askmerra/catalog/attributes';
    private const PATH_BRAND = 'askmerra/catalog/brand_attribute';
    private const PATH_DESCRIPTION = 'askmerra/catalog/description_source';
    private const PATH_VARIANT_OPTIONS = 'askmerra/catalog/include_variant_options';
    private const PATH_IMAGE_COUNT = 'askmerra/catalog/image_count';
    private const PATH_STOCK_QTY = 'askmerra/catalog/include_stock_qty';
    private const PATH_EXCLUDED_CATEGORIES = 'askmerra/catalog/excluded_category_ids';

    private const PATH_BATCH_SIZE = 'askmerra/push/batch_size';
    private const PATH_REBUILD = 'askmerra/push/full_sync_frequency';

    private const PATH_FEED_FORMAT = 'askmerra/feed/format';
    public const PATH_FEED_TOKEN = 'askmerra/feed/token';

    private const PATH_WIDGET_ENABLED = 'askmerra/widget/enabled';
    private const PATH_WIDGET_POSITION = 'askmerra/widget/position';
    private const PATH_WIDGET_OPEN = 'askmerra/widget/open_on_load';
    private const PATH_PRODUCT_CONTEXT = 'askmerra/widget/product_context';
    private const PATH_ADD_TO_CART = 'askmerra/widget/add_to_cart';
    private const PATH_AFTER_ADD = 'askmerra/widget/after_add_to_cart';
    private const PATH_TRACK_PURCHASES = 'askmerra/widget/track_purchases';
    private const PATH_CONSENT = 'askmerra/widget/consent_mode';
    private const PATH_SHOW_ON_CHECKOUT = 'askmerra/widget/show_on_checkout';
    private const PATH_WIDGET_URL = 'askmerra/widget/script_url';

    private const PATH_API_URL = 'askmerra/advanced/api_url';
    private const PATH_TIMEOUT = 'askmerra/advanced/timeout';
    private const PATH_DEBUG = 'askmerra/advanced/debug';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_ENABLED, $storeId);
    }

    public function getSecretKey(?int $storeId = null): string
    {
        $value = trim((string) $this->value(self::PATH_SECRET_KEY, $storeId));

        if ($value === '') {
            return '';
        }

        // Saved from the admin the key is encrypted; set through env.php or config:set it may be plain.
        if (str_starts_with($value, 'sk_')) {
            return $value;
        }

        return trim((string) $this->encryptor->decrypt($value));
    }

    public function getSiteKey(?int $storeId = null): string
    {
        return trim((string) $this->value(self::PATH_SITE_KEY, $storeId));
    }

    /**
     * The AskMerra locale of a store view: the one chosen in the admin, else the store's own locale
     * (ro_RO -> ro) when AskMerra serves it, else null - AskMerra then uses the shop's default.
     */
    public function getLocale(?int $storeId = null): ?string
    {
        $chosen = (string) $this->value(self::PATH_LOCALE, $storeId);

        if (in_array($chosen, self::LOCALES, true)) {
            return $chosen;
        }

        $storeLocale = strtolower(substr((string) $this->value('general/locale/code', $storeId), 0, 2));

        return in_array($storeLocale, self::LOCALES, true) ? $storeLocale : null;
    }

    public function getSyncMethod(?int $storeId = null): string
    {
        return $this->value(self::PATH_SYNC_METHOD, $storeId) === self::SYNC_FEED ? self::SYNC_FEED : self::SYNC_PUSH;
    }

    /** Products of this store view are pushed through the Push API. */
    public function isPushEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->getSyncMethod($storeId) === self::SYNC_PUSH
            && $this->getSecretKey($storeId) !== '';
    }

    /** Products of this store view are published as a feed AskMerra downloads. */
    public function isFeedEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getSyncMethod($storeId) === self::SYNC_FEED;
    }

    public function getProductIdentifier(?int $storeId = null): string
    {
        return $this->value(self::PATH_IDENTIFIER, $storeId) === self::IDENTIFIER_SKU
            ? self::IDENTIFIER_SKU
            : self::IDENTIFIER_ID;
    }

    /** @return string[] */
    public function getProductTypes(?int $storeId = null): array
    {
        return $this->csv(self::PATH_PRODUCT_TYPES, $storeId);
    }

    /** @return int[] */
    public function getVisibilities(?int $storeId = null): array
    {
        $values = array_map('intval', $this->csv(self::PATH_VISIBILITY, $storeId));

        return $values ?: [Visibility::VISIBILITY_BOTH, Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_IN_SEARCH];
    }

    public function includeOutOfStock(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_OUT_OF_STOCK, $storeId);
    }

    /** @return string[] attribute codes sent as product attributes */
    public function getAttributeCodes(?int $storeId = null): array
    {
        return $this->csv(self::PATH_ATTRIBUTES, $storeId);
    }

    public function getBrandAttribute(?int $storeId = null): ?string
    {
        $code = trim((string) $this->value(self::PATH_BRAND, $storeId));

        return $code !== '' ? $code : null;
    }

    public function getDescriptionSource(?int $storeId = null): string
    {
        $value = (string) $this->value(self::PATH_DESCRIPTION, $storeId);

        return in_array($value, [self::DESCRIPTION_SHORT, self::DESCRIPTION_BOTH], true) ? $value : self::DESCRIPTION_LONG;
    }

    public function includeVariantOptions(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_VARIANT_OPTIONS, $storeId);
    }

    public function getImageCount(?int $storeId = null): int
    {
        return max(1, min(20, (int) $this->value(self::PATH_IMAGE_COUNT, $storeId) ?: 4));
    }

    public function includeStockQty(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_STOCK_QTY, $storeId);
    }

    /** @return int[] */
    public function getExcludedCategoryIds(?int $storeId = null): array
    {
        $ids = preg_split('/[\s,;]+/', (string) $this->value(self::PATH_EXCLUDED_CATEGORIES, $storeId)) ?: [];

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** How often every product is rebuilt and compared: daily, weekly, monthly or never. */
    public function getRebuildFrequency(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::PATH_REBUILD);

        return in_array($value, ['daily', 'weekly', 'monthly', 'never'], true) ? $value : 'weekly';
    }

    public function getBatchSize(?int $storeId = null): int
    {
        return max(1, min(self::MAX_BATCH_SIZE, (int) $this->value(self::PATH_BATCH_SIZE, $storeId) ?: 200));
    }

    public function getFeedFormat(?int $storeId = null): string
    {
        return $this->value(self::PATH_FEED_FORMAT, $storeId) === self::FEED_GOOGLE_XML
            ? self::FEED_GOOGLE_XML
            : self::FEED_JSON;
    }

    /** Secret part of the feed file names - AskMerra cannot send credentials when it downloads a feed. */
    public function getFeedToken(): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_FEED_TOKEN);
    }

    public function isWidgetEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId)
            && $this->flag(self::PATH_WIDGET_ENABLED, $storeId)
            && $this->getSiteKey($storeId) !== '';
    }

    public function getWidgetPosition(?int $storeId = null): ?string
    {
        $value = (string) $this->value(self::PATH_WIDGET_POSITION, $storeId);

        return in_array($value, ['bottom-right', 'bottom-left'], true) ? $value : null;
    }

    public function isOpenOnLoad(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_WIDGET_OPEN, $storeId);
    }

    public function isProductContextEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_PRODUCT_CONTEXT, $storeId);
    }

    public function isAddToCartEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_ADD_TO_CART, $storeId);
    }

    public function getAfterAddToCart(?int $storeId = null): string
    {
        return $this->value(self::PATH_AFTER_ADD, $storeId) === self::AFTER_ADD_CART ? self::AFTER_ADD_CART : self::AFTER_ADD_STAY;
    }

    public function isPurchaseTrackingEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_TRACK_PURCHASES, $storeId);
    }

    public function getConsentMode(?int $storeId = null): string
    {
        $value = (string) $this->value(self::PATH_CONSENT, $storeId);

        return in_array($value, [self::CONSENT_GRANTED, self::CONSENT_MAGENTO, self::CONSENT_MANUAL], true)
            ? $value
            : self::CONSENT_AUTO;
    }

    /** The chat stays off the checkout page unless asked for: it would distract from paying. */
    public function isShownOnCheckout(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_SHOW_ON_CHECKOUT, $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->value(self::PATH_API_URL, $storeId) ?: self::DEFAULT_API_URL, '/');
    }

    public function getWidgetUrl(?int $storeId = null): string
    {
        return (string) $this->value(self::PATH_WIDGET_URL, $storeId) ?: self::DEFAULT_WIDGET_URL;
    }

    public function getTimeout(): int
    {
        return max(5, (int) $this->scopeConfig->getValue(self::PATH_TIMEOUT) ?: 30);
    }

    public function isDebug(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_DEBUG);
    }

    /** @return int[] active store views whose products are pushed */
    public function getPushStoreIds(): array
    {
        return array_values(array_filter($this->getActiveStoreIds(), fn (int $id) => $this->isPushEnabled($id)));
    }

    /** @return int[] active store views that publish a feed */
    public function getFeedStoreIds(): array
    {
        return array_values(array_filter($this->getActiveStoreIds(), fn (int $id) => $this->isFeedEnabled($id)));
    }

    /** @return int[] active store views whose catalog is kept in sync, by push or feed */
    public function getSyncStoreIds(): array
    {
        return array_values(array_unique(array_merge($this->getPushStoreIds(), $this->getFeedStoreIds())));
    }

    public function isSyncEnabled(?int $storeId = null): bool
    {
        return $this->isPushEnabled($storeId) || $this->isFeedEnabled($storeId);
    }

    /** @return int[] */
    public function getActiveStoreIds(): array
    {
        $ids = [];

        foreach ($this->storeManager->getStores() as $store) {
            if ($store->isActive()) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** @return string[] */
    private function csv(string $path, ?int $storeId): array
    {
        $value = (string) $this->value($path, $storeId);

        return $value === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
