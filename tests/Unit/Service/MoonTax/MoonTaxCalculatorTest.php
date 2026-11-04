<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Enum\MoonOreRarity;
use App\Service\MoonTax\MoonTax;
use App\Service\MoonTax\MoonTaxCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonTaxCalculator::class)]
#[Group('issue-273')]
final class MoonTaxCalculatorTest extends TestCase
{
    private const int CINNABAR = 45_510;
    private const int CLOUD_RUBBLE = 45_495;
    private const int ZEOLITES = 45_490;
    private const int VELDSPAR = 1_230;

    private const array RATES_PERCENT = ['r4' => 2.0, 'r8' => 4.0, 'r16' => 8.0, 'r32' => 9.0, 'r64' => 10.0];

    private MoonTaxCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MoonTaxCalculator();
    }

    public function testRarityRateAppliesToTheCompressedValueOfTheQuantity(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::CINNABAR => 20_000],
            [self::CINNABAR => MoonOreRarity::R64],
            [self::CINNABAR => 3_000.0],
            self::RATES_PERCENT,
        );

        self::assertSame(
            [6_000_000.0, [], [[self::CINNABAR, 20_000, 3_000.0, 10.0, 6_000_000.0]]],
            [$moonTax->tax, $moonTax->unknownTypeIds, $this->linesOf($moonTax)],
        );
    }

    public function testEachTypeIsTaxedAtTheRateOfItsOwnRarityAndTheCharacterTaxIsTheSum(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::CINNABAR => 20_000, self::CLOUD_RUBBLE => 10_000],
            [self::CINNABAR => MoonOreRarity::R64, self::CLOUD_RUBBLE => MoonOreRarity::R16],
            [self::CINNABAR => 3_000.0, self::CLOUD_RUBBLE => 800.0],
            self::RATES_PERCENT,
        );

        self::assertSame(
            [6_640_000.0, [], [[self::CLOUD_RUBBLE, 10_000, 800.0, 8.0, 640_000.0], [self::CINNABAR, 20_000, 3_000.0, 10.0, 6_000_000.0]]],
            [$moonTax->tax, $moonTax->unknownTypeIds, $this->linesOf($moonTax)],
        );
    }

    public function testLineWithoutACompressedPriceMakesTheTaxUnknownAndListsItsType(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::CINNABAR => 20_000, self::CLOUD_RUBBLE => 10_000],
            [self::CINNABAR => MoonOreRarity::R64, self::CLOUD_RUBBLE => MoonOreRarity::R16],
            [self::CINNABAR => 3_000.0, self::CLOUD_RUBBLE => null],
            self::RATES_PERCENT,
        );

        self::assertSame([null, [self::CLOUD_RUBBLE]], [$moonTax->tax, $moonTax->unknownTypeIds]);
    }

    public function testTypeWithoutAnyPriceEntryMakesTheTaxUnknown(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::ZEOLITES => 5_000],
            [self::ZEOLITES => MoonOreRarity::R4],
            [],
            self::RATES_PERCENT,
        );

        self::assertSame([null, [self::ZEOLITES]], [$moonTax->tax, $moonTax->unknownTypeIds]);
    }

    public function testTypeOutsideTheMoonOreGroupsMakesTheTaxUnknownEvenWithAPrice(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::CINNABAR => 20_000, self::VELDSPAR => 30_000],
            [self::CINNABAR => MoonOreRarity::R64, self::VELDSPAR => null],
            [self::CINNABAR => 3_000.0, self::VELDSPAR => 15.0],
            self::RATES_PERCENT,
        );

        self::assertSame([null, [self::VELDSPAR]], [$moonTax->tax, $moonTax->unknownTypeIds]);
    }

    public function testEveryUnknownTypeIsListedOnceInAscendingOrder(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::CLOUD_RUBBLE => 10_000, self::VELDSPAR => 30_000, self::ZEOLITES => 5_000],
            [self::CLOUD_RUBBLE => MoonOreRarity::R16, self::VELDSPAR => null, self::ZEOLITES => MoonOreRarity::R4],
            [self::CLOUD_RUBBLE => null, self::VELDSPAR => 15.0, self::ZEOLITES => 1_200.0],
            self::RATES_PERCENT,
        );

        self::assertSame([null, [self::VELDSPAR, self::ZEOLITES, self::CLOUD_RUBBLE]], [$moonTax->tax, $moonTax->unknownTypeIds]);
    }

    public function testZeroPercentRateGivesAZeroTaxNotAnUnknownOne(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::ZEOLITES => 5_000],
            [self::ZEOLITES => MoonOreRarity::R4],
            [self::ZEOLITES => 1_200.0],
            ['r4' => 0.0] + self::RATES_PERCENT,
        );

        self::assertSame([0.0, []], [$moonTax->tax, $moonTax->unknownTypeIds]);
    }

    public function testTotalIsRoundedToTwoDecimals(): void
    {
        $moonTax = $this->calculator->calculate(
            [self::ZEOLITES => 3],
            [self::ZEOLITES => MoonOreRarity::R32],
            [self::ZEOLITES => 1.2345],
            ['r32' => 12.5] + self::RATES_PERCENT,
        );

        self::assertSame(0.46, $moonTax->tax);
    }

    private function linesOf(MoonTax $moonTax): array
    {
        $lines = array_map(
            static fn (array $line): array => [$line['typeId'], $line['quantity'], $line['unitPrice'], $line['ratePercent'], $line['tax']],
            $moonTax->lines,
        );
        usort($lines, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $lines;
    }
}
