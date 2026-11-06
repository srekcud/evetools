<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\ProjectStatus;

final readonly class ProjectCockpitInput
{
    /**
     * @param list<CockpitBomLine> $bomLines
     * @param array<int, int>      $soldQuantityByTypeId
     */
    public function __construct(
        public ProjectStatus $status,
        public int $productCount,
        public array $bomLines,
        public array $soldQuantityByTypeId,
        public int $pendingContributionCount,
        public bool $viewerIsManager,
    ) {
    }
}
