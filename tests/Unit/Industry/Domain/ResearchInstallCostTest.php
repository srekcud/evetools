<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ResearchLevels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec #475 R3, R4: install cost of an ME or TE research job.
 *
 * base        = 2 % × manufacturing EIV × level multiplier (time of the level / time of level 1)
 * system cost = base × cost index × structure cost modifier
 * SCC         = base × 2 %
 */
#[CoversClass(InstallCost::class)]
#[CoversClass(ResearchLevels::class)]
final class ResearchInstallCostTest extends TestCase
{
    private const float EIV = 10_000_000.0;
    private const float COST_INDEX = 0.05;
    private const float ISK_TOLERANCE = 1.0;

    /**
     * @return iterable<string, array{ActivityKind, int, int, int, int}>
     */
    public static function researchLevelProvider(): iterable
    {
        yield 'ME level 1' => [ActivityKind::ResearchMaterial, 1, 10_000, 4_000, 14_000];
        yield 'ME level 10' => [ActivityKind::ResearchMaterial, 10, 24_380_952, 9_752_381, 34_133_333];
        yield 'TE level 1 (TE 2)' => [ActivityKind::ResearchTime, 1, 10_000, 4_000, 14_000];
        yield 'TE level 10 (TE 20)' => [ActivityKind::ResearchTime, 10, 24_380_952, 9_752_381, 34_133_333];
    }

    #[DataProvider('researchLevelProvider')]
    public function testResearchPaysTwoPercentOfTheEivTimesTheLevelMultiplierWithAnSccOfTwoPercent(
        ActivityKind $activity,
        int $targetLevel,
        int $expectedSystemCost,
        int $expectedSccSurcharge,
        int $expectedTotal,
    ): void {
        $installCost = InstallCost::forResearch($activity, Cost::known(new Isk(self::EIV)), $targetLevel, self::COST_INDEX, Multiplier::one(), 0.0, false);

        $this->assertKnown($expectedSystemCost, $installCost->systemCost, 'system cost');
        $this->assertKnown($expectedSccSurcharge, $installCost->sccSurcharge, 'SCC surcharge');
        $this->assertKnown(0, $installCost->facilityTax, 'facility tax');
        $this->assertKnown(0, $installCost->alphaCloneTax, 'alpha clone tax');
        $this->assertKnown($expectedTotal, $installCost->total, 'total');
    }

    public function testStructureCostBonusFacilityTaxAndAlphaTaxFollowTheSameRulesAsOtherJobs(): void
    {
        $installCost = InstallCost::forResearch(ActivityKind::ResearchMaterial, Cost::known(new Isk(self::EIV)), 1, self::COST_INDEX, new Multiplier(0.9), 0.1, true);

        $this->assertKnown(9_000, $installCost->systemCost, 'system cost');
        $this->assertKnown(20_000, $installCost->facilityTax, 'facility tax');
        $this->assertKnown(4_000, $installCost->sccSurcharge, 'SCC surcharge');
        $this->assertKnown(500, $installCost->alphaCloneTax, 'alpha clone tax');
        $this->assertKnown(33_500, $installCost->total, 'total');
    }

    public function testMissingCostIndexMakesTheResearchCostUnknownNeverZero(): void
    {
        $installCost = InstallCost::forResearch(ActivityKind::ResearchTime, Cost::known(new Isk(self::EIV)), 3, null, Multiplier::one(), 0.0, false);

        $this->assertFalse($installCost->total->isKnown());
        $this->assertEquals([MissingData::costIndex()], $installCost->total->missingData);
    }

    public function testUnknownEivMakesTheResearchCostUnknownWithItsReason(): void
    {
        $unknownEiv = Cost::unknown(MissingData::adjustedPrice(34));

        $installCost = InstallCost::forResearch(ActivityKind::ResearchMaterial, $unknownEiv, 3, self::COST_INDEX, Multiplier::one(), 0.0, false);

        $this->assertFalse($installCost->total->isKnown());
        $this->assertEquals([MissingData::adjustedPrice(34)], $installCost->total->missingData);
    }

    #[DataProvider('invalidResearchLevelProvider')]
    public function testTargetLevelOutsideOneToTenIsRefused(int $targetLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InstallCost::forResearch(ActivityKind::ResearchMaterial, Cost::known(new Isk(self::EIV)), $targetLevel, self::COST_INDEX, Multiplier::one(), 0.0, false);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidResearchLevelProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'eleven' => [11];
        yield 'negative' => [-1];
    }

    public function testForResearchRefusesAnActivityThatIsNotResearch(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InstallCost::forResearch(ActivityKind::Manufacturing, Cost::known(new Isk(self::EIV)), 1, self::COST_INDEX, Multiplier::one(), 0.0, false);
    }

    #[DataProvider('researchActivityProvider')]
    public function testForJobRefusesResearchBecauseItHasNoLevel(ActivityKind $activity): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InstallCost::forJob($activity, Cost::known(new Isk(self::EIV)), self::COST_INDEX, Multiplier::one(), 0.0, false);
    }

    /**
     * @return iterable<string, array{ActivityKind}>
     */
    public static function researchActivityProvider(): iterable
    {
        yield 'ME' => [ActivityKind::ResearchMaterial];
        yield 'TE' => [ActivityKind::ResearchTime];
    }

    public function testCopyingStillPaysTheFourPercentSccSurcharge(): void
    {
        $installCost = InstallCost::forJob(ActivityKind::Copying, Cost::known(new Isk(self::EIV)), self::COST_INDEX, Multiplier::one(), 0.0, false);

        $this->assertKnown(8_000, $installCost->sccSurcharge, 'SCC surcharge');
    }

    private function assertKnown(int|float $expectedAmount, Cost $cost, string $part): void
    {
        $this->assertTrue($cost->isKnown(), "{$part} should be known");
        $this->assertEqualsWithDelta((float) $expectedAmount, $cost->amount()->amount, self::ISK_TOLERANCE, $part);
    }
}
