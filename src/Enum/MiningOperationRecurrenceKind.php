<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningOperationRecurrenceKind: string
{
    case Weekly = 'weekly';
    case Chunk = 'chunk';
}
