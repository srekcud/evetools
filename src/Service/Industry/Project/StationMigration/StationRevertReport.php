<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class StationRevertReport
{
    /**
     * @param list<StationMigrationChange> $reverted
     * @param list<StationMigrationChange> $changedSince
     */
    public function __construct(
        public array $reverted,
        public array $changedSince,
    ) {
    }
}
