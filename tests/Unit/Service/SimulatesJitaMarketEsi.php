<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Constant\EveConstants;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\JitaMarketService;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait SimulatesJitaMarketEsi
{
    private const string SIMULATED_ESI_BASE_URL = 'https://esi.test/latest';
    private const int PERIMETER_STATION_ID = 60011866;
    private const int SEEDED_MORPHITE = 11399;

    private array $requestedEsiUrls = [];

    private function createJitaMarketServiceOnSimulatedEsi(
        array $esiResponsesByRoute,
        CacheItemPoolInterface $marketCache,
        Connection $connection,
    ): JitaMarketService {
        $callsByRoute = [];
        $esiHttpClient = new MockHttpClient(function (string $method, string $url) use ($esiResponsesByRoute, &$callsByRoute): MockResponse {
            $this->requestedEsiUrls[] = $url;
            $route = $this->simulatedEsiRoute($url);
            $callIndex = $callsByRoute[$route] ?? 0;
            $callsByRoute[$route] = $callIndex + 1;
            $response = $esiResponsesByRoute[$route][$callIndex] ?? null;

            if ($response instanceof \Throwable) {
                throw $response;
            }
            if ($response === null) {
                return new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
            }

            return $response instanceof MockResponse ? $response : $this->esiJsonResponse($response);
        });

        $esiClient = new EsiClient(
            $esiHttpClient,
            $this->createStub(CacheItemPoolInterface::class),
            $this->createStub(TokenManager::class),
            self::SIMULATED_ESI_BASE_URL,
            new NullLogger(),
        );

        return new JitaMarketService(
            esiClient: $esiClient,
            cache: $marketCache,
            connection: $connection,
            logger: new NullLogger(),
        );
    }

    private function seedMainJitaOrderBooks(CacheItemPoolInterface $marketCache): void
    {
        foreach (['jita_market_prices' => $this->seededSellOrderBooks(), 'jita_market_buy_prices' => $this->seededBuyOrderBooks()] as $key => $orderBooks) {
            $cacheItem = $marketCache->getItem($key);
            $cacheItem->set($orderBooks);
            $marketCache->save($cacheItem);
        }
    }

    private function seededSellOrderBooks(): array
    {
        return [self::SEEDED_MORPHITE => [['price' => 14250.5, 'volume' => 64]]];
    }

    private function seededBuyOrderBooks(): array
    {
        return [self::SEEDED_MORPHITE => [['price' => 13875.25, 'volume' => 210]]];
    }

    private function industryMaterialsConnection(array $industryMaterialTypeIds): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(array_map('strval', $industryMaterialTypeIds));

        return $connection;
    }

    private function simulatedEsiRoute(string $url): string
    {
        $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/latest'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $route = $path . '#' . ($query['type_id'] ?? '');

        return isset($query['page']) && $query['page'] !== '1' ? $route . '@' . $query['page'] : $route;
    }

    private function esiUrlsWithoutPage(array $urls): array
    {
        return array_map(static function (string $url): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            unset($query['page']);

            return strtok($url, '?') . '?' . http_build_query($query);
        }, $urls);
    }

    private function theForgeOrdersRoute(int $typeId, ?int $page = null): string
    {
        $route = sprintf('/markets/%d/orders/#%d', EveConstants::THE_FORGE_REGION_ID, $typeId);

        return $page === null ? $route : $route . '@' . $page;
    }

    private function theForgeOrdersUrl(int $typeId): string
    {
        return sprintf('%s/markets/%d/orders/?order_type=all&type_id=%d', self::SIMULATED_ESI_BASE_URL, EveConstants::THE_FORGE_REGION_ID, $typeId);
    }

    private function esiSellOrder(int $typeId, float $price, int $volumeRemain, int $locationId): array
    {
        return [
            'type_id' => $typeId,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain + 7919,
            'min_volume' => 1,
            'range' => 'region',
            'is_buy_order' => false,
            'location_id' => $locationId,
        ];
    }

    private function esiBuyOrder(int $typeId, float $price, int $volumeRemain, int $locationId, string $range = 'station', int $minVolume = 1): array
    {
        return [
            'type_id' => $typeId,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain + 6131,
            'min_volume' => $minVolume,
            'range' => $range,
            'is_buy_order' => true,
            'location_id' => $locationId,
        ];
    }

    private function esiJsonResponse(array $body, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), [
            'http_code' => 200,
            'response_headers' => ['Content-Type' => 'application/json', ...$headers],
        ]);
    }

    private function esiServiceUnavailable(): MockResponse
    {
        return new MockResponse('{"error":"Service unavailable"}', ['http_code' => 503]);
    }
}
