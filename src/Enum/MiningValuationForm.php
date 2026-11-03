<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningValuationForm: string
{
    case Raw = 'raw';
    case Compressed = 'compressed';
    case Reprocessed = 'reprocessed';

    public function priceKeyOn(MiningValuationMarket $market): string
    {
        return '';
    }
}
