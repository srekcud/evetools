<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Sde\MapSolarSystemJump;
use App\Repository\Sde\MapSolarSystemJumpRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MapSolarSystemJumpRepository::class)]
final class MapSolarSystemJumpRepositoryTest extends IntegrationTestCase
{
    private const int REGION_ID = 19_990_001;
    private const int CONSTELLATION_ID = 29_990_001;
    private const int TRADE_HUB_SYSTEM_ID = 39_990_001;
    private const int GATE_SYSTEM_ID = 39_990_002;
    private const int WORKSHOP_SYSTEM_ID = 39_990_003;

    public function testStargateStoredOnceIsCrossableFromBothEnds(): void
    {
        $this->seedStargate(self::TRADE_HUB_SYSTEM_ID, self::GATE_SYSTEM_ID);
        $this->seedStargate(self::WORKSHOP_SYSTEM_ID, self::GATE_SYSTEM_ID);
        $this->flushAndClear();

        $adjacencyList = self::getContainer()->get(MapSolarSystemJumpRepository::class)->getAdjacencyList();

        self::assertSame([self::GATE_SYSTEM_ID], $adjacencyList[self::TRADE_HUB_SYSTEM_ID]);
        self::assertSame([self::TRADE_HUB_SYSTEM_ID, self::WORKSHOP_SYSTEM_ID], $this->sorted($adjacencyList[self::GATE_SYSTEM_ID]));
        self::assertSame([self::GATE_SYSTEM_ID], $adjacencyList[self::WORKSHOP_SYSTEM_ID]);
    }

    public function testStargateStoredInBothDirectionsIsListedOncePerEnd(): void
    {
        $this->seedStargate(self::TRADE_HUB_SYSTEM_ID, self::GATE_SYSTEM_ID);
        $this->seedStargate(self::GATE_SYSTEM_ID, self::TRADE_HUB_SYSTEM_ID);
        $this->flushAndClear();

        $adjacencyList = self::getContainer()->get(MapSolarSystemJumpRepository::class)->getAdjacencyList();

        self::assertSame([self::GATE_SYSTEM_ID], $adjacencyList[self::TRADE_HUB_SYSTEM_ID]);
        self::assertSame([self::TRADE_HUB_SYSTEM_ID], $adjacencyList[self::GATE_SYSTEM_ID]);
    }

    private function seedStargate(int $fromSolarSystemId, int $toSolarSystemId): void
    {
        $this->em->persist((new MapSolarSystemJump())
            ->setFromSolarSystemId($fromSolarSystemId)
            ->setToSolarSystemId($toSolarSystemId)
            ->setFromRegionId(self::REGION_ID)
            ->setFromConstellationId(self::CONSTELLATION_ID)
            ->setToRegionId(self::REGION_ID)
            ->setToConstellationId(self::CONSTELLATION_ID));
    }

    /**
     * @param array<int> $solarSystemIds
     *
     * @return list<int>
     */
    private function sorted(array $solarSystemIds): array
    {
        sort($solarSystemIds);

        return $solarSystemIds;
    }
}
