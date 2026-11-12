<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Sde\IndustryActivityMaterial;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[Group('issue-736')]
final class JitaMarketInventionSyncTest extends IntegrationTestCase
{
    private const INVENTION_ACTIVITY_ID = 8;
    private const DATACORE_ELECTRONIC_ENGINEERING = 20418;
    private const BLUEPRINT_TYPE_ID = 11_567;
    private const DATACORE_QUANTITY_PER_RUN = 2;
    private const JITA_STATION_ID = 60_003_760;
    private const DATACORE_CHEAPEST_PRICE = 187_500.0;
    private const DATACORE_CHEAPEST_VOLUME = 40;
    private const DATACORE_OTHER_PRICE = 199_000.0;
    private const DATACORE_OTHER_VOLUME = 60;

    public function testSyncJitaMarketCachesInventionMaterialSellOrders(): void
    {
        $this->em->persist(
            (new IndustryActivityMaterial())
                ->setTypeId(self::BLUEPRINT_TYPE_ID)
                ->setActivityId(self::INVENTION_ACTIVITY_ID)
                ->setMaterialTypeId(self::DATACORE_ELECTRONIC_ENGINEERING)
                ->setQuantity(self::DATACORE_QUANTITY_PER_RUN)
        );
        $this->flushAndClear();

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getBatch')->willReturnCallback(
            static fn (array $endpoints): array => array_map(
                static fn (string $endpoint): array => str_contains($endpoint, 'type_id=' . self::DATACORE_ELECTRONIC_ENGINEERING)
                    ? [
                        ['price' => self::DATACORE_OTHER_PRICE, 'volume_remain' => self::DATACORE_OTHER_VOLUME, 'location_id' => self::JITA_STATION_ID, 'is_buy_order' => false],
                        ['price' => self::DATACORE_CHEAPEST_PRICE, 'volume_remain' => self::DATACORE_CHEAPEST_VOLUME, 'location_id' => self::JITA_STATION_ID, 'is_buy_order' => false],
                    ]
                    : [],
                $endpoints,
            )
        );

        $jitaMarket = new JitaMarketService(
            $esiClient,
            new ArrayAdapter(),
            self::getContainer()->get(Connection::class),
            new NullLogger(),
        );

        $result = $jitaMarket->syncJitaMarket();

        $this->assertTrue($result['success']);
        $this->assertSame(
            [
                ['price' => self::DATACORE_CHEAPEST_PRICE, 'volume' => self::DATACORE_CHEAPEST_VOLUME],
                ['price' => self::DATACORE_OTHER_PRICE, 'volume' => self::DATACORE_OTHER_VOLUME],
            ],
            $jitaMarket->getSellOrders(self::DATACORE_ELECTRONIC_ENGINEERING),
        );
        $this->assertSame(
            [self::DATACORE_ELECTRONIC_ENGINEERING => self::DATACORE_CHEAPEST_PRICE],
            $jitaMarket->getCheapestPercentilePrices([self::DATACORE_ELECTRONIC_ENGINEERING], 0.05),
        );
    }
}
