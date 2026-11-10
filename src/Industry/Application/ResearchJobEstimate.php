<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ResearchJobDuration;

final readonly class ResearchJobEstimate
{
    public function __construct(public int $targetLevel, public ResearchJobDuration $duration)
    {
    }
}
