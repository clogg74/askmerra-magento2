<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** How often every product is rebuilt and compared. */
class FullSyncFrequency implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'weekly', 'label' => __('Every week, on Sunday (recommended)')],
            ['value' => 'daily', 'label' => __('Every day')],
            ['value' => 'monthly', 'label' => __('Every month, on the 1st')],
            ['value' => 'never', 'label' => __('Only when started by hand')],
        ];
    }
}
