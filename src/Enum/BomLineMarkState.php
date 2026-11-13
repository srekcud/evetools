<?php

declare(strict_types=1);

namespace App\Enum;

enum BomLineMarkState: string
{
    case Launched = 'launched';
    case Done = 'done';
}
