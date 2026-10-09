<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionRecipe;
use App\Industry\Infrastructure\Sde\SdeInventionCatalog;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * Adapter of the InventionCatalog port over the SDE tables (ADR-0009, spec R8): the T2 product is manufactured
 * by a T2 blueprint, which is the invention product (activity 8) of a T1 blueprint, itself manufacturing the T1 product.
 *
 * Fixtures: SDE of the dev database on 2026-10-08 (Hobgoblin I / II), plus synthetic type ids
 * in the 990_000_000 range for the cases the real SDE does not hold.
 */
final class SdeInventionCatalogTest extends IntegrationTestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_COPYING = 5;
    private const int ACTIVITY_INVENTION = 8;
    private const int ACTIVITY_REACTION = 11;

    private const int HOBGOBLIN_I = 2454;
    private const int HOBGOBLIN_I_BLUEPRINT = 2455;
    private const int HOBGOBLIN_II = 2456;
    private const int HOBGOBLIN_II_BLUEPRINT = 2457;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int ISOGEN = 37;
    private const int NOCXIUM = 38;
    private const int ROBOTICS = 9848;
    private const int MORPHITE = 11399;
    private const int DATA_SHEETS = 3812;
    private const int R_DB_CREODRON = 11466;
    private const int DATACORE_ELECTRONIC_ENGINEERING = 20418;
    private const int DATACORE_GRAVITON_PHYSICS = 20419;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46206;
    private const int FERNITE_ALLOY = 16656;

    private const int SYNTHETIC_T1_PRODUCT = 990_000_101;
    private const int SYNTHETIC_T1_BLUEPRINT = 990_000_102;
    private const int SYNTHETIC_T2_PRODUCT = 990_000_103;
    private const int SYNTHETIC_T2_BLUEPRINT = 990_000_104;
    private const int SYNTHETIC_MATERIAL = 990_000_110;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testSdeInventionCatalogIsTheInventionCatalogOfTheApplication(): void
    {
        $this->assertInstanceOf(SdeInventionCatalog::class, self::getContainer()->get(InventionCatalog::class));
    }

    public function testInventionOfHobgoblinIIIsReadFromTheSde(): void
    {
        $this->insertHobgoblinBlueprints();

        $invention = $this->catalog()->inventionOf(self::HOBGOBLIN_II);

        $this->assertNotNull($invention);
        $this->assertSame(self::HOBGOBLIN_I_BLUEPRINT, $invention->t1BlueprintTypeId);
        $this->assertSame(self::HOBGOBLIN_I, $invention->t1ProductTypeId);
        $this->assertSame(0.34, $invention->baseProbability);
        $this->assertSame(10, $invention->baseRuns->value);
        $this->assertSame([
            self::DATACORE_ELECTRONIC_ENGINEERING => 1,
            self::DATACORE_GRAVITON_PHYSICS => 1,
        ], $this->datacoreQuantitiesPerAttempt($invention));
    }

    /**
     * #74: the invention chain exists but its success probability is unknown, never 0.
     */
    public function testInventionWithoutProbabilityInTheSdeHasAnUnknownBaseProbability(): void
    {
        $this->insertBlueprint(self::SYNTHETIC_T1_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_T1_PRODUCT, 1, [self::SYNTHETIC_MATERIAL => 10]);
        $this->insertBlueprint(self::SYNTHETIC_T2_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_T2_PRODUCT, 1, [self::SYNTHETIC_T1_PRODUCT => 1]);
        $this->insertActivity(self::SYNTHETIC_T1_BLUEPRINT, self::ACTIVITY_INVENTION, [self::DATACORE_ELECTRONIC_ENGINEERING => 2]);
        $this->insertActivityProduct(self::SYNTHETIC_T1_BLUEPRINT, self::ACTIVITY_INVENTION, self::SYNTHETIC_T2_BLUEPRINT, 1, null);

        $invention = $this->catalog()->inventionOf(self::SYNTHETIC_T2_PRODUCT);

        $this->assertNotNull($invention);
        $this->assertNull($invention->baseProbability);
        $this->assertSame(self::SYNTHETIC_T1_BLUEPRINT, $invention->t1BlueprintTypeId);
        $this->assertSame(self::SYNTHETIC_T1_PRODUCT, $invention->t1ProductTypeId);
        $this->assertSame(1, $invention->baseRuns->value);
        $this->assertSame([self::DATACORE_ELECTRONIC_ENGINEERING => 2], $this->datacoreQuantitiesPerAttempt($invention));
    }

    public function testT1ProductIsNotInvented(): void
    {
        $this->insertHobgoblinBlueprints();

        $this->assertNull($this->catalog()->inventionOf(self::HOBGOBLIN_I));
    }

    public function testReactionProductIsNotInvented(): void
    {
        $this->insertBlueprint(self::FERNITE_CARBIDE_REACTION_FORMULA, self::ACTIVITY_REACTION, self::FERNITE_CARBIDE, 10_000, [self::FERNITE_ALLOY => 100]);

        $this->assertNull($this->catalog()->inventionOf(self::FERNITE_CARBIDE));
    }

    public function testMaterialWithoutBlueprintIsNotInvented(): void
    {
        $this->insertHobgoblinBlueprints();

        $this->assertNull($this->catalog()->inventionOf(self::TRITANIUM));
    }

    /**
     * A T1 product that cannot be named must not be guessed (CLAUDE.md, no plausible default on business data).
     */
    public function testT1BlueprintWhichManufacturesNothingIsAnExplicitError(): void
    {
        $this->insertBlueprint(self::SYNTHETIC_T2_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_T2_PRODUCT, 1, [self::SYNTHETIC_MATERIAL => 1]);
        $this->insertActivity(self::SYNTHETIC_T1_BLUEPRINT, self::ACTIVITY_INVENTION, [self::DATACORE_ELECTRONIC_ENGINEERING => 2]);
        $this->insertActivityProduct(self::SYNTHETIC_T1_BLUEPRINT, self::ACTIVITY_INVENTION, self::SYNTHETIC_T2_BLUEPRINT, 1, 0.3);
        $catalog = $this->catalog();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/'.self::SYNTHETIC_T1_BLUEPRINT.'/');

        $catalog->inventionOf(self::SYNTHETIC_T2_PRODUCT);
    }

    private function catalog(): SdeInventionCatalog
    {
        return self::getContainer()->get(SdeInventionCatalog::class);
    }

    /**
     * Rows of the dev SDE for both blueprints, every activity included so that only the invention ones are read.
     */
    private function insertHobgoblinBlueprints(): void
    {
        $this->insertBlueprint(self::HOBGOBLIN_I_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HOBGOBLIN_I, 1, [
            self::TRITANIUM => 509,
            self::PYERITE => 7,
            self::ISOGEN => 4,
            self::NOCXIUM => 2,
        ]);
        $this->insertActivity(self::HOBGOBLIN_I_BLUEPRINT, self::ACTIVITY_COPYING, []);
        $this->insertActivity(self::HOBGOBLIN_I_BLUEPRINT, self::ACTIVITY_INVENTION, [
            self::DATACORE_ELECTRONIC_ENGINEERING => 1,
            self::DATACORE_GRAVITON_PHYSICS => 1,
        ]);
        $this->insertActivityProduct(self::HOBGOBLIN_I_BLUEPRINT, self::ACTIVITY_INVENTION, self::HOBGOBLIN_II_BLUEPRINT, 10, 0.34);

        $this->insertBlueprint(self::HOBGOBLIN_II_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::HOBGOBLIN_II, 1, [
            self::HOBGOBLIN_I => 1,
            self::ROBOTICS => 1,
            self::MORPHITE => 1,
        ]);
        $this->insertActivity(self::HOBGOBLIN_II_BLUEPRINT, self::ACTIVITY_COPYING, [
            self::DATA_SHEETS => 10,
            self::R_DB_CREODRON => 1,
        ]);
    }

    /**
     * @param array<int, int> $baseQuantityPerRunByMaterial
     */
    private function insertBlueprint(int $blueprintTypeId, int $activityId, int $productTypeId, int $outputPerRun, array $baseQuantityPerRunByMaterial): void
    {
        $this->connection->insert('sde_industry_blueprints', [
            'type_id' => $blueprintTypeId,
            'max_production_limit' => 10,
        ]);
        $this->insertActivity($blueprintTypeId, $activityId, $baseQuantityPerRunByMaterial);
        $this->insertActivityProduct($blueprintTypeId, $activityId, $productTypeId, $outputPerRun, null);
    }

    private function insertActivityProduct(int $blueprintTypeId, int $activityId, int $productTypeId, int $quantity, ?float $probability): void
    {
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'product_type_id' => $productTypeId,
            'quantity' => $quantity,
            'probability' => $probability,
        ]);
    }

    /**
     * @param array<int, int> $baseQuantityPerRunByMaterial
     */
    private function insertActivity(int $blueprintTypeId, int $activityId, array $baseQuantityPerRunByMaterial): void
    {
        $this->connection->insert('sde_industry_activities', [
            'type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'time' => 600,
        ]);
        foreach ($baseQuantityPerRunByMaterial as $materialTypeId => $baseQuantityPerRun) {
            $this->connection->insert('sde_industry_activity_materials', [
                'type_id' => $blueprintTypeId,
                'activity_id' => $activityId,
                'material_type_id' => $materialTypeId,
                'quantity' => $baseQuantityPerRun,
            ]);
        }
    }

    /**
     * @return array<int, int> quantity of each datacore consumed by one invention attempt, in typeId order
     */
    private function datacoreQuantitiesPerAttempt(InventionRecipe $invention): array
    {
        $quantities = [];
        foreach ($invention->datacores as $datacore) {
            $quantities[$datacore->typeId] = $datacore->baseQuantityPerRun->value;
        }
        ksort($quantities);

        return $quantities;
    }
}
