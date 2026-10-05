<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** A settings row that shows information or a button rather than a value of its own. */
abstract class AbstractButton extends Field
{
    public function render(AbstractElement $element)
    {
        // No "Use default" checkbox or scope label: there is nothing to inherit.
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /** The store view ids of the scope being edited: one store view, a website's, or all. */
    protected function getScopeStoreIds(): array
    {
        $storeId = $this->getRequest()->getParam('store');

        if ($storeId !== null && $storeId !== '') {
            return [(int) $storeId];
        }

        $websiteId = $this->getRequest()->getParam('website');
        $ids = [];

        foreach ($this->_storeManager->getStores() as $store) {
            if ($websiteId === null || $websiteId === '' || (int) $store->getWebsiteId() === (int) $websiteId) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }

    /** The current scope as URL parameters. */
    protected function getScopeParams(): array
    {
        return array_filter([
            'website' => $this->getRequest()->getParam('website'),
            'store' => $this->getRequest()->getParam('store'),
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
