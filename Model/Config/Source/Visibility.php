<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Which product visibilities are sent; "Not visible individually" products (variants) never are. */
class Visibility implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '4', 'label' => __('Catalog, Search')],
            ['value' => '2', 'label' => __('Catalog')],
            ['value' => '3', 'label' => __('Search')],
        ];
    }
}
