<?php

declare(strict_types=1);

namespace AskMerra\Connector\Controller\Adminhtml\Status;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedGenerator;
use AskMerra\Connector\Model\Feed\FeedToken;
use AskMerra\Connector\Model\Sync\Enqueuer;
use AskMerra\Connector\Model\Sync\Problems;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\QueueProcessor;
use AskMerra\Connector\Model\Sync\Reconciler;
use AskMerra\Connector\Model\Sync\Remover;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/** The buttons of the Sync status page. */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'AskMerra_Connector::status';

    /** Seconds "Send changes now" may work within the admin request. */
    private const PROCESS_SECONDS = 25;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly QueueProcessor $queueProcessor,
        private readonly Reconciler $reconciler,
        private readonly Queue $queue,
        private readonly Enqueuer $enqueuer,
        private readonly Problems $problems,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedToken $feedToken,
        private readonly Remover $remover
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $storeId = (int) $this->getRequest()->getParam('store');
        $do = (string) $this->getRequest()->getParam('do');

        try {
            match ($do) {
                'process' => $this->process($storeId),
                'reconcile' => $this->reconcile($storeId),
                'rebuild' => $this->rebuild($storeId),
                'retry' => $this->retry($storeId),
                'feed' => $this->feed($storeId),
                'token' => $this->token(),
                'remove' => $this->remove($storeId),
                'queue_product' => $this->queueProduct($storeId, (int) $this->getRequest()->getParam('product')),
                'clear_problem' => $this->problems->clear($storeId),
                default => $this->messageManager->addErrorMessage(__('Unknown action.')),
            };
        } catch (ApiException $e) {
            $this->messageManager->addErrorMessage(__('AskMerra: %1', $e->getMessage()));
        } catch (\Throwable $e) {
            $this->messageManager->addExceptionMessage($e, __('The action failed: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('askmerra/status/index');
    }

    private function process(int $storeId): void
    {
        $this->requireSync($storeId);
        $stats = $this->queueProcessor->run(self::PROCESS_SECONDS, [$storeId]);

        if ($stats === null) {
            $this->messageManager->addNoticeMessage(__('Cron is sending the queue right now. Check again in a minute.'));

            return;
        }

        $done = ($stats[$storeId] ?? []) + ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];
        $pending = $this->queue->countPending($storeId);

        if (array_sum($done) === 0 && $pending > 0) {
            // Paused (rate limit, AskMerra not answering, key refused) or waiting to be tried again.
            $this->messageManager->addWarningMessage(__(
                'Nothing could be sent right now: AskMerra asked to wait, did not answer or refused the key. %1 products wait and are sent automatically; the latest errors are listed below.',
                $pending
            ));

            return;
        }

        $this->messageManager->addSuccessMessage(__(
            'Sent or updated: %1, unchanged: %2, removed: %3, failed: %4.',
            $done['sent'],
            $done['unchanged'],
            $done['removed'],
            $done['failed']
        ));

        if ($pending > 0) {
            $this->messageManager->addNoticeMessage(__('%1 products are still waiting; cron continues with them.', $pending));
        }
    }

    private function reconcile(int $storeId): void
    {
        $this->requireSync($storeId);
        $stats = $this->reconciler->reconcile($storeId);
        $this->messageManager->addSuccessMessage(__(
            'Daily check done: %1 missing products and %2 products that left the catalog were queued, %3 with special prices starting or ending today.',
            $stats['missing'],
            $stats['gone'],
            $stats['price_dates']
        ));
    }

    private function rebuild(int $storeId): void
    {
        $this->requireSync($storeId);
        $stats = $this->reconciler->rebuild($storeId);
        $this->messageManager->addSuccessMessage(__(
            '%1 products queued for the rebuild. Cron sends those whose content changed within the next minutes.',
            $stats['queued']
        ));
    }

    private function retry(int $storeId): void
    {
        $count = $this->queue->retryFailed($storeId);
        $this->messageManager->addSuccessMessage(__('%1 failed products are tried again.', $count));
    }

    private function feed(int $storeId): void
    {
        $result = $this->feedGenerator->generate($storeId, true);

        match ($result['status']) {
            'written' => $this->messageManager->addSuccessMessage(__('The feed was written: %1 products.', $result['products'])),
            'building' => $this->messageManager->addNoticeMessage($result['message']),
            default => $this->messageManager->addNoticeMessage(__('This store view does not send its catalog as a feed.')),
        };
    }

    private function token(): void
    {
        $this->feedToken->regenerate();
        $this->feedGenerator->generateAll(true);
        $this->messageManager->addSuccessMessage(__('The feeds have new URLs. Update the feed sources in the AskMerra dashboard.'));
    }

    private function remove(int $storeId): void
    {
        if ($this->config->isSyncEnabled($storeId)) {
            $this->messageManager->addErrorMessage(__(
                'Turn AskMerra off for this store view first: otherwise its products are sent again at the next daily check.'
            ));

            return;
        }

        $removed = $this->remover->removeAll($storeId);
        $this->messageManager->addSuccessMessage(__('%1 products were removed from AskMerra.', $removed));
    }

    private function queueProduct(int $storeId, int $productId): void
    {
        $this->requireSync($storeId);
        $this->enqueuer->enqueue([$productId], [$storeId]);
        $this->queueProcessor->run(self::PROCESS_SECONDS, [$storeId]);
        $this->messageManager->addSuccessMessage(__('Product %1 was processed. Preview it again to see the result.', $productId));
    }

    private function requireSync(int $storeId): void
    {
        if (!$this->config->isSyncEnabled($storeId)) {
            throw new \RuntimeException((string) __('AskMerra does not sync this store view. Check its settings.'));
        }
    }
}
