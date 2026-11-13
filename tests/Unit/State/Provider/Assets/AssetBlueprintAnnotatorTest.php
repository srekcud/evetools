<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use App\ApiResource\Assets\AssetBlueprintResource;
use App\ApiResource\Assets\AssetItemResource;
use App\Entity\Character;
use App\Entity\OwnedBlueprint;
use App\Repository\OwnedBlueprintRepository;
use App\State\Provider\Assets\AssetBlueprintAnnotator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssetBlueprintAnnotator::class)]
#[Group('issue-887')]
final class AssetBlueprintAnnotatorTest extends TestCase
{
    private const int BLUEPRINT_CATEGORY_ID = 9;
    private const int MINERAL_CATEGORY_ID = 4;
    private const int CORPORATION_ID = 98_000_001;
    private const int COPY_ITEM_ID = 1_870_000_000_001;
    private const int ORIGINAL_ITEM_ID = 1_870_000_000_002;
    private const int UNSYNCED_ITEM_ID = 1_870_000_000_003;
    private const int TRITANIUM_ITEM_ID = 1_870_000_000_004;
    private const int UNKNOWN_CATEGORY_ITEM_ID = 1_870_000_000_005;
    private const int ESI_ORIGINAL_QUANTITY = -1;
    private const int ESI_COPY_QUANTITY = -2;
    private const int ESI_ORIGINAL_RUNS = -1;

    public function testACopyShowsItsRunsAndEfficiencies(): void
    {
        $item = $this->item(self::COPY_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);

        $this->annotatorOwning([$this->copy(self::COPY_ITEM_ID, 10, 8, 14)])->annotateCharacterItems($this->createStub(Character::class), [$item]);

        self::assertEquals(new AssetBlueprintResource('copy', 10, 8, 14), $item->blueprint);
    }

    public function testAnOriginalShowsItsEfficienciesAndNoRuns(): void
    {
        $item = $this->item(self::ORIGINAL_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);

        $this->annotatorOwning([$this->original(self::ORIGINAL_ITEM_ID, 10, 20)])->annotateCharacterItems($this->createStub(Character::class), [$item]);

        self::assertEquals(new AssetBlueprintResource('original', null, 10, 20), $item->blueprint);
    }

    public function testABlueprintMissingFromTheBlueprintSyncIsExplicitlyNotSyncedWithoutDefaultValues(): void
    {
        $item = $this->item(self::UNSYNCED_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);

        $this->annotatorOwning([$this->copy(self::COPY_ITEM_ID, 10, 8, 14)])->annotateCharacterItems($this->createStub(Character::class), [$item]);

        self::assertEquals(new AssetBlueprintResource('not_synced', null, null, null), $item->blueprint);
    }

    public function testEachBlueprintRowIsJoinedToTheBlueprintOfItsOwnItemId(): void
    {
        $copyRow = $this->item(self::COPY_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);
        $originalRow = $this->item(self::ORIGINAL_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);

        $this->annotatorOwning([
            $this->original(self::ORIGINAL_ITEM_ID, 2, 4),
            $this->copy(self::COPY_ITEM_ID, 5, 6, 8),
        ])->annotateCharacterItems($this->createStub(Character::class), [$copyRow, $originalRow]);

        self::assertEquals(new AssetBlueprintResource('copy', 5, 6, 8), $copyRow->blueprint);
        self::assertEquals(new AssetBlueprintResource('original', null, 2, 4), $originalRow->blueprint);
    }

    public function testAnItemThatIsNotABlueprintHasNoBlueprintState(): void
    {
        $item = $this->item(self::TRITANIUM_ITEM_ID, self::MINERAL_CATEGORY_ID);

        $this->annotatorOwning([$this->copy(self::TRITANIUM_ITEM_ID, 10, 8, 14)])->annotateCharacterItems($this->createStub(Character::class), [$item]);

        self::assertNull($item->blueprint);
    }

    public function testAnItemWhoseTypeIsAbsentFromTheSdeHasNoBlueprintState(): void
    {
        $item = $this->item(self::UNKNOWN_CATEGORY_ITEM_ID, null);

        $this->annotatorOwning([])->annotateCharacterItems($this->createStub(Character::class), [$item]);

        self::assertNull($item->blueprint);
    }

    public function testNoBlueprintIsReadWhenTheInventoryHoldsNoBlueprintRow(): void
    {
        $repository = $this->createMock(OwnedBlueprintRepository::class);
        $repository->expects(self::never())->method('findByCharacters');
        $repository->expects(self::never())->method('findByCorporationId');

        (new AssetBlueprintAnnotator($repository))->annotateCharacterItems(
            $this->createStub(Character::class),
            [$this->item(self::TRITANIUM_ITEM_ID, self::MINERAL_CATEGORY_ID)],
        );
    }

    public function testACorporationBlueprintRowIsJoinedToTheBlueprintsOfThatCorporation(): void
    {
        $item = $this->item(self::COPY_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);
        $repository = $this->createMock(OwnedBlueprintRepository::class);
        $repository->expects(self::once())->method('findByCorporationId')->with(self::CORPORATION_ID)
            ->willReturn([$this->copy(self::COPY_ITEM_ID, 30, 9, 18)]);
        $repository->expects(self::never())->method('findByCharacters');

        (new AssetBlueprintAnnotator($repository))->annotateCorporationItems(self::CORPORATION_ID, [$item]);

        self::assertEquals(new AssetBlueprintResource('copy', 30, 9, 18), $item->blueprint);
    }

    public function testACharacterBlueprintRowIsJoinedToTheBlueprintsOfThatCharacterOnly(): void
    {
        $character = $this->createStub(Character::class);
        $item = $this->item(self::COPY_ITEM_ID, self::BLUEPRINT_CATEGORY_ID);
        $repository = $this->createMock(OwnedBlueprintRepository::class);
        $repository->expects(self::once())->method('findByCharacters')->with([$character])
            ->willReturn([$this->copy(self::COPY_ITEM_ID, 3, 1, 2)]);
        $repository->expects(self::never())->method('findByCorporationId');

        (new AssetBlueprintAnnotator($repository))->annotateCharacterItems($character, [$item]);

        self::assertEquals(new AssetBlueprintResource('copy', 3, 1, 2), $item->blueprint);
    }

    /**
     * @param list<OwnedBlueprint> $owned
     */
    private function annotatorOwning(array $owned): AssetBlueprintAnnotator
    {
        $repository = $this->createStub(OwnedBlueprintRepository::class);
        $repository->method('findByCharacters')->willReturn($owned);
        $repository->method('findByCorporationId')->willReturn($owned);

        return new AssetBlueprintAnnotator($repository);
    }

    private function item(int $itemId, ?int $categoryId): AssetItemResource
    {
        $item = new AssetItemResource();
        $item->itemId = $itemId;
        $item->categoryId = $categoryId;

        return $item;
    }

    private function copy(int $itemId, int $runs, int $materialEfficiency, int $timeEfficiency): OwnedBlueprint
    {
        return (new OwnedBlueprint())
            ->setItemId($itemId)
            ->setQuantity(self::ESI_COPY_QUANTITY)
            ->setRuns($runs)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency);
    }

    private function original(int $itemId, int $materialEfficiency, int $timeEfficiency): OwnedBlueprint
    {
        return (new OwnedBlueprint())
            ->setItemId($itemId)
            ->setQuantity(self::ESI_ORIGINAL_QUANTITY)
            ->setRuns(self::ESI_ORIGINAL_RUNS)
            ->setMaterialEfficiency($materialEfficiency)
            ->setTimeEfficiency($timeEfficiency);
    }
}
