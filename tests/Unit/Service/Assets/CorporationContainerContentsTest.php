<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Assets;

use App\Entity\CachedAsset;
use App\Service\Assets\CorporationContainerContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorporationContainerContents::class)]
#[Group('issue-266')]
final class CorporationContainerContentsTest extends TestCase
{
    private const string CONTAINER_NAME = 'OPS-HAUL';
    private const int STATION_ID = 60_008_494;
    private const int STATION_CONTAINER = 17366;
    private const int VELDSPAR = 1230;
    private const int COMPRESSED_VELDSPAR = 62516;
    private const int BITUMENS = 45492;
    private const int OPS_HAUL_ITEM_ID = 1_051_000_001;
    private const int SECOND_OPS_HAUL_ITEM_ID = 1_051_000_002;
    private const int BIN_ITEM_ID = 1_051_000_003;
    private const int OTHER_CONTAINER_ITEM_ID = 1_051_000_004;

    private int $nextItemId = 1_052_000_001;

    public function testContentsOfTheNamedContainerAreSummedByType(): void
    {
        $quantities = $this->quantitiesIn([
            $this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME),
            $this->item(self::COMPRESSED_VELDSPAR, 60_000, self::OPS_HAUL_ITEM_ID),
            $this->item(self::VELDSPAR, 40_000, self::OPS_HAUL_ITEM_ID),
            $this->item(self::VELDSPAR, 2_500, self::OPS_HAUL_ITEM_ID),
        ]);

        self::assertSame([self::COMPRESSED_VELDSPAR => 60_000, self::VELDSPAR => 42_500], $this->sortedByType($quantities));
    }

    public function testEveryContainerOfThatNameIsCounted(): void
    {
        $quantities = $this->quantitiesIn([
            $this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME),
            $this->container(self::SECOND_OPS_HAUL_ITEM_ID, self::CONTAINER_NAME),
            $this->item(self::VELDSPAR, 50_000, self::OPS_HAUL_ITEM_ID),
            $this->item(self::VELDSPAR, 50_000, self::SECOND_OPS_HAUL_ITEM_ID),
        ]);

        self::assertSame([self::VELDSPAR => 100_000], $quantities);
    }

    public function testAssetsOutsideTheNamedContainerAreNotCounted(): void
    {
        $quantities = $this->quantitiesIn([
            $this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME),
            $this->container(self::OTHER_CONTAINER_ITEM_ID, 'Reprocessing'),
            $this->item(self::BITUMENS, 3_333, self::OPS_HAUL_ITEM_ID),
            $this->item(self::VELDSPAR, 120_000, self::OTHER_CONTAINER_ITEM_ID),
            $this->item(self::VELDSPAR, 7_000, self::STATION_ID),
        ]);

        self::assertSame([self::BITUMENS => 3_333], $quantities);
    }

    public function testOreInASubContainerOfTheNamedContainerIsCounted(): void
    {
        $quantities = $this->quantitiesIn([
            $this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME),
            $this->container(self::BIN_ITEM_ID, 'Bin', self::OPS_HAUL_ITEM_ID),
            $this->item(self::VELDSPAR, 100_000, self::BIN_ITEM_ID),
        ]);

        self::assertSame([self::STATION_CONTAINER => 1, self::VELDSPAR => 100_000], $this->sortedByType($quantities));
    }

    public function testAssetListedTwiceIsCountedOnce(): void
    {
        $veldspar = $this->item(self::VELDSPAR, 100_000, self::OPS_HAUL_ITEM_ID);

        $quantities = $this->quantitiesIn([$this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME), $veldspar, $veldspar]);

        self::assertSame([self::VELDSPAR => 100_000], $quantities);
    }

    public function testEmptyNamedContainerHoldsNothing(): void
    {
        self::assertSame([], $this->quantitiesIn([$this->container(self::OPS_HAUL_ITEM_ID, self::CONTAINER_NAME)]));
    }

    public function testNameIsComparedWithItsCaseSoAnotherCaseIsNoContainer(): void
    {
        $quantities = $this->quantitiesIn([
            $this->container(self::OPS_HAUL_ITEM_ID, 'ops-haul'),
            $this->item(self::VELDSPAR, 100_000, self::OPS_HAUL_ITEM_ID),
        ]);

        self::assertNull($quantities);
    }

    public function testNoVisibleAssetMeansNoContainer(): void
    {
        self::assertNull($this->quantitiesIn([]));
    }

    private function quantitiesIn(array $visibleAssets): ?array
    {
        return (new CorporationContainerContents())->quantitiesIn($visibleAssets, self::CONTAINER_NAME);
    }

    private function container(int $itemId, string $name, int $locationId = self::STATION_ID): CachedAsset
    {
        return $this->asset($itemId, self::STATION_CONTAINER, 1, $locationId)->setItemName($name);
    }

    private function item(int $typeId, int $quantity, int $locationId): CachedAsset
    {
        return $this->asset($this->nextItemId++, $typeId, $quantity, $locationId);
    }

    private function asset(int $itemId, int $typeId, int $quantity, int $locationId): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationType($locationId === self::STATION_ID ? 'station' : 'item')
            ->setLocationName('Aldranette VI - Moon 1 - Federal Navy Academy')
            ->setCorporationId(98_262_101)
            ->setIsCorporationAsset(true)
            ->setCachedAt(new \DateTimeImmutable('2026-11-05T21:41:00+00:00'));
    }

    private function sortedByType(?array $quantities): ?array
    {
        if ($quantities !== null) {
            ksort($quantities);
        }

        return $quantities;
    }
}
