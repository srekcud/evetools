<?php

declare(strict_types=1);

namespace App\Enum;

enum MinersEstimateStatus: string
{
    case Estimated = 'estimated';
    case InsufficientHistory = 'insufficient_history';
}
