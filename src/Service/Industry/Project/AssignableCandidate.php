<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;

final readonly class AssignableCandidate
{
    /**
     * @param list<string> $signals
     */
    public function __construct(
        public IndustryProject $project,
        public IndustryProjectBomLine $line,
        public int $remainingRuns,
        public int $score,
        public array $signals,
        public bool $oversized,
    ) {
    }
}
