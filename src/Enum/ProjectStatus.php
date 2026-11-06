<?php

declare(strict_types=1);

namespace App\Enum;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case InProgress = 'in_progress';
    case Selling = 'selling';
    case Completed = 'completed';
}
