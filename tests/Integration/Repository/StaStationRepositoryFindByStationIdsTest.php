<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Sde\MapConstellation;
use App\Entity\Sde\MapRegion;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Repository\Sde\StaStationRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-814')]
final class StaStationRepositoryFindByStationIdsTest extends IntegrationTestCase
{
    private const int FIXTURE_REGION_ID = 990_814;
    private const int FIXTURE_CONSTELLATION_ID = 990_814_001;
    private const int FIXTURE_SOLAR_SYSTEM_ID = 990_814_002;
    private const int FIRST_STATION_ID = 990_814_101;
    private const int SECOND_STATION_ID = 990_814_102;
    private const int NOT_REQUESTED_STATION_ID = 990_814_103;
    private const int STATION_UNKNOWN_TO_SDE = 990_814_199;

    public function testStationsAreReturnedIndexedByStationIdWithNameAndSolarSystem(): void
    {
        $solarSystem = $this->solarSystem();
        $this->station(self::FIRST_STATION_ID, 'Fixture 814 I - Moon 1', $solarSystem);
        $this->station(self::SECOND_STATION_ID, 'Fixture 814 II - Moon 2', $solarSystem);
        $this->station(self::NOT_REQUESTED_STATION_ID, 'Fixture 814 III - Moon 3', $solarSystem);
        $this->flushAndClear();

        $stations = $this->repository()->findByStationIds([self::FIRST_STATION_ID, self::SECOND_STATION_ID, self::STATION_UNKNOWN_TO_SDE]);

        $returnedStationIds = array_keys($stations);
        sort($returnedStationIds);

        self::assertSame([self::FIRST_STATION_ID, self::SECOND_STATION_ID], $returnedStationIds);
        self::assertSame('Fixture 814 I - Moon 1', $stations[self::FIRST_STATION_ID]->getStationName());
        self::assertSame(self::FIXTURE_SOLAR_SYSTEM_ID, $stations[self::SECOND_STATION_ID]->getSolarSystem()->getSolarSystemId());
    }

    public function testNoGivenStationReturnsNoStation(): void
    {
        self::assertSame([], $this->repository()->findByStationIds([]));
    }

    private function repository(): StaStationRepository
    {
        return $this->em->getRepository(StaStation::class);
    }

    private function solarSystem(): MapSolarSystem
    {
        $region = (new MapRegion())->setRegionId(self::FIXTURE_REGION_ID)->setRegionName('Fixture Region 814');
        $constellation = (new MapConstellation())
            ->setConstellationId(self::FIXTURE_CONSTELLATION_ID)
            ->setConstellationName('Fixture Constellation 814')
            ->setRegion($region);
        $solarSystem = (new MapSolarSystem())
            ->setSolarSystemId(self::FIXTURE_SOLAR_SYSTEM_ID)
            ->setSolarSystemName('Fixture System 814')
            ->setConstellation($constellation)
            ->setRegionId(self::FIXTURE_REGION_ID);

        $this->em->persist($region);
        $this->em->persist($constellation);
        $this->em->persist($solarSystem);

        return $solarSystem;
    }

    private function station(int $stationId, string $stationName, MapSolarSystem $solarSystem): void
    {
        $this->em->persist(
            (new StaStation())
                ->setStationId($stationId)
                ->setStationName($stationName)
                ->setSolarSystem($solarSystem)
                ->setConstellationId(self::FIXTURE_CONSTELLATION_ID)
                ->setRegionId(self::FIXTURE_REGION_ID),
        );
    }
}
