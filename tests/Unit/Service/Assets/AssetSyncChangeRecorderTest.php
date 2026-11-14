<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Entity\AssetSyncChange;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Repository\AssetSyncChangeRepository;
use App\Service\Assets\AssetSyncChangeCalculator;
use App\Service\Assets\AssetSyncChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetSyncChangeRecorder::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-888')]
final class AssetSyncChangeRecorderTest extends TestCase
{
    private const int CORPORATION_ID = 98_888_001;
    private const int TYPE_DRAKE = 24_698;
    private const int TYPE_TRITANIUM = 34;
    private const int JITA_STATION_ID = 60_003_760;
    private const string STATION_LABEL = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';

    private AssetSyncChangeRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $entityManager;
    private AssetSyncChangeRecorder $recorder;

    /** @var list<AssetSyncChange> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AssetSyncChangeRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof AssetSyncChange) {
                $this->persisted[] = $entity;
            }
        });
        $this->recorder = new AssetSyncChangeRecorder(new AssetSyncChangeCalculator(), $this->repository, $this->entityManager);
    }

    public function testACharacterDiffReplacesThePreviousRowsAndCarriesBothSyncDates(): void
    {
        $character = new Character();
        $previousSync = new \DateTimeImmutable('2026-11-13 08:00:00');
        $syncedAt = new \DateTimeImmutable('2026-11-13 09:00:00');
        $this->repository->expects(self::once())->method('deleteForCharacter')->with($character);

        $this->recorder->recordForCharacter(
            $character,
            [$this->asset(self::TYPE_DRAKE, 'Drake', 5, null, $previousSync)],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 2, null, $syncedAt)],
            $syncedAt,
        );

        self::assertCount(1, $this->persisted);
        $row = $this->persisted[0];
        self::assertSame($character, $row->getCharacter());
        self::assertNull($row->getCorporationId());
        self::assertSame(self::TYPE_DRAKE, $row->getTypeId());
        self::assertSame('Drake', $row->getTypeName());
        self::assertSame(self::JITA_STATION_ID, $row->getLocationId());
        self::assertSame(self::STATION_LABEL, $row->getLocationName());
        self::assertSame(-3, $row->getQuantityDelta());
        self::assertEquals($syncedAt, $row->getSyncedAt());
        self::assertEquals($previousSync, $row->getPreviousSyncedAt());
    }

    public function testThePreviousSyncDateIsTheMostRecentCachedAtOfTheAssetsBeforeTheSync(): void
    {
        $syncedAt = new \DateTimeImmutable('2026-11-13 09:00:00');

        $this->recorder->recordForCharacter(
            new Character(),
            [
                $this->asset(self::TYPE_DRAKE, 'Drake', 5, null, new \DateTimeImmutable('2026-11-13 07:00:00')),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 5, null, new \DateTimeImmutable('2026-11-13 08:30:00')),
            ],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 1, null, $syncedAt)],
            $syncedAt,
        );

        self::assertEquals(new \DateTimeImmutable('2026-11-13 08:30:00'), $this->persisted[0]->getPreviousSyncedAt());
    }

    public function testASyncWithoutAnyAssetBeforeItWritesNothingAndKeepsTheStoredRows(): void
    {
        $this->repository->expects(self::never())->method('deleteForCharacter');
        $syncedAt = new \DateTimeImmutable('2026-11-13 09:00:00');

        $this->recorder->recordForCharacter(
            new Character(),
            [],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 3, null, $syncedAt)],
            $syncedAt,
        );

        self::assertSame([], $this->persisted);
    }

    public function testAnEmptyDiffKeepsTheNonEmptyDiffAlreadyStored(): void
    {
        $this->repository->expects(self::never())->method('deleteForCharacter');
        $sync = new \DateTimeImmutable('2026-11-13 09:00:00');

        $this->recorder->recordForCharacter(
            new Character(),
            [$this->asset(self::TYPE_DRAKE, 'Drake', 3, null, $sync)],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 3, null, $sync)],
            $sync,
        );

        self::assertSame([], $this->persisted);
    }

    public function testACorporationDiffIgnoresAssetsOutsideTheHangarDivisions(): void
    {
        $sync = new \DateTimeImmutable('2026-11-13 09:00:00');

        $this->recorder->recordForCorporation(
            self::CORPORATION_ID,
            [
                $this->asset(self::TYPE_DRAKE, 'Drake', 1, 'Ships', $sync),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 2, null, $sync),
            ],
            [
                $this->asset(self::TYPE_DRAKE, 'Drake', 4, 'Ships', $sync),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 9, null, $sync),
            ],
            $sync,
        );

        self::assertCount(1, $this->persisted);
        self::assertSame(self::CORPORATION_ID, $this->persisted[0]->getCorporationId());
        self::assertNull($this->persisted[0]->getCharacter());
        self::assertSame('Ships', $this->persisted[0]->getDivisionName());
        self::assertSame(3, $this->persisted[0]->getQuantityDelta());
    }

    public function testACorporationDiffReplacesThePreviousRowsOfThatCorporationOnly(): void
    {
        $this->repository->expects(self::once())->method('deleteForCorporation')->with(self::CORPORATION_ID);
        $sync = new \DateTimeImmutable('2026-11-13 09:00:00');

        $this->recorder->recordForCorporation(
            self::CORPORATION_ID,
            [$this->asset(self::TYPE_DRAKE, 'Drake', 1, 'Ships', $sync)],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 2, 'Ships', $sync)],
            $sync,
        );

        self::assertCount(1, $this->persisted);
    }

    private function asset(int $typeId, ?string $typeName, int $quantity, ?string $divisionName, \DateTimeImmutable $cachedAt): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId(random_int(1_000_000_000_000, 9_000_000_000_000))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId(self::JITA_STATION_ID)
            ->setLocationName(self::STATION_LABEL)
            ->setLocationType('station')
            ->setDivisionName($divisionName)
            ->setCachedAt($cachedAt);
    }
}
