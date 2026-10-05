<?php

declare(strict_types=1);

namespace AskMerra\Connector\Controller\Adminhtml\Product;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Sync\Enqueuer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Products grid > Actions > "Send to AskMerra now": queues the selected products in every store view
 * that syncs, failed ones included. Cron sends them within a minute.
 */
class MassSync extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'AskMerra_Connector::status';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly Enqueuer $enqueuer,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('catalog/product/index');

        if (!$this->config->getSyncStoreIds()) {
            $this->messageManager->addErrorMessage(__('AskMerra does not sync any store view yet. Check its settings.'));

            return $redirect;
        }

        $ids = $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        $this->enqueuer->enqueue($ids);
        $this->messageManager->addSuccessMessage(__(
            '%1 products were queued for AskMerra; they are sent within a minute (only those whose content changed).',
            count($ids)
        ));

        return $redirect;
    }
}
