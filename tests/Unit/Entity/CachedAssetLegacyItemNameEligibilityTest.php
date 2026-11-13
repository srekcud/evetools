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
#[Group('issue-863')]
final class CachedAssetLegacyItemNameEligibilityTest extends TestCase
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int MATERIAL_CATEGORY_ID = 4;
    private const int BLUEPRINT_CATEGORY_ID = 9;
    private const int MODULE_CATEGORY_ID = 7;
    private const int CARGO_CONTAINER_GROUP_ID = 12;
    private const int SECURE_CARGO_CONTAINER_GROUP_ID = 340;
    private const int PLANET_GROUP_ID = 7;
    private const int MINERAL_GROUP_ID = 18;

    public static function rowsSyncedBeforeTheSingletonFlagExisted(): iterable
    {
        yield 'unflagged ship, one unit, no name' => [null, 1, null, 'Hangar', self::SHIP_CATEGORY_ID, null, true];
        yield 'unflagged container, one unit, no name' => [null, 1, null, 'Hangar', self::CELESTIAL_CATEGORY_ID, self::CARGO_CONTAINER_GROUP_ID, true];
        yield 'unflagged container without location flag' => [null, 1, null, null, self::CELESTIAL_CATEGORY_ID, self::CARGO_CONTAINER_GROUP_ID, true];
        yield 'unflagged secure container known by its group only' => [null, 1, null, 'Hangar', null, self::SECURE_CARGO_CONTAINER_GROUP_ID, true];
        yield 'unflagged ship already named' => [null, 1, 'Hauler One', 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'unflagged ship stack of several units' => [null, 5, null, 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'unflagged material' => [null, 1, null, 'Hangar', self::MATERIAL_CATEGORY_ID, self::MINERAL_GROUP_ID, false];
        yield 'unflagged celestial outside the container groups' => [null, 1, null, 'Hangar', self::CELESTIAL_CATEGORY_ID, self::PLANET_GROUP_ID, false];
        yield 'unflagged item of an unknown category and group' => [null, 1, null, 'Hangar', null, null, false];
        yield 'unflagged office' => [null, 1, null, 'OfficeFolder', self::CELESTIAL_CATEGORY_ID, self::CARGO_CONTAINER_GROUP_ID, false];
        yield 'flagged non singleton ship stays excluded' => [false, 1, null, 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'flagged singleton material is no longer eligible' => [true, 1, null, 'Hangar', self::MATERIAL_CATEGORY_ID, self::MINERAL_GROUP_ID, false];
        yield 'flagged singleton blueprint is no longer eligible' => [true, 1, null, 'Hangar', self::BLUEPRINT_CATEGORY_ID, null, false];
        yield 'flagged singleton module is no longer eligible' => [true, 1, null, 'HiSlot0', self::MODULE_CATEGORY_ID, null, false];
        yield 'flagged singleton without category and group is no longer eligible' => [true, 1, null, 'Hangar', null, null, false];
        yield 'flagged singleton ship stays eligible' => [true, 1, null, 'Hangar', self::SHIP_CATEGORY_ID, null, true];
    }

    #[DataProvider('rowsSyncedBeforeTheSingletonFlagExisted')]
    public function testAnUnflaggedRowIsEligibleOnlyWhenItIsASingleUnitOfANameableTypeWithoutName(
        ?bool $isSingleton,
        int $quantity,
        ?string $itemName,
        ?string $locationFlag,
        ?int $categoryId,
        ?int $groupId,
        bool $expected,
    ): void {
        $asset = (new CachedAsset())
            ->setIsSingleton($isSingleton)
            ->setQuantity($quantity)
            ->setItemName($itemName)
            ->setLocationFlag($locationFlag);

        self::assertSame($expected, $asset->canResolveItemName($categoryId, $groupId));
    }
}
