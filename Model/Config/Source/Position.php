<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Where the chat button sits. */
class Position implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('As set in the AskMerra designer')],
            ['value' => 'bottom-right', 'label' => __('Bottom right')],
            ['value' => 'bottom-left', 'label' => __('Bottom left')],
        ];
    }
}
