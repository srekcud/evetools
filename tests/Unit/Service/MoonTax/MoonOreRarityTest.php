<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Enum\MoonOreRarity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonOreRarity::class)]
#[Group('issue-273')]
final class MoonOreRarityTest extends TestCase
{
    public function testEachMoonOreGroupMapsToItsRarity(): void
    {
        self::assertSame(
            [MoonOreRarity::R4, MoonOreRarity::R8, MoonOreRarity::R16, MoonOreRarity::R32, MoonOreRarity::R64],
            array_map(MoonOreRarity::fromGroupId(...), [1884, 1920, 1921, 1922, 1923]),
        );
    }

    public function testAGroupOutsideTheMoonOresHasNoRarity(): void
    {
        self::assertSame(
            [null, null, null],
            array_map(MoonOreRarity::fromGroupId(...), [462, 1919, 0]),
        );
    }
}
