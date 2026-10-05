<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** How often the feed files are regenerated, in minutes. */
class FeedFrequency implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '60', 'label' => __('Every hour')],
            ['value' => '180', 'label' => __('Every 3 hours')],
            ['value' => '360', 'label' => __('Every 6 hours')],
            ['value' => '480', 'label' => __('Every 8 hours')],
            ['value' => '720', 'label' => __('Every 12 hours')],
            ['value' => '1440', 'label' => __('Once a day')],
        ];
    }
}
