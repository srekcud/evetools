<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingSecurity: string
{
    case Highsec = 'highsec';
    case Lowsec = 'lowsec';
    case Nullsec = 'nullsec';
}
