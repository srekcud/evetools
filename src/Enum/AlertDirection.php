<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertDirection: string
{
    case Above = 'above';
    case Below = 'below';
}
