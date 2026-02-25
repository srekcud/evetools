<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertStatus: string
{
    case Active = 'active';
    case Triggered = 'triggered';
    case Expired = 'expired';
}
