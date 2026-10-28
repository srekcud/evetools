<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\OrderBookCoverage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderBookCoverage::class)]
final class OrderBookCoverageTest extends TestCase
{
    public function testKeepsItsFraction(): void
    {
        $this->assertSame(0.3, (new OrderBookCoverage(0.3))->fraction);
    }

    public function testFullCoverageIsNotPartial(): void
    {
        $this->assertFalse((new OrderBookCoverage(1.0))->isPartial());
    }

    #[DataProvider('partialCoverageProvider')]
    public function testCoverageStrictlyBelowOneIsPartialWithoutAnyThreshold(float $fraction): void
    {
        $this->assertTrue((new OrderBookCoverage($fraction))->isPartial());
    }

    #[DataProvider('invalidCoverageProvider')]
    public function testRefusesACoverageOutsideZeroExcludedToOne(float $invalidFraction): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OrderBookCoverage($invalidFraction);
    }

    public static function partialCoverageProvider(): iterable
    {
        yield 'order book of 300 units for 1 000 asked' => [0.3];
        yield 'order book of 300 units for 320 asked' => [300 / 320];
        yield 'order book of 300 units for 301 asked' => [300 / 301];
        yield 'just below one' => [0.999];
        yield 'closest to one' => [0.99999];
        yield 'closest to zero' => [0.00004];
    }

    public static function invalidCoverageProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.3];
        yield 'above one' => [1.0001];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
        yield 'negative infinite' => [-\INF];
    }
}
