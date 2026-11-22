<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class AssetStation
{
    public function __construct(
        public int $locationId,
        public string $name,
        public ?string $solarSystemName,
    ) {
    }
}
