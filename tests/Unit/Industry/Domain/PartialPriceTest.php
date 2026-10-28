<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartialPrice::class)]
final class PartialPriceTest extends TestCase
{
    private const int TRITANIUM = 34;

    public function testPartialPriceIsAPartialMarketDepthWarningOnItsTypeIdWithItsCoverage(): void
    {
        $partialPrice = new PartialPrice(self::TRITANIUM, new OrderBookCoverage(0.3));

        $this->assertSame('partial_market_depth', $partialPrice->reason);
        $this->assertSame(self::TRITANIUM, $partialPrice->typeId);
        $this->assertSame(0.3, $partialPrice->coverage->fraction);
    }

    public function testTwoPartialPricesOfTheSameTypeIdAndCoverageAreEqual(): void
    {
        $this->assertEquals(
            new PartialPrice(self::TRITANIUM, new OrderBookCoverage(0.3)),
            new PartialPrice(self::TRITANIUM, new OrderBookCoverage(0.3)),
        );
    }
}
