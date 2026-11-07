<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectReference
{
    public function __construct(
        public int $productTypeId,
        public string $projectId,
        public \DateTimeImmutable $completedAt,
        public ?int $units,
        public ?float $unitCost,
        public ?float $marginPercent,
    ) {
    }
}
