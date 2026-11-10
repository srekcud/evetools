<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureRig;
use App\Industry\Application\StructureType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\InstallCost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\ScienceRig;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStructure::class)]
#[CoversClass(StructureRig::class)]
#[CoversClass(ScienceRig::class)]
#[Group('issue-526')]
final class ProductionStructureInstallCostMultiplierTest extends TestCase
{
    private const float FLOAT_TOLERANCE = 1e-12;
    private const float SOTIYO_COST_ROLE_BONUS = 0.95;
    private const float LABORATORY_RIG_II_REDUCTION_PERCENT = 12.0;

    private const float OBSERVED_ESTIMATED_ITEM_VALUE = 2_694_500.0;
    private const float OBSERVED_COST_INDEX = 0.0743;
    private const float OBSERVED_FACILITY_TAX_RATE = 0.01;

    #[DataProvider('laboratoryActivityProvider')]
    public function testLaboratoryRigLowersTheCostIndexTermOfEveryLaboratoryActivity(ActivityKind $activity): void
    {
        $structure = self::sotiyo(SecurityClass::NullSec, scienceRigs: ProductionStructures::laboratoryRigs(null, self::LABORATORY_RIG_II_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(0.95 * 0.748, $structure->installCostMultiplierFor($activity)->value, self::FLOAT_TOLERANCE);
    }

    /**
     * @return iterable<string, array{ActivityKind}>
     */
    public static function laboratoryActivityProvider(): iterable
    {
        yield 'copying' => [ActivityKind::Copying];
        yield 'invention' => [ActivityKind::Invention];
        yield 'ME research' => [ActivityKind::ResearchMaterial];
        yield 'TE research' => [ActivityKind::ResearchTime];
    }

    #[DataProvider('productionActivityProvider')]
    public function testLaboratoryRigLeavesProductionJobsAtTheRoleBonus(ActivityKind $activity): void
    {
        $structure = self::sotiyo(SecurityClass::NullSec, scienceRigs: ProductionStructures::laboratoryRigs(null, self::LABORATORY_RIG_II_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(self::SOTIYO_COST_ROLE_BONUS, $structure->installCostMultiplierFor($activity)->value, self::FLOAT_TOLERANCE);
    }

    /**
     * @return iterable<string, array{ActivityKind}>
     */
    public static function productionActivityProvider(): iterable
    {
        yield 'manufacturing' => [ActivityKind::Manufacturing];
        yield 'reaction' => [ActivityKind::Reaction];
    }

    #[DataProvider('securityProvider')]
    public function testSecurityModifierOfTheRigScalesItsCostReduction(SecurityClass $security, float $expectedRigMultiplier): void
    {
        $structure = self::sotiyo($security, scienceRigs: ProductionStructures::laboratoryRigs(null, self::LABORATORY_RIG_II_REDUCTION_PERCENT));

        $this->assertEqualsWithDelta(self::SOTIYO_COST_ROLE_BONUS * $expectedRigMultiplier, $structure->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    /**
     * @return iterable<string, array{SecurityClass, float}>
     */
    public static function securityProvider(): iterable
    {
        yield 'highsec' => [SecurityClass::HighSec, 0.88];
        yield 'lowsec' => [SecurityClass::LowSec, 0.772];
        yield 'nullsec' => [SecurityClass::NullSec, 0.748];
    }

    public function testMaterialRigDoesNotChangeTheInstallCostMultiplierOfCopying(): void
    {
        $structure = self::sotiyo(SecurityClass::NullSec, rigs: [ProductionStructures::manufacturingRig(2.4, 'ammunition')]);

        $this->assertEqualsWithDelta(self::SOTIYO_COST_ROLE_BONUS, $structure->installCostMultiplierFor(ActivityKind::Copying)->value, self::FLOAT_TOLERANCE);
    }

    public function testObservedCopyJobCostsTheInGameInstallCostAndTheRigTouchesNeitherTheTaxNorTheSurcharge(): void
    {
        $withRig = $this->copyInstallCostIn(self::sotiyo(SecurityClass::NullSec, scienceRigs: ProductionStructures::laboratoryRigs(null, self::LABORATORY_RIG_II_REDUCTION_PERCENT)));
        $withoutRig = $this->copyInstallCostIn(self::sotiyo(SecurityClass::NullSec));

        $this->assertEqualsWithDelta(2846.0, $withRig->systemCost->amount()->amount, 1.5);
        $this->assertEqualsWithDelta(539.0, $withRig->facilityTax->amount()->amount, 1.0);
        $this->assertEqualsWithDelta(2156.0, $withRig->sccSurcharge->amount()->amount, 1.0);
        $this->assertEqualsWithDelta(5541.0, $withRig->total->amount()->amount, 2.0);
        $this->assertSame($withoutRig->facilityTax->amount()->amount, $withRig->facilityTax->amount()->amount);
        $this->assertSame($withoutRig->sccSurcharge->amount()->amount, $withRig->sccSurcharge->amount()->amount);
    }

    private function copyInstallCostIn(ProductionStructure $structure): InstallCost
    {
        return InstallCost::forJob(
            ActivityKind::Copying,
            Cost::known(new Isk(self::OBSERVED_ESTIMATED_ITEM_VALUE)),
            self::OBSERVED_COST_INDEX,
            $structure->installCostMultiplierFor(ActivityKind::Copying),
            self::OBSERVED_FACILITY_TAX_RATE,
            false,
        );
    }

    private static function sotiyo(SecurityClass $security, array $rigs = [], array $scienceRigs = []): ProductionStructure
    {
        return new ProductionStructure(
            'sotiyo',
            StructureType::EngineeringComplex,
            $security,
            30_002_187,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(ProductionStructures::ENGINEERING_COMPLEX_MATERIAL_ROLE_BONUS),
            $rigs,
            new Multiplier(self::SOTIYO_COST_ROLE_BONUS),
            self::OBSERVED_FACILITY_TAX_RATE,
            scienceRigs: $scienceRigs,
        );
    }
}
