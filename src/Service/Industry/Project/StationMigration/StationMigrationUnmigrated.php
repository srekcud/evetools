<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class StationMigrationUnmigrated
{
    public function __construct(
        public string $projectId,
        public StationMigrationChangeKind $kind,
        public string $subject,
        public StationMigrationReason $reason,
        public ?string $detail = null,
    ) {
    }
}
