<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedAsset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedAsset::class)]
#[Group('issue-797')]
final class CachedAssetLegacyItemNameEligibilityTest extends TestCase
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int MATERIAL_CATEGORY_ID = 4;

    /**
     * @return iterable<string, array{?bool, int, ?string, ?string, ?int, bool}>
     */
    public static function rowsSyncedBeforeTheSingletonFlagExisted(): iterable
    {
        yield 'unflagged ship, one unit, no name' => [null, 1, null, 'Hangar', self::SHIP_CATEGORY_ID, true];
        yield 'unflagged container, one unit, no name' => [null, 1, null, 'Hangar', self::CELESTIAL_CATEGORY_ID, true];
        yield 'unflagged container without location flag' => [null, 1, null, null, self::CELESTIAL_CATEGORY_ID, true];
        yield 'unflagged ship already named' => [null, 1, 'Hauler One', 'Hangar', self::SHIP_CATEGORY_ID, false];
        yield 'unflagged ship stack of several units' => [null, 5, null, 'Hangar', self::SHIP_CATEGORY_ID, false];
        yield 'unflagged material' => [null, 1, null, 'Hangar', self::MATERIAL_CATEGORY_ID, false];
        yield 'unflagged item of an unknown category' => [null, 1, null, 'Hangar', null, false];
        yield 'unflagged office' => [null, 1, null, 'OfficeFolder', self::CELESTIAL_CATEGORY_ID, false];
        yield 'flagged non singleton ship stays excluded' => [false, 1, null, 'Hangar', self::SHIP_CATEGORY_ID, false];
        yield 'flagged singleton of any category stays eligible' => [true, 1, null, 'Hangar', self::MATERIAL_CATEGORY_ID, true];
        yield 'flagged singleton without category stays eligible' => [true, 1, null, 'Hangar', null, true];
    }

    #[DataProvider('rowsSyncedBeforeTheSingletonFlagExisted')]
    public function testAnUnflaggedRowIsEligibleOnlyWhenItIsASingleUnitShipOrContainerWithoutName(
        ?bool $isSingleton,
        int $quantity,
        ?string $itemName,
        ?string $locationFlag,
        ?int $categoryId,
        bool $expected,
    ): void {
        $asset = (new CachedAsset())
            ->setIsSingleton($isSingleton)
            ->setQuantity($quantity)
            ->setItemName($itemName)
            ->setLocationFlag($locationFlag);

        self::assertSame($expected, $asset->canResolveItemName($categoryId));
    }
}
