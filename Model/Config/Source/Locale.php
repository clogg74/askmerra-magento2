<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** The languages AskMerra serves. */
class Locale implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Automatic (from the store view locale)')],
            ['value' => 'en', 'label' => __('English')],
            ['value' => 'ro', 'label' => __('Romanian')],
            ['value' => 'it', 'label' => __('Italian')],
            ['value' => 'fr', 'label' => __('French')],
            ['value' => 'de', 'label' => __('German')],
            ['value' => 'es', 'label' => __('Spanish')],
        ];
    }
}
