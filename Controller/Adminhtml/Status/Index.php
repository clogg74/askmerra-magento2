<?php

declare(strict_types=1);

namespace AskMerra\Connector\Controller\Adminhtml\Status;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/** Marketing > AskMerra > Sync status. */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'AskMerra_Connector::status';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->pageFactory->create();
        $page->setActiveMenu('AskMerra_Connector::status');
        $page->getConfig()->getTitle()->prepend((string) __('AskMerra sync status'));

        return $page;
    }
}
