<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureMaterialModifiers;
use App\Industry\Application\StructureSelector;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\PlanRequest;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JobMaterialModifiers of the Application: the structure of each job is chosen by the StructureSelector (D3),
 * then mod_matériaux = role bonus × rig (security included) is composed with the Domain classes (spec R2, R4).
 *
 * Inputs: the category of each job product (IndustryRigCategory, null when the product has none) and the structures
 * assigned to steps, by product typeId. Configuration of the goldens §4.2 / §4.3: Raitaru nullsec with
 * M-Set Ammunition ME II (2.4 %), Tatara nullsec with L-Set Reactor Efficiency II (2.4 %).
 */
#[CoversClass(StructureMaterialModifiers::class)]
final class StructureMaterialModifiersTest extends TestCase
{
    private const int HAIL_L_TYPE_ID = 12779;
    private const int RAM_AMMUNITION_TECH_TYPE_ID = 11476;
    private const int FERNITE_CARBIDE_TYPE_ID = 16673;
    private const int CERAMIC_POWDER_TYPE_ID = 16660;
    private const int HYDROGEN_FUEL_BLOCK_TYPE_ID = 4246;

    private const string GOLDEN_DIRECTORY = __DIR__.'/../../../Fixtures/Golden';

    #[DataProvider('goldenConfigurationProvider')]
    public function testJobModifierIsTheRoleBonusTimesTheRigOfTheChosenStructure(string $productName, float $expectedMultiplier): void
    {
        $modifiers = self::goldenModifiers();

        $multiplier = $modifiers->forJob(self::recipeOf($productName));

        $this->assertEqualsWithDelta($expectedMultiplier, $multiplier->value, 1e-12);
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function goldenConfigurationProvider(): iterable
    {
        yield '4.2 Hail L root: Raitaru × ammunition T2 rig nullsec, 0.99 × 0.9496' => ['Hail L', 0.940104];
        yield '4.2 R.A.M.: no rig category, Raitaru role bonus only' => ['R.A.M.- Ammunition Tech', 0.99];
        yield '4.3 Fernite Carbide: Tatara × reaction T2 rig nullsec, no role bonus' => ['Fernite Carbide', 0.9736];
        yield '4.3 Ceramic Powder: Tatara × reaction T2 rig nullsec' => ['Ceramic Powder', 0.9736];
        yield '4.3 Hydrogen Fuel Block: Raitaru role bonus only' => ['Hydrogen Fuel Block', 0.99];
    }

    public function testStructureAssignedToTheStepOfAProductIsUsed(): void
    {
        $assigned = ProductionStructures::raitaru('assigned-lowsec', SecurityClass::LowSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.0, 'ammunition'));
        $modifiers = new StructureMaterialModifiers(
            self::goldenSelector(),
            self::goldenProductCategories(),
            [self::HAIL_L_TYPE_ID => $assigned],
        );

        $this->assertEqualsWithDelta(0.95238, $modifiers->forJob(self::recipeOf('Hail L'))->value, 1e-12);
        $this->assertEqualsWithDelta(0.99, $modifiers->forJob(self::recipeOf('R.A.M.- Ammunition Tech'))->value, 1e-12);
    }

    public function testRoleBonusOfAnEngineeringComplexAssignedToAReactionIsNotApplied(): void
    {
        // R2: mod_structure = 1 on a reaction job, whatever the structure.
        $modifiers = new StructureMaterialModifiers(
            self::goldenSelector(),
            self::goldenProductCategories(),
            [self::FERNITE_CARBIDE_TYPE_ID => ProductionStructures::raitaru('raitaru-assigned-to-a-reaction', SecurityClass::NullSec)],
        );

        $this->assertEqualsWithDelta(1.0, $modifiers->forJob(self::recipeOf('Fernite Carbide'))->value, 1e-12);
    }

    public function testNoConfiguredStructureGivesNeutralModifiers(): void
    {
        $modifiers = new StructureMaterialModifiers(
            new StructureSelector([], FavoriteSystems::none()),
            self::goldenProductCategories(),
        );

        $this->assertEqualsWithDelta(1.0, $modifiers->forJob(self::recipeOf('Hail L'))->value, 1e-12);
        $this->assertEqualsWithDelta(1.0, $modifiers->forJob(self::recipeOf('Fernite Carbide'))->value, 1e-12);
    }

    public function testProductMissingFromTheCategoriesIsAnExplicitError(): void
    {
        // "No rig category" is an explicit null; a product absent from the input is missing data, not "no bonus".
        $modifiers = new StructureMaterialModifiers(self::goldenSelector(), [self::HAIL_L_TYPE_ID => 'ammunition']);

        $this->expectException(\DomainException::class);

        $modifiers->forJob(self::recipeOf('Fernite Carbide'));
    }

    public function testFerniteCarbidePlanWithTheChosenStructuresBuysTheGoldenLeaves(): void
    {
        $golden = self::loadGolden('fernite-carbide-10runs-tatara-nullsec.json');
        $planner = new ProductionPlanner(InMemoryBlueprintCatalog::fromSdeFixture());

        $plan = $planner->plan(new PlanRequest(
            targets: [new PlanTarget(self::FERNITE_CARBIDE_TYPE_ID, new Runs(10), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            materialModifiers: self::goldenModifiers(),
        ));

        $expectedLeaves = [];
        foreach ($golden['expected']['leaves'] as $leaf) {
            $expectedLeaves[$leaf['typeId']] = $leaf['quantity'];
        }
        ksort($expectedLeaves);
        $leaves = array_map(static fn (Quantity $quantity): int => $quantity->value, $plan->leaves);
        ksort($leaves);
        $this->assertSame($expectedLeaves, $leaves);
    }

    private static function goldenModifiers(): StructureMaterialModifiers
    {
        return new StructureMaterialModifiers(self::goldenSelector(), self::goldenProductCategories());
    }

    private static function goldenSelector(): StructureSelector
    {
        return new StructureSelector([
            ProductionStructures::raitaru('raitaru-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::manufacturingRig(2.4, 'ammunition')),
            ProductionStructures::tatara('tatara-nullsec', SecurityClass::NullSec, null, '2026-01-01 00:00:00', ProductionStructures::reactionRig(2.4, 'composite_reaction', 'hybrid_reaction', 'biochemical_reaction')),
        ], FavoriteSystems::none());
    }

    /**
     * @return array<int, ?string> category of each job product, null when no rig targets its group
     */
    private static function goldenProductCategories(): array
    {
        return [
            self::HAIL_L_TYPE_ID => 'ammunition',
            self::RAM_AMMUNITION_TECH_TYPE_ID => null,
            self::FERNITE_CARBIDE_TYPE_ID => 'composite_reaction',
            self::CERAMIC_POWDER_TYPE_ID => 'composite_reaction',
            InMemoryBlueprintCatalog::typeIdOf('Fernite Alloy') => 'composite_reaction',
            self::HYDROGEN_FUEL_BLOCK_TYPE_ID => null,
        ];
    }

    private static function recipeOf(string $productName): Recipe
    {
        $recipe = InMemoryBlueprintCatalog::fromSdeFixture()->recipeFor(InMemoryBlueprintCatalog::typeIdOf($productName));
        self::assertNotNull($recipe, "No recipe for {$productName} in the SDE fixture");

        return $recipe;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadGolden(string $fileName): array
    {
        $json = file_get_contents(self::GOLDEN_DIRECTORY.'/'.$fileName);
        self::assertIsString($json, "Golden fixture {$fileName} is missing");

        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
