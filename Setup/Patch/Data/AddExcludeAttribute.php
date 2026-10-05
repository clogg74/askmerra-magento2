<?php

declare(strict_types=1);

namespace AskMerra\Connector\Setup\Patch\Data;

use AskMerra\Connector\Model\Catalog\ProductBuilder;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/** "Hide from AskMerra" on the product page (AskMerra group), per store view. */
class AddExcludeAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(Product::ENTITY, ProductBuilder::EXCLUDE_ATTRIBUTE, [
            'type' => 'int',
            'label' => 'Hide from AskMerra',
            'input' => 'boolean',
            'source' => Boolean::class,
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => 'AskMerra',
            'sort_order' => 10,
            'default' => '0',
            'required' => false,
            'user_defined' => false,
            'visible' => true,
            'visible_on_front' => false,
            'used_in_product_listing' => false,
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => false,
            'is_filterable_in_grid' => true,
            'apply_to' => '',
            'note' => 'The AI shopping assistant will not know or recommend this product.',
        ]);

        return $this;
    }

    public function revert(): void
    {
        $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup])
            ->removeAttribute(Product::ENTITY, ProductBuilder::EXCLUDE_ATTRIBUTE);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
