<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonTaxStatus: string
{
    case Provisional = 'provisional';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';
    case Paid = 'paid';
    case Late = 'late';
    case Partial = 'partial';
    case Due = 'due';
}
