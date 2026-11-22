<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

final readonly class CandidateLine
{
    /**
     * @param list<string> $reservingInstallerIds
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public int $remainingRuns,
        public ?int $plannedStructureId = null,
        public array $reservingInstallerIds = [],
    ) {
    }
}
