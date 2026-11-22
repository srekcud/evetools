<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectContainerStation
{
    public function __construct(
        public string $structureId,
        public string $structureName,
        public ?int $locationId,
        public ?int $solarSystemId,
        public ?string $containerId,
        public ?string $containerName,
        public ?string $proposedName,
    ) {
    }
}
