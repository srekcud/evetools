<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cost::class)]
#[CoversClass(PartialPrice::class)]
final class CostPartialPriceTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    private const float TRITANIUM_LEAF_COVERAGE = 0.3;
    private const float TRITANIUM_DATACORE_COVERAGE = 0.6;
    private const float MEXALLON_COVERAGE = 0.45;

    private const float FLOAT_TOLERANCE = 1e-9;

    public function testKnownCostHasNoPartialPrice(): void
    {
        $this->assertSame([], Cost::known(new Isk(6000.0))->partialPrices);
    }

    public function testUnknownCostHasNoPartialPrice(): void
    {
        $cost = Cost::unknown(MissingData::marketPrice(self::ISOGEN));

        $this->assertSame([], $cost->partialPrices);
        $this->assertEquals([MissingData::marketPrice(self::ISOGEN)], $cost->missingData);
    }

    public function testKnownCostWithAPartialPriceStaysKnownWithItsAmountAndNoMissingData(): void
    {
        $cost = self::tritaniumLeafAtPartialPrice();

        $this->assertTrue($cost->isKnown());
        $this->assertSame(6000.0, $cost->amount()->amount);
        $this->assertSame([], $cost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $cost->partialPrices);
    }

    public function testSumOfMaterialsWithOnePartialPriceIsKnownAndListsOnlyThatPartialPrice(): void
    {
        $materialCost = self::tritaniumLeafAtPartialPrice()
            ->plus(Cost::known(new Isk(800.0)))
            ->plus(Cost::known(new Isk(5000.0)));

        $this->assertTrue($materialCost->isKnown());
        $this->assertSame(11800.0, $materialCost->amount()->amount);
        $this->assertSame([], $materialCost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $materialCost->partialPrices);
    }

    public function testSumKeepsThePartialPricesOfBothTermsInOrderOfAppearance(): void
    {
        $materialCost = self::tritaniumLeafAtPartialPrice()->plus(self::mexallonLeafAtPartialPrice());

        $this->assertSame(11000.0, $materialCost->amount()->amount);
        $this->assertEquals(
            [
                self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE),
                self::partialPrice(self::MEXALLON, self::MEXALLON_COVERAGE),
            ],
            $materialCost->partialPrices,
        );
    }

    public function testSumOfAPartialKnownCostAndAnUnknownCostListsTheMissingDataAndThePartialPrice(): void
    {
        $materialCost = self::tritaniumLeafAtPartialPrice()->plus(Cost::unknown(MissingData::marketPrice(self::ISOGEN)));

        $this->assertFalse($materialCost->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::ISOGEN)], $materialCost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $materialCost->partialPrices);
    }

    public function testSumOfAnUnknownCostAndAPartialKnownCostListsTheMissingDataAndThePartialPrice(): void
    {
        $materialCost = Cost::unknown(MissingData::marketPrice(self::ISOGEN))->plus(self::tritaniumLeafAtPartialPrice());

        $this->assertFalse($materialCost->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::ISOGEN)], $materialCost->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $materialCost->partialPrices);
    }

    public function testSumOfTwoUnknownCostsKeepsThePartialPricesOfBoth(): void
    {
        $firstUnknownCost = Cost::unknown(MissingData::marketPrice(self::ISOGEN))->plus(self::tritaniumLeafAtPartialPrice());
        $secondUnknownCost = Cost::unknown(MissingData::adjustedPrice(self::PYERITE))->plus(self::mexallonLeafAtPartialPrice());

        $totalCost = $firstUnknownCost->plus($secondUnknownCost);

        $this->assertFalse($totalCost->isKnown());
        $this->assertEquals(
            [MissingData::marketPrice(self::ISOGEN), MissingData::adjustedPrice(self::PYERITE)],
            $totalCost->missingData,
        );
        $this->assertEquals(
            [
                self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE),
                self::partialPrice(self::MEXALLON, self::MEXALLON_COVERAGE),
            ],
            $totalCost->partialPrices,
        );
    }

    public function testCostPerUnitOfAPartialKnownCostKeepsItsPartialPrice(): void
    {
        $totalCost = Cost::known(new Isk(12800.85))
            ->withPartialPrice(self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE));

        $costPerUnit = $totalCost->times(1 / 100);

        $this->assertTrue($costPerUnit->isKnown());
        $this->assertEqualsWithDelta(128.0085, $costPerUnit->amount()->amount, self::FLOAT_TOLERANCE);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $costPerUnit->partialPrices);
    }

    public function testShareOfAnUnknownCostKeepsItsPartialPrice(): void
    {
        $totalCost = Cost::unknown(MissingData::marketPrice(self::ISOGEN))->plus(self::tritaniumLeafAtPartialPrice());

        $costPerUnit = $totalCost->times(1 / 100);

        $this->assertFalse($costPerUnit->isKnown());
        $this->assertEquals([MissingData::marketPrice(self::ISOGEN)], $costPerUnit->missingData);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $costPerUnit->partialPrices);
    }

    public function testSameTypeIdBoughtTwiceIsListedOnceAtTheLowestCoverage(): void
    {
        $totalCost = self::tritaniumLeafAtPartialPrice()->plus(self::tritaniumDatacoreAtPartialPrice());

        $this->assertSame(9000.0, $totalCost->amount()->amount);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $totalCost->partialPrices);
    }

    public function testSameTypeIdBoughtTwiceKeepsItsFirstPositionWithTheLowestCoverage(): void
    {
        $totalCost = self::tritaniumDatacoreAtPartialPrice()
            ->plus(self::mexallonLeafAtPartialPrice())
            ->plus(self::tritaniumLeafAtPartialPrice());

        $this->assertSame(14000.0, $totalCost->amount()->amount);
        $this->assertEquals(
            [
                self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE),
                self::partialPrice(self::MEXALLON, self::MEXALLON_COVERAGE),
            ],
            $totalCost->partialPrices,
        );
    }

    public function testSamePartialPriceInBothTermsIsListedOnce(): void
    {
        $totalCost = self::tritaniumLeafAtPartialPrice()->plus(self::tritaniumLeafAtPartialPrice());

        $this->assertSame(12000.0, $totalCost->amount()->amount);
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $totalCost->partialPrices);
    }

    public function testSameTypeIdInAnUnknownSumIsListedOnceAtTheLowestCoverage(): void
    {
        $totalCost = self::tritaniumDatacoreAtPartialPrice()
            ->plus(Cost::unknown(MissingData::marketPrice(self::ISOGEN)))
            ->plus(self::tritaniumLeafAtPartialPrice());

        $this->assertFalse($totalCost->isKnown());
        $this->assertEquals([self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE)], $totalCost->partialPrices);
    }

    private static function tritaniumLeafAtPartialPrice(): Cost
    {
        return Cost::known(new Isk(6000.0))->withPartialPrice(self::partialPrice(self::TRITANIUM, self::TRITANIUM_LEAF_COVERAGE));
    }

    private static function tritaniumDatacoreAtPartialPrice(): Cost
    {
        return Cost::known(new Isk(3000.0))->withPartialPrice(self::partialPrice(self::TRITANIUM, self::TRITANIUM_DATACORE_COVERAGE));
    }

    private static function mexallonLeafAtPartialPrice(): Cost
    {
        return Cost::known(new Isk(5000.0))->withPartialPrice(self::partialPrice(self::MEXALLON, self::MEXALLON_COVERAGE));
    }

    private static function partialPrice(int $typeId, float $coverage): PartialPrice
    {
        return new PartialPrice($typeId, new OrderBookCoverage($coverage));
    }
}
