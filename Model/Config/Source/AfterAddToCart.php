<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** What happens after the chat adds a product to the cart. */
class AfterAddToCart implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'stay', 'label' => __('Stay on the page (mini cart refreshes)')],
            ['value' => 'cart', 'label' => __('Go to the cart page')],
        ];
    }
}
