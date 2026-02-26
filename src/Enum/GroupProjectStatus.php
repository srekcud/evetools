<?php

declare(strict_types=1);

namespace App\Enum;

enum GroupProjectStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case InProgress = 'in_progress';
    case Selling = 'selling';
    case Completed = 'completed';
}
