<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

final readonly class AssetChangeResource
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
