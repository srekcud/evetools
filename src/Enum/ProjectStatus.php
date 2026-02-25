<?php

declare(strict_types=1);

namespace App\Enum;

enum ProjectStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';
}
