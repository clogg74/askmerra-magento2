<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Catalog\Model\Product\Type;
use Magento\Framework\Data\OptionSourceInterface;

/** The product types of this installation. */
class ProductTypes implements OptionSourceInterface
{
    public function __construct(private readonly Type $productType)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->productType->getOptionArray() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
