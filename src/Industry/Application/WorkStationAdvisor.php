<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Job;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\Plan;

final readonly class WorkStationAdvisor
{
    private const int STATION_REFINEMENT_PASSES = 2;

    public function __construct(
        private BlueprintCatalog $blueprints,
        private RigCategories $rigCategories,
        private StructureTotalCostComparator $totalCostComparator,
        private SystemCostIndices $costIndices,
        private ItemVolumes $itemVolumes,
        private WorkTypeClassifier $classifier,
    ) {
    }

    /**
     * @param list<ProductionStructure> $structures
     * @param list<int>                 $targetProductTypeIds
     * @param array<string, string>     $storedStructureIds   structure id stored by WorkType value
     *
     * @return list<WorkTypeAdvice>
     */
    public function advise(
        PlannedProduction $planned,
        array $structures,
        FavoriteSystems $favoriteSystems,
        array $targetProductTypeIds,
        array $storedStructureIds = [],
        bool $includesBlueprints = false,
    ): array {
        $jobsByType = $this->jobsByWorkType($planned->plan, $targetProductTypeIds);
        $structuresById = [];
        foreach ($structures as $structure) {
            $structuresById[$structure->id] = $structure;
        }
        $costsByType = $this->installationAndMaterialCostsOf($jobsByType, $structures, $favoriteSystems);
        $cargoByType = $this->cargoByWorkType($jobsByType, $planned->plan);

        $stations = array_map(static fn (array $costsByStructure): ?string => self::cheapestStationOf($costsByStructure), $costsByType);
        for ($pass = 0; $pass < self::STATION_REFINEMENT_PASSES; ++$pass) {
            $stations = $this->refinedStations($stations, $jobsByType, $costsByType, $cargoByType, $structuresById, $favoriteSystems, $storedStructureIds);
        }

        $advices = [];
        foreach ($jobsByType as $type => $jobs) {
            $rows = $this->rowsOf($type, $jobs, $costsByType[$type], $cargoByType[$type], $stations, $structuresById, $favoriteSystems);
            $advices[] = $this->adviceOf(WorkType::from($type), $jobs, $rows, $storedStructureIds[$type] ?? null, $structuresById);
        }
        if ($includesBlueprints) {
            $advices[] = $this->blueprintsAdvice($structures, $favoriteSystems, $storedStructureIds[WorkType::Blueprints->value] ?? null, $structuresById);
        }

        return $advices;
    }

    /**
     * @param list<int> $targetProductTypeIds
     *
     * @return array<string, non-empty-list<Job>> by WorkType value, in the order of the work types
     */
    private function jobsByWorkType(Plan $plan, array $targetProductTypeIds): array
    {
        $jobsByType = [];
        foreach ($plan->jobs as $job) {
            if (ActivityKind::Manufacturing === $job->activity || ActivityKind::Reaction === $job->activity) {
                $jobsByType[$this->classifier->classify($job->productTypeId, $job->activity, $targetProductTypeIds)->value][] = $job;
            }
        }

        $ordered = [];
        foreach (WorkType::cases() as $type) {
            if (isset($jobsByType[$type->value])) {
                $ordered[$type->value] = $jobsByType[$type->value];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, non-empty-list<Job>> $jobsByType
     * @param list<ProductionStructure>          $structures
     *
     * @return array<string, array<string, array{materials: Cost, installation: Cost}>> by WorkType value, then structure id
     */
    private function installationAndMaterialCostsOf(array $jobsByType, array $structures, FavoriteSystems $favoriteSystems): array
    {
        $costs = [];
        foreach ($jobsByType as $type => $jobs) {
            foreach ($structures as $structure) {
                if ($structure->canRun($jobs[0]->activity)) {
                    $costs[$type][$structure->id] = $this->installationAndMaterialCostOf($jobs, $structure, $favoriteSystems);
                }
            }
            $costs[$type] ??= [];
        }

        return $costs;
    }

    /**
     * @param non-empty-list<Job> $jobs
     *
     * @return array{materials: Cost, installation: Cost}
     */
    private function installationAndMaterialCostOf(array $jobs, ProductionStructure $structure, FavoriteSystems $favoriteSystems): array
    {
        $breakdown = null;
        foreach ($jobs as $job) {
            $recipe = $this->blueprints->recipeFor($job->productTypeId)
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
            $jobBreakdown = $this->totalCostComparator->breakdownOf(
                $recipe,
                $job->runs,
                $this->rigCategories->categoryOf($job->productTypeId, $job->activity),
                $structure,
                $favoriteSystems->forActivity($job->activity),
                false,
            );
            $breakdown = null === $breakdown ? $jobBreakdown : $breakdown->plus($jobBreakdown);
        }

        return ['materials' => $breakdown->materials, 'installation' => $breakdown->installation];
    }

    /**
     * @param array<string, array{materials: Cost, installation: Cost}> $costsByStructure
     */
    private static function cheapestStationOf(array $costsByStructure): ?string
    {
        $cheapestId = null;
        $lowestCost = \INF;
        foreach ($costsByStructure as $structureId => $costs) {
            $cost = $costs['materials']->plus($costs['installation']);
            if ($cost->isKnown() && $cost->amount()->amount < $lowestCost) {
                $cheapestId = $structureId;
                $lowestCost = $cost->amount()->amount;
            }
        }

        return $cheapestId;
    }

    /**
     * @param array<string, non-empty-list<Job>> $jobsByType
     *
     * @return array<string, list<WorkCargo>> by WorkType value
     */
    private function cargoByWorkType(array $jobsByType, Plan $plan): array
    {
        $producerOf = [];
        foreach ($jobsByType as $type => $jobs) {
            foreach ($jobs as $job) {
                $producerOf[$job->productTypeId] = $type;
            }
        }

        $cargoByType = [];
        foreach ($jobsByType as $type => $jobs) {
            $purchasedUnits = [];
            $unitsByProducer = [];
            foreach ($jobs as $job) {
                foreach ($job->materials as $typeId => $quantity) {
                    $producer = $producerOf[$typeId] ?? null;
                    if (null !== $producer && $producer !== $type) {
                        $unitsByProducer[$producer][$typeId] = ($unitsByProducer[$producer][$typeId] ?? 0) + $quantity->value;
                    } elseif (null === $producer && isset($plan->leaves[$typeId])) {
                        $purchasedUnits[$typeId] = ($purchasedUnits[$typeId] ?? 0) + $quantity->value;
                    }
                }
            }

            $cargo = [];
            if ([] !== $purchasedUnits) {
                $cargo[] = WorkCargo::of(WorkTransportKind::Courier, null, $purchasedUnits, $this->itemVolumes);
            }
            foreach ($unitsByProducer as $producer => $units) {
                $cargo[] = WorkCargo::of(WorkTransportKind::JumpBridge, WorkType::from($producer), $units, $this->itemVolumes);
            }
            if (WorkType::FinalProducts->value === $type) {
                $cargo[] = WorkCargo::of(WorkTransportKind::ToHub, null, $this->producedUnitsOf($jobs), $this->itemVolumes);
            }
            $cargoByType[$type] = $cargo;
        }

        return $cargoByType;
    }

    /**
     * @param non-empty-list<Job> $jobs
     *
     * @return array<int, int>
     */
    private function producedUnitsOf(array $jobs): array
    {
        $units = [];
        foreach ($jobs as $job) {
            $recipe = $this->blueprints->recipeFor($job->productTypeId)
                ?? throw new \LogicException(\sprintf('Job of product %d has no recipe.', $job->productTypeId));
            $units[$job->productTypeId] = ($units[$job->productTypeId] ?? 0) + $job->runs->value * $recipe->outputPerRun->value;
        }

        return $units;
    }

    /**
     * @param array<string, ?string>                                                   $stations
     * @param array<string, non-empty-list<Job>>                                       $jobsByType
     * @param array<string, array<string, array{materials: Cost, installation: Cost}>> $costsByType
     * @param array<string, list<WorkCargo>>                                           $cargoByType
     * @param array<string, ProductionStructure>                                       $structuresById
     * @param array<string, string>                                                    $storedStructureIds
     *
     * @return array<string, ?string>
     */
    private function refinedStations(array $stations, array $jobsByType, array $costsByType, array $cargoByType, array $structuresById, FavoriteSystems $favoriteSystems, array $storedStructureIds): array
    {
        $refined = $stations;
        foreach ($jobsByType as $type => $jobs) {
            $rows = $this->rowsOf($type, $jobs, $costsByType[$type], $cargoByType[$type], $stations, $structuresById, $favoriteSystems);
            $refined[$type] = self::recommendedStructureIdOf($rows);
        }
        foreach ($jobsByType as $type => $jobs) {
            $storedId = self::runnableStoredId($storedStructureIds[$type] ?? null, $jobs[0]->activity, $structuresById);
            if (null !== $storedId) {
                $refined[$type] = $storedId;
            }
        }

        return $refined;
    }

    /**
     * @param non-empty-list<Job>                                       $jobs
     * @param array<string, array{materials: Cost, installation: Cost}> $costsByStructure
     * @param list<WorkCargo>                                           $cargo
     * @param array<string, ?string>                                    $stations
     * @param array<string, ProductionStructure>                        $structuresById
     *
     * @return list<WorkStationRow>
     */
    private function rowsOf(string $type, array $jobs, array $costsByStructure, array $cargo, array $stations, array $structuresById, FavoriteSystems $favoriteSystems): array
    {
        $activity = $jobs[0]->activity;
        $category = $this->rigCategories->categoryOf($jobs[0]->productTypeId, $activity);
        $rows = [];
        foreach ($costsByStructure as $structureId => $costs) {
            $structure = $structuresById[$structureId];
            $legs = [];
            $transport = Cost::known(new Isk(0.0));
            foreach ($cargo as $load) {
                [$leg, $legCost] = $this->legOf($load, $structure, $stations, $structuresById, $favoriteSystems->courierHub);
                $legs[] = $leg;
                $transport = $transport->plus($legCost);
            }
            $total = $costs['materials']->plus($costs['installation'])->plus($transport);
            $rows[] = new WorkStationRow(
                $structure,
                $favoriteSystems->contains($structure, $activity),
                $structure->materialMultiplierFor($activity, $category),
                self::amountOf($costs['materials']),
                self::amountOf($costs['installation']),
                self::amountOf($transport),
                $legs,
                self::amountOf($total),
                null,
                $total->missingData,
            );
        }

        return $this->rankedWithDelta($rows);
    }

    /**
     * @param array<string, ?string>             $stations
     * @param array<string, ProductionStructure> $structuresById
     *
     * @return array{0: WorkTransportLeg, 1: Cost}
     */
    private function legOf(WorkCargo $cargo, ProductionStructure $structure, array $stations, array $structuresById, ?CourierHub $hub): array
    {
        $originId = null === $cargo->producedBy ? null : ($stations[$cargo->producedBy->value] ?? null);
        if (null === $hub) {
            $cost = Cost::unknown(MissingData::courierHub());
        } else {
            $cost = match ($cargo->kind) {
                WorkTransportKind::Courier => $cargo->costOf($hub->transportCostOf($cargo->cubicMeters, $structure->solarSystemId)),
                WorkTransportKind::JumpBridge => $this->jumpBridgeCostOf($cargo, $structure, null === $originId ? null : $structuresById[$originId], $hub),
                WorkTransportKind::ToHub => $this->toHubCostOf($cargo, $structure, $hub),
            };
        }

        $leg = match ($cargo->kind) {
            WorkTransportKind::Courier => new WorkTransportLeg($cargo->kind, null, $structure->id, $cargo->cubicMeters, self::amountOf($cost)),
            WorkTransportKind::JumpBridge => new WorkTransportLeg($cargo->kind, $originId, $structure->id, $cargo->cubicMeters, self::amountOf($cost)),
            WorkTransportKind::ToHub => new WorkTransportLeg($cargo->kind, $structure->id, null, $cargo->cubicMeters, self::amountOf($cost)),
        };

        return [$leg, $cost];
    }

    private function jumpBridgeCostOf(WorkCargo $cargo, ProductionStructure $destination, ?ProductionStructure $origin, CourierHub $hub): Cost
    {
        if ($origin?->id === $destination->id) {
            return Cost::known(new Isk(0.0));
        }
        if (null === $destination->solarSystemId || null === $origin?->solarSystemId) {
            return Cost::unknown(MissingData::jumpRoute());
        }
        if ($destination->solarSystemId === $origin->solarSystemId) {
            return Cost::known(new Isk(0.0));
        }

        return $cargo->costAt($hub->jumpBridgeIskPerCubicMeter);
    }

    private function toHubCostOf(WorkCargo $cargo, ProductionStructure $origin, CourierHub $hub): Cost
    {
        if (null === $origin->solarSystemId) {
            return Cost::unknown(MissingData::jumpRoute());
        }
        if ($origin->solarSystemId === $hub->solarSystemId) {
            return Cost::known(new Isk(0.0));
        }

        return $cargo->costAt($hub->jumpBridgeIskPerCubicMeter);
    }

    /**
     * @param list<WorkStationRow> $rows
     *
     * @return list<WorkStationRow>
     */
    private function rankedWithDelta(array $rows): array
    {
        usort(
            $rows,
            static fn (WorkStationRow $left, WorkStationRow $right): int => [null === $left->totalCost, $left->totalCost ?? 0.0, !$left->inFavoriteSystem, $right->structure->configuredAt, $left->structure->id]
                <=> [null === $right->totalCost, $right->totalCost ?? 0.0, !$right->inFavoriteSystem, $left->structure->configuredAt, $right->structure->id],
        );

        $lowestTotal = $rows[0]->totalCost ?? null;

        return array_map(
            static fn (WorkStationRow $row): WorkStationRow => $row->withDelta(null === $row->totalCost || null === $lowestTotal ? null : $row->totalCost - $lowestTotal),
            $rows,
        );
    }

    /**
     * @param list<WorkStationRow> $rows
     */
    private static function recommendedStructureIdOf(array $rows): ?string
    {
        $recommended = $rows[0] ?? null;

        return null === $recommended?->totalCost ? null : $recommended->structure->id;
    }

    /**
     * @param non-empty-list<Job>                $jobs
     * @param list<WorkStationRow>               $rows
     * @param array<string, ProductionStructure> $structuresById
     */
    private function adviceOf(WorkType $type, array $jobs, array $rows, ?string $storedId, array $structuresById): WorkTypeAdvice
    {
        $activity = $jobs[0]->activity;
        $recommendedId = self::recommendedStructureIdOf($rows);
        $chosenId = self::runnableStoredId($storedId, $activity, $structuresById);

        return new WorkTypeAdvice(
            $type,
            $activity,
            \count($jobs),
            array_sum(array_map(static fn (Job $job): int => $job->runs->value, $jobs)),
            $recommendedId,
            $chosenId,
            null !== $storedId && null === $chosenId,
            self::savingOf($rows, $chosenId, $recommendedId),
            true,
            $rows,
        );
    }

    /**
     * @param list<WorkStationRow> $rows
     */
    private static function savingOf(array $rows, ?string $chosenId, ?string $recommendedId): ?float
    {
        if (null === $chosenId || null === $recommendedId || $chosenId === $recommendedId) {
            return null;
        }
        $chosenTotal = array_find($rows, static fn (WorkStationRow $row): bool => $row->structure->id === $chosenId)?->totalCost;
        $recommendedTotal = array_find($rows, static fn (WorkStationRow $row): bool => $row->structure->id === $recommendedId)?->totalCost;

        if (null === $chosenTotal || null === $recommendedTotal || $chosenTotal - $recommendedTotal <= 0.0) {
            return null;
        }

        return $chosenTotal - $recommendedTotal;
    }

    /**
     * @param list<ProductionStructure>          $structures
     * @param array<string, ProductionStructure> $structuresById
     */
    private function blueprintsAdvice(array $structures, FavoriteSystems $favoriteSystems, ?string $storedId, array $structuresById): WorkTypeAdvice
    {
        $recommendedId = (new StructureSelector($structures, $favoriteSystems, $this->costIndices))->select(ActivityKind::Copying, null)->structure?->id;
        $rows = [];
        foreach ($structures as $structure) {
            if ($structure->canRun(ActivityKind::Copying)) {
                $rows[] = new WorkStationRow(
                    $structure,
                    $favoriteSystems->contains($structure, ActivityKind::Copying),
                    $structure->materialMultiplierFor(ActivityKind::Copying, null),
                    null,
                    null,
                    null,
                    [],
                    null,
                    null,
                    [],
                );
            }
        }
        $chosenId = self::runnableStoredId($storedId, ActivityKind::Copying, $structuresById);

        return new WorkTypeAdvice(WorkType::Blueprints, ActivityKind::Copying, 0, 0, $recommendedId, $chosenId, null !== $storedId && null === $chosenId, null, false, $rows);
    }

    /**
     * @param array<string, ProductionStructure> $structuresById
     */
    private static function runnableStoredId(?string $storedId, ActivityKind $activity, array $structuresById): ?string
    {
        return null !== $storedId && ($structuresById[$storedId] ?? null)?->canRun($activity) ? $storedId : null;
    }

    private static function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? $cost->amount()->amount : null;
    }
}
