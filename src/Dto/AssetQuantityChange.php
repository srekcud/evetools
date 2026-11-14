<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AssetQuantityChange
{
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public int $locationId,
        public string $locationName,
        public ?string $divisionName,
        public int $quantityDelta,
    ) {
    }
}
