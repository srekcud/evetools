<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class StationMigrationChange
{
    public function __construct(
        public string $projectId,
        public StationMigrationChangeKind $kind,
        public string $subject,
        public string $value,
    ) {
    }
}
