<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AssetDto
{
    public function __construct(
        public int $itemId,
        public int $typeId,
        public string $typeName,
        public int $quantity,
        public int $locationId,
        public string $locationName,
        public string $locationType,
        public ?string $locationFlag = null,
        public ?string $divisionName = null,
        public ?int $solarSystemId = null,
        public ?string $solarSystemName = null,
        public ?string $itemName = null,
    ) {
    }
}
