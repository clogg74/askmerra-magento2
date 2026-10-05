<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** How many images per product are sent (AskMerra keeps up to 20). */
class ImageCount implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];

        foreach ([1, 2, 3, 4, 6, 8, 10] as $count) {
            $options[] = ['value' => (string) $count, 'label' => (string) $count];
        }

        return $options;
    }
}
