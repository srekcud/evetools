<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CachedAsset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedAsset::class)]
#[Group('issue-784')]
#[Group('issue-863')]
final class CachedAssetItemNameEligibilityTest extends TestCase
{
    private const int SHIP_CATEGORY_ID = 6;
    private const int BLUEPRINT_CATEGORY_ID = 9;
    private const int MODULE_CATEGORY_ID = 7;
    private const int CELESTIAL_CATEGORY_ID = 2;
    private const int CARGO_CONTAINER_GROUP_ID = 12;
    private const int STACK_QUANTITY = 5;

    public static function cachedRows(): iterable
    {
        yield 'unnamed singleton in a hangar' => [true, null, 'Hangar', self::SHIP_CATEGORY_ID, null, true];
        yield 'unnamed singleton without location flag' => [true, null, null, self::SHIP_CATEGORY_ID, null, true];
        yield 'unnamed singleton inside a container' => [true, null, 'Unlocked', self::SHIP_CATEGORY_ID, null, true];
        yield 'stack that is not a singleton' => [false, null, 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'singleton flag unknown before the first sync' => [null, null, 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'singleton already named' => [true, 'Ammo Box', 'Hangar', self::SHIP_CATEGORY_ID, null, false];
        yield 'office' => [true, null, 'OfficeFolder', self::SHIP_CATEGORY_ID, null, false];
        yield 'singleton container of the cargo container group' => [true, null, 'Hangar', self::CELESTIAL_CATEGORY_ID, self::CARGO_CONTAINER_GROUP_ID, true];
        yield 'singleton blueprint' => [true, null, 'Hangar', self::BLUEPRINT_CATEGORY_ID, null, false];
        yield 'singleton fitted module' => [true, null, 'HiSlot0', self::MODULE_CATEGORY_ID, null, false];
        yield 'singleton of an unknown category and group' => [true, null, 'Hangar', null, null, false];
        yield 'container in an office' => [true, null, 'OfficeFolder', self::CELESTIAL_CATEGORY_ID, self::CARGO_CONTAINER_GROUP_ID, false];
    }

    #[DataProvider('cachedRows')]
    public function testOnlyAnUnnamedSingletonOfANameableTypeOutsideAnOfficeCanResolveItsItemName(
        ?bool $isSingleton,
        ?string $itemName,
        ?string $locationFlag,
        ?int $categoryId,
        ?int $groupId,
        bool $expected,
    ): void {
        $asset = (new CachedAsset())
            ->setQuantity(self::STACK_QUANTITY)
            ->setIsSingleton($isSingleton)
            ->setItemName($itemName)
            ->setLocationFlag($locationFlag);

        self::assertSame($expected, $asset->canResolveItemName($categoryId, $groupId));
    }

    public function testAnAssetCannotResolveItsItemNameWhenItsTypeIsNotGiven(): void
    {
        $asset = (new CachedAsset())->setIsSingleton(true)->setItemName(null)->setLocationFlag('Hangar');

        self::assertFalse($asset->canResolveItemName());
    }

    public function testASingletonFlagStaysUnknownUntilASyncSetsIt(): void
    {
        self::assertNull((new CachedAsset())->isSingleton());
    }
}
