<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Service\Mining\ReprocessingYieldCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReprocessingYieldCalculator::class)]
#[Group('issue-269')]
final class ReprocessingYieldCalculatorTest extends TestCase
{
    private const float YIELD_DELTA = 1e-9;
    private const float ROUNDED_EXAMPLE_DELTA = 1e-4;

    public function testNpcStationWithAllSkillsAtFiveAndAFourPercentImplantYieldsR1(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(5, 5, 5, ReprocessingLocation::NpcStation, null, null, 4);

        $this->assertEqualsWithDelta(0.72358, $reprocessYield, self::YIELD_DELTA);
    }

    public function testTataraWithAT2RigInNullsecWithAllSkillsAtFiveAndAFourPercentImplantYieldsR2(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(5, 5, 5, ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 4);

        $this->assertEqualsWithDelta(0.90628105568, $reprocessYield, self::YIELD_DELTA);
    }

    public function testNpcStationWithoutSkillsNorImplantYieldsTheBaseHalf(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(0, 0, 0, ReprocessingLocation::NpcStation, null, null, 0);

        $this->assertEqualsWithDelta(0.5, $reprocessYield, self::YIELD_DELTA);
    }

    public function testAthanorWithAT1RigInHighsecWithAllSkillsAtFiveWithoutImplantYieldsR4(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(5, 5, 5, ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Highsec, 0);

        $this->assertEqualsWithDelta(0.7238583, $reprocessYield, self::YIELD_DELTA);
    }

    public function testTataraWithoutRigInLowsecWithSkillsThreeTwoOneAndATwoPercentImplantYieldsR5(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(3, 2, 1, ReprocessingLocation::Tatara, ReprocessingRigTier::None, ReprocessingSecurity::Lowsec, 2);

        $this->assertEqualsWithDelta(0.65946, $reprocessYield, self::ROUNDED_EXAMPLE_DELTA);
    }

    public function testNpcStationIgnoresAnyRigAndSecurity(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(5, 5, 5, ReprocessingLocation::NpcStation, ReprocessingRigTier::T2, ReprocessingSecurity::Nullsec, 4);

        $this->assertEqualsWithDelta(0.72358, $reprocessYield, self::YIELD_DELTA);
    }

    #[DataProvider('singleSkillLevels')]
    public function testEachSkillRaisesTheYieldByItsOwnBonusPerLevel(int $reprocessingLevel, int $reprocessingEfficiencyLevel, int $oreProcessingLevel, float $expectedYield): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate($reprocessingLevel, $reprocessingEfficiencyLevel, $oreProcessingLevel, ReprocessingLocation::NpcStation, null, null, 0);

        $this->assertEqualsWithDelta($expectedYield, $reprocessYield, self::YIELD_DELTA);
    }

    public static function singleSkillLevels(): iterable
    {
        yield 'Reprocessing 5 adds 3 % per level' => [5, 0, 0, 0.575];
        yield 'Reprocessing Efficiency 4 adds 2 % per level' => [0, 4, 0, 0.54];
        yield 'ore processing skill 1 adds 2 % per level' => [0, 0, 1, 0.51];
    }

    public function testNpcStationMultipliesTheThreeSkillBonusesAndTheImplant(): void
    {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate(4, 3, 5, ReprocessingLocation::NpcStation, null, null, 1);

        $this->assertEqualsWithDelta(0.6594896, $reprocessYield, self::YIELD_DELTA);
    }

    #[DataProvider('structureRigAndSecurityCrossings')]
    public function testAStructureAppliesItsOwnBonusWithTheRigAndTheSecurity(
        ReprocessingLocation $location,
        ReprocessingRigTier $rigTier,
        ReprocessingSecurity $security,
        int $reprocessingLevel,
        int $reprocessingEfficiencyLevel,
        int $oreProcessingLevel,
        int $implantPercent,
        float $expectedYield,
    ): void {
        $reprocessYield = (new ReprocessingYieldCalculator())->calculate($reprocessingLevel, $reprocessingEfficiencyLevel, $oreProcessingLevel, $location, $rigTier, $security, $implantPercent);

        $this->assertEqualsWithDelta($expectedYield, $reprocessYield, self::YIELD_DELTA);
    }

    public static function structureRigAndSecurityCrossings(): iterable
    {
        yield 'Athanor, T1 rig, lowsec' => [ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Lowsec, 4, 3, 5, 1, 0.7273009586304];
        yield 'Tatara, T2 rig, highsec' => [ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Highsec, 2, 4, 3, 0, 0.6785218152];
        yield 'Athanor, no rig, nullsec' => [ReprocessingLocation::Athanor, ReprocessingRigTier::None, ReprocessingSecurity::Nullsec, 1, 0, 2, 2, 0.6241068288];
        yield 'Tatara, T2 rig, lowsec' => [ReprocessingLocation::Tatara, ReprocessingRigTier::T2, ReprocessingSecurity::Lowsec, 5, 5, 5, 1, 0.832988065085];
        yield 'Athanor, T1 rig, highsec, skills 4/3/5' => [ReprocessingLocation::Athanor, ReprocessingRigTier::T1, ReprocessingSecurity::Highsec, 4, 3, 5, 0, 0.679339584];
    }
}
