<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\RigBonus;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

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
