<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\UnclaimedStock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnclaimedStock::class)]
#[Group('issue-1006')]
final class UnclaimedStockTest extends TestCase
{
    #[DataProvider('cases')]
    public function testUnclaimedQuantityIsTheContainerStockCappedAtTheRequirementMinusTheClaimedUnits(?int $containerQuantity, int $requiredQuantity, int $claimedQuantity, ?int $expected): void
    {
        self::assertSame($expected, UnclaimedStock::quantityOf($containerQuantity, $requiredQuantity, $claimedQuantity));
    }

    /**
     * @return iterable<string, array{?int, int, int, ?int}>
     */
    public static function cases(): iterable
    {
        yield 'nothing claimed' => [80, 100, 0, 80];
        yield 'partly claimed' => [80, 100, 30, 50];
        yield 'container above the requirement is capped' => [150, 100, 0, 100];
        yield 'over-claimed never goes negative' => [80, 100, 90, 0];
        yield 'empty container' => [0, 100, 0, 0];
        yield 'unknown container stock is not guessed' => [null, 100, 0, null];
        yield 'unknown container stock stays unknown even when claimed' => [null, 100, 40, null];
    }

    public function testValueIsTheUnclaimedQuantityAtTheLaunchPrice(): void
    {
        self::assertSame(625.0, UnclaimedStock::valueOf(50, 12.5));
    }

    public function testValueIsUnknownWithoutALaunchPrice(): void
    {
        self::assertNull(UnclaimedStock::valueOf(50, null));
    }

    public function testValueIsUnknownWithoutAnUnclaimedQuantity(): void
    {
        self::assertNull(UnclaimedStock::valueOf(null, 12.5));
    }
}
