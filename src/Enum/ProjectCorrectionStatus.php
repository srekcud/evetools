<?php

declare(strict_types=1);

namespace App\Enum;

enum ProjectCorrectionStatus: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
