<?php

declare(strict_types=1);

namespace App\Enum;

enum SdeBuildStatus: string
{
    case Unknown = 'unknown';
    case NotRecorded = 'not_recorded';
    case UpToDate = 'up_to_date';
    case Outdated = 'outdated';
}
