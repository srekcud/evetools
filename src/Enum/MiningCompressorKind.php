<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningCompressorKind: string
{
    case Asteroid = 'asteroid';
    case Gas = 'gas';
    case Ice = 'ice';
    case Mercoxit = 'mercoxit';
    case Moon = 'moon';
}
