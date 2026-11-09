<?php

declare(strict_types=1);

namespace App\Enum;

enum SaleChannel: string
{
    case Market = 'market';
    case Contract = 'contract';
}
