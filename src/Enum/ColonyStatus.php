<?php

declare(strict_types=1);

namespace App\Enum;

enum ColonyStatus: string
{
    case Expired = 'expired';
    case Expiring = 'expiring';
    case Active = 'active';
    case Idle = 'idle';
}
