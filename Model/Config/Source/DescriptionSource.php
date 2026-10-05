<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Which Magento text becomes the product description. */
class DescriptionSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'description', 'label' => __('Description')],
            ['value' => 'short_description', 'label' => __('Short description')],
            ['value' => 'both', 'label' => __('Short description, then description')],
        ];
    }
}
