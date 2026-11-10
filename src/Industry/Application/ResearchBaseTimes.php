<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;

interface ResearchBaseTimes
{
    public function of(int $productTypeId, ActivityKind $activity): ?int;
}
