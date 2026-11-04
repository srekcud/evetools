<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningSiteStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
