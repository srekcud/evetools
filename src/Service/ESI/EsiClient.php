<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Entity\EveToken;
use App\Exception\EsiApiException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class EsiClient
{
    private const REQUEST_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $esiCache,
        private readonly TokenManager $tokenManager,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function get(string $endpoint, ?EveToken $token = null, array $extraHeaders = []): array
    {
        try {
            $response = $this->rawGet($endpoint, $token, self::REQUEST_TIMEOUT, $extraHeaders);
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
            $response = $this->rawGet($endpoint, $token, $timeout);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return json_decode($response->getContent(), false);
            }

            throw EsiApiException::fromResponse($statusCode, 'ESI request failed', $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * Get multiple scalar values concurrently.
     * Returns an array keyed by the request key, with null for failed requests.
     *
     * @param array<string, array{endpoint: string, token: ?EveToken}> $requests
     * @return array<string, mixed>
     */
    public function getScalarBatch(array $requests, int $timeout = 10): array
    {
        $responses = [];

        // Start all requests (non-blocking)
        foreach ($requests as $key => $request) {
            try {
                $responses[$key] = $this->httpClient->request('GET', $this->baseUrl . $request['endpoint'], [
                    'headers' => $this->buildHeaders($request['token']),
                    'timeout' => $timeout,
                    'user_data' => $key,
                ]);
            } catch (\Throwable) {
                $responses[$key] = null;
            }
        }

        // Collect all responses (concurrent processing)
        $results = [];
        foreach ($responses as $key => $response) {
            if ($response === null) {
                $results[$key] = null;
                continue;
            }

            try {
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $results[$key] = json_decode($response->getContent(), false);
                } else {
                    $results[$key] = null;
                }
            } catch (\Throwable) {
                $results[$key] = null;
            }
        }

        return $results;
    }

    /**
     * @return array<mixed>
     */
    public function getWithCache(string $endpoint, ?EveToken $token = null): array
    {
        $cacheKey = $this->getCacheKey($endpoint, $token);
        $cacheItem = $this->esiCache->getItem($cacheKey);

        try {
            if ($cacheItem->isHit()) {
                $cachedData = $cacheItem->get();
                $etag = $cachedData['etag'] ?? null;
                $data = $cachedData['data'] ?? [];

                // Try conditional request with ETag
                if ($etag !== null) {
                    $response = $this->conditionalGet($endpoint, $token, $etag);

                    if ($response === null) {
                        // 304 Not Modified
                        return $data;
                    }

                    return $this->cacheResponse($cacheKey, $response, $endpoint);
                }

                return $data;
            }

            $response = $this->rawGet($endpoint, $token);

            return $this->cacheResponse($cacheKey, $response, $endpoint);
        } catch (TransportExceptionInterface $e) {
            // On network error, return cached data if available
            if ($cacheItem->isHit()) {
                $cachedData = $cacheItem->get();
                return $cachedData['data'] ?? [];
            }
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
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
                $response = $this->rawGet($paginatedEndpoint, $token);
                $statusCode = $response->getStatusCode();

                // Get headers before consuming body
                $headers = $response->getHeaders(false);
                $pages = (int) ($headers['x-pages'][0] ?? 1);

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
     * @return array<mixed>
     */
    public function post(string $endpoint, array $body, ?EveToken $token = null): array
    {
        $headers = $this->buildHeaders($token, ['Content-Type' => 'application/json']);

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl . $endpoint, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            return $this->handleResponse($response, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    private function rawGet(string $endpoint, ?EveToken $token, int $timeout = self::REQUEST_TIMEOUT, array $extraHeaders = []): ResponseInterface
    {
        return $this->httpClient->request('GET', $this->baseUrl . $endpoint, [
            'headers' => $this->buildHeaders($token, $extraHeaders),
            'timeout' => $timeout,
        ]);
    }

    private function conditionalGet(string $endpoint, ?EveToken $token, string $etag): ?ResponseInterface
    {
        $headers = $this->buildHeaders($token, ['If-None-Match' => $etag]);

        $response = $this->httpClient->request('GET', $this->baseUrl . $endpoint, [
            'headers' => $headers,
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if ($response->getStatusCode() === 304) {
            $response->getContent(false);
            return null;
        }

        return $response;
    }

    /**
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

            if ($statusCode >= 200 && $statusCode < 300) {
                return $response->toArray();
            }

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

            throw EsiApiException::fromResponse($statusCode, $message, $endpoint);
        } catch (TransportExceptionInterface $e) {
            throw EsiApiException::fromResponse(0, 'Network error: ' . $e->getMessage(), $endpoint);
        }
    }

    /**
     * @return array<mixed>
     */
    private function cacheResponse(string $cacheKey, ResponseInterface $response, string $endpoint): array
    {
        $data = $this->handleResponse($response, $endpoint);

        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            $headers = [];
        }

        $etag = $headers['etag'][0] ?? null;
        $expires = $headers['expires'][0] ?? null;

        $cacheItem = $this->esiCache->getItem($cacheKey);
        $cacheItem->set([
            'data' => $data,
            'etag' => $etag,
        ]);

        if ($expires !== null) {
            try {
                $expiresAt = new \DateTimeImmutable($expires);
                $cacheItem->expiresAt($expiresAt);
            } catch (\Exception) {
                $cacheItem->expiresAfter(300); // Default 5 minutes
            }
        } else {
            $cacheItem->expiresAfter(300);
        }

        $this->esiCache->save($cacheItem);

        return $data;
    }

    private function getCacheKey(string $endpoint, ?EveToken $token): string
    {
        $key = 'esi_' . md5($endpoint);

        if ($token !== null) {
            $key .= '_' . $token->getCharacter()?->getId()?->toRfc4122();
        }

        return $key;
    }
}
