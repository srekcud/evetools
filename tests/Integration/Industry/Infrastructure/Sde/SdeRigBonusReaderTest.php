<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\ScienceRig;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;

/**
 * Spec R4 (D10): rig bonuses are read from the dogma attributes of the rig type (sde_dgm_type_attributes).
 * The SDE stores a reduction as a negative percent (−2.4); RigBonus carries it as a positive one (2.4).
 * An absent attribute means "this rig gives no such bonus" (bonus) or "this rig cannot be used there" (security
 * modifier): null, never a default. The rig → groups targeting stays in IndustryRigCategory, outside this reader.
 *
 * The importer stores a whole number in value_int and any other number in value_float (SdeDogmaImporter):
 * the fixtures keep that split. Values: SDE of the dev database on 2026-10-07.
 */
final class SdeRigBonusReaderTest extends IntegrationTestCase
{
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_ENG_RIG_TIME_BONUS = 2593;
    private const int ATTRIBUTE_ENG_RIG_MAT_BONUS = 2594;
    private const int ATTRIBUTE_ENG_RIG_COST_BONUS = 2595;
    private const int ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS = 2653;
    private const int ATTRIBUTE_REF_RIG_TIME_BONUS = 2713;
    private const int ATTRIBUTE_REF_RIG_MAT_BONUS = 2714;

    private const int EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_I = 43920;
    private const int EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II = 43921;
    private const int EQUIPMENT_MANUFACTURING_TIME_EFFICIENCY_I = 37160;
    private const int COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II = 46487;
    private const int COMPOSITE_REACTOR_TIME_EFFICIENCY_I = 46484;
    private const int THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY = 45544;
    private const int THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY = 45640;

    private const int XL_SET_LABORATORY_OPTIMIZATION_II = 37182;
    private const int XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II = 37181;
    private const int M_SET_BLUEPRINT_COPY_ACCELERATOR_II = 43892;
    private const int M_SET_BLUEPRINT_COPY_COST_OPTIMIZATION_II = 43890;
    private const int L_SET_BLUEPRINT_COPY_OPTIMIZATION_I = 43729;
    private const int M_SET_INVENTION_ACCELERATOR_II = 43881;
    private const int UNKNOWN_RIG = 99_999_999;

    private const int EFFECT_RIG_SLOT = 2663;
    private const int EFFECT_STRUCTURE_RIG_SECURITY = 6842;
    private const int EFFECT_INVENTION_COST = 6830;
    private const int EFFECT_INVENTION_TIME = 6831;
    private const int EFFECT_RESEARCH_MATERIAL_COST = 6832;
    private const int EFFECT_RESEARCH_MATERIAL_TIME = 6833;
    private const int EFFECT_RESEARCH_TIME_COST = 6834;
    private const int EFFECT_RESEARCH_TIME_TIME = 6835;
    private const int EFFECT_COPY_COST = 6836;
    private const int EFFECT_COPY_TIME = 6837;
    private const int EFFECT_SHIP_MANUFACTURING_MATERIAL = 6840;
    private const int EFFECT_SHIP_MANUFACTURING_TIME = 6841;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testManufacturingMaterialBonusOfAT2RigIsReadAsAPositivePercent(): void
    {
        $this->insertManufacturingRig(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, materialBonus: -2.4, timeBonus: 0);

        $rigBonus = $this->reader()->materialBonusOf(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, ActivityKind::Manufacturing);

        $this->assertNotNull($rigBonus);
        $this->assertSame(2.4, $rigBonus->reductionPercent);
    }

    public function testManufacturingMaterialBonusStoredAsAWholeNumberIsRead(): void
    {
        $this->insertManufacturingRig(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_I, materialBonus: -2, timeBonus: 0);

        $rigBonus = $this->reader()->materialBonusOf(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_I, ActivityKind::Manufacturing);

        $this->assertNotNull($rigBonus);
        $this->assertSame(2.0, $rigBonus->reductionPercent);
    }

    public function testManufacturingRigCarriesItsHighLowAndNullSecModifiers(): void
    {
        $this->insertManufacturingRig(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, materialBonus: -2.4, timeBonus: 0);

        $rigBonus = $this->reader()->materialBonusOf(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, ActivityKind::Manufacturing);

        $this->assertNotNull($rigBonus);
        $this->assertSame(1.0, $rigBonus->securityModifiers->highSec);
        $this->assertSame(1.9, $rigBonus->securityModifiers->lowSec);
        $this->assertSame(2.1, $rigBonus->securityModifiers->nullSec);
    }

    public function testManufacturingTimeBonusOfAT1RigIsReadAsAPositivePercent(): void
    {
        $this->insertManufacturingRig(self::EQUIPMENT_MANUFACTURING_TIME_EFFICIENCY_I, materialBonus: 0, timeBonus: -20);

        $rigBonus = $this->reader()->timeBonusOf(self::EQUIPMENT_MANUFACTURING_TIME_EFFICIENCY_I, ActivityKind::Manufacturing);

        $this->assertNotNull($rigBonus);
        $this->assertSame(20.0, $rigBonus->reductionPercent);
        $this->assertSame(2.1, $rigBonus->securityModifiers->nullSec);
    }

    public function testReactionMaterialBonusIsReadFromTheRefineryRigAttribute(): void
    {
        $this->insertReactionMaterialRig(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, -2.4);

        $rigBonus = $this->reader()->materialBonusOf(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, ActivityKind::Reaction);

        $this->assertNotNull($rigBonus);
        $this->assertSame(2.4, $rigBonus->reductionPercent);
    }

    /**
     * Issue #72: a reaction rig scales by ×1.0 in lowsec and ×1.1 in nullsec, never by the manufacturing ×1.9 / ×2.1.
     * The SDE gives it no hiSecModifier: reaction rigs cannot be used in highsec.
     */
    public function testReactionRigHasNoHighSecModifierAndItsOwnLowAndNullSecModifiers(): void
    {
        $this->insertReactionMaterialRig(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, -2.4);

        $rigBonus = $this->reader()->materialBonusOf(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, ActivityKind::Reaction);

        $this->assertNotNull($rigBonus);
        $this->assertNull($rigBonus->securityModifiers->highSec);
        $this->assertSame(1.0, $rigBonus->securityModifiers->lowSec);
        $this->assertSame(1.1, $rigBonus->securityModifiers->nullSec);
    }

    public function testReactionTimeBonusIsReadFromTheRefineryRigAttribute(): void
    {
        $this->insertReactionRigSecurityModifiers(self::COMPOSITE_REACTOR_TIME_EFFICIENCY_I);
        $this->insertAttribute(self::COMPOSITE_REACTOR_TIME_EFFICIENCY_I, self::ATTRIBUTE_REF_RIG_TIME_BONUS, -20);

        $rigBonus = $this->reader()->timeBonusOf(self::COMPOSITE_REACTOR_TIME_EFFICIENCY_I, ActivityKind::Reaction);

        $this->assertNotNull($rigBonus);
        $this->assertSame(20.0, $rigBonus->reductionPercent);
    }

    public function testRigWithoutTheBonusAttributeGivesNoBonus(): void
    {
        $this->insertReactionMaterialRig(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, -2.4);

        $this->assertNull($this->reader()->timeBonusOf(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, ActivityKind::Reaction));
    }

    public function testReactionRigGivesNoManufacturingBonus(): void
    {
        $this->insertReactionMaterialRig(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, -2.4);

        $this->assertNull($this->reader()->materialBonusOf(self::COMPOSITE_REACTOR_MATERIAL_EFFICIENCY_II, ActivityKind::Manufacturing));
    }

    public function testUnknownRigGivesNoBonus(): void
    {
        $this->assertNull($this->reader()->materialBonusOf(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, ActivityKind::Manufacturing));
    }

    /**
     * Issue #71: a Thukker rig gives 3.7 % on basic capital components (attributeThukkerEngRigMatBonus) and is scaled
     * ×0.1 in highsec and nullsec, ×1.9 in lowsec.
     */
    public function testThukkerMaterialBonusIsReadWithItsOwnSecurityModifiers(): void
    {
        $this->insertThukkerRigSecurityModifiers(self::THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY);
        $this->insertAttribute(self::THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY, self::ATTRIBUTE_ENG_RIG_TIME_BONUS, 0);
        $this->insertAttribute(self::THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY, self::ATTRIBUTE_ENG_RIG_COST_BONUS, 0);
        $this->insertAttribute(self::THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY, self::ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS, -3.7);

        $rigBonus = $this->reader()->thukkerMaterialBonusOf(self::THUKKER_BASIC_CAPITAL_COMPONENT_MATERIAL_EFFICIENCY);

        $this->assertNotNull($rigBonus);
        $this->assertSame(3.7, $rigBonus->reductionPercent);
        $this->assertSame(0.1, $rigBonus->securityModifiers->highSec);
        $this->assertSame(1.9, $rigBonus->securityModifiers->lowSec);
        $this->assertSame(0.1, $rigBonus->securityModifiers->nullSec);
    }

    /**
     * A Thukker advanced component rig carries both: 2.0 % (attributeEngRigMatBonus) and 3.7 % (Thukker attribute).
     * Which one applies depends on the product group, decided outside this reader (IndustryRigCategory).
     */
    public function testThukkerRigKeepsItsGeneralManufacturingMaterialBonusApartFromItsThukkerBonus(): void
    {
        $this->insertThukkerRigSecurityModifiers(self::THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY);
        $this->insertAttribute(self::THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY, self::ATTRIBUTE_ENG_RIG_MAT_BONUS, -2);
        $this->insertAttribute(self::THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY, self::ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS, -3.7);

        $generalBonus = $this->reader()->materialBonusOf(self::THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY, ActivityKind::Manufacturing);
        $thukkerBonus = $this->reader()->thukkerMaterialBonusOf(self::THUKKER_ADVANCED_COMPONENT_MATERIAL_EFFICIENCY);

        $this->assertInstanceOf(RigBonus::class, $generalBonus);
        $this->assertInstanceOf(RigBonus::class, $thukkerBonus);
        $this->assertSame(2.0, $generalBonus->reductionPercent);
        $this->assertSame(3.7, $thukkerBonus->reductionPercent);
    }

    public function testRigWithoutThukkerAttributeGivesNoThukkerBonus(): void
    {
        $this->insertManufacturingRig(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II, materialBonus: -2.4, timeBonus: 0);

        $this->assertNull($this->reader()->thukkerMaterialBonusOf(self::EQUIPMENT_MANUFACTURING_MATERIAL_EFFICIENCY_II));
    }

    #[Group('issue-523')]
    public function testXlLaboratoryOptimizationIiIsAScienceRigOfEachLaboratoryActivity(): void
    {
        $this->insertScienceRig(self::XL_SET_LABORATORY_OPTIMIZATION_II, timeBonus: -24, costBonus: -12, effects: [
            self::EFFECT_INVENTION_COST, self::EFFECT_INVENTION_TIME,
            self::EFFECT_RESEARCH_MATERIAL_COST, self::EFFECT_RESEARCH_MATERIAL_TIME,
            self::EFFECT_RESEARCH_TIME_COST, self::EFFECT_RESEARCH_TIME_TIME,
            self::EFFECT_COPY_COST, self::EFFECT_COPY_TIME,
        ]);

        $this->assertSame(
            [
                'Copying' => [24.0, 12.0],
                'Invention' => [24.0, 12.0],
                'ResearchMaterial' => [24.0, 12.0],
                'ResearchTime' => [24.0, 12.0],
            ],
            self::bonusesByActivity($this->reader()->scienceRigsOf(self::XL_SET_LABORATORY_OPTIMIZATION_II)),
        );
    }

    #[Group('issue-523')]
    public function testMSetBlueprintCopyAcceleratorIiGivesACopyTimeBonusAndNoCostBonus(): void
    {
        $this->insertScienceRig(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II, timeBonus: -24, costBonus: 0, effects: [self::EFFECT_COPY_TIME]);

        $this->assertSame(
            ['Copying' => [24.0, null]],
            self::bonusesByActivity($this->reader()->scienceRigsOf(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II)),
        );
    }

    #[Group('issue-523')]
    public function testMSetBlueprintCopyCostOptimizationIiGivesACopyCostBonusAndNoTimeBonus(): void
    {
        $this->insertScienceRig(self::M_SET_BLUEPRINT_COPY_COST_OPTIMIZATION_II, timeBonus: 0, costBonus: -12, effects: [self::EFFECT_COPY_COST]);

        $this->assertSame(
            ['Copying' => [null, 12.0]],
            self::bonusesByActivity($this->reader()->scienceRigsOf(self::M_SET_BLUEPRINT_COPY_COST_OPTIMIZATION_II)),
        );
    }

    #[Group('issue-523')]
    public function testLSetBlueprintCopyOptimizationIGivesBothCopyBonusesWithTheSecurityModifiersOfTheRig(): void
    {
        $this->insertScienceRig(self::L_SET_BLUEPRINT_COPY_OPTIMIZATION_I, timeBonus: -20, costBonus: -10, effects: [self::EFFECT_COPY_COST, self::EFFECT_COPY_TIME]);

        $scienceRigs = $this->reader()->scienceRigsOf(self::L_SET_BLUEPRINT_COPY_OPTIMIZATION_I);

        $this->assertSame(['Copying' => [20.0, 10.0]], self::bonusesByActivity($scienceRigs));
        $this->assertSame([1.0, 1.9, 2.1], [
            $scienceRigs[0]->timeBonus?->securityModifiers->highSec,
            $scienceRigs[0]->timeBonus?->securityModifiers->lowSec,
            $scienceRigs[0]->timeBonus?->securityModifiers->nullSec,
        ]);
        $this->assertSame([1.0, 1.9, 2.1], [
            $scienceRigs[0]->costBonus?->securityModifiers->highSec,
            $scienceRigs[0]->costBonus?->securityModifiers->lowSec,
            $scienceRigs[0]->costBonus?->securityModifiers->nullSec,
        ]);
    }

    #[Group('issue-523')]
    public function testMSetInventionAcceleratorIiIsAnInventionRigOnly(): void
    {
        $this->insertScienceRig(self::M_SET_INVENTION_ACCELERATOR_II, timeBonus: -24, costBonus: 0, effects: [self::EFFECT_INVENTION_TIME]);

        $this->assertSame(
            ['Invention' => [24.0, null]],
            self::bonusesByActivity($this->reader()->scienceRigsOf(self::M_SET_INVENTION_ACCELERATOR_II)),
        );
    }

    #[Group('issue-523')]
    public function testCopyCostEffectWithAZeroCostAttributeGivesAZeroCostBonus(): void
    {
        $this->insertScienceRig(self::M_SET_BLUEPRINT_COPY_COST_OPTIMIZATION_II, timeBonus: 0, costBonus: 0, effects: [self::EFFECT_COPY_COST]);

        $this->assertSame(
            ['Copying' => [null, 0.0]],
            self::bonusesByActivity($this->reader()->scienceRigsOf(self::M_SET_BLUEPRINT_COPY_COST_OPTIMIZATION_II)),
        );
    }

    #[Group('issue-523')]
    public function testShipManufacturingRigIsNoScienceRig(): void
    {
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_ENG_RIG_TIME_BONUS, -24);
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_ENG_RIG_MAT_BONUS, -2.4);
        $this->insertAttribute(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, self::ATTRIBUTE_ENG_RIG_COST_BONUS, 0);
        $this->insertEffects(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II, [self::EFFECT_SHIP_MANUFACTURING_MATERIAL, self::EFFECT_SHIP_MANUFACTURING_TIME]);

        $this->assertSame([], $this->reader()->scienceRigsOf(self::XL_SET_SHIP_MANUFACTURING_EFFICIENCY_II));
    }

    #[Group('issue-523')]
    public function testUnknownRigIsNoScienceRig(): void
    {
        $this->assertSame([], $this->reader()->scienceRigsOf(self::UNKNOWN_RIG));
    }

    #[Group('issue-523')]
    public function testCopyTimeEffectWithoutItsTimeAttributeIsRefusedAsIncoherentSdeData(): void
    {
        $this->insertAttribute(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
        $this->insertAttribute(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
        $this->insertAttribute(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
        $this->insertEffects(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II, [self::EFFECT_COPY_TIME]);

        $this->expectException(\UnexpectedValueException::class);

        $this->reader()->scienceRigsOf(self::M_SET_BLUEPRINT_COPY_ACCELERATOR_II);
    }

    #[Group('issue-523')]
    public function testZeroMaterialAttributeOfALaboratoryRigIsNoManufacturingMaterialBonus(): void
    {
        $this->insertScienceRig(self::XL_SET_LABORATORY_OPTIMIZATION_II, timeBonus: -24, costBonus: -12, effects: [self::EFFECT_COPY_COST, self::EFFECT_COPY_TIME]);

        $this->assertNull($this->reader()->materialBonusOf(self::XL_SET_LABORATORY_OPTIMIZATION_II, ActivityKind::Manufacturing));
    }

    private static function bonusesByActivity(array $scienceRigs): array
    {
        $bonuses = [];
        foreach ($scienceRigs as $scienceRig) {
            self::assertInstanceOf(ScienceRig::class, $scienceRig);
            self::assertArrayNotHasKey($scienceRig->activity->name, $bonuses);
            $bonuses[$scienceRig->activity->name] = [$scienceRig->timeBonus?->reductionPercent, $scienceRig->costBonus?->reductionPercent];
        }
        ksort($bonuses);

        return $bonuses;
    }

    private function insertScienceRig(int $rigTypeId, int $timeBonus, int $costBonus, array $effects): void
    {
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_TIME_BONUS, $timeBonus);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_MAT_BONUS, 0);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_COST_BONUS, $costBonus);
        $this->insertEffects($rigTypeId, $effects);
    }

    private function insertEffects(int $typeId, array $effectIds): void
    {
        foreach ([self::EFFECT_RIG_SLOT, self::EFFECT_STRUCTURE_RIG_SECURITY, ...$effectIds] as $effectId) {
            $this->connection->insert('sde_dgm_type_effects', [
                'type_id' => $typeId,
                'effect_id' => $effectId,
                'is_default' => 'false',
            ]);
        }
    }

    private function reader(): SdeRigBonusReader
    {
        return self::getContainer()->get(SdeRigBonusReader::class);
    }

    /** Real layout of an M-Set manufacturing rig: both bonus attributes present, the unused one at 0. */
    private function insertManufacturingRig(int $rigTypeId, int|float $materialBonus, int|float $timeBonus): void
    {
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_HI_SEC_MODIFIER, 1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 2.1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_TIME_BONUS, $timeBonus);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_MAT_BONUS, $materialBonus);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_ENG_RIG_COST_BONUS, 0);
    }

    private function insertReactionMaterialRig(int $rigTypeId, int|float $materialBonus): void
    {
        $this->insertReactionRigSecurityModifiers($rigTypeId);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_REF_RIG_MAT_BONUS, $materialBonus);
    }

    /** No hiSecModifier row: the SDE has none for reaction rigs. */
    private function insertReactionRigSecurityModifiers(int $rigTypeId): void
    {
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 1.1);
    }

    private function insertThukkerRigSecurityModifiers(int $rigTypeId): void
    {
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_HI_SEC_MODIFIER, 0.1);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_LOW_SEC_MODIFIER, 1.9);
        $this->insertAttribute($rigTypeId, self::ATTRIBUTE_NULL_SEC_MODIFIER, 0.1);
    }

    /** Same split as SdeDogmaImporter: whole numbers in value_int, the others in value_float. */
    private function insertAttribute(int $typeId, int $attributeId, int|float $value): void
    {
        $this->connection->insert('sde_dgm_type_attributes', [
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'value_int' => \is_int($value) ? $value : null,
            'value_float' => \is_float($value) ? $value : null,
        ]);
    }
}
