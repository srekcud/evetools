<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PlanetaryTaxDay
{
    public function __construct(
        public int $eveCharacterId,
        public int $planetId,
        public string $day,
        public float $importTax,
        public float $exportTax,
    ) {
    }
}
