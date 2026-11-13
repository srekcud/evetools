<?php

declare(strict_types=1);

namespace App\Service\Assets;

final readonly class PinnedContainerEntry
{
    public function __construct(
        public string $key,
        public ?int $itemId,
        public string $name,
        public ?string $locationName,
        public ?string $solarSystemName,
        public ?string $divisionName,
        public ?int $itemCount,
        public bool $found,
        public ?string $projectId,
        public ?string $projectName,
    ) {
    }
}
