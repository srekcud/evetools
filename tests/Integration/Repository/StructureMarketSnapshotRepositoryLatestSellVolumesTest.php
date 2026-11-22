<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\StructureMarketSnapshot;
use App\Repository\StructureMarketSnapshotRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(StructureMarketSnapshotRepository::class)]
#[Group('issue-1287')]
final class StructureMarketSnapshotRepositoryLatestSellVolumesTest extends IntegrationTestCase
{
    private const int STRUCTURE_ID = 1_035_466_543_993;
    private const int OTHER_STRUCTURE_ID = 1_035_466_617_946;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int ZYDRINE = 39;

    private StructureMarketSnapshotRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(StructureMarketSnapshotRepository::class);
    }

    public function testEachTypeTakesTheSellVolumeOfItsMostRecentSnapshot(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::TRITANIUM, 'today', 52_000);
        $this->persistSnapshot(self::STRUCTURE_ID, self::TRITANIUM, '-1 day', 91_000);
        $this->persistSnapshot(self::STRUCTURE_ID, self::PYERITE, '-1 day', 640);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::TRITANIUM, self::PYERITE]);

        self::assertSame([self::TRITANIUM => 52_000, self::PYERITE => 640], $volumes);
    }

    public function testSellVolumeOfZeroIsPresentBecauseNothingIsInSale(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::MEXALLON, 'today', 0);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::MEXALLON]);

        self::assertArrayHasKey(self::MEXALLON, $volumes);
        self::assertSame(0, $volumes[self::MEXALLON]);
    }

    public function testTypeWhoseMostRecentSnapshotIsTooOldIsAbsentEvenWithOlderRowsOnly(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::ISOGEN, '-5 days', 3_700);
        $this->persistSnapshot(self::STRUCTURE_ID, self::ISOGEN, '-9 days', 4_100);
        $this->persistSnapshot(self::STRUCTURE_ID, self::TRITANIUM, 'today', 52_000);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::ISOGEN, self::TRITANIUM]);

        self::assertSame([self::TRITANIUM => 52_000], $volumes);
    }

    public function testTypeWithoutAnySnapshotIsAbsent(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::PYERITE, 'today', 640);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::PYERITE, self::NOCXIUM]);

        self::assertSame([self::PYERITE => 640], $volumes);
        self::assertArrayNotHasKey(self::NOCXIUM, $volumes);
    }

    public function testSnapshotsOfAnotherStructureAndOfTypesNotAskedAreIgnored(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::PYERITE, 'today', 640);
        $this->persistSnapshot(self::OTHER_STRUCTURE_ID, self::PYERITE, 'today', 77_000);
        $this->persistSnapshot(self::OTHER_STRUCTURE_ID, self::ZYDRINE, 'today', 250);
        $this->persistSnapshot(self::STRUCTURE_ID, self::TRITANIUM, 'today', 52_000);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::PYERITE, self::ZYDRINE]);

        self::assertSame([self::PYERITE => 640], $volumes);
    }

    public function testNoTypeAskedReturnsNothing(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::PYERITE, 'today', 640);
        $this->flushAndClear();

        self::assertSame([], $this->repository->latestSellVolumes(self::STRUCTURE_ID, []));
    }

    public function testSellVolumeAboveTheIntegerRangeOfFourBytesIsKeptWhole(): void
    {
        $this->persistSnapshot(self::STRUCTURE_ID, self::TRITANIUM, 'today', 5_000_000_000);
        $this->flushAndClear();

        $volumes = $this->repository->latestSellVolumes(self::STRUCTURE_ID, [self::TRITANIUM]);

        self::assertSame([self::TRITANIUM => 5_000_000_000], $volumes);
    }

    private function persistSnapshot(int $structureId, int $typeId, string $relativeDate, int $sellVolume): void
    {
        $this->em->persist(
            (new StructureMarketSnapshot())
                ->setStructureId($structureId)
                ->setTypeId($typeId)
                ->setDate(new \DateTimeImmutable($relativeDate))
                ->setSellMin(12.5)
                ->setSellOrderCount(3)
                ->setSellVolume($sellVolume),
        );
    }
}
