<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Action;

use App\Constant\EveConstants;
use App\Entity\CachedStructure;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\Project\Action\ActionPlaceResolver;

trait BuildsActionPlaces
{
    private const string JITA_STATION_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string JITA_SYSTEM_NAME = 'Jita';
    private const int PERIMETER_SYSTEM_ID = 30_000_144;
    private const string PERIMETER_SYSTEM_NAME = 'Perimeter';
    private const int AZBEL_LOCATION_ID = 1_040_000_001_000;
    private const string AZBEL_NAME = 'Azbel';
    private const int MARKET_STRUCTURE_ID = 1_035_466_617_946;
    private const string MARKET_STRUCTURE_NAME = 'Perimeter - Tranquility Trading Tower';

    private function placeResolver(array $ownerConfigs = [], array $cachedStructures = []): ActionPlaceResolver
    {
        $jitaSystem = (new MapSolarSystem())->setSolarSystemId(EveConstants::JITA_SOLAR_SYSTEM_ID)->setSolarSystemName(self::JITA_SYSTEM_NAME);
        $jitaStation = (new StaStation())->setStationId(EveConstants::JITA_STATION_ID)->setStationName(self::JITA_STATION_NAME)->setSolarSystem($jitaSystem);
        $stations = $this->createStub(StaStationRepository::class);
        $stations->method('findByStationId')->willReturnCallback(static fn (int $stationId): ?StaStation => EveConstants::JITA_STATION_ID === $stationId ? $jitaStation : null);
        $stations->method('findByStationIds')->willReturnCallback(static fn (array $stationIds): array => \in_array(EveConstants::JITA_STATION_ID, $stationIds, true) ? [EveConstants::JITA_STATION_ID => $jitaStation] : []);
        $stations->method('find')->willReturnCallback(static fn (mixed $stationId): ?StaStation => EveConstants::JITA_STATION_ID === $stationId ? $jitaStation : null);

        $structures = $this->createStub(CachedStructureRepository::class);
        $structures->method('findByStructureId')->willReturnCallback(static fn (int $structureId): ?CachedStructure => $cachedStructures[$structureId] ?? null);
        $structures->method('findByStructureIds')->willReturnCallback(static fn (array $structureIds): array => array_intersect_key($cachedStructures, array_flip($structureIds)));

        $solarSystems = [
            self::PERIMETER_SYSTEM_ID => (new MapSolarSystem())->setSolarSystemId(self::PERIMETER_SYSTEM_ID)->setSolarSystemName(self::PERIMETER_SYSTEM_NAME),
            EveConstants::JITA_SOLAR_SYSTEM_ID => $jitaSystem,
        ];
        $systems = $this->createStub(MapSolarSystemRepository::class);
        $systems->method('findBySolarSystemId')->willReturnCallback(static fn (int $solarSystemId): ?MapSolarSystem => $solarSystems[$solarSystemId] ?? null);
        $systems->method('find')->willReturnCallback(static fn (mixed $solarSystemId): ?MapSolarSystem => $solarSystems[$solarSystemId] ?? null);

        $configs = $this->createStub(IndustryStructureConfigRepository::class);
        $configs->method('findByUser')->willReturn($ownerConfigs);

        return new ActionPlaceResolver($stations, $structures, $configs, $systems);
    }

    private function azbelConfig(?int $locationId = self::AZBEL_LOCATION_ID): IndustryStructureConfig
    {
        return (new IndustryStructureConfig())
            ->setName(self::AZBEL_NAME)
            ->setLocationId($locationId)
            ->setSolarSystemId(self::PERIMETER_SYSTEM_ID);
    }

    private function viewerFollowingTheMarketStructure(): User
    {
        return (new User())
            ->setPreferredMarketStructureId(self::MARKET_STRUCTURE_ID)
            ->setPreferredMarketStructureName(self::MARKET_STRUCTURE_NAME);
    }
}
