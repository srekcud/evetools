<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\StationMigration;

enum StationMigrationChangeKind: string
{
    case WorkStation = 'work_station';
    case ContainerLocation = 'container_location';
}
