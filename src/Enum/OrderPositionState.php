<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderPositionState: string
{
    case First = 'first';
    case Undercut = 'undercut';
    case Unknown = 'unknown';
}
