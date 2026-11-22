<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class StationMigrationReport
{
    /**
     * @param list<ProjectStationMigrationReport> $projects
     */
    public function __construct(public array $projects)
    {
    }

    /**
     * @return list<StationMigrationChange>
     */
    public function changes(): array
    {
        return array_merge(...array_map(
            static fn (ProjectStationMigrationReport $project): array => $project->changes,
            $this->projects,
        ));
    }

    /**
     * @return array<string, int> not migrated count by reason value
     */
    public function unmigratedCountsByReason(): array
    {
        $counts = [];
        foreach ($this->projects as $project) {
            foreach ($project->unmigrated as $unmigrated) {
                $counts[$unmigrated->reason->value] = ($counts[$unmigrated->reason->value] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
