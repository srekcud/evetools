<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockPriceReport
{
    /**
     * @param list<PlanetaryStockPriceRow>                                              $rows
     * @param list<array{characterId: int, characterName: string, status: string}> $unsyncedWallets
     */
    public function __construct(
        public array $rows,
        public bool $complete,
        public array $unsyncedWallets,
    ) {
    }
}
