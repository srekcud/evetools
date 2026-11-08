<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonChunkAlertKind: string
{
    case Soon = 'soon';
    case Arrived = 'arrived';
}
