<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderPositionUnknownReason: string
{
    case MarketNotFollowed = 'market_not_followed';
    case NoMarketData = 'no_market_data';
}
