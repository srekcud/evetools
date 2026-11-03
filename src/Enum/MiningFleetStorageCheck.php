<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningFleetStorageCheck: string
{
    case Verified = 'verified';
    case Short = 'short';
    case Unknown = 'unknown';
    case NothingDelivered = 'nothing_delivered';
}
