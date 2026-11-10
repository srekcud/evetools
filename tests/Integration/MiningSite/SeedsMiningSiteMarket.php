<?php

declare(strict_types=1);

namespace App\Tests\Integration\MiningSite;

use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

trait SeedsMiningSiteMarket
{
    private const int ALDRANETTE = 30_003_504;
    private const int UNKNOWN_SOLAR_SYSTEM = 30_999_999;
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int SCORDITE = 1228;
    private const int COMPRESSED_SCORDITE = 62548;
    private const int UNKNOWN_TYPE = 999_999;
    private const float VELDSPAR_VOLUME = 0.1;
    private const float VELDSPAR_UNIT_PRICE = 15.0;
    private const string JITA_SYNCED_AT = '2026-11-05T19:55:00+00:00';

    private const int SCAN_STRUCTURE_ID = 60_003_760;
    private const string SCAN_STRUCTURE_NAME = 'Refinery B-1';
    private const string STRUCTURE_SYNCED_AT = '2026-11-05T19:50:00+00:00';

    private array $jitaBuyPrices = [
        self::COMPRESSED_VELDSPAR => self::VELDSPAR_UNIT_PRICE,
        self::COMPRESSED_SCORDITE => 25.0,
    ];

    private array $jitaSellPrices = [
        self::COMPRESSED_VELDSPAR => 18.0,
        self::COMPRESSED_SCORDITE => 28.0,
    ];

    private array $structureBuyPrices = [
        self::COMPRESSED_VELDSPAR => 21.0,
    ];

    private function replaceJitaBuyMarket(): void
    {
        $jitaMarket = $this->createStub(JitaMarketService::class);
        $jitaMarket->method('getBuyPrices')->willReturnCallback(fn (array $typeIds): array => array_combine($typeIds, array_map(fn (int $typeId): ?float => $this->jitaBuyPrices[$typeId] ?? null, $typeIds)));
        $jitaMarket->method('getPrices')->willReturnCallback(fn (array $typeIds): array => array_combine($typeIds, array_map(fn (int $typeId): ?float => $this->jitaSellPrices[$typeId] ?? null, $typeIds)));
        $jitaMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::JITA_SYNCED_AT));
        self::getContainer()->set(JitaMarketService::class, $jitaMarket);
        self::getContainer()->set('logger', new NullLogger());
    }

    private function replaceStructureBuyMarket(): void
    {
        $structureMarket = $this->createStub(StructureMarketService::class);
        $structureMarket->method('getHighestBuyPrices')->willReturnCallback(
            fn (int $structureId, array $typeIds): array => array_combine($typeIds, array_map(fn (int $typeId): ?float => $structureId === self::SCAN_STRUCTURE_ID ? ($this->structureBuyPrices[$typeId] ?? null) : null, $typeIds)),
        );
        $structureMarket->method('getLastSyncTime')->willReturn(new \DateTimeImmutable(self::STRUCTURE_SYNCED_AT));
        self::getContainer()->set(StructureMarketService::class, $structureMarket);
    }

    private function seedMiningSiteSde(EntityManagerInterface $em): void
    {
        $region = (new MapRegion())->setRegionId(10_000_032)->setRegionName('Sinq Laison');
        $constellation = (new MapConstellation())->setConstellationId(20_000_441)->setConstellationName('Ancbeu')->setRegion($region);
        $aldranette = (new MapSolarSystem())->setSolarSystemId(self::ALDRANETTE)->setSolarSystemName('Aldranette')->setConstellation($constellation)->setRegionId(10_000_032);
        $category = (new InvCategory())->setCategoryId(25)->setCategoryName('Asteroid');
        $veldsparGroup = (new InvGroup())->setGroupId(462)->setGroupName('Veldspar')->setCategory($category);
        $scorditeGroup = (new InvGroup())->setGroupId(461)->setGroupName('Scordite')->setCategory($category);
        foreach ([$region, $constellation, $aldranette, $category, $veldsparGroup, $scorditeGroup] as $staticRow) {
            $em->persist($staticRow);
        }
        foreach ([
            [self::VELDSPAR, 'Veldspar', $veldsparGroup, self::VELDSPAR_VOLUME],
            [self::COMPRESSED_VELDSPAR, 'Compressed Veldspar', $veldsparGroup, 0.001],
            [self::SCORDITE, 'Scordite', $scorditeGroup, 0.15],
            [self::COMPRESSED_SCORDITE, 'Compressed Scordite', $scorditeGroup, 0.0015],
        ] as [$typeId, $typeName, $group, $volume]) {
            $em->persist((new InvType())->setTypeId($typeId)->setTypeName($typeName)->setGroup($group)->setVolume($volume)->setPortionSize(100)->setPublished(true));
        }
    }
}
