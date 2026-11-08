<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\LineRentalCategory;
use App\Industry\Domain\LineRentalRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineRentalRates::class)]
#[Group('issue-300')]
final class LineRentalRatesTest extends TestCase
{
    private const float MOCKUP_DAYS = 83_100 / 86_400;

    public function testDefaultScheduleHasADayFactorOfOneMillionIskPerDay(): void
    {
        self::assertSame(1_000_000.0, LineRentalRates::resolve(null, [])->dayFactor());
    }

    public function testDefaultScheduleHasTheSevenRatesPerRunOfTheSpecification(): void
    {
        $rates = LineRentalRates::resolve(null, []);

        self::assertSame(
            [
                'component_t1' => 250.0,
                'module_t1' => 10_000.0,
                'ship_t1' => 100_000.0,
                'module_t2' => 100_000.0,
                'ship_t2' => 2_500_000.0,
                'capital_t1' => 25_000.0,
                'capital_t2' => 125_000.0,
            ],
            $this->ratesPerRunByCategory($rates),
        );
    }

    public function testProjectOverrideWinsOverUserRatesAndDefaults(): void
    {
        $rates = LineRentalRates::resolve(
            [LineRentalCategory::ModuleT2->value => 200_000],
            [LineRentalCategory::ModuleT2->value => 150_000],
        );

        self::assertSame(200_000.0, $rates->ratePerRun(LineRentalCategory::ModuleT2));
        self::assertSame(3_000_000.0, $rates->valueOf(LineRentalCategory::ModuleT2, 1.0, 10));
    }

    public function testUserRatesApplyWhenTheProjectOverrideIsEmpty(): void
    {
        $rates = LineRentalRates::resolve([], [LineRentalCategory::ShipT1->value => 120_000]);

        self::assertSame(120_000.0, $rates->ratePerRun(LineRentalCategory::ShipT1));
        self::assertSame(860_000.0, $rates->valueOf(LineRentalCategory::ShipT1, 0.5, 3));
    }

    public function testDefaultsApplyWhenNoLayerDefinesTheKey(): void
    {
        $rates = LineRentalRates::resolve(null, []);

        self::assertSame(1_200_000.0, $rates->valueOf(LineRentalCategory::ShipT1, 1.0, 2));
    }

    public function testOverriddenDayFactorReplacesTheDefaultFactor(): void
    {
        $rates = LineRentalRates::resolve([LineRentalRates::DAY_FACTOR_KEY => 2_000_000], []);

        self::assertSame(2_000_000.0, $rates->dayFactor());
        self::assertSame(540_000.0, $rates->valueOf(LineRentalCategory::ModuleT1, 0.25, 4));
    }

    public function testUserDayFactorAppliesWhenTheProjectDoesNotOverrideIt(): void
    {
        $rates = LineRentalRates::resolve([LineRentalCategory::ShipT2->value => 3_000_000], [LineRentalRates::DAY_FACTOR_KEY => 1_500_000]);

        self::assertSame(1_500_000.0, $rates->dayFactor());
        self::assertSame(3_000_000.0, $rates->ratePerRun(LineRentalCategory::ShipT2));
    }

    public function testKeysAreResolvedOneByOneAcrossLayers(): void
    {
        $rates = LineRentalRates::resolve(
            [LineRentalCategory::CapitalT1->value => 40_000],
            [LineRentalCategory::CapitalT2->value => 175_000, LineRentalCategory::CapitalT1->value => 30_000],
        );

        self::assertSame(40_000.0, $rates->ratePerRun(LineRentalCategory::CapitalT1));
        self::assertSame(175_000.0, $rates->ratePerRun(LineRentalCategory::CapitalT2));
        self::assertSame(250.0, $rates->ratePerRun(LineRentalCategory::ComponentT1));
    }

    public function testUnknownKeyOfALayerIsIgnored(): void
    {
        $withUnknownKeys = LineRentalRates::resolve(['titan_t3' => 9_000_000], ['dreadnought' => 8_000_000]);

        self::assertEquals(LineRentalRates::resolve(null, []), $withUnknownKeys);
        self::assertSame(1_000_000.0, $withUnknownKeys->dayFactor());
    }

    public function testMockupLineOfFiveJobsOfFourHoursThirtySevenMinutesOverFiftyRunsIsWorthTheMockupValue(): void
    {
        $rates = LineRentalRates::resolve(null, []);

        self::assertSame(5_961_805.56, $rates->valueOf(LineRentalCategory::ModuleT2, self::MOCKUP_DAYS, 50));
    }

    public function testValueIsRoundedToTwoDecimals(): void
    {
        $rates = LineRentalRates::resolve([LineRentalRates::DAY_FACTOR_KEY => 1], []);

        self::assertSame(0.13, $rates->valueOf(LineRentalCategory::ComponentT1, 0.125, 0));
        self::assertSame(333_333.33, LineRentalRates::resolve(null, [])->valueOf(LineRentalCategory::ModuleT1, 1 / 3, 0));
    }

    /**
     * @return array<string, float>
     */
    private function ratesPerRunByCategory(LineRentalRates $rates): array
    {
        $ratesPerRun = [];
        foreach (LineRentalCategory::cases() as $category) {
            $ratesPerRun[$category->value] = $rates->ratePerRun($category);
        }

        return $ratesPerRun;
    }
}
