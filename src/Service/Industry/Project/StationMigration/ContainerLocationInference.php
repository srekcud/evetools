<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

final readonly class ContainerLocationInference
{
    /**
     * @param list<int|null> $assetStationIds station of each asset named like the container, null when unresolved
     *
     * @return int|StationMigrationReason the location to store, or why none can be
     */
    public function of(array $assetStationIds): int|StationMigrationReason
    {
        if ([] === $assetStationIds) {
            return StationMigrationReason::ContainerNotFound;
        }

        if (\in_array(null, $assetStationIds, true)) {
            return StationMigrationReason::StationUnresolved;
        }

        $distinctStationIds = array_values(array_unique($assetStationIds));

        return 1 === \count($distinctStationIds) ? $distinctStationIds[0] : StationMigrationReason::ContainerInSeveralStations;
    }
}
