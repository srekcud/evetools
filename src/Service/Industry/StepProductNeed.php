<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;

final readonly class StepProductNeed
{
    /**
     * @param list<IndustryProjectStep> $jobs ordered by split index
     * @param list<int> $effectiveRuns one entry per job
     */
    public function __construct(
        public int $productTypeId,
        public array $jobs,
        public int $need,
        public int $quantityToProvide,
        public bool $isBlacklisted,
        public bool $isPurchased,
        public bool $isFailed,
        public array $effectiveRuns,
    ) {
    }

    public function isBought(): bool
    {
        return !$this->isFailed && ($this->isBlacklisted || $this->isPurchased);
    }
}
