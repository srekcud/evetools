<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertPriceSource: string
{
    case JitaSell = 'jita_sell';
    case JitaBuy = 'jita_buy';
    case StructureSell = 'structure_sell';
    case StructureBuy = 'structure_buy';
}
