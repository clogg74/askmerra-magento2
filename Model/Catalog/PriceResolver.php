<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Store\Model\Store;

/**
 * The price a guest sees in the store view - catalog price rules, special prices and the tax
 * display setting included - in the store view's currency. Must run inside a frontend emulation of
 * the store view (ProductBuilder does that).
 */
class PriceResolver
{
    /** Product types priced "from" their cheapest option. */
    private const FROM_PRICE_TYPES = ['configurable', 'bundle', 'grouped'];

    /**
     * @return array{price: ?float, sale_price: ?float}
     */
    public function resolve(Product $product, Store $store): array
    {
        try {
            $priceInfo = $product->getPriceInfo();
            $final = $priceInfo->getPrice(FinalPrice::PRICE_CODE);
            $regular = $priceInfo->getPrice(RegularPrice::PRICE_CODE);

            if (in_array($product->getTypeId(), self::FROM_PRICE_TYPES, true)) {
                $finalValue = (float) $final->getMinimalPrice()->getValue();
                $regularValue = match (true) {
                    method_exists($regular, 'getMinRegularAmount') => (float) $regular->getMinRegularAmount()->getValue(),
                    method_exists($regular, 'getMinimalPrice') => (float) $regular->getMinimalPrice()->getValue(),
                    default => (float) $regular->getAmount()->getValue(),
                };
            } else {
                $finalValue = (float) $final->getAmount()->getValue();
                $regularValue = (float) $regular->getAmount()->getValue();
            }
        } catch (\Throwable) {
            return ['price' => null, 'sale_price' => null];
        }

        $finalValue = $this->convert($finalValue, $store);
        $regularValue = $this->convert($regularValue, $store);

        if ($regularValue <= 0) {
            $regularValue = $finalValue;
        }

        if ($regularValue <= 0) {
            return ['price' => null, 'sale_price' => null];
        }

        return [
            'price' => $regularValue,
            // AskMerra shows a sale only below the regular price.
            'sale_price' => $finalValue > 0 && $finalValue < $regularValue - 0.004 ? $finalValue : null,
        ];
    }

    /** Base currency to the store view's default display currency, rounded to cents. */
    private function convert(float $amount, Store $store): float
    {
        $base = $store->getBaseCurrency();
        $target = (string) $store->getDefaultCurrencyCode();

        if ($base && $target !== '' && $base->getCode() !== $target) {
            try {
                $amount = (float) $base->convert($amount, $target);
            } catch (\Throwable) {
                // No rate for this currency: the base amount is the best there is.
            }
        }

        return round($amount, 2);
    }
}
