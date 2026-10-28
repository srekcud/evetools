<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\PartialPrice;
use App\Service\Industry\PartialPricePresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartialPricePresenter::class)]
final class PartialPricePresenterTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int MEXALLON = 36;
    private const int ISOGEN = 37;

    public function testCostWithoutPartialPriceHasNoWarning(): void
    {
        $this->assertSame([], PartialPricePresenter::of(Cost::known(new Isk(11800.0))));
    }

    public function testPartialPriceIsPresentedWithItsReasonTypeIdAndCoveragePercent(): void
    {
        $cost = self::partiallyPricedCost(6000.0, self::TRITANIUM, 0.30);

        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 30.0]],
            PartialPricePresenter::of($cost),
        );
    }

    #[DataProvider('coveragesAndTheirDisplayedPercent')]
    public function testCoveragePercentIsRoundedToOneDecimalAndNeverShownAsZeroNorAsFull(float $coverage, float $coveragePercent): void
    {
        $cost = self::partiallyPricedCost(6000.0, self::TRITANIUM, $coverage);

        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => $coveragePercent]],
            PartialPricePresenter::of($cost),
        );
    }

    public static function coveragesAndTheirDisplayedPercent(): iterable
    {
        yield 'thirty percent' => [0.30, 30.0];
        yield 'half up at the first decimal' => [0.9375, 93.8];
        yield 'one unit short of a full book' => [0.99668, 99.7];
        yield 'rounded to a hundred is capped below full' => [0.99999, 99.9];
        yield 'rounded to zero is raised to the smallest percent' => [0.00004, 0.1];
    }

    public function testSeveralPartialPricesKeepTheirOrderOfAppearance(): void
    {
        $cost = self::partiallyPricedCost(6000.0, self::TRITANIUM, 0.30)
            ->plus(Cost::known(new Isk(800.0)))
            ->plus(self::partiallyPricedCost(5000.0, self::MEXALLON, 0.9375));

        $this->assertSame(
            [
                ['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 30.0],
                ['reason' => 'partial_market_depth', 'typeId' => self::MEXALLON, 'coveragePercent' => 93.8],
            ],
            PartialPricePresenter::of($cost),
        );
    }

    public function testPartialPriceOfAnUnknownCostIsStillPresented(): void
    {
        $cost = Cost::unknown(MissingData::marketPrice(self::ISOGEN))
            ->plus(self::partiallyPricedCost(6000.0, self::TRITANIUM, 0.45));

        $this->assertSame(
            [['reason' => 'partial_market_depth', 'typeId' => self::TRITANIUM, 'coveragePercent' => 45.0]],
            PartialPricePresenter::of($cost),
        );
    }

    public function testCoveragePercentOfAPartiallyPricedTypeIsItsDisplayedPercent(): void
    {
        $cost = self::partiallyPricedCost(6000.0, self::TRITANIUM, 0.30)
            ->plus(self::partiallyPricedCost(5000.0, self::MEXALLON, 0.99999));

        $this->assertSame(99.9, PartialPricePresenter::coveragePercentOf($cost, self::MEXALLON));
    }

    public function testCoveragePercentOfATypeWithoutPartialPriceIsNull(): void
    {
        $cost = self::partiallyPricedCost(6000.0, self::TRITANIUM, 0.30)->plus(Cost::known(new Isk(800.0)));

        $this->assertNull(PartialPricePresenter::coveragePercentOf($cost, self::PYERITE));
    }

    private static function partiallyPricedCost(float $amount, int $typeId, float $coverage): Cost
    {
        return Cost::known(new Isk($amount))->withPartialPrice(new PartialPrice($typeId, new OrderBookCoverage($coverage)));
    }
}
