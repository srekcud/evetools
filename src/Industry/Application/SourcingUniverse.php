<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;

final readonly class SourcingUniverse
{
    public const string UNKNOWN_STATION = '';

    /**
     * @param array<int, non-empty-list<Job>>           $jobsByProduct
     * @param array<int, ?ProductionStructure>          $builderByProduct
     * @param array<int, array<string, int>>            $consumption
     * @param array<string, ?ProductionStructure>       $stations
     * @param list<int>                                 $builtTypeIds
     * @param list<int>                                 $leafTypeIds
     * @param array<int, ActivityKind>                  $activityByProduct
     */
    public function __construct(
        public array $jobsByProduct,
        public array $builderByProduct,
        public array $consumption,
        public array $stations,
        public array $builtTypeIds,
        public array $leafTypeIds,
        public array $activityByProduct,
    ) {
    }

    public static function of(PlannedProduction $planned): self
    {
        $jobsByProduct = [];
        $builderByProduct = [];
        $activityByProduct = [];
        foreach ($planned->plan->jobs as $job) {
            $jobsByProduct[$job->productTypeId][] = $job;
            $activityByProduct[$job->productTypeId] ??= $job->activity;
            $builderByProduct[$job->productTypeId] ??= $planned->structureChoiceFor($job->productTypeId, $job->activity)?->structure;
        }

        $consumption = [];
        $stations = [];
        foreach ($planned->plan->jobs as $job) {
            $structure = $planned->structureChoiceFor($job->productTypeId, $job->activity)?->structure;
            $key = $structure->id ?? self::UNKNOWN_STATION;
            $stations[$key] = $structure;
            foreach ($job->materials as $materialTypeId => $quantity) {
                $consumption[$materialTypeId][$key] = ($consumption[$materialTypeId][$key] ?? 0) + $quantity->value;
            }
        }

        $builtTypeIds = [];
        foreach ($planned->plan->intermediates as $typeId => $intermediate) {
            if ([] !== $intermediate->jobs && isset($consumption[$typeId])) {
                $builtTypeIds[] = $typeId;
            }
        }

        return new self(
            $jobsByProduct,
            $builderByProduct,
            $consumption,
            $stations,
            $builtTypeIds,
            array_keys($planned->plan->leaves),
            $activityByProduct,
        );
    }

    public function demandOf(int $typeId): int
    {
        return array_sum($this->consumption[$typeId] ?? []);
    }

    public function isBuilt(int $typeId): bool
    {
        return \in_array($typeId, $this->builtTypeIds, true);
    }

    /**
     * @return list<int>
     */
    public function consumedTypeIds(): array
    {
        return array_keys($this->consumption);
    }
}
