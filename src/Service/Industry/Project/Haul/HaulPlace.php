<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

final readonly class HaulPlace
{
    public function __construct(
        public ?int $locationId,
        public ?string $stationName,
        public ?int $solarSystemId,
        public ?string $solarSystemName,
    ) {
    }
}
