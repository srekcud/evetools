<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\StationMigration;

use App\Service\Industry\Project\StationMigration\ContainerLocationInference;
use App\Service\Industry\Project\StationMigration\StationMigrationReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerLocationInference::class)]
#[Group('issue-1320')]
final class ContainerLocationInferenceTest extends TestCase
{
    private const int STATION_A = 60_003_760;
    private const int STATION_B = 60_008_494;

    private ContainerLocationInference $inference;

    protected function setUp(): void
    {
        $this->inference = new ContainerLocationInference();
    }

    public function testNoAssetNamedLikeTheContainerMeansContainerNotFound(): void
    {
        self::assertSame(StationMigrationReason::ContainerNotFound, $this->inference->of([]));
    }

    public function testASingleAssetInAStationGivesThatStation(): void
    {
        self::assertSame(self::STATION_A, $this->inference->of([self::STATION_A]));
    }

    public function testSeveralAssetsInTheSameStationGiveThatStationOnce(): void
    {
        self::assertSame(self::STATION_B, $this->inference->of([self::STATION_B, self::STATION_B, self::STATION_B]));
    }

    public function testAssetsInTwoStationsMeanContainerInSeveralStations(): void
    {
        self::assertSame(StationMigrationReason::ContainerInSeveralStations, $this->inference->of([self::STATION_A, self::STATION_B]));
    }

    public function testAnAssetWhoseStationIsUnresolvedMeansStationUnresolved(): void
    {
        self::assertSame(StationMigrationReason::StationUnresolved, $this->inference->of([null]));
    }

    public function testAnUnresolvedStationIsNotChosenAgainstAKnownStation(): void
    {
        self::assertSame(StationMigrationReason::StationUnresolved, $this->inference->of([self::STATION_A, null]));
    }

    public function testAnUnresolvedStationAmongSeveralKnownStationsMeansStationUnresolved(): void
    {
        self::assertSame(StationMigrationReason::StationUnresolved, $this->inference->of([self::STATION_A, self::STATION_B, null]));
    }
}
