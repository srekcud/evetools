<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

enum StationMigrationReason: string
{
    case TypeMissingFromSde = 'type_missing_from_sde';
    case LinesWithoutStation = 'lines_without_station';
    case SeveralStations = 'several_stations';
    case StructureUnknown = 'structure_unknown';
    case StructureCannotRun = 'structure_cannot_run';
    case ProjectCompleted = 'project_completed';
    case AssetsUnavailable = 'assets_unavailable';
    case ContainerNotFound = 'container_not_found';
    case StationUnresolved = 'station_unresolved';
    case ContainerInSeveralStations = 'container_in_several_stations';
    case StationNotInPlan = 'station_not_in_plan';
    case StationAlreadyHasContainer = 'station_already_has_container';
    case NamedLikePersonalStock = 'named_like_personal_stock';
    case ChangedSince = 'changed_since';
}
