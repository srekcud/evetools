<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonTaxPeriodKind: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
