<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\EveToken;
use App\Exception\EsiApiException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EsiClient
{
    private const REQUEST_TIMEOUT = 30;
    private const MAX_RETRY_AFTER_SECONDS = 60;
    private const DEFAULT_CACHE_TTL_SECONDS = 300;
    private const REVALIDATION_TTL_SECONDS = 7 * 24 * 3600;
    private const VERSION_SEGMENT = '/latest';

    private int $errorLimitRemain = 100;
    private int $errorLimitReset = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $esiCache,
        private readonly TokenManager $tokenManager,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<mixed>
     */
    public function get(string $endpoint, ?EveToken $token = null, array $extraHeaders = []): array
    {
        try {
            $response = $this->requestWithRetry('GET', $endpoint, $token, $extraHeaders);
            return $this->handleResponse($response, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * GETs an unversioned ESI route (e.g. corporation projects): served from the origin of the
     * configured base URL, without its version segment, and dated by X-Compatibility-Date.
     *
     * @return array<mixed>
     */
    public function getUnversioned(string $endpoint, ?EveToken $token, string $compatibilityDate): array
    {
        try {
            $response = $this->requestWithRetry(
                'GET',
                $endpoint,
                $token,
                ['X-Compatibility-Date' => $compatibilityDate],
                baseUrl: $this->unversionedBaseUrl(),
            );
            return $this->handleResponse($response, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * Get a scalar value (number, string) from ESI endpoint.
     */
    public function getScalar(string $endpoint, ?EveToken $token = null, int $timeout = self::REQUEST_TIMEOUT): mixed
    {
        try {
            $response = $this->requestWithRetry('GET', $endpoint, $token, timeout: $timeout);
            $statusCode = $response->getStatusCode();
            $this->processRateLimitHeaders($response);

            if ($statusCode >= 200 && $statusCode < 300) {
                return json_decode($response->getContent(), false);
            }

            throw $this->failedResponseException($response, $statusCode, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * Get multiple scalar values concurrently, with the replay mechanism of getBatch().
     * Returns an array keyed by the request key, with null for failed requests.
     *
     * @param array<string, array{endpoint: string, token: ?EveToken}> $requests
     * @return array<string, mixed>
     */
    public function getScalarBatch(array $requests, int $timeout = 10): array
    {
        return $this->fetchBatch(
            $requests,
            $timeout,
            static fn (ResponseInterface $response): mixed => json_decode($response->getContent(), false),
        );
    }

    /**
     * GETs several endpoints concurrently: every request is launched before any response is read.
     * Each result keeps its endpoint's key; a key whose request fails (4xx, 5xx, network error,
     * or a second consecutive 420/429) maps to null. 420/429 keys are replayed once, together,
     * after a single wait.
     *
     * @template TKey of array-key
     * @param array<TKey, string> $endpoints
     * @return array<TKey, array<mixed>|null>
     */
    public function getBatch(array $endpoints, ?EveToken $token = null): array
    {
        if ($endpoints === []) {
            return [];
        }

        $this->throttleIfNeeded();

        return $this->fetchBatch(
            array_map(static fn (string $endpoint): array => ['endpoint' => $endpoint, 'token' => $token], $endpoints),
            self::REQUEST_TIMEOUT,
            static fn (ResponseInterface $response): array => $response->toArray(),
        );
    }

    /**
     * Serves the cached copy until ESI's Expires, then revalidates it with the stored ETag.
     *
     * @return array<mixed>
     */
    public function getWithCache(string $endpoint, ?EveToken $token = null): array
    {
        $cacheKey = $this->getCacheKey($endpoint, $token);
        $freshItem = $this->esiCache->getItem($cacheKey);
        if ($freshItem->isHit()) {
            return $freshItem->get()['data'];
        }

        $revalidationItem = $this->esiCache->getItem($this->getRevalidationCacheKey($cacheKey));
        /** @var array{data: array<mixed>, etag: string}|null $stale */
        $stale = $revalidationItem->isHit() ? $revalidationItem->get() : null;

        if ($stale === null) {
            try {
                return $this->cacheResponse($cacheKey, $this->requestWithRetry('GET', $endpoint, $token), $endpoint);
            } catch (TransportExceptionInterface $e) {
                throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
            }
        }

        try {
            $response = $this->conditionalGet($endpoint, $token, $stale['etag']);
            if ($response->getStatusCode() !== 304) {
                return $this->cacheResponse($cacheKey, $response, $endpoint);
            }
        } catch (TransportExceptionInterface) {
            // ESI unreachable: the stale copy is better than no data.
            return $stale['data'];
        }

        $this->storeInCache($cacheKey, $stale['data'], $stale['etag'], $this->responseHeaders($response));

        return $stale['data'];
    }

    /**
     * @return array<mixed>
     */
    public function getPaginated(string $endpoint, ?EveToken $token = null): array
    {
        $allData = [];
        $page = 1;
        $pages = 1;

        do {
            $paginatedEndpoint = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . "page={$page}";

            try {
                $response = $this->requestWithRetry('GET', $paginatedEndpoint, $token);
                $statusCode = $response->getStatusCode();

                // Get headers before consuming body
                $headers = $response->getHeaders(false);
                $pages = (int) ($headers['x-pages'][0] ?? 1);
                $this->processRateLimitHeaders($response);

                if ($statusCode >= 200 && $statusCode < 300) {
                    $data = $response->toArray();
                    $allData = array_merge($allData, $data);
                } else {
                    // Consume body to prevent curl handle issues
                    $response->getContent(false);
                    throw EsiApiException::fromResponse($statusCode, 'ESI request failed', $paginatedEndpoint);
                }
            } catch (TransportExceptionInterface $e) {
                throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $paginatedEndpoint);
            }

            $page++;
        } while ($page <= $pages);

        return $allData;
    }

    /**
     * POST without JSON body (for UI endpoints that take query params).
     */
    public function postEmpty(string $endpoint, ?EveToken $token): void
    {
        try {
            $response = $this->request('POST', $endpoint, $token);

            $statusCode = $response->getStatusCode();
            $this->processRateLimitHeaders($response);

            // Consume response body to prevent curl handle issues
            $response->getContent(false);

            if ($statusCode < 200 || $statusCode >= 300) {
                $message = match ($statusCode) {
                    401 => 'Authentication failed',
                    403 => 'Access forbidden',
                    404 => 'Resource not found',
                    420 => 'Error limited',
                    429 => 'Rate limit exceeded',
                    500, 502, 503, 504 => 'ESI server error',
                    default => 'ESI request failed',
                };

                throw EsiApiException::fromResponse($statusCode, $message, $endpoint);
            }
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * @param array<int|string, mixed> $body
     * @return array<mixed>
     */
    public function post(string $endpoint, array $body, ?EveToken $token = null): array
    {
        try {
            $response = $this->requestWithRetry('POST', $endpoint, $token, ['Content-Type' => 'application/json'], $body);

            return $this->handleResponse($response, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * Launches every request before reading any response, then replays the 420/429 keys once,
     * together, after a single wait. A key whose request fails maps to null.
     *
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, array{endpoint: string, token: ?EveToken}> $requests
     * @param \Closure(ResponseInterface): TValue $decode
     * @return array<TKey, TValue|null>
     */
    private function fetchBatch(array $requests, int $timeout, \Closure $decode): array
    {
        $results = $this->collectBatch($requests, $this->launchBatch($requests, $timeout), $decode, $retryDelays);

        if ($retryDelays !== []) {
            $sleepSeconds = max($retryDelays);
            $this->logger->warning('ESI 420/429 received on {count} batched requests, sleeping {seconds}s before retry', [
                'count' => count($retryDelays),
                'seconds' => $sleepSeconds,
            ]);
            sleep($sleepSeconds);

            // The error-limit window has just been waited out: the replay is not throttled again.
            $retryRequests = array_intersect_key($requests, $retryDelays);
            // A second consecutive 420/429 is not replayed again: that key stays null.
            $retryResults = $this->collectBatch($retryRequests, $this->launchBatch($retryRequests, $timeout), $decode, $unreplayedDelays);
            $results = array_replace($results, $retryResults);
        }

        return $results;
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, array{endpoint: string, token: ?EveToken}> $requests
     * @return array<TKey, ResponseInterface>
     */
    private function launchBatch(array $requests, int $timeout): array
    {
        $responses = [];
        foreach ($requests as $key => $request) {
            $responses[$key] = $this->request('GET', $request['endpoint'], $request['token'], timeout: $timeout, throttle: false);
        }

        return $responses;
    }

    /**
     * Reads every response of a batch. 420/429 keys map to null and their retry delay is
     * reported in $retryDelays. A 2xx with an unusable JSON body maps to null without replay.
     *
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, array{endpoint: string, token: ?EveToken}> $requests
     * @param array<TKey, ResponseInterface> $responses
     * @param \Closure(ResponseInterface): TValue $decode
     * @param array<TKey, int>|null $retryDelays
     * @param-out array<TKey, int> $retryDelays
     * @return array<TKey, TValue|null>
     */
    private function collectBatch(array $requests, array $responses, \Closure $decode, ?array &$retryDelays): array
    {
        $retryDelays = [];
        $results = [];
        foreach ($responses as $key => $response) {
            $results[$key] = null;
            try {
                $statusCode = $response->getStatusCode();
                if ($statusCode === 420 || $statusCode === 429) {
                    $retryDelays[$key] = $this->retryDelaySeconds($response, $statusCode);
                    continue;
                }

                $this->processRateLimitHeaders($response);
                if ($statusCode >= 200 && $statusCode < 300) {
                    $results[$key] = $decode($response);
                } else {
                    // Consume response body to prevent curl handle issues
                    $response->getContent(false);
                }
            } catch (DecodingExceptionInterface $e) {
                $this->logger->warning('ESI batched response for {endpoint} has an unusable JSON body, key ignored: {message}', [
                    'endpoint' => $requests[$key]['endpoint'],
                    'message' => $e->getMessage(),
                ]);
            } catch (TransportExceptionInterface) {
                // A network error leaves this key null; the other keys of the batch are unaffected.
            }
        }

        return $results;
    }

    /**
     * @param array<string, string> $extraHeaders
     * @param array<int|string, mixed>|null $jsonBody
     */
    private function request(
        string $method,
        string $endpoint,
        ?EveToken $token,
        array $extraHeaders = [],
        ?array $jsonBody = null,
        int $timeout = self::REQUEST_TIMEOUT,
        bool $throttle = true,
        ?string $baseUrl = null,
    ): ResponseInterface {
        if ($throttle) {
            $this->throttleIfNeeded();
        }

        $options = [
            'headers' => $this->buildHeaders($token, $extraHeaders),
            'timeout' => $timeout,
        ];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        return $this->httpClient->request($method, ($baseUrl ?? $this->baseUrl) . $endpoint, $options);
    }

    /**
     * Sends the request and replays it once, identically, after a 420 (error limited)
     * or 429 (rate limited) response. A second consecutive failure is returned as is.
     *
     * @param array<string, string> $extraHeaders
     * @param array<int|string, mixed>|null $jsonBody
     */
    private function requestWithRetry(
        string $method,
        string $endpoint,
        ?EveToken $token,
        array $extraHeaders = [],
        ?array $jsonBody = null,
        ?string $baseUrl = null,
        int $timeout = self::REQUEST_TIMEOUT,
    ): ResponseInterface {
        $response = $this->request($method, $endpoint, $token, $extraHeaders, $jsonBody, $timeout, baseUrl: $baseUrl);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 420 && $statusCode !== 429) {
            return $response;
        }

        $sleepSeconds = $this->retryDelaySeconds($response, $statusCode);
        $this->logger->warning('ESI {status} received, sleeping {seconds}s before retry', [
            'status' => $statusCode,
            'seconds' => $sleepSeconds,
            'endpoint' => $endpoint,
        ]);
        sleep($sleepSeconds);

        // The error-limit window has just been waited out: throttling again would double the pause.
        return $this->request($method, $endpoint, $token, $extraHeaders, $jsonBody, $timeout, throttle: false, baseUrl: $baseUrl);
    }

    private function unversionedBaseUrl(): string
    {
        return str_ends_with($this->baseUrl, self::VERSION_SEGMENT)
            ? substr($this->baseUrl, 0, -strlen(self::VERSION_SEGMENT))
            : $this->baseUrl;
    }

    /**
     * Records the error-limit headers of a 420/429 response, releases its body and returns
     * how long to wait before replaying it: Retry-After for a 429, else the error-limit reset.
     */
    private function retryDelaySeconds(ResponseInterface $response, int $statusCode): int
    {
        $this->processRateLimitHeaders($response);
        // Consume response body to prevent curl handle issues
        $response->getContent(false);

        return $statusCode === 429
            ? $this->retryAfterSeconds($response) ?? $this->errorLimitWaitSeconds()
            : $this->errorLimitWaitSeconds();
    }

    /**
     * Seconds requested by the Retry-After header, capped; null when absent or not a delay in seconds.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
        if ($retryAfter === null || !ctype_digit($retryAfter)) {
            return null;
        }

        return min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS);
    }

    private function errorLimitWaitSeconds(): int
    {
        return max($this->errorLimitReset, 1);
    }

    private function conditionalGet(string $endpoint, ?EveToken $token, string $etag): ResponseInterface
    {
        $response = $this->requestWithRetry('GET', $endpoint, $token, ['If-None-Match' => $etag]);

        $this->processRateLimitHeaders($response);

        if ($response->getStatusCode() === 304) {
            $response->getContent(false);
        }

        return $response;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function buildHeaders(?EveToken $token, array $extra = []): array
    {
        $headers = ['Accept' => 'application/json', ...$extra];

        if ($token !== null) {
            $accessToken = $this->tokenManager->getValidAccessToken($token);
            $headers['Authorization'] = "Bearer {$accessToken}";
        }

        return $headers;
    }

    /**
     * @return array<mixed>
     */
    private function handleResponse(ResponseInterface $response, string $endpoint): array
    {
        try {
            $statusCode = $response->getStatusCode();
            $this->processRateLimitHeaders($response);

            if ($statusCode >= 200 && $statusCode < 300) {
                return $response->toArray();
            }

            throw $this->failedResponseException($response, $statusCode, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * @throws TransportExceptionInterface
     */
    private function failedResponseException(ResponseInterface $response, int $statusCode, string $endpoint): EsiApiException
    {
        // Consume response body to prevent curl handle issues
        $response->getContent(false);

        $message = match ($statusCode) {
            401 => 'Authentication failed',
            403 => 'Access forbidden',
            404 => 'Resource not found',
            420 => 'Error limited',
            429 => 'Rate limit exceeded',
            500, 502, 503, 504 => 'ESI server error',
            default => 'ESI request failed',
        };

        return EsiApiException::fromResponse($statusCode, $message, $endpoint);
    }

    private function processRateLimitHeaders(ResponseInterface $response): void
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            return;
        }

        if (isset($headers['x-esi-error-limit-remain'][0])) {
            $this->errorLimitRemain = (int) $headers['x-esi-error-limit-remain'][0];
        }
        if (isset($headers['x-esi-error-limit-reset'][0])) {
            $this->errorLimitReset = (int) $headers['x-esi-error-limit-reset'][0];
        }
    }

    private function throttleIfNeeded(): void
    {
        if ($this->errorLimitRemain < 5) {
            $sleepSeconds = $this->errorLimitWaitSeconds();
            $this->logger->warning('ESI error limit critical ({remain} remaining), pausing {seconds}s', [
                'remain' => $this->errorLimitRemain,
                'seconds' => $sleepSeconds,
            ]);
            sleep($sleepSeconds);
        } elseif ($this->errorLimitRemain < 20) {
            $delayMs = (20 - $this->errorLimitRemain) * 100;
            $this->logger->info('ESI error limit low ({remain} remaining), throttling {delay}ms', [
                'remain' => $this->errorLimitRemain,
                'delay' => $delayMs,
            ]);
            usleep($delayMs * 1000);
        }
    }

    /**
     * @return array<mixed>
     */
    private function cacheResponse(string $cacheKey, ResponseInterface $response, string $endpoint): array
    {
        $data = $this->handleResponse($response, $endpoint);
        $headers = $this->responseHeaders($response);

        $this->storeInCache($cacheKey, $data, $headers['etag'][0] ?? null, $headers);

        return $data;
    }

    /**
     * The fresh item expires with ESI's Expires; the revalidation item outlives it so the
     * ETag can still be sent once the data is stale.
     *
     * @param array<mixed> $data
     * @param array<string, list<string>> $headers
     */
    private function storeInCache(string $cacheKey, array $data, ?string $etag, array $headers): void
    {
        $freshItem = $this->esiCache->getItem($cacheKey);
        $freshItem->set(['data' => $data]);
        $expiresAt = $this->expiresAt($headers);
        if ($expiresAt !== null) {
            $freshItem->expiresAt($expiresAt);
        } else {
            $freshItem->expiresAfter(self::DEFAULT_CACHE_TTL_SECONDS);
        }
        $this->esiCache->save($freshItem);

        $revalidationItem = $this->esiCache->getItem($this->getRevalidationCacheKey($cacheKey));
        if ($etag !== null) {
            $revalidationItem->set(['data' => $data, 'etag' => $etag]);
            $revalidationItem->expiresAfter(self::REVALIDATION_TTL_SECONDS);
            $this->esiCache->save($revalidationItem);
        } else {
            $this->esiCache->deleteItem($revalidationItem->getKey());
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function expiresAt(array $headers): ?\DateTimeImmutable
    {
        $expires = $headers['expires'][0] ?? null;
        if ($expires === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($expires);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function responseHeaders(ResponseInterface $response): array
    {
        try {
            return $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            return [];
        }
    }

    private function getCacheKey(string $endpoint, ?EveToken $token): string
    {
        $key = 'esi_' . md5($endpoint);

        if ($token !== null) {
            $key .= '_' . $token->getCharacter()?->getId()?->toRfc4122();
        }

        return $key;
    }

    private function getRevalidationCacheKey(string $cacheKey): string
    {
        return $cacheKey . '_etag';
    }
}
