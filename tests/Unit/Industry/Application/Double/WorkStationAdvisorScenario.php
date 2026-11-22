<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\CourierHub;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureTotalCostComparator;
use App\Industry\Application\StructureType;
use App\Industry\Application\WorkStationAdvisor;
use App\Industry\Application\WorkTypeClassifier;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\OutputPerRun;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\SecurityClass;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\StructureTotalCostExample as Example;
use App\Tests\Unit\Industry\Domain\Double\InMemoryBlueprintCatalog;

final class WorkStationAdvisorScenario
{
    public const float COURIER_ISK_PER_CUBIC_METER = 800.0;
    public const float JUMP_BRIDGE_ISK_PER_CUBIC_METER = 50.0;
    public const int HUB_SOLAR_SYSTEM_ID = Example::WORKSHOP_SYSTEM_ID;
    public const float FACILITY_TAX_RATE = 0.01;

    /**
     * @param list<Recipe>      $recipes
     * @param array<int, float> $cubicMetersByTypeId
     * @param array<int, float> $unitPrices
     * @param list<int>         $shipTypeIds
     */
    public static function advisor(array $recipes, array $cubicMetersByTypeId, array $unitPrices, array $shipTypeIds = []): WorkStationAdvisor
    {
        $volumes = new InMemoryItemVolumes($cubicMetersByTypeId);

        return new WorkStationAdvisor(
            new InMemoryBlueprintCatalog(...$recipes),
            new InMemoryRigCategories(),
            new StructureTotalCostComparator(
                new InMemoryMarketPrices($unitPrices),
                new InMemoryAdjustedPrices($unitPrices),
                Example::costIndices(),
                Example::jumpDistances(),
                $volumes,
            ),
            Example::costIndices(),
            $volumes,
            new WorkTypeClassifier(new InMemoryShipProducts($shipTypeIds)),
            Example::jumpDistances(),
        );
    }

    public static function favoriteSystemsWithHub(): FavoriteSystems
    {
        return new FavoriteSystems(null, null, new CourierHub(self::HUB_SOLAR_SYSTEM_ID, self::COURIER_ISK_PER_CUBIC_METER, self::JUMP_BRIDGE_ISK_PER_CUBIC_METER));
    }

    public static function complex(string $id, ?int $solarSystemId): ProductionStructure
    {
        return new ProductionStructure($id, StructureType::EngineeringComplex, SecurityClass::HighSec, $solarSystemId, new \DateTimeImmutable('2026-01-01 00:00:00'), Multiplier::one(), [], Multiplier::one(), self::FACILITY_TAX_RATE);
    }

    public static function refinery(string $id, ?int $solarSystemId): ProductionStructure
    {
        return new ProductionStructure($id, StructureType::Refinery, SecurityClass::LowSec, $solarSystemId, new \DateTimeImmutable('2026-01-01 00:00:00'), Multiplier::one(), [], Multiplier::one(), self::FACILITY_TAX_RATE);
    }

    /**
     * @param list<Job>            $jobs
     * @param array<int, int>      $leafQuantities
     */
    public static function plan(array $jobs, array $leafQuantities): Plan
    {
        return new Plan($jobs, array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $leafQuantities), []);
    }

    /**
     * @param array<int, int> $quantitiesByMaterial
     */
    public static function job(int $productTypeId, ActivityKind $activity, int $runs, array $quantitiesByMaterial): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $quantitiesByMaterial),
        );
    }

    /**
     * @param array<int, int> $baseQuantitiesPerRun
     */
    public static function recipe(int $productTypeId, ActivityKind $activity, int $outputPerRun, array $baseQuantitiesPerRun): Recipe
    {
        $materials = [];
        foreach ($baseQuantitiesPerRun as $materialTypeId => $baseQuantityPerRun) {
            $materials[] = new RecipeMaterial($materialTypeId, new Quantity($baseQuantityPerRun));
        }

        return new Recipe($productTypeId, $activity, new OutputPerRun($outputPerRun), $materials, new Runs(300), 3600);
    }
}
