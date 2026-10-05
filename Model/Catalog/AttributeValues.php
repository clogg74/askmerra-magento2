<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Product attribute values as AskMerra takes them: under the attribute's store view label, as text,
 * numbers, yes/no or lists of text. Dropdown values become their store view labels.
 */
class AttributeValues
{
    /** AskMerra: keys up to 100 characters; text up to 2,000; lists of up to 50 texts of up to 500. */
    private const MAX_KEY = 100;
    private const MAX_TEXT = 2000;
    private const MAX_LIST = 50;
    private const MAX_LIST_ITEM = 500;

    /** @var array<string, AbstractAttribute|false> */
    private array $attributes = [];

    /** @var array<string, array<string, string>> "storeId:code" => [option id => label] */
    private array $options = [];

    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly TextCleaner $text
    ) {
    }

    /**
     * @param string[] $codes
     * @return array<string, string|int|float|bool|string[]>
     */
    public function getValues(Product $product, array $codes, int $storeId): array
    {
        $values = [];

        foreach ($codes as $code) {
            $attribute = $this->getAttribute($code);

            if ($attribute === null) {
                continue;
            }

            $value = $this->toValue($product, $attribute, $storeId);

            if ($value === null) {
                continue;
            }

            $key = $this->getLabel($attribute, $storeId);

            if (isset($values[$key])) {
                $key = $this->text->limit($key . ' (' . $code . ')', self::MAX_KEY);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /** A single text value, e.g. the brand. */
    public function getText(Product $product, string $code, int $storeId, int $maxLength): ?string
    {
        $attribute = $this->getAttribute($code);
        $value = $attribute ? $this->toValue($product, $attribute, $storeId) : null;

        if (is_array($value)) {
            $value = implode(', ', $value);
        }

        return is_string($value) || is_int($value) || is_float($value)
            ? $this->text->toLine((string) $value, $maxLength)
            : null;
    }

    /** The attribute's label in the store view, as the key of its value. */
    public function getLabel(AbstractAttribute $attribute, int $storeId): string
    {
        $label = (string) ($attribute->getStoreLabel($storeId) ?: $attribute->getFrontendLabel() ?: $attribute->getAttributeCode());

        return $this->text->limit((string) $this->text->toLine($label, self::MAX_KEY), self::MAX_KEY);
    }

    public function getAttribute(string $code): ?AbstractAttribute
    {
        if (!array_key_exists($code, $this->attributes)) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            $this->attributes[$code] = $attribute && $attribute->getId() ? $attribute : false;
        }

        return $this->attributes[$code] ?: null;
    }

    /**
     * @return string|int|float|bool|string[]|null
     */
    private function toValue(Product $product, AbstractAttribute $attribute, int $storeId): mixed
    {
        $raw = $product->getData($attribute->getAttributeCode());

        if ($raw === null || $raw === '' || $raw === false || $raw === []) {
            return null;
        }

        switch ($attribute->getFrontendInput()) {
            case 'boolean':
                return (bool) $raw;

            case 'select':
                if ($attribute->usesSource()) {
                    $label = $this->getOptionLabels($attribute, $storeId)[(string) $raw] ?? null;

                    return $label === null ? null : $this->text->toLine($label, self::MAX_TEXT);
                }

                return $this->text->toLine((string) $raw, self::MAX_TEXT);

            case 'multiselect':
                $labels = $this->getOptionLabels($attribute, $storeId);
                $list = [];

                foreach (is_array($raw) ? $raw : explode(',', (string) $raw) as $optionId) {
                    $label = $labels[trim((string) $optionId)] ?? null;

                    if ($label !== null && $label !== '') {
                        $list[] = (string) $this->text->toLine($label, self::MAX_LIST_ITEM);
                    }
                }

                $list = array_slice(array_values(array_unique(array_filter($list))), 0, self::MAX_LIST);

                return $list ?: null;

            case 'price':
            case 'weight':
                return is_numeric($raw) ? round((float) $raw, 4) : null;

            case 'date':
            case 'datetime':
                return substr((string) $raw, 0, 10);

            default:
                return $this->text->toPlainText(is_array($raw) ? implode(', ', $raw) : (string) $raw, self::MAX_TEXT);
        }
    }

    /** @return array<string, string> */
    private function getOptionLabels(AbstractAttribute $attribute, int $storeId): array
    {
        $key = $storeId . ':' . $attribute->getAttributeCode();

        if (!isset($this->options[$key])) {
            $this->options[$key] = [];
            $attribute->setStoreId($storeId);

            try {
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    if (isset($option['value']) && !is_array($option['value'])) {
                        $this->options[$key][(string) $option['value']] = (string) $option['label'];
                    }
                }
            } catch (\Throwable) {
                // A broken source model leaves the attribute out rather than stopping the sync.
            }
        }

        return $this->options[$key];
    }
}
