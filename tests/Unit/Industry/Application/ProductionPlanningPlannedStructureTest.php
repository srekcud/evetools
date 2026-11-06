<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionPlanning;
use App\Industry\Application\ProductionPlanRequest;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureSelectionPolicy;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\PlanTarget;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\InMemoryRigCategories;
use App\Tests\Unit\Industry\Application\Double\InMemorySystemCostIndices;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionPlanning::class)]
#[CoversClass(PlannedProduction::class)]
#[Group('issue-337')]
final class ProductionPlanningPlannedStructureTest extends TestCase
{
    private const int HAMMERHEAD_II = 337_002;
    private const int HAMMERHEAD_I = 337_001;
    private const int FULLERIDES = 337_005;
    private const int TRITANIUM = 34;
    private const int CARBON_POLYMERS = 337_006;

    private const int MANUFACTURING_FAVORITE_SYSTEM_ID = 30_002_187;
    private const int REFINERY_SYSTEM_ID = 30_004_759;
    private const string RAITARU_ID = '01970000-0000-7000-8000-0000000000a1';
    private const string TATARA_ID = '01970000-0000-7000-8000-0000000000a2';

    public function testEachJobOfThePlanKeepsTheStructureChosenForItsProductAndActivityWithHowItWasChosen(): void
    {
        $planned = $this->planning()->planWithStructuresOf($this->requestWithTheRaitaruAndTheTatara());

        self::assertSame(
            [
                [self::RAITARU_ID, StructureChoiceStatus::BestInFavoriteSystem],
                [self::RAITARU_ID, StructureChoiceStatus::BestInFavoriteSystem],
                [self::TATARA_ID, StructureChoiceStatus::BestConfigured],
            ],
            [
                self::structureAndStatusOf($planned->structureChoiceFor(self::HAMMERHEAD_II, ActivityKind::Manufacturing)),
                self::structureAndStatusOf($planned->structureChoiceFor(self::HAMMERHEAD_I, ActivityKind::Manufacturing)),
                self::structureAndStatusOf($planned->structureChoiceFor(self::FULLERIDES, ActivityKind::Reaction)),
            ],
        );
    }

    public function testMaterialOfThePlanAndProductOfAnotherActivityHaveNoStructureChoice(): void
    {
        $planned = $this->planning()->planWithStructuresOf($this->requestWithTheRaitaruAndTheTatara());

        self::assertSame(
            [null, null],
            [
                $planned->structureChoiceFor(self::TRITANIUM, ActivityKind::Manufacturing),
                $planned->structureChoiceFor(self::FULLERIDES, ActivityKind::Manufacturing),
            ],
        );
    }

    public function testJobOfAnOwnerWithoutConfiguredStructureKeepsAChoiceOfNoStructure(): void
    {
        $planned = $this->planning()->planWithStructuresOf(new ProductionPlanRequest([self::hammerheadTarget()], [], FavoriteSystems::none()));

        self::assertSame(
            [null, StructureChoiceStatus::NotConfigured],
            self::structureAndStatusOf($planned->structureChoiceFor(self::HAMMERHEAD_II, ActivityKind::Manufacturing)),
        );
    }

    public function testStructureChosenByTheSelectionPolicyIsTheOneKeptForTheJob(): void
    {
        $planning = new ProductionPlanning(
            new InMemoryBlueprintCatalog(StructureTotalCostExample::recipe()),
            StructureTotalCostExample::costIndices(),
            new InMemoryRigCategories(manufacturing: [StructureTotalCostExample::PRODUCT_TYPE_ID => StructureTotalCostExample::PRODUCT_CATEGORY]),
            StructureTotalCostExample::comparator(),
        );

        $planned = $planning->planWithStructuresOf(new ProductionPlanRequest(
            [new PlanTarget(
                StructureTotalCostExample::PRODUCT_TYPE_ID,
                new Runs(StructureTotalCostExample::REFERENCE_RUNS),
                new BlueprintEfficiency(new MaterialEfficiency(0), new TimeEfficiency(0)),
            )],
            [StructureTotalCostExample::workshopStructureWithNeutralRig(), StructureTotalCostExample::structureC()],
            new FavoriteSystems(StructureTotalCostExample::WORKSHOP_SYSTEM_ID, null),
            selectionPolicy: new StructureSelectionPolicy(true, new Isk(100_000.0)),
        ));

        self::assertSame(
            ['C', StructureChoiceStatus::BestByTotalCost],
            self::structureAndStatusOf($planned->structureChoiceFor(StructureTotalCostExample::PRODUCT_TYPE_ID, ActivityKind::Manufacturing)),
        );
    }

    public function testPlanOfIsThePlanOfThePlannedProduction(): void
    {
        $request = $this->requestWithTheRaitaruAndTheTatara();

        self::assertEquals($this->planning()->planWithStructuresOf($request)->plan, $this->planning()->planOf($request));
    }

    private function planning(): ProductionPlanning
    {
        return new ProductionPlanning(
            new InMemoryBlueprintCatalog(
                self::recipe(self::HAMMERHEAD_II, ActivityKind::Manufacturing, 1, 10, [self::HAMMERHEAD_I => 1, self::FULLERIDES => 30, self::TRITANIUM => 900]),
                self::recipe(self::HAMMERHEAD_I, ActivityKind::Manufacturing, 1, 300, [self::TRITANIUM => 2_500]),
                self::recipe(self::FULLERIDES, ActivityKind::Reaction, 3_000, 100, [self::CARBON_POLYMERS => 100]),
            ),
            InMemorySystemCostIndices::none(),
            new InMemoryRigCategories(),
        );
    }

    private function requestWithTheRaitaruAndTheTatara(): ProductionPlanRequest
    {
        return new ProductionPlanRequest(
            [self::hammerheadTarget()],
            [
                ProductionStructures::raitaru(self::RAITARU_ID, SecurityClass::NullSec, self::MANUFACTURING_FAVORITE_SYSTEM_ID),
                ProductionStructures::tatara(self::TATARA_ID, SecurityClass::NullSec, self::REFINERY_SYSTEM_ID),
            ],
            new FavoriteSystems(self::MANUFACTURING_FAVORITE_SYSTEM_ID, null),
        );
    }

    private static function hammerheadTarget(): PlanTarget
    {
        return new PlanTarget(
            self::HAMMERHEAD_II,
            new Runs(5),
            new BlueprintEfficiency(new MaterialEfficiency(2), new TimeEfficiency(4)),
        );
    }

    private static function recipe(int $productTypeId, ActivityKind $activity, int $outputPerRun, int $maxProductionLimit, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun($outputPerRun), $materials, new Runs($maxProductionLimit), 3_600);
    }

    private static function structureAndStatusOf(?StructureChoice $choice): array
    {
        self::assertNotNull($choice);

        return [$choice->structure?->id, $choice->status];
    }
}
