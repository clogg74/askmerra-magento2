<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Api;

use AskMerra\Connector\Model\Config;
use AskMerra\Connector\Model\Log\Logger;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * The AskMerra Push API: the only server-to-server API AskMerra offers.
 *
 *  GET  /v1/catalog/ping                      -> {ok, shopId, shopName}
 *  POST /v1/catalog/products:batchUpsert      {products: 1-500, locale?} -> {received, created, updated, unchanged, failed, errors[]}
 *  POST /v1/catalog/products:batchDelete      {external_ids: 1-1000, locale?} -> {deleted}
 *
 * Authenticated with the shop's secret key as a bearer token. AskMerra allows 120 requests per
 * minute per key and 10 MB per upsert (1 MB for everything else).
 */
class Client
{
    public const VERSION = '1.0.1';

    /** Largest upsert body we send; AskMerra refuses more than 10 MB. */
    public const MAX_UPSERT_BYTES = 9_500_000;

    public const MAX_DELETE_IDS = 1000;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Logger $logger
    ) {
    }

    /**
     * Checks a key; the overrides let the admin test values that are not saved yet.
     *
     * @return array{ok: bool, shopId: string, shopName: string}
     * @throws ApiException
     */
    public function ping(int $storeId, ?string $secretKey = null, ?string $apiUrl = null): array
    {
        $response = $this->request('GET', '/v1/catalog/ping', $storeId, null, $secretKey, $apiUrl);

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'shopId' => (string) ($response['shopId'] ?? ''),
            'shopName' => (string) ($response['shopName'] ?? ''),
        ];
    }

    /**
     * Creates or replaces products. Every field left out is cleared on AskMerra's side, so each
     * product is always sent complete.
     *
     * @param array[] $products 1-500 products in AskMerra's ProductInput shape
     * @return array{received: int, created: int, updated: int, unchanged: int, failed: int, errors: array}
     * @throws ApiException
     */
    public function batchUpsert(int $storeId, array $products, ?string $locale): array
    {
        $body = ['products' => array_values($products)];

        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        $response = $this->request('POST', '/v1/catalog/products:batchUpsert', $storeId, $body);

        return [
            'received' => (int) ($response['received'] ?? 0),
            'created' => (int) ($response['created'] ?? 0),
            'updated' => (int) ($response['updated'] ?? 0),
            'unchanged' => (int) ($response['unchanged'] ?? 0),
            'failed' => (int) ($response['failed'] ?? 0),
            'errors' => is_array($response['errors'] ?? null) ? $response['errors'] : [],
        ];
    }

    /**
     * Removes products from the assistant (AskMerra deactivates them; sending them again brings
     * them back).
     *
     * @param string[] $externalIds 1-1000 ids
     * @throws ApiException
     */
    public function batchDelete(int $storeId, array $externalIds, ?string $locale): int
    {
        $body = ['external_ids' => array_values(array_map('strval', $externalIds))];

        if ($locale !== null) {
            $body['locale'] = $locale;
        }

        $response = $this->request('POST', '/v1/catalog/products:batchDelete', $storeId, $body);

        return (int) ($response['deleted'] ?? 0);
    }

    /**
     * @throws ApiException
     */
    private function request(
        string $method,
        string $path,
        int $storeId,
        ?array $body,
        ?string $secretKey = null,
        ?string $apiUrl = null
    ): array {
        $secretKey = $secretKey ?: $this->config->getSecretKey($storeId);
        $url = rtrim($apiUrl ?: $this->config->getApiUrl($storeId), '/') . $path;
        $requestId = 'mage-' . bin2hex(random_bytes(8));

        if ($secretKey === '') {
            throw new ApiException(
                'No AskMerra secret key is configured for this store view.',
                401,
                'missing_api_key',
                $requestId
            );
        }

        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = $this->curlFactory->create();
        $curl->setTimeout($this->config->getTimeout());
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 10);
        $curl->setHeaders([
            'Authorization' => 'Bearer ' . $secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => sprintf('AskMerra-Magento/%s Magento/%s', self::VERSION, $this->productMetadata->getVersion()),
            'x-request-id' => $requestId,
            // No "100 Continue" round trip before large bodies.
            'Expect' => '',
        ]);

        $started = microtime(true);

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->post($url, (string) $payload);
            }
        } catch (\Throwable $e) {
            $exception = ApiException::transport($e->getMessage(), $requestId);
            $this->logger->error($exception->getMessage(), ['url' => $url]);

            throw $exception;
        }

        $status = (int) $curl->getStatus();
        $raw = (string) $curl->getBody();
        $decoded = json_decode($raw, true);

        if ($this->config->isDebug()) {
            $this->logger->debug(sprintf('%s %s -> %d in %d ms', $method, $url, $status, (microtime(true) - $started) * 1000), [
                'request_id' => $requestId,
                'request_bytes' => $payload === null ? 0 : strlen($payload),
                'request' => $payload === null ? null : mb_substr($payload, 0, 4000),
                'response' => mb_substr($raw, 0, 4000),
            ]);
        }

        if ($status === 0 || $status >= 400) {
            $exception = $status === 0
                ? ApiException::transport('no HTTP status', $requestId)
                : ApiException::fromResponse($status, $decoded, $curl->getHeaders(), $requestId);
            $this->logger->error($exception->getMessage(), ['url' => $url]);

            throw $exception;
        }

        if (!is_array($decoded)) {
            $exception = ApiException::transport('the response is not JSON', $requestId);
            $this->logger->error($exception->getMessage(), ['url' => $url, 'response' => mb_substr($raw, 0, 500)]);

            throw $exception;
        }

        return $decoded;
    }
}
