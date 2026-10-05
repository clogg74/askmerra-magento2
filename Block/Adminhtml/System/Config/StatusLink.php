<?php

declare(strict_types=1);

namespace AskMerra\Connector\Block\Adminhtml\System\Config;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\State;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** A one-line sync summary of the scope, with the way to the Sync status page. */
class StatusLink extends AbstractButton
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly State $state,
        private readonly Queue $queue,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $products = 0;
        $pending = 0;
        $failed = 0;

        foreach ($this->getScopeStoreIds() as $storeId) {
            if ($this->config->isSyncEnabled($storeId)) {
                $products += $this->state->count($storeId);
                $pending += $this->queue->countPending($storeId);
                $failed += count($this->queue->getFailedProductIds($storeId));
            }
        }

        $summary = __('%1 products sent, %2 waiting, %3 failed.', $products, $pending, $failed);

        return sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            $this->_escaper->escapeHtml((string) $summary),
            $this->_escaper->escapeUrl($this->getUrl('askmerra/status/index')),
            $this->_escaper->escapeHtml((string) __('Open the sync status: errors, full rebuild, payload preview'))
        );
    }
}
