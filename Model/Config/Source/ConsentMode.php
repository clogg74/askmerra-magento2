<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Where the shopper's analytics consent comes from. */
class ConsentMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic (Google Consent Mode / Tag Manager)')],
            ['value' => 'magento', 'label' => __('Magento cookie notice')],
            ['value' => 'granted', 'label' => __('Not needed on this site')],
            ['value' => 'manual', 'label' => __('Manual (set by my cookie banner)')],
        ];
    }
}
