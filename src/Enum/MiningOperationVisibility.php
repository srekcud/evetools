<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningOperationVisibility: string
{
    case Private = 'private';
    case Corporation = 'corporation';
}
