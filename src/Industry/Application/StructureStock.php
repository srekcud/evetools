<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum StructureStock: string
{
    case Enough = 'enough';
    case Insufficient = 'insufficient';
    case Unknown = 'unknown';
}
