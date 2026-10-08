<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * Spec R4 (D10): structure role bonuses are read from the dogma attributes of the structure type, already stored
 * as multipliers (0.99, 0.85, 0.75…): strEngMatBonus, strEngTimeBonus, strEngCostBonus, strReactionTimeMultiplier.
 * An absent attribute means "no role bonus": null, never a default.
 * Values: SDE of the dev database on 2026-10-07 (spec R4 reference table).
 */
final class SdeStructureBonusReaderTest extends IntegrationTestCase
{
    private const int ATTRIBUTE_STR_ENG_MAT_BONUS = 2600;
    private const int ATTRIBUTE_STR_ENG_COST_BONUS = 2601;
    private const int ATTRIBUTE_STR_ENG_TIME_BONUS = 2602;
    private const int ATTRIBUTE_STR_REACTION_TIME_MULTIPLIER = 2721;

    private const int RAITARU = 35825;
    private const int AZBEL = 35826;
    private const int SOTIYO = 35827;
    private const int ATHANOR = 35835;
    private const int TATARA = 35836;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->insertEngineeringComplex(self::RAITARU, materialMultiplier: 0.99, costMultiplier: 0.97, timeMultiplier: 0.85);
        $this->insertEngineeringComplex(self::AZBEL, materialMultiplier: 0.99, costMultiplier: 0.96, timeMultiplier: 0.8);
        $this->insertEngineeringComplex(self::SOTIYO, materialMultiplier: 0.99, costMultiplier: 0.95, timeMultiplier: 0.7);
        $this->insertAttribute(self::TATARA, self::ATTRIBUTE_STR_REACTION_TIME_MULTIPLIER, 0.75);
    }

    public function testMaterialRoleBonusOfAnEngineeringComplexIsReadAsAMultiplier(): void
    {
        $this->assertSame(0.99, $this->reader()->materialMultiplierOf(self::RAITARU)?->value);
    }

    public function testCostRoleBonusOfEachEngineeringComplexIsReadAsAMultiplier(): void
    {
        $this->assertSame(0.97, $this->reader()->costMultiplierOf(self::RAITARU)?->value);
        $this->assertSame(0.96, $this->reader()->costMultiplierOf(self::AZBEL)?->value);
        $this->assertSame(0.95, $this->reader()->costMultiplierOf(self::SOTIYO)?->value);
    }

    public function testManufacturingTimeRoleBonusOfEachEngineeringComplexIsReadAsAMultiplier(): void
    {
        $this->assertSame(0.85, $this->reader()->timeMultiplierOf(self::RAITARU, ActivityKind::Manufacturing)?->value);
        $this->assertSame(0.8, $this->reader()->timeMultiplierOf(self::AZBEL, ActivityKind::Manufacturing)?->value);
        $this->assertSame(0.7, $this->reader()->timeMultiplierOf(self::SOTIYO, ActivityKind::Manufacturing)?->value);
    }

    public function testReactionTimeRoleBonusOfTataraIsReadFromStrReactionTimeMultiplier(): void
    {
        $this->assertSame(0.75, $this->reader()->timeMultiplierOf(self::TATARA, ActivityKind::Reaction)?->value);
    }

    /**
     * Issue #71: the Athanor has no reaction time role bonus in the SDE.
     */
    public function testAthanorHasNoReactionTimeRoleBonus(): void
    {
        $this->assertNull($this->reader()->timeMultiplierOf(self::ATHANOR, ActivityKind::Reaction));
    }

    public function testTataraHasNoMaterialNorCostRoleBonus(): void
    {
        $this->assertNull($this->reader()->materialMultiplierOf(self::TATARA));
        $this->assertNull($this->reader()->costMultiplierOf(self::TATARA));
    }

    public function testManufacturingTimeRoleBonusIsNotTakenAsAReactionTimeRoleBonus(): void
    {
        $this->assertNull($this->reader()->timeMultiplierOf(self::RAITARU, ActivityKind::Reaction));
    }

    public function testReactionTimeRoleBonusIsNotTakenAsAManufacturingTimeRoleBonus(): void
    {
        $this->assertNull($this->reader()->timeMultiplierOf(self::TATARA, ActivityKind::Manufacturing));
    }

    private function reader(): SdeStructureBonusReader
    {
        return self::getContainer()->get(SdeStructureBonusReader::class);
    }

    private function insertEngineeringComplex(int $structureTypeId, float $materialMultiplier, float $costMultiplier, float $timeMultiplier): void
    {
        $this->insertAttribute($structureTypeId, self::ATTRIBUTE_STR_ENG_MAT_BONUS, $materialMultiplier);
        $this->insertAttribute($structureTypeId, self::ATTRIBUTE_STR_ENG_COST_BONUS, $costMultiplier);
        $this->insertAttribute($structureTypeId, self::ATTRIBUTE_STR_ENG_TIME_BONUS, $timeMultiplier);
    }

    private function insertAttribute(int $typeId, int $attributeId, float $value): void
    {
        $this->connection->insert('sde_dgm_type_attributes', [
            'type_id' => $typeId,
            'attribute_id' => $attributeId,
            'value_int' => null,
            'value_float' => $value,
        ]);
    }
}
