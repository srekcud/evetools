<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sde;

use App\Service\Sde\CelestialNameFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CelestialNameFormatter::class)]
#[Group('issue-849')]
final class CelestialNameFormatterTest extends TestCase
{
    public static function planets(): iterable
    {
        yield 'first planet' => ['Rashy', 1, 'Rashy I'];
        yield 'subtractive four' => ['Rashy', 4, 'Rashy IV'];
        yield 'subtractive nine' => ['Ikoskio', 9, 'Ikoskio IX'];
        yield 'fourteen' => ['V-4DBR', 14, 'V-4DBR XIV'];
        yield 'eighteen' => ['J105201', 18, 'J105201 XVIII'];
        yield 'wormhole eight' => ['J105201', 8, 'J105201 VIII'];
        yield 'system name with a space' => ['Old Man Star', 3, 'Old Man Star III'];
    }

    #[DataProvider('planets')]
    public function testAPlanetIsNamedAfterItsSystemAndTheRomanNumeralOfItsCelestialIndex(string $system, int $celestialIndex, string $expected): void
    {
        self::assertSame($expected, CelestialNameFormatter::planetName($system, $celestialIndex));
    }

    public static function moons(): iterable
    {
        yield 'k-space' => ['Abhan', 5, 8, 'Abhan V - Moon 8'];
        yield 'first moon' => ['Todeko', 7, 1, 'Todeko VII - Moon 1'];
        yield 'two digit moon' => ['Gulmorogod', 7, 11, 'Gulmorogod VII - Moon 11'];
        yield 'wormhole' => ['J123352', 3, 14, 'J123352 III - Moon 14'];
    }

    #[DataProvider('moons')]
    public function testAMoonIsNamedAfterItsPlanetNameAndItsOrbitIndex(string $system, int $celestialIndex, int $orbitIndex, string $expected): void
    {
        self::assertSame($expected, CelestialNameFormatter::moonName($system, $celestialIndex, $orbitIndex));
    }
}
