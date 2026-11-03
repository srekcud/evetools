<?php

declare(strict_types=1);

namespace App\Enum;

enum MarketSide: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
