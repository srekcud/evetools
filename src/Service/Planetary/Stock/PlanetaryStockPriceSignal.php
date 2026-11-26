<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

enum PlanetaryStockPriceSignal: string
{
    case BuyJita = 'buy_jita';
    case BuyHub = 'buy_hub';
    case Outbid = 'outbid';
    case PlaceOrder = 'place_order';
}
