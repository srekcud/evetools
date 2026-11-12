<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ScienceRig;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStructure::class)]
#[Group('issue-724')]
final class ProductionStructureResearchTimeMultiplierTest extends TestCase
{
    private const float FLOAT_TOLERANCE = 1e-12;

    private const float RAITARU_TIME_ROLE_BONUS = 0.85;
    private const float SOTIYO_TIME_ROLE_BONUS = 0.70;
    private const float RAITARU_COST_ROLE_BONUS = 0.97;

    private const float RIG_II_TIME_REDUCTION_PERCENT = 24.0;
    private const float RIG_II_COST_REDUCTION_PERCENT = 12.0;

    private const float NULLSEC_RIG_II_TIME_MULTIPLIER = 0.496;
    private const float NULLSEC_RIG_II_COST_MULTIPLIER = 0.748;

    public function testXlLaboratoryOptimizationIiShortensBothResearchesOfANullsecSotiyoByTheRoleAndTheRig(): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, ...ProductionStructures::laboratoryRigs(self::RIG_II_TIME_REDUCTION_PERCENT, self::RIG_II_COST_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(0.3472, $sotiyo->timeMultiplierFor(ActivityKind::ResearchMaterial)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.3472, $sotiyo->timeMultiplierFor(ActivityKind::ResearchTime)->value, self::FLOAT_TOLERANCE);
    }

    public function testSecurityModifierOfTheXlLaboratoryRigScalesTheResearchTimeReduction(): void
    {
        $researchTimeMultiplierIn = static fn (SecurityClass $security): float => self::sotiyo($security, ...ProductionStructures::laboratoryRigs(self::RIG_II_TIME_REDUCTION_PERCENT, self::RIG_II_COST_REDUCTION_PERCENT))
            ->timeMultiplierFor(ActivityKind::ResearchMaterial)->value;

        $this->assertEqualsWithDelta(0.532, $researchTimeMultiplierIn(SecurityClass::HighSec), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.3808, $researchTimeMultiplierIn(SecurityClass::LowSec), self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(0.3472, $researchTimeMultiplierIn(SecurityClass::NullSec), self::FLOAT_TOLERANCE);
    }

    public function testMSetMeResearchAcceleratorIiShortensTheMeResearchOnly(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::timeOnlyRig(ActivityKind::ResearchMaterial));

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $raitaru->timeMultiplierFor(ActivityKind::ResearchMaterial)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::ResearchTime)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testMSetTeResearchAcceleratorIiShortensTheTeResearchOnly(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::timeOnlyRig(ActivityKind::ResearchTime));

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $raitaru->timeMultiplierFor(ActivityKind::ResearchTime)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::ResearchMaterial)->value, self::FLOAT_TOLERANCE);
    }

    #[DataProvider('researchActivityProvider')]
    public function testResearchCostOptimizationLeavesTheResearchTimeAtTheRoleBonus(ActivityKind $researchActivity): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, ProductionStructures::scienceRig($researchActivity, null, self::RIG_II_COST_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor($researchActivity)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $raitaru->installCostMultiplierFor($researchActivity)->value, self::FLOAT_TOLERANCE);
    }

    public function testTeResearchCostOptimizationLeavesTheMeResearchInstallCostAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, ProductionStructures::scienceRig(ActivityKind::ResearchTime, null, self::RIG_II_COST_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS * self::NULLSEC_RIG_II_COST_MULTIPLIER, $raitaru->installCostMultiplierFor(ActivityKind::ResearchTime)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_COST_ROLE_BONUS, $raitaru->installCostMultiplierFor(ActivityKind::ResearchMaterial)->value, self::FLOAT_TOLERANCE);
    }

    #[DataProvider('researchActivityProvider')]
    public function testCopyAndInventionRigsLeaveTheResearchTimeAtTheRoleBonus(ActivityKind $researchActivity): void
    {
        $raitaru = self::raitaru(
            SecurityClass::NullSec,
            self::timeOnlyRig(ActivityKind::Copying),
            self::timeOnlyRig(ActivityKind::Invention),
        );

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor($researchActivity)->value, self::FLOAT_TOLERANCE);
    }

    public function testResearchRigsLeaveTheCopyAndInventionTimeAtTheRoleBonus(): void
    {
        $raitaru = self::raitaru(
            SecurityClass::NullSec,
            self::timeOnlyRig(ActivityKind::ResearchMaterial),
            self::timeOnlyRig(ActivityKind::ResearchTime),
        );

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Invention)->value, self::FLOAT_TOLERANCE);
    }

    public function testInventionRigShortensTheInventionTimeOnly(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::timeOnlyRig(ActivityKind::Invention));

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER, $raitaru->timeMultiplierFor(ActivityKind::Invention)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->timeMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    #[DataProvider('researchActivityProvider')]
    public function testTwoResearchTimeRigsOfTheSameActivityMultiplyTheirReductions(ActivityKind $researchActivity): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, self::timeOnlyRig($researchActivity), self::timeOnlyRig($researchActivity));

        $this->assertEqualsWithDelta(
            self::RAITARU_TIME_ROLE_BONUS * self::NULLSEC_RIG_II_TIME_MULTIPLIER * self::NULLSEC_RIG_II_TIME_MULTIPLIER,
            $raitaru->timeMultiplierFor($researchActivity)->value,
            self::FLOAT_TOLERANCE,
        );
    }

    #[DataProvider('researchActivityProvider')]
    public function testStructureWithoutTimeRoleBonusNorRigResearchesAtTheBaseTime(ActivityKind $researchActivity): void
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

        $this->assertSame(1.0, $structure->timeMultiplierFor($researchActivity)->value);
    }

    #[DataProvider('industrialActivityProvider')]
    public function testTimeMultiplierIsRefusedForManufacturingAndReaction(ActivityKind $activity): void
    {
        $sotiyo = self::sotiyo(SecurityClass::NullSec, ...ProductionStructures::laboratoryRigs(self::RIG_II_TIME_REDUCTION_PERCENT, self::RIG_II_COST_REDUCTION_PERCENT));

        $this->expectException(\InvalidArgumentException::class);

        $sotiyo->timeMultiplierFor($activity);
    }

    public static function researchActivityProvider(): iterable
    {
        yield 'ME research' => [ActivityKind::ResearchMaterial];
        yield 'TE research' => [ActivityKind::ResearchTime];
    }

    public static function industrialActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing];
        yield 'reaction' => [ActivityKind::Reaction];
    }

    private static function timeOnlyRig(ActivityKind $activity): ScienceRig
    {
        return ProductionStructures::scienceRig($activity, self::RIG_II_TIME_REDUCTION_PERCENT, null);
    }

    private static function raitaru(SecurityClass $security, ScienceRig ...$scienceRigs): ProductionStructure
    {
        return self::engineeringComplex('raitaru', $security, self::RAITARU_TIME_ROLE_BONUS, $scienceRigs);
    }

    private static function sotiyo(SecurityClass $security, ScienceRig ...$scienceRigs): ProductionStructure
    {
        return self::engineeringComplex('sotiyo', $security, self::SOTIYO_TIME_ROLE_BONUS, $scienceRigs);
    }

    private static function engineeringComplex(string $id, SecurityClass $security, float $timeRoleBonus, array $scienceRigs): ProductionStructure
    {
        return new ProductionStructure(
            $id,
            StructureType::EngineeringComplex,
            $security,
            30_002_187,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            [],
            new Multiplier(self::RAITARU_COST_ROLE_BONUS),
            ProductionStructures::NO_FACILITY_TAX,
            new Multiplier($timeRoleBonus),
            array_values($scienceRigs),
        );
    }
}
