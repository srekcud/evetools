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
        return match ($market) {
            MiningValuationMarket::Jita => match ($this) {
                self::Raw => 'rawUnitPrice',
                self::Compressed => 'compressedEquivalentPrice',
                self::Reprocessed => 'reprocessValue',
            },
            MiningValuationMarket::Structure => match ($this) {
                self::Raw => 'structureUnitPrice',
                self::Compressed => 'structureCompressedEquivalentPrice',
                self::Reprocessed => 'structureReprocessValue',
            },
        };
    }
}
