<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** How a store view's catalog reaches AskMerra. */
class SyncMethod implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'push', 'label' => __('Push API - changes within a minute (recommended)')],
            ['value' => 'feed', 'label' => __('Product feed - AskMerra downloads a file')],
        ];
    }
}
