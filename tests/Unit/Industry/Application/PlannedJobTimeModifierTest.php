<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedJobTimeModifier;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Application\TimeRig;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\SecurityClass;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlannedJobTimeModifier::class)]
#[Group('issue-338')]
final class PlannedJobTimeModifierTest extends TestCase
{
    private const float FLOAT_TOLERANCE = 1e-12;

    private const int HORNET_TYPE_ID = 2_048;
    private const int FERNITE_CARBIDE_TYPE_ID = 16_673;
    private const int UNCATEGORIZED_TYPE_ID = 999;

    private const string RAITARU_ID = 'raitaru-id';
    private const string SOTIYO_ID = 'sotiyo-id';
    private const string TATARA_ID = 'tatara-id';
    private const string AZBEL_ID = 'azbel-id';

    public function testJobWithoutPlannedStructureHasNoStructureTimeBonus(): void
    {
        $modifier = $this->modifier()->of(null, $this->structures(), self::HORNET_TYPE_ID, ActivityKind::Manufacturing);

        $this->assertNotNull($modifier);
        $this->assertSame(1.0, $modifier->value);
    }

    public function testManufacturingJobTakesTheTimeOfItsPlannedStructureForTheCategoryOfItsProduct(): void
    {
        $modifier = $this->modifier()->of(self::RAITARU_ID, $this->structures(), self::HORNET_TYPE_ID, ActivityKind::Manufacturing);

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.85 * 0.58, $modifier->value, self::FLOAT_TOLERANCE);
    }

    public function testManufacturingJobTakesOnlyItsPlannedStructureAmongTheConfiguredOnes(): void
    {
        $modifier = $this->modifier()->of(self::SOTIYO_ID, $this->structures(), self::HORNET_TYPE_ID, ActivityKind::Manufacturing);

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.7, $modifier->value, self::FLOAT_TOLERANCE);
    }

    public function testProductWithoutRigCategoryGetsTheRoleBonusOnly(): void
    {
        $modifier = $this->modifier()->of(self::RAITARU_ID, $this->structures(), self::UNCATEGORIZED_TYPE_ID, ActivityKind::Manufacturing);

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.85, $modifier->value, self::FLOAT_TOLERANCE);
    }

    public function testReactionJobTakesTheReactionTimeOfItsPlannedRefineryForTheCategoryOfItsFormula(): void
    {
        $modifier = $this->modifier()->of(self::TATARA_ID, $this->structures(), self::FERNITE_CARBIDE_TYPE_ID, ActivityKind::Reaction);

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.75 * 0.78, $modifier->value, self::FLOAT_TOLERANCE);
    }

    public function testPlannedStructureThatIsNoLongerConfiguredIsUnknownNeverNeutral(): void
    {
        $this->assertNull($this->modifier()->of('deleted-structure-id', $this->structures(), self::HORNET_TYPE_ID, ActivityKind::Manufacturing));
    }

    public function testPlannedStructureIsUnknownWhenTheUserConfiguresNoStructureAtAll(): void
    {
        $this->assertNull($this->modifier()->of(self::RAITARU_ID, [], self::HORNET_TYPE_ID, ActivityKind::Manufacturing));
    }

    #[Group('issue-1204')]
    public function testCopyWithoutPlannedStructureHasNoStructureTimeBonus(): void
    {
        $modifier = $this->modifier()->ofCopy(null, $this->structures());

        $this->assertNotNull($modifier);
        $this->assertSame(1.0, $modifier->value);
    }

    #[Group('issue-1204')]
    public function testCopyTakesTheCopyTimeOfItsPlannedStructureFromItsRoleBonusAndItsCopyRigsOnly(): void
    {
        $modifier = $this->modifier()->ofCopy(self::AZBEL_ID, $this->structures());

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.8 * 0.496, $modifier->value, self::FLOAT_TOLERANCE);
    }

    #[Group('issue-1204')]
    public function testCopyInAStructureWithoutScienceRigGetsTheTimeRoleBonusAlone(): void
    {
        $modifier = $this->modifier()->ofCopy(self::RAITARU_ID, $this->structures());

        $this->assertNotNull($modifier);
        $this->assertEqualsWithDelta(0.85, $modifier->value, self::FLOAT_TOLERANCE);
    }

    #[Group('issue-1204')]
    public function testCopyPlannedInAStructureThatIsNoLongerConfiguredIsUnknownNeverNeutral(): void
    {
        $this->assertNull($this->modifier()->ofCopy('deleted-structure-id', $this->structures()));
    }

    #[Group('issue-1204')]
    public function testCopyIsUnknownWhenTheUserConfiguresNoStructureAtAll(): void
    {
        $this->assertNull($this->modifier()->ofCopy(self::AZBEL_ID, []));
    }

    private function modifier(): PlannedJobTimeModifier
    {
        return new PlannedJobTimeModifier(new InMemoryRigCategories(
            [self::HORNET_TYPE_ID => 'equipment'],
            [self::FERNITE_CARBIDE_TYPE_ID => 'composite_reaction'],
        ));
    }

    /**
     * @return list<ProductionStructure>
     */
    private function structures(): array
    {
        $engineeringRig = new TimeRig(new RigBonus(20.0, new RigSecurityModifiers(1.0, 1.9, 2.1)), ['equipment']);
        $refineryRig = new TimeRig(new RigBonus(20.0, new RigSecurityModifiers(null, 1.0, 1.1)), ['composite_reaction']);

        return [
            new ProductionStructure(self::RAITARU_ID, StructureType::EngineeringComplex, SecurityClass::NullSec, null, new \DateTimeImmutable('2026-01-01'), Multiplier::one(), [], Multiplier::one(), 0.0, new Multiplier(0.85), [], Multiplier::one(), [$engineeringRig]),
            new ProductionStructure(self::SOTIYO_ID, StructureType::EngineeringComplex, SecurityClass::NullSec, null, new \DateTimeImmutable('2026-01-01'), Multiplier::one(), [], Multiplier::one(), 0.0, new Multiplier(0.7)),
            new ProductionStructure(self::AZBEL_ID, StructureType::EngineeringComplex, SecurityClass::NullSec, null, new \DateTimeImmutable('2026-01-01'), Multiplier::one(), [], Multiplier::one(), 0.0, new Multiplier(0.8), [ProductionStructures::scienceRig(ActivityKind::Copying, 24.0, null), ProductionStructures::scienceRig(ActivityKind::ResearchTime, 10.0, null)]),
            new ProductionStructure(self::TATARA_ID, StructureType::Refinery, SecurityClass::NullSec, null, new \DateTimeImmutable('2026-01-01'), Multiplier::one(), [], Multiplier::one(), 0.0, Multiplier::one(), [], new Multiplier(0.75), [$refineryRig]),
        ];
    }
}
