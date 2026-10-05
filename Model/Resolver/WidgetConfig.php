<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Resolver;

use AskMerra\Connector\Model\Config;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/** Query.askMerraWidgetConfig: the widget settings for headless storefronts. */
class WidgetConfig implements ResolverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CookieHelper $cookieHelper
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();

        if (!$this->config->isWidgetEnabled($storeId)) {
            return ['enabled' => false];
        }

        $consent = $this->config->getConsentMode($storeId);

        if ($consent === Config::CONSENT_MAGENTO && !$this->cookieHelper->isCookieRestrictionModeEnabled()) {
            $consent = Config::CONSENT_GRANTED;
        }

        return [
            'enabled' => true,
            'script_url' => $this->config->getWidgetUrl($storeId),
            'site_key' => $this->config->getSiteKey($storeId),
            'locale' => $this->config->getLocale($storeId),
            'position' => $this->config->getWidgetPosition($storeId),
            'open_on_load' => $this->config->isOpenOnLoad($storeId),
            'api_url' => $this->config->getApiUrl($storeId) !== Config::DEFAULT_API_URL
                ? $this->config->getApiUrl($storeId)
                : null,
            'product_identifier' => $this->config->getProductIdentifier($storeId),
            'product_context' => $this->config->isProductContextEnabled($storeId),
            'add_to_cart' => $this->config->isAddToCartEnabled($storeId),
            'after_add_to_cart' => $this->config->getAfterAddToCart($storeId),
            'track_purchases' => $this->config->isPurchaseTrackingEnabled($storeId),
            'consent_mode' => $consent,
        ];
    }
}
