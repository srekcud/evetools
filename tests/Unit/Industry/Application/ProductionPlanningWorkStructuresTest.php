<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\WorkType;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemoryShipProducts;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanning::class)]
#[Group('issue-1285')]
final class ProductionPlanningWorkStructuresTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int MORPHITE = Example::MATERIAL_TYPE_ID;
    private const int FERNITE_COMPOSITE = 16670;
    private const int RAM_STARSHIP_TECH = 11478;
    private const int ARBITRATOR = 12006;

    public function testWithoutWorkStructuresTheStructureOfEveryJobAndOfTheCopyIsChosenAutomatically(): void
    {
        $planned = $this->planWith([]);

        $this->assertSame(
            [
                ['C', StructureChoiceStatus::BestConfigured, 20_250],
                ['A', StructureChoiceStatus::BestConfigured, null],
                ['C-refinery', StructureChoiceStatus::BestConfigured, null],
                ['C', StructureChoiceStatus::BestConfigured, null],
            ],
            [
                $this->componentOf($planned),
                $this->finalProductOf($planned),
                $this->reactionOf($planned),
                $this->copyOf($planned),
            ],
        );
    }

    public function testWorkStructureOfATypeIsAssignedToItsJobsWithTheMaterialsOfThatStructure(): void
    {
        $planned = $this->planWith([
            WorkType::Components->value => Example::workshopStructureWithoutRig(),
            WorkType::FinalProducts->value => Example::structureC(),
            WorkType::Reaction->value => Example::workshopRefinery(),
        ]);

        $this->assertSame(
            [
                ['A', StructureChoiceStatus::Assigned, 22_500],
                ['C', StructureChoiceStatus::Assigned, null],
                ['A-refinery', StructureChoiceStatus::Assigned, null],
            ],
            [
                $this->componentOf($planned),
                $this->finalProductOf($planned),
                $this->reactionOf($planned),
            ],
        );
    }

    public function testTypeOfWorkWithoutWorkStructureKeepsTheAutomaticSelection(): void
    {
        $planned = $this->planWith([WorkType::Components->value => Example::workshopStructureWithoutRig()]);

        $this->assertSame(
            [['A', StructureChoiceStatus::Assigned, 22_500], ['A', StructureChoiceStatus::BestConfigured, null], ['C-refinery', StructureChoiceStatus::BestConfigured, null]],
            [$this->componentOf($planned), $this->finalProductOf($planned), $this->reactionOf($planned)],
        );
    }

    public function testWorkStructureThatCannotRunTheActivityIsIgnoredAndTheJobIsSelectedAutomatically(): void
    {
        $planned = $this->planWith([WorkType::Components->value => Example::workshopRefinery()]);

        $choice = $planned->structureChoiceFor(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing);
        $this->assertNotNull($choice);
        $this->assertSame(['C', StructureChoiceStatus::BestConfigured, 'A-refinery'], [$choice->structure?->id, $choice->status, $choice->ignoredAssignedStructure?->id]);
        $this->assertSame(20_250, $this->componentOf($planned)[2]);
    }

    public function testWorkStructuresWithoutClassifierAreRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->planning(null)->planWithStructuresOf($this->request([WorkType::Components->value => Example::workshopStructureWithoutRig()]));
    }

    public function testNoWorkStructureNeedsNoClassifier(): void
    {
        $planned = $this->planning(null)->planWithStructuresOf($this->request([]));

        $this->assertSame(['C', StructureChoiceStatus::BestConfigured, 20_250], $this->componentOf($planned));
    }

    public function testBlueprintsWorkStructureIsTheStructureAssignedToTheCopy(): void
    {
        $planned = $this->planWith([WorkType::Blueprints->value => Example::workshopStructureWithoutRig()]);

        $this->assertSame(['A', StructureChoiceStatus::Assigned, null], $this->copyOf($planned));
        $this->assertSame(['C', StructureChoiceStatus::BestConfigured, 20_250], $this->componentOf($planned));
    }

    public function testBlueprintsWorkStructureThatCannotCopyIsIgnoredAndTheCopyIsSelectedAutomatically(): void
    {
        $planned = $this->planWith([WorkType::Blueprints->value => Example::workshopRefinery()]);

        $choice = $planned->copyStructureChoice;
        $this->assertNotNull($choice);
        $this->assertSame(['C', StructureChoiceStatus::BestConfigured, 'A-refinery'], [$choice->structure?->id, $choice->status, $choice->ignoredAssignedStructure?->id]);
    }

    /**
     * @param array<string, ProductionStructure> $workStructures
     */
    private function planWith(array $workStructures): PlannedProduction
    {
        return $this->planning(new WorkTypeClassifier(new InMemoryShipProducts()))->planWithStructuresOf($this->request($workStructures));
    }

    /**
     * @param array<string, ProductionStructure> $workStructures
     */
    private function request(array $workStructures): ProductionPlanRequest
    {
        return new ProductionPlanRequest(
            [new PlanTarget(self::ARBITRATOR, new Runs(2), new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)))],
            [Example::workshopStructureWithoutRig(), Example::structureC(), Example::workshopRefinery(), Example::riggedRefinery()],
            FavoriteSystems::none(),
            workStructures: $workStructures,
        );
    }

    private function planning(?WorkTypeClassifier $classifier): ProductionPlanning
    {
        return new ProductionPlanning(
            new InMemoryBlueprintCatalog(
                $this->recipeOf(self::ARBITRATOR, ActivityKind::Manufacturing, [self::RAM_STARSHIP_TECH => 5, self::MORPHITE => 300]),
                $this->recipeOf(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing, [self::FERNITE_COMPOSITE => 40, self::TRITANIUM => 2_500]),
                $this->recipeOf(self::FERNITE_COMPOSITE, ActivityKind::Reaction, [self::TRITANIUM => 7_000]),
            ),
            Example::costIndices(),
            new InMemoryRigCategories(
                [self::RAM_STARSHIP_TECH => Example::PRODUCT_CATEGORY],
                [self::FERNITE_COMPOSITE => Example::PRODUCT_CATEGORY],
            ),
            null,
            $classifier,
        );
    }

    /**
     * @param array<int, int> $baseQuantitiesPerRun
     */
    private function recipeOf(int $productTypeId, ActivityKind $activity, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun(1), $materials, new Runs(300), 3600);
    }

    /**
     * @return array{0: ?string, 1: StructureChoiceStatus|null, 2: int|null}
     */
    private function componentOf(PlannedProduction $planned): array
    {
        $componentJobs = $planned->plan->jobsFor(self::RAM_STARSHIP_TECH);
        $this->assertCount(1, $componentJobs);

        return [...$this->structureOf($planned->structureChoiceFor(self::RAM_STARSHIP_TECH, ActivityKind::Manufacturing)), $componentJobs[0]->materials[self::TRITANIUM]->value];
    }

    /**
     * @return array{0: ?string, 1: StructureChoiceStatus|null, 2: null}
     */
    private function finalProductOf(PlannedProduction $planned): array
    {
        return [...$this->structureOf($planned->structureChoiceFor(self::ARBITRATOR, ActivityKind::Manufacturing)), null];
    }

    /**
     * @return array{0: ?string, 1: StructureChoiceStatus|null, 2: null}
     */
    private function reactionOf(PlannedProduction $planned): array
    {
        return [...$this->structureOf($planned->structureChoiceFor(self::FERNITE_COMPOSITE, ActivityKind::Reaction)), null];
    }

    /**
     * @return array{0: ?string, 1: StructureChoiceStatus|null, 2: null}
     */
    private function copyOf(PlannedProduction $planned): array
    {
        return [...$this->structureOf($planned->copyStructureChoice), null];
    }

    /**
     * @return array{0: ?string, 1: StructureChoiceStatus|null}
     */
    private function structureOf(?StructureChoice $choice): array
    {
        $this->assertNotNull($choice);

        return [$choice->structure?->id, $choice->status];
    }
}
