<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryBuyOrder
{
    public function __construct(
        public int $typeId,
        public int $locationId,
        public int $volumeRemain,
    ) {
    }
}
