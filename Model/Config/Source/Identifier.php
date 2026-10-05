<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** What identifies a product in AskMerra. */
class Identifier implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'id', 'label' => __('Product ID (recommended)')],
            ['value' => 'sku', 'label' => __('SKU')],
        ];
    }
}
