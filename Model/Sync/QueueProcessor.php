<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Api\ApiException;
use AskMerra\Connector\Model\Api\Client;
use AskMerra\Connector\Model\Catalog\ProductBuilder;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Works through the queue: builds the queued products and keeps only those whose content changed.
 *
 * - Push store views send them to AskMerra (batchUpsert) and remove products that left the catalog
 *   (batchDelete) - removals first, so a product sent under a removed product's id keeps it.
 * - Feed store views keep the finished entries in askmerra_product_state; the feed file is written
 *   from there (FeedGenerator).
 *
 * An id belongs to one product: a product taking an id another product still has fails with a
 * message, and a copy stays in AskMerra while another product has it.
 *
 * Store views take turns batch by batch, so one large catalog does not hold up the others. A rate
 * limit, a refused key or AskMerra out of reach pauses a store view as a whole, without counting
 * failures against its products.
 */
class QueueProcessor
{
    public const LOCK = 'askmerra_queue';

    /** A run fits in Magento's one-minute cron. */
    public const DEFAULT_SECONDS = 50;

    /** Seconds before a store view whose key was rejected is tried again. */
    private const AUTH_PAUSE = 600;

    /**
     * Outages in a row by store view (no answer, a timeout, a server error): the store view waits
     * 1 minute after the first, doubling up to 6 hours, until AskMerra answers again.
     */
    private const FLAG_OUTAGES = 'askmerra_outages';

    private const OUTAGE_PAUSE = 60;

    private const MAX_OUTAGE_PAUSE = 21600;

    /** @var array<int, true> store views paused for the rest of the run */
    private array $paused = [];

    /** @var array<int, true> the products of the batch being processed */
    private array $batch = [];

    /** @var array<int, true> products of the batch still without an outcome (dequeue(), retryLater(), reject()) */
    private array $open = [];

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
        private readonly FlagManager $flagManager,
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
            $this->paused = [];

            foreach ($storeIds as $storeId) {
                $this->reconciler->ensureSyncMethod((int) $storeId);
            }

            do {
                $worked = false;

                foreach ($storeIds as $storeId) {
                    $storeId = (int) $storeId;

                    if (microtime(true) >= $deadline) {
                        break 2;
                    }

                    if (isset($this->paused[$storeId])) {
                        continue;
                    }

                    $result = $this->processNextBatch($storeId);

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
                $this->answered($storeId);
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
        $this->batch = array_fill_keys($productIds, true);
        $this->open = $this->batch;

        try {
            $this->processBatch($storeId, $productIds, $isPush, $stats);
        } catch (ApiException $e) {
            $this->onApiError($storeId, $e, $stats);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Store %d: %s', $storeId, $e->getMessage()), ['exception' => $e]);
            $stats['failed'] += $this->retryOpen($storeId, $e->getMessage());
        }

        return $stats;
    }

    /**
     * A call failed as a whole. A rate limit, a refused key or an outage is the store view's, not
     * its products': everything it has waiting is paused without counting a failure, and the rest
     * of the run leaves it alone. Any other error is a failed attempt of the batch's open products.
     */
    private function onApiError(int $storeId, ApiException $e, array &$stats): void
    {
        if ($e->isAuthError()) {
            $this->problems->set($storeId, $e->getMessage());
            $this->pause($storeId, self::AUTH_PAUSE, $e->getMessage());
        } elseif ($e->isRateLimited()) {
            $this->pause($storeId, $e->getRetryAfter());
        } elseif ($e->isRetryable()) {
            // No answer, a timeout or a server error.
            $this->pause($storeId, $this->countOutage($storeId), $e->getMessage());
        } else {
            $stats['failed'] += $this->retryOpen($storeId, $e->getMessage());
        }
    }

    private function pause(int $storeId, int $seconds, ?string $reason = null): void
    {
        $this->queue->postponeStore($storeId, $seconds, $reason);
        $this->paused[$storeId] = true;
    }

    /** @return int seconds the store view waits after this outage */
    private function countOutage(int $storeId): int
    {
        $outages = $this->outages();
        $count = (int) ($outages[$storeId] ?? 0) + 1;
        $outages[$storeId] = $count;
        $this->flagManager->saveFlag(self::FLAG_OUTAGES, $outages);

        return min(self::OUTAGE_PAUSE * 2 ** min($count - 1, 16), self::MAX_OUTAGE_PAUSE);
    }

    /** AskMerra answered: the key works (the admin warning goes) and no outage is going on. */
    private function answered(int $storeId): void
    {
        $this->problems->clear($storeId);
        $outages = $this->outages();

        if (isset($outages[$storeId])) {
            unset($outages[$storeId]);
            $this->flagManager->saveFlag(self::FLAG_OUTAGES, $outages);
        }
    }

    /** @return array<int, int> */
    private function outages(): array
    {
        $outages = $this->flagManager->getFlagData(self::FLAG_OUTAGES);

        return is_array($outages) ? $outages : [];
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
            $this->retryLater($storeId, [(int) $productId], (string) $message);
            $stats['failed']++;
        }

        [$owners, $taken] = $this->claimIds($storeId, $built, $states);

        foreach ($taken as $productId => $message) {
            $this->reject($storeId, $productId, $message);
            unset($built['payloads'][$productId]);
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
                && $previous['external_id'] === (string) $payload['external_id']
                && (string) $previous['locale'] === (string) $locale
            ) {
                $unchanged[] = (int) $productId;
                continue;
            }

            $changed[(int) $productId] = ['payload' => $payload, 'json' => $json, 'hash' => $hash];
        }

        if ($unchanged) {
            $this->dequeue($storeId, $unchanged);
            $stats['unchanged'] += count($unchanged);
        }

        $ineligible = array_map('intval', array_keys($built['ineligible']));
        $known = array_intersect_key($states, array_flip($ineligible));
        $handedOver = [];

        if ($isPush) {
            // A copy under an id that a product of the batch sends is left to that product: its
            // entry goes once that product is recorded (record()), or below when it was not sent.
            foreach ($known as $productId => $row) {
                if ((string) $row['locale'] === (string) $locale && isset($changed[$owners[$row['external_id']] ?? 0])) {
                    $handedOver[$productId] = $row;
                }
            }
        }

        $this->removeKnown($storeId, array_diff_key($known, $handedOver), $isPush, $locale, $stats);

        if ($ineligible) {
            $this->dequeue($storeId, $ineligible);
        }

        if ($changed) {
            $isPush
                ? $this->push($storeId, $changed, $states, $locale, $stats)
                : $this->cache($storeId, $changed, $locale, $stats);
        }

        if ($handedOver) {
            // Entries still there: the product taking the id over was rejected.
            $left = array_intersect_key($handedOver, $this->state->get($storeId, array_keys($handedOver)));
            $this->removeKnown($storeId, $left, $isPush, $locale, $stats);
        }
    }

    /**
     * Removes products that left the catalog: their copies that no other product has from AskMerra
     * (or the feed), and their entries.
     *
     * @param array[] $known their entries, by product id
     * @throws ApiException
     */
    private function removeKnown(int $storeId, array $known, bool $isPush, ?string $locale, array &$stats): void
    {
        if (!$known) {
            return;
        }

        if ($isPush) {
            $this->deleteRemote($storeId, $this->unowned($storeId, $known, [], $locale));
        } else {
            $this->feedFlags->markDirty($storeId);
        }

        $this->state->delete($storeId, array_keys($known));
        $stats['removed'] += count($known);
    }

    /**
     * Gives each external id of the batch to one product, and finds the products that cannot have
     * theirs - sending them would overwrite another product in AskMerra:
     * - a product AskMerra has under its id keeps it (the first one, should two have it);
     * - a product taking a new id gets it, unless another product of the batch has it, or a product
     *   AskMerra has under it still has it (built now; one that cannot be built is taken to). A
     *   product that is gone or has another id now gives way.
     *
     * @return array{0: array<string, int>, 1: array<int, string>} external id => the product that
     *         has it, and product id => why it is not sent
     */
    private function claimIds(int $storeId, array $built, array $states): array
    {
        $owners = [];
        $newcomers = [];
        $taken = [];

        foreach ($built['payloads'] as $productId => $payload) {
            $externalId = (string) $payload['external_id'];

            if (($states[$productId]['external_id'] ?? null) !== $externalId) {
                $newcomers[$productId] = $externalId;
            } elseif (isset($owners[$externalId])) {
                $taken[$productId] = [$owners[$externalId], $externalId];
            } else {
                $owners[$externalId] = $productId;
            }
        }

        $holders = $newcomers ? $this->getHolders($storeId, $newcomers, $built) : [];

        foreach ($newcomers as $productId => $externalId) {
            $other = $owners[$externalId] ?? $holders[$externalId] ?? null;

            if ($other === null) {
                $owners[$externalId] = $productId;
            } else {
                $taken[$productId] = [$other, $externalId];
            }
        }

        $reasons = [];

        foreach ($taken as $productId => [$otherId, $externalId]) {
            $reasons[$productId] = (string) __(
                'Product %1 has the same SKU as product %2 (%3); AskMerra needs unique product identifiers.',
                $productId,
                $otherId,
                $externalId
            );
        }

        return [$owners, $reasons];
    }

    /**
     * The products AskMerra has under ids that products of the batch take, and that still have
     * them. The batch's own products are known from its build: those still sending the id own it
     * already, those that left or changed their id are gone from it.
     *
     * @param array<int, string> $newcomers product id => the id it takes
     * @return array<string, int> external id => the product that has it
     */
    private function getHolders(int $storeId, array $newcomers, array $built): array
    {
        $holders = [];
        $outside = [];

        foreach ($this->state->getByExternalIds($storeId, $newcomers) as $productId => $row) {
            if (isset($built['errors'][$productId])) {
                $holders[$row['external_id']] ??= $productId;
            } elseif (!isset($this->batch[$productId])) {
                $outside[$productId] = $row['external_id'];
            }
        }

        if ($outside) {
            $now = $this->productBuilder->build($storeId, array_keys($outside));

            foreach ($outside as $productId => $externalId) {
                if (isset($now['errors'][$productId])
                    || (string) ($now['payloads'][$productId]['external_id'] ?? '') === $externalId
                ) {
                    $holders[$externalId] ??= $productId;
                }
            }
        }

        return $holders;
    }

    /**
     * The copies no other product has in AskMerra: another product's entry with the same id and
     * language keeps the copy, and so does a product just sent under that id.
     *
     * @param array[] $rows entries going (product_id, external_id, locale), by product id
     * @param string[] $sentIds ids just sent, in the store view's language
     * @return array[] those whose copy can be removed
     */
    private function unowned(int $storeId, array $rows, array $sentIds, ?string $locale): array
    {
        $kept = [];

        foreach ($sentIds as $externalId) {
            $kept[$externalId . "\n" . $locale] = true;
        }

        foreach ($this->state->getByExternalIds($storeId, array_column($rows, 'external_id')) as $productId => $row) {
            if (!isset($rows[$productId])) {
                $kept[$row['external_id'] . "\n" . $row['locale']] = true;
            }
        }

        return array_filter($rows, static fn (array $row): bool => !isset($kept[$row['external_id'] . "\n" . $row['locale']]));
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
            $this->answered($storeId);
        } catch (ApiException $e) {
            if (!$e->isPayloadTooLarge()) {
                throw $e;
            }

            // Too large after all: send it in halves. A product too large on its own stays out.
            if (count($chunk) > 1) {
                foreach (array_chunk($chunk, (int) ceil(count($chunk) / 2), true) as $half) {
                    $this->pushChunk($storeId, $half, $states, $locale, $stats);
                }
            } else {
                $this->reject($storeId, (int) array_key_first($chunk), (string) __(
                    'The product is too large to send to AskMerra; shorten its description or attributes. %1',
                    $e->getMessage()
                ));
                $stats['failed']++;
            }

            return;
        }

        $rejected = [];

        foreach ($response['errors'] as $error) {
            if (is_array($error)) {
                $rejected[(int) ($error['index'] ?? -1)] = (string) ($error['message'] ?? 'rejected');
            }
        }

        $sent = [];
        $sentIds = [];
        $stale = [];
        $index = 0;

        foreach ($chunk as $productId => $item) {
            if (isset($rejected[$index])) {
                $this->reject($storeId, $productId, (string) __('AskMerra rejected the product: %1', $rejected[$index]));
                $stats['failed']++;
            } else {
                $sent[$productId] = $item;
                $sentIds[] = (string) $item['payload']['external_id'];
                $previous = $states[$productId] ?? null;

                // The product was in AskMerra under another id or language: that copy goes.
                if ($previous !== null
                    && ($previous['external_id'] !== (string) $item['payload']['external_id'] || (string) $previous['locale'] !== (string) $locale)
                ) {
                    $stale[$productId] = $previous;
                }
            }

            $index++;
        }

        $this->recordSent($storeId, array_diff_key($sent, $stale), $locale, $stats);

        if ($stale) {
            // Old copies go before their products are recorded: the entry is all that knows about
            // them. When removing fails, those products stay queued with their entry as it was, and
            // sending them again is harmless.
            $this->deleteRemote($storeId, $this->unowned($storeId, $stale, $sentIds, $locale));
            $this->recordSent($storeId, array_intersect_key($sent, $stale), $locale, $stats);
        }
    }

    /** Records sent products as AskMerra has them now, and takes them out of the queue. */
    private function recordSent(int $storeId, array $items, ?string $locale, array &$stats): void
    {
        if (!$items) {
            return;
        }

        $rows = [];

        foreach ($items as $productId => $item) {
            $rows[] = [
                'product_id' => $productId,
                'external_id' => (string) $item['payload']['external_id'],
                'locale' => $locale,
                'payload_hash' => $item['hash'],
                'in_stock' => $item['payload']['in_stock'],
            ];
        }

        $this->record($storeId, $rows, $locale);
        $this->dequeue($storeId, array_keys($items));
        $stats['sent'] += count($items);
    }

    private function cache(int $storeId, array $changed, ?string $locale, array &$stats): void
    {
        $rows = [];

        foreach ($changed as $productId => $item) {
            $rows[] = [
                'product_id' => $productId,
                'external_id' => (string) $item['payload']['external_id'],
                'locale' => $locale,
                'payload_hash' => $item['hash'],
                'in_stock' => $item['payload']['in_stock'],
                'payload' => $item['json'],
            ];
        }

        // Dirty first: a process stopping after the save must not leave the file behind the entries.
        $this->feedFlags->markDirty($storeId);
        $this->record($storeId, $rows, $locale);
        $this->dequeue($storeId, array_keys($changed));
        $stats['sent'] += count($rows);
    }

    /**
     * Saves entries. Another product's entry under one of their ids in this language described the
     * copy just replaced - that product left the catalog or has another id now (claimIds()): the
     * entry goes, and the product is queued to be sent under its new id or removed.
     */
    private function record(int $storeId, array $rows, ?string $locale): void
    {
        $this->state->save($storeId, $rows);

        $saved = array_column($rows, 'product_id', 'external_id');
        $replaced = [];

        foreach ($this->state->getByExternalIds($storeId, array_keys($saved)) as $productId => $row) {
            if ($productId !== $saved[$row['external_id']] && (string) $row['locale'] === (string) $locale) {
                $replaced[$productId] = true;
            }
        }

        if ($replaced) {
            $this->state->delete($storeId, array_keys($replaced));
            // The batch's own products are being handled.
            $this->queue->add([$storeId], array_keys(array_diff_key($replaced, $this->batch)));
        }
    }

    /** @param int[] $productIds done: out of the queue */
    private function dequeue(int $storeId, array $productIds): void
    {
        $this->queue->remove($storeId, $productIds);
        $this->close($productIds);
    }

    /** @param int[] $productIds a failed attempt: tried again later */
    private function retryLater(int $storeId, array $productIds, string $error): void
    {
        $this->queue->fail($storeId, $productIds, $error);
        $this->close($productIds);
    }

    /** Failed for good: tried again when it changes, or with "Retry failed". */
    private function reject(int $storeId, int $productId, string $error): void
    {
        $this->queue->failPermanently($storeId, [$productId], $error);
        $this->close([$productId]);
    }

    /** @return int products of the batch that failed this attempt */
    private function retryOpen(int $storeId, string $error): int
    {
        $open = array_keys($this->open);
        $this->retryLater($storeId, $open, $error);

        return count($open);
    }

    /** @param int[] $productIds */
    private function close(array $productIds): void
    {
        foreach ($productIds as $productId) {
            unset($this->open[(int) $productId]);
        }
    }
}
