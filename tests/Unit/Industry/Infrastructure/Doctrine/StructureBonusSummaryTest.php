<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Infrastructure\Doctrine;

use App\Industry\Infrastructure\Doctrine\StructureBonusSummary;
use App\Tests\Unit\Support\StubsSdeStructureBonuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructureBonusSummary::class)]
#[Group('issue-1331')]
final class StructureBonusSummaryTest extends TestCase
{
    use StubsSdeStructureBonuses;

    public function testAthanorInNullsecWithACompositeReactorMaterialRigGivesItsReactionMaterialBonusOnly(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('athanor', 'nullsec', [self::SDE_COMPOSITE_REACTOR_ME_II]);

        $this->assertSame([0.0, 2.64, 0.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testTataraInNullsecWithALargeReactorEfficiencyRigStacksItsRoleAndRigReactionTime(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('tatara', 'nullsec', [self::SDE_L_SET_REACTOR_EFFICIENCY_II]);

        $this->assertSame([0.0, 2.64, 0.0, 44.8, []], self::sdeBonusesAsList($bonuses));
    }

    public function testSotiyoInLowsecWithAThukkerRigGivesItsGenericMaterialBonusRatherThanItsCapitalComponentOne(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('sotiyo', 'lowsec', [self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT]);

        $this->assertSame([3.8, 0.0, 56.6, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testSotiyoInNullsecWithAThukkerRigScalesItsBonusesByTheNullsecModifier(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('sotiyo', 'nullsec', [self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT]);

        $this->assertSame([0.2, 0.0, 31.4, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testRaitaruInHighsecWithoutRigGivesItsRoleTimeBonusOnly(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('raitaru', 'highsec', []);

        $this->assertSame([0.0, 0.0, 15.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testRaitaruWithAThukkerCapitalComponentRigFallsBackOnItsCapitalComponentMaterialBonus(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('raitaru', 'nullsec', [self::SDE_M_SET_THUKKER_BASIC_CAPITAL_COMPONENT]);

        $this->assertSame([0.37, 0.0, 15.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testLaboratoryRigAddsNoJobTimeBonusAndNoMaterialBonus(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('sotiyo', 'nullsec', [self::SDE_XL_SET_LABORATORY_OPTIMIZATION_II]);

        $this->assertSame([0.0, 0.0, 30.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testRigNameMissingFromTheSdeIsListedAndAddsNoBonus(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('raitaru', 'nullsec', [self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]);

        $this->assertSame([0.0, 0.0, 15.0, 0.0, [self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]], self::sdeBonusesAsList($bonuses));
    }

    public function testUnknownRigIsListedOnceAndTheBonusesOfTheOtherRigsAreUnchanged(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('tatara', 'nullsec', [
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II,
            self::SDE_COMPOSITE_REACTOR_ME_II,
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II,
        ]);

        $this->assertSame([0.0, 2.64, 0.0, 25.0, [self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II]], self::sdeBonusesAsList($bonuses));
    }

    public function testEveryRigUnknownLeavesTheRoleBonusesOnlyAndListsThemAllInOrder(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('raitaru', 'highsec', [
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II,
            self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_I,
        ]);

        $this->assertSame(
            [0.0, 0.0, 15.0, 0.0, [self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II, self::SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_I]],
            self::sdeBonusesAsList($bonuses),
        );
    }

    public function testNpcStationGivesNoBonusAtAll(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('station', 'highsec', []);

        $this->assertSame([0.0, 0.0, 0.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testLegacyStructureTypeHasNoRoleBonusButItsRigsAreStillRead(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('engineering_complex', 'nullsec', [self::SDE_AMMUNITION_ME_II]);

        $this->assertSame([5.04, 0.0, 0.0, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testTwoJobTimeRigsStackMultiplicativelyWithTheRoleBonus(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('raitaru', 'nullsec', [self::SDE_EQUIPMENT_TE_I, self::SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II]);

        $this->assertSame([5.04, 0.0, 75.55, 0.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testRigWithoutSecurityModifierInTheSecurityAddsNothing(): void
    {
        $bonuses = $this->sdeStructureBonusSummary()->of('tatara', 'highsec', [self::SDE_COMPOSITE_REACTOR_ME_II, self::SDE_COMPOSITE_REACTOR_TE_I]);

        $this->assertSame([0.0, 0.0, 0.0, 25.0, []], self::sdeBonusesAsList($bonuses));
    }

    public function testUnknownSecurityTypeIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->sdeStructureBonusSummary()->of('raitaru', 'wormhole', []);
    }
}
