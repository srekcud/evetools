<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProjectStep;

final readonly class AppliedNeed
{
    /**
     * @param list<IndustryProjectStep> $changedSteps
     * @param list<IndustryProjectStep> $surplusJobs
     */
    public function __construct(
        public array $changedSteps,
        public array $surplusJobs,
    ) {
    }
}
