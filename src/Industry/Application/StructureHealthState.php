<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum StructureHealthState: string
{
    case Ok = 'ok';
    case LowFuel = 'low-fuel';
    case OfflineService = 'offline-service';
    case Unknown = 'unknown';
}
