<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Sync;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Feed\FeedFlags;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Remembers what each store view's catalog was built for and notices - however the configuration
 * was changed (admin, config:set, a deployed config), also while the store view was turned off -
 * when that changed:
 *
 * - The sync method: the whole catalog is sent again the new way. A feed needs every product's
 *   entry, and pushed products must not count on what an old feed delivered. A store view that
 *   switched to a feed does not publish it before it is complete.
 * - Where a store view pushes to (API URL and secret key, e.g. a test key replaced by the live
 *   one): the whole catalog is sent again, as the other AskMerra shop has none of it.
 * - Store settings every product shows (language, currencies, prices with or without tax, links):
 *   the catalog is rebuilt, and only the products whose content changed are sent.
 *
 * A change keeps asking for a rebuild until Reconciler::rebuild() has queued the catalog
 * (rebuilt()), so a run that dies in between leaves it to the next check.
 *
 * Flag askmerra_sync_methods: store view id => {method, destination (a keyed hash, never the key),
 * context (a hash), rebuild (pending: 'catalog', or 'feed' to reset the feed's "ready" after it)}.
 */
class MethodTracker
{
    private const FLAG = 'askmerra_sync_methods';

    private const REBUILD_CATALOG = 'catalog';
    private const REBUILD_FEED = 'feed';

    /** Store settings the products are built with (Catalog\ProductBuilder, Catalog\PriceResolver). */
    private const CONTEXT_PATHS = [
        'web/unsecure/base_link_url',
        'web/secure/base_link_url',
        'web/secure/use_in_frontend',
        'web/unsecure/base_media_url',
        'web/secure/base_media_url',
        'web/url/use_store',
        'web/seo/use_rewrites',
        'catalog/seo/product_url_suffix',
        'catalog/price/scope',
        'tax/display/type',
        'tax/calculation/price_includes_tax',
        'tax/calculation/based_on',
        'tax/defaults/country',
        'tax/defaults/region',
        'tax/defaults/postcode',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly FeedFlags $feedFlags,
        private readonly FlagManager $flagManager,
        private readonly EncryptorInterface $encryptor,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The first check only records what the catalog is built for, and so does the first check of a
     * destination or settings recorded by no earlier one (an update from version 1.0.0): a store view
     * that starts syncing is rebuilt by the configuration change.
     *
     * @return bool whether the store view's catalog must be rebuilt: it changed since its catalog
     *              was last checked, or a rebuild it asked for was not queued yet
     */
    public function check(int $storeId): bool
    {
        $records = $this->read();
        $previous = $this->getRecord($records, $storeId);
        $method = $this->config->getSyncMethod($storeId);
        $current = [
            'method' => $method,
            // Kept while the store view does not push: a key entered later is compared with the last one.
            'destination' => $this->getDestination($storeId, $method) ?? $previous['destination'] ?? null,
            'context' => $this->getContext($storeId),
            'rebuild' => $previous['rebuild'] ?? null,
        ];

        if ($previous === null) {
            $this->save($records, $storeId, $current);

            return false;
        }

        $isFeed = $method === Config::SYNC_FEED;
        $switched = $previous['method'] !== $method;
        $moved = !$switched && !$isFeed
            && $previous['destination'] !== null && $previous['destination'] !== $current['destination'];
        $changed = $previous['context'] !== null && $previous['context'] !== $current['context'];

        if ($switched || $moved) {
            // Every product is sent again; the rows stay, they say what to remove.
            $this->state->invalidateStore($storeId, $isFeed);
        }

        if ($switched) {
            $current['rebuild'] = $isFeed ? self::REBUILD_FEED : self::REBUILD_CATALOG;
        } elseif ($moved || $changed) {
            $current['rebuild'] ??= self::REBUILD_CATALOG;
        }

        if ($current !== $previous) {
            $this->save($records, $storeId, $current);
        }

        return $current['rebuild'] !== null;
    }

    /**
     * Reconciler::rebuild() queued the whole catalog: the rebuild a change asked for is done. A
     * store view that switched to a feed waits for that catalog: "ready" is reset now, after the
     * queue has it.
     */
    public function rebuilt(int $storeId): void
    {
        $records = $this->read();
        $record = $this->getRecord($records, $storeId);

        if ($record === null || $record['rebuild'] === null) {
            return;
        }

        if ($record['rebuild'] === self::REBUILD_FEED) {
            $this->feedFlags->resetReady($storeId);
        }

        $record['rebuild'] = null;
        $this->save($records, $storeId, $record);
    }

    /**
     * Where a store view pushes to: a keyed hash (Magento's encryption key) of the API URL and the
     * secret key. Null for a feed, or without a key.
     */
    private function getDestination(int $storeId, string $method): ?string
    {
        $secretKey = $this->config->getSecretKey($storeId);

        if ($method !== Config::SYNC_PUSH || $secretKey === '') {
            return null;
        }

        return $this->encryptor->hash($this->config->getApiUrl($storeId) . '|' . $secretKey);
    }

    /** The store settings every product is built with: language, currencies, links, prices and taxes. */
    private function getContext(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);
        $values = [
            $this->config->getLocale($storeId),
            (string) $store->getDefaultCurrencyCode(),
            (string) $store->getBaseCurrencyCode(),
        ];

        foreach (self::CONTEXT_PATHS as $path) {
            $values[] = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        }

        return sha1((string) json_encode($values));
    }

    /** @return array{method: string, destination: ?string, context: ?string, rebuild: ?string}|null */
    private function getRecord(array $records, int $storeId): ?array
    {
        $record = $records[$storeId] ?? null;

        if ($record === null) {
            return null;
        }

        // Version 1.0.0 recorded the method alone.
        $record = is_array($record) ? $record : ['method' => $record];

        return [
            'method' => (string) ($record['method'] ?? ''),
            'destination' => isset($record['destination']) ? (string) $record['destination'] : null,
            'context' => isset($record['context']) ? (string) $record['context'] : null,
            'rebuild' => isset($record['rebuild']) ? (string) $record['rebuild'] : null,
        ];
    }

    private function read(): array
    {
        $records = $this->flagManager->getFlagData(self::FLAG);

        return is_array($records) ? $records : [];
    }

    private function save(array $records, int $storeId, array $record): void
    {
        $records[$storeId] = $record;
        $this->flagManager->saveFlag(self::FLAG, $records);
    }
}
