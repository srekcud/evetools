<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class BlueprintDto
{
    public function __construct(
        public int $itemId,
        public int $typeId,
        public int $locationId,
        public string $locationFlag,
        public int $quantity,
        public int $materialEfficiency,
        public int $timeEfficiency,
        public int $runs,
    ) {
    }
}
