<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum SourcingSide: string
{
    case Jita = 'jita';
    case Structure = 'structure';
    case Unknown = 'unknown';
}
