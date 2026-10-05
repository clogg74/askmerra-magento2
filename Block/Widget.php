<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block;

use AskMerra\Connector\Model\Catalog\ExternalId;
use AskMerra\Connector\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The AskMerra chat widget on every storefront page: the snippet from the AskMerra dashboard with
 * this store view's site key and language, the product being viewed, and the settings the
 * storefront script (js/storefront.js) needs for the Magento cart, consent and order reporting.
 */
class Widget extends Template
{
    public const SCRIPT_ID = 'askmerra-widget-script';

    /**
     * @param string[] $checkoutActions full action names of checkout pages, or prefixes ending in
     *                                  "*" (etc/frontend/di.xml; other checkout modules can add theirs)
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly ExternalId $externalId,
        private readonly Registry $registry,
        private readonly CookieHelper $cookieHelper,
        private readonly array $checkoutActions = [],
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isVisible(): bool
    {
        $storeId = $this->getStoreId();

        if (!$this->config->isWidgetEnabled($storeId)) {
            return false;
        }

        return $this->config->isShownOnCheckout($storeId) || !$this->isCheckoutPage();
    }

    /** Attributes of the widget's script tag, as in the AskMerra dashboard snippet. */
    public function getScriptAttributes(): array
    {
        $storeId = $this->getStoreId();
        $attributes = [
            'id' => self::SCRIPT_ID,
            'src' => $this->config->getWidgetUrl($storeId),
            'data-site-key' => $this->config->getSiteKey($storeId),
        ];

        $locale = $this->config->getLocale($storeId);

        if ($locale !== null) {
            $attributes['data-locale'] = $locale;
        }

        $position = $this->config->getWidgetPosition($storeId);

        if ($position !== null) {
            $attributes['data-position'] = $position;
        }

        if ($this->config->isOpenOnLoad($storeId)) {
            $attributes['data-open'] = 'true';
        }

        if ($this->config->getApiUrl($storeId) !== Config::DEFAULT_API_URL) {
            $attributes['data-api-url'] = $this->config->getApiUrl($storeId);
        }

        $attributes['async'] = 'async';

        return $attributes;
    }

    /** window.AskMerraSettings: read by the widget when it starts. */
    public function getSettings(): array
    {
        $storeId = $this->getStoreId();
        $settings = [];
        // A single-page app changes product without loading a page; the widget reads productId
        // only when it starts, so it would keep the first one.
        $productId = $this->isSinglePageApp() ? null : $this->getProductExternalId($storeId);

        if ($productId !== null) {
            $settings['productId'] = $productId;
        }

        if ($this->isConsentGranted($storeId)) {
            $settings['consent'] = ['analytics' => true];
        }

        return $settings;
    }

    /** window.AskMerraMagento: read by js/storefront.js. */
    public function getStorefrontConfig(): array
    {
        $storeId = $this->getStoreId();
        $addToCart = $this->config->isAddToCartEnabled($storeId);

        return [
            // A single-page app (ScandiPWA...) adds to its own cart: it reads addToCart and afterAdd.
            'singlePageApp' => $this->isSinglePageApp(),
            'addToCart' => $addToCart,
            'addToCartUrl' => $addToCart && !$this->isSinglePageApp() ? $this->getUrl('askmerra/cart/add') : null,
            'productIdentifier' => $this->config->getProductIdentifier($storeId),
            'afterAdd' => $this->config->getAfterAddToCart($storeId),
            'trackPurchases' => $this->config->isPurchaseTrackingEnabled($storeId),
            // Consent follows Magento's cookie notice: storefront.js watches for the shopper's "Allow".
            'consentCookie' => $this->config->getConsentMode($storeId) === Config::CONSENT_MAGENTO
                && !$this->isConsentGranted($storeId),
            'websiteId' => (int) $this->_storeManager->getStore()->getWebsiteId(),
            'errorMessage' => (string) __('The product could not be added to your cart. Please try again.'),
        ];
    }

    /** js/storefront.js connects the chat to Magento's own pages; a single-page app does it itself. */
    public function getStorefrontScriptUrl(): ?string
    {
        return $this->isSinglePageApp() ? null : $this->getViewFileUrl('AskMerra_Connector::js/storefront.js');
    }

    /**
     * Rendered into a storefront that does not use Magento's layout - a single-page app such as
     * ScandiPWA (see Plugin\View\AddWidgetToHead).
     */
    public function isSinglePageApp(): bool
    {
        return (bool) $this->getData('single_page_app');
    }

    /** JSON safe inside an inline script. */
    public function encodeJson(array $data): string
    {
        return (string) json_encode(
            $data ?: new \stdClass(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    protected function _toHtml()
    {
        return $this->isVisible() ? parent::_toHtml() : '';
    }

    /** A full action name of the list, or one starting like an entry ending in "*". */
    private function isCheckoutPage(): bool
    {
        $action = (string) $this->getRequest()->getFullActionName();

        foreach ($this->checkoutActions as $entry) {
            $entry = (string) $entry;

            if ($action === $entry || (str_ends_with($entry, '*') && str_starts_with($action, rtrim($entry, '*')))) {
                return true;
            }
        }

        return false;
    }

    private function getProductExternalId(int $storeId): ?string
    {
        if (!$this->config->isProductContextEnabled($storeId)) {
            return null;
        }

        $product = $this->registry->registry('current_product');

        return $product instanceof ProductInterface && $product->getId()
            ? $this->externalId->get($product, $storeId)
            : null;
    }

    /**
     * "Not needed", or "Magento cookie notice" on a store without the notice: orders can be
     * reported from the start.
     */
    private function isConsentGranted(int $storeId): bool
    {
        return match ($this->config->getConsentMode($storeId)) {
            Config::CONSENT_GRANTED => true,
            Config::CONSENT_MAGENTO => !$this->cookieHelper->isCookieRestrictionModeEnabled(),
            default => false,
        };
    }

    private function getStoreId(): int
    {
        return (int) $this->_storeManager->getStore()->getId();
    }
}
