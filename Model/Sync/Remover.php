<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Feed\FeedGenerator;

/**
 * Takes a store view's catalog out of AskMerra: every product it pushed is removed (AskMerra
 * deactivates them), and its queue, state and feed file are cleared. A feed source has to be
 * removed in the AskMerra dashboard as well - AskMerra keeps a feed's products when the feed
 * comes back empty.
 */
class Remover
{
    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly RunLog $runLog,
        private readonly QueueProcessor $queueProcessor,
        private readonly FeedGenerator $feedGenerator,
        private readonly FeedFlags $feedFlags
    ) {
    }

    /**
     * @return int products removed
     * @throws ApiException when AskMerra cannot be reached; what was removed so far stays removed
     */
    public function removeAll(int $storeId): int
    {
        $runId = $this->runLog->start($storeId, RunLog::TYPE_REMOVE_ALL);
        $canPush = $this->config->getSecretKey($storeId) !== '';
        $removed = 0;

        try {
            while ($rows = $this->state->getPage($storeId, 0, 1000)) {
                if ($canPush) {
                    $this->queueProcessor->deleteRemote($storeId, $rows);
                }

                $this->state->delete($storeId, array_column($rows, 'product_id'));
                $removed += count($rows);
            }
        } catch (ApiException $e) {
            $this->runLog->finish($runId, RunLog::STATUS_FAILED, ['removed' => $removed], $e->getMessage());

            throw $e;
        }

        $this->queue->clear($storeId);
        $this->feedGenerator->deleteFiles($storeId);
        $this->feedFlags->resetReady($storeId);
        $this->feedFlags->clearDirty($storeId);
        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, ['removed' => $removed]);

        return $removed;
    }
}
