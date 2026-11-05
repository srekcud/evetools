<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\SalesFees;

use App\Service\Industry\SalesFees\SalesFeeCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalesFeeCalculator::class)]
#[Group('issue-347')]
final class SalesFeeCalculatorTest extends TestCase
{
    private const float FRACTION_DELTA = 1e-9;

    public function testComputesTheFeesOfAnAccountingAndBrokerRelationsFiveSellerWithPositiveStandings(): void
    {
        $fees = (new SalesFeeCalculator())->calculate(5, 5, 2.0, 3.0);

        $this->assertEqualsWithDelta(0.03375, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.0138, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testChargesTheBaseRatesToASellerWithoutSkillsNorStandings(): void
    {
        $fees = (new SalesFeeCalculator())->calculate(0, 0, 0.0, 0.0);

        $this->assertEqualsWithDelta(0.075, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.03, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testComputesTheFeesOfAPartiallyTrainedSellerWithoutStandings(): void
    {
        $fees = (new SalesFeeCalculator())->calculate(4, 3, 0.0, 0.0);

        $this->assertEqualsWithDelta(0.042, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.021, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testLowersTheBrokerFeeToOnePercentAtMaximumStandings(): void
    {
        $fees = (new SalesFeeCalculator())->calculate(5, 5, 10.0, 10.0);

        $this->assertEqualsWithDelta(0.03375, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.01, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    public function testRaisesTheBrokerFeeWithANegativeFactionStanding(): void
    {
        $fees = (new SalesFeeCalculator())->calculate(5, 5, -5.0, 0.0);

        $this->assertEqualsWithDelta(0.03375, $fees->salesTaxRate, self::FRACTION_DELTA);
        $this->assertEqualsWithDelta(0.0165, $fees->brokerFeeRate, self::FRACTION_DELTA);
    }

    #[DataProvider('skillLevelsOutsideZeroToFive')]
    public function testRefusesASkillLevelOutsideZeroToFive(int $accountingLevel, int $brokerRelationsLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SalesFeeCalculator())->calculate($accountingLevel, $brokerRelationsLevel, 0.0, 0.0);
    }

    public static function skillLevelsOutsideZeroToFive(): iterable
    {
        yield 'accounting above five' => [6, 3];
        yield 'accounting below zero' => [-1, 3];
        yield 'broker relations above five' => [4, 6];
        yield 'broker relations below zero' => [4, -1];
    }
}
