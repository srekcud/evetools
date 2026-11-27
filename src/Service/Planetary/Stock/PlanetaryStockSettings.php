<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryStockSettings
{
    public function __construct(
        public ?int $homeLocationId,
        public int $hubStructureId,
        public int $coverageWeeks,
        public array $excludedTypeIds = [],
    ) {
    }
}
