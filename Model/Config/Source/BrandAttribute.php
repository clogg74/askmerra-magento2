<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

/** The attribute that holds the brand: a dropdown or a text attribute. */
class BrandAttribute extends ProductAttributes
{
    public const SUPPORTED_INPUTS = ['select', 'text'];

    public function toOptionArray(): array
    {
        return array_merge([['value' => '', 'label' => __('-- No brand --')]], parent::toOptionArray());
    }
}
