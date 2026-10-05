<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Format of the product feed. */
class FeedFormat implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'json', 'label' => __('JSON - all attributes (recommended)')],
            ['value' => 'google_xml', 'label' => __('Google Shopping XML')],
        ];
    }
}
