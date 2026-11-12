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
final class CachedAssetItemNameEligibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{?bool, ?string, ?string, bool}>
     */
    public static function cachedRows(): iterable
    {
        yield 'unnamed singleton in a hangar' => [true, null, 'Hangar', true];
        yield 'unnamed singleton without location flag' => [true, null, null, true];
        yield 'unnamed singleton inside a container' => [true, null, 'Unlocked', true];
        yield 'stack that is not a singleton' => [false, null, 'Hangar', false];
        yield 'singleton flag unknown before the first sync' => [null, null, 'Hangar', false];
        yield 'singleton already named' => [true, 'Ammo Box', 'Hangar', false];
        yield 'office' => [true, null, 'OfficeFolder', false];
    }

    #[DataProvider('cachedRows')]
    public function testOnlyAnUnnamedSingletonOutsideAnOfficeCanResolveItsItemName(
        ?bool $isSingleton,
        ?string $itemName,
        ?string $locationFlag,
        bool $expected,
    ): void {
        $asset = (new CachedAsset())
            ->setIsSingleton($isSingleton)
            ->setItemName($itemName)
            ->setLocationFlag($locationFlag);

        self::assertSame($expected, $asset->canResolveItemName());
    }

    public function testASingletonFlagStaysUnknownUntilASyncSetsIt(): void
    {
        self::assertNull((new CachedAsset())->isSingleton());
    }
}
