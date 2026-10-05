<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes that can describe a product to the assistant. Those the module already sends
 * in their own fields (name, prices, stock, images, URL, categories...) and Magento internals are
 * left out.
 */
class ProductAttributes implements OptionSourceInterface
{
    /** Input types whose values can be sent as text, numbers, yes/no or lists. */
    public const SUPPORTED_INPUTS = [
        'text', 'textarea', 'texteditor', 'pagebuilder', 'select', 'multiselect', 'boolean', 'date', 'datetime',
        'price', 'weight',
    ];

    /** Sent in a field of their own, or of no use to a shopping assistant. */
    public const EXCLUDED_CODES = [
        'sku', 'name', 'description', 'short_description', 'price', 'special_price', 'special_from_date',
        'special_to_date', 'cost', 'tier_price', 'minimal_price', 'msrp', 'msrp_display_actual_price_type', 'status',
        'visibility', 'url_key', 'url_path', 'meta_title', 'meta_keyword', 'meta_description', 'image', 'small_image',
        'thumbnail', 'swatch_image', 'media_gallery', 'gallery', 'image_label', 'small_image_label', 'thumbnail_label',
        'news_from_date', 'news_to_date', 'custom_design', 'custom_design_from', 'custom_design_to', 'custom_layout',
        'custom_layout_update', 'custom_layout_update_file', 'page_layout', 'options_container', 'gift_message_available',
        'quantity_and_stock_status', 'category_ids', 'tax_class_id', 'links_purchased_separately', 'links_title',
        'links_exist', 'samples_title', 'shipment_type', 'price_type', 'price_view', 'sku_type', 'weight_type',
        'has_options', 'required_options', 'created_at', 'updated_at', 'askmerra_exclude',
    ];

    private ?array $options = null;

    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    public function toOptionArray(): array
    {
        if ($this->options === null) {
            $this->options = [];
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter('frontend_input', ['in' => static::SUPPORTED_INPUTS])
                ->addFieldToFilter('main_table.attribute_code', ['nin' => self::EXCLUDED_CODES]);

            foreach ($collection as $attribute) {
                $label = (string) ($attribute->getFrontendLabel() ?: $attribute->getAttributeCode());
                $this->options[] = [
                    'value' => $attribute->getAttributeCode(),
                    'label' => sprintf('%s (%s)', $label, $attribute->getAttributeCode()),
                ];
            }

            usort($this->options, static fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
        }

        return $this->options;
    }
}
