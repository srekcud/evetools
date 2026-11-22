<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class ProjectStationMigrationReport
{
    /**
     * @param list<StationMigrationChange> $changes
     * @param list<StationMigrationUnmigrated> $unmigrated
     */
    public function __construct(
        public string $projectId,
        public ?string $projectName,
        public string $status,
        public array $changes,
        public array $unmigrated,
    ) {
    }
}
