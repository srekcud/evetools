<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryWeeklyNeed
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public ?float $weeklyNetNeed,
        public ?float $volumePerUnit,
    ) {
    }
}
