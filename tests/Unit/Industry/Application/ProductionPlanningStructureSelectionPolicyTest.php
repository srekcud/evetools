<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanning::class)]
#[Group('issue-341')]
final class ProductionPlanningStructureSelectionPolicyTest extends TestCase
{
    private const int MATERIALS_AT_THE_WORKSHOP_STRUCTURE = 100_000;
    private const int MATERIALS_AT_THE_RIGGED_STRUCTURE_THREE_JUMPS_AWAY = 90_000;

    public static function selectionPolicyProvider(): iterable
    {
        yield 'saving of 150 000 above a minimum outside saving of 100 000 takes the rigged structure' => [true, 100_000.0, self::MATERIALS_AT_THE_RIGGED_STRUCTURE_THREE_JUMPS_AWAY];
        yield 'saving of 150 000 equal to the minimum outside saving takes the rigged structure' => [true, 150_000.0, self::MATERIALS_AT_THE_RIGGED_STRUCTURE_THREE_JUMPS_AWAY];
        yield 'saving of 150 000 below a minimum outside saving of 500 000 keeps the workshop structure' => [true, 500_000.0, self::MATERIALS_AT_THE_WORKSHOP_STRUCTURE];
        yield 'policy refusing a structure outside the favorite system keeps the workshop structure' => [false, 0.0, self::MATERIALS_AT_THE_WORKSHOP_STRUCTURE];
    }

    #[DataProvider('selectionPolicyProvider')]
    public function testPlanTakesTheMaterialsOfTheStructureChosenByTheSelectionPolicyOfTheRequest(bool $acceptsOutsideFavoriteSystem, float $minimumOutsideSaving, int $expectedMaterialQuantity): void
    {
        $planning = new ProductionPlanning(
            new InMemoryBlueprintCatalog(StructureTotalCostExample::recipe()),
            StructureTotalCostExample::costIndices(),
            new InMemoryRigCategories(manufacturing: [StructureTotalCostExample::PRODUCT_TYPE_ID => StructureTotalCostExample::PRODUCT_CATEGORY]),
            StructureTotalCostExample::comparator(),
        );

        $plan = $planning->planOf(new ProductionPlanRequest(
            [new PlanTarget(
                StructureTotalCostExample::PRODUCT_TYPE_ID,
                new Runs(StructureTotalCostExample::REFERENCE_RUNS),
                new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            )],
            [StructureTotalCostExample::workshopStructureWithNeutralRig(), StructureTotalCostExample::structureC()],
            new FavoriteSystems(StructureTotalCostExample::WORKSHOP_SYSTEM_ID, null),
            selectionPolicy: new StructureSelectionPolicy($acceptsOutsideFavoriteSystem, new Isk($minimumOutsideSaving)),
        ));

        self::assertSame(
            [StructureTotalCostExample::MATERIAL_TYPE_ID => $expectedMaterialQuantity],
            array_map(static fn (Quantity $quantity): int => $quantity->value, $plan->leaves),
        );
    }
}
