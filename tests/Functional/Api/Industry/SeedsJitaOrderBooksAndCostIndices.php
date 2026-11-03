<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Industry;

use App\Constant\EveConstants;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

trait SeedsJitaOrderBooksAndCostIndices
{
    private array $esiRequests = [];

    private function recordEsiRequestsInsteadOfSendingThem(): void
    {
        $container = self::getContainer();
        $container->set(EsiClient::class, new EsiClient(
            new MockHttpClient(function (string $method, string $url): MockResponse {
                $this->esiRequests[] = $method.' '.$url;

                return new MockResponse('', ['http_code' => 503]);
            }),
            $container->get('esi.cache'),
            $container->get(TokenManager::class),
            'https://esi.evetech.net/latest',
            new NullLogger(),
        ));
    }

    private function seedJitaSellOrderBooks(array $sellOrdersByTypeId): void
    {
        $this->saveInCache('structure_market.cache', 'jita_market_prices', $sellOrdersByTypeId);
    }

    private function seedNoJitaSellOrderFor(int ...$typeIds): void
    {
        foreach ($typeIds as $typeId) {
            $this->saveInCache('structure_market.cache', 'jita_ondemand_'.$typeId, ['sell' => [], 'buy' => []]);
        }
    }

    private function seedAdjustedPrices(array $adjustedPricesByTypeId): void
    {
        foreach ($adjustedPricesByTypeId as $typeId => $adjustedPrice) {
            $this->saveInCache('esi_cost_index.cache', 'esi_adjusted_price_'.$typeId, $adjustedPrice);
        }
    }

    private function seedJitaCostIndex(string $activity, float $costIndex): void
    {
        $this->saveInCache('esi_cost_index.cache', 'esi_cost_index_'.EveConstants::JITA_SOLAR_SYSTEM_ID.'_'.$activity, $costIndex);
    }

    private function saveInCache(string $poolId, string $key, mixed $value): void
    {
        $pool = self::getContainer()->get($poolId);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->save($pool->getItem($key)->set($value));
    }

    private function fieldsOf(array $object, array $fields): array
    {
        return array_map(static fn (string $field): mixed => \array_key_exists($field, $object) ? $object[$field] : 'absent', $fields);
    }
}
