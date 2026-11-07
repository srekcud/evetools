<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectSurplus
{
    /**
     * @param list<ProjectSurplusLine> $lines
     */
    public function __construct(
        public array $lines,
        public ?float $totalValue,
    ) {
    }
}
