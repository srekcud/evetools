<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

use App\Enum\StructureAccessStatus;

final readonly class HaulLeg
{
    /**
     * @param list<int> $carrierCharacterIds
     * @param list<HaulLine> $lines
     */
    public function __construct(
        public string $key,
        public HaulPlace $departure,
        public HaulPlace $arrival,
        public StructureAccessStatus $access,
        public array $carrierCharacterIds,
        public ?float $volume,
        public array $lines,
    ) {
    }
}
