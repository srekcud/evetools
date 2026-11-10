<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\JobDuration;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Runs;
use App\Industry\Domain\ScienceRig;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\SkillTimeModifier;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStructure::class)]
#[CoversClass(ScienceRig::class)]
#[Group('issue-523')]
final class ProductionStructureCopyTimeMultiplierTest extends TestCase
{
    private const float FLOAT_TOLERANCE = 1e-12;

    private const float RAITARU_TIME_ROLE_BONUS = 0.85;
    private const float AZBEL_TIME_ROLE_BONUS = 0.80;
    private const float SOTIYO_TIME_ROLE_BONUS = 0.70;

    private const float RAITARU_COST_ROLE_BONUS = 0.97;
    private const float AZBEL_COST_ROLE_BONUS = 0.96;
    private const float SOTIYO_COST_ROLE_BONUS = 0.95;

    private const float SCIENCE_RIG_II_TIME_REDUCTION_PERCENT = 24.0;
    private const float SCIENCE_RIG_II_COST_REDUCTION_PERCENT = 12.0;

    private const float NULLSEC_RIG_II_TIME_MULTIPLIER = 0.496;
    private const float NULLSEC_RIG_II_COST_MULTIPLIER = 0.748;

    private const int HORNET_COPY_BASE_TIME_PER_RUN_SECONDS = 240;
    private const int HORNET_COPY_RUNS = 600;
    private const int RAVEN_COPY_BASE_TIME_PER_RUN_SECONDS = 14_400;

    private const int SKILL_LEVEL_V = 5;
    private const int NO_SKILL = 0;

    public function testHornetCopyInANullsecSotiyoWithAnXlLaboratoryOptimizationIiTakes31873Seconds(): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, scienceRigs: self::xlLaboratoryOptimizationII());

        $structureTimeMultiplier = $sotiyo->timeMultiplierFor(ActivityKind::Copying);
        $duration = JobDuration::forCopyJob(
            self::HORNET_COPY_BASE_TIME_PER_RUN_SECONDS,
            new Runs(self::HORNET_COPY_RUNS),
            SkillTimeModifier::forCopy(self::SKILL_LEVEL_V, self::SKILL_LEVEL_V)->times($structureTimeMultiplier),
        );

        $this->assertEqualsWithDelta(0.3472, $structureTimeMultiplier->value, self::FLOAT_TOLERANCE);
        $this->assertSame(31_873, $duration->seconds);
    }

    public function testHornetCopyInASotiyoWithoutRigTakes64260SecondsFromTheTimeRoleBonusAlone(): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec);

        $duration = JobDuration::forCopyJob(
            self::HORNET_COPY_BASE_TIME_PER_RUN_SECONDS,
            new Runs(self::HORNET_COPY_RUNS),
            SkillTimeModifier::forCopy(self::SKILL_LEVEL_V, self::SKILL_LEVEL_V)->times($sotiyo->timeMultiplierFor(ActivityKind::Copying)),
        );

        $this->assertSame(64_260, $duration->seconds);
    }

    public function testRavenCopyRunWithoutSkillTakesTheTimeRoleBonusOfEachEngineeringComplex(): void
    {
        $withoutSkill = SkillTimeModifier::forCopy(self::NO_SKILL, self::NO_SKILL);
        $durationIn = static fn (ProductionStructure $structure): int => JobDuration::forCopyJob(
            self::RAVEN_COPY_BASE_TIME_PER_RUN_SECONDS,
            new Runs(1),
            $withoutSkill->times($structure->timeMultiplierFor(ActivityKind::Copying)),
        )->seconds;

        $this->assertSame(12_240, $durationIn(self::raitaru(SecurityClass::NullSec)));
        $this->assertSame(11_520, $durationIn(self::azbel(SecurityClass::NullSec)));
        $this->assertSame(10_080, $durationIn(self::sotiyo(SecurityClass::NullSec)));
    }

    public function testSecurityModifierOfTheXlLaboratoryRigScalesItsCopyTimeReduction(): void
    {
        $copyTimeMultiplierIn = static fn (SecurityClass $security): float => self::sotiyo($security, scienceRigs: self::xlLaboratoryOptimizationII())
            ->timeMultiplierFor(ActivityKind::Copying)->value;

        $this->assertEqualsWithDelta(0.532, $copyTimeMultiplierIn(SecurityClass::HighSec), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.3808, $copyTimeMultiplierIn(SecurityClass::LowSec), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.3472, $copyTimeMultiplierIn(SecurityClass::NullSec), self::FLOAT_TOLERANCE);
    }

    public function testMSetBlueprintCopyAcceleratorIiShortensTheCopyAndLeavesItsInstallCostAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::mSetBlueprintCopyAcceleratorII());

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS, $raitaru->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testMSetBlueprintCopyCostOptimizationIiLowersTheCopyInstallCostAndLeavesItsTimeAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::mSetBlueprintCopyCostOptimizationII());

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $raitaru->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testBothMSetBlueprintCopyRigsLowerTheCopyTimeAndTheCopyInstallCost(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::mSetBlueprintCopyAcceleratorII(), self::mSetBlueprintCopyCostOptimizationII());

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $raitaru->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testLSetBlueprintCopyOptimizationIiLowersTheCopyTimeAndTheCopyInstallCostOfANullsecAzbel(): void
    {
        $azbel = self::azbel(SecurityClass::NullSec, ProductionStructures::scienceRig(
            ActivityKind::Copying,
            self::SCIENCE_RIG_II_TIME_REDUCTION_PERCENT,
            self::SCIENCE_RIG_II_COST_REDUCTION_PERCENT,
        ));

        $this->assertEqualsWithDelta(self::AZBEL_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $azbel->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::AZBEL_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $azbel->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testCopyCostRigLowersTheCopyInstallCostOnlyAndLeavesTheInventionOneAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::mSetBlueprintCopyCostOptimizationII());

        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS, $raitaru->installCostMultiplierFor(ActivityKind::Invention)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $raitaru->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testShipManufacturingRigLeavesTheCopyTimeAtTheRoleBonusAndKeepsItsManufacturingMaterialBonus(): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, rigs: [ProductionStructures::manufacturingRig(2.4, 'basic_large_ship')]);

        $this->assertEqualsWithDelta(self::SOTIYO_TIME_ROLE_BONUS, $sotiyo->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.940104, $sotiyo->materialMultiplierFor(ActivityKind::Manufacturing, 'basic_large_ship')->value, self::FLOAT_TOLERANCE);
    }

    public function testXlLaboratoryRigLeavesTheManufacturingMaterialsAndInstallCostAtTheRoleBonus(): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, scienceRigs: self::xlLaboratoryOptimizationII());

        $this->assertEqualsWithDelta(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS, $sotiyo->materialMultiplierFor(ActivityKind::Manufacturing, 'basic_large_ship')->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::SOTIYO_COST_ROLE_BONUS, $sotiyo->installCostMultiplierFor(ActivityKind::Manufacturing)->value, self::FLOAT_TOLERANCE);
        $this->assertFalse($sotiyo->isAdaptedTo('basic_large_ship'));
    }

    public function testInventionRigLeavesTheCopyTimeAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, ProductionStructures::scienceRig(
            ActivityKind::Invention,
            self::SCIENCE_RIG_II_TIME_REDUCTION_PERCENT,
            self::SCIENCE_RIG_II_COST_REDUCTION_PERCENT,
        ));

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS, $raitaru->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testTwoCopyTimeRigsMultiplyTheirReductions(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::mSetBlueprintCopyAcceleratorII(), self::mSetBlueprintCopyAcceleratorII());

        $this->assertEqualsWithDelta(
            self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER * self::NULLSEC_RIG_II_TIME_MULTIPLIER,
            $raitaru->timeMultiplierFor(ActivityKind::Copying)->value,
            self::FLOAT_TOLERANCE,
        );
    }

    public function testStructureWithoutTimeRoleBonusNorRigCopiesAtTheBaseTime(): void
    {
        $structure = new ProductionStructure(
            'raitaru-without-time-role-bonus',
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(self::RAITARU_COST_ROLE_BONUS),
            ProductionStructures::NO_FACILITY_TAX,
        );

        $this->assertSame(1.0, $structure->timeMultiplierFor(ActivityKind::Copying)->value);
    }

    #[DataProvider('activityOtherThanCopyingProvider')]
    public function testTimeMultiplierIsRefusedForAnActivityOtherThanCopying(ActivityKind $activity): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, scienceRigs: self::xlLaboratoryOptimizationII());

        $this->expectException(\InvalidArgumentException::class);

        $sotiyo->timeMultiplierFor($activity);
    }

    public static function activityOtherThanCopyingProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing];
        yield 'reaction' => [ActivityKind::Reaction];
        yield 'invention' => [ActivityKind::Invention];
        yield 'ME research' => [ActivityKind::ResearchMaterial];
        yield 'TE research' => [ActivityKind::ResearchTime];
    }

    private static function xlLaboratoryOptimizationII(): array
    {
        return ProductionStructures::laboratoryRigs(self::SCIENCE_RIG_II_TIME_REDUCTION_PERCENT, self::SCIENCE_RIG_II_COST_REDUCTION_PERCENT);
    }

    private static function mSetBlueprintCopyAcceleratorII(): ScienceRig
    {
        return ProductionStructures::scienceRig(ActivityKind::Copying, self::SCIENCE_RIG_II_TIME_REDUCTION_PERCENT, null);
    }

    private static function mSetBlueprintCopyCostOptimizationII(): ScienceRig
    {
        return ProductionStructures::scienceRig(ActivityKind::Copying, null, self::SCIENCE_RIG_II_COST_REDUCTION_PERCENT);
    }

    private static function raitaru(SecurityClass $security, ScienceRig ...$scienceRigs): ProductionStructure
    {
        return self::engineeringComplex('raitaru', $security, self::RAITARU_TIME_ROLE_BONUS, self::RAITARU_COST_ROLE_BONUS, [], array_values($scienceRigs));
    }

    private static function azbel(SecurityClass $security, ScienceRig ...$scienceRigs): ProductionStructure
    {
        return self::engineeringComplex('azbel', $security, self::AZBEL_TIME_ROLE_BONUS, self::AZBEL_COST_ROLE_BONUS, [], array_values($scienceRigs));
    }

    private static function sotiyo(SecurityClass $security, array $rigs = [], array $scienceRigs = []): ProductionStructure
    {
        return self::engineeringComplex('sotiyo', $security, self::SOTIYO_TIME_ROLE_BONUS, self::SOTIYO_COST_ROLE_BONUS, $rigs, $scienceRigs);
    }

    private static function engineeringComplex(string $id, SecurityClass $security, float $timeRoleBonus, float $costRoleBonus, array $rigs, array $scienceRigs): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            StructureType::EngineeringComplex,
            $security,
            30_002_187,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            $rigs,
            new Multiplier($costRoleBonus),
            ProductionStructures::NO_FACILITY_TAX,
            new Multiplier($timeRoleBonus),
            $scienceRigs,
        );
    }
}
