<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryStockpileTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryStockpileTarget::class)]
final class IndustryStockpileTargetTest extends TestCase
{
    public static function stockAroundTheTargetQuantityProvider(): iterable
    {
        yield 'target 10, stock 9: one short' => [10, 9, false];
        yield 'target 10, stock 10: exactly met' => [10, 10, true];
        yield 'target 10, stock 11: overstocked' => [10, 11, true];
        yield 'target 0, stock 0: nothing required' => [0, 0, true];
    }

    #[DataProvider('stockAroundTheTargetQuantityProvider')]
    public function testIsMetByAStockAtLeastEqualToTheTargetQuantity(int $targetQuantity, int $stock, bool $expectedMet): void
    {
        $target = (new IndustryStockpileTarget())->setTargetQuantity($targetQuantity);

        self::assertSame($expectedMet, $target->isMetBy($stock));
    }
}
