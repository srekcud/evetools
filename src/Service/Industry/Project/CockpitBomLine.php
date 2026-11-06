<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class CockpitBomLine
{
    public function __construct(
        public string $id,
        public int $typeId,
        public string $typeName,
        public int $requiredQuantity,
        public int $fulfilledQuantity,
        public bool $isJob,
        public ?string $jobGroup,
        public ?int $runs,
        public int $matchedRuns,
    ) {
    }
}
