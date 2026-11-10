<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Service\Industry\Project\DistributionResult;
use App\Service\Industry\Project\SellForecast;
use App\Service\Industry\Project\ViewerProfitShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViewerProfitShare::class)]
#[Group('issue-570')]
final class ViewerProfitShareForecastTest extends TestCase
{
    private const float JITA_NET_REVENUE = 4_000_000.0;
    private const float REMAINING_COST = 600_000.0;
    private const float ENGAGED_COST = 1_400_000.0;

    public function testForecastIsTheJitaNetRevenueMinusTheRemainingCostAndTheEngagedCost(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(self::ENGAGED_COST),
            $this->forecastOf(self::JITA_NET_REVENUE, self::REMAINING_COST),
        );

        self::assertSame(['amount' => 2_000_000.0, 'status' => 'forecast'], $profit);
    }

    public function testForecastCanBeNegative(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(self::ENGAGED_COST),
            $this->forecastOf(1_000_000.0, self::REMAINING_COST),
        );

        self::assertSame(['amount' => -1_000_000.0, 'status' => 'forecast'], $profit);
    }

    public function testForecastWithNothingEngagedIsTheRevenueMinusTheRemainingCost(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(0.0),
            $this->forecastOf(self::JITA_NET_REVENUE, 0.0),
        );

        self::assertSame(['amount' => self::JITA_NET_REVENUE, 'status' => 'forecast'], $profit);
    }

    public function testForecastStaysAwaitingSalesWithoutAmountWhenTheJitaRevenueIsUnknown(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(self::ENGAGED_COST),
            $this->forecastOf(null, self::REMAINING_COST),
        );

        self::assertSame(['amount' => null, 'status' => 'awaiting_sales'], $profit);
    }

    public function testForecastStaysAwaitingSalesWithoutAmountWhenTheRemainingCostIsUnknown(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(self::ENGAGED_COST),
            $this->forecastOf(self::JITA_NET_REVENUE, null),
        );

        self::assertSame(['amount' => null, 'status' => 'awaiting_sales'], $profit);
    }

    public function testForecastStaysAwaitingSalesWithoutAmountWhenTheEngagedCostIsUnknown(): void
    {
        $profit = ViewerProfitShare::forecastOf(
            $this->distributionEngaging(null),
            $this->forecastOf(self::JITA_NET_REVENUE, self::REMAINING_COST),
        );

        self::assertSame(['amount' => null, 'status' => 'awaiting_sales'], $profit);
    }

    public function testAMemberAbsentFromTheDistributionOfASoldProjectStaysUnknown(): void
    {
        $distribution = new DistributionResult(10.0, 0.0, 0.0, 10.0, 4.0, 150.0, [], false);

        self::assertSame(['amount' => null, 'status' => 'unknown'], ViewerProfitShare::of($distribution, 'absent-member'));
    }

    private function distributionEngaging(?float $totalProjectCost): DistributionResult
    {
        return new DistributionResult(0.0, null, null, null, $totalProjectCost, null, [], true);
    }

    private function forecastOf(?float $jitaNetRevenue, ?float $remainingCost): SellForecast
    {
        return new SellForecast(null, [], null, $jitaNetRevenue, 0, 0, $remainingCost);
    }
}
