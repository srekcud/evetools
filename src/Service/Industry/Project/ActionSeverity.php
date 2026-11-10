<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum ActionSeverity: string
{
    case Warning = 'warning';
    case Info = 'info';
    case Unknown = 'unknown';
}
