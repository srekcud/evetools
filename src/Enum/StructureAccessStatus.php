<?php

declare(strict_types=1);

namespace App\Enum;

enum StructureAccessStatus: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
