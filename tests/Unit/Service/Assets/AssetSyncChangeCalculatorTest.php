<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Entity\CachedAsset;
use App\Dto\AssetQuantityChange;
use App\Service\Assets\AssetSyncChangeCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetSyncChangeCalculator::class)]
#[Group('issue-888')]
final class AssetSyncChangeCalculatorTest extends TestCase
{
    private const int TYPE_IRIDIUM_CHARGE_M = 21_894;
    private const int TYPE_DRAKE = 24_698;
    private const int TYPE_TRITANIUM = 34;
    private const int CONTAINER_ID = 1_080_000_888_001;
    private const int OTHER_CONTAINER_ID = 1_080_000_888_002;
    private const int JITA_STATION_ID = 60_003_760;
    private const string CONTAINER_LABEL = 'Ammo box — Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string OTHER_CONTAINER_LABEL = 'Spare box — Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string STATION_LABEL = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';

    private AssetSyncChangeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AssetSyncChangeCalculator();
    }

    public function testAQuantityThatDroppedInAContainerIsANegativeDelta(): void
    {
        $changes = $this->calculator->calculate(
            [$this->asset(self::TYPE_IRIDIUM_CHARGE_M, 'Caldari Navy Iridium Charge M', 10, self::CONTAINER_ID, self::CONTAINER_LABEL, 'Ammunition')],
            [$this->asset(self::TYPE_IRIDIUM_CHARGE_M, 'Caldari Navy Iridium Charge M', 6, self::CONTAINER_ID, self::CONTAINER_LABEL, 'Ammunition')],
        );

        self::assertEquals(
            [new AssetQuantityChange(self::TYPE_IRIDIUM_CHARGE_M, 'Caldari Navy Iridium Charge M', self::CONTAINER_ID, self::CONTAINER_LABEL, 'Ammunition', -4)],
            $changes,
        );
    }

    public function testATypeThatAppearedIsAPositiveDelta(): void
    {
        $changes = $this->calculator->calculate(
            [],
            [$this->asset(self::TYPE_DRAKE, 'Drake', 3, self::JITA_STATION_ID, self::STATION_LABEL, 'Ships')],
        );

        self::assertEquals(
            [new AssetQuantityChange(self::TYPE_DRAKE, 'Drake', self::JITA_STATION_ID, self::STATION_LABEL, 'Ships', 3)],
            $changes,
        );
    }

    public function testATypeThatDisappearedIsANegativeDeltaOfItsWholeQuantity(): void
    {
        $changes = $this->calculator->calculate(
            [$this->asset(self::TYPE_DRAKE, 'Drake', 3, self::JITA_STATION_ID, self::STATION_LABEL, 'Ships')],
            [],
        );

        self::assertEquals(
            [new AssetQuantityChange(self::TYPE_DRAKE, 'Drake', self::JITA_STATION_ID, self::STATION_LABEL, 'Ships', -3)],
            $changes,
        );
    }

    public function testAStackThatSplitsWithoutChangingTheTotalProducesNoChange(): void
    {
        $changes = $this->calculator->calculate(
            [$this->asset(self::TYPE_TRITANIUM, 'Tritanium', 5, self::JITA_STATION_ID, self::STATION_LABEL, null)],
            [
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 3, self::JITA_STATION_ID, self::STATION_LABEL, null),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 2, self::JITA_STATION_ID, self::STATION_LABEL, null),
            ],
        );

        self::assertSame([], $changes);
    }

    public function testTheSameTypeInTwoContainersIsTrackedPerContainer(): void
    {
        $changes = $this->calculator->calculate(
            [
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 10, self::CONTAINER_ID, self::CONTAINER_LABEL, 'Minerals'),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 10, self::OTHER_CONTAINER_ID, self::OTHER_CONTAINER_LABEL, 'Minerals'),
            ],
            [
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 4, self::CONTAINER_ID, self::CONTAINER_LABEL, 'Minerals'),
                $this->asset(self::TYPE_TRITANIUM, 'Tritanium', 16, self::OTHER_CONTAINER_ID, self::OTHER_CONTAINER_LABEL, 'Minerals'),
            ],
        );

        self::assertEqualsCanonicalizing(
            [
                new AssetQuantityChange(self::TYPE_TRITANIUM, 'Tritanium', self::CONTAINER_ID, self::CONTAINER_LABEL, 'Minerals', -6),
                new AssetQuantityChange(self::TYPE_TRITANIUM, 'Tritanium', self::OTHER_CONTAINER_ID, self::OTHER_CONTAINER_LABEL, 'Minerals', 6),
            ],
            $changes,
        );
    }

    public function testATypeNameMissingOnOneSideFallsBackToTheNameKnownOnTheOtherSide(): void
    {
        $changes = $this->calculator->calculate(
            [$this->asset(self::TYPE_DRAKE, 'Drake', 1, self::JITA_STATION_ID, self::STATION_LABEL, 'Ships')],
            [$this->asset(self::TYPE_DRAKE, null, 4, self::JITA_STATION_ID, self::STATION_LABEL, 'Ships')],
        );

        self::assertCount(1, $changes);
        self::assertSame('Drake', $changes[0]->typeName);
        self::assertSame(3, $changes[0]->quantityDelta);
    }

    public function testATypeWithoutAnyKnownNameKeepsANullName(): void
    {
        $changes = $this->calculator->calculate(
            [],
            [$this->asset(self::TYPE_DRAKE, null, 1, self::JITA_STATION_ID, self::STATION_LABEL, 'Ships')],
        );

        self::assertNull($changes[0]->typeName);
    }

    private function asset(int $typeId, ?string $typeName, int $quantity, int $locationId, string $locationName, ?string $divisionName): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId(random_int(1_000_000_000_000, 9_000_000_000_000))
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType('item')
            ->setDivisionName($divisionName);
    }
}
