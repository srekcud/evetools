<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlanningMaterial
{
    public function __construct(
        public string $typeName,
        public int $requiredQuantity,
        public int $fulfilledQuantity,
    ) {
    }
}
