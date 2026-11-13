<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class T2BlueprintCopyProposalLine
{
    public function __construct(
        public int $productTypeId,
        public string $typeName,
        public int $manufacturingRuns,
        public int $personalRuns,
        public ?int $containerRuns,
        public int $availableRuns,
        public int $proposedRuns,
        public int $declaredRuns,
    ) {
    }
}
