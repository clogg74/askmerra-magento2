<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model;

use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Feed\FeedGenerator;
use AskMerra\Connector\Model\Sync\Problems;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\RunLog;
use AskMerra\Connector\Model\Sync\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Everything the status page and bin/magento askmerra:status show about each store view.
 */
class Status
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly Problems $problems,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resource
    ) {
    }

    /** @return array[] one entry per store view */
    public function getStores(): array
    {
        $problems = $this->problems->all();
        $rows = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $mode = match (true) {
                $this->config->isPushEnabled($storeId) => Config::SYNC_PUSH,
                $this->config->isFeedEnabled($storeId) => Config::SYNC_FEED,
                default => null,
            };

            $row = [
                'store_id' => $storeId,
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'website' => $store->getWebsite()->getName(),
                'active' => (bool) $store->isActive(),
                'enabled' => $this->config->isEnabled($storeId),
                'mode' => $mode,
                'missing_key' => $this->config->isEnabled($storeId)
                    && $this->config->getSyncMethod($storeId) === Config::SYNC_PUSH
                    && $this->config->getSecretKey($storeId) === '',
                'widget' => $this->config->isWidgetEnabled($storeId),
                'locale' => $this->config->getLocale($storeId),
                'currency' => $store->getDefaultCurrencyCode(),
                'products' => $this->state->count($storeId),
                'pending' => $this->queue->countPending($storeId),
                'failed' => count($this->queue->getFailedProductIds($storeId)),
                'errors' => $this->queue->getErrors($storeId, 10),
                'last_synced_at' => $this->state->getLastSyncedAt($storeId),
                'last_reconcile' => $this->runLog->getLast($storeId, RunLog::TYPE_RECONCILE),
                'last_rebuild' => $this->runLog->getLast($storeId, RunLog::TYPE_REBUILD),
                'problem' => $problems[$storeId] ?? null,
                'feed' => null,
            ];

            if ($mode === Config::SYNC_FEED) {
                $row['feed'] = $this->feedGenerator->getInfo($storeId) + [
                    'ready' => $this->feedFlags->isReady($storeId),
                    'dirty' => $this->feedFlags->isDirty($storeId),
                    'format' => $this->config->getFeedFormat($storeId),
                    'last_run' => $this->runLog->getLast($storeId, RunLog::TYPE_FEED),
                ];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Warnings about the installation itself.
     *
     * @return string[]
     */
    public function getWarnings(): array
    {
        $warnings = [];

        try {
            if (!$this->indexerRegistry->get('askmerra_products')->isScheduled()) {
                $warnings[] = (string) __(
                    'The "AskMerra product sync" indexer is on "Update on Save": changes made by imports or ERP integrations are missed. Set it to "Update by Schedule" (System > Index Management).'
                );
            }
        } catch (\Throwable) {
            $warnings[] = (string) __('The "AskMerra product sync" indexer is missing: run bin/magento setup:upgrade.');
        }

        if ($this->config->getSyncStoreIds() && !$this->hasRecentCronRun()) {
            $warnings[] = (string) __(
                'Magento cron has not processed the AskMerra queue in the last 15 minutes. Without cron nothing is sent: check that bin/magento cron:run runs every minute.'
            );
        }

        $locales = [];

        foreach ($this->config->getPushStoreIds() as $storeId) {
            $key = $this->config->getSecretKey($storeId) . '|' . $this->config->getLocale($storeId);
            $locales[$key][] = $this->storeManager->getStore($storeId)->getName();
        }

        foreach ($locales as $names) {
            if (count($names) > 1) {
                $warnings[] = (string) __(
                    'Store views %1 send the same language to the same AskMerra shop: each overwrites the other. Give them different languages or different AskMerra shops.',
                    implode(', ', $names)
                );
            }
        }

        foreach ($this->config->getSyncStoreIds() as $storeId) {
            if ($this->config->getLocale($storeId) === null) {
                $warnings[] = (string) __(
                    'Store view "%1" has a language AskMerra does not serve: choose one under AskMerra > Connection > Language.',
                    $this->storeManager->getStore($storeId)->getName()
                );
            }
        }

        return $warnings;
    }

    private function hasRecentCronRun(): bool
    {
        $connection = $this->resource->getConnection();

        try {
            $lastRun = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('cron_schedule'), [new \Zend_Db_Expr('MAX(finished_at)')])
                    ->where('job_code = ?', 'askmerra_process_queue')
                    ->where('status = ?', 'success')
            );
        } catch (\Throwable) {
            return true;
        }

        // cron_schedule times are UTC.
        return $lastRun && strtotime($lastRun . ' UTC') > time() - 900;
    }
}
