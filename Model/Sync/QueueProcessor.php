<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Api\Client;
use AskMerra\Connector\Model\Catalog\ProductBuilder;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Works through the queue: builds the queued products and keeps only those whose content changed.
 *
 * - Push store views send them to AskMerra (batchUpsert) and remove products that left the catalog
 *   (batchDelete).
 * - Feed store views keep the finished entries in askmerra_product_state; the feed file is written
 *   from there (FeedGenerator).
 *
 * Store views take turns batch by batch, so one large catalog does not hold up the others.
 */
class QueueProcessor
{
    public const LOCK = 'askmerra_queue';

    /** A run fits in Magento's one-minute cron. */
    public const DEFAULT_SECONDS = 50;

    /** Seconds before a store view whose key was rejected is tried again. */
    private const AUTH_PAUSE = 600;

    public function __construct(
        private readonly Config $config,
        private readonly Queue $queue,
        private readonly State $state,
        private readonly Problems $problems,
        private readonly ProductBuilder $productBuilder,
        private readonly Client $client,
        private readonly FeedFlags $feedFlags,
        private readonly Reconciler $reconciler,
        private readonly LockManagerInterface $lockManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param int[]|null $storeIds null: every store view that syncs
     * @return array<int, array{sent: int, unchanged: int, removed: int, failed: int}>|null null when
     *         another run holds the lock
     */
    public function run(int $seconds = self::DEFAULT_SECONDS, ?array $storeIds = null): ?array
    {
        if (!$this->lockManager->lock(self::LOCK, 0)) {
            return null;
        }

        try {
            $storeIds ??= $this->config->getSyncStoreIds();
            $deadline = microtime(true) + $seconds;
            $stats = [];

            foreach ($storeIds as $storeId) {
                $this->reconciler->ensureSyncMethod((int) $storeId);
            }

            do {
                $worked = false;

                foreach ($storeIds as $storeId) {
                    if (microtime(true) >= $deadline) {
                        break 2;
                    }

                    $result = $this->processNextBatch((int) $storeId);

                    if ($result !== null) {
                        $worked = true;
                        foreach ($result as $key => $count) {
                            $stats[$storeId][$key] = ($stats[$storeId][$key] ?? 0) + $count;
                        }
                    }
                }
            } while ($worked && microtime(true) < $deadline);

            return $stats;
        } finally {
            $this->lockManager->unlock(self::LOCK);
        }
    }

    /** @return array{sent: int, unchanged: int, removed: int, failed: int}|null null: nothing was due */
    private function processNextBatch(int $storeId): ?array
    {
        $isPush = $this->config->isPushEnabled($storeId);

        if (!$isPush && !$this->config->isFeedEnabled($storeId)) {
            return null;
        }

        $productIds = $this->queue->fetchDue($storeId, $this->config->getBatchSize($storeId));

        if (!$productIds) {
            return null;
        }

        $stats = ['sent' => 0, 'unchanged' => 0, 'removed' => 0, 'failed' => 0];

        try {
            $this->processBatch($storeId, $productIds, $isPush, $stats);
        } catch (ApiException $e) {
            if ($e->isAuthError()) {
                $this->problems->set($storeId, $e->getMessage());
                $this->queue->postpone($storeId, $productIds, self::AUTH_PAUSE, $e->getMessage());
            } elseif ($e->isRateLimited()) {
                $this->queue->postpone($storeId, $productIds, $e->getRetryAfter());
            } else {
                $this->queue->fail($storeId, $productIds, $e->getMessage());
                $stats['failed'] += count($productIds);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Store %d: %s', $storeId, $e->getMessage()), ['exception' => $e]);
            $this->queue->fail($storeId, $productIds, $e->getMessage());
            $stats['failed'] += count($productIds);
        }

        return $stats;
    }

    /**
     * @param int[] $productIds
     * @throws ApiException
     */
    private function processBatch(int $storeId, array $productIds, bool $isPush, array &$stats): void
    {
        $built = $this->productBuilder->build($storeId, $productIds);
        $states = $this->state->get($storeId, $productIds);
        $locale = $this->config->getLocale($storeId);

        foreach ($built['errors'] as $productId => $message) {
            $this->queue->fail($storeId, [$productId], $message);
            $stats['failed']++;
        }

        $changed = [];
        $unchanged = [];

        foreach ($built['payloads'] as $productId => $payload) {
            $json = self::encode($payload);
            $hash = self::hash($payload);
            $previous = $states[$productId] ?? null;

            if ($previous !== null
                && $previous['payload_hash'] === $hash
                && $previous['external_id'] === $payload['external_id']
                && (string) $previous['locale'] === (string) $locale
            ) {
                $unchanged[] = $productId;
                continue;
            }

            $changed[$productId] = ['payload' => $payload, 'json' => $json, 'hash' => $hash];
        }

        if ($unchanged) {
            $this->queue->remove($storeId, $unchanged);
            $stats['unchanged'] += count($unchanged);
        }

        if ($changed) {
            $isPush
                ? $this->push($storeId, $changed, $states, $locale, $stats)
                : $this->cache($storeId, $changed, $locale, $stats);
        }

        $ineligible = array_keys($built['ineligible']);

        if ($ineligible) {
            $known = array_intersect_key($states, array_flip($ineligible));

            if ($known) {
                if ($isPush) {
                    $this->deleteRemote($storeId, $known);
                } else {
                    $this->feedFlags->markDirty($storeId);
                }

                $this->state->delete($storeId, array_keys($known));
                $stats['removed'] += count($known);
            }

            $this->queue->remove($storeId, $ineligible);
        }
    }

    /** @throws ApiException */
    private function push(int $storeId, array $changed, array $states, ?string $locale, array &$stats): void
    {
        $chunk = [];
        $bytes = 0;

        foreach ($changed as $productId => $item) {
            $size = strlen($item['json']) + 1;

            if ($chunk && $bytes + $size > Client::MAX_UPSERT_BYTES) {
                $this->pushChunk($storeId, $chunk, $states, $locale, $stats);
                $chunk = [];
                $bytes = 0;
            }

            $chunk[$productId] = $item;
            $bytes += $size;
        }

        if ($chunk) {
            $this->pushChunk($storeId, $chunk, $states, $locale, $stats);
        }
    }

    /** @throws ApiException */
    private function pushChunk(int $storeId, array $chunk, array $states, ?string $locale, array &$stats): void
    {
        try {
            $response = $this->client->batchUpsert($storeId, array_column($chunk, 'payload'), $locale);
            // AskMerra accepts the key again: the admin warning goes.
            $this->problems->clear($storeId);
        } catch (ApiException $e) {
            // Too large after all: send it in halves.
            if ($e->isPayloadTooLarge() && count($chunk) > 1) {
                foreach (array_chunk($chunk, (int) ceil(count($chunk) / 2), true) as $half) {
                    $this->pushChunk($storeId, $half, $states, $locale, $stats);
                }

                return;
            }

            throw $e;
        }

        $rejected = [];

        foreach ($response['errors'] as $error) {
            $rejected[(int) ($error['index'] ?? -1)] = (string) ($error['message'] ?? 'rejected');
        }

        $saved = [];
        $stale = [];
        $index = 0;

        foreach ($chunk as $productId => $item) {
            if (isset($rejected[$index])) {
                $this->queue->failPermanently($storeId, [$productId], 'AskMerra rejected the product: ' . $rejected[$index]);
                $stats['failed']++;
            } else {
                $externalId = $item['payload']['external_id'];
                $saved[] = [
                    'product_id' => $productId,
                    'external_id' => $externalId,
                    'locale' => $locale,
                    'payload_hash' => $item['hash'],
                    'in_stock' => $item['payload']['in_stock'],
                ];

                // The product was in AskMerra under another id or language: that copy goes.
                $previous = $states[$productId] ?? null;

                if ($previous !== null
                    && ($previous['external_id'] !== $externalId || (string) $previous['locale'] !== (string) $locale)
                ) {
                    $stale[] = $previous;
                }
            }

            $index++;
        }

        $this->state->save($storeId, $saved);
        $this->queue->remove($storeId, array_column($saved, 'product_id'));
        $stats['sent'] += count($saved);

        if ($stale) {
            $this->deleteRemote($storeId, $stale);
        }
    }

    private function cache(int $storeId, array $changed, ?string $locale, array &$stats): void
    {
        $rows = [];

        foreach ($changed as $productId => $item) {
            $rows[] = [
                'product_id' => $productId,
                'external_id' => $item['payload']['external_id'],
                'locale' => $locale,
                'payload_hash' => $item['hash'],
                'in_stock' => $item['payload']['in_stock'],
                'payload' => $item['json'],
            ];
        }

        $this->state->save($storeId, $rows);
        $this->queue->remove($storeId, array_keys($changed));
        $this->feedFlags->markDirty($storeId);
        $stats['sent'] += count($rows);
    }

    /**
     * Removes products from AskMerra, grouped by the language they were sent in.
     *
     * @param array[] $rows each: external_id, locale
     * @throws ApiException
     */
    public function deleteRemote(int $storeId, array $rows): void
    {
        $byLocale = [];

        foreach ($rows as $row) {
            $byLocale[(string) $row['locale']][] = (string) $row['external_id'];
        }

        foreach ($byLocale as $locale => $externalIds) {
            foreach (array_chunk(array_values(array_unique($externalIds)), Client::MAX_DELETE_IDS) as $chunk) {
                $this->client->batchDelete($storeId, $chunk, $locale === '' ? null : (string) $locale);
                $this->problems->clear($storeId);
            }
        }
    }

    public static function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    /** What a product looks like to AskMerra; source_updated_at alone is no reason to send it again. */
    public static function hash(array $payload): string
    {
        unset($payload['source_updated_at']);

        return sha1(self::encode($payload));
    }
}
