<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class ProjectLineDemand
{
    public function __construct(
        public string $projectId,
        public ?string $projectName,
        public int $typeId,
        public ?string $typeName,
        public int $remaining,
    ) {
    }
}
