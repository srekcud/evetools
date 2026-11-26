<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStock
{
    /**
     * @param list<PlanetaryStockRow> $rows
     */
    public function __construct(
        public array $rows,
        public ?string $corpUnknownReason,
        public bool $complete,
    ) {
    }
}
