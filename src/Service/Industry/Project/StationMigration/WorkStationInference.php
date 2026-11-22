<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\WorkType;
use App\Industry\Domain\ActivityKind;

final readonly class WorkStationInference
{
    /**
     * @param list<string|null> $plannedStructureIds one entry per job line of the work type
     * @param array<string, ProductionStructure> $ownerStructuresById
     *
     * @return string|StationMigrationReason the structure id to store, or why none can be
     */
    public function of(WorkType $workType, array $plannedStructureIds, array $ownerStructuresById): string|StationMigrationReason
    {
        if ([] === $plannedStructureIds || \in_array(null, $plannedStructureIds, true)) {
            return StationMigrationReason::LinesWithoutStation;
        }

        $distinctStructureIds = array_values(array_unique($plannedStructureIds));
        if (\count($distinctStructureIds) > 1) {
            return StationMigrationReason::SeveralStations;
        }

        $structureId = $distinctStructureIds[0];
        $structure = $ownerStructuresById[$structureId] ?? null;
        if (null === $structure) {
            return StationMigrationReason::StructureUnknown;
        }

        return $structure->canRun($this->activityOf($workType)) ? $structureId : StationMigrationReason::StructureCannotRun;
    }

    private function activityOf(WorkType $workType): ActivityKind
    {
        return match ($workType) {
            WorkType::Blueprints => ActivityKind::Copying,
            WorkType::Reaction => ActivityKind::Reaction,
            WorkType::Components, WorkType::Hulls, WorkType::FinalProducts => ActivityKind::Manufacturing,
        };
    }
}
