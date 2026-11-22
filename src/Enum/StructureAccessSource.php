<?php

declare(strict_types=1);

namespace App\Enum;

enum StructureAccessSource: string
{
    case Esi = 'esi';
    case Job = 'job';
}
