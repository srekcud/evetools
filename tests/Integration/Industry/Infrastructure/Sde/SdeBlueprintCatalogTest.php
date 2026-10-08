<?php

declare(strict_types=1);

namespace App\Tests\Integration\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Recipe;
use App\Industry\Infrastructure\Sde\SdeBlueprintCatalog;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Connection;

/**
 * Adapter of the BlueprintCatalog port over the SDE tables (ADR-0009, spec R1-R3):
 * sde_industry_activity_products, sde_industry_activity_materials, sde_industry_activities, sde_industry_blueprints.
 *
 * Fixtures: SDE of the dev database on 2026-10-07 (Nitrogen Fuel Block, Fernite Carbide), plus synthetic type ids
 * in the 990_000_000 range for the cases the real SDE does not hold.
 */
final class SdeBlueprintCatalogTest extends IntegrationTestCase
{
    private const int ACTIVITY_MANUFACTURING = 1;
    private const int ACTIVITY_COPYING = 5;
    private const int ACTIVITY_INVENTION = 8;
    private const int ACTIVITY_REACTION = 11;

    private const int NITROGEN_FUEL_BLOCK = 4051;
    private const int NITROGEN_FUEL_BLOCK_BLUEPRINT = 4314;
    private const int ENRICHED_URANIUM = 44;
    private const int OXYGEN = 3683;
    private const int MECHANICAL_PARTS = 3689;
    private const int COOLANT = 9832;
    private const int ROBOTICS = 9848;
    private const int HEAVY_WATER = 16272;
    private const int LIQUID_OZONE = 16273;
    private const int STRONTIUM_CLATHRATES = 16275;
    private const int NITROGEN_ISOTOPES = 17888;

    private const int FERNITE_CARBIDE = 16673;
    private const int FERNITE_CARBIDE_REACTION_FORMULA = 46206;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const int FERNITE_ALLOY = 16656;
    private const int CERAMIC_POWDER = 16660;

    private const int SYNTHETIC_PRODUCT = 990_000_001;
    private const int SYNTHETIC_MANUFACTURING_BLUEPRINT = 990_000_002;
    private const int SYNTHETIC_REACTION_FORMULA = 990_000_003;
    private const int SYNTHETIC_TEST_BLUEPRINT = 990_000_004;
    private const int SYNTHETIC_T2_BLUEPRINT = 990_000_005;
    private const int SYNTHETIC_BLUEPRINT_WITHOUT_LIMIT = 990_000_006;
    private const int SYNTHETIC_MATERIAL = 990_000_010;
    private const int SYNTHETIC_OTHER_MATERIAL = 990_000_011;
    private const int DATACORE = 20_418;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testSdeBlueprintCatalogIsTheBlueprintCatalogOfTheApplication(): void
    {
        $this->assertInstanceOf(SdeBlueprintCatalog::class, self::getContainer()->get(BlueprintCatalog::class));
    }

    public function testManufacturingRecipeOfNitrogenFuelBlockIsReadFromTheSde(): void
    {
        $this->insertNitrogenFuelBlockBlueprint();

        $recipe = $this->catalog()->recipeFor(self::NITROGEN_FUEL_BLOCK);

        $this->assertNotNull($recipe);
        $this->assertSame(self::NITROGEN_FUEL_BLOCK, $recipe->productTypeId);
        $this->assertSame(ActivityKind::Manufacturing, $recipe->activity);
        $this->assertSame(40, $recipe->outputPerRun->value);
        $this->assertSame(200, $recipe->maxProductionLimit->value);
        $this->assertSame(900, $recipe->baseTimeSeconds);
        $this->assertSame([
            self::ENRICHED_URANIUM => 4,
            self::OXYGEN => 22,
            self::MECHANICAL_PARTS => 4,
            self::COOLANT => 9,
            self::ROBOTICS => 1,
            self::HEAVY_WATER => 170,
            self::LIQUID_OZONE => 350,
            self::STRONTIUM_CLATHRATES => 20,
            self::NITROGEN_ISOTOPES => 450,
        ], $this->baseQuantitiesPerRunByMaterial($recipe));
    }

    public function testReactionRecipeOfFerniteCarbideIsReadFromTheSde(): void
    {
        $this->insertProducer(self::FERNITE_CARBIDE_REACTION_FORMULA, self::ACTIVITY_REACTION, self::FERNITE_CARBIDE, 10_000, 10_800, 1_000, [
            self::HYDROGEN_FUEL_BLOCK => 5,
            self::FERNITE_ALLOY => 100,
            self::CERAMIC_POWDER => 100,
        ]);

        $recipe = $this->catalog()->recipeFor(self::FERNITE_CARBIDE);

        $this->assertNotNull($recipe);
        $this->assertSame(self::FERNITE_CARBIDE, $recipe->productTypeId);
        $this->assertSame(ActivityKind::Reaction, $recipe->activity);
        $this->assertSame(10_000, $recipe->outputPerRun->value);
        $this->assertSame(1_000, $recipe->maxProductionLimit->value);
        $this->assertSame(10_800, $recipe->baseTimeSeconds);
        $this->assertSame([
            self::HYDROGEN_FUEL_BLOCK => 5,
            self::FERNITE_ALLOY => 100,
            self::CERAMIC_POWDER => 100,
        ], $this->baseQuantitiesPerRunByMaterial($recipe));
    }

    public function testRawMaterialHasNoRecipe(): void
    {
        $this->insertNitrogenFuelBlockBlueprint();

        $this->assertNull($this->catalog()->recipeFor(self::NITROGEN_ISOTOPES));
    }

    public function testMaterialsOfTheOtherActivitiesOfTheBlueprintAreNotPartOfTheRecipe(): void
    {
        $this->insertNitrogenFuelBlockBlueprint();
        $this->insertActivity(self::NITROGEN_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_COPYING, 4_800, []);
        $this->insertActivity(self::NITROGEN_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_INVENTION, 6_300, [self::DATACORE => 2]);

        $recipe = $this->catalog()->recipeFor(self::NITROGEN_FUEL_BLOCK);

        $this->assertNotNull($recipe);
        $this->assertArrayNotHasKey(self::DATACORE, $this->baseQuantitiesPerRunByMaterial($recipe));
        $this->assertCount(9, $recipe->materials);
        $this->assertSame(900, $recipe->baseTimeSeconds);
    }

    public function testProductOnlyObtainedByInventionHasNoRecipe(): void
    {
        $this->insertNitrogenFuelBlockBlueprint();
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => self::NITROGEN_FUEL_BLOCK_BLUEPRINT,
            'activity_id' => self::ACTIVITY_INVENTION,
            'product_type_id' => self::SYNTHETIC_T2_BLUEPRINT,
            'quantity' => 1,
        ]);

        $this->assertNull($this->catalog()->recipeFor(self::SYNTHETIC_T2_BLUEPRINT));
    }

    /**
     * Same rule as IndustryTreeService::findProducerFor(): manufacturing first, reaction as a fallback.
     */
    public function testManufacturingIsPreferredWhenAProductHasBothABlueprintAndAReactionFormula(): void
    {
        $this->insertProducer(self::SYNTHETIC_REACTION_FORMULA, self::ACTIVITY_REACTION, self::SYNTHETIC_PRODUCT, 200, 3_600, 1_000, [
            self::SYNTHETIC_OTHER_MATERIAL => 100,
        ]);
        $this->insertProducer(self::SYNTHETIC_MANUFACTURING_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_PRODUCT, 10, 600, 50, [
            self::SYNTHETIC_MATERIAL => 7,
        ]);

        $recipe = $this->catalog()->recipeFor(self::SYNTHETIC_PRODUCT);

        $this->assertNotNull($recipe);
        $this->assertSame(ActivityKind::Manufacturing, $recipe->activity);
        $this->assertSame(10, $recipe->outputPerRun->value);
        $this->assertSame(50, $recipe->maxProductionLimit->value);
        $this->assertSame(600, $recipe->baseTimeSeconds);
        $this->assertSame([self::SYNTHETIC_MATERIAL => 7], $this->baseQuantitiesPerRunByMaterial($recipe));
    }

    /**
     * Same rule as IndustryActivityProductRepository::findBlueprintForProduct(): the SDE holds test blueprints
     * next to the real one; the blueprint with the highest output per run wins.
     */
    public function testBlueprintWithTheHighestOutputPerRunWinsWhenSeveralBlueprintsBuildTheSameProduct(): void
    {
        $this->insertProducer(self::SYNTHETIC_TEST_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_PRODUCT, 1, 60, 1, [
            self::SYNTHETIC_OTHER_MATERIAL => 1,
        ]);
        $this->insertProducer(self::SYNTHETIC_MANUFACTURING_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::SYNTHETIC_PRODUCT, 100, 600, 300, [
            self::SYNTHETIC_MATERIAL => 7,
        ]);

        $recipe = $this->catalog()->recipeFor(self::SYNTHETIC_PRODUCT);

        $this->assertNotNull($recipe);
        $this->assertSame(100, $recipe->outputPerRun->value);
        $this->assertSame(300, $recipe->maxProductionLimit->value);
        $this->assertSame([self::SYNTHETIC_MATERIAL => 7], $this->baseQuantitiesPerRunByMaterial($recipe));
    }

    /**
     * A missing max production limit must not become a plausible default (CLAUDE.md, no `?? 0` on business data).
     */
    public function testBlueprintWithoutMaxProductionLimitIsAnExplicitError(): void
    {
        $this->insertActivity(self::SYNTHETIC_BLUEPRINT_WITHOUT_LIMIT, self::ACTIVITY_MANUFACTURING, 600, [self::SYNTHETIC_MATERIAL => 7]);
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => self::SYNTHETIC_BLUEPRINT_WITHOUT_LIMIT,
            'activity_id' => self::ACTIVITY_MANUFACTURING,
            'product_type_id' => self::SYNTHETIC_PRODUCT,
            'quantity' => 10,
        ]);
        $catalog = $this->catalog();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/'.self::SYNTHETIC_BLUEPRINT_WITHOUT_LIMIT.'/');

        $catalog->recipeFor(self::SYNTHETIC_PRODUCT);
    }

    private function catalog(): SdeBlueprintCatalog
    {
        return self::getContainer()->get(SdeBlueprintCatalog::class);
    }

    private function insertNitrogenFuelBlockBlueprint(): void
    {
        $this->insertProducer(self::NITROGEN_FUEL_BLOCK_BLUEPRINT, self::ACTIVITY_MANUFACTURING, self::NITROGEN_FUEL_BLOCK, 40, 900, 200, [
            self::ENRICHED_URANIUM => 4,
            self::OXYGEN => 22,
            self::MECHANICAL_PARTS => 4,
            self::COOLANT => 9,
            self::ROBOTICS => 1,
            self::HEAVY_WATER => 170,
            self::LIQUID_OZONE => 350,
            self::STRONTIUM_CLATHRATES => 20,
            self::NITROGEN_ISOTOPES => 450,
        ]);
    }

    /**
     * @param array<int, int> $baseQuantityPerRunByMaterial
     */
    private function insertProducer(
        int $blueprintTypeId,
        int $activityId,
        int $productTypeId,
        int $outputPerRun,
        int $baseTimeSeconds,
        int $maxProductionLimit,
        array $baseQuantityPerRunByMaterial,
    ): void {
        $this->connection->insert('sde_industry_blueprints', [
            'type_id' => $blueprintTypeId,
            'max_production_limit' => $maxProductionLimit,
        ]);
        $this->insertActivity($blueprintTypeId, $activityId, $baseTimeSeconds, $baseQuantityPerRunByMaterial);
        $this->connection->insert('sde_industry_activity_products', [
            'type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'product_type_id' => $productTypeId,
            'quantity' => $outputPerRun,
        ]);
    }

    /**
     * @param array<int, int> $baseQuantityPerRunByMaterial
     */
    private function insertActivity(int $blueprintTypeId, int $activityId, int $baseTimeSeconds, array $baseQuantityPerRunByMaterial): void
    {
        $this->connection->insert('sde_industry_activities', [
            'type_id' => $blueprintTypeId,
            'activity_id' => $activityId,
            'time' => $baseTimeSeconds,
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
     * @return array<int, int> base quantity per run by material typeId, in typeId order
     */
    private function baseQuantitiesPerRunByMaterial(Recipe $recipe): array
    {
        $baseQuantities = [];
        foreach ($recipe->materials as $material) {
            $baseQuantities[$material->typeId] = $material->baseQuantityPerRun->value;
        }
        ksort($baseQuantities);

        return $baseQuantities;
    }
}
