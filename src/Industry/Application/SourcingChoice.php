<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum SourcingChoice: string
{
    case Build = 'build';
    case Jita = 'jita';
    case Structure = 'structure';
}
