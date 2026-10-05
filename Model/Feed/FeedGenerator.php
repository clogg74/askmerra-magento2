<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Feed;

use AskMerra\Connector\Model\Api\Client;
use AskMerra\Connector\Model\Catalog\AttributeValues;
use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use AskMerra\Connector\Model\Sync\Queue;
use AskMerra\Connector\Model\Sync\Reconciler;
use AskMerra\Connector\Model\Sync\RunLog;
use AskMerra\Connector\Model\Sync\State;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes each feed store view's file (pub/media/askmerra/feed/<store code>-<token>.<json|xml>).
 *
 * Nothing is rebuilt here: the queue keeps every product's finished entry in askmerra_product_state
 * and only rebuilds the products that change. The file is streamed from those entries - seconds for
 * 50,000 products - and only when something changed since it was last written. A store view's
 * first file waits until its whole catalog is ready: AskMerra removes the products missing from a
 * feed.
 */
class FeedGenerator
{
    public const DIRECTORY = 'askmerra/feed';

    private const PAGE_SIZE = 1000;

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly Queue $queue,
        private readonly FeedFlags $flags,
        private readonly FeedToken $token,
        private readonly RunLog $runLog,
        private readonly Reconciler $reconciler,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly AttributeValues $attributeValues,
        private readonly JsonWriterFactory $jsonWriterFactory,
        private readonly GoogleXmlWriterFactory $googleXmlWriterFactory,
        private readonly Logger $logger
    ) {
    }

    /** @return array<int, array> per store view, see generate() */
    public function generateAll(bool $force = false): array
    {
        $results = [];

        foreach ($this->config->getFeedStoreIds() as $storeId) {
            try {
                $results[$storeId] = $this->generate($storeId, $force);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Feed of store %d: %s', $storeId, $e->getMessage()), ['exception' => $e]);
                $results[$storeId] = ['status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return array{status: string, message?: string, products?: int, bytes?: int, url?: string}
     *         status: written, unchanged, building (catalog not ready yet) or disabled
     */
    public function generate(int $storeId, bool $force = false): array
    {
        if (!$this->config->isFeedEnabled($storeId)) {
            return ['status' => 'disabled'];
        }

        $this->reconciler->ensureSyncMethod($storeId);

        if (!$this->flags->isReady($storeId)) {
            $pending = $this->queue->countPending($storeId);

            if ($pending > 0 || $this->state->countPayloads($storeId) === 0) {
                return [
                    'status' => 'building',
                    'message' => (string) __(
                        'The catalog is being prepared (%1 products to go); the feed is published once all of it is ready.',
                        $pending
                    ),
                ];
            }

            $this->flags->markReady($storeId);
        }

        $info = $this->getInfo($storeId);

        if (!$force && $info['exists'] && !$this->flags->isDirty($storeId)) {
            return ['status' => 'unchanged'] + $info;
        }

        $runId = $this->runLog->start($storeId, RunLog::TYPE_FEED);
        // Cleared first: a product that changes while the file is written marks it again.
        $this->flags->clearDirty($storeId);

        try {
            $result = $this->write($storeId);
        } catch (\Throwable $e) {
            $this->flags->markDirty($storeId);
            $this->runLog->finish($runId, RunLog::STATUS_FAILED, [], $e->getMessage());

            throw $e;
        }

        $this->runLog->finish($runId, RunLog::STATUS_SUCCESS, ['products' => $result['products'], 'bytes' => $result['bytes']]);

        return ['status' => 'written'] + $result;
    }

    /** @return array{exists: bool, url: string, bytes: int, modified_at: ?string} modified_at in UTC */
    public function getInfo(int $storeId): array
    {
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $path = self::DIRECTORY . '/' . $this->getFileName($storeId);
        $exists = $directory->isFile($path);
        $stat = $exists ? $directory->stat($path) : [];

        return [
            'exists' => $exists,
            'url' => $this->getUrl($storeId),
            'bytes' => (int) ($stat['size'] ?? 0),
            'modified_at' => isset($stat['mtime']) ? gmdate('Y-m-d H:i:s', (int) $stat['mtime']) : null,
        ];
    }

    public function getUrl(int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);

        return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, $store->isFrontUrlSecure())
            . self::DIRECTORY . '/' . $this->getFileName($storeId);
    }

    public function getFileName(int $storeId): string
    {
        return sprintf(
            '%s-%s.%s',
            $this->storeManager->getStore($storeId)->getCode(),
            $this->token->get(),
            $this->config->getFeedFormat($storeId) === Config::FEED_GOOGLE_XML ? 'xml' : 'json'
        );
    }

    /** Removes a store view's feed files, whatever their token or format. */
    public function deleteFiles(int $storeId): void
    {
        $this->removeFiles($storeId, null);
    }

    /**
     * Removes the files no store view publishes anymore: store views switched to the Push API or
     * turned off (AskMerra would keep importing their outdated file), deleted store views, old names.
     *
     * @return int files removed
     */
    public function removeUnusedFiles(): int
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        if (!$directory->isDirectory(self::DIRECTORY)) {
            return 0;
        }

        $keep = [];

        foreach ($this->config->getFeedStoreIds() as $storeId) {
            $keep[$this->getFileName($storeId)] = true;
        }

        $removed = 0;

        foreach ($directory->read(self::DIRECTORY) as $path) {
            $name = basename($path);

            // Files starting with a dot are being written.
            if (!isset($keep[$name]) && !str_starts_with($name, '.') && $directory->isFile($path)) {
                $directory->delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function write(int $storeId): array
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $directory->create(self::DIRECTORY);

        $fileName = $this->getFileName($storeId);
        $target = self::DIRECTORY . '/' . $fileName;
        $temporary = self::DIRECTORY . '/.' . $fileName . '.' . getmypid() . '.tmp';
        $isXml = $this->config->getFeedFormat($storeId) === Config::FEED_GOOGLE_XML;
        $writer = $isXml ? $this->googleXmlWriterFactory->create() : $this->jsonWriterFactory->create();
        $count = 0;

        $file = $directory->openFile($temporary, 'w');

        try {
            $writer->start($file, $this->getMeta($storeId, $isXml));
            $after = 0;

            $damaged = [];

            while ($page = $this->state->getPayloadPage($storeId, $after, self::PAGE_SIZE)) {
                foreach ($page as $productId => $json) {
                    $after = $productId;

                    if ($json === null) {
                        $damaged[] = $productId;
                        continue;
                    }

                    $writer->add($json);
                    $count++;
                }
            }

            if ($damaged) {
                // Never publish a feed with products missing - AskMerra would deactivate them. The
                // queue rebuilds them and the file is written at the next run.
                $this->state->invalidate($storeId, $damaged);
                $this->queue->add([$storeId], $damaged);

                throw new \RuntimeException(sprintf(
                    '%d stored products were damaged and are being rebuilt; the previous feed file stays until then.',
                    count($damaged)
                ));
            }

            $writer->finish($count);
            $file->close();
        } catch (\Throwable $e) {
            $file->close();
            $directory->delete($temporary);

            throw $e;
        }

        $directory->renameFile($temporary, $target);
        $this->removeFiles($storeId, $fileName);

        return [
            'products' => $count,
            'bytes' => (int) ($directory->stat($target)['size'] ?? 0),
            'url' => $this->getUrl($storeId),
        ];
    }

    private function getMeta(int $storeId, bool $isXml): array
    {
        $store = $this->storeManager->getStore($storeId);
        $meta = [
            'generator' => 'AskMerra for Magento ' . Client::VERSION,
            'generated_at' => gmdate(DATE_ATOM),
            'store' => $store->getCode(),
            'store_name' => $store->getName(),
            'store_url' => $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isFrontUrlSecure()),
            'locale' => $this->config->getLocale($storeId),
            'currency' => $store->getDefaultCurrencyCode(),
        ];

        if ($isXml) {
            $meta['attribute_map'] = $this->getGoogleAttributeMap($storeId);
        }

        return $meta;
    }

    /**
     * Selected attributes whose code is one of the attribute names AskMerra knows in a Google feed,
     * keyed by the label they are sent under in this store view (e.g. "Culoare" => "color").
     *
     * @return array<string, string>
     */
    private function getGoogleAttributeMap(int $storeId): array
    {
        $map = [];

        foreach ($this->config->getAttributeCodes($storeId) as $code) {
            $name = GoogleXmlWriter::normalize($code);
            $attribute = $this->attributeValues->getAttribute($code);

            if ($attribute && in_array($name, GoogleXmlWriter::KNOWN_ATTRIBUTES, true)) {
                $map[$this->attributeValues->getLabel($attribute, $storeId)] = $name;
            }
        }

        return $map;
    }

    /** Removes a store view's feed files except $keep (null: all of them). */
    private function removeFiles(int $storeId, ?string $keep): void
    {
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        if (!$directory->isDirectory(self::DIRECTORY)) {
            return;
        }

        $code = $this->storeManager->getStore($storeId)->getCode();

        foreach ($directory->read(self::DIRECTORY) as $path) {
            $name = basename($path);

            if ($name !== $keep && (str_starts_with($name, $code . '-') || str_starts_with($name, '.' . $code . '-'))) {
                $directory->delete($path);
            }
        }
    }
}
